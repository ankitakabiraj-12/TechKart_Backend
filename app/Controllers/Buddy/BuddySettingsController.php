<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

class BuddySettingsController
{
    /**
     * Resolve authenticated user
     */
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Authentication required.', 401);
        }
        return $user;
    }

    /**
     * Get All System Settings & Configurable Commission Rates
     * GET /api/v1/buddy/admin/settings
     */
    public function getSettings(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        try {
            $pdo = Database::getConnection();

            // 1. Fetch Key-Value platform settings
            $stmt = $pdo->query('SELECT setting_key, setting_value, setting_group, description, updated_at FROM platform_settings');
            $rawSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $settingsMap = [];
            foreach ($rawSettings as $row) {
                $val = $row['setting_value'];
                // Cast numerics and booleans appropriately
                if (is_numeric($val)) {
                    $val = strpos($val, '.') !== false ? (float)$val : (int)$val;
                }
                $settingsMap[$row['setting_key']] = $val;
            }

            // Defaults if not set yet
            $returnWindowDays = (int)($settingsMap['return_window_days'] ?? 7);
            $defaultCommissionRate = (float)($settingsMap['default_commission_rate'] ?? 6.0);
            $processingFeeRate = (float)($settingsMap['payment_processing_fee_rate'] ?? 1.5);
            $autoRejectBin = (int)($settingsMap['auto_reject_duplicate_bin'] ?? 1) === 1;

            $kycRules = [
                'trade_license' => (int)($settingsMap['kyc_trade_license_required'] ?? 1) === 1,
                'tin'           => (int)($settingsMap['kyc_tin_required'] ?? 1) === 1,
                'biometric_nid' => (int)($settingsMap['kyc_biometric_nid_required'] ?? 1) === 1,
                'bank_cheque'   => (int)($settingsMap['kyc_bank_cheque_required'] ?? 1) === 1,
            ];

            // 2. Fetch category-wise commission tiers
            $catSql = 'SELECT ccr.id, ccr.category_id, ccr.commission_rate, ccr.min_commission, ccr.max_commission, ccr.is_active,
                              c.name AS category_name, c.slug AS category_slug, c.parent_id
                       FROM category_commission_rates ccr
                       JOIN categories c ON ccr.category_id = c.id
                       ORDER BY c.parent_id ASC, c.name ASC';
            $catStmt = $pdo->query($catSql);
            $categoriesCommission = $catStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categoriesCommission as &$catComm) {
                $catComm['id'] = (int)$catComm['id'];
                $catComm['category_id'] = (int)$catComm['category_id'];
                $catComm['commission_rate'] = (float)$catComm['commission_rate'];
                $catComm['min_commission'] = (float)$catComm['min_commission'];
                $catComm['max_commission'] = $catComm['max_commission'] !== null ? (float)$catComm['max_commission'] : null;
                $catComm['is_active'] = (int)$catComm['is_active'];
            }
            unset($catComm);

            Response::json([
                'system_version'                 => 'v4.2.1 Stable',
                'node_id'                        => 'NODE-CENTRAL-01 (IN-MUM-01)',
                'default_commission_rate'        => $defaultCommissionRate,
                'payment_processing_fee_rate'    => $processingFeeRate,
                'return_window_days'             => $returnWindowDays,
                'auto_reject_duplicate_bin'      => $autoRejectBin,
                'kyc_rules'                      => $kycRules,
                'category_commission_rates'      => $categoriesCommission,
                'last_updated'                   => date('Y-m-d H:i:s'),
            ], 200, 'System settings and commission rates retrieved successfully');

        } catch (Throwable $e) {
            Response::error('Failed to retrieve system settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update Global System Policies & Rates
     * PUT/PATCH /api/v1/buddy/admin/settings
     * Body: {
     *   default_commission_rate?: float,
     *   payment_processing_fee_rate?: float,
     *   return_window_days?: int,
     *   auto_reject_duplicate_bin?: bool,
     *   kyc_trade_license_required?: bool,
     *   kyc_tin_required?: bool,
     *   kyc_biometric_nid_required?: bool,
     *   kyc_bank_cheque_required?: bool
     * }
     */
    public function updateSettings(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        try {
            $pdo = Database::getConnection();

            $updates = [];

            // 1. Commission rate validation (0% to 100%)
            $defaultCommission = $request->get('default_commission_rate');
            if ($defaultCommission !== null) {
                $commVal = (float)$defaultCommission;
                if ($commVal < 0.0 || $commVal > 50.0) {
                    Response::error('Default commission rate must be between 0.0% and 50.0%.', 422);
                }
                $updates['default_commission_rate'] = number_format($commVal, 2, '.', '');
            }

            // 2. Payment processing fee validation (0% to 20%)
            $processingFee = $request->get('payment_processing_fee_rate');
            if ($processingFee !== null) {
                $feeVal = (float)$processingFee;
                if ($feeVal < 0.0 || $feeVal > 20.0) {
                    Response::error('Payment processing fee rate must be between 0.0% and 20.0%.', 422);
                }
                $updates['payment_processing_fee_rate'] = number_format($feeVal, 2, '.', '');
            }

            // 3. Return window days validation (1 to 90 days)
            $returnWindow = $request->get('return_window_days');
            if ($returnWindow !== null) {
                $days = (int)$returnWindow;
                if ($days < 1 || $days > 90) {
                    Response::error('Return window grace period must be between 1 and 90 days.', 422);
                }
                $updates['return_window_days'] = (string)$days;
            }

            // 4. Policy and KYC toggles
            if ($request->get('auto_reject_duplicate_bin') !== null) {
                $updates['auto_reject_duplicate_bin'] = (bool)$request->get('auto_reject_duplicate_bin') ? '1' : '0';
            }
            if ($request->get('kyc_trade_license_required') !== null) {
                $updates['kyc_trade_license_required'] = (bool)$request->get('kyc_trade_license_required') ? '1' : '0';
            }
            if ($request->get('kyc_tin_required') !== null) {
                $updates['kyc_tin_required'] = (bool)$request->get('kyc_tin_required') ? '1' : '0';
            }
            if ($request->get('kyc_biometric_nid_required') !== null) {
                $updates['kyc_biometric_nid_required'] = (bool)$request->get('kyc_biometric_nid_required') ? '1' : '0';
            }
            if ($request->get('kyc_bank_cheque_required') !== null) {
                $updates['kyc_bank_cheque_required'] = (bool)$request->get('kyc_bank_cheque_required') ? '1' : '0';
            }

            if (empty($updates)) {
                Response::error('No settings provided to update.', 400);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:key, :val, :uid, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()'
            );

            foreach ($updates as $k => $v) {
                $stmt->execute([
                    'key' => $k,
                    'val' => $v,
                    'uid' => (int)$user['id'],
                ]);
            }

            Response::json([
                'updated_settings' => $updates,
            ], 200, 'System settings updated successfully');

        } catch (Throwable $e) {
            Response::error('Failed to update system settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update Category-Specific Commission Rate
     * PUT/PATCH /api/v1/buddy/admin/settings/commissions/{category_id}
     * Body: { commission_rate: float, min_commission?: float, max_commission?: float|null, is_active?: bool }
     */
    public function updateCategoryCommission(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Administrators only.', 403);
        }

        $categoryId = (int)($params['category_id'] ?? $request->get('category_id', 0));
        if ($categoryId <= 0) {
            Response::error('Invalid category ID.', 400);
        }

        $rate = $request->get('commission_rate');
        if ($rate === null) {
            Response::error('Commission rate is required.', 422);
        }

        $rateVal = (float)$rate;
        if ($rateVal < 0.0 || $rateVal > 50.0) {
            Response::error('Commission rate must be between 0.0% and 50.0%.', 422);
        }

        $minComm = $request->get('min_commission') !== null ? (float)$request->get('min_commission') : 0.0;
        $maxComm = $request->get('max_commission') !== null && $request->get('max_commission') !== ''
            ? (float)$request->get('max_commission')
            : null;
        $isActive = $request->get('is_active') !== null ? (int)(bool)$request->get('is_active') : 1;

        try {
            $pdo = Database::getConnection();

            // Validate category existence
            $catStmt = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id LIMIT 1');
            $catStmt->execute(['id' => $categoryId]);
            $category = $catStmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {
                Response::error('Category not found.', 404);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO category_commission_rates (category_id, commission_rate, min_commission, max_commission, is_active, updated_at)
                 VALUES (:cid, :rate, :min_c, :max_c, :active, NOW())
                 ON DUPLICATE KEY UPDATE
                    commission_rate = VALUES(commission_rate),
                    min_commission = VALUES(min_commission),
                    max_commission = VALUES(max_commission),
                    is_active = VALUES(is_active),
                    updated_at = NOW()'
            );

            $stmt->execute([
                'cid'    => $categoryId,
                'rate'   => $rateVal,
                'min_c'  => $minComm,
                'max_c'  => $maxComm,
                'active' => $isActive,
            ]);

            Response::json([
                'category_id'     => $categoryId,
                'category_name'   => $category['name'],
                'commission_rate' => $rateVal,
                'min_commission'  => $minComm,
                'max_commission'  => $maxComm,
                'is_active'       => $isActive,
            ], 200, "Commission rate for {$category['name']} updated successfully");

        } catch (Throwable $e) {
            Response::error('Failed to update category commission: ' . $e->getMessage(), 500);
        }
    }
}
