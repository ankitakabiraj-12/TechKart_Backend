<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Order Management for Sellers & Admins (TechKart Buddy).
 * Protected by AuthMiddleware(['seller', 'admin']).
 *
 * Rules:
 * - Sellers can ONLY view order items belonging to their seller_id (multi-seller isolation).
 * - Sellers can update item fulfillment status ('placed', 'processing', 'shipped', 'delivered', 'cancelled').
 * - Admins can view marketplace orders across all sellers and update global order status.
 */
class BuddyOrderController
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
     * List orders for the authenticated seller or admin.
     * GET /api/v1/buddy/orders
     * Query: status?, page?, limit?
     */
    public function index(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];

        $status = strtolower(trim((string)$request->getQuery('status', '')));
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 15)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            if ($role === 'seller') {
                // Sellers only see distinct orders containing their products
                $conditions = ['oi.seller_id = :seller_id'];
                $params = ['seller_id' => $sellerId];

                if ($status !== '' && $status !== 'all') {
                    $conditions[] = 'oi.item_status = :status';
                    $params['status'] = $status;
                }

                $whereSql = implode(' AND ', $conditions);

                $countStmt = $pdo->prepare(
                    "SELECT COUNT(DISTINCT o.id)
                     FROM orders o
                     JOIN order_items oi ON o.id = oi.order_id
                     WHERE {$whereSql}"
                );
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $sql = "SELECT o.id, o.order_number, o.created_at, o.updated_at,
                               o.payment_status, o.order_status, o.shipping_address, o.rejection_reason,
                               u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                               SUM(oi.subtotal) AS seller_subtotal,
                               SUM(oi.quantity) AS seller_quantity,
                               GROUP_CONCAT(DISTINCT oi.item_status) AS seller_item_statuses
                        FROM orders o
                        JOIN order_items oi ON o.id = oi.order_id
                        JOIN users u ON o.customer_id = u.id
                        WHERE {$whereSql}
                        GROUP BY o.id
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

                // Attach seller-specific items
                if (!empty($orders)) {
                    $orderIds = array_column($orders, 'id');
                    $inQuery = implode(',', array_map('intval', $orderIds));

                    $itemStmt = $pdo->query(
                        "SELECT oi.id, oi.order_id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status, oi.rejection_reason,
                                p.title, p.slug,
                                (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                         FROM order_items oi
                         JOIN products p ON oi.product_id = p.id
                         WHERE oi.seller_id = {$sellerId} AND oi.order_id IN ({$inQuery})"
                    );
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                    $itemsByOrder = [];
                    foreach ($items as $it) {
                        $itemsByOrder[$it['order_id']][] = [
                            'item_id'           => (int)$it['id'],
                            'product_id'        => (int)$it['product_id'],
                            'title'             => $it['title'],
                            'unit_price'        => (float)$it['unit_price'],
                            'quantity'          => (int)$it['quantity'],
                            'subtotal'          => (float)$it['subtotal'],
                            'item_status'       => $it['item_status'],
                            'rejection_reason'  => $it['rejection_reason'],
                            'primary_image_url' => $it['primary_image_url'],
                        ];
                    }

                    foreach ($orders as &$ord) {
                        $ord['id'] = (int)$ord['id'];
                        $ord['seller_subtotal'] = (float)$ord['seller_subtotal'];
                        $ord['seller_quantity'] = (int)$ord['seller_quantity'];
                        $ord['shipping_address'] = json_decode($ord['shipping_address'], true);
                        $ord['items'] = $itemsByOrder[$ord['id']] ?? [];
                    }
                    unset($ord);
                }
            } else {
                // Admin: Full marketplace oversight
                $conditions = ['1=1'];
                $params = [];

                if ($status !== '' && $status !== 'all') {
                    $conditions[] = 'o.order_status = :status';
                    $params['status'] = $status;
                }

                $whereSql = implode(' AND ', $conditions);

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE {$whereSql}");
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $sql = "SELECT o.id, o.order_number, o.total_amount, o.payment_status, o.order_status,
                               o.shipping_address, o.created_at, o.updated_at,
                               u.full_name AS customer_name, u.email AS customer_email,
                               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) AS items_count
                        FROM orders o
                        JOIN users u ON o.customer_id = u.id
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

                foreach ($orders as &$ord) {
                    $ord['id'] = (int)$ord['id'];
                    $ord['total_amount'] = (float)$ord['total_amount'];
                    $ord['items_count'] = (int)$ord['items_count'];
                    $ord['shipping_address'] = json_decode($ord['shipping_address'], true);
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
     * Get specific order details for seller or admin.
     * GET /api/v1/buddy/orders/{id}
     */
    public function show(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $orderIdent = trim((string)($params['id'] ?? ''));

        if ($orderIdent === '') {
            Response::error('Invalid order identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT o.id, o.order_number, o.customer_id, o.total_amount, o.payment_status, o.order_status,
                             o.shipping_address, o.rejection_reason, o.created_at, o.updated_at,
                             u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
                      FROM orders o
                      JOIN users u ON o.customer_id = u.id
                      WHERE ' . ($isNumeric ? '(o.id = :id OR o.order_number = :ord_num)' : 'o.order_number = :ord_num') . '
                      LIMIT 1';

            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            $orderId = (int)$order['id'];

            // Fetch items
            if ($role === 'seller') {
                $itemStmt = $pdo->prepare(
                    'SELECT oi.id AS order_item_id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status, oi.rejection_reason,
                            p.title, p.slug,
                            (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                     FROM order_items oi
                     JOIN products p ON oi.product_id = p.id
                     WHERE oi.order_id = :order_id AND oi.seller_id = :seller_id
                     ORDER BY oi.id ASC'
                );
                $itemStmt->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($items)) {
                    Response::error('Forbidden. This order contains no items belonging to your store.', 403);
                }
            } else {
                $itemStmt = $pdo->prepare(
                    'SELECT oi.id AS order_item_id, oi.seller_id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status, oi.rejection_reason,
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
            }

            // Fetch payment records
            $payStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id = :order_id ORDER BY id DESC');
            $payStmt->execute(['order_id' => $orderId]);
            $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            $order['id'] = $orderId;
            $order['total_amount'] = (float)$order['total_amount'];
            $order['shipping_address'] = json_decode($order['shipping_address'], true);
            $order['items'] = array_map(function ($it) {
                $it['order_item_id'] = (int)$it['order_item_id'];
                $it['product_id'] = (int)$it['product_id'];
                $it['unit_price'] = (float)$it['unit_price'];
                $it['quantity'] = (int)$it['quantity'];
                $it['subtotal'] = (float)$it['subtotal'];
                return $it;
            }, $items);
            $order['payments'] = $payments;

            Response::json($order, 200, 'Order details retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve order details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update order item status (for sellers) or overall order status (for admin).
     * PATCH or PUT /api/v1/buddy/orders/{id}/status
     * Body: { status: string, item_id?: int }
     */
    public function updateStatus(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $orderIdent = trim((string)($params['id'] ?? ''));

        $newStatus = strtolower(trim((string)$request->get('status', '')));
        $itemId = (int)$request->get('item_id', 0);
        $courierName = trim((string)$request->get('courier_name', ''));
        $courierPhone = trim((string)$request->get('courier_phone', ''));
        $currentLocation = trim((string)$request->get('current_location', ''));
        $nextStop = trim((string)$request->get('next_stop', ''));
        $trackingNumber = trim((string)$request->get('tracking_number', ''));
        $eventDescription = trim((string)$request->get('description', ''));

        $allowedStatuses = ['placed', 'processing', 'shipped', 'delivered', 'cancelled'];
        if (!in_array($newStatus, $allowedStatuses, true)) {
            Response::error('Invalid status value.', 422, [
                'status' => 'Allowed statuses: ' . implode(', ', $allowedStatuses)
            ]);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT id, order_number, customer_id, order_status, payment_status, tracking_number, courier_name, courier_phone, current_location, next_stop FROM orders WHERE ' . ($isNumeric ? '(id = :id OR order_number = :ord_num)' : 'order_number = :ord_num') . ' LIMIT 1';
            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            $orderId = (int)$order['id'];
            $currentStatus = $order['order_status'];

            // VALID STATUS TRANSITION RULES:
            // placed -> processing -> shipped -> delivered
            // cancelled is terminal; delivered is terminal
            // Cannot transition backwards or skip from placed directly to delivered
            $validTransitions = [
                'placed'     => ['processing', 'cancelled'],
                'processing' => ['shipped', 'cancelled'],
                'shipped'    => ['delivered'],
                'delivered'  => [],
                'cancelled'  => [],
            ];

            // If same status, allow updating courier details without transition error
            if ($newStatus !== $currentStatus) {
                if (!isset($validTransitions[$currentStatus]) || !in_array($newStatus, $validTransitions[$currentStatus], true)) {
                    Response::error("Invalid status transition from '{$currentStatus}' to '{$newStatus}'.", 400, [
                        'current_status'    => $currentStatus,
                        'target_status'     => $newStatus,
                        'valid_transitions' => $validTransitions[$currentStatus] ?? [],
                    ]);
                }
            }

            // Prepare courier and location updates
            $updatedTracking = $trackingNumber !== '' ? $trackingNumber : ($order['tracking_number'] ?: ('APX-' . rand(100000, 999999)));
            $updatedCourierName = $courierName !== '' ? $courierName : ($order['courier_name'] ?: 'Apex Courier Express');
            $updatedCourierPhone = $courierPhone !== '' ? $courierPhone : ($order['courier_phone'] ?: '+880 1700-112233');
            $updatedCurrentLocation = $currentLocation !== '' ? $currentLocation : ($order['current_location'] ?: 'Sorting Facility');
            $updatedNextStop = $nextStop !== '' ? $nextStop : ($order['next_stop'] ?: 'Customer Destination');

            if ($role === 'seller') {
                // Ensure the seller owns items in this order
                $checkSellerItems = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND seller_id = :seller_id');
                $checkSellerItems->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
                if ((int)$checkSellerItems->fetchColumn() === 0) {
                    Response::error('Forbidden. This order contains no items belonging to your store.', 403);
                }

                // Seller updates their item(s) status
                if ($itemId > 0) {
                    $itemUpdateStmt = $pdo->prepare(
                        'UPDATE order_items SET item_status = :status WHERE id = :item_id AND order_id = :order_id AND seller_id = :seller_id'
                    );
                    $itemUpdateStmt->execute([
                        'status'    => $newStatus,
                        'item_id'   => $itemId,
                        'order_id'  => $orderId,
                        'seller_id' => $sellerId,
                    ]);
                } else {
                    $itemUpdateStmt = $pdo->prepare(
                        'UPDATE order_items SET item_status = :status WHERE order_id = :order_id AND seller_id = :seller_id'
                    );
                    $itemUpdateStmt->execute([
                        'status'    => $newStatus,
                        'order_id'  => $orderId,
                        'seller_id' => $sellerId,
                    ]);
                }

                // If all items across all sellers in this order are now in newStatus, or seller triggers status, synchronize order & tracking
                $statusCheckStmt = $pdo->prepare('SELECT DISTINCT item_status FROM order_items WHERE order_id = :order_id');
                $statusCheckStmt->execute(['order_id' => $orderId]);
                $statuses = $statusCheckStmt->fetchAll(PDO::FETCH_COLUMN);

                $orderUpdated = false;
                if (count($statuses) === 1 && $statuses[0] === $newStatus) {
                    $pdo->prepare(
                        'UPDATE orders SET order_status = :status, tracking_number = :track, courier_name = :cname, courier_phone = :cphone,
                                           current_location = :loc, next_stop = :next, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                    )->execute([
                        'status' => $newStatus,
                        'track'  => $updatedTracking,
                        'cname'  => $updatedCourierName,
                        'cphone' => $updatedCourierPhone,
                        'loc'    => $updatedCurrentLocation,
                        'next'   => $updatedNextStop,
                        'id'     => $orderId,
                    ]);
                    $orderUpdated = true;
                } else {
                    $pdo->prepare(
                        'UPDATE orders SET tracking_number = :track, courier_name = :cname, courier_phone = :cphone,
                                           current_location = :loc, next_stop = :next, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                    )->execute([
                        'track'  => $updatedTracking,
                        'cname'  => $updatedCourierName,
                        'cphone' => $updatedCourierPhone,
                        'loc'    => $updatedCurrentLocation,
                        'next'   => $updatedNextStop,
                        'id'     => $orderId,
                    ]);
                }

                // Record persisted shipment event
                $eventTitle = match ($newStatus) {
                    'processing' => 'Package Scanned & Processed',
                    'shipped'    => 'Departed Sorting Facility',
                    'delivered'  => 'Package Delivered Successfully',
                    'cancelled'  => 'Order Cancelled by Seller',
                    default      => 'Fulfillment Status Updated',
                };
                $desc = $eventDescription !== '' ? $eventDescription : match ($newStatus) {
                    'processing' => 'Merchant packed and prepared package for logistics partner handover.',
                    'shipped'    => "Package handed over to {$updatedCourierName}. Tracking #{$updatedTracking}. In transit to {$updatedNextStop}.",
                    'delivered'  => 'Package handed over to recipient. Delivery confirmed.',
                    'cancelled'  => 'Seller cancelled the order fulfillment.',
                    default      => "Fulfillment status updated to {$newStatus}.",
                };
                $tagLabel = match ($newStatus) {
                    'processing' => 'Processing',
                    'shipped'    => 'In Transit',
                    'delivered'  => 'Delivered',
                    'cancelled'  => 'Cancelled',
                    default      => ucfirst($newStatus),
                };

                $evtStmt = $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                );
                $evtStmt->execute([
                    'order_id'    => $orderId,
                    'status'      => $newStatus,
                    'title'       => $eventTitle,
                    'description' => $desc,
                    'location'    => $updatedCurrentLocation,
                    'tag_label'   => $tagLabel,
                    'actor_role'  => 'seller',
                    'actor_id'    => $sellerId,
                ]);

                Response::json([
                    'order_id'         => $orderId,
                    'order_number'     => $order['order_number'],
                    'item_status'      => $newStatus,
                    'order_status'     => $orderUpdated ? $newStatus : $currentStatus,
                    'tracking_number'  => $updatedTracking,
                    'courier_name'     => $updatedCourierName,
                    'current_location' => $updatedCurrentLocation,
                    'next_stop'        => $updatedNextStop,
                    'updated_by'       => 'seller',
                ], 200, "Order item status updated to '{$newStatus}'");
            } else {
                // Admin can update global order status and cascade to all items
                $pdo->prepare(
                    'UPDATE orders SET order_status = :status, tracking_number = :track, courier_name = :cname, courier_phone = :cphone,
                                       current_location = :loc, next_stop = :next, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute([
                    'status' => $newStatus,
                    'track'  => $updatedTracking,
                    'cname'  => $updatedCourierName,
                    'cphone' => $updatedCourierPhone,
                    'loc'    => $updatedCurrentLocation,
                    'next'   => $updatedNextStop,
                    'id'     => $orderId,
                ]);
                $pdo->prepare('UPDATE order_items SET item_status = :status WHERE order_id = :id')->execute([
                    'status' => $newStatus,
                    'id'     => $orderId,
                ]);

                // Record persisted shipment event
                $eventTitle = match ($newStatus) {
                    'processing' => 'Package Scanned & Processed',
                    'shipped'    => 'Departed Sorting Facility',
                    'delivered'  => 'Package Delivered Successfully',
                    'cancelled'  => 'Order Cancelled by Admin',
                    default      => 'Fulfillment Status Updated',
                };
                $desc = $eventDescription !== '' ? $eventDescription : match ($newStatus) {
                    'processing' => 'Admin verified order; packaging and verification approved.',
                    'shipped'    => "Package departed central logistics facility via {$updatedCourierName}. In transit to {$updatedNextStop}.",
                    'delivered'  => 'Order marked delivered across all marketplace items.',
                    'cancelled'  => 'Order cancelled by marketplace administrator.',
                    default      => "Order status updated to {$newStatus}.",
                };
                $tagLabel = match ($newStatus) {
                    'processing' => 'Processing',
                    'shipped'    => 'In Transit',
                    'delivered'  => 'Delivered',
                    'cancelled'  => 'Cancelled',
                    default      => ucfirst($newStatus),
                };

                $evtStmt = $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                );
                $evtStmt->execute([
                    'order_id'    => $orderId,
                    'status'      => $newStatus,
                    'title'       => $eventTitle,
                    'description' => $desc,
                    'location'    => $updatedCurrentLocation,
                    'tag_label'   => $tagLabel,
                    'actor_role'  => 'admin',
                    'actor_id'    => $user['id'],
                ]);

                Response::json([
                    'order_id'         => $orderId,
                    'order_number'     => $order['order_number'],
                    'order_status'     => $newStatus,
                    'tracking_number'  => $updatedTracking,
                    'courier_name'     => $updatedCourierName,
                    'current_location' => $updatedCurrentLocation,
                    'next_stop'        => $updatedNextStop,
                    'updated_by'       => 'admin',
                ], 200, "Order status updated to '{$newStatus}'");
            }
        } catch (Throwable $e) {
            Response::error('Failed to update order status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Accept an order (or seller's order item).
     * POST|PUT /api/v1/buddy/orders/{id}/accept
     * Body: { item_id?: int, notes?: string }
     */
    public function accept(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $orderIdent = trim((string)($params['id'] ?? ''));
        $itemId = (int)$request->get('item_id', 0);
        $notes = trim((string)$request->get('notes', 'Order accepted by seller. Ready for fulfillment.'));

        if ($orderIdent === '') {
            Response::error('Invalid order identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT id, order_number, order_status, customer_id, shipping_address FROM orders WHERE ' . ($isNumeric ? '(id = :id OR order_number = :ord_num)' : 'order_number = :ord_num') . ' LIMIT 1';
            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            $orderId = (int)$order['id'];
            $currentStatus = $order['order_status'];

            // Prevent invalid transition: can only accept orders in 'placed' status
            if ($currentStatus !== 'placed' && $currentStatus !== 'processing') {
                Response::error("Cannot accept order in '{$currentStatus}' status. Only newly placed orders can be accepted.", 400, [
                    'current_status' => $currentStatus,
                    'allowed_status' => 'placed'
                ]);
            }

            if ($role === 'seller') {
                // Enforce seller ownership
                $checkSeller = $pdo->prepare('SELECT id, item_status FROM order_items WHERE order_id = :order_id AND seller_id = :seller_id');
                $checkSeller->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
                $sellerItems = $checkSeller->fetchAll(PDO::FETCH_ASSOC);

                if (empty($sellerItems)) {
                    Response::error('Forbidden. This order contains no items belonging to your store.', 403);
                }

                // If itemId specified, verify ownership of that item
                if ($itemId > 0) {
                    $itemBelongs = false;
                    foreach ($sellerItems as $si) {
                        if ((int)$si['id'] === $itemId) {
                            $itemBelongs = true;
                            if ($si['item_status'] !== 'placed' && $si['item_status'] !== 'processing') {
                                Response::error("Item #{$itemId} is in status '{$si['item_status']}' and cannot be accepted.", 400);
                            }
                            break;
                        }
                    }
                    if (!$itemBelongs) {
                        Response::error('Forbidden. The specified order item does not belong to your store.', 403);
                    }

                    $pdo->prepare('UPDATE order_items SET item_status = :st WHERE id = :item_id AND order_id = :order_id AND seller_id = :seller_id')
                        ->execute(['st' => 'processing', 'item_id' => $itemId, 'order_id' => $orderId, 'seller_id' => $sellerId]);
                } else {
                    $pdo->prepare('UPDATE order_items SET item_status = :st WHERE order_id = :order_id AND seller_id = :seller_id')
                        ->execute(['st' => 'processing', 'order_id' => $orderId, 'seller_id' => $sellerId]);
                }

                // Check if all order items are now accepted/processing
                $allStatusesStmt = $pdo->prepare('SELECT DISTINCT item_status FROM order_items WHERE order_id = :order_id');
                $allStatusesStmt->execute(['order_id' => $orderId]);
                $allStatuses = $allStatusesStmt->fetchAll(PDO::FETCH_COLUMN);

                $orderUpdated = false;
                if (!in_array('placed', $allStatuses, true) && !in_array('cancelled', $allStatuses, true)) {
                    $pdo->prepare('UPDATE orders SET order_status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                        ->execute(['st' => 'processing', 'id' => $orderId]);
                    $orderUpdated = true;
                }

                // Audit log
                $auditStmt = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, ip_address, details)
                     VALUES (:actor_id, :actor_role, :actor_name, :action, :target_type, :target_id, :ip_address, :details)'
                );
                $auditStmt->execute([
                    'actor_id'    => $sellerId,
                    'actor_role'  => 'seller',
                    'actor_name'  => $user['name'] ?? 'Seller Store',
                    'action'      => 'ORDER_ACCEPT',
                    'target_type' => 'orders',
                    'target_id'   => (string)$orderId,
                    'ip_address'  => $request->getIp() ?? '127.0.0.1',
                    'details'     => json_encode([
                        'order_number' => $order['order_number'],
                        'item_id'      => $itemId > 0 ? $itemId : 'all_seller_items',
                        'notes'        => $notes,
                        'new_status'   => 'processing',
                    ]),
                ]);

                // Record shipment event
                $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                )->execute([
                    'order_id'    => $orderId,
                    'status'      => 'processing',
                    'title'       => 'Order Accepted by Merchant',
                    'description' => $notes,
                    'location'    => 'Merchant Warehouse',
                    'tag_label'   => 'Confirmed',
                    'actor_role'  => 'seller',
                    'actor_id'    => $sellerId,
                ]);

                Response::json([
                    'order_id'     => $orderId,
                    'order_number' => $order['order_number'],
                    'order_status' => $orderUpdated ? 'processing' : $currentStatus,
                    'item_status'  => 'processing',
                    'action'       => 'accepted',
                ], 200, "Order #{$order['order_number']} accepted successfully.");
            } else {
                // Admin accepting order
                $pdo->prepare('UPDATE orders SET order_status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['st' => 'processing', 'id' => $orderId]);
                $pdo->prepare('UPDATE order_items SET item_status = :st WHERE order_id = :id AND item_status = :placed')
                    ->execute(['st' => 'processing', 'id' => $orderId, 'placed' => 'placed']);

                // Audit log
                $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, ip_address, details)
                     VALUES (:actor_id, :actor_role, :actor_name, :action, :target_type, :target_id, :ip_address, :details)'
                )->execute([
                    'actor_id'    => (int)$user['id'],
                    'actor_role'  => 'admin',
                    'actor_name'  => $user['name'] ?? 'System Admin',
                    'action'      => 'ORDER_ACCEPT',
                    'target_type' => 'orders',
                    'target_id'   => (string)$orderId,
                    'ip_address'  => $request->getIp() ?? '127.0.0.1',
                    'details'     => json_encode([
                        'order_number' => $order['order_number'],
                        'notes'        => $notes,
                        'new_status'   => 'processing',
                    ]),
                ]);

                // Shipment event
                $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                )->execute([
                    'order_id'    => $orderId,
                    'status'      => 'processing',
                    'title'       => 'Order Accepted by Admin',
                    'description' => $notes,
                    'location'    => 'Central Operations Hub',
                    'tag_label'   => 'Verified',
                    'actor_role'  => 'admin',
                    'actor_id'    => (int)$user['id'],
                ]);

                Response::json([
                    'order_id'     => $orderId,
                    'order_number' => $order['order_number'],
                    'order_status' => 'processing',
                    'action'       => 'accepted',
                ], 200, "Order #{$order['order_number']} accepted by administrator.");
            }
        } catch (Throwable $e) {
            Response::error('Failed to accept order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject an order (or seller's order item).
     * POST|PUT /api/v1/buddy/orders/{id}/reject
     * Body: { reason: string (MANDATORY), item_id?: int }
     */
    public function reject(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $orderIdent = trim((string)($params['id'] ?? ''));
        $itemId = (int)$request->get('item_id', 0);
        $reason = trim((string)$request->get('reason', ''));

        if ($orderIdent === '') {
            Response::error('Invalid order identifier.', 400);
        }

        // MANDATORY REJECTION REASON VALIDATION
        if ($reason === '') {
            Response::error('Validation error. Rejection reason is mandatory.', 422, [
                'reason' => 'A valid rejection reason must be provided (e.g. out of stock, unserviceable location, pricing error).'
            ]);
        }

        if (strlen($reason) < 5) {
            Response::error('Validation error. Rejection reason must be at least 5 characters.', 422, [
                'reason' => 'Please provide a descriptive reason for customer notification.'
            ]);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT id, order_number, order_status, customer_id, shipping_address FROM orders WHERE ' . ($isNumeric ? '(id = :id OR order_number = :ord_num)' : 'order_number = :ord_num') . ' LIMIT 1';
            $stmt = $pdo->prepare($query);
            $binds = ['ord_num' => $orderIdent];
            if ($isNumeric) $binds['id'] = (int)$orderIdent;
            $stmt->execute($binds);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                Response::error('Order not found.', 404);
            }

            $orderId = (int)$order['id'];
            $currentStatus = $order['order_status'];

            // Prevent invalid transitions: cannot reject already shipped or delivered orders
            if (in_array($currentStatus, ['shipped', 'delivered', 'cancelled'], true)) {
                Response::error("Cannot reject order in '{$currentStatus}' status. Only pending or unfulfilled orders can be rejected.", 400, [
                    'current_status' => $currentStatus
                ]);
            }

            if ($role === 'seller') {
                // Enforce seller ownership
                $checkSeller = $pdo->prepare('SELECT id, item_status FROM order_items WHERE order_id = :order_id AND seller_id = :seller_id');
                $checkSeller->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
                $sellerItems = $checkSeller->fetchAll(PDO::FETCH_ASSOC);

                if (empty($sellerItems)) {
                    Response::error('Forbidden. This order contains no items belonging to your store.', 403);
                }

                // If itemId specified, verify ownership of that item
                if ($itemId > 0) {
                    $itemBelongs = false;
                    foreach ($sellerItems as $si) {
                        if ((int)$si['id'] === $itemId) {
                            $itemBelongs = true;
                            if (in_array($si['item_status'], ['shipped', 'delivered', 'cancelled'], true)) {
                                Response::error("Item #{$itemId} is in status '{$si['item_status']}' and cannot be rejected.", 400);
                            }
                            break;
                        }
                    }
                    if (!$itemBelongs) {
                        Response::error('Forbidden. The specified order item does not belong to your store.', 403);
                    }

                    $pdo->prepare('UPDATE order_items SET item_status = :st, rejection_reason = :reason WHERE id = :item_id AND order_id = :order_id AND seller_id = :seller_id')
                        ->execute([
                            'st'        => 'cancelled',
                            'reason'    => $reason,
                            'item_id'   => $itemId,
                            'order_id'  => $orderId,
                            'seller_id' => $sellerId,
                        ]);
                } else {
                    $pdo->prepare('UPDATE order_items SET item_status = :st, rejection_reason = :reason WHERE order_id = :order_id AND seller_id = :seller_id')
                        ->execute([
                            'st'        => 'cancelled',
                            'reason'    => $reason,
                            'order_id'  => $orderId,
                            'seller_id' => $sellerId,
                        ]);
                }

                // Check if all items in order are now cancelled
                $allStatusesStmt = $pdo->prepare('SELECT DISTINCT item_status FROM order_items WHERE order_id = :order_id');
                $allStatusesStmt->execute(['order_id' => $orderId]);
                $allStatuses = $allStatusesStmt->fetchAll(PDO::FETCH_COLUMN);

                $orderCancelled = false;
                if (count($allStatuses) === 1 && $allStatuses[0] === 'cancelled') {
                    $pdo->prepare('UPDATE orders SET order_status = :st, rejection_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                        ->execute(['st' => 'cancelled', 'reason' => $reason, 'id' => $orderId]);
                    $orderCancelled = true;
                }

                // Audit log entry
                $auditStmt = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, ip_address, details)
                     VALUES (:actor_id, :actor_role, :actor_name, :action, :target_type, :target_id, :ip_address, :details)'
                );
                $auditStmt->execute([
                    'actor_id'    => $sellerId,
                    'actor_role'  => 'seller',
                    'actor_name'  => $user['name'] ?? 'Seller Store',
                    'action'      => 'ORDER_REJECT',
                    'target_type' => 'orders',
                    'target_id'   => (string)$orderId,
                    'ip_address'  => $request->getIp() ?? '127.0.0.1',
                    'details'     => json_encode([
                        'order_number' => $order['order_number'],
                        'item_id'      => $itemId > 0 ? $itemId : 'all_seller_items',
                        'reason'       => $reason,
                        'new_status'   => 'cancelled',
                    ]),
                ]);

                // Record shipment / fulfillment event
                $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                )->execute([
                    'order_id'    => $orderId,
                    'status'      => 'cancelled',
                    'title'       => 'Order Item Rejected by Seller',
                    'description' => "Order rejection reason: {$reason}",
                    'location'    => 'Merchant Warehouse',
                    'tag_label'   => 'Rejected',
                    'actor_role'  => 'seller',
                    'actor_id'    => $sellerId,
                ]);

                Response::json([
                    'order_id'         => $orderId,
                    'order_number'     => $order['order_number'],
                    'order_status'     => $orderCancelled ? 'cancelled' : $currentStatus,
                    'item_status'      => 'cancelled',
                    'rejection_reason' => $reason,
                    'action'           => 'rejected',
                ], 200, "Order #{$order['order_number']} item rejected.");
            } else {
                // Admin rejection
                $pdo->prepare('UPDATE orders SET order_status = :st, rejection_reason = :reason, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                    ->execute(['st' => 'cancelled', 'reason' => $reason, 'id' => $orderId]);
                $pdo->prepare('UPDATE order_items SET item_status = :st, rejection_reason = :reason WHERE order_id = :id')
                    ->execute(['st' => 'cancelled', 'reason' => $reason, 'id' => $orderId]);

                // Audit log
                $pdo->prepare(
                    'INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, ip_address, details)
                     VALUES (:actor_id, :actor_role, :actor_name, :action, :target_type, :target_id, :ip_address, :details)'
                )->execute([
                    'actor_id'    => (int)$user['id'],
                    'actor_role'  => 'admin',
                    'actor_name'  => $user['name'] ?? 'System Admin',
                    'action'      => 'ORDER_REJECT',
                    'target_type' => 'orders',
                    'target_id'   => (string)$orderId,
                    'ip_address'  => $request->getIp() ?? '127.0.0.1',
                    'details'     => json_encode([
                        'order_number' => $order['order_number'],
                        'reason'       => $reason,
                        'new_status'   => 'cancelled',
                    ]),
                ]);

                // Shipment event
                $pdo->prepare(
                    'INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id)
                     VALUES (:order_id, :status, :title, :description, :location, :tag_label, :actor_role, :actor_id)'
                )->execute([
                    'order_id'    => $orderId,
                    'status'      => 'cancelled',
                    'title'       => 'Order Cancelled by Admin',
                    'description' => "Administrative cancellation. Reason: {$reason}",
                    'location'    => 'Central Operations',
                    'tag_label'   => 'Cancelled',
                    'actor_role'  => 'admin',
                    'actor_id'    => (int)$user['id'],
                ]);

                Response::json([
                    'order_id'         => $orderId,
                    'order_number'     => $order['order_number'],
                    'order_status'     => 'cancelled',
                    'rejection_reason' => $reason,
                    'action'           => 'rejected',
                ], 200, "Order #{$order['order_number']} rejected by administrator.");
            }
        } catch (Throwable $e) {
            Response::error('Failed to reject order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get live tracking details and timeline events for Buddy (Seller / Admin).
     * GET /api/v1/buddy/orders/{id}/tracking
     */
    public function track(Request $request, array $params): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $sellerId = (int)$user['id'];
        $orderIdent = trim((string)($params['id'] ?? ''));

        if ($orderIdent === '') {
            Response::error('Invalid order or tracking identifier.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $isNumeric = ctype_digit($orderIdent);
            $query = 'SELECT o.id, o.order_number, o.customer_id, o.total_amount, o.payment_status, o.order_status,
                             o.tracking_number, o.courier_name, o.courier_phone, o.estimated_delivery, o.estimated_time,
                             o.current_location, o.next_stop, o.shipping_address, o.rejection_reason, o.created_at, o.updated_at,
                             u.full_name AS customer_name, u.phone AS customer_phone
                      FROM orders o
                      JOIN users u ON o.customer_id = u.id
                      WHERE ' . ($isNumeric ? '(o.id = :id OR o.order_number = :ord_num OR o.tracking_number = :track_num)' : '(o.order_number = :ord_num OR o.tracking_number = :track_num)') . '
                      LIMIT 1';

            $stmt = $pdo->prepare($query);
            $binds = [
                'ord_num'   => $orderIdent,
                'track_num' => $orderIdent,
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

            // Seller ownership verification
            if ($role === 'seller') {
                $checkSeller = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id = :order_id AND seller_id = :seller_id');
                $checkSeller->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
                if ((int)$checkSeller->fetchColumn() === 0) {
                    Response::error('Forbidden. This order contains no items belonging to your store.', 403);
                }
            }

            // Fetch persisted shipment events
            $evtStmt = $pdo->prepare(
                'SELECT id, status, title, description, location, tag_label, actor_role, created_at
                 FROM shipment_events
                 WHERE order_id = :order_id
                 ORDER BY created_at ASC, id ASC'
            );
            $evtStmt->execute(['order_id' => $orderId]);
            $events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch order items (isolated to seller if seller role)
            if ($role === 'seller') {
                $itemStmt = $pdo->prepare(
                    'SELECT oi.id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status,
                            p.title, p.slug,
                            (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                     FROM order_items oi
                     JOIN products p ON oi.product_id = p.id
                     WHERE oi.order_id = :order_id AND oi.seller_id = :seller_id
                     ORDER BY oi.id ASC'
                );
                $itemStmt->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
            } else {
                $itemStmt = $pdo->prepare(
                    'SELECT oi.id, oi.product_id, oi.unit_price, oi.quantity, oi.subtotal, oi.item_status,
                            p.title, p.slug, sp.store_name,
                            (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image_url
                     FROM order_items oi
                     JOIN products p ON oi.product_id = p.id
                     LEFT JOIN seller_profiles sp ON oi.seller_id = sp.user_id
                     WHERE oi.order_id = :order_id
                     ORDER BY oi.id ASC'
                );
                $itemStmt->execute(['order_id' => $orderId]);
            }
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $currentStatus = $order['order_status'];
            $statusStep = match ($currentStatus) {
                'placed'     => 1,
                'processing' => 2,
                'shipped'    => 3,
                'delivered'  => 4,
                'cancelled'  => 0,
                default      => 1,
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
                    'time'        => date('h:i A', $time),
                    'date_time'   => $dateFormatted,
                    'timestamp'   => $evt['created_at'],
                ];
            }, $events);

            $headlineStatus = match ($currentStatus) {
                'placed'     => 'Order Placed & Awaiting Merchant Accept',
                'processing' => 'Package Scanned & Being Prepared for Handover',
                'shipped'    => 'In Transit — Courier Transfer Active',
                'delivered'  => 'Delivered & Handed Over',
                'cancelled'  => 'Order Cancelled',
                default      => ucfirst($currentStatus),
            };

            Response::json([
                'order_id'           => $orderId,
                'order_number'       => $order['order_number'],
                'tracking_number'    => $order['tracking_number'] ?: ('APX-' . rand(100000, 999999)),
                'status'             => $currentStatus,
                'status_step'        => $statusStep,
                'headline'           => $headlineStatus,
                'courier_name'       => $order['courier_name'] ?: 'Apex Courier Express',
                'courier_phone'      => $order['courier_phone'] ?: '+880 1700-112233',
                'current_location'   => $order['current_location'] ?: 'Merchant Facility',
                'next_stop'          => $order['next_stop'] ?: 'Customer Address',
                'estimated_delivery' => $order['estimated_delivery'] ?: 'Tomorrow, 6:00 PM',
                'estimated_time'     => $order['estimated_time'] ?: 'On Schedule',
                'shipping_address'   => json_decode($order['shipping_address'] ?? '[]', true),
                'rejection_reason'   => $order['rejection_reason'],
                'items_count'        => count($items),
                'timeline'           => $formattedTimeline,
            ], 200, 'Tracking timeline retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve tracking details: ' . $e->getMessage(), 500);
        }
    }
}
