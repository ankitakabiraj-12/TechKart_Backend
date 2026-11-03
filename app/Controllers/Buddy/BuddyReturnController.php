<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Return & Refund decisions for Sellers and Admins.
 * Protected by AuthMiddleware(['seller', 'admin']).
 *
 * Rules:
 * - Sellers can only view and update return requests belonging to their seller_id.
 * - Admins can view and update all return requests across all sellers.
 * - Decisions:
 *     - 'quality_inspection': Item picked up and currently under inspection.
 *     - 'approved': Inspection passed, approved for refund.
 *     - 'rejected': Inspection failed or item damaged/missing, return rejected.
 *     - 'refund_released': Refund issued; links to payment record and sets orders.payment_status = 'refunded'.
 * - Atomic execution inside PDO transactions.
 */
class BuddyReturnController
{
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }
        if (!in_array($user['role'], ['seller', 'admin'], true)) {
            Response::error('Forbidden. Sellers and admins only.', 403);
        }
        return $user;
    }

    /**
     * List return requests for seller or admin.
     * GET /api/v1/buddy/returns
     */
    public function index(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];

        $status = strtolower(trim((string)$request->getQuery('status', '')));
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = [];
            $params = [];

            if ($role === 'seller') {
                $conditions[] = 'r.seller_id = :seller_id';
                $params['seller_id'] = $sellerId;
            }

            if ($status !== '' && $status !== 'all') {
                $conditions[] = 'r.status = :status';
                $params['status'] = $status;
            }

            $whereSql = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM return_requests r {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT r.*, o.order_number, p.title AS product_name,
                           u.full_name AS customer_name, u.email AS customer_email
                    FROM return_requests r
                    JOIN orders o ON r.order_id = o.id
                    JOIN products p ON r.product_id = p.id
                    JOIN users u ON r.customer_id = u.id
                    {$whereSql}
                    ORDER BY r.id DESC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'returns' => $returns,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int)ceil($total / $limit),
                ],
            ], 200, 'Return requests retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve return requests: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Show return details for seller or admin.
     * GET /api/v1/buddy/returns/{id}
     */
    public function show(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $returnIdent = trim((string)($params['id'] ?? ''));

        if ($returnIdent === '') {
            Response::error('Invalid return identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($returnIdent);
            $sql = "SELECT r.*, o.order_number, o.payment_status AS order_payment_status,
                           oi.unit_price, oi.quantity, oi.subtotal,
                           p.title AS product_name,
                           u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
                    FROM return_requests r
                    JOIN orders o ON r.order_id = o.id
                    JOIN order_items oi ON r.order_item_id = oi.id
                    JOIN products p ON r.product_id = p.id
                    JOIN users u ON r.customer_id = u.id
                    WHERE " . ($isNumeric ? '(r.id = :id OR r.return_number = :ret_num)' : 'r.return_number = :ret_num') . "
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $binds = ['ret_num' => $returnIdent];
            if ($isNumeric) $binds['id'] = (int)$returnIdent;
            $stmt->execute($binds);
            $return = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$return) {
                Response::error('Return request not found.', 404);
            }

            // Seller isolation: Sellers can only view returns for their products
            if ($role === 'seller' && (int)$return['seller_id'] !== $sellerId) {
                Response::error('Forbidden. This return request does not belong to your store.', 403);
            }

            Response::json($return, 200, 'Return request details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve return request details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update return status / make decision (authorized seller or admin).
     * PUT/PATCH /api/v1/buddy/returns/{id}/decision
     * Body: {
     *   status: 'quality_inspection' | 'approved' | 'rejected' | 'refund_released' | 'completed',
     *   decision_reason?: string,
     *   admin_notes?: string,
     *   actual_refund?: float
     * }
     */
    public function decision(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $returnIdent = trim((string)($params['id'] ?? ''));

        $newStatus = strtolower(trim((string)$request->get('status', '')));
        $decisionReason = trim((string)$request->get('decision_reason', ''));
        $adminNotes = trim((string)$request->get('admin_notes', ''));
        $actualRefund = $request->get('actual_refund');

        $allowedStatuses = ['pickup_scheduled', 'quality_inspection', 'approved', 'rejected', 'refund_released', 'completed'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Response::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 422);
        }

        try {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            $isNumeric = ctype_digit($returnIdent);
            $sql = "SELECT r.*, o.payment_status AS order_payment_status, o.total_amount AS order_total
                    FROM return_requests r
                    JOIN orders o ON r.order_id = o.id
                    WHERE " . ($isNumeric ? '(r.id = :id OR r.return_number = :ret_num)' : 'r.return_number = :ret_num') . "
                    FOR UPDATE";

            $stmt = $pdo->prepare($sql);
            $binds = ['ret_num' => $returnIdent];
            if ($isNumeric) $binds['id'] = (int)$returnIdent;
            $stmt->execute($binds);
            $ret = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ret) {
                $pdo->rollBack();
                Response::error('Return request not found.', 404);
            }

            // Seller isolation: Sellers can only decide on their own items
            if ($role === 'seller' && (int)$ret['seller_id'] !== $sellerId) {
                $pdo->rollBack();
                Response::error('Forbidden. This return request does not belong to your store.', 403);
            }

            $returnId = (int)$ret['id'];
            $orderId = (int)$ret['order_id'];
            $refundAmount = $actualRefund !== null ? (float)$actualRefund : (float)$ret['estimated_refund'];

            $refundPaymentId = $ret['refund_payment_id'];

            // If decision is 'refund_released', link to payment record and update payment_status
            if ($newStatus === 'refund_released' || $newStatus === 'completed') {
                // Check if a refund payment record already exists
                if (!$refundPaymentId) {
                    $txnRef = 'TXN-REFUND-' . strtoupper(substr(md5(uniqid((string)$returnId, true)), 0, 8));
                    $insPayStmt = $pdo->prepare(
                        "INSERT INTO payments
                         (order_id, customer_id, payment_method, gateway, transaction_reference, amount, currency, payment_status, paid_at, created_at, updated_at)
                         VALUES
                         (:ord_id, :cust_id, 'card', 'manual', :txn_ref, :amt, 'INR', 'refunded', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                    );
                    $insPayStmt->execute([
                        'ord_id'   => $orderId,
                        'cust_id'  => (int)$ret['customer_id'],
                        'txn_ref'  => $txnRef,
                        'amt'      => $refundAmount,
                    ]);
                    $refundPaymentId = (int)$pdo->lastInsertId();
                }

                // Update orders payment_status to 'refunded'
                $pdo->prepare("UPDATE orders SET payment_status = 'refunded', updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $orderId]);
                $pdo->prepare("UPDATE payments SET payment_status = 'refunded' WHERE order_id = :order_id")->execute(['order_id' => $orderId]);
            }

            // Update return request record
            $updStmt = $pdo->prepare(
                "UPDATE return_requests
                 SET status = :status,
                     actual_refund = :actual_refund,
                     decision_reason = :decision_reason,
                     admin_notes = :admin_notes,
                     refund_payment_id = :refund_payment_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $updStmt->execute([
                'status'            => $newStatus,
                'actual_refund'     => $refundAmount,
                'decision_reason'   => $decisionReason ?: $ret['decision_reason'],
                'admin_notes'       => $adminNotes ?: $ret['admin_notes'],
                'refund_payment_id' => $refundPaymentId,
                'id'                => $returnId,
            ]);

            $pdo->commit();

            Response::json([
                'return_id'         => $returnId,
                'return_number'     => $ret['return_number'],
                'status'            => $newStatus,
                'actual_refund'     => $refundAmount,
                'decision_reason'   => $decisionReason ?: $ret['decision_reason'],
                'refund_payment_id' => $refundPaymentId,
            ], 200, "Return status successfully updated to '{$newStatus}'");

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update return request decision: ' . $e->getMessage(), 500);
        }
    }
}
