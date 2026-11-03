<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Inventory & Stock Management for TechKart Buddy
 * Supports inventory listings, stock adjustments/movements, and live telemetry synchronization.
 */
class BuddyInventoryController
{
    private function resolveSellerId(Request $request): ?int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
            exit;
        }

        if ($user['role'] === 'seller') {
            return (int)$user['id'];
        }

        if ($user['role'] === 'admin') {
            $overrideId = $request->get('seller_id') ?? $request->getQuery('seller_id');
            return $overrideId !== null && $overrideId !== '' ? (int)$overrideId : null;
        }

        Response::error('Forbidden', 403);
        exit;
    }

    /**
     * List inventory items with stock counts, status badges, and aggregate summary metrics.
     * GET /api/v1/buddy/inventory
     * Query Params:
     * - status: 'all' | 'healthy' | 'low_stock' | 'out_of_stock'
     * - search: string
     * - category_id: int
     * - page: int
     * - limit: int
     */
    public function index(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);
        $user = $request->getUser();

        $statusFilter = trim((string)$request->getQuery('status', 'all'));
        $search       = trim((string)$request->getQuery('search', ''));
        $categoryId   = $request->getQuery('category_id');
        $page         = max(1, (int)$request->getQuery('page', 1));
        $limit        = min(100, max(1, (int)$request->getQuery('limit', 50)));
        $offset       = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            // 1. Compute high-level inventory metrics for seller / admin using dynamic low_stock_threshold
            $metricSql = "SELECT
                            COUNT(p.id) AS total_items,
                            COALESCE(SUM(p.stock_quantity), 0) AS total_vault_units,
                            COALESCE(SUM(CASE WHEN p.stock_quantity > p.low_stock_threshold THEN 1 ELSE 0 END), 0) AS healthy_count,
                            COALESCE(SUM(CASE WHEN p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count,
                            COALESCE(SUM(CASE WHEN p.stock_quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,
                            COALESCE(SUM(p.stock_quantity * p.price), 0) AS total_vault_valuation
                          FROM products p
                          WHERE p.status != 'archived'";

            $metricParams = [];
            if ($sellerId !== null) {
                $metricSql .= " AND p.seller_id = :seller_id";
                $metricParams['seller_id'] = $sellerId;
            }
            $metricStmt = $pdo->prepare($metricSql);
            $metricStmt->execute($metricParams);
            $metrics = $metricStmt->fetch(PDO::FETCH_ASSOC);

            // Calculate estimated reserved units from unfulfilled orders
            $reservedSql = "SELECT COALESCE(SUM(oi.quantity), 0) AS reserved_units
                            FROM order_items oi
                            JOIN products p ON oi.product_id = p.id
                            WHERE oi.item_status IN ('placed', 'processing') AND p.status != 'archived'";
            $reservedParams = [];
            if ($sellerId !== null) {
                $reservedSql .= " AND oi.seller_id = :seller_id";
                $reservedParams['seller_id'] = $sellerId;
            }
            $reservedStmt = $pdo->prepare($reservedSql);
            $reservedStmt->execute($reservedParams);
            $reservedRow = $reservedStmt->fetch(PDO::FETCH_ASSOC);
            $totalReserved = (int)($reservedRow['reserved_units'] ?? 0);

            // 2. Fetch inventory items with filtering
            $where = ["p.status != 'archived'"];
            $binds = [];

            if ($sellerId !== null) {
                $where[] = "p.seller_id = :seller_id";
                $binds['seller_id'] = $sellerId;
            }

            if ($categoryId !== null && $categoryId !== '') {
                $where[] = "p.category_id = :cat_id";
                $binds['cat_id'] = (int)$categoryId;
            }

            if ($search !== '') {
                $where[] = "(p.title LIKE :search OR p.slug LIKE :search OR p.id = :search_id)";
                $binds['search'] = '%' . $search . '%';
                $binds['search_id'] = is_numeric($search) ? (int)$search : 0;
            }

            if ($statusFilter === 'low_stock') {
                $where[] = "p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold";
            } elseif ($statusFilter === 'out_of_stock') {
                $where[] = "p.stock_quantity = 0";
            } elseif ($statusFilter === 'healthy') {
                $where[] = "p.stock_quantity > p.low_stock_threshold";
            }

            $whereStr = implode(' AND ', $where);

            // Count total matching
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereStr}");
            $countStmt->execute($binds);
            $totalFiltered = (int)$countStmt->fetchColumn();

            // Fetch items
            $sql = "SELECT
                        p.id, p.seller_id, p.category_id, p.title, p.slug,
                        p.price, p.mrp, p.stock_quantity, p.low_stock_threshold, p.status, p.created_at,
                        c.name AS category_name,
                        u.email AS seller_email,
                        sp.store_name,
                        (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1) AS primary_image_url,
                        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.product_id = p.id AND oi.item_status IN ('placed', 'processing')) AS reserved_units
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN users u ON p.seller_id = u.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    WHERE {$whereStr}
                    ORDER BY p.stock_quantity ASC, p.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($binds as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format inventory items
            $formattedItems = [];
            foreach ($items as $item) {
                $available = (int)$item['stock_quantity'];
                $threshold = max(1, (int)($item['low_stock_threshold'] ?? 5));
                $reserved  = (int)($item['reserved_units'] ?? 0);
                $totalVault = $available + $reserved;

                $statusType = 'healthy';
                $statusBadge = "$available Available";
                $actionType = 'quick_adjust';

                if ($available === 0) {
                    $statusType = 'out_of_stock';
                    $statusBadge = '0 Available';
                    $actionType = 'quick_restock';
                } elseif ($available <= $threshold) {
                    $statusType = 'low_stock';
                    $statusBadge = "$available Available";
                    $actionType = 'update_stock';
                }

                $formattedItems[] = [
                    'id'                => (int)$item['id'],
                    'seller_id'         => (int)$item['seller_id'],
                    'store_name'        => $item['store_name'] ?? 'TechKart Seller',
                    'category_id'       => $item['category_id'] !== null ? (int)$item['category_id'] : null,
                    'category_name'     => $item['category_name'] ?? 'General',
                    'title'             => $item['title'],
                    'sku'               => 'TK-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $item['category_name'] ?? 'GEN'), 0, 3)) . '-' . str_pad((string)$item['id'], 4, '0', STR_PAD_LEFT),
                    'price'             => (float)$item['price'],
                    'mrp'               => $item['mrp'] !== null ? (float)$item['mrp'] : null,
                    'available_stock'   => $available,
                    'reserved_stock'    => $reserved,
                    'total_vault_units' => $totalVault,
                    'threshold'         => $threshold,
                    'low_stock_threshold' => $threshold,
                    'status_type'       => $statusType,
                    'status_badge'      => $statusBadge,
                    'action_type'       => $actionType,
                    'image_url'         => $item['primary_image_url'],
                    'status'            => $item['status'],
                ];
            }

            Response::json([
                'items' => $formattedItems,
                'metrics' => [
                    'total_items'           => (int)$metrics['total_items'],
                    'total_vault_units'     => (int)$metrics['total_vault_units'],
                    'healthy_count'         => (int)$metrics['healthy_count'],
                    'low_stock_count'       => (int)$metrics['low_stock_count'],
                    'out_of_stock_count'    => (int)$metrics['out_of_stock_count'],
                    'reserved_units'        => $totalReserved,
                    'total_vault_valuation' => (float)$metrics['total_vault_valuation'],
                    'active_ratio_percent'  => (int)$metrics['total_items'] > 0
                        ? round(((int)$metrics['healthy_count'] / (int)$metrics['total_items']) * 100, 1)
                        : 0.0,
                ],
                'pagination' => [
                    'total'        => $totalFiltered,
                    'page'         => $page,
                    'limit'        => $limit,
                    'total_pages'  => ceil($totalFiltered / $limit) ?: 1,
                    'has_next'     => ($offset + $limit) < $totalFiltered,
                    'has_previous' => $page > 1,
                ]
            ], 200, 'Inventory items retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve inventory: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update/Adjust product stock quantity and log stock movement.
     * POST /api/v1/buddy/inventory/update-stock
     * Body: {
     *   product_id: int,
     *   stock_quantity?: int,       // Absolute new stock count
     *   adjustment_delta?: int,     // Relative adjustment count (+/-)
     *   movement_type?: 'restock'|'manual_adjustment'|'damage_writeoff'|'return_restock',
     *   reason?: string
     * }
     */
    public function updateStock(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);
        $user = $request->getUser();

        $productId       = (int)($request->get('product_id') ?? 0);
        $stockQuantity   = $request->get('stock_quantity');
        $adjustmentDelta = $request->get('adjustment_delta');
        $movementType    = (string)($request->get('movement_type') ?? 'manual_adjustment');
        $reason          = trim((string)($request->get('reason') ?? ''));

        if ($productId <= 0) {
            Response::error('Valid product_id is required.', 422, ['product_id' => 'Product ID is missing or invalid']);
        }

        if ($stockQuantity === null && $adjustmentDelta === null) {
            Response::error('Either stock_quantity (absolute) or adjustment_delta (relative) must be provided.', 422);
        }

        $validMovements = ['restock', 'manual_adjustment', 'order_fulfillment', 'return_restock', 'damage_writeoff'];
        if (!in_array($movementType, $validMovements, true)) {
            $movementType = 'manual_adjustment';
        }

        try {
            $pdo = Database::getConnection();

            // Verify product existence and ownership
            $chkSql = 'SELECT id, seller_id, title, stock_quantity, low_stock_threshold, status FROM products WHERE id = :id LIMIT 1';
            $chkStmt = $pdo->prepare($chkSql);
            $chkStmt->execute(['id' => $productId]);
            $product = $chkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found.', 404);
            }

            if ($sellerId !== null && (int)$product['seller_id'] !== $sellerId) {
                Response::error('Forbidden. You do not own this product.', 403);
            }

            $previousQuantity = (int)$product['stock_quantity'];

            // Calculate new quantity
            if ($stockQuantity !== null) {
                $newQuantity = max(0, (int)$stockQuantity);
                $changedQuantity = $newQuantity - $previousQuantity;
            } else {
                $delta = (int)$adjustmentDelta;
                $newQuantity = max(0, $previousQuantity + $delta);
                $changedQuantity = $newQuantity - $previousQuantity;
            }

            // Derive reason if not supplied
            if (empty($reason)) {
                if ($changedQuantity > 0) {
                    $reason = "Warehouse stock replenishment of +{$changedQuantity} units";
                } elseif ($changedQuantity < 0) {
                    $reason = "Manual stock reconciliation: " . $changedQuantity . " units";
                } else {
                    $reason = "Stock verified unchanged ({$newQuantity} units)";
                }
            }

            $pdo->beginTransaction();

            // 1. Update product stock_quantity in MariaDB
            $updateStmt = $pdo->prepare('UPDATE products SET stock_quantity = :stock WHERE id = :id');
            $updateStmt->execute([
                'stock' => $newQuantity,
                'id'    => $productId,
            ]);

            // 2. Insert audited stock movement record
            $movStmt = $pdo->prepare(
                'INSERT INTO stock_movements (
                    product_id, seller_id, movement_type, previous_quantity,
                    changed_quantity, new_quantity, reason, actor_id, actor_role
                 ) VALUES (
                    :pid, :sid, :mtype, :prev, :chg, :newq, :reason, :aid, :arole
                 )'
            );
            $movStmt->execute([
                'pid'     => $productId,
                'sid'     => (int)$product['seller_id'],
                'mtype'   => $movementType,
                'prev'    => $previousQuantity,
                'chg'     => $changedQuantity,
                'newq'    => $newQuantity,
                'reason'  => $reason,
                'aid'     => $user['id'] ?? null,
                'arole'   => $user['role'] ?? 'seller',
            ]);
            $movementId = (int)$pdo->lastInsertId();

            // 3. Persistent Low-Stock Alert synchronization
            $prodSellerId = (int)$product['seller_id'];
            $threshold = max(1, (int)($product['low_stock_threshold'] ?? 5));

            if ($newQuantity === 0) {
                // Out of stock critical alert
                $chkAlert = $pdo->prepare('SELECT id FROM inventory_alerts WHERE product_id = :pid AND alert_type = \'out_of_stock\' AND is_dismissed = 0 LIMIT 1');
                $chkAlert->execute(['pid' => $productId]);
                if (!$chkAlert->fetch()) {
                    $insAlert = $pdo->prepare('INSERT INTO inventory_alerts (seller_id, product_id, alert_type, severity, current_stock, threshold, title, message)
                        VALUES (:sid, :pid, \'out_of_stock\', \'critical\', :cstock, :thresh, :title, :msg)');
                    $insAlert->execute([
                        'sid'    => $prodSellerId,
                        'pid'    => $productId,
                        'cstock' => 0,
                        'thresh' => $threshold,
                        'title'  => "Out of Stock: {$product['title']}",
                        'msg'    => "{$product['title']} is completely sold out (0 units remaining in Mumbai Hub). Immediate restock required.",
                    ]);
                }
            } elseif ($newQuantity <= $threshold) {
                // Low stock warning alert
                $chkAlert = $pdo->prepare('SELECT id FROM inventory_alerts WHERE product_id = :pid AND alert_type = \'low_stock\' AND is_dismissed = 0 LIMIT 1');
                $chkAlert->execute(['pid' => $productId]);
                if (!$chkAlert->fetch()) {
                    $insAlert = $pdo->prepare('INSERT INTO inventory_alerts (seller_id, product_id, alert_type, severity, current_stock, threshold, title, message)
                        VALUES (:sid, :pid, \'low_stock\', \'warning\', :cstock, :thresh, :title, :msg)');
                    $insAlert->execute([
                        'sid'    => $prodSellerId,
                        'pid'    => $productId,
                        'cstock' => $newQuantity,
                        'thresh' => $threshold,
                        'title'  => "Low Stock Warning: {$product['title']}",
                        'msg'    => "{$product['title']} reached threshold: only {$newQuantity} left in Mumbai Hub (Threshold: {$threshold}).",
                    ]);
                } else {
                    // Update current stock in active alert
                    $updAlert = $pdo->prepare('UPDATE inventory_alerts SET current_stock = :cstock, updated_at = NOW() WHERE product_id = :pid AND alert_type = \'low_stock\' AND is_dismissed = 0');
                    $updAlert->execute(['cstock' => $newQuantity, 'pid' => $productId]);
                }
            } else {
                // Stock replenished above threshold: auto-resolve / dismiss active low_stock/out_of_stock alerts
                $resAlert = $pdo->prepare('UPDATE inventory_alerts SET is_dismissed = 1, is_read = 1, read_at = NOW(), updated_at = NOW() WHERE product_id = :pid AND is_dismissed = 0');
                $resAlert->execute(['pid' => $productId]);
            }

            $pdo->commit();

            Response::json([
                'product_id'          => $productId,
                'title'               => $product['title'],
                'previous_quantity'   => $previousQuantity,
                'changed_quantity'    => $changedQuantity,
                'new_quantity'        => $newQuantity,
                'threshold'           => $threshold,
                'low_stock_threshold' => $threshold,
                'is_low_stock'        => ($newQuantity > 0 && $newQuantity <= $threshold),
                'is_out_of_stock'     => ($newQuantity === 0),
                'movement_id'         => $movementId,
                'movement_type'       => $movementType,
                'reason'              => $reason,
                'status_badge'        => "{$newQuantity} Available",
            ], 200, 'Product inventory updated and movement logged successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update inventory: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get stock movement audit trail for a product or the authenticated seller.
     * GET /api/v1/buddy/inventory/movements
     * Query Params:
     * - product_id: int
     * - page: int
     * - limit: int
     */
    public function movements(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);
        $productId = $request->getQuery('product_id') ? (int)$request->getQuery('product_id') : null;
        $page      = max(1, (int)$request->getQuery('page', 1));
        $limit     = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset    = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $where = ['1=1'];
            $binds = [];

            if ($sellerId !== null) {
                $where[] = 'sm.seller_id = :seller_id';
                $binds['seller_id'] = $sellerId;
            }

            if ($productId !== null && $productId > 0) {
                $where[] = 'sm.product_id = :product_id';
                $binds['product_id'] = $productId;
            }

            $whereStr = implode(' AND ', $where);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements sm WHERE {$whereStr}");
            $countStmt->execute($binds);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT
                        sm.*,
                        p.title AS product_title,
                        u.email AS actor_email
                    FROM stock_movements sm
                    JOIN products p ON sm.product_id = p.id
                    LEFT JOIN users u ON sm.actor_id = u.id
                    WHERE {$whereStr}
                    ORDER BY sm.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($binds as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json($movements, 200, 'Stock movements retrieved successfully', [
                'pagination' => [
                    'total'        => $total,
                    'page'         => $page,
                    'limit'        => $limit,
                    'total_pages'  => ceil($total / $limit) ?: 1,
                    'has_next'     => ($offset + $limit) < $total,
                    'has_previous' => $page > 1,
                ]
            ]);

        } catch (Throwable $e) {
            Response::error('Failed to retrieve stock movements: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get seller persistent inventory alerts with unread badge count
     * GET /api/v1/buddy/inventory/alerts
     */
    public function alerts(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);

        $statusFilter = $request->getQuery('status', 'active'); // 'active', 'all', 'unread'
        $limit = min(50, max(1, (int)$request->getQuery('limit', 20)));

        try {
            $pdo = Database::getConnection();

            // Auto-synchronize missing alerts for products currently below threshold
            $this->syncLowStockAlerts($pdo, $sellerId);

            $where = ['1=1'];
            $binds = [];

            if ($sellerId !== null) {
                $where[] = 'ia.seller_id = :seller_id';
                $binds['seller_id'] = $sellerId;
            }

            if ($statusFilter === 'unread') {
                $where[] = 'ia.is_read = 0 AND ia.is_dismissed = 0';
            } elseif ($statusFilter === 'active') {
                $where[] = 'ia.is_dismissed = 0';
            }

            $whereStr = implode(' AND ', $where);

            // Compute summary metrics (unread_count, critical_count, warning_count)
            $countSql = "SELECT
                            COUNT(*) AS total_alerts,
                            COALESCE(SUM(CASE WHEN ia.is_read = 0 AND ia.is_dismissed = 0 THEN 1 ELSE 0 END), 0) AS unread_count,
                            COALESCE(SUM(CASE WHEN ia.severity = 'critical' AND ia.is_dismissed = 0 THEN 1 ELSE 0 END), 0) AS critical_count,
                            COALESCE(SUM(CASE WHEN ia.severity = 'warning' AND ia.is_dismissed = 0 THEN 1 ELSE 0 END), 0) AS warning_count
                         FROM inventory_alerts ia
                         WHERE " . ($sellerId !== null ? 'ia.seller_id = :sid' : '1=1');
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($sellerId !== null ? ['sid' => $sellerId] : []);
            $metrics = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'total_alerts'   => 0,
                'unread_count'   => 0,
                'critical_count' => 0,
                'warning_count'  => 0,
            ];

            // Fetch alert list
            $sql = "SELECT
                        ia.*,
                        p.title AS product_title,
                        p.stock_quantity AS live_stock,
                        p.low_stock_threshold AS product_threshold,
                        (SELECT image_url FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, id ASC LIMIT 1) AS product_image
                    FROM inventory_alerts ia
                    JOIN products p ON ia.product_id = p.id
                    WHERE {$whereStr}
                    ORDER BY ia.is_read ASC, ia.id DESC
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            foreach ($binds as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = array_map(function ($a) {
                return [
                    'id'            => (int)$a['id'],
                    'seller_id'     => (int)$a['seller_id'],
                    'product_id'    => (int)$a['product_id'],
                    'product_title' => $a['product_title'],
                    'alert_type'    => $a['alert_type'],
                    'severity'      => $a['severity'],
                    'current_stock' => (int)$a['live_stock'],
                    'threshold'     => (int)$a['threshold'],
                    'title'         => $a['title'],
                    'message'       => $a['message'],
                    'is_read'       => (bool)$a['is_read'],
                    'read_at'       => $a['read_at'],
                    'is_dismissed'  => (bool)$a['is_dismissed'],
                    'product_image' => $a['product_image'],
                    'created_at'    => $a['created_at'],
                ];
            }, $alerts);

            Response::json([
                'alerts' => $formatted,
                'metrics' => [
                    'total_alerts'   => (int)$metrics['total_alerts'],
                    'unread_count'   => (int)$metrics['unread_count'],
                    'critical_count' => (int)$metrics['critical_count'],
                    'warning_count'  => (int)$metrics['warning_count'],
                ],
            ], 200, 'Inventory alerts retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve inventory alerts: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mark an alert as read
     * POST /api/v1/buddy/inventory/alerts/{id}/read
     * PUT  /api/v1/buddy/inventory/alerts/{id}/read
     */
    public function markAlertRead(Request $request, array $params = []): void
    {
        $sellerId = $this->resolveSellerId($request);
        $alertId = (int)($params['id'] ?? $request->getQuery('id') ?? $request->get('id', 0));

        if ($alertId <= 0) {
            Response::error('Valid alert ID is required.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $chkSql = 'SELECT id, seller_id, is_read FROM inventory_alerts WHERE id = :id LIMIT 1';
            $chkStmt = $pdo->prepare($chkSql);
            $chkStmt->execute(['id' => $alertId]);
            $alert = $chkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$alert) {
                Response::error('Alert not found.', 404);
            }

            if ($sellerId !== null && (int)$alert['seller_id'] !== $sellerId) {
                Response::error('Forbidden. This alert belongs to another seller.', 403);
            }

            $pdo->prepare('UPDATE inventory_alerts SET is_read = 1, read_at = NOW(), updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $alertId]);

            Response::json([
                'id'      => $alertId,
                'is_read' => true,
                'read_at' => date('Y-m-d H:i:s'),
            ], 200, 'Alert marked as read successfully');

        } catch (Throwable $e) {
            Response::error('Failed to update alert: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mark all alerts as read for the authenticated seller
     * POST /api/v1/buddy/inventory/alerts/mark-all-read
     */
    public function markAllAlertsRead(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);

        try {
            $pdo = Database::getConnection();

            if ($sellerId !== null) {
                $stmt = $pdo->prepare('UPDATE inventory_alerts SET is_read = 1, read_at = NOW(), updated_at = NOW() WHERE seller_id = :sid AND is_read = 0');
                $stmt->execute(['sid' => $sellerId]);
                $updatedCount = $stmt->rowCount();
            } else {
                // Admin marking all
                $updatedCount = (int)$pdo->exec('UPDATE inventory_alerts SET is_read = 1, read_at = NOW(), updated_at = NOW() WHERE is_read = 0');
            }

            Response::json([
                'marked_read_count' => $updatedCount,
                'unread_count'      => 0,
            ], 200, 'All alerts marked as read successfully');

        } catch (Throwable $e) {
            Response::error('Failed to mark all alerts read: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update product inventory threshold
     * PUT/POST /api/v1/buddy/inventory/threshold
     * Body: { product_id: int, threshold: int }
     */
    public function updateThreshold(Request $request): void
    {
        $sellerId = $this->resolveSellerId($request);

        $productId = (int)($request->get('product_id') ?? 0);
        $threshold = max(1, (int)($request->get('threshold') ?? $request->get('low_stock_threshold', 5)));

        if ($productId <= 0) {
            Response::error('Valid product_id is required.', 422);
        }

        try {
            $pdo = Database::getConnection();

            $chk = $pdo->prepare('SELECT id, seller_id, title, stock_quantity FROM products WHERE id = :id LIMIT 1');
            $chk->execute(['id' => $productId]);
            $product = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                Response::error('Product not found.', 404);
            }

            if ($sellerId !== null && (int)$product['seller_id'] !== $sellerId) {
                Response::error('Forbidden. You do not own this product.', 403);
            }

            $upd = $pdo->prepare('UPDATE products SET low_stock_threshold = :th WHERE id = :id');
            $upd->execute(['th' => $threshold, 'id' => $productId]);

            // Synchronize alerts with new threshold
            $stock = (int)$product['stock_quantity'];
            if ($stock > $threshold) {
                $pdo->prepare('UPDATE inventory_alerts SET is_dismissed = 1, is_read = 1, read_at = NOW() WHERE product_id = :pid AND is_dismissed = 0')
                    ->execute(['pid' => $productId]);
            } else {
                $this->syncLowStockAlerts($pdo, (int)$product['seller_id']);
            }

            Response::json([
                'product_id'          => $productId,
                'title'               => $product['title'],
                'stock_quantity'      => $stock,
                'low_stock_threshold' => $threshold,
            ], 200, 'Inventory threshold updated successfully');

        } catch (Throwable $e) {
            Response::error('Failed to update threshold: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to synchronize any low stock or out of stock products into inventory_alerts
     */
    private function syncLowStockAlerts(PDO $pdo, ?int $sellerId): void
    {
        $sql = "SELECT id, seller_id, title, stock_quantity, low_stock_threshold
                FROM products
                WHERE status = 'published' AND stock_quantity <= low_stock_threshold";
        $params = [];
        if ($sellerId !== null) {
            $sql .= " AND seller_id = :sid";
            $params['sid'] = $sellerId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lowProds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lowProds as $p) {
            $pid = (int)$p['id'];
            $sid = (int)$p['seller_id'];
            $stk = (int)$p['stock_quantity'];
            $th  = max(1, (int)$p['low_stock_threshold']);
            $type = ($stk === 0) ? 'out_of_stock' : 'low_stock';
            $sev  = ($stk === 0) ? 'critical' : 'warning';

            $chk = $pdo->prepare("SELECT id FROM inventory_alerts WHERE product_id = :pid AND alert_type = :type AND is_dismissed = 0 LIMIT 1");
            $chk->execute(['pid' => $pid, 'type' => $type]);
            if (!$chk->fetch()) {
                $ins = $pdo->prepare("INSERT INTO inventory_alerts (seller_id, product_id, alert_type, severity, current_stock, threshold, title, message)
                    VALUES (:sid, :pid, :type, :sev, :cstock, :thresh, :title, :msg)");
                $title = ($stk === 0) ? "Out of Stock: {$p['title']}" : "Low Stock Warning: {$p['title']}";
                $msg   = ($stk === 0)
                    ? "{$p['title']} is completely sold out (0 units remaining in Mumbai Hub). Immediate restock required."
                    : "{$p['title']} reached threshold: only {$stk} left in Mumbai Hub (Threshold: {$th}).";
                $ins->execute([
                    'sid'    => $sid,
                    'pid'    => $pid,
                    'type'   => $type,
                    'sev'    => $sev,
                    'cstock' => $stk,
                    'thresh' => $th,
                    'title'  => $title,
                    'msg'    => $msg,
                ]);
            }
        }
    }
}
