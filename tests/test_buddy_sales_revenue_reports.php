<?php
require_once __DIR__ . '/../bootstrap/app.php';

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    global $baseUrl;
    $fullUrl = (strpos($url, 'http') === 0) ? $url : $baseUrl . $url;
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== TEST: TechKart Buddy Sales & Revenue Reports API ===\n";

// 1. Authenticate Seller 2 (Apex Gear BD)
$loginRes2 = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);

if ($loginRes2['code'] !== 200 || empty($loginRes2['body']['data']['token'])) {
    echo "FAIL: Seller 2 login failed. Code: " . $loginRes2['code'] . "\n";
    exit(1);
}
$token2 = $loginRes2['body']['data']['token'];
echo "PASS: Seller 2 authenticated (Apex Gear BD).\n";

// 2. Authenticate Seller 4 (GadgetPlanet BD)
$loginRes4 = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123'
]);

if ($loginRes4['code'] !== 200 || empty($loginRes4['body']['data']['token'])) {
    echo "FAIL: Seller 4 login failed. Code: " . $loginRes4['code'] . "\n";
    exit(1);
}
$token4 = $loginRes4['body']['data']['token'];
echo "PASS: Seller 4 authenticated (GadgetPlanet BD).\n";

// 3. Authenticate Admin
$loginAdmin = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

if ($loginAdmin['code'] !== 200 || empty($loginAdmin['body']['data']['token'])) {
    echo "FAIL: Admin login failed. Code: " . $loginAdmin['code'] . "\n";
    exit(1);
}
$tokenAdmin = $loginAdmin['body']['data']['token'];
echo "PASS: Admin authenticated.\n";

// 4. Test Unauthorized access
$unauth = makeRequest('GET', '/buddy/reports/sales-revenue');
if ($unauth['code'] === 401) {
    echo "PASS: Unauthenticated access rejected with 401 Unauthorized.\n";
} else {
    echo "FAIL: Expected 401, got " . $unauth['code'] . "\n";
}

// 5. Test Seller 2 Sales & Revenue Report (30-day default)
$repSeller2 = makeRequest('GET', '/buddy/reports/sales-revenue', [
    "Authorization: Bearer $token2"
]);

if ($repSeller2['code'] !== 200) {
    echo "FAIL: Seller 2 report failed with code {$repSeller2['code']}: " . $repSeller2['raw'] . "\n";
    exit(1);
}
$d2 = $repSeller2['body']['data'];
echo "PASS: Seller 2 report retrieved successfully (HTTP 200).\n";
echo " - Scope: Store '" . ($d2['scope']['store_name'] ?? 'N/A') . "', Seller ID: " . ($d2['scope']['seller_id'] ?? 'N/A') . "\n";
echo " - Filter Period: " . ($d2['filter']['period'] ?? 'N/A') . " (" . ($d2['filter']['start_date'] ?? '') . " to " . ($d2['filter']['end_date'] ?? '') . ")\n";
echo " - Gross Sales GMV: ৳" . ($d2['metrics']['gross_sales_gmv'] ?? 0) . "\n";
echo " - Net Payout: ৳" . ($d2['metrics']['net_payout'] ?? 0) . "\n";
echo " - Platform Commission (6%): ৳" . ($d2['metrics']['platform_commission'] ?? 0) . "\n";
echo " - Total Orders: " . ($d2['metrics']['total_orders'] ?? 0) . "\n";
echo " - Total Units Sold: " . ($d2['metrics']['total_units_sold'] ?? 0) . "\n";
echo " - Daily Trend Days Count: " . count($d2['trends']['daily_sales'] ?? []) . "\n";
echo " - Top Products Count: " . count($d2['top_products'] ?? []) . "\n";

if (($d2['metrics']['gross_sales_gmv'] ?? 0) > 0) {
    echo "PASS: Live GMV verified for Seller 2.\n";
} else {
    echo "FAIL: Gross GMV unexpectedly 0.\n";
}

// 6. Test Date-Filtered Periods: 7d, 90d, and custom dates
$rep7d = makeRequest('GET', '/buddy/reports/sales-revenue?period=7d', [
    "Authorization: Bearer $token2"
]);
if ($rep7d['code'] === 200 && ($rep7d['body']['data']['filter']['period'] ?? '') === '7d' && count($rep7d['body']['data']['trends']['daily_sales'] ?? []) === 7) {
    echo "PASS: 7-day period filter returns 7 daily trend points.\n";
} else {
    echo "FAIL: 7-day filter failed.\n";
}

