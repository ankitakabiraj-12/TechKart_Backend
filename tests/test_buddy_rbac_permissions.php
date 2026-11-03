<?php
/**
 * Comprehensive Role-Based Access Control (RBAC) & Cross-Store Data Isolation Test
 * for TechKart Buddy APIs.
 *
 * Scope tested:
 *  1. Unauthenticated requests to all protected routes -> 401 Unauthorized
 *  2. Deactivated accounts (is_active = 0) -> 403 Forbidden
 *  3. Role restriction: Seller attempting to access Admin-only endpoints -> 403 Forbidden
 *  4. Role restriction: Customer attempting to access Buddy endpoints -> 403 Forbidden
 *  5. Cross-store product isolation:
 *     - Seller A attempting to read Seller B's product -> 403 Forbidden
 *     - Seller A attempting to update Seller B's product -> 403 Forbidden
 *     - Seller A attempting to delete Seller B's product -> 403 Forbidden
 *  6. Cross-store order fulfillment isolation:
 *     - Seller A attempting to view an order containing only Seller B's items -> 403 Forbidden
 *     - Seller A attempting to update status of an order containing only Seller B's items -> 403 Forbidden
 *  7. Cross-store returns isolation:
 *     - Seller A attempting to view Seller B's return request -> 403 Forbidden
 *     - Seller A attempting to decide on Seller B's return request -> 403 Forbidden
 *  8. Admin authorization override:
 *     - Admin can access any seller's product -> 200 OK
 *     - Admin can access any order -> 200 OK
 *     - Admin can access any return request -> 200 OK
 *     - Admin can access all admin management endpoints -> 200 OK
 *  9. Authorized seller store operations:
 *     - Seller A can access and manage their own products, orders, returns, and dashboard -> 200 OK
 */

require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function callApi($method, $url, $token = null, $body = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

$passed = 0;
$failed = 0;

function assertCheck($name, $cond, $details = "") {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] $name\n";
        if ($details) echo "        -> $details\n";
        $passed++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) echo "        -> $details\n";
        $failed++;
    }
}

echo "=================================================================\n";
echo " TECHKART BUDDY RBAC & CROSS-STORE DATA ISOLATION VERIFICATION\n";
echo " Base URL: $baseUrl\n";
echo "=================================================================\n\n";

// --- 1. LOGIN ACTORS ---
echo "[SETUP] Authenticating test actors...\n";

// Seller A (ID 2 - Vikram Store)
$loginSellerA = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$tokenSellerA = $loginSellerA['body']['data']['token'] ?? null;
assertCheck("Seller A (ID 2) authenticated", $loginSellerA['code'] === 200 && !empty($tokenSellerA));

// Seller B (ID 4 - GadgetPlanet BD)
$loginSellerB = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123'
]);
$tokenSellerB = $loginSellerB['body']['data']['token'] ?? null;
assertCheck("Seller B (ID 4) authenticated", $loginSellerB['code'] === 200 && !empty($tokenSellerB));

// Admin (ID 3 - Admin Audit)
$loginAdmin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
$tokenAdmin = $loginAdmin['body']['data']['token'] ?? null;
assertCheck("Admin (ID 3) authenticated", $loginAdmin['code'] === 200 && !empty($tokenAdmin));

// Customer (ID 1 - Rahul Sharma)
$customerJwt = \App\Core\Jwt::generate(['sub' => 1, 'email' => 'rahul.test@techkart.com', 'role' => 'customer']);
assertCheck("Customer JWT generated", !empty($customerJwt));

// Fetch specific resource IDs for cross-store tests
$sellerA_product = $pdo->query('SELECT id FROM products WHERE seller_id = 2 LIMIT 1')->fetchColumn();
$sellerB_product = $pdo->query('SELECT id FROM products WHERE seller_id = 4 LIMIT 1')->fetchColumn();
$sellerA_order   = $pdo->query('SELECT DISTINCT order_id FROM order_items WHERE seller_id = 2 LIMIT 1')->fetchColumn();
$sellerB_order   = $pdo->query('SELECT DISTINCT order_id FROM order_items WHERE seller_id = 4 LIMIT 1')->fetchColumn();
$sellerA_return  = $pdo->query('SELECT id FROM return_requests WHERE seller_id = 2 LIMIT 1')->fetchColumn();
$sellerB_return  = $pdo->query('SELECT id FROM return_requests WHERE seller_id = 4 LIMIT 1')->fetchColumn();

echo "\nTest Targets:\n";
echo "  Seller A (ID 2): Product #$sellerA_product, Order #$sellerA_order, Return #$sellerA_return\n";
echo "  Seller B (ID 4): Product #$sellerB_product, Order #$sellerB_order, Return #$sellerB_return\n\n";

