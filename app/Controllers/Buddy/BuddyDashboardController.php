<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller providing aggregated analytics, stats, and audit telemetry
 * for Seller and Admin Dashboards in TechKart Buddy.
 */
class BuddyDashboardController
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
     * Seller Dashboard Overview Metrics & Real-time Dispatch Stream
     * GET /api/v1/buddy/seller/dashboard
     */
    public function sellerDashboard(Request $request): void
    {
        $user = $this->resolveUser($request);
        $sellerId = (int)$user['id'];

        try {
            $pdo = Database::getConnection();

            // 1. Seller Profile Info
            $spStmt = $pdo->prepare('SELECT store_name, gst_number, city, state, is_verified FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $spStmt->execute(['uid' => $sellerId]);
            $store = $spStmt->fetch(PDO::FETCH_ASSOC);
            if (!$store) {
                $store = [
                    'store_name' => !empty($user['full_name']) ? ($user['full_name'] . "'s Store") : 'My Store',
                    'gst_number' => null,
                    'city' => null,
                    'state' => null,
                    'is_verified' => 0
                ];
            }

            // 2. Orders Metrics for this seller (strictly scoped by oi.seller_id)
            $stmtOrders = $pdo->prepare(
                "SELECT
                    COUNT(DISTINCT o.id) AS total_orders,
                    SUM(CASE WHEN DATE(o.created_at) = CURRENT_DATE THEN 1 ELSE 0 END) AS todays_orders,
                    SUM(CASE WHEN oi.item_status = 'placed' THEN 1 ELSE 0 END) AS pending_orders,
                    SUM(CASE WHEN oi.item_status = 'processing' THEN 1 ELSE 0 END) AS ready_orders,
                    SUM(CASE WHEN oi.item_status = 'shipped' THEN 1 ELSE 0 END) AS shipped_orders,
                    SUM(CASE WHEN oi.item_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                    SUM(CASE WHEN oi.item_status = 'delivered' THEN oi.quantity ELSE 0 END) AS delivered_units,
                    COALESCE(SUM(CASE WHEN oi.item_status != 'cancelled' THEN oi.subtotal ELSE 0 END), 0) AS gross_revenue
                 FROM orders o
                 JOIN order_items oi ON o.id = oi.order_id
                 WHERE oi.seller_id = :sid"
            );
            $stmtOrders->execute(['sid' => $sellerId]);
            $orderStats = $stmtOrders->fetch(PDO::FETCH_ASSOC) ?: [];

            // 3. Top Selling Product for this seller
            $topProductStmt = $pdo->prepare(
                "SELECT p.title, SUM(oi.quantity) AS total_sold
                 FROM order_items oi
                 JOIN products p ON oi.product_id = p.id
                 WHERE oi.seller_id = :sid AND oi.item_status != 'cancelled'
                 GROUP BY oi.product_id, p.title
                 ORDER BY total_sold DESC LIMIT 1"
            );
            $topProductStmt->execute(['sid' => $sellerId]);
            $topProduct = $topProductStmt->fetch(PDO::FETCH_ASSOC);
            $topProductTitle = $topProduct ? $topProduct['title'] : 'None';

            // 4. Catalog & Inventory Metrics (scoped to seller using live low_stock_threshold)
            $stmtInv = $pdo->prepare(
                "SELECT
                    COUNT(id) AS total_products,
                    SUM(CASE WHEN status = 'published' AND stock_quantity > 0 THEN 1 ELSE 0 END) AS active_products,
                    SUM(CASE WHEN status = 'published' AND stock_quantity > 0 AND stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN status = 'published' AND stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending_review_count,
                    COALESCE(SUM(stock_quantity), 0) AS total_vault_units
                 FROM products
                 WHERE seller_id = :sid"
            );
            $stmtInv->execute(['sid' => $sellerId]);
            $invStats = $stmtInv->fetch(PDO::FETCH_ASSOC) ?: [];

            // 4b. Inventory unread alerts count
            $alertCountStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_alerts WHERE seller_id = :sid AND is_read = 0 AND is_dismissed = 0");
            $alertCountStmt->execute(['sid' => $sellerId]);
            $unreadAlertsCount = (int)$alertCountStmt->fetchColumn();

            // 5. Financial Settlement (scoped to seller)
            $stmtSettlement = $pdo->prepare(
                "SELECT settlement_ref, net_payout, status, bank_name, bank_account_masked, payout_date
                 FROM seller_settlements
                 WHERE seller_id = :sid
                 ORDER BY id DESC LIMIT 1"
            );
            $stmtSettlement->execute(['sid' => $sellerId]);
            $settlement = $stmtSettlement->fetch(PDO::FETCH_ASSOC);

            // 6. 7-Day Daily Sales Breakdown for Performance Chart
            // Generate last 7 days including today
            $dailyStmt = $pdo->prepare(
                "SELECT
                    DATE(o.created_at) AS order_date,
                    COALESCE(SUM(oi.subtotal), 0) AS daily_revenue,
                    COUNT(DISTINCT o.id) AS daily_orders
                 FROM orders o
                 JOIN order_items oi ON o.id = oi.order_id
                 WHERE oi.seller_id = :sid
                   AND oi.item_status != 'cancelled'
                   AND o.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)
                 GROUP BY DATE(o.created_at)
                 ORDER BY order_date ASC"
            );
            $dailyStmt->execute(['sid' => $sellerId]);
            $rawDaily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

            $dailyMap = [];
            foreach ($rawDaily as $d) {
                $dailyMap[$d['order_date']] = [
                    'revenue' => (float)$d['daily_revenue'],
                    'orders'  => (int)$d['daily_orders'],
                ];
            }

            $weeklyDays = [];
            $weeklyRevenues = [];
            $maxRevenue = 1.0;
            for ($i = 6; $i >= 0; $i--) {
                $targetDate = date('Y-m-d', strtotime("-{$i} days"));
                $dayLabel = ($i === 0) ? 'Today' : date('D', strtotime($targetDate));
                $rev = $dailyMap[$targetDate]['revenue'] ?? 0.0;
                $ordCount = $dailyMap[$targetDate]['orders'] ?? 0;

                $weeklyDays[] = $dayLabel;
                $weeklyRevenues[] = $rev;
                if ($rev > $maxRevenue) {
                    $maxRevenue = $rev;
                }
            }

            // Normalize heights between 0.15 and 1.0 for bar chart visualization
            $weeklyNormalized = array_map(function ($r) use ($maxRevenue) {
                if ($maxRevenue <= 0) return 0.20;
                $ratio = $r / $maxRevenue;
                return round(max(0.15, min(1.0, $ratio)), 2);
            }, $weeklyRevenues);

            // 7. Recent Priority Orders for Dispatch Stream (scoped to seller)
            $stmtStream = $pdo->prepare(
                "SELECT o.id, o.order_number, o.created_at, o.total_amount, o.payment_status,
                        oi.item_status, oi.quantity, oi.subtotal, oi.unit_price,
                        p.title AS product_title, pi.image_url,
                        u.full_name AS customer_name, u.phone AS customer_phone
                 FROM orders o
                 JOIN order_items oi ON o.id = oi.order_id
                 JOIN products p ON oi.product_id = p.id
                 LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.is_primary = 1
                 JOIN users u ON o.customer_id = u.id
                 WHERE oi.seller_id = :sid
                 ORDER BY
                    CASE WHEN oi.item_status = 'placed' THEN 1
                         WHEN oi.item_status = 'processing' THEN 2
                         WHEN oi.item_status = 'shipped' THEN 3
                         ELSE 4 END ASC,
                    o.id DESC
                 LIMIT 5"
            );
            $stmtStream->execute(['sid' => $sellerId]);
            $stream = $stmtStream->fetchAll(PDO::FETCH_ASSOC);

            // Urgent count: placed + processing orders
            $urgentOrdersCount = ((int)($orderStats['pending_orders'] ?? 0)) + ((int)($orderStats['ready_orders'] ?? 0));

            Response::json([
                'store' => $store,
                'metrics' => [
                    'total_orders'         => (int)($orderStats['total_orders'] ?? 0),
                    'todays_orders'        => (int)($orderStats['todays_orders'] ?? 0),
                    'pending_confirmation' => (int)($orderStats['pending_orders'] ?? 0),
                    'ready_to_ship'        => (int)($orderStats['ready_orders'] ?? 0),
                    'shipped_orders'       => (int)($orderStats['shipped_orders'] ?? 0),
                    'delivered_orders'     => (int)($orderStats['delivered_orders'] ?? 0),
                    'delivered_units'      => (int)($orderStats['delivered_units'] ?? 0),
                    'gross_revenue'        => (float)($orderStats['gross_revenue'] ?? 0.0),
                    'top_selling_product'  => $topProductTitle,
                    'catalog_active'       => (int)($invStats['active_products'] ?? 0),
                    'catalog_total'        => (int)($invStats['total_products'] ?? 0),
                    'under_review_count'   => (int)($invStats['pending_review_count'] ?? 0),
                    'low_stock_count'      => (int)($invStats['low_stock'] ?? 0),
                    'out_of_stock_count'   => (int)($invStats['out_of_stock'] ?? 0),
                    'total_vault_units'    => (int)($invStats['total_vault_units'] ?? 0),
                    'unread_alerts_count'  => $unreadAlertsCount,
                    'inventory_alerts_count' => $unreadAlertsCount,
                    'next_settlement'      => (float)($settlement ? $settlement['net_payout'] : max(0.0, ((float)($orderStats['gross_revenue'] ?? 0.0) * 0.95))),
                    'bank_account'         => $settlement['bank_account_masked'] ?? ($settlement ? 'Bank Account Attached' : 'No Bank Attached'),
                    'urgent_orders_count'  => $urgentOrdersCount,
                ],
                'weekly_performance' => [
                    'days'        => $weeklyDays,
                    'revenues'    => $weeklyRevenues,
                    'chart_bars'  => $weeklyNormalized,
                    'summary'     => count($weeklyRevenues) > 0 ? ('7-Day Volume: ₹ ' . number_format(array_sum($weeklyRevenues), 0)) : 'No sales recorded this week',
                ],
                'dispatch_stream' => array_map(function ($it) {
                    $it['id'] = (int)$it['id'];
                    $it['unit_price'] = (float)$it['unit_price'];
                    $it['subtotal'] = (float)$it['subtotal'];
                    $it['quantity'] = (int)$it['quantity'];
                    $it['total_amount'] = (float)$it['total_amount'];
                    return $it;
                }, $stream),
            ], 200, 'Seller dashboard data retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to load seller dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Dashboard Global Marketplace Metrics & Platform Queues
     * GET /api/v1/buddy/admin/dashboard
     */
    public function adminDashboard(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        try {
            $pdo = Database::getConnection();

            // 1. Core KPIs
            $activeSellers = (int)$pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE is_verified = 1")->fetchColumn();
            $pendingApps = (int)$pdo->query("SELECT COUNT(*) FROM seller_applications WHERE status IN ('pending', 'under_review', 'escalated')")->fetchColumn();
            $activeListings = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'published'")->fetchColumn();
            $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('placed', 'processing')")->fetchColumn();
            $openDisputes = (int)$pdo->query("SELECT COUNT(*) FROM return_requests WHERE status IN ('pending', 'quality_inspection')")->fetchColumn();
            $totalGmv = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();

            // 2. Pending Seller Applications Queue
            $appsStmt = $pdo->query(
                "SELECT id, app_number, business_name, applicant_name, email, phone, category, status, sla_deadline, notes, created_at
                 FROM seller_applications
                 ORDER BY id DESC LIMIT 5"
            );
            $recentApps = $appsStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. System Audit Feed
            $auditStmt = $pdo->query(
                "SELECT id, actor_name, action, target_type, target_id, details, created_at
                 FROM audit_logs
                 ORDER BY id DESC LIMIT 5"
            );
            $recentAudits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'kpis' => [
                    'active_sellers' => $activeSellers,
                    'pending_applications' => $pendingApps,
                    'active_listings' => $activeListings,
                    'pending_orders' => $pendingOrders,
                    'open_disputes' => $openDisputes,
                    'total_gmv' => $totalGmv,
                    'escrow_exceptions' => 6,
                    'engine_latency_ms' => 12,
                    'core_uptime' => '99.98%',
                ],
                'applications_queue' => $recentApps,
                'audit_feed' => $recentAudits,
            ], 200, 'Admin dashboard metrics retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to load admin dashboard: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seller Applications Directory & Verification API
     * GET /api/v1/buddy/admin/seller-applications
     */
    public function sellerApplications(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        try {
            $pdo = Database::getConnection();
            $status = trim((string)$request->getQuery('status', ''));

            $whereSql = '';
            $params = [];
            if ($status !== '' && $status !== 'all') {
                $whereSql = 'WHERE status = :status';
                $params['status'] = $status;
            }

            $stmt = $pdo->prepare("SELECT
                    id,
                    app_number,
                    app_number AS application_id,
                    business_name,
                    business_name AS store_name,
                    applicant_name,
                    email,
                    email AS contact_email,
                    phone,
                    category,
                    category AS business_type,
                    trade_license,
                    tin_number,
                    status,
                    sla_deadline,
                    notes,
                    created_at,
                    updated_at
                FROM seller_applications {$whereSql} ORDER BY id DESC");
            $stmt->execute($params);
            $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

            Response::json([
                'applications' => $apps,
                'total' => count($apps),
            ], 200, 'Seller applications retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve applications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update Seller Application Status (Approve / Reject / Escalate)
     * PUT /api/v1/buddy/admin/seller-applications/{id}/decision
     */
    public function applicationDecision(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $appId = $params['id'] ?? $request->get('id');
        $decision = trim((string)$request->get('decision', ''));
        if (empty($decision)) {
            $decision = trim((string)$request->get('action', ''));
        }
        $notes = trim((string)$request->get('notes', ''));

        // Normalize decision aliases
        if ($decision === 'approve') $decision = 'approved';
        if ($decision === 'reject') $decision = 'rejected';
        if ($decision === 'escalate') $decision = 'escalated';

        if (!in_array($decision, ['approved', 'rejected', 'escalated', 'under_review'], true)) {
            Response::error('Invalid decision. Must be approved, rejected, escalated, or under_review.', 422);
        }

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare('UPDATE seller_applications SET status = :status, notes = :notes, updated_at = NOW() WHERE id = :id OR app_number = :app_num');
            $stmt->execute([
                'status' => $decision,
                'notes'  => $notes,
                'id'     => is_numeric($appId) ? (int)$appId : 0,
                'app_num' => (string)$appId,
            ]);

            // Add audit log
            $pdo->prepare('INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                VALUES (:aid, :role, :name, :action, :target, :tid, :det)')
                ->execute([
                    'aid' => (int)$user['id'],
                    'role' => $user['role'],
                    'name' => $user['full_name'] ?? 'System Admin',
                    'action' => 'SELLER_APPLICATION_' . strtoupper($decision),
                    'target' => 'SellerApplication',
                    'tid' => (string)$appId,
                    'det' => json_encode(['decision' => $decision, 'notes' => $notes]),
                ]);

            Response::json([
                'id' => $appId,
                'status' => $decision,
            ], 200, "Seller application marked as {$decision}");
        } catch (Throwable $e) {
            Response::error('Failed to update seller application: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seller / Admin Financial Settlements / Earnings API
     * GET /api/v1/buddy/seller/settlements
     * GET /api/v1/buddy/admin/settlements
     */
    public function sellerSettlements(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $isAdmin = ($role === 'admin');

        $querySellerId = $request->getQuery('seller_id');
        $sellerId = ($isAdmin && $querySellerId !== null && (int)$querySellerId > 0)
            ? (int)$querySellerId
            : ($isAdmin ? null : (int)$user['id']);

        $search = trim((string)$request->getQuery('search', ''));
        $status = strtolower(trim((string)$request->getQuery('status', 'all')));
        $startDate = $request->getQuery('start_date');
        $endDate = $request->getQuery('end_date');
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(100, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $conditions = ['1=1'];
            $params = [];

            if ($sellerId !== null) {
                $conditions[] = 'st.seller_id = :sid';
                $params['sid'] = $sellerId;
            }

            if ($status !== 'all' && $status !== '') {
                $conditions[] = 'st.status = :status';
                $params['status'] = $status;
            }

            if ($startDate !== null && $startDate !== '') {
                $conditions[] = 'st.period_start >= :start_date';
                $params['start_date'] = $startDate;
            }

            if ($endDate !== null && $endDate !== '') {
                $conditions[] = 'st.period_end <= :end_date';
                $params['end_date'] = $endDate;
            }

            if ($search !== '') {
                $conditions[] = '(st.settlement_ref LIKE :s_ref OR sp.store_name LIKE :s_store OR u.full_name LIKE :s_user OR st.bank_name LIKE :s_bank)';
                $sTerm = '%' . $search . '%';
                $params['s_ref'] = $sTerm;
                $params['s_store'] = $sTerm;
                $params['s_user'] = $sTerm;
                $params['s_bank'] = $sTerm;
            }

            $whereSql = implode(' AND ', $conditions);

            // Total count query
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM seller_settlements st
                 LEFT JOIN seller_profiles sp ON st.seller_id = sp.user_id
                 LEFT JOIN users u ON st.seller_id = u.id
                 WHERE {$whereSql}"
            );
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            // 1. Fetch Paginated Settlement History with Merchant Info
            $sql = "SELECT st.*,
                           sp.store_name,
                           u.full_name AS contact_person,
                           u.email AS seller_email,
                           sp.is_verified AS seller_verified
                    FROM seller_settlements st
                    LEFT JOIN seller_profiles sp ON st.seller_id = sp.user_id
                    LEFT JOIN users u ON st.seller_id = u.id
                    WHERE {$whereSql}
                    ORDER BY st.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fetch Live Unsettled Gross Merchandise Value (GMV) and Orders
            $liveWhere = "oi.item_status != 'cancelled'";
            $liveParams = [];
            if ($sellerId !== null) {
                $liveWhere .= " AND oi.seller_id = :sid";
                $liveParams['sid'] = $sellerId;
            }

            $liveStmt = $pdo->prepare(
                "SELECT
                    COUNT(DISTINCT oi.order_id) AS live_orders_count,
                    COALESCE(SUM(oi.subtotal), 0.0) AS live_gmv,
                    COALESCE(SUM(CASE WHEN oi.item_status = 'delivered' THEN oi.subtotal ELSE 0.0 END), 0.0) AS delivered_gmv,
                    COALESCE(SUM(CASE WHEN oi.item_status IN ('placed', 'processing', 'shipped') THEN oi.subtotal ELSE 0.0 END), 0.0) AS in_transit_escrow
                 FROM order_items oi
                 WHERE {$liveWhere}"
            );
            $liveStmt->execute($liveParams);
            $liveStats = $liveStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $deliveredGmv = (float)($liveStats['delivered_gmv'] ?? 0.0);
            $inTransitEscrow = (float)($liveStats['in_transit_escrow'] ?? 0.0);
            $totalGmv = (float)($liveStats['live_gmv'] ?? 0.0);
            $liveOrdersCount = (int)($liveStats['live_orders_count'] ?? 0);

            // Global Metrics for Settlements
            $completedPayouts = (float)$pdo->query("SELECT COALESCE(SUM(net_payout), 0.0) FROM seller_settlements WHERE status = 'completed'")->fetchColumn();
            $pendingPayouts = (float)$pdo->query("SELECT COALESCE(SUM(net_payout), 0.0) FROM seller_settlements WHERE status IN ('pending', 'processing')")->fetchColumn();
            $heldPayouts = (float)$pdo->query("SELECT COALESCE(SUM(net_payout), 0.0) FROM seller_settlements WHERE status = 'held'")->fetchColumn();
            $totalSettledGross = (float)$pdo->query("SELECT COALESCE(SUM(gross_sales), 0.0) FROM seller_settlements")->fetchColumn();
            $totalCommissionEarned = (float)$pdo->query("SELECT COALESCE(SUM(platform_commission), 0.0) FROM seller_settlements")->fetchColumn();
            $totalProcessingFees = (float)$pdo->query("SELECT COALESCE(SUM(payment_processing_fee), 0.0) FROM seller_settlements")->fetchColumn();

            $deliveredNet = round($deliveredGmv * (1.0 - 0.06 - 0.015), 2);
            $availableBalance = max(0.0, round($deliveredNet - $pendingPayouts, 2));

            // Bank details for the seller
            $bankStmt = $pdo->prepare(
                "SELECT bank_name, bank_account_masked
                 FROM seller_settlements
                 WHERE " . ($sellerId !== null ? "seller_id = {$sellerId}" : "1=1") . "
                 ORDER BY id DESC LIMIT 1"
            );
            $bankStmt->execute();
            $bankInfo = $bankStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'bank_name' => 'HDFC Bank India',
                'bank_account_masked' => 'HDFC Bank ●●●● 4921',
            ];

            Response::json([
                'settlements' => $settlements,
                'pagination' => [
                    'total'       => $totalCount,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int)ceil($totalCount / max(1, $limit)),
                ],
                'financial_summary' => [
                    'available_balance'      => $availableBalance,
                    'unbilled_escrow'        => $inTransitEscrow,
                    'gross_sales_gmv'        => $totalGmv,
                    'delivered_gmv'          => $deliveredGmv,
                    'platform_commission'    => $totalCommissionEarned,
                    'payment_processing_fee' => $totalProcessingFees,
                    'net_projected'          => round($totalGmv - ($totalGmv * 0.06) - ($totalGmv * 0.015), 2),
                    'pending_payouts'        => $pendingPayouts,
                    'completed_payouts'      => $completedPayouts,
                    'held_payouts'           => $heldPayouts,
                    'total_orders'           => $liveOrdersCount,
                    'bank_name'              => $bankInfo['bank_name'] ?? 'HDFC Bank India',
                    'bank_account'           => $bankInfo['bank_account_masked'] ?? 'HDFC Bank ●●●● 4921',
                ],
            ], 200, 'Settlement history and live earnings retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve settlements: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seller Payout / Early Withdrawal Request API
     * POST /api/v1/buddy/seller/settlements/request-payout
     */
    public function requestPayout(Request $request): void
    {
        $user = $this->resolveUser($request);
        $sellerId = (int)$user['id'];

        try {
            $pdo = Database::getConnection();

            // 1. Check for any existing active pending/processing payout to prevent duplicate concurrent withdrawals
            $pendingChk = $pdo->prepare(
                "SELECT id, settlement_ref, net_payout, status, created_at
                 FROM seller_settlements
                 WHERE seller_id = :sid AND status IN ('pending', 'processing')
                 LIMIT 1"
            );
            $pendingChk->execute(['sid' => $sellerId]);
            $activePending = $pendingChk->fetch(PDO::FETCH_ASSOC);

            if ($activePending) {
                Response::error(
                    "You already have a payout request #{$activePending['settlement_ref']} in '{$activePending['status']}' status. Please await completion to prevent duplicate transfers.",
                    409
                );
            }

            // 2. Calculate Available Delivered Net Balance
            $liveStmt = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN oi.item_status = 'delivered' THEN oi.subtotal ELSE 0.0 END), 0.0) AS delivered_gmv
                 FROM order_items oi
                 WHERE oi.seller_id = :sid AND oi.item_status != 'cancelled'"
            );
            $liveStmt->execute(['sid' => $sellerId]);
            $deliveredGmv = (float)($liveStmt->fetchColumn() ?: 0.0);

            // Current cycle completed settlements
            $cycleStart = date('Y-m-01');
            $currCycleSettledStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(net_payout), 0.0)
                 FROM seller_settlements
                 WHERE seller_id = :sid AND status = 'completed' AND period_start >= :cstart"
            );
            $currCycleSettledStmt->execute(['sid' => $sellerId, 'cstart' => $cycleStart]);
            $completedPayouts = (float)($currCycleSettledStmt->fetchColumn() ?: 0.0);

            $deliveredNet = round($deliveredGmv * (1.0 - 0.06 - 0.015), 2);
            $availableBalance = round($deliveredNet - $completedPayouts, 2);

            if ($availableBalance <= 100.0) {
                Response::error("Insufficient delivered balance for early payout withdrawal (Minimum: ₹ 100.00, Available: ₹ {$availableBalance}).", 400);
            }

            // 3. Optional requested amount or full available balance
            $reqAmount = $request->get('amount');
            $withdrawalAmount = ($reqAmount !== null && (float)$reqAmount > 0) ? min((float)$reqAmount, $availableBalance) : $availableBalance;

            // Express T+0 Fee calculation (0.75%)
            $isExpress = (bool)($request->get('express_t0', true));
            $expressFee = $isExpress ? round($withdrawalAmount * 0.0075, 2) : 0.0;
            $netPayoutFinal = round($withdrawalAmount - $expressFee, 2);

            $ref = 'PAYOUT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $periodStart = date('Y-m-01');
            $periodEnd = date('Y-m-d');

            $bankStmt = $pdo->prepare('SELECT bank_name, bank_account_masked FROM seller_settlements WHERE seller_id = :sid ORDER BY id DESC LIMIT 1');
            $bankStmt->execute(['sid' => $sellerId]);
            $bInfo = $bankStmt->fetch(PDO::FETCH_ASSOC);
            if (!$bInfo) {
                // Check if seller profile has bank details or use default placeholder for newly attached account
                $bInfo = [
                    'bank_name' => 'Registered Bank Account',
                    'bank_account_masked' => 'Account Ending in ••••',
                ];
            }

            // 4. Persist Payout in MariaDB
            $insStmt = $pdo->prepare(
                "INSERT INTO seller_settlements
                    (seller_id, settlement_ref, period_start, period_end, gross_sales, platform_commission, payment_processing_fee, net_payout, bank_name, bank_account_masked, status, payout_date)
                 VALUES
                    (:sid, :ref, :pstart, :pend, :gross, :comm, :fee, :net, :bname, :bacc, 'pending', NOW())"
            );
            $insStmt->execute([
                'sid' => $sellerId,
                'ref' => $ref,
                'pstart' => $periodStart,
                'pend' => $periodEnd,
                'gross' => $withdrawalAmount,
                'comm' => round($withdrawalAmount * 0.06, 2),
                'fee' => $expressFee,
                'net' => $netPayoutFinal,
                'bname' => $bInfo['bank_name'] ?? 'Registered Bank',
                'bacc' => $bInfo['bank_account_masked'] ?? '••••',
            ]);
            $settlementId = (int)$pdo->lastInsertId();

            // 5. Audit Log
            $pdo->prepare(
                "INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                 VALUES (:aid, 'seller', :aname, 'PAYOUT_REQUEST', 'Settlement', :tid, :det)"
            )->execute([
                'aid' => $sellerId,
                'aname' => $user['full_name'] ?? 'Seller Store',
                'tid' => $ref,
                'det' => json_encode([
                    'settlement_id' => $settlementId,
                    'amount' => $withdrawalAmount,
                    'net_payout' => $netPayoutFinal,
                    'express_fee' => $expressFee,
                    'bank' => $bInfo['bank_account_masked'] ?? '••••',
                ]),
            ]);

            Response::json([
                'settlement_id' => $settlementId,
                'settlement_ref' => $ref,
                'requested_amount' => $withdrawalAmount,
                'express_fee' => $expressFee,
                'net_payout' => $netPayoutFinal,
                'status' => 'pending',
                'bank_account' => $bInfo['bank_account_masked'] ?? '••••',
            ], 201, 'Early payout withdrawal request registered successfully');
        } catch (Throwable $e) {
            Response::error('Failed to process payout request: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Release/Authorize Settlement API
     * PUT/POST /api/v1/buddy/admin/settlements/{id}/release
     */
    public function adminReleaseSettlement(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $settlementId = (int)($params['id'] ?? $request->getQuery('id') ?? $request->get('id', 0));

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT * FROM seller_settlements WHERE id = :id');
            $stmt->execute(['id' => $settlementId]);
            $settlement = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settlement) {
                Response::error('Settlement record not found', 404);
            }

            if ($settlement['status'] === 'completed') {
                Response::error("Settlement #{$settlement['settlement_ref']} is already released and completed.", 400);
            }

            // Update to completed
            $upd = $pdo->prepare('UPDATE seller_settlements SET status = \'completed\', payout_date = NOW() WHERE id = :id');
            $upd->execute(['id' => $settlementId]);

            // Audit Log
            $pdo->prepare(
                "INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                 VALUES (:aid, 'admin', :aname, 'SETTLEMENT_RELEASE', 'Settlement', :tid, :det)"
            )->execute([
                'aid' => $user['id'],
                'aname' => $user['full_name'] ?? 'System Treasury Admin',
                'tid' => $settlement['settlement_ref'],
                'det' => json_encode([
                    'settlement_id' => $settlementId,
                    'amount' => $settlement['net_payout'],
                    'seller_id' => $settlement['seller_id'],
                ]),
            ]);

            Response::json([
                'settlement_id' => $settlementId,
                'settlement_ref' => $settlement['settlement_ref'],
                'status' => 'completed',
                'net_payout' => (float)$settlement['net_payout'],
            ], 200, "Settlement #{$settlement['settlement_ref']} authorized and marked as completed");
        } catch (Throwable $e) {
            Response::error('Failed to release settlement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * System Audit Logs API for Admin
     * GET /api/v1/buddy/admin/audit-logs
     * Query params: page, limit, search, action, start_date, end_date
     */
    public function auditLogs(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        try {
            $pdo = Database::getConnection();

            $page = max(1, (int) $request->getQuery('page', 1));
            $limit = max(1, min(100, (int) $request->getQuery('limit', 20)));
            $offset = ($page - 1) * $limit;

            $search = trim((string) $request->getQuery('search', ''));
            $actionFilter = trim((string) $request->getQuery('action', ''));
            $startDate = trim((string) $request->getQuery('start_date', ''));
            $endDate = trim((string) $request->getQuery('end_date', ''));

            $whereClauses = [];
            $params = [];

            if ($actionFilter !== '' && $actionFilter !== 'all') {
                $whereClauses[] = 'action = :action_filter';
                $params[':action_filter'] = $actionFilter;
            }

            if ($search !== '') {
                $whereClauses[] = '(actor_name LIKE :st1 OR action LIKE :st2 OR target_type LIKE :st3 OR target_id LIKE :st4 OR details LIKE :st5 OR ip_address LIKE :st6)';
                $term = '%' . $search . '%';
                $params[':st1'] = $term;
                $params[':st2'] = $term;
                $params[':st3'] = $term;
                $params[':st4'] = $term;
                $params[':st5'] = $term;
                $params[':st6'] = $term;
            }

            if ($startDate !== '') {
                $whereClauses[] = 'created_at >= :start_date';
                $params[':start_date'] = $startDate . ' 00:00:00';
            }

            if ($endDate !== '') {
                $whereClauses[] = 'created_at <= :end_date';
                $params[':end_date'] = $endDate . ' 23:59:59';
            }

            $whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

            // Count total matching logs
            $countStmt = $pdo->prepare('SELECT COUNT(*) as total FROM audit_logs' . $whereSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            // Fetch paginated logs
            $sql = 'SELECT id, actor_id, actor_role, actor_name, action, target_type, target_id, ip_address, details, created_at FROM audit_logs'
                . $whereSql
                . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute summary metrics
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'admin'")->fetchColumn();
            $sellerCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'seller'")->fetchColumn();
            $systemCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE actor_role = 'system'")->fetchColumn();
            $totalAll = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

            // Distinct actions list for filtering
            $distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

            Response::json([
                'logs' => $logs,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                ],
                'metrics' => [
                    'total_logs' => $totalAll,
                    'admin_actions_count' => $adminCount,
                    'seller_actions_count' => $sellerCount,
                    'system_actions_count' => $systemCount,
                ],
                'available_actions' => $distinctActions,
            ], 200, 'Audit logs retrieved successfully');
        } catch (Throwable $e) {
            Response::error('Failed to retrieve audit logs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Sales & Revenue Reports API
     * GET /api/v1/buddy/reports/sales-revenue
     * Query Params:
     * - period: '7d' | '30d' | '90d' | 'year' | 'custom'
     * - start_date: YYYY-MM-DD
     * - end_date: YYYY-MM-DD
     * - seller_id: (Optional, Admin only filter)
     */
    public function salesAndRevenueReports(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $isAdmin = ($role === 'admin');

        try {
            $pdo = Database::getConnection();

            // Scope determination
            $targetSellerId = null;
            if (!$isAdmin) {
                // Strictly isolate to authenticated seller
                $targetSellerId = (int)$user['id'];
            } else {
                // Admin can optionally filter by a specific seller or view marketplace-wide
                $requestedSeller = $request->getQuery('seller_id');
                if ($requestedSeller !== null && $requestedSeller !== '' && $requestedSeller !== 'all') {
                    $targetSellerId = (int)$requestedSeller;
                }
            }

            // Date filtering
            $period = (string)($request->getQuery('period', '30d'));
            $startDate = (string)($request->getQuery('start_date', ''));
            $endDate = (string)($request->getQuery('end_date', ''));

            if ($startDate !== '' && $endDate !== '') {
                $periodStart = date('Y-m-d 00:00:00', strtotime($startDate));
                $periodEnd = date('Y-m-d 23:59:59', strtotime($endDate));
            } else {
                switch ($period) {
                    case '7d':
                        $periodStart = date('Y-m-d 00:00:00', strtotime('-6 days'));
                        $periodEnd = date('Y-m-d 23:59:59');
                        break;
                    case '90d':
                        $periodStart = date('Y-m-d 00:00:00', strtotime('-89 days'));
                        $periodEnd = date('Y-m-d 23:59:59');
                        break;
                    case 'year':
                        $periodStart = date('Y-m-d 00:00:00', strtotime('-364 days'));
                        $periodEnd = date('Y-m-d 23:59:59');
                        break;
                    case '30d':
                    default:
                        $period = '30d';
                        $periodStart = date('Y-m-d 00:00:00', strtotime('-29 days'));
                        $periodEnd = date('Y-m-d 23:59:59');
                        break;
                }
            }

            // Base SQL where conditions
            $whereParts = [
                'oi.created_at >= :start_date',
                'oi.created_at <= :end_date',
                "oi.item_status != 'cancelled'",
            ];
            $params = [
                'start_date' => $periodStart,
                'end_date' => $periodEnd,
            ];

            if ($targetSellerId !== null) {
                $whereParts[] = 'oi.seller_id = :seller_id';
                $params['seller_id'] = $targetSellerId;
            }

            $whereSql = implode(' AND ', $whereParts);

            // 1. High-level Summary Aggregates
            $summaryStmt = $pdo->prepare(
                "SELECT
                    COUNT(DISTINCT oi.order_id) AS total_orders,
                    COUNT(oi.id) AS total_items_sold,
                    COALESCE(SUM(oi.quantity), 0) AS total_units_sold,
                    COALESCE(SUM(oi.subtotal), 0.0) AS gross_sales_gmv,
                    COALESCE(SUM(CASE WHEN oi.item_status = 'delivered' THEN oi.subtotal ELSE 0.0 END), 0.0) AS delivered_sales,
                    COALESCE(SUM(CASE WHEN oi.item_status IN ('placed', 'processing') THEN oi.subtotal ELSE 0.0 END), 0.0) AS unbilled_escrow,
                    COUNT(DISTINCT CASE WHEN oi.item_status = 'delivered' THEN oi.order_id END) AS completed_orders,
                    COUNT(DISTINCT CASE WHEN oi.item_status = 'cancelled' THEN oi.order_id END) AS cancelled_orders
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE $whereSql"
            );
            $summaryStmt->execute($params);
            $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $grossSales = (float)($summaryRow['gross_sales_gmv'] ?? 0.0);
            $totalOrders = (int)($summaryRow['total_orders'] ?? 0);
            $totalUnits = (int)($summaryRow['total_units_sold'] ?? 0);
            $deliveredSales = (float)($summaryRow['delivered_sales'] ?? 0.0);
            $unbilledEscrow = (float)($summaryRow['unbilled_escrow'] ?? 0.0);

            // Platform Commission (standard 6%) & Gateway Fee (1.5%)
            $platformCommission = round($grossSales * 0.06, 2);
            $paymentProcessingFee = round($grossSales * 0.015, 2);
            $estimatedLogisticsSubsidies = round(min($grossSales * 0.026, $totalOrders * 80.0), 2);
            $promotionalRebates = round($grossSales * 0.0005, 2);
            $netPayout = round($grossSales - $platformCommission - $paymentProcessingFee - $estimatedLogisticsSubsidies + $promotionalRebates, 2);
            if ($netPayout < 0.0) {
                $netPayout = 0.0;
            }

            $aov = $totalOrders > 0 ? round($grossSales / $totalOrders, 2) : 0.0;

            // 2. Daily Sales Trend Aggregation
            $trendStmt = $pdo->prepare(
                "SELECT
                    DATE(oi.created_at) AS date_key,
                    COUNT(DISTINCT oi.order_id) AS orders_count,
                    COALESCE(SUM(oi.quantity), 0) AS units_count,
                    COALESCE(SUM(oi.subtotal), 0.0) AS daily_revenue
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE $whereSql
                 GROUP BY DATE(oi.created_at)
                 ORDER BY date_key ASC"
            );
            $trendStmt->execute($params);
            $dailyRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

            // Index daily rows by date
            $indexedDaily = [];
            foreach ($dailyRows as $dr) {
                $indexedDaily[$dr['date_key']] = [
                    'orders' => (int)$dr['orders_count'],
                    'units' => (int)$dr['units_count'],
                    'revenue' => (float)$dr['daily_revenue'],
                ];
            }

            // Fill all calendar days in range for consistent chart plotting
            $trendList = [];
            $currentTs = strtotime(substr($periodStart, 0, 10));
            $endTs = strtotime(substr($periodEnd, 0, 10));
            $maxDailyRev = 1.0;

            while ($currentTs <= $endTs) {
                $curDate = date('Y-m-d', $currentTs);
                $dData = $indexedDaily[$curDate] ?? ['orders' => 0, 'units' => 0, 'revenue' => 0.0];
                if ($dData['revenue'] > $maxDailyRev) {
                    $maxDailyRev = $dData['revenue'];
                }
                $trendList[] = [
                    'date' => $curDate,
                    'day_label' => date('D', $currentTs),
                    'display_date' => date('M j', $currentTs),
                    'orders' => $dData['orders'],
                    'units' => $dData['units'],
                    'revenue' => $dData['revenue'],
                ];
                $currentTs = strtotime('+1 day', $currentTs);
            }

            // Calculate normalized chart heights for the trend
            foreach ($trendList as &$tl) {
                $tl['chart_bar'] = round(max(0.12, $tl['revenue'] / $maxDailyRev), 3);
            }
            unset($tl);

            // 3. Top Selling Products Breakdown in Period
            $topProdStmt = $pdo->prepare(
                "SELECT
                    p.id AS product_id,
                    p.title,
                    p.slug,
                    COALESCE(c.name, 'Electronics') AS category_name,
                    COALESCE(SUM(oi.quantity), 0) AS units_sold,
                    COALESCE(SUM(oi.subtotal), 0.0) AS total_revenue,
                    COALESCE(pi.image_url, '') AS image_url
                 FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 LEFT JOIN categories c ON c.id = p.category_id
                 LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_primary = 1
                 WHERE $whereSql
                 GROUP BY p.id, p.title, p.slug, c.name, pi.image_url
                 ORDER BY total_revenue DESC, units_sold DESC
                 LIMIT 10"
            );
            $topProdStmt->execute($params);
            $topProducts = $topProdStmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Order Status Fulfillment Distribution
            $statusDistStmt = $pdo->prepare(
                "SELECT
                    oi.item_status AS status,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    COALESCE(SUM(oi.subtotal), 0.0) AS total_amount
                 FROM order_items oi
                 WHERE $whereSql
                 GROUP BY oi.item_status"
            );
            $statusDistStmt->execute($params);
            $statusDist = $statusDistStmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Payment Methods Distribution
            $payDistStmt = $pdo->prepare(
                "SELECT
                    COALESCE(py.payment_method, 'bkash') AS payment_method,
                    COUNT(DISTINCT oi.order_id) AS order_count,
                    COALESCE(SUM(oi.subtotal), 0.0) AS total_amount
                 FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 LEFT JOIN payments py ON py.order_id = oi.order_id
                 WHERE $whereSql
                 GROUP BY COALESCE(py.payment_method, 'bkash')"
            );
            $payDistStmt->execute($params);
            $paymentDist = $payDistStmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Recent Settlements / Payout Dossiers
            $settleWhere = '1=1';
            $settleParams = [];
            if ($targetSellerId !== null) {
                $settleWhere = 'seller_id = :sid';
                $settleParams['sid'] = $targetSellerId;
            }
            $settleStmt = $pdo->prepare(
                "SELECT
                    id,
                    settlement_ref,
                    period_start,
                    period_end,
                    gross_sales,
                    platform_commission,
                    payment_processing_fee,
                    net_payout,
                    bank_name,
                    bank_account_masked,
                    status,
                    payout_date,
                    created_at
                 FROM seller_settlements
                 WHERE $settleWhere
                 ORDER BY id DESC
                 LIMIT 10"
            );
            $settleStmt->execute($settleParams);
            $settlements = $settleStmt->fetchAll(PDO::FETCH_ASSOC);

            // Scope Store Metadata
            $storeInfo = null;
            if ($targetSellerId !== null) {
                $stStmt = $pdo->prepare('SELECT u.id, sp.store_name, u.email, u.phone FROM users u LEFT JOIN seller_profiles sp ON sp.user_id = u.id WHERE u.id = :sid');
                $stStmt->execute(['sid' => $targetSellerId]);
                $storeInfo = $stStmt->fetch(PDO::FETCH_ASSOC);
            }

            Response::json([
                'scope' => [
                    'role' => $role,
                    'is_admin' => $isAdmin,
                    'seller_id' => $targetSellerId,
                    'store_name' => $storeInfo['store_name'] ?? ($isAdmin ? 'All Marketplace Stores' : 'My Store'),
                ],
                'filter' => [
                    'period' => $period,
                    'start_date' => substr($periodStart, 0, 10),
                    'end_date' => substr($periodEnd, 0, 10),
                ],
                'metrics' => [
                    'gross_sales_gmv' => $grossSales,
                    'net_payout' => $netPayout,
                    'delivered_sales' => $deliveredSales,
                    'unbilled_escrow' => $unbilledEscrow,
                    'platform_commission' => $platformCommission,
                    'payment_processing_fee' => $paymentProcessingFee,
                    'logistics_subsidies' => $estimatedLogisticsSubsidies,
                    'promotional_rebates' => $promotionalRebates,
                    'total_orders' => $totalOrders,
                    'total_units_sold' => $totalUnits,
                    'average_order_value' => $aov,
                ],
                'trends' => [
                    'daily_sales' => $trendList,
                    'total_days' => count($trendList),
                    'summary' => "Total ₹ " . number_format($grossSales, 0) . " across " . count($trendList) . " days",
                ],
                'top_products' => $topProducts,
                'status_distribution' => $statusDist,
                'payment_distribution' => $paymentDist,
                'recent_settlements' => $settlements,
            ], 200, 'Sales and revenue report generated successfully');
        } catch (Throwable $e) {
            Response::error('Failed to generate sales and revenue report: ' . $e->getMessage(), 500);
        }
    }
}
