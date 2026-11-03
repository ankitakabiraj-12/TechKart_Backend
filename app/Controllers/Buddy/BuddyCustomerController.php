<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

class BuddyCustomerController
{
    /**
     * Resolve authenticated user from request attribute
     */
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }
        return $user;
    }

    /**
     * Admin Customers Directory
     * GET /api/v1/buddy/admin/customers
     * Query: page, limit, search, status, sort
     */
    public function adminCustomersDirectory(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $search = trim((string)$request->getQuery('search', ''));
        $status = strtolower(trim((string)$request->getQuery('status', 'all')));
        $page   = max(1, (int)$request->getQuery('page', 1));
        $limit  = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = ["u.role = 'customer'"];
            $params = [];

            if ($status === 'active') {
                $conditions[] = 'u.is_active = 1';
            } elseif ($status === 'inactive') {
                $conditions[] = 'u.is_active = 0';
            }

            if ($search !== '') {
                $conditions[] = '(u.full_name LIKE :s_name OR u.email LIKE :s_email OR u.phone LIKE :s_phone OR ua.city LIKE :s_city OR ua.state LIKE :s_state)';
                $searchTerm = '%' . $search . '%';
                $params['s_name']  = $searchTerm;
                $params['s_email'] = $searchTerm;
                $params['s_phone'] = $searchTerm;
                $params['s_city']  = $searchTerm;
                $params['s_state'] = $searchTerm;
            }

            $whereSql = implode(' AND ', $conditions);

            // Total count query
            $countSql = "SELECT COUNT(DISTINCT u.id)
                         FROM users u
                         LEFT JOIN user_addresses ua ON u.id = ua.user_id AND ua.is_default = 1
                         WHERE {$whereSql}";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Main customer list with order summaries, review metrics, and default address
            $sql = "SELECT
                        u.id AS user_id,
                        u.full_name,
                        u.email,
                        u.phone,
                        u.avatar_url,
                        u.is_active,
                        u.created_at,
                        ua.address_line1,
                        ua.city,
                        ua.state,
                        ua.postal_code,
                        ua.country,
                        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = u.id) AS total_orders,
                        (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.customer_id = u.id AND o.payment_status = 'paid') AS total_spent,
                        (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = u.id) AS last_order_date,
                        (SELECT COUNT(*) FROM product_reviews pr WHERE pr.customer_id = u.id) AS total_reviews,
                        (SELECT COALESCE(ROUND(AVG(pr.rating), 1), 0) FROM product_reviews pr WHERE pr.customer_id = u.id) AS avg_review_rating,
                        (SELECT COUNT(*) FROM return_requests rr WHERE rr.customer_id = u.id) AS total_returns
                    FROM users u
                    LEFT JOIN user_addresses ua ON u.id = ua.user_id AND ua.is_default = 1
                    WHERE {$whereSql}
                    GROUP BY u.id
                    ORDER BY u.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Cast and format fields for clean presentation
            foreach ($customers as &$c) {
                $c['user_id'] = (int)$c['user_id'];
                $c['is_active'] = (int)$c['is_active'];
                $c['total_orders'] = (int)$c['total_orders'];
                $c['total_spent'] = (float)$c['total_spent'];
                $c['total_spent_fmt'] = '₹ ' . number_format($c['total_spent'], 2);
                $c['total_reviews'] = (int)$c['total_reviews'];
                $c['avg_review_rating'] = (float)$c['avg_review_rating'];
                $c['total_returns'] = (int)$c['total_returns'];
            }
            unset($c);

            // Summary metrics for admin header cards
            $totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
            $activeCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND is_active = 1")->fetchColumn();
            $totalGrossRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
            $totalCustomerOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $totalCustomerReturns = (int)$pdo->query("SELECT COUNT(*) FROM return_requests")->fetchColumn();
            $totalReviewsCount = (int)$pdo->query("SELECT COUNT(*) FROM product_reviews")->fetchColumn();

            Response::json([
                'metrics' => [
                    'total_customers'       => $totalCustomers,
                    'active_customers'      => $activeCustomers,
                    'total_orders'          => $totalCustomerOrders,
                    'total_spent_fmt'       => '₹ ' . number_format($totalGrossRevenue, 2),
                    'total_returns'         => $totalCustomerReturns,
                    'total_reviews'         => $totalReviewsCount,
                    'active_support_agents' => 18,
                    'average_sla'           => '4.2m SLA',
                ],
                'customers'  => $customers,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int)ceil($total / max(1, $limit)),
                ],
            ], 200, 'Customer directory retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve customer directory: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Customer Details with Order History and Reviews
     * GET /api/v1/buddy/admin/customers/{id}
     */
    public function adminCustomerDetails(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $customerId = (int)($params['id'] ?? $request->getQuery('customer_id', 0));
        if ($customerId <= 0) {
            Response::error('Invalid customer ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare("SELECT
                                        u.id,
                                        u.full_name,
                                        u.email,
                                        u.phone,
                                        u.avatar_url,
                                        u.is_active,
                                        u.created_at,
                                        u.updated_at
                                    FROM users u
                                    WHERE u.id = :id AND u.role = 'customer'
                                    LIMIT 1");
            $stmt->execute(['id' => $customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                Response::error('Customer not found.', 404);
            }

            // Customer addresses
            $addrStmt = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC");
            $addrStmt->execute(['uid' => $customerId]);
            $addresses = $addrStmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent Orders
            $orderStmt = $pdo->prepare("SELECT
                                            id,
                                            order_number,
                                            total_amount,
                                            payment_status,
                                            order_status,
                                            tracking_number,
                                            courier_name,
                                            created_at
                                        FROM orders
                                        WHERE customer_id = :uid
                                        ORDER BY id DESC
                                        LIMIT 20");
            $orderStmt->execute(['uid' => $customerId]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

            // Reviews authored by customer
            $reviewStmt = $pdo->prepare("SELECT
                                            pr.id,
                                            pr.product_id,
                                            p.title AS product_name,
                                            pr.rating,
                                            pr.title,
                                            pr.review_text,
                                            pr.status,
                                            pr.created_at
                                         FROM product_reviews pr
                                         LEFT JOIN products p ON pr.product_id = p.id
                                         WHERE pr.customer_id = :uid
                                         ORDER BY pr.id DESC
                                         LIMIT 20");
            $reviewStmt->execute(['uid' => $customerId]);
            $reviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);

            // Returns requested by customer
            $returnStmt = $pdo->prepare("SELECT
                                            rr.id,
                                            rr.return_number,
                                            rr.order_id,
                                            rr.reason,
                                            rr.status,
                                            rr.refund_amount,
                                            rr.created_at
                                         FROM return_requests rr
                                         WHERE rr.customer_id = :uid
                                         ORDER BY rr.id DESC
                                         LIMIT 20");
            $returnStmt->execute(['uid' => $customerId]);
            $returns = $returnStmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate aggregate metrics
            $totalSpent = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE customer_id = {$customerId} AND payment_status = 'paid'")->fetchColumn();

            Response::json([
                'customer' => [
                    'id'              => (int)$customer['id'],
                    'full_name'       => $customer['full_name'],
                    'email'           => $customer['email'],
                    'phone'           => $customer['phone'],
                    'avatar_url'      => $customer['avatar_url'],
                    'is_active'       => (int)$customer['is_active'],
                    'created_at'      => $customer['created_at'],
                    'total_spent'     => $totalSpent,
                    'total_spent_fmt' => '₹ ' . number_format($totalSpent, 2),
                    'total_orders'    => count($orders),
                    'total_reviews'   => count($reviews),
                    'total_returns'   => count($returns),
                ],
                'addresses' => $addresses,
                'orders'    => $orders,
                'reviews'   => $reviews,
                'returns'   => $returns,
            ], 200, 'Customer details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve customer details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Update Customer Status (Activate / Suspend)
     * PUT/PATCH /api/v1/buddy/admin/customers/{id}/status
     * Body: { is_active: int|bool }
     */
    public function updateCustomerStatus(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $customerId = (int)($params['id'] ?? $request->get('customer_id', 0));
        if ($customerId <= 0) {
            Response::error('Invalid customer ID.', 400);
        }

        $isActive = $request->get('is_active');
        if ($isActive === null) {
            Response::error('Missing is_active parameter.', 422);
        }

        $statusVal = (int)(bool)$isActive;

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = :id AND role = 'customer' LIMIT 1");
            $checkStmt->execute(['id' => $customerId]);
            $customer = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer) {
                Response::error('Customer not found.', 404);
            }

            $upStmt = $pdo->prepare('UPDATE users SET is_active = :status, updated_at = NOW() WHERE id = :id');
            $upStmt->execute([
                'status' => $statusVal,
                'id'     => $customerId,
            ]);

            $actionText = $statusVal === 1 ? 'activated' : 'suspended';

            Response::json([
                'customer_id' => $customerId,
                'is_active'   => $statusVal,
            ], 200, "Customer account {$actionText} successfully");

        } catch (Throwable $e) {
            Response::error('Failed to update customer status: ' . $e->getMessage(), 500);
        }
    }
}
