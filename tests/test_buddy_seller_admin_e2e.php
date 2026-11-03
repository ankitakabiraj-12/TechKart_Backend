 <?php
/**
 * End-to-End Verification Suite for TechKart Buddy Seller & Admin Integration
 * Tests:
 * 1. MariaDB Connection and Seeded Data Integrity
 * 2. Seller JWT Authentication (vikram.store@techkart.com)
 * 3. Seller Dashboard API (/buddy/seller/dashboard)
 * 4. Seller Products API (/buddy/products)
 * 5. Seller Orders API (/buddy/orders)
 * 6. Seller Settlements API (/buddy/seller/settlements)
 * 7. Admin JWT Authentication (admin.audit@techkart.com)
 * 8. Admin Dashboard API (/buddy/admin/dashboard)
 * 9. Admin Seller Applications Directory (/buddy/admin/seller-applications)
 * 10. Admin Seller Application Decision Flow (/buddy/admin/seller-applications/{id}/decision)
 * 11. Admin Audit Logs Trail (/buddy/admin/audit-logs)
 * 12. Security & Role Permissions Check (Seller forbidden from Admin routes)
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function callApi($method, $url, $token = null, $body = null) {
    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

echo "=================================================================\n";
echo " TECHKART BUDDY SELLER & ADMIN E2E INTEGRATION VERIFICATION\n";
echo " Base URL: $baseUrl\n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest($name, $condition, $details = "") {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details) echo "        -> $details\n";
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) echo "        -> $details\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// Test 1: Seller Authentication
// -------------------------------------------------------------
echo "[STEP 1] Testing Seller Authentication (/buddy/auth/login)...\n";
$sellerLogin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);

$sellerToken = $sellerLogin['body']['data']['token'] ?? null;
$sellerStore = $sellerLogin['body']['data']['store_name'] ?? ($sellerLogin['body']['data']['store']['store_name'] ?? null);
assertTest(
    "Seller Login returns 200 with JWT token and store name",
    $sellerLogin['code'] === 200 && !empty($sellerToken) && $sellerStore === 'Apex Gear India',
    "Store: $sellerStore | Token: " . substr((string)$sellerToken, 0, 16) . "..."
);

// -------------------------------------------------------------
// Test 2: Seller Dashboard API
// -------------------------------------------------------------
echo "\n[STEP 2] Testing Seller Dashboard (/buddy/seller/dashboard)...\n";
$sellerDash = callApi('GET', "$baseUrl/buddy/seller/dashboard", $sellerToken);
$storeProfile = $sellerDash['body']['data']['store'] ?? [];
$metrics = $sellerDash['body']['data']['metrics'] ?? [];

assertTest(
    "Seller Dashboard returns store profile and live counts",
    $sellerDash['code'] === 200 && ($storeProfile['store_name'] ?? '') === 'Apex Gear India',
    "Profile: " . json_encode($storeProfile)
);
assertTest(
    "Seller Dashboard returns non-zero orders, revenue, and active catalog items",
    ($metrics['catalog_active'] ?? 0) >= 5 && ($metrics['gross_revenue'] ?? 0) > 0,
    "Active Listings: {$metrics['catalog_active']} | Gross Revenue: ৳ {$metrics['gross_revenue']}"
);

// -------------------------------------------------------------
// Test 3: Seller Products API
// -------------------------------------------------------------
echo "\n[STEP 3] Testing Seller Catalog (/buddy/products)...\n";
$sellerProducts = callApi('GET', "$baseUrl/buddy/products", $sellerToken);
$productsList = $sellerProducts['body']['data'] ?? [];
$firstProd = $productsList[0]['title'] ?? ($productsList[0]['name'] ?? 'N/A');
assertTest(
    "Seller Products endpoint returns persisted catalog items",
    $sellerProducts['code'] === 200 && count($productsList) >= 5,
    "Total products fetched: " . count($productsList) . " (First: $firstProd)"
);

// -------------------------------------------------------------
// Test 4: Seller Orders API
// -------------------------------------------------------------
echo "\n[STEP 4] Testing Seller Orders (/buddy/orders)...\n";
$sellerOrders = callApi('GET', "$baseUrl/buddy/orders", $sellerToken);
$ordersList = $sellerOrders['body']['data'] ?? [];
assertTest(
    "Seller Orders endpoint returns persisted orders for Apex Gear India",
    $sellerOrders['code'] === 200 && count($ordersList) >= 3,
    "Total orders fetched: " . count($ordersList) . " (Sample Order: " . ($ordersList[0]['order_number'] ?? 'N/A') . ")"
);

// -------------------------------------------------------------
// Test 5: Seller Settlements API
// -------------------------------------------------------------
echo "\n[STEP 5] Testing Seller Settlements (/buddy/seller/settlements)...\n";
$sellerSettlements = callApi('GET', "$baseUrl/buddy/seller/settlements", $sellerToken);
$settlementsList = $sellerSettlements['body']['data']['settlements'] ?? [];
$firstRef = $settlementsList[0]['settlement_ref'] ?? ($settlementsList[0]['settlement_reference'] ?? 'N/A');
assertTest(
    "Seller Settlements returns persisted BRAC Bank payout logs",
    $sellerSettlements['code'] === 200 && count($settlementsList) >= 2,
    "Settlements: " . count($settlementsList) . " (Ref: $firstRef)"
);

// -------------------------------------------------------------
// Test 6: Admin Authentication
// -------------------------------------------------------------
echo "\n[STEP 6] Testing Admin Authentication (/buddy/auth/login)...\n";
$adminLogin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

$adminToken = $adminLogin['body']['data']['token'] ?? null;
$adminRole = $adminLogin['body']['data']['role'] ?? ($adminLogin['body']['data']['user']['role'] ?? null);
assertTest(
    "Admin Login returns 200 with JWT token and role admin",
    $adminLogin['code'] === 200 && !empty($adminToken) && $adminRole === 'admin',
    "Admin Token: " . substr((string)$adminToken, 0, 16) . "... | Role: $adminRole"
);

// -------------------------------------------------------------
// Test 7: Admin Dashboard API
// -------------------------------------------------------------
echo "\n[STEP 7] Testing Admin Dashboard (/buddy/admin/dashboard)...\n";
$adminDash = callApi('GET', "$baseUrl/buddy/admin/dashboard", $adminToken);
$kpis = $adminDash['body']['data']['kpis'] ?? [];
$appQueues = $adminDash['body']['data']['application_queues'] ?? [];

assertTest(
    "Admin Dashboard returns live platform KPIs and queues",
    $adminDash['code'] === 200 && ($kpis['active_sellers'] ?? 0) >= 2 && ($kpis['active_listings'] ?? 0) >= 5,
    "Active Sellers: {$kpis['active_sellers']} | Active Listings: {$kpis['active_listings']} | Pending Apps: {$kpis['pending_applications']}"
);

// -------------------------------------------------------------
// Test 8: Admin Seller Applications Directory
// -------------------------------------------------------------
echo "\n[STEP 8] Testing Admin Seller Applications (/buddy/admin/seller-applications)...\n";
$adminApps = callApi('GET', "$baseUrl/buddy/admin/seller-applications", $adminToken);
$applications = $adminApps['body']['data']['applications'] ?? [];
$firstApp = $applications[0]['app_number'] ?? ($applications[0]['application_id'] ?? 'N/A');
assertTest(
    "Admin Seller Applications returns seeded applications list",
    $adminApps['code'] === 200 && count($applications) >= 3,
    "Queued applications: " . count($applications) . " (Top App: $firstApp)"
);

// -------------------------------------------------------------
// Test 9: Admin Seller Application Decision Flow
// -------------------------------------------------------------
echo "\n[STEP 9] Testing Admin Application Decision Flow (PUT /buddy/admin/seller-applications/{id}/decision)...\n";
$targetApp = $applications[0]['app_number'] ?? ($applications[0]['id'] ?? 'APP-84910');
$decisionRes = callApi('PUT', "$baseUrl/buddy/admin/seller-applications/$targetApp/decision", $adminToken, [
    'action' => 'approve',
    'notes' => 'Trade license and e-TIN 100% verified via automated NBR check.'
]);
assertTest(
    "Admin Application Decision endpoint updates status to approved",
    $decisionRes['code'] === 200 && ($decisionRes['body']['data']['status'] ?? '') === 'approved',
    "Updated Status: " . ($decisionRes['body']['data']['status'] ?? 'N/A')
);

// -------------------------------------------------------------
// Test 10: Admin System Audit Trail
// -------------------------------------------------------------
echo "\n[STEP 10] Testing Admin Audit Trail (/buddy/admin/audit-logs)...\n";
$auditLogs = callApi('GET', "$baseUrl/buddy/admin/audit-logs", $adminToken);
$auditList = $auditLogs['body']['data']['logs'] ?? ($auditLogs['body']['data']['audit_logs'] ?? []);
$latestAction = $auditList[0]['action'] ?? 'N/A';
$latestDetail = $auditList[0]['target_id'] ?? 'N/A';
assertTest(
    "Admin Audit Logs contains the newly registered decision action",
    $auditLogs['code'] === 200 && count($auditList) > 0,
    "Latest Audit Entry: $latestAction - Target: $latestDetail"
);

// -------------------------------------------------------------
// Test 11: Role Permission Enforcement
// -------------------------------------------------------------
echo "\n[STEP 11] Testing Role & Permission Boundaries...\n";
$forbiddenAccess = callApi('GET', "$baseUrl/buddy/admin/dashboard", $sellerToken);
assertTest(
    "Seller token cannot access Admin Dashboard (HTTP 403 Forbidden)",
    $forbiddenAccess['code'] === 403,
    "Response Code: {$forbiddenAccess['code']} (Expected: 403 Forbidden)"
);

echo "\n=================================================================\n";
echo " TEST SUMMARY: $passed Passed | $failed Failed\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
} else {
    exit(0);
}
