<?php
/**
 * Test Admin Customer List API with order/review summaries, authorization, MariaDB pagination
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

echo "=== TECHKART BUDDY ADMIN CUSTOMER API TEST ===\n\n";

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

// 2. Authorization / RBAC check: regular seller rejected
echo "[2] Seller Login (RBAC check on customer directory)...\n";
$sellerLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$sellerToken = $sellerLogin['body']['token'] ?? $sellerLogin['body']['data']['token'] ?? null;
if ($sellerToken) {
    $forbiddenRes = makeRequest('GET', "$baseUrl/buddy/admin/customers", ["Authorization: Bearer $sellerToken"]);
    if ($forbiddenRes['code'] === 403) {
        echo "  SUCCESS: Seller correctly rejected with 403 Forbidden.\n\n";
    } else {
        echo "  FAILED: Expected 403 Forbidden for seller on customer API, got {$forbiddenRes['code']}.\n\n";
        exit(1);
    }
}

// 3. Unauthenticated request rejected with 401
echo "[3] Unauthenticated request check...\n";
$unauthRes = makeRequest('GET', "$baseUrl/buddy/admin/customers");
if ($unauthRes['code'] === 401) {
    echo "  SUCCESS: Unauthenticated request rejected with 401 Unauthorized.\n\n";
} else {
    echo "  FAILED: Expected 401 Unauthorized, got {$unauthRes['code']}.\n\n";
    exit(1);
}

// 4. Fetch Paginated Customers (Page 1, limit 10)
echo "[4] Fetch Full Customer Directory (Page 1)...\n";
$custRes = makeRequest('GET', "$baseUrl/buddy/admin/customers?page=1&limit=10", $adminHeaders);
if ($custRes['code'] !== 200 || !($custRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not fetch customer directory. Code: {$custRes['code']}, Body: {$custRes['raw']}\n";
    exit(1);
}
$data = $custRes['body']['data'];
$metrics = $data['metrics'];
$customers = $data['customers'];
$pagination = $data['pagination'];

echo "  SUCCESS: Customer directory fetched.\n";
echo "  - Total Customers: " . ($metrics['total_customers'] ?? 'N/A') . "\n";
echo "  - Active Customers: " . ($metrics['active_customers'] ?? 'N/A') . "\n";
echo "  - Total Orders: " . ($metrics['total_orders'] ?? 'N/A') . "\n";
echo "  - Total Lifetime Volume: " . ($metrics['total_spent_fmt'] ?? 'N/A') . "\n";
echo "  - Customers Returned: " . count($customers) . " (Total in DB: {$pagination['total']})\n";

// Validate order and review summaries exist on customer items
$firstCust = $customers[0];
echo "  - Sample customer record: {$firstCust['full_name']} (ID: {$firstCust['user_id']})\n";
echo "    Orders: {$firstCust['total_orders']}, Total Spent: {$firstCust['total_spent_fmt']}, Reviews: {$firstCust['total_reviews']}, Avg Rating: {$firstCust['avg_review_rating']}\n\n";

if (!isset($firstCust['total_orders']) || !isset($firstCust['total_reviews']) || !isset($firstCust['avg_review_rating'])) {
    echo "  FAILED: Customer record missing order or review summary metrics.\n";
    exit(1);
}

// 5. Test Filter Tabs
echo "[5] Testing Filter Tabs...\n";
// 5a. Filter Active
$activeRes = makeRequest('GET', "$baseUrl/buddy/admin/customers?status=active", $adminHeaders);
$activeList = $activeRes['body']['data']['customers'] ?? [];
echo "  - Filter 'active': " . count($activeList) . " customers found.\n";
foreach ($activeList as $c) {
    if ((int)$c['is_active'] !== 1) {
        echo "    FAILED: Customer {$c['full_name']} has is_active={$c['is_active']}, expected 1\n";
        exit(1);
    }
}

// 6. Test Search Queries
echo "[6] Testing Search Queries...\n";
// 6a. Search for "Nusrat"
$searchNusrat = makeRequest('GET', "$baseUrl/buddy/admin/customers?search=Nusrat", $adminHeaders);
$nusratList = $searchNusrat['body']['data']['customers'] ?? [];
echo "  - Search 'Nusrat': " . count($nusratList) . " match(es) found.\n";
if (empty($nusratList) || stripos($nusratList[0]['full_name'], 'Nusrat') === false) {
    echo "    FAILED: Search for 'Nusrat' did not return expected customer.\n";
    exit(1);
}

// 6b. Search by Indian City ("Mumbai")
$searchCity = makeRequest('GET', "$baseUrl/buddy/admin/customers?search=Mumbai", $adminHeaders);
$mumbaiList = $searchCity['body']['data']['customers'] ?? [];
echo "  - Search 'Mumbai': " . count($mumbaiList) . " match(es) found.\n";
if (empty($mumbaiList)) {
    echo "    FAILED: Search for 'Mumbai' returned 0 results.\n";
    exit(1);
}
echo "  SUCCESS: Search queries validated.\n\n";

// 7. Test Customer Details API
echo "[7] Testing Customer Details API...\n";
$targetId = $nusratList[0]['user_id'];
$detailRes = makeRequest('GET', "$baseUrl/buddy/admin/customers/$targetId", $adminHeaders);
if ($detailRes['code'] !== 200 || !($detailRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not retrieve customer details for ID $targetId. Response: {$detailRes['raw']}\n";
    exit(1);
}
$detailData = $detailRes['body']['data'];
echo "  SUCCESS: Details retrieved for customer #$targetId: {$detailData['customer']['full_name']}\n";
echo "  - Order history items: " . count($detailData['orders']) . "\n";
echo "  - Review items: " . count($detailData['reviews']) . "\n";
echo "  - Address items: " . count($detailData['addresses']) . "\n\n";

// 8. Test Customer Status Update (Suspend & Reactivate)
echo "[8] Testing Customer Status Update (Suspend & Reactivate)...\n";
$testCustId = 16; // Ananya Roy
// Suspend
$suspendRes = makeRequest('PUT', "$baseUrl/buddy/admin/customers/$testCustId/status", $adminHeaders, [
    'is_active' => 0
]);
if ($suspendRes['code'] !== 200 || !($suspendRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not suspend customer $testCustId.\n";
    exit(1);
}
echo "  - Suspended customer $testCustId: " . $suspendRes['body']['message'] . "\n";

// Check in DB via filter inactive
$inactiveCheck = makeRequest('GET', "$baseUrl/buddy/admin/customers?status=inactive", $adminHeaders);
$inactiveList = $inactiveCheck['body']['data']['customers'] ?? [];
$foundInactive = false;
foreach ($inactiveList as $c) {
    if ((int)$c['user_id'] === $testCustId) {
        $foundInactive = true;
        break;
    }
}
if (!$foundInactive) {
    echo "  FAILED: Suspended customer $testCustId not found in inactive filter.\n";
    exit(1);
}
echo "  - Verified customer $testCustId is reflected as inactive.\n";

// Reactivate
$activateRes = makeRequest('PUT', "$baseUrl/buddy/admin/customers/$testCustId/status", $adminHeaders, [
    'is_active' => 1
]);
if ($activateRes['code'] !== 200 || !($activateRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not reactivate customer $testCustId.\n";
    exit(1);
}
echo "  - Reactivated customer $testCustId: " . $activateRes['body']['message'] . "\n";
echo "  SUCCESS: Customer status toggle validated in database.\n\n";

echo "=== ALL ADMIN CUSTOMER API TESTS PASSED ===\n";