// --- 2. UNAUTHENTICATED REQUESTS (401) ---
echo "[TEST SUITE 1] Unauthenticated Requests Guard (401 Unauthorized)...\n";
$unauthRoutes = [
    ['GET', "$baseUrl/buddy/auth/profile"],
    ['GET', "$baseUrl/buddy/auth/validate-session"],
    ['POST', "$baseUrl/buddy/auth/logout"],
    ['GET', "$baseUrl/buddy/products"],
    ['GET', "$baseUrl/buddy/products/$sellerA_product"],
    ['GET', "$baseUrl/buddy/orders"],
    ['GET', "$baseUrl/buddy/orders/$sellerA_order"],
    ['GET', "$baseUrl/buddy/returns"],
    ['GET', "$baseUrl/buddy/returns/$sellerA_return"],
    ['GET', "$baseUrl/buddy/seller/dashboard"],
    ['GET', "$baseUrl/buddy/seller/settlements"],
    ['GET', "$baseUrl/buddy/admin/dashboard"],
    ['GET', "$baseUrl/buddy/admin/seller-applications"],
    ['GET', "$baseUrl/buddy/admin/audit-logs"],
];

foreach ($unauthRoutes as $route) {
    [$method, $url] = $route;
    $res = callApi($method, $url);
    $path = parse_url($url, PHP_URL_PATH);
    assertCheck("Unauthenticated $method $path -> 401", $res['code'] === 401, "Returned code: {$res['code']}");
}

// --- 3. ACCOUNT STATUS CHECK (403 when is_active = 0) ---
echo "\n[TEST SUITE 2] Account Status Checks (Deactivated Account Enforcement)...\n";
// Temporarily deactivate Seller B in DB
$pdo->exec('UPDATE users SET is_active = 0 WHERE id = 4');
$deactivatedRes = callApi('GET', "$baseUrl/buddy/auth/validate-session", $tokenSellerB);
assertCheck(
    "Deactivated account (is_active = 0) rejected with 403 Forbidden",
    $deactivatedRes['code'] === 403,
    "Message: " . ($deactivatedRes['body']['message'] ?? '')
);
// Restore Seller B
$pdo->exec('UPDATE users SET is_active = 1 WHERE id = 4');
$reactivatedRes = callApi('GET', "$baseUrl/buddy/auth/validate-session", $tokenSellerB);
assertCheck("Restored account (is_active = 1) accepted with 200 OK", $reactivatedRes['code'] === 200);

// --- 4. ROLE RESTRICTION: SELLER ACCESSING ADMIN-ONLY ENDPOINTS (403) ---
echo "\n[TEST SUITE 3] Role Enforcement: Seller forbidden from Admin-Only Endpoints (403 Forbidden)...\n";
$adminOnlyRoutes = [
    ['GET', "$baseUrl/buddy/admin/dashboard"],
    ['GET', "$baseUrl/buddy/admin/seller-applications"],
    ['PUT', "$baseUrl/buddy/admin/seller-applications/1/decision", ['decision' => 'approved']],
    ['GET', "$baseUrl/buddy/admin/audit-logs"],
];

foreach ($adminOnlyRoutes as $route) {
    [$method, $url] = $route;
    $body = $route[2] ?? null;
    $res = callApi($method, $url, $tokenSellerA, $body);
    $path = parse_url($url, PHP_URL_PATH);
    assertCheck("Seller blocked from Admin $method $path -> 403", $res['code'] === 403, "Returned code: {$res['code']}");
}

// Customer role blocked from Buddy endpoints
$custRes = callApi('GET', "$baseUrl/buddy/products", $customerJwt);
assertCheck("Customer role blocked from Buddy endpoints -> 403 Forbidden", $custRes['code'] === 403);

// --- 5. CROSS-STORE PRODUCT ISOLATION (403) ---
echo "\n[TEST SUITE 4] Cross-Store Product Isolation (Seller A accessing Seller B's products)...\n";

// Seller A reads Seller B's product
$viewCrossProduct = callApi('GET', "$baseUrl/buddy/products/$sellerB_product", $tokenSellerA);
assertCheck(
    "Seller A reading Seller B's product #$sellerB_product -> 403 Forbidden",
    $viewCrossProduct['code'] === 403,
    "Response: " . ($viewCrossProduct['body']['message'] ?? '')
);

// Seller A updates Seller B's product
$updateCrossProduct = callApi('PUT', "$baseUrl/buddy/products/$sellerB_product", $tokenSellerA, [
    'title' => 'Hacked Product Title',
    'price' => 100.00
]);
assertCheck(
    "Seller A modifying Seller B's product #$sellerB_product -> 403 Forbidden",
    $updateCrossProduct['code'] === 403,
    "Response: " . ($updateCrossProduct['body']['message'] ?? '')
);

// Seller A deletes Seller B's product
$deleteCrossProduct = callApi('DELETE', "$baseUrl/buddy/products/$sellerB_product", $tokenSellerA);
assertCheck(
    "Seller A deleting Seller B's product #$sellerB_product -> 403 Forbidden",
    $deleteCrossProduct['code'] === 403,
    "Response: " . ($deleteCrossProduct['body']['message'] ?? '')
);

// --- 6. CROSS-STORE ORDER ISOLATION (403) ---
echo "\n[TEST SUITE 5] Cross-Store Order Isolation (Seller A accessing Seller B's orders)...\n";

