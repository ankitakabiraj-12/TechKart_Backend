<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Seller Profile and Store Management for TechKart Buddy.
 *
 * Rules:
 * - Sellers can view their own profile and store details.
 * - Sellers can update their own contact information (full_name, phone, avatar_url)
 *   and store business/address details (store_name, gst_number, address, city, state, pincode).
 * - Only Admins can update verification status (`is_verified`) or view/modify any seller's store profile.
 * - Cross-store permission checks: A seller attempting to modify another seller's profile
 *   is rejected with 403 Forbidden.
 */
class BuddyProfileController
{
    /**
     * Resolve authenticated user and verify role.
     */
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }
        if (!in_array($user['role'], ['seller', 'admin'], true)) {
            Response::error('Forbidden. Sellers and administrators only.', 403);
        }
        return $user;
    }

    /**
     * Get Seller Profile & Store Details
     * GET /api/v1/buddy/seller/profile
     * Query: seller_id? (admin only override)
     */
    public function getProfile(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $targetUserId = (int)$user['id'];

        // If admin provides seller_id query param, allow inspecting that seller
        if ($role === 'admin') {
            $querySellerId = $request->getQuery('seller_id');
            if ($querySellerId !== null && $querySellerId !== '') {
                $targetUserId = (int)$querySellerId;
            }
        }

        try {
            $pdo = Database::getConnection();

            // Fetch user info
            $userStmt = $pdo->prepare('SELECT id, role, full_name, email, phone, avatar_url, is_active, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
            $userStmt->execute(['id' => $targetUserId]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$userData) {
                Response::error('Seller user not found.', 404);
            }

            // Fetch seller store profile
            $spStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $spStmt->execute(['uid' => $targetUserId]);
            $storeProfile = $spStmt->fetch(PDO::FETCH_ASSOC);

            // Fetch summary stats for this seller
            $statsStmt = $pdo->prepare('SELECT
                (SELECT COUNT(*) FROM products WHERE seller_id = :sid1 AND status = "published") AS active_products,
                (SELECT COUNT(DISTINCT order_id) FROM order_items WHERE seller_id = :sid2) AS total_orders,
                (SELECT COUNT(*) FROM return_requests WHERE seller_id = :sid3) AS total_returns
            ');
            $statsStmt->execute([
                'sid1' => $targetUserId,
                'sid2' => $targetUserId,
                'sid3' => $targetUserId,
            ]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'active_products' => 0,
                'total_orders'    => 0,
                'total_returns'   => 0,
            ];

            Response::json([
                'user'  => $userData,
                'store' => $storeProfile ?: [
                    'id'          => null,
                    'user_id'     => $targetUserId,
                    'store_name'  => $userData['full_name'] . ' Store',
                    'gst_number'  => null,
                    'address'     => null,
                    'city'        => 'Mumbai',
                    'state'       => 'Maharashtra',
                    'pincode'     => '400001',
                    'is_verified' => 0,
                ],
                'stats' => [
                    'active_products' => (int)$stats['active_products'],
                    'total_orders'    => (int)$stats['total_orders'],
                    'total_returns'   => (int)$stats['total_returns'],
                    'rating'          => 4.8,
                    'sla_compliance'  => '98.4%',
                    'cancellation_rate' => '0.4%',
                ],
            ], 200, 'Seller profile and store details retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update Seller Profile & Store Details
     * PUT/PATCH /api/v1/buddy/seller/profile
     * Body: {
     *   seller_id?: int (admin only),
     *   full_name?: string,
     *   phone?: string,
     *   avatar_url?: string,
     *   store_name?: string,
     *   gst_number?: string,
     *   address?: string,
     *   city?: string,
     *   state?: string,
     *   pincode?: string,
     *   is_verified?: int|bool (admin only)
     * }
     */
    public function updateProfile(Request $request): void
    {
        $user = $this->resolveUser($request);
        $role = $user['role'];
        $targetUserId = (int)$user['id'];

        $requestedSellerId = $request->get('seller_id') ?? $request->getQuery('seller_id');
        if ($requestedSellerId !== null && $requestedSellerId !== '') {
            $reqId = (int)$requestedSellerId;
            if ($role !== 'admin' && $reqId !== $targetUserId) {
                Response::error('Forbidden. You do not have permission to modify another seller\'s profile.', 403);
            }
            if ($role === 'admin') {
                $targetUserId = $reqId;
            }
        }

        try {
            $pdo = Database::getConnection();

            // Verify target user exists
            $uStmt = $pdo->prepare('SELECT id, role, full_name, email, phone, avatar_url FROM users WHERE id = :id LIMIT 1');
            $uStmt->execute(['id' => $targetUserId]);
            $existingUser = $uStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingUser) {
                Response::error('Seller user not found.', 404);
            }

            // Check existing seller_profile
            $spStmt = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $spStmt->execute(['uid' => $targetUserId]);
            $existingStore = $spStmt->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();

            // 1. Update users fields
            $fullName  = $request->get('full_name');
            $phone     = $request->get('phone');
            $avatarUrl = $request->get('avatar_url');

            $userUpdates = [];
            $userBinds = ['id' => $targetUserId];

            if ($fullName !== null) {
                $trimmedName = trim((string)$fullName);
                if (mb_strlen($trimmedName) < 2) {
                    $pdo->rollBack();
                    Response::error('Full name must be at least 2 characters long.', 422);
                }
                $userUpdates[] = 'full_name = :full_name';
                $userBinds['full_name'] = $trimmedName;
            }

            if ($phone !== null) {
                $userUpdates[] = 'phone = :phone';
                $userBinds['phone'] = trim((string)$phone);
            }

            if ($avatarUrl !== null) {
                $userUpdates[] = 'avatar_url = :avatar_url';
                $userBinds['avatar_url'] = trim((string)$avatarUrl);
            }

            if (!empty($userUpdates)) {
                $userUpdates[] = 'updated_at = NOW()';
                $uSql = 'UPDATE users SET ' . implode(', ', $userUpdates) . ' WHERE id = :id';
                $pdo->prepare($uSql)->execute($userBinds);
            }

            // 2. Update or insert seller_profiles fields
            $storeName  = $request->get('store_name');
            $gstNumber  = $request->get('gst_number');
            $address    = $request->get('address');
            $city       = $request->get('city');
            $state      = $request->get('state');
            $pincode    = $request->get('pincode');
            $isVerified = $request->get('is_verified');

            // Unique store_name check if store_name is being changed
            if ($storeName !== null) {
                $trimmedStoreName = trim((string)$storeName);
                if (mb_strlen($trimmedStoreName) < 2) {
                    $pdo->rollBack();
                    Response::error('Store name must be at least 2 characters long.', 422);
                }
                $checkName = $pdo->prepare('SELECT user_id FROM seller_profiles WHERE LOWER(store_name) = LOWER(:sname) AND user_id != :uid LIMIT 1');
                $checkName->execute(['sname' => $trimmedStoreName, 'uid' => $targetUserId]);
                if ($checkName->fetch()) {
                    $pdo->rollBack();
                    Response::error('Store name is already taken by another merchant.', 409, ['store_name' => 'Store name already in use']);
                }
            }

            if ($existingStore) {
                $storeUpdates = [];
                $storeBinds = ['uid' => $targetUserId];

                if ($storeName !== null) {
                    $storeUpdates[] = 'store_name = :store_name';
                    $storeBinds['store_name'] = trim((string)$storeName);
                }
                if ($gstNumber !== null) {
                    $storeUpdates[] = 'gst_number = :gst_number';
                    $storeBinds['gst_number'] = trim((string)$gstNumber);
                }
                if ($address !== null) {
                    $storeUpdates[] = 'address = :address';
                    $storeBinds['address'] = trim((string)$address);
                }
                if ($city !== null) {
                    $storeUpdates[] = 'city = :city';
                    $storeBinds['city'] = trim((string)$city);
                }
                if ($state !== null) {
                    $storeUpdates[] = 'state = :state';
                    $storeBinds['state'] = trim((string)$state);
                }
                if ($pincode !== null) {
                    $storeUpdates[] = 'pincode = :pincode';
                    $storeBinds['pincode'] = trim((string)$pincode);
                }

                // Verification status: ADMIN ONLY
                if ($isVerified !== null) {
                    if ($role !== 'admin') {
                        $pdo->rollBack();
                        Response::error('Forbidden. Only administrators can change verification status.', 403);
                    }
                    $storeUpdates[] = 'is_verified = :is_verified';
                    $storeBinds['is_verified'] = (int)(bool)$isVerified;
                }

                if (!empty($storeUpdates)) {
                    $storeUpdates[] = 'updated_at = NOW()';
                    $spSql = 'UPDATE seller_profiles SET ' . implode(', ', $storeUpdates) . ' WHERE user_id = :uid';
                    $pdo->prepare($spSql)->execute($storeBinds);
                }
            } else {
                // Insert new profile
                $verifiedVal = 0;
                if ($role === 'admin' && $isVerified !== null) {
                    $verifiedVal = (int)(bool)$isVerified;
                }

                $insStmt = $pdo->prepare('INSERT INTO seller_profiles
                    (user_id, store_name, gst_number, address, city, state, pincode, is_verified, created_at, updated_at)
                    VALUES (:uid, :store_name, :gst_number, :address, :city, :state, :pincode, :verified, NOW(), NOW())');
                $insStmt->execute([
                    'uid'        => $targetUserId,
                    'store_name' => $storeName !== null ? trim((string)$storeName) : ($existingUser['full_name'] . ' Store'),
                    'gst_number' => $gstNumber !== null ? trim((string)$gstNumber) : null,
                    'address'    => $address !== null ? trim((string)$address) : null,
                    'city'       => $city !== null ? trim((string)$city) : 'Mumbai',
                    'state'      => $state !== null ? trim((string)$state) : 'Maharashtra',
                    'pincode'    => $pincode !== null ? trim((string)$pincode) : '400001',
                    'verified'   => $verifiedVal,
                ]);
            }

            // Audit log
            $pdo->prepare('INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                VALUES (:aid, :role, :name, :action, :target, :tid, :det)')
                ->execute([
                    'aid'    => (int)$user['id'],
                    'role'   => $role,
                    'name'   => $user['full_name'] ?? 'Buddy User',
                    'action' => 'SELLER_PROFILE_UPDATE',
                    'target' => 'SellerProfile',
                    'tid'    => (string)$targetUserId,
                    'det'    => json_encode([
                        'updated_by' => $user['email'],
                        'store_name' => $storeName ?? ($existingStore['store_name'] ?? null),
                        'phone'      => $phone ?? ($existingUser['phone'] ?? null),
                    ]),
                ]);

            $pdo->commit();

            // Fetch refreshed data
            $refUser = $pdo->prepare('SELECT id, role, full_name, email, phone, avatar_url, is_active FROM users WHERE id = :id LIMIT 1');
            $refUser->execute(['id' => $targetUserId]);
            $updatedUser = $refUser->fetch(PDO::FETCH_ASSOC);

            $refStore = $pdo->prepare('SELECT * FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $refStore->execute(['uid' => $targetUserId]);
            $updatedStore = $refStore->fetch(PDO::FETCH_ASSOC);

            Response::json([
                'user'  => $updatedUser,
                'store' => $updatedStore,
            ], 200, 'Seller profile and store updated successfully');

        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update seller profile: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Seller Directory API
     * GET /api/v1/buddy/admin/sellers
     * Query: search?, status? ('all'|'verified'|'unverified'|'active'), page?, limit?
     */
    public function adminSellersDirectory(Request $request): void
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

            $conditions = ["u.role = 'seller'"];
            $params = [];

            if ($status === 'verified') {
                $conditions[] = 'sp.is_verified = 1';
            } elseif ($status === 'unverified') {
                $conditions[] = 'sp.is_verified = 0';
            } elseif ($status === 'active') {
                $conditions[] = 'u.is_active = 1';
            }

            if ($search !== '') {
                $conditions[] = '(sp.store_name LIKE :s_name OR u.full_name LIKE :s_user OR u.email LIKE :s_email OR sp.gst_number LIKE :s_gst OR sp.city LIKE :s_city OR sp.state LIKE :s_state)';
                $searchTerm = '%' . $search . '%';
                $params['s_name']  = $searchTerm;
                $params['s_user']  = $searchTerm;
                $params['s_email'] = $searchTerm;
                $params['s_gst']   = $searchTerm;
                $params['s_city']  = $searchTerm;
                $params['s_state'] = $searchTerm;
            }

            $whereSql = implode(' AND ', $conditions);

            $countStmt = $pdo->prepare("SELECT COUNT(u.id)
                FROM users u
                LEFT JOIN seller_profiles sp ON u.id = sp.user_id
                WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT
                        u.id AS user_id,
                        u.full_name AS contact_person,
                        u.email,
                        u.phone,
                        u.avatar_url,
                        u.is_active,
                        sp.id AS profile_id,
                        sp.store_name,
                        sp.gst_number,
                        sp.address,
                        sp.city,
                        sp.state,
                        sp.pincode,
                        sp.is_verified,
                        sp.created_at,
                        (SELECT COUNT(*) FROM products p WHERE p.seller_id = u.id AND p.status = 'published') AS total_products,
                        (SELECT COUNT(DISTINCT oi.order_id) FROM order_items oi WHERE oi.seller_id = u.id) AS total_orders,
                        (SELECT COALESCE(SUM(oi.subtotal), 0) FROM order_items oi WHERE oi.seller_id = u.id AND oi.item_status != 'cancelled') AS total_revenue
                    FROM users u
                    LEFT JOIN seller_profiles sp ON u.id = sp.user_id
                    WHERE {$whereSql}
                    ORDER BY u.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Metrics summary for directory header
            $totalSellers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller'")->fetchColumn();
            $verifiedSellers = (int)$pdo->query("SELECT COUNT(*) FROM seller_profiles WHERE is_verified = 1")->fetchColumn();
            $totalEscrow = (float)$pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();

            Response::json([
                'metrics' => [
                    'total_merchants'   => $totalSellers,
                    'verified_pool'     => $verifiedSellers,
                    'compliance_rate'   => '98.2%',
                    'escrow_volume_fmt' => '₹ ' . number_format($totalEscrow, 2),
                ],
                'sellers'    => $sellers,
                'pagination' => [
                    'total'       => $total,
                    'page'        => $page,
                    'limit'       => $limit,
                    'total_pages' => (int)ceil($total / $limit),
                ],
            ], 200, 'Seller directory retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve seller directory: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin Update Seller Verification Status
     * PUT/PATCH /api/v1/buddy/admin/sellers/{id}/verification
     * Body: { is_verified: int|bool, notes?: string }
     */
    public function updateVerificationStatus(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $sellerId = (int)($params['id'] ?? $request->get('seller_id', 0));
        if ($sellerId <= 0) {
            Response::error('Invalid seller ID.', 400);
        }

        $isVerified = $request->get('is_verified');
        if ($isVerified === null) {
            Response::error('Missing is_verified parameter.', 422);
        }

        $statusVal = (int)(bool)$isVerified;
        $notes = trim((string)$request->get('notes', ''));

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare('SELECT id, user_id, store_name FROM seller_profiles WHERE user_id = :uid LIMIT 1');
            $checkStmt->execute(['uid' => $sellerId]);
            $store = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$store) {
                Response::error('Seller store profile not found.', 404);
            }

            $updateStmt = $pdo->prepare('UPDATE seller_profiles SET is_verified = :v, updated_at = NOW() WHERE user_id = :uid');
            $updateStmt->execute(['v' => $statusVal, 'uid' => $sellerId]);

            // Add audit log
            $pdo->prepare('INSERT INTO audit_logs (actor_id, actor_role, actor_name, action, target_type, target_id, details)
                VALUES (:aid, :role, :name, :action, :target, :tid, :det)')
                ->execute([
                    'aid'    => (int)$user['id'],
                    'role'   => 'admin',
                    'name'   => $user['full_name'] ?? 'System Admin',
                    'action' => $statusVal === 1 ? 'SELLER_VERIFICATION_APPROVED' : 'SELLER_VERIFICATION_REVOKED',
                    'target' => 'SellerProfile',
                    'tid'    => (string)$sellerId,
                    'det'    => json_encode(['store_name' => $store['store_name'], 'is_verified' => $statusVal, 'notes' => $notes]),
                ]);

            Response::json([
                'seller_id'   => $sellerId,
                'store_name'  => $store['store_name'],
                'is_verified' => $statusVal,
            ], 200, 'Seller verification status updated successfully');

        } catch (Throwable $e) {
            Response::error('Failed to update verification status: ' . $e->getMessage(), 500);
        }
    }
}
