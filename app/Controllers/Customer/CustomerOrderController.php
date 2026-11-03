<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Customer Checkout preview, Order creation, Order History,
 * Details inspection, and Order Cancellation.
 *
 * Implements:
 * - Server-calculated totals (subtotal, shipping, discount, tax, grand total).
 * - Live stock validation & atomic stock decrement within PDO transaction.
 * - Idempotency key checking to avoid duplicate order placement.
 * - Historical price & address snapshots.
 * - Customer ownership security (cannot view/cancel other users' orders).
 */
class CustomerOrderController
{
    private function resolveCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }
        if ($user['role'] !== 'customer') {
            Response::error('Forbidden. Only customers can perform checkout and manage orders.', 403);
        }
        return (int)$user['id'];
    }

    /**
     * Preview checkout summary before placing order (SCR-28 Order Review).
     * GET /api/v1/customer/checkout/preview
     * Query: address_id? (optional, defaults to default address)
     */
    public function preview(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);
        $addressId = (int)$request->getQuery('address_id', 0);

        try {
            $pdo = Database::getConnection();

            // 1. Fetch Delivery Address
            $address = $this->resolveAddress($pdo, $customerId, $addressId);

            // 2. Fetch and Validate Cart Items
            $cartEvaluation = $this->evaluateCartItems($pdo, $customerId);

            if (empty($cartEvaluation['items'])) {
                Response::error('Your cart is empty. Add items before checking out.', 400);
            }

            Response::json([
                'delivery_address' => $address,
                'items'            => $cartEvaluation['items'],
                'pricing'          => $cartEvaluation['pricing'],
                'can_place_order'  => !$cartEvaluation['has_unavailable_items'],
                'unavailable_reason' => $cartEvaluation['has_unavailable_items'] ? 'Some items in your cart are currently out of stock or unavailable.' : null,
            ], 200, 'Checkout preview calculated successfully');
        } catch (Throwable $e) {
            Response::error('Failed to preview checkout: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Place an order from active cart items (SCR-28 -> SCR-29 / SCR-30).
     * POST /api/v1/customer/orders
     * Headers: Idempotency-Key? (optional but enforced if provided)
     * Body: { address_id?: int, shipping_address?: object, payment_method: string, notes?: string }
     */
    public function store(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $idempotencyKey = trim((string)$request->getHeader('Idempotency-Key', (string)$request->get('idempotency_key', '')));
        $addressId = (int)$request->get('address_id', 0);
        $customAddress = $request->get('shipping_address');
        $paymentMethod = strtolower(trim((string)$request->get('payment_method', 'cod')));
        $notes = trim((string)$request->get('notes', ''));

        $allowedPaymentMethods = ['card', 'upi', 'netbanking', 'cod', 'stripe', 'sslcommerz', 'bkash'];
        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            Response::error('Invalid payment method selected.', 422, [
                'payment_method' => 'Allowed methods: ' . implode(', ', $allowedPaymentMethods)
            ]);
        }

        try {
            $pdo = Database::getConnection();

            // Check Idempotency Key
            if ($idempotencyKey !== '') {
                $idemStmt = $pdo->prepare(
                    'SELECT response_code, response_body FROM idempotency_keys WHERE idempotency_key = :key AND user_id = :uid LIMIT 1'
                );
                $idemStmt->execute(['key' => $idempotencyKey, 'uid' => $customerId]);
                $cached = $idemStmt->fetch(PDO::FETCH_ASSOC);
                if ($cached) {
                    $decoded = json_decode($cached['response_body'], true);
                    Response::json($decoded['data'] ?? $decoded, (int)$cached['response_code'], $decoded['message'] ?? 'Order retrieved (idempotent)');
                }
            }

            // 1. Resolve Shipping Address snapshot
            $shippingAddressSnapshot = null;
            if (is_array($customAddress) && !empty($customAddress['recipient_name']) && !empty($customAddress['phone']) && !empty($customAddress['address_line1'])) {
                $shippingAddressSnapshot = [
                    'recipient_name' => trim((string)$customAddress['recipient_name']),
                    'phone'          => trim((string)$customAddress['phone']),
                    'address_line1'  => trim((string)$customAddress['address_line1']),
                    'address_line2'  => trim((string)($customAddress['address_line2'] ?? '')),
                    'city'           => trim((string)($customAddress['city'] ?? '')),
                    'state'          => trim((string)($customAddress['state'] ?? '')),
                    'postal_code'    => trim((string)($customAddress['postal_code'] ?? '')),
                    'country'        => trim((string)($customAddress['country'] ?? 'Bangladesh')),
                ];
            } else {
                $resolved = $this->resolveAddress($pdo, $customerId, $addressId);
                if (!$resolved) {
                    Response::error('No valid shipping address provided or found on profile.', 422, [
                        'address_id' => 'Please provide a valid address ID or address object.'
                    ]);
                }
                $shippingAddressSnapshot = [
                    'address_id'     => $resolved['id'],
                    'label'          => $resolved['label'],
                    'recipient_name' => $resolved['recipient_name'],
                    'phone'          => $resolved['phone'],
                    'address_line1'  => $resolved['address_line1'],
                    'address_line2'  => $resolved['address_line2'],
                    'city'           => $resolved['city'],
                    'state'          => $resolved['state'],
                    'postal_code'    => $resolved['postal_code'],
                    'country'        => $resolved['country'],
                    'delivery_instructions' => $resolved['delivery_instructions'],
                ];
            }

            // Begin Atomic Transaction: Lock inventory, create order, create line items, clear cart
            $pdo->beginTransaction();

            // Lock cart rows and products FOR UPDATE
            $cartStmt = $pdo->prepare(
                'SELECT ci.id AS cart_item_id, ci.product_id, ci.quantity,
                        p.seller_id, p.title, p.price, p.mrp, p.stock_quantity, p.status,
                        u.is_active AS seller_active, sp.store_name
                 FROM cart_items ci
                 JOIN products p ON ci.product_id = p.id
                 JOIN users u ON p.seller_id = u.id
                 LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                 WHERE ci.customer_id = :customer_id
                 FOR UPDATE'
            );
            $cartStmt->execute(['customer_id' => $customerId]);
            $cartRows = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($cartRows)) {
                $pdo->rollBack();
                Response::error('Cannot place order: Cart is empty.', 400);
            }

            $orderItems = [];
            $totalAmount = 0.0;
            $totalQuantity = 0;

            foreach ($cartRows as $row) {
                $stock = (int)$row['stock_quantity'];
                $qty = (int)$row['quantity'];
                $pid = (int)$row['product_id'];

                if ($row['status'] !== 'published' || (int)$row['seller_active'] !== 1) {
                    $pdo->rollBack();
                    Response::error("Product '{$row['title']}' is no longer available for purchase.", 400);
                }

                if ($stock < $qty) {
                    $pdo->rollBack();
                    Response::error("Insufficient stock for '{$row['title']}'. Only {$stock} available, {$qty} requested.", 400, [
                        'product_id' => $pid,
                        'available_stock' => $stock,
                        'requested' => $qty
                    ]);
                }

                $unitPrice = (float)$row['price'];
                $subtotal = round($unitPrice * $qty, 2);
                $totalAmount += $subtotal;
                $totalQuantity += $qty;

                $orderItems[] = [
                    'product_id'  => $pid,
                    'seller_id'   => (int)$row['seller_id'],
                    'title'       => $row['title'],
                    'unit_price'  => $unitPrice,
                    'quantity'    => $qty,
                    'subtotal'    => $subtotal,
                ];
            }

            $totalAmount = round($totalAmount, 2);

            // Generate Unique Order Number: TK-YYYYMM-XXXXX (matching UI design SCR-28 / SCR-31)
            $orderNumber = 'TK-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $trackingNumber = 'APX-' . rand(100000, 999999);
            $courierName = 'Apex Courier Express';
            $courierPhone = '+880 1700-112233';
            $estimatedDelivery = date('l, M d', strtotime('+1 day'));
            $estimatedTime = 'By 6:00 PM • On Schedule';
            $currentLocation = 'Order Processing Facility';
            $nextStop = 'Dhaka Central Hub';

            // Initial statuses based on payment method
            $paymentStatus = ($paymentMethod === 'cod') ? 'pending' : 'pending';
            $orderStatus = 'placed';

            // 1. Insert into orders table
            $orderInsertStmt = $pdo->prepare(
                'INSERT INTO orders (order_number, customer_id, total_amount, payment_status, order_status,
                                     tracking_number, courier_name, courier_phone, estimated_delivery, estimated_time, current_location, next_stop, shipping_address)
                 VALUES (:order_number, :customer_id, :total_amount, :payment_status, :order_status,
                         :tracking_number, :courier_name, :courier_phone, :estimated_delivery, :estimated_time, :current_location, :next_stop, :shipping_address)'
            );
            $orderInsertStmt->execute([
                'order_number'       => $orderNumber,
                'customer_id'        => $customerId,
                'total_amount'       => $totalAmount,
                'payment_status'     => $paymentStatus,
                'order_status'       => $orderStatus,
                'tracking_number'    => $trackingNumber,
                'courier_name'       => $courierName,
                'courier_phone'      => $courierPhone,
                'estimated_delivery' => $estimatedDelivery,
                'estimated_time'     => $estimatedTime,
                'current_location'   => $currentLocation,
                'next_stop'          => $nextStop,
                'shipping_address'   => json_encode($shippingAddressSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // Insert initial shipment event
            $eventStmt = $pdo->prepare(
                'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                 VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
            );
            $eventStmt->execute([
                'order_id'    => $orderId,
                'status'      => 'placed',
                'title'       => 'Order Placed & Verified',
                'description' => 'Payment authorization received. Order sent for warehouse processing.',
                'location'    => 'Order Processing Center',
                'tag_label'   => 'Verified',
                'actor_role'  => 'system',
                'actor_id'    => $customerId,
            ]);

            // 2. Insert order items and deduct product stock
            $itemInsertStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
                 VALUES (:order_id, :seller_id, :product_id, :unit_price, :quantity, :subtotal, :item_status)'
            );
            $decrementStockStmt = $pdo->prepare(
                'UPDATE products SET stock_quantity = stock_quantity - :qty WHERE id = :id'
            );

            foreach ($orderItems as $item) {
                $itemInsertStmt->execute([
                    'order_id'    => $orderId,
                    'seller_id'   => $item['seller_id'],
                    'product_id'  => $item['product_id'],
                    'unit_price'  => $item['unit_price'],
                    'quantity'    => $item['quantity'],
                    'subtotal'    => $item['subtotal'],
                    'item_status' => 'placed',
                ]);

                $decrementStockStmt->execute([
                    'qty' => $item['quantity'],
                    'id'  => $item['product_id'],
                ]);
            }

            // 3. Create initial Payment record
            $txRef = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));
            $payInsertStmt = $pdo->prepare(
                'INSERT INTO payments
                    (order_id, customer_id, payment_method, gateway, transaction_reference, amount, currency, payment_status, idempotency_key, payment_metadata)
                 VALUES
                    (:order_id, :customer_id, :payment_method, :gateway, :tx_ref, :amount, :currency, :payment_status, :idem_key, :meta)'
            );
            $payInsertStmt->execute([
                'order_id'       => $orderId,
                'customer_id'    => $customerId,
                'payment_method' => $paymentMethod,
                'gateway'        => ($paymentMethod === 'cod') ? 'cod' : 'simulated_gateway',
                'tx_ref'         => $txRef,
                'amount'         => $totalAmount,
                'currency'       => 'INR',
                'payment_status' => $paymentStatus,
                'idem_key'       => $idempotencyKey !== '' ? $idempotencyKey : null,
                'meta'           => json_encode(['notes' => $notes, 'created_via' => 'api']),
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // 4. Clear Customer Cart
            $pdo->prepare('DELETE FROM cart_items WHERE customer_id = :customer_id')->execute(['customer_id' => $customerId]);

            $pdo->commit();

            // Prepare Response Data
            $responseData = [
                'order_id'       => $orderId,
                'order_number'   => $orderNumber,
                'total_amount'   => $totalAmount,
                'total_quantity' => $totalQuantity,
                'payment_status' => $paymentStatus,
                'order_status'   => $orderStatus,
                'payment_method' => $paymentMethod,
                'payment_id'     => $paymentId,
                'transaction_reference' => $txRef,
                'tracking_number' => $trackingNumber,
                'courier_name'    => $courierName,
                'courier_phone'   => $courierPhone,
                'estimated_delivery' => $estimatedDelivery,
                'estimated_time'  => $estimatedTime,
                'shipping_address' => $shippingAddressSnapshot,
                'items_count'    => count($orderItems),
                'next_action'    => ($paymentMethod === 'cod') ? 'order_confirmed' : 'proceed_to_payment',
                'payment_intent' => ($paymentMethod !== 'cod') ? [
                    'transaction_reference' => $txRef,
                    'amount'                => $totalAmount,
                    'currency'              => 'INR',
                    'gateway'               => 'simulated_gateway',
                    'verify_endpoint'       => '/api/v1/customer/payments/verify',
                ] : null,
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
                        'path' => '/api/v1/customer/orders',
                        'code' => 201,
                        'body' => json_encode([
                            'success' => true,
                            'status'  => 201,
                            'message' => 'Order placed successfully',
                            'data'    => $responseData,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (Throwable) {
                    // Ignore duplicate key collision
                }
            }

            Response::json($responseData, 201, 'Order placed successfully');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to place order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer Order History (SCR-32).
     * GET /api/v1/customer/orders
     * Query: status? (all, placed, processing, shipped, delivered, cancelled), page?, limit?
     */
    public function index(Request $request): void
    {
        $customerId = $this->resolveCustomerId($request);

        $statusFilter = strtolower(trim((string)$request->getQuery('status', '')));
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 10)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = ['o.customer_id = :customer_id'];
            $params = ['customer_id' => $customerId];

            if ($statusFilter !== '' && $statusFilter !== 'all') {
                $conditions[] = 'o.order_status = :status';
                $params['status'] = $statusFilter;
            }

            $whereSql = implode(' AND ', $conditions);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT o.id, o.order_number, o.total_amount, o.payment_status, o.order_status,
                           o.tracking_number, o.courier_name, o.courier_phone, o.estimated_delivery, o.estimated_time,
                           o.shipping_address, o.created_at, o.updated_at,
                           p.payment_method, p.transaction_reference,
                           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS items_count
                    FROM orders o
                    LEFT JOIN payments p ON p.order_id = o.id
                    WHERE {$whereSql}
                    ORDER BY o.created_at DESC, o.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch preview items thumbnails for each order
            if (!empty($orders)) {
                $orderIds = array_column($orders, 'id');
                $inQuery = implode(',', array_map('intval', $orderIds));

                $itemThumbStmt = $pdo->query(
                    "SELECT oi.order_id, oi.product_id, p.title,
                            (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                     FROM order_items oi
                     JOIN products p ON oi.product_id = p.id
                     WHERE oi.order_id IN ({$inQuery})"
                );
                $thumbnails = $itemThumbStmt->fetchAll(PDO::FETCH_ASSOC);

                $itemsByOrder = [];
                foreach ($thumbnails as $thumb) {
                    $itemsByOrder[$thumb['order_id']][] = [
                        'product_id' => (int)$thumb['product_id'],
                        'title'      => $thumb['title'],
                        'image_url'  => $thumb['primary_image_url'],
                    ];
                }

                foreach ($orders as &$ord) {
                    $ord['id'] = (int)$ord['id'];
                    $ord['total_amount'] = (float)$ord['total_amount'];
                    $ord['items_count'] = (int)$ord['items_count'];
                    $ord['shipping_address'] = json_decode($ord['shipping_address'], true);
                    $ord['preview_items'] = $itemsByOrder[$ord['id']] ?? [];
                }
                unset($ord);
            }

            Response::json($orders, 200, 'Orders retrieved successfully', [
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => ceil($total / $limit) ?: 1,
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve orders: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Customer Order Details (SCR-33).
     * GET /api/v1/customer/orders/{id}
     * Param: {id} can be order primary key ID or order_number.
     */
    public function show(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $orderIdent = trim((string)($params['id'] ?? ''));

        if ($orderIdent === '') {
            Response::error('Invalid order identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT o.id, o.order_number, o.customer_id, o.total_amount, o.payment_status, o.order_status,
                             o.tracking_number, o.courier_name, o.courier_phone, o.estimated_delivery, o.estimated_time,
                             o.current_location, o.next_stop, o.shipping_address, o.created_at, o.updated_at
                      FROM orders o
                      WHERE ' . ($isNumeric ? '(o.id = :id OR o.order_number = :ord_num)' : 'o.order_number = :ord_num') . '
                      AND o.customer_id = :cust_id
                      LIMIT 1';

            $stmt = $pdo->prepare($query);
            $binds = [
                'ord_num' => $orderIdent,
                'cust_id' => $customerId,
            ];
            if ($isNumeric) {
                $binds['id'] = (int)$orderIdent;
            }
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            $orderId = (int)$order['id'];

            // Fetch Line Items with Product and Seller Metadata
            $itemStmt = $pdo->prepare(
                'SELECT oi.id AS order_item_id, oi.seller_id, oi.product_id, oi.unit_price, oi.quantity,
                        oi.subtotal, oi.item_status, oi.created_at,
                        p.title, p.slug, sp.store_name,
                        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                 FROM order_items oi
                 JOIN products p ON oi.product_id = p.id
                 LEFT JOIN seller_profiles sp ON oi.seller_id = sp.user_id
                 WHERE oi.order_id = :order_id
                 ORDER BY oi.id ASC'
            );
            $itemStmt->execute(['order_id' => $orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch Payment Records
            $payStmt = $pdo->prepare(
                'SELECT id, payment_method, gateway, transaction_reference, amount, currency, payment_status, paid_at, created_at
                 FROM payments
                 WHERE order_id = :order_id
                 ORDER BY id DESC'
            );
            $payStmt->execute(['order_id' => $orderId]);
            $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            $order['id'] = $orderId;
            $order['total_amount'] = (float)$order['total_amount'];
            $order['shipping_address'] = json_decode($order['shipping_address'], true);
            $order['items'] = array_map(function ($it) {
                $it['order_item_id'] = (int)$it['order_item_id'];
                $it['product_id'] = (int)$it['product_id'];
                $it['seller_id'] = (int)$it['seller_id'];
                $it['unit_price'] = (float)$it['unit_price'];
                $it['quantity'] = (int)$it['quantity'];
                $it['subtotal'] = (float)$it['subtotal'];
                return $it;
            }, $items);
            $order['payments'] = array_map(function ($p) {
                $p['id'] = (int)$p['id'];
                $p['amount'] = (float)$p['amount'];
                return $p;
            }, $payments);

            // Calculate can_cancel rule: Can cancel if placed or processing and not shipped/cancelled
            $order['can_cancel'] = in_array($order['order_status'], ['placed', 'processing'], true);

            Response::json($order, 200, 'Order details retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve order details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel an active order and restore product inventory.
     * POST /api/v1/customer/orders/{id}/cancel
     * Body: { reason?: string }
     */
    public function cancel(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $orderIdent = trim((string)($params['id'] ?? ''));
        $reason = trim((string)$request->get('reason', 'Customer requested cancellation'));

        if ($orderIdent === '') {
            Response::error('Invalid order identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();
            $pdo->beginTransaction();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT id, order_number, order_status, payment_status, total_amount
                      FROM orders
                      WHERE ' . ($isNumeric ? '(id = :id OR order_number = :ord_num)' : 'order_number = :ord_num') . '
                      AND customer_id = :cust_id
                      FOR UPDATE';

            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent, 'cust_id' => $customerId];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $pdo->rollBack();
                Response::error('Order not found.', 404);
            }

            if (!in_array($order['order_status'], ['placed', 'processing'], true)) {
                $pdo->rollBack();
                Response::error("Order cannot be cancelled in its current state ('{$order['order_status']}').", 400);
            }

            $orderId = (int)$order['id'];

            // 1. Fetch items to restore stock
            $itemsStmt = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id');
            $itemsStmt->execute(['order_id' => $orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity + :qty WHERE id = :id');
            foreach ($items as $item) {
                $restoreStmt->execute([
                    'qty' => (int)$item['quantity'],
                    'id'  => (int)$item['product_id'],
                ]);
            }

            // 2. Mark order and items as cancelled
            $updateOrderStmt = $pdo->prepare(
                'UPDATE orders SET order_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $updateOrderStmt->execute([
                'status' => 'cancelled',
                'id'     => $orderId,
            ]);

            $pdo->prepare("UPDATE order_items SET item_status = 'cancelled' WHERE order_id = :order_id")->execute(['order_id' => $orderId]);

            // Record cancellation shipment event
            $cancelEventStmt = $pdo->prepare(
                'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                 VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
            );
            $cancelEventStmt->execute([
                'order_id'    => $orderId,
                'status'      => 'cancelled',
                'title'       => 'Order Cancelled',
                'description' => "Order cancelled: {$reason}. Inventory restored to seller warehouse.",
                'location'    => 'Order Processing Center',
                'tag_label'   => 'Cancelled',
                'actor_role'  => 'customer',
                'actor_id'    => $customerId,
            ]);

            // If payment was paid, mark as refunded / pending refund
            if ($order['payment_status'] === 'paid') {
                $pdo->prepare("UPDATE orders SET payment_status = 'refunded' WHERE id = :id")->execute(['id' => $orderId]);
                $pdo->prepare("UPDATE payments SET payment_status = 'refunded' WHERE order_id = :order_id")->execute(['order_id' => $orderId]);
            }

            $pdo->commit();

            Response::json([
                'order_id'       => $orderId,
                'order_number'   => $order['order_number'],
                'order_status'   => 'cancelled',
                'payment_status' => ($order['payment_status'] === 'paid') ? 'refunded' : $order['payment_status'],
                'reason'         => $reason,
                'inventory_restored' => true,
            ], 200, 'Order cancelled successfully and inventory restored');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to cancel order: ' . $e->getMessage(), 500);
        }
    }

    // --- Helpers ---

    private function resolveAddress(PDO $pdo, int $customerId, int $addressId): ?array
    {
        if ($addressId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM user_addresses WHERE id = :id AND user_id = :uid LIMIT 1');
            $stmt->execute(['id' => $addressId, 'uid' => $customerId]);
            $addr = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($addr) {
                $addr['id'] = (int)$addr['id'];
                $addr['is_default'] = (bool)(int)$addr['is_default'];
                return $addr;
            }
        }

        // Default or most recent address
        $stmt = $pdo->prepare(
            'SELECT * FROM user_addresses WHERE user_id = :uid ORDER BY is_default DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $customerId]);
        $addr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($addr) {
            $addr['id'] = (int)$addr['id'];
            $addr['is_default'] = (bool)(int)$addr['is_default'];
            return $addr;
        }

        return null;
    }

    private function evaluateCartItems(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT ci.id AS cart_item_id, ci.product_id, ci.quantity,
                    p.title, p.price, p.mrp, p.stock_quantity, p.status,
                    u.is_active AS seller_active, sp.store_name,
                    (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
             FROM cart_items ci
             JOIN products p ON ci.product_id = p.id
             JOIN users u ON p.seller_id = u.id
             LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
             WHERE ci.customer_id = :uid
             ORDER BY ci.id DESC'
        );
        $stmt->execute(['uid' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $subtotal = 0.0;
        $totalMrp = 0.0;
        $totalQuantity = 0;
        $hasUnavailableItems = false;

        foreach ($rows as $row) {
            $qty = (int)$row['quantity'];
            $unitPrice = (float)$row['price'];
            $mrp = $row['mrp'] !== null ? (float)$row['mrp'] : $unitPrice;
            $stock = (int)$row['stock_quantity'];

            $isAvailable = ($row['status'] === 'published') && ((int)$row['seller_active'] === 1) && ($stock >= $qty);
            if (!$isAvailable) {
                $hasUnavailableItems = true;
            }

            $lineTotal = round($unitPrice * $qty, 2);
            $subtotal += $lineTotal;
            $totalMrp += round($mrp * $qty, 2);
            $totalQuantity += $qty;

            $items[] = [
                'cart_item_id'      => (int)$row['cart_item_id'],
                'product_id'        => (int)$row['product_id'],
                'title'             => $row['title'],
                'seller_store_name' => $row['store_name'] ?? 'Authorized Seller',
                'primary_image_url' => $row['primary_image_url'],
                'unit_price'        => $unitPrice,
                'mrp'               => $mrp,
                'quantity'          => $qty,
                'line_total'        => $lineTotal,
                'stock_quantity'    => $stock,
                'is_available'      => $isAvailable,
            ];
        }

        $subtotal = round($subtotal, 2);
        $shippingFee = 0.00; // Free TechKart Express Priority delivery
        $discount = round(max(0.0, $totalMrp - $subtotal), 2);
        $grandTotal = round($subtotal + $shippingFee, 2);

        return [
            'items' => $items,
            'has_unavailable_items' => $hasUnavailableItems,
            'pricing' => [
                'items_subtotal' => $subtotal,
                'shipping_fee'   => $shippingFee,
                'total_discount' => $discount,
                'grand_total'    => $grandTotal,
                'total_quantity' => $totalQuantity,
                'currency'       => 'INR',
            ]
        ];
    }

    /**
     * Get live order shipment tracking details & persisted timeline events (SCR-34).
     * GET /api/v1/customer/orders/{id}/tracking
     * Param: {id} can be order primary key ID, order_number, or tracking_number.
     */
    public function track(Request $request, array $params): void
    {
        $customerId = $this->resolveCustomerId($request);
        $orderIdent = trim((string)($params['id'] ?? ''));

        if ($orderIdent === '') {
            Response::error('Invalid order or tracking identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT o.id, o.order_number, o.customer_id, o.total_amount, o.payment_status, o.order_status,
                             o.tracking_number, o.courier_name, o.courier_phone, o.estimated_delivery, o.estimated_time,
                             o.current_location, o.next_stop, o.shipping_address, o.created_at, o.updated_at,
                             u.full_name AS customer_name, u.phone AS customer_phone
                      FROM orders o
                      JOIN users u ON o.customer_id = u.id
                      WHERE ' . ($isNumeric ? '(o.id = :id OR o.order_number = :ord_num OR o.tracking_number = :track_num)' : '(o.order_number = :ord_num OR o.tracking_number = :track_num)') . '
                      AND o.customer_id = :cust_id
                      LIMIT 1';

            $stmt = $pdo->prepare($query);
            $binds = [
                'ord_num'   => $orderIdent,
                'track_num' => $orderIdent,
                'cust_id'   => $customerId,
            ];
            if ($isNumeric) {
                $binds['id'] = (int)$orderIdent;
            }
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order or tracking record not found.', 404);
            }

            $orderId = (int)$order['id'];

            // Fetch persisted shipment events
            $evtStmt = $pdo->prepare(
                'SELECT id, status, title, description, location, tag_label, actor_role, created_at
                 FROM shipment_events
                 WHERE order_id = :order_id
                 ORDER BY created_at ASC, id ASC'
            );
            $evtStmt->execute(['order_id' => $orderId]);
            $events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch order items for shipment preview
            $itemStmt = $pdo->prepare(
                'SELECT oi.id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status,
                        p.title, p.slug,
                        (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                 FROM order_items oi
                 JOIN products p ON oi.product_id = p.id
                 WHERE oi.order_id = :order_id
                 ORDER BY oi.id ASC'
            );
            $itemStmt->execute(['order_id' => $orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $shippingAddress = json_decode($order['shipping_address'] ?? '[]', true);
            $recipientName = $shippingAddress['recipient_name'] ?? $order['customer_name'] ?? 'Valued Customer';
            $addrLines = array_filter([
                $shippingAddress['address_line1'] ?? '',
                $shippingAddress['address_line2'] ?? '',
                $shippingAddress['city'] ?? '',
                $shippingAddress['state'] ?? '',
            ]);
            $recipientAddress = !empty($addrLines) ? implode(', ', $addrLines) : 'Delivery Address';

            // Calculate progress stepper values based on order status
            $currentStatus = $order['order_status'];
            $statusStep = match ($currentStatus) {
                'placed'     => 1,
                'processing' => 2,
                'shipped'    => 3,
                'delivered'  => 4,
                'cancelled'  => 0,
                default      => 1,
            };
            $stepText = $statusStep > 0 ? "Step {$statusStep} of 4" : "Cancelled";
            $progressPercent = match ($statusStep) {
                1 => 25,
                2 => 50,
                3 => 75,
                4 => 100,
                default => 0,
            };

            $formattedTimeline = array_map(function ($evt) {
                $time = strtotime($evt['created_at']);
                $dateFormatted = date('Y-m-d') === date('Y-m-d', $time)
                    ? 'Today, ' . date('M d • h:i A', $time)
                    : date('M d, Y • h:i A', $time);

                return [
                    'id'          => (int)$evt['id'],
                    'status'      => $evt['status'],
                    'title'       => $evt['title'],
                    'description' => $evt['description'],
                    'location'    => $evt['location'],
                    'tag_label'   => $evt['tag_label'],
                    'actor_role'  => $evt['actor_role'],
                    'date_time'   => $dateFormatted,
                    'timestamp'   => $evt['created_at'],
                ];
            }, $events);

            $headlineStatus = match ($currentStatus) {
                'placed'     => 'Order Placed & Verification Pending',
                'processing' => 'Package Scanned & Being Prepared',
                'shipped'    => 'In Transit — Regional Hub Transfer',
                'delivered'  => 'Delivered & Handed Over',
                'cancelled'  => 'Order Cancelled',
                default      => ucfirst($currentStatus),
            };

            Response::json([
                'order_id'           => $orderId,
                'order_number'       => $order['order_number'],
                'tracking_number'    => $order['tracking_number'] ?: ('APX-' . rand(100000, 999999)),
                'order_status'       => $order['order_status'],
                'current_status'     => $headlineStatus,
                'payment_status'     => $order['payment_status'],
                'estimated_delivery' => $order['estimated_delivery'] ?: date('l, M d', strtotime('+1 day')),
                'estimated_time'     => $order['estimated_time'] ?: 'By 6:00 PM • On Schedule',
                'current_location'   => $order['current_location'] ?: 'Sorting Facility',
                'next_stop'          => $order['next_stop'] ?: 'North Banani Dispatch Center',
                'courier_name'       => $order['courier_name'] ?: 'Apex Courier Express',
                'courier_phone'      => $order['courier_phone'] ?: '+880 1700-112233',
                'recipient_name'     => $recipientName,
                'recipient_address'  => $recipientAddress,
                'step_text'          => $stepText,
                'progress_percent'   => $progressPercent,
                'progress_factor'    => $progressPercent / 100.0,
                'items_count'        => count($items),
                'items'              => array_map(function ($it) {
                    return [
                        'item_id'           => (int)$it['id'],
                        'product_id'        => (int)$it['product_id'],
                        'title'             => $it['title'],
                        'unit_price'        => (float)$it['unit_price'],
                        'quantity'          => (int)$it['quantity'],
                        'subtotal'          => (float)$it['subtotal'],
                        'item_status'       => $it['item_status'],
                        'primary_image_url' => $it['primary_image_url'],
                    ];
                }, $items),
                'timeline'           => $formattedTimeline,
                'created_at'         => $order['created_at'],
                'updated_at'         => $order['updated_at'],
            ], 200, 'Order tracking details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve tracking details: ' . $e->getMessage(), 500);
        }
    }
}