// Seller A views Seller B's order
$viewCrossOrder = callApi('GET', "$baseUrl/buddy/orders/$sellerB_order", $tokenSellerA);
assertCheck(
    "Seller A viewing Seller B's order #$sellerB_order -> 403 Forbidden",
    $viewCrossOrder['code'] === 403,
    "Response: " . ($viewCrossOrder['body']['message'] ?? '')
);

// Seller A updates status of Seller B's order
$updateCrossOrder = callApi('PUT', "$baseUrl/buddy/orders/$sellerB_order/status", $tokenSellerA, [
    'status' => 'processing'
]);
assertCheck(
    "Seller A modifying status of Seller B's order #$sellerB_order -> 403 Forbidden",
    $updateCrossOrder['code'] === 403,
    "Response: " . ($updateCrossOrder['body']['message'] ?? '')
);

// --- 7. CROSS-STORE RETURN REQUEST ISOLATION (403) ---
echo "\n[TEST SUITE 6] Cross-Store Return Request Isolation (Seller A accessing Seller B's returns)...\n";

// Seller A views Seller B's return request
$viewCrossReturn = callApi('GET', "$baseUrl/buddy/returns/$sellerB_return", $tokenSellerA);
assertCheck(
    "Seller A viewing Seller B's return #$sellerB_return -> 403 Forbidden",
    $viewCrossReturn['code'] === 403,
    "Response: " . ($viewCrossReturn['body']['message'] ?? '')
);

// Seller A makes decision on Seller B's return request
$decideCrossReturn = callApi('PUT', "$baseUrl/buddy/returns/$sellerB_return/decision", $tokenSellerA, [
    'status' => 'rejected',
    'decision_reason' => 'Unauthorized rejection attempt'
]);
assertCheck(
    "Seller A deciding on Seller B's return #$sellerB_return -> 403 Forbidden",
    $decideCrossReturn['code'] === 403,
    "Response: " . ($decideCrossReturn['body']['message'] ?? '')
);

// --- 8. ADMIN AUTHORIZED OVERRIDE (200 OK) ---
echo "\n[TEST SUITE 7] Admin Role Authorized Override across All Resources (200 OK)...\n";

// Admin can access Seller B's product
$adminViewProd = callApi('GET', "$baseUrl/buddy/products/$sellerB_product", $tokenAdmin);
assertCheck("Admin can view Seller B's product -> 200 OK", $adminViewProd['code'] === 200);

// Admin can access Seller B's order
$adminViewOrd = callApi('GET', "$baseUrl/buddy/orders/$sellerB_order", $tokenAdmin);
assertCheck("Admin can view any seller's order -> 200 OK", $adminViewOrd['code'] === 200);

// Admin can access Seller B's return
$adminViewRet = callApi('GET', "$baseUrl/buddy/returns/$sellerB_return", $tokenAdmin);
assertCheck("Admin can view any seller's return request -> 200 OK", $adminViewRet['code'] === 200);

// Admin can access Admin Dashboard, Applications, and Audit Logs
$adminDash = callApi('GET', "$baseUrl/buddy/admin/dashboard", $tokenAdmin);
assertCheck("Admin dashboard -> 200 OK", $adminDash['code'] === 200);

$adminApps = callApi('GET', "$baseUrl/buddy/admin/seller-applications", $tokenAdmin);
assertCheck("Admin seller applications list -> 200 OK", $adminApps['code'] === 200);

$adminAudits = callApi('GET', "$baseUrl/buddy/admin/audit-logs", $tokenAdmin);
assertCheck("Admin audit logs feed -> 200 OK", $adminAudits['code'] === 200);

// --- 9. AUTHORIZED SELLER OWN STORE OPERATIONS (200 OK) ---
echo "\n[TEST SUITE 8] Authorized Seller Access to Own Store Resources (200 OK)...\n";

$ownProd = callApi('GET', "$baseUrl/buddy/products/$sellerA_product", $tokenSellerA);
assertCheck("Seller A accessing own product -> 200 OK", $ownProd['code'] === 200);

$ownOrd = callApi('GET', "$baseUrl/buddy/orders/$sellerA_order", $tokenSellerA);
assertCheck("Seller A accessing own order -> 200 OK", $ownOrd['code'] === 200);

$ownRet = callApi('GET', "$baseUrl/buddy/returns/$sellerA_return", $tokenSellerA);
assertCheck("Seller A accessing own return -> 200 OK", $ownRet['code'] === 200);

$ownDash = callApi('GET', "$baseUrl/buddy/seller/dashboard", $tokenSellerA);
assertCheck("Seller A accessing own seller dashboard -> 200 OK", $ownDash['code'] === 200);

$ownSettlements = callApi('GET', "$baseUrl/buddy/seller/settlements", $tokenSellerA);
assertCheck("Seller A accessing own settlements -> 200 OK", $ownSettlements['code'] === 200);

// --- SUMMARY ---
echo "\n=================================================================\n";
echo " FINAL RBAC TEST RESULTS: $passed PASSED / $failed FAILED\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo ">>> ALL RBAC, STORE ISOLATION, AND ACCOUNT STATUS CHECKS PASSED! <<<\n";
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
    exit(1);
}