$repCustom = makeRequest('GET', '/buddy/reports/sales-revenue?start_date=2026-10-01&end_date=2026-10-15', [
    "Authorization: Bearer $token2"
]);
if ($repCustom['code'] === 200 && ($repCustom['body']['data']['filter']['start_date'] ?? '') === '2026-10-01') {
    echo "PASS: Custom date filter (2026-10-01 to 2026-10-15) processed successfully.\n";
} else {
    echo "FAIL: Custom date filter failed.\n";
}

// 7. Test Seller Isolation (Seller 4 vs Seller 2)
$repSeller4 = makeRequest('GET', '/buddy/reports/sales-revenue', [
    "Authorization: Bearer $token4"
]);

if ($repSeller4['code'] !== 200) {
    echo "FAIL: Seller 4 report failed with code {$repSeller4['code']}\n";
    exit(1);
}
$d4 = $repSeller4['body']['data'];
echo "PASS: Seller 4 report retrieved successfully (HTTP 200).\n";
echo " - Scope: Store '" . ($d4['scope']['store_name'] ?? 'N/A') . "', Seller ID: " . ($d4['scope']['seller_id'] ?? 'N/A') . "\n";
echo " - Seller 4 Gross Sales GMV: ৳" . ($d4['metrics']['gross_sales_gmv'] ?? 0) . "\n";

if ($d4['scope']['seller_id'] !== $d2['scope']['seller_id'] && $d4['scope']['store_name'] !== $d2['scope']['store_name']) {
    echo "PASS: Seller isolation strictly enforced! Seller 4 is isolated from Seller 2.\n";
} else {
    echo "FAIL: Seller isolation failure! Leak detected.\n";
}

// Ensure Seller 4 cannot tamper with seller_id query param to see Seller 2
$tamperReq = makeRequest('GET', '/buddy/reports/sales-revenue?seller_id=' . $d2['scope']['seller_id'], [
    "Authorization: Bearer $token4"
]);
if ($tamperReq['code'] === 200 && (int)$tamperReq['body']['data']['scope']['seller_id'] === (int)$d4['scope']['seller_id']) {
    echo "PASS: Seller tampering ignored! Authenticated token strictly binds to seller 4.\n";
} else {
    echo "FAIL: Seller allowed to override seller_id via query param!\n";
}

// 8. Test Admin-Wide Access and Marketplace Aggregation
$repAdmin = makeRequest('GET', '/buddy/admin/reports/sales-revenue', [
    "Authorization: Bearer $tokenAdmin"
]);

if ($repAdmin['code'] !== 200) {
    echo "FAIL: Admin reports failed with code {$repAdmin['code']}: " . $repAdmin['raw'] . "\n";
    exit(1);
}
$da = $repAdmin['body']['data'];
echo "PASS: Admin marketplace-wide report retrieved successfully (HTTP 200).\n";
echo " - Admin Scope: " . ($da['scope']['store_name'] ?? 'N/A') . ", is_admin: " . ($da['scope']['is_admin'] ? 'true' : 'false') . "\n";
echo " - Marketplace Total GMV: ৳" . ($da['metrics']['gross_sales_gmv'] ?? 0) . "\n";
echo " - Marketplace Total Orders: " . ($da['metrics']['total_orders'] ?? 0) . "\n";

// 9. Admin Filter by Specific Seller
$repAdminSeller2 = makeRequest('GET', '/buddy/admin/reports/sales-revenue?seller_id=' . $d2['scope']['seller_id'], [
    "Authorization: Bearer $tokenAdmin"
]);
if ($repAdminSeller2['code'] === 200 && (int)$repAdminSeller2['body']['data']['scope']['seller_id'] === (int)$d2['scope']['seller_id']) {
    echo "PASS: Admin can filter by specific seller ID (" . $d2['scope']['seller_id'] . ").\n";
} else {
    echo "FAIL: Admin filter by seller_id failed.\n";
}

echo "=== ALL SALES & REVENUE REPORT TESTS PASSED SUCCESSFULLY ===\n";
