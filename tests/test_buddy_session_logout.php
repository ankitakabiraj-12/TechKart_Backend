<?php
/**
 * Verification test for JWT session validation, expiry handling, secure logout, and role access
 */

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
echo " TECHKART BUDDY JWT SESSION VALIDATION & LOGOUT VERIFICATION\n";
echo " Base URL: $baseUrl\n";
echo "=================================================================\n\n";

// 1. Authenticate Seller
echo "[STEP 1] Login Seller (vikram.store@techkart.com)...\n";
$sellerLogin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$sellerToken = $sellerLogin['body']['data']['token'] ?? null;
assertCheck("Seller login returns 200 with JWT", $sellerLogin['code'] === 200 && !empty($sellerToken));

// 2. Validate Session with valid token
echo "\n[STEP 2] Validate Seller Session (/buddy/auth/validate-session)...\n";
$sessionVal = callApi('GET', "$baseUrl/buddy/auth/validate-session", $sellerToken);
assertCheck(
    "Session validation returns 200 with role 'seller' and store details",
    $sessionVal['code'] === 200 &&
    ($sessionVal['body']['data']['role'] ?? '') === 'seller' &&
    !empty($sessionVal['body']['data']['store']['store_name']),
    "Store: " . ($sessionVal['body']['data']['store']['store_name'] ?? 'none')
);

// 3. Test Role-Based Protection: Seller access to Admin-only route
echo "\n[STEP 3] Role-Based Protection Check (Seller accessing admin dashboard)...\n";
$adminForbidden = callApi('GET', "$baseUrl/buddy/admin/dashboard", $sellerToken);
assertCheck(
    "Seller is forbidden from Admin route (/buddy/admin/dashboard)",
    $adminForbidden['code'] === 403
);

// 4. Test Missing and Invalid Token Handling
echo "\n[STEP 4] Token Validation & Error Handling...\n";
$noToken = callApi('GET', "$baseUrl/buddy/auth/validate-session");
assertCheck("Missing token returns 401 Unauthorized", $noToken['code'] === 401);

$invalidToken = callApi('GET', "$baseUrl/buddy/auth/validate-session", "invalid.jwt.token123");
assertCheck("Malformed token returns 401 Unauthorized", $invalidToken['code'] === 401);

// 5. Test Expiry Handling using artificially expired token
echo "\n[STEP 5] Token Expiry Handling...\n";
require_once __DIR__ . '/../bootstrap/app.php';
$expiredJwt = \App\Core\Jwt::generate(['sub' => 2, 'email' => 'vikram.store@techkart.com', 'role' => 'seller'], -10);
$expiredRes = callApi('GET', "$baseUrl/buddy/auth/validate-session", $expiredJwt);
assertCheck("Expired token is rejected with 401", $expiredRes['code'] === 401);

// 6. Test Secure Logout and Invalidation
echo "\n[STEP 6] Secure Logout (/buddy/auth/logout)...\n";
$logoutRes = callApi('POST', "$baseUrl/buddy/auth/logout", $sellerToken);
assertCheck(
    "Logout returns 200 and logs_out is true",
    $logoutRes['code'] === 200 && ($logoutRes['body']['data']['logged_out'] ?? false) === true
);

// 7. Verify Invalidation: Token cannot be used anymore
echo "\n[STEP 7] Verify Token Invalidation after Logout...\n";
$postLogoutVal = callApi('GET', "$baseUrl/buddy/auth/validate-session", $sellerToken);
assertCheck(
    "Blacklisted/Logged-out token is rejected with 401 Unauthorized",
    $postLogoutVal['code'] === 401,
    "Response Code: " . $postLogoutVal['code']
);

$postLogoutDash = callApi('GET', "$baseUrl/buddy/seller/dashboard", $sellerToken);
assertCheck(
    "Protected seller dashboard rejects invalidated token with 401",
    $postLogoutDash['code'] === 401
);

// 8. Admin Login & Logout Flow
echo "\n[STEP 8] Admin Login & Logout Verification...\n";
$adminLogin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
$adminToken = $adminLogin['body']['data']['token'] ?? null;
assertCheck("Admin login returns 200 with JWT", $adminLogin['code'] === 200 && !empty($adminToken));

$adminVal = callApi('GET', "$baseUrl/buddy/auth/validate-session", $adminToken);
assertCheck("Admin session validation returns role 'admin'", ($adminVal['body']['data']['role'] ?? '') === 'admin');

$adminLogout = callApi('POST', "$baseUrl/buddy/auth/logout", $adminToken);
assertCheck("Admin logout returns 200", $adminLogout['code'] === 200);

$adminPostLogout = callApi('GET', "$baseUrl/buddy/admin/dashboard", $adminToken);
assertCheck("Admin dashboard rejects invalidated token with 401", $adminPostLogout['code'] === 401);

echo "\n=================================================================\n";
echo " RESULTS: $passed PASSED / $failed FAILED\n";
echo "=================================================================\n";
