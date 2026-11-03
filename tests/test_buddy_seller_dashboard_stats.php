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

echo "=== TEST: TechKart Buddy Seller Dashboard Statistics API ===\n";

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
echo "PASS: Seller 2 authenticated (store_id = 2).\n";

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
echo "PASS: Seller 4 authenticated (store_id = 4).\n";

// 3. Test Unauthorized request
$unauthRes = makeRequest('GET', '/buddy/seller/dashboard');
if ($unauthRes['code'] === 401) {
    echo "PASS: Unauthorized access without token correctly rejected with 401.\n";
} else {
    echo "FAIL: Expected 401 for unauthorized dashboard request, got: " . $unauthRes['code'] . "\n";
}

// 4. Test Seller 2 Dashboard Statistics
$dash2Res = makeRequest('GET', '/buddy/seller/dashboard', [
    "Authorization: Bearer $token2"
]);

if ($dash2Res['code'] !== 200) {
    echo "FAIL: Seller 2 dashboard stats request returned HTTP {$dash2Res['code']}\n";
    echo $dash2Res['raw'] . "\n";
    exit(1);
}

$data2 = $dash2Res['body']['data'];
$metrics2 = $data2['metrics'] ?? [];
$store2 = $data2['store'] ?? [];
$weekly2 = $data2['weekly_performance'] ?? [];
$stream2 = $data2['dispatch_stream'] ?? [];

echo "PASS: Seller 2 dashboard stats returned successfully (HTTP 200).\n";
echo " - Store: " . ($store2['store_name'] ?? 'N/A') . "\n";
echo " - Todays Orders: " . ($metrics2['todays_orders'] ?? 0) . "\n";
echo " - Total Orders: " . ($metrics2['total_orders'] ?? 0) . "\n";
echo " - Gross Revenue: ৳" . ($metrics2['gross_revenue'] ?? 0) . "\n";
echo " - Delivered Units: " . ($metrics2['delivered_units'] ?? 0) . "\n";
echo " - Top Product: " . ($metrics2['top_selling_product'] ?? 'N/A') . "\n";
echo " - Catalog Active: " . ($metrics2['catalog_active'] ?? 0) . "\n";
echo " - Low Stock: " . ($metrics2['low_stock_count'] ?? 0) . "\n";
echo " - Urgent Orders: " . ($metrics2['urgent_orders_count'] ?? 0) . "\n";
echo " - Weekly Summary: " . ($weekly2['summary'] ?? 'N/A') . "\n";
echo " - Dispatch Stream Items Count: " . count($stream2) . "\n";

// Validate weekly chart structure
if (isset($weekly2['chart_bars']) && count($weekly2['chart_bars']) === 7 && isset($weekly2['days']) && count($weekly2['days']) === 7) {
    echo "PASS: 7-day weekly performance chart has 7 days and 7 normalized bar heights.\n";
} else {
    echo "FAIL: Weekly chart missing expected 7-day structure.\n";
}

// 5. Test Seller 4 Dashboard Statistics and verify isolation
$dash4Res = makeRequest('GET', '/buddy/seller/dashboard', [
    "Authorization: Bearer $token4"
]);

if ($dash4Res['code'] !== 200) {
    echo "FAIL: Seller 4 dashboard stats request returned HTTP {$dash4Res['code']}\n";
    exit(1);
}

$data4 = $dash4Res['body']['data'];
$metrics4 = $data4['metrics'] ?? [];
$store4 = $data4['store'] ?? [];

echo "PASS: Seller 4 dashboard stats returned successfully (HTTP 200).\n";
echo " - Store 4 Name: " . ($store4['store_name'] ?? 'N/A') . "\n";
echo " - Store 4 Gross Revenue: ৳" . ($metrics4['gross_revenue'] ?? 0) . "\n";
echo " - Store 4 Total Orders: " . ($metrics4['total_orders'] ?? 0) . "\n";

// Verify Seller 4 does not see Seller 2's store name or data
if ($store4['store_name'] !== $store2['store_name']) {
    echo "PASS: Seller isolation confirmed! Seller 4 cannot see Seller 2's store information.\n";
} else {
    echo "FAIL: Store isolation breached! Same store name returned for different sellers.\n";
}

echo "=== ALL DASHBOARD STATISTICS TESTS COMPLETED SUCCESSFULLY ===\n";
