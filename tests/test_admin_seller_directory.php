<?php
/**
 * Test Admin Seller Directory API integration
 * Verifies live database retrieval, search, filter tabs, pagination, and KYC toggle
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

echo "=== TECHKART BUDDY ADMIN SELLER DIRECTORY TEST ===\n\n";

// 1. Login as Admin
echo "[1] Admin Login...\n";
$loginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

$adminToken = $loginRes['body']['token'] ?? $loginRes['body']['data']['token'] ?? null;
if (!$adminToken) {
    echo "FAILED: Admin token not found in response.\n";
    exit(1);
}
$adminHeaders = ["Authorization: Bearer $adminToken"];
echo "  SUCCESS: Admin logged in.\n\n";

// 2. Login as regular Seller (Seller 2) to test RBAC protection
echo "[2] Seller Login (RBAC check)...\n";
$sellerLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$sellerToken = $sellerLogin['body']['token'] ?? $sellerLogin['body']['data']['token'] ?? null;
if ($sellerToken) {
    $forbiddenRes = makeRequest('GET', "$baseUrl/buddy/admin/sellers", ["Authorization: Bearer $sellerToken"]);
    if ($forbiddenRes['code'] === 403) {
        echo "  SUCCESS: Seller correctly rejected with 403 Forbidden on admin seller directory.\n\n";
    } else {
        echo "  FAILED: Expected 403 Forbidden for non-admin, got {$forbiddenRes['code']}.\n\n";
        exit(1);
    }
}

// 3. Fetch Full Directory (Default Pagination: Page 1, Limit 10)
echo "[3] Fetch Full Directory (Page 1)...\n";
$dirRes = makeRequest('GET', "$baseUrl/buddy/admin/sellers?page=1&limit=10", $adminHeaders);
if ($dirRes['code'] !== 200 || !$dirRes['body']['success']) {
    echo "  FAILED: Could not fetch seller directory. Code: {$dirRes['code']}, Body: {$dirRes['raw']}\n";
    exit(1);
}
$data = $dirRes['body']['data'];
$metrics = $data['metrics'];
$sellers = $data['sellers'];
$pagination = $data['pagination'];

echo "  SUCCESS: Directory fetched.\n";
echo "  - Total Merchants: " . ($metrics['total_merchants'] ?? 'N/A') . "\n";
echo "  - Verified Pool: " . ($metrics['verified_pool'] ?? 'N/A') . "\n";
echo "  - Compliance Rate: " . ($metrics['compliance_rate'] ?? 'N/A') . "\n";
echo "  - Escrow Volume: " . ($metrics['escrow_volume_fmt'] ?? 'N/A') . "\n";
echo "  - Sellers Returned: " . count($sellers) . " (Total in DB: {$pagination['total']})\n\n";

// 4. Test Filter Tabs
echo "[4] Testing Filter Tabs...\n";
// 4a. Verified filter
$verifiedRes = makeRequest('GET', "$baseUrl/buddy/admin/sellers?status=verified", $adminHeaders);
$verifiedSellers = $verifiedRes['body']['data']['sellers'] ?? [];
echo "  - Filter 'verified': " . count($verifiedSellers) . " sellers found.\n";
foreach ($verifiedSellers as $s) {
    if ((int)$s['is_verified'] !== 1) {
        echo "    FAILED: Seller {$s['store_name']} has is_verified=" . $s['is_verified'] . ", expected 1\n";
        exit(1);
    }
}

// 4b. Unverified filter
$unverifiedRes = makeRequest('GET', "$baseUrl/buddy/admin/sellers?status=unverified", $adminHeaders);
$unverifiedSellers = $unverifiedRes['body']['data']['sellers'] ?? [];
echo "  - Filter 'unverified': " . count($unverifiedSellers) . " sellers found.\n";
foreach ($unverifiedSellers as $s) {
    if ((int)$s['is_verified'] !== 0) {
        echo "    FAILED: Seller {$s['store_name']} has is_verified=" . $s['is_verified'] . ", expected 0\n";
        exit(1);
    }
}

// 4c. Active filter
$activeRes = makeRequest('GET', "$baseUrl/buddy/admin/sellers?status=active", $adminHeaders);
$activeSellers = $activeRes['body']['data']['sellers'] ?? [];
echo "  - Filter 'active': " . count($activeSellers) . " sellers found.\n";
foreach ($activeSellers as $s) {
    if ((int)$s['is_active'] !== 1) {
        echo "    FAILED: Seller {$s['store_name']} has is_active=" . $s['is_active'] . ", expected 1\n";
        exit(1);
    }
}
echo "  SUCCESS: Status filter tabs validated.\n\n";

// 5. Test Search
echo "[5] Testing Search Queries...\n";
// 5a. Search for "Apex"
$searchApex = makeRequest('GET', "$baseUrl/buddy/admin/sellers?search=Apex", $adminHeaders);
$apexSellers = $searchApex['body']['data']['sellers'] ?? [];
echo "  - Search 'Apex': " . count($apexSellers) . " match(es) found.\n";
if (empty($apexSellers) || stripos($apexSellers[0]['store_name'], 'Apex') === false) {
    echo "    FAILED: Search for 'Apex' did not return Apex Gear India.\n";
    exit(1);
}

// 5b. Search for "Bengaluru" (city search)
$searchCity = makeRequest('GET', "$baseUrl/buddy/admin/sellers?search=Bengaluru", $adminHeaders);
$bengaluruSellers = $searchCity['body']['data']['sellers'] ?? [];
echo "  - Search 'Bengaluru': " . count($bengaluruSellers) . " match(es) found.\n";
if (empty($bengaluruSellers)) {
    echo "    FAILED: Search for 'Bengaluru' returned 0 results.\n";
    exit(1);
}
echo "  SUCCESS: Search queries validated.\n\n";

// 6. Test KYC / Verification Status Toggle API
echo "[6] Testing Verification Toggle API...\n";
// Find an unverified seller or toggle User 14
$testTargetUserId = 14; // Kiran Deshmukh / VoltTech
$toggleOnRes = makeRequest('PUT', "$baseUrl/buddy/admin/sellers/$testTargetUserId/verification", $adminHeaders, [
    'is_verified' => 1
]);
if ($toggleOnRes['code'] !== 200 || !($toggleOnRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not toggle verification to 1. Code: {$toggleOnRes['code']}, Body: {$toggleOnRes['raw']}\n";
    exit(1);
}
echo "  - Toggled seller $testTargetUserId to Verified (1): " . $toggleOnRes['body']['message'] . "\n";

// Verify in DB via directory query
$checkRes1 = makeRequest('GET', "$baseUrl/buddy/admin/sellers?search=VoltTech", $adminHeaders);
$voltTech1 = $checkRes1['body']['data']['sellers'][0] ?? null;
if (!$voltTech1 || (int)$voltTech1['is_verified'] !== 1) {
    echo "  FAILED: VoltTech verification state did not reflect as 1 in directory query.\n";
    exit(1);
}
echo "  - Reflected in directory: is_verified = 1\n";

// Toggle back to 0
$toggleOffRes = makeRequest('PUT', "$baseUrl/buddy/admin/sellers/$testTargetUserId/verification", $adminHeaders, [
    'is_verified' => 0
]);
if ($toggleOffRes['code'] !== 200 || !($toggleOffRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not toggle verification back to 0.\n";
    exit(1);
}
echo "  - Toggled seller $testTargetUserId back to Unverified (0): " . $toggleOffRes['body']['message'] . "\n";

$checkRes2 = makeRequest('GET', "$baseUrl/buddy/admin/sellers?search=VoltTech", $adminHeaders);
$voltTech2 = $checkRes2['body']['data']['sellers'][0] ?? null;
if (!$voltTech2 || (int)$voltTech2['is_verified'] !== 0) {
    echo "  FAILED: VoltTech verification state did not reflect as 0 in directory query.\n";
    exit(1);
}
echo "  - Reflected in directory: is_verified = 0\n";
echo "  SUCCESS: Verification toggle API & database persistence validated.\n\n";

echo "=== ALL ADMIN SELLER DIRECTORY TESTS PASSED ===\n";
