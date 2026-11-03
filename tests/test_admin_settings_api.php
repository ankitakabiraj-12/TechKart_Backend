<?php
/**
 * Test Admin Configurable Commission Rate & System Settings API
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== TECHKART BUDDY ADMIN COMMISSION & SYSTEM SETTINGS API TEST ===\n\n";

// 1. Admin Login
echo "[1] Admin Login...\n";
$loginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

$adminToken = $loginRes['body']['token'] ?? $loginRes['body']['data']['token'] ?? null;
if (!$adminToken) {
    echo "FAILED: Admin login failed. Response: " . $loginRes['raw'] . "\n";
    exit(1);
}
$adminHeaders = ["Authorization: Bearer $adminToken"];
echo "  SUCCESS: Admin logged in.\n\n";

// 2. Authorization / RBAC check: non-admin seller rejected with 403
echo "[2] Seller RBAC check on settings endpoints...\n";
$sellerLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$sellerToken = $sellerLogin['body']['token'] ?? $sellerLogin['body']['data']['token'] ?? null;
if ($sellerToken) {
    $forbiddenGet = makeRequest('GET', "$baseUrl/buddy/admin/settings", ["Authorization: Bearer $sellerToken"]);
    $forbiddenPut = makeRequest('PUT', "$baseUrl/buddy/admin/settings", ["Authorization: Bearer $sellerToken"], ['default_commission_rate' => 5.0]);
    if ($forbiddenGet['code'] === 403 && $forbiddenPut['code'] === 403) {
        echo "  SUCCESS: Seller correctly rejected with 403 Forbidden on settings endpoints.\n\n";
    } else {
        echo "  FAILED: Expected 403 Forbidden for seller, got GET:{$forbiddenGet['code']}, PUT:{$forbiddenPut['code']}.\n\n";
        exit(1);
    }
}

// 3. Unauthenticated request check
echo "[3] Unauthenticated request check...\n";
$unauthRes = makeRequest('GET', "$baseUrl/buddy/admin/settings");
if ($unauthRes['code'] === 401) {
    echo "  SUCCESS: Unauthenticated request rejected with 401 Unauthorized.\n\n";
} else {
    echo "  FAILED: Expected 401 Unauthorized, got {$unauthRes['code']}.\n\n";
    exit(1);
}

// 4. Fetch System Settings & Commission Rates
echo "[4] Fetch System Settings from MariaDB...\n";
$settingsRes = makeRequest('GET', "$baseUrl/buddy/admin/settings", $adminHeaders);
if ($settingsRes['code'] !== 200 || !($settingsRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not retrieve settings. Response: {$settingsRes['raw']}\n";
    exit(1);
}
$data = $settingsRes['body']['data'];
echo "  SUCCESS: Settings retrieved from database.\n";
echo "  - Version: {$data['system_version']}\n";
echo "  - Node: {$data['node_id']}\n";
echo "  - Default Commission Rate: {$data['default_commission_rate']}%\n";
echo "  - Payment Processing Fee: {$data['payment_processing_fee_rate']}%\n";
echo "  - Return Window: {$data['return_window_days']} days\n";
echo "  - Category Tiers Count: " . count($data['category_commission_rates']) . "\n\n";

// 5. Validation on Invalid Commission Rates & Policies
echo "[5] Testing Commission & Setting Validations...\n";
// Out of bounds commission rate (> 50%)
$invalidRateRes = makeRequest('PUT', "$baseUrl/buddy/admin/settings", $adminHeaders, [
    'default_commission_rate' => 99.5
]);
if ($invalidRateRes['code'] === 422) {
    echo "  SUCCESS: Out of bounds commission rate (>50%) rejected with 422: {$invalidRateRes['body']['message']}\n";
} else {
    echo "  FAILED: Expected 422 for commission rate > 50%, got {$invalidRateRes['code']}.\n";
    exit(1);
}

// Negative processing fee
$invalidFeeRes = makeRequest('PUT', "$baseUrl/buddy/admin/settings", $adminHeaders, [
    'payment_processing_fee_rate' => -2.0
]);
if ($invalidFeeRes['code'] === 422) {
    echo "  SUCCESS: Negative processing fee rejected with 422: {$invalidFeeRes['body']['message']}\n";
} else {
    echo "  FAILED: Expected 422 for negative fee, got {$invalidFeeRes['code']}.\n";
    exit(1);
}

// Return window > 90 days
$invalidWindowRes = makeRequest('PUT', "$baseUrl/buddy/admin/settings", $adminHeaders, [
    'return_window_days' => 120
]);
if ($invalidWindowRes['code'] === 422) {
    echo "  SUCCESS: Return window > 90 days rejected with 422: {$invalidWindowRes['body']['message']}\n\n";
} else {
    echo "  FAILED: Expected 422 for return window > 90 days, got {$invalidWindowRes['code']}.\n";
    exit(1);
}

// 6. Update and Persist Global Settings
echo "[6] Updating and Persisting Global System Settings in MariaDB...\n";
$updateRes = makeRequest('PUT', "$baseUrl/buddy/admin/settings", $adminHeaders, [
    'default_commission_rate' => 6.5,
    'payment_processing_fee_rate' => 1.8,
    'return_window_days' => 10,
    'auto_reject_duplicate_bin' => true,
    'kyc_trade_license_required' => true,
    'kyc_tin_required' => true,
    'kyc_biometric_nid_required' => true,
    'kyc_bank_cheque_required' => true,
]);
if ($updateRes['code'] !== 200 || !($updateRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not update global settings. Response: {$updateRes['raw']}\n";
    exit(1);
}
echo "  SUCCESS: Global settings updated in MariaDB.\n";

// Verify in DB via GET
$checkRes = makeRequest('GET', "$baseUrl/buddy/admin/settings", $adminHeaders);
$checkData = $checkRes['body']['data'];
if ($checkData['default_commission_rate'] != 6.5 || $checkData['return_window_days'] != 10) {
    echo "  FAILED: Updated settings did not persist correctly in MariaDB.\n";
    exit(1);
}
echo "  - Verified in DB: default_commission_rate = {$checkData['default_commission_rate']}%, return_window_days = {$checkData['return_window_days']}\n\n";

// 7. Update Category Commission Rate
echo "[7] Updating Category-Specific Commission Rate...\n";
// Update Audio & Studio Sound (Category ID 3) to 8.5%
$updateCatRes = makeRequest('PUT', "$baseUrl/buddy/admin/settings/commissions/3", $adminHeaders, [
    'commission_rate' => 8.5,
    'min_commission' => 20.0,
    'is_active' => true,
]);
if ($updateCatRes['code'] !== 200 || !($updateCatRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not update category commission. Response: {$updateCatRes['raw']}\n";
    exit(1);
}
echo "  SUCCESS: " . $updateCatRes['body']['message'] . "\n";

// Verify category update in DB
$checkCatRes = makeRequest('GET', "$baseUrl/buddy/admin/settings", $adminHeaders);
$catRates = $checkCatRes['body']['data']['category_commission_rates'];
$audioRate = null;
foreach ($catRates as $cr) {
    if ($cr['category_id'] == 3) {
        $audioRate = $cr['commission_rate'];
        break;
    }
}
if ($audioRate != 8.5) {
    echo "  FAILED: Category commission rate did not persist as 8.5 in MariaDB (got $audioRate).\n";
    exit(1);
}
echo "  - Verified in DB: Audio & Studio Sound commission_rate = {$audioRate}%\n\n";

echo "=== ALL COMMISSION & SYSTEM SETTINGS TESTS PASSED ===\n";
