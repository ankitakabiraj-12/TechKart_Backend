<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Customer Payment Initiation and Verification.
 *
 * Implements:
 * - Payment initiation (generates client payment tokens/intent for Card, UPI, NetBanking, bKash).
 * - Verification callback with idempotency locking and order status synchronization.
 * - Demo simulation strictly restricted to development environments.
 */
class CustomerPaymentController
{
    private function resolveCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }
        if ($user['role'] !== 'customer') {
            Response::error('Forbidden. Only customers can verify payments.', 403);
        }
        return (int)$user['id'];
    }

    /**
     * Initiate payment session / intent for an order (SCR-29).
     * POST /api/v1/customer/payments/initiate
     * Body: { order_id: int|string, payment_method: string }
     */
    public function initiate(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $orderIdent = trim((string)$request->get('order_id', ''));
        $paymentMethod = strtolower(trim((string)$request->get('payment_method', 'card')));

        if ($orderIdent === '') {
            Response::error('Validation failed: order_id is required.', 422, ['order_id' => 'Order ID is required.']);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT id, order_number, total_amount, payment_status, order_status
                      FROM orders
                      WHERE ' . ($isNumeric ? '(id = :id OR order_number = :ord_num)' : 'order_number = :ord_num') . '
                      AND customer_id = :cust_id
                      LIMIT 1';

            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent, 'cust_id' => $customerId];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            if ($order['payment_status'] === 'paid') {
                Response::error('Order is already marked as paid.', 400);
            }

            if ($order['order_status'] === 'cancelled') {
                Response::error('Cannot pay for a cancelled order.', 400);
            }

            $orderId = (int)$order['id'];
            $amount = (float)$order['total_amount'];

            // Generate or fetch active transaction reference
            $txRef = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

            // Register payment record
            $payStmt = $pdo->prepare(
                'INSERT INTO payments
                    (order_id, customer_id, payment_method, gateway, transaction_reference, amount, currency, payment_status, payment_metadata)
                 VALUES
                    (:order_id, :customer_id, :payment_method, :gateway, :tx_ref, :amount, :currency, :status, :meta)'
            );
            $payStmt->execute([
                'order_id'       => $orderId,
                'customer_id'    => $customerId,
                'payment_method' => $paymentMethod,
                'gateway'        => $this->mapGateway($paymentMethod),
                'tx_ref'         => $txRef,
                'amount'         => $amount,
                'currency'       => 'INR',
                'status'         => 'pending',
                'meta'           => json_encode(['initiated_at' => date('Y-m-d H:i:s')]),
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // Return client intent configuration
            Response::json([
                'payment_id'            => $paymentId,
                'order_id'              => $orderId,
                'order_number'          => $order['order_number'],
                'amount'                => $amount,
                'currency'              => 'INR',
                'payment_method'        => $paymentMethod,
                'transaction_reference' => $txRef,
                'gateway_reference'     => 'sim_' . bin2hex(random_bytes(8)),
                'client_secret'         => 'tok_' . bin2hex(random_bytes(16)),
                'verify_endpoint'       => '/api/v1/customer/payments/verify',
                'status'                => 'pending',
            ], 200, 'Payment intent initiated successfully');
        } catch (Throwable $e) {
            error_log('[CustomerPaymentController] Payment initiation error: ' . $e->getMessage());
            Response::error('Failed to initiate payment. Please try again later.', 500);
        }
    }

    /**
     * Verify payment status idempotently from Flutter client (SCR-30 -> SCR-31).
     * POST /api/v1/customer/payments/verify
     * Headers: Idempotency-Key?
     * Body: { transaction_reference: string, gateway_payment_ref?: string, status?: string }
     */
    public function verify(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $txRef = trim((string)$request->get('transaction_reference', ''));
        $gatewayPaymentRef = trim((string)$request->get('gateway_payment_ref', ''));
        $simulationStatus = strtolower(trim((string)$request->get('status', 'success')));
        $idempotencyKey = trim((string)$request->getHeader('Idempotency-Key', (string)$request->get('idempotency_key', '')));

        if ($txRef === '') {
            Response::error('Validation failed: transaction_reference is required.', 422, [
                'transaction_reference' => 'Valid transaction reference is required.'
            ]);
        }

        $appEnv = strtolower(trim((string)Env::get('APP_ENV', 'production')));
        $isDevelopment = in_array($appEnv, ['local', 'development', 'dev', 'testing', 'test'], true);

        if (!$isDevelopment) {
            Response::error('Simulated client payment verification is disabled in production environments.', 403);
        }

        try {
            $pdo = Database::getConnection();

            // Check Idempotency Cache
            if ($idempotencyKey !== '') {
                $idemStmt = $pdo->prepare(
                    'SELECT response_code, response_body FROM idempotency_keys WHERE idempotency_key = :key AND user_id = :uid LIMIT 1'
                );
                $idemStmt->execute(['key' => $idempotencyKey, 'uid' => $customerId]);
                $cached = $idemStmt->fetch(PDO::FETCH_ASSOC);
                if ($cached) {
                    $decoded = json_decode($cached['response_body'], true);
                    Response::json($decoded['data'] ?? $decoded, (int)$cached['response_code'], $decoded['message'] ?? 'Payment verified (idempotent)');
                }
            }

            $pdo->beginTransaction();

            $payStmt = $pdo->prepare(
                'SELECT p.id, p.order_id, p.customer_id, p.amount, p.currency, p.payment_status, p.payment_method,
                        o.order_number, o.payment_status AS order_pay_status, o.order_status
                 FROM payments p
                 JOIN orders o ON p.order_id = o.id
                 WHERE p.transaction_reference = :tx_ref AND p.customer_id = :cust_id
                 FOR UPDATE'
            );
            $payStmt->execute([
                'tx_ref'  => $txRef,
                'cust_id' => $customerId,
            ]);
            $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();
                Response::error('Payment transaction record not found.', 404);
            }

            $paymentId = (int)$payment['id'];
            $orderId = (int)$payment['order_id'];

            // If already marked as paid, return idempotent success
            if ($payment['payment_status'] === 'paid') {
                $pdo->commit();
                Response::json([
                    'payment_id'            => $paymentId,
                    'order_id'              => $orderId,
                    'order_number'          => $payment['order_number'],
                    'payment_status'        => 'paid',
                    'order_status'          => $payment['order_status'],
                    'transaction_reference' => $txRef,
                    'is_already_verified'   => true,
                ], 200, 'Payment already verified');
            }

            $isSuccess = ($simulationStatus === 'success' || $simulationStatus === 'paid');

            if ($isSuccess) {
                $now = date('Y-m-d H:i:s');
                $updatePayStmt = $pdo->prepare(
                    'UPDATE payments SET
                        payment_status = :status,
                        gateway_payment_ref = :g_ref,
                        paid_at = :paid_at,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updatePayStmt->execute([
                    'status'   => 'paid',
                    'g_ref'    => $gatewayPaymentRef !== '' ? $gatewayPaymentRef : 'sim_pay_' . bin2hex(random_bytes(6)),
                    'paid_at'  => $now,
                    'id'       => $paymentId,
                ]);

                // Update Order Status to processing/paid
                $updateOrdStmt = $pdo->prepare(
                    'UPDATE orders SET
                        payment_status = :p_status,
                        order_status = :o_status,
                        updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateOrdStmt->execute([
                    'p_status' => 'paid',
                    'o_status' => 'processing',
                    'id'       => $orderId,
                ]);

                // Update order_items to processing
                $pdo->prepare("UPDATE order_items SET item_status = 'processing' WHERE order_id = :id")->execute(['id' => $orderId]);

                // Record processing shipment event
                $processingEventStmt = $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                );
                $processingEventStmt->execute([
                    'order_id'    => $orderId,
                    'status'      => 'processing',
                    'title'       => 'Package Scanned & Processed',
                    'description' => 'Custom packaging inspection passed and sealed securely for dispatch.',
                    'location'    => 'Dhaka Central Hub',
                    'tag_label'   => 'Processed',
                    'actor_role'  => 'system',
                    'actor_id'    => $customerId,
                ]);

                $pdo->commit();

                $responseData = [
                    'payment_id'            => $paymentId,
                    'order_id'              => $orderId,
                    'order_number'          => $payment['order_number'],
                    'payment_status'        => 'paid',
                    'order_status'          => 'processing',
                    'transaction_reference' => $txRef,
                    'amount'                => (float)$payment['amount'],
                    'currency'              => $payment['currency'],
                    'verified_at'           => $now,
                ];

                // Cache Idempotency Key
                if ($idempotencyKey !== '') {
                    try {
                        $cacheStmt = $pdo->prepare(
                            'INSERT INTO idempotency_keys (idempotency_key, user_id, request_path, response_code, response_body)
                             VALUES (:key, :uid, :path, :code, :body)'
                        );
                        $cacheStmt->execute([
                            'key'  => $idempotencyKey,
                            'uid'  => $customerId,
                            'path' => '/api/v1/customer/payments/verify',
                            'code' => 200,
                            'body' => json_encode([
                                'success' => true,
                                'status'  => 200,
                                'message' => 'Payment verified successfully',
                                'data'    => $responseData,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ]);
                    } catch (Throwable) {}
                }

                Response::json($responseData, 200, 'Payment verified successfully');
            } else {
                // Payment Failed / Cancelled
                $pdo->prepare("UPDATE payments SET payment_status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $paymentId]);
                $pdo->prepare("UPDATE orders SET payment_status = 'failed', updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute(['id' => $orderId]);

                $pdo->commit();

                Response::error('Payment authorization failed or cancelled.', 400, [
                    'payment_id'            => $paymentId,
                    'order_id'              => $orderId,
                    'order_number'          => $payment['order_number'],
                    'payment_status'        => 'failed',
                    'transaction_reference' => $txRef,
                ]);
            }
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[CustomerPaymentController] Payment verification error: ' . $e->getMessage());
            Response::error('Payment verification failed. Please try again later.', 500);
        }
    }

    private function mapGateway(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'stripe', 'card'       => 'stripe',
            'sslcommerz'           => 'sslcommerz',
            'bkash'                => 'bkash',
            'upi', 'netbanking'    => 'aggregator',
            'cod'                  => 'cod',
            default                => 'simulated_gateway',
        };
    }
}
