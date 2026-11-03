<?php
/**
 * Comprehensive verification test for Seller Profile and Store Management
 * in TechKart Buddy (Core PHP REST API & MariaDB).
 *
 * Test cases:
 *  1. Unauthenticated profile access -> 401 Unauthorized
 *  2. Seller A (Vikram Patel, ID 2) fetches own profile & store details -> 200 OK
 *  3. Seller A updates own store profile (store_name, contact info, address) -> 200 OK
 *  4. Seller cross-store protection: Seller A cannot update Seller B's profile -> 403 Forbidden
 *  5. Verification status protection: Seller cannot self-verify (is_verified) -> 403 Forbidden
 *  6. Duplicate store name prevention: Attempting to rename store to an existing name -> 409 Conflict
 *  7. Admin authority:
 *     - Admin can inspect Seller A's store profile via ?seller_id=2 -> 200 OK
 *     - Admin can update Seller A's verification status (is_verified = 1/0) -> 200 OK
 *     - Admin can fetch the full registered merchants directory -> 200 OK
 *  8. Account status enforcement on profile route: deactivated seller -> 403 Forbidden
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
echo " TECHKART BUDDY SELLER PROFILE & STORE MANAGEMENT TEST\n";
echo " Base URL: $baseUrl\n";
echo "=================================================================\n\n";

// --- 1. AUTHENTICATE ACTORS ---
echo "[STEP 1] Authenticating test actors...\n";

// Seller A (ID 2)
$loginSellerA = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$tokenSellerA = $loginSellerA['body']['data']['token'] ?? null;
assertCheck("Seller A authenticated", $loginSellerA['code'] === 200 && !empty($tokenSellerA));

// Seller B (ID 4)
$loginSellerB = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123'
]);
$tokenSellerB = $loginSellerB['body']['data']['token'] ?? null;
assertCheck("Seller B authenticated", $loginSellerB['code'] === 200 && !empty($tokenSellerB));

// Admin (ID 3)
$loginAdmin = callApi('POST', "$baseUrl/buddy/auth/login", null, [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
$tokenAdmin = $loginAdmin['body']['data']['token'] ?? null;
assertCheck("Admin authenticated", $loginAdmin['code'] === 200 && !empty($tokenAdmin));

// --- 2. UNAUTHENTICATED PROTECTION ---
echo "\n[STEP 2] Unauthenticated access protection (401)...\n";
$unauthProfile = callApi('GET', "$baseUrl/buddy/seller/profile");
assertCheck("Unauthenticated GET /buddy/seller/profile -> 401", $unauthProfile['code'] === 401);

$unauthUpdate = callApi('PUT', "$baseUrl/buddy/seller/profile", null, ['store_name' => 'Hacked Store']);
assertCheck("Unauthenticated PUT /buddy/seller/profile -> 401", $unauthUpdate['code'] === 401);

$unauthDir = callApi('GET', "$baseUrl/buddy/admin/sellers");
assertCheck("Unauthenticated GET /buddy/admin/sellers -> 401", $unauthDir['code'] === 401);

// --- 3. SELLER FETCHES OWN PROFILE ---
echo "\n[STEP 3] Seller A views own profile & store details...\n";
$getProfileRes = callApi('GET', "$baseUrl/buddy/seller/profile", $tokenSellerA);
assertCheck(
    "Seller A gets own profile -> 200 OK",
    $getProfileRes['code'] === 200 &&
    ($getProfileRes['body']['data']['user']['id'] ?? 0) === 2 &&
    !empty($getProfileRes['body']['data']['store']['store_name']),
    "Store Name: " . ($getProfileRes['body']['data']['store']['store_name'] ?? 'none')
);
assertCheck(
    "Profile response contains aggregated stats (products, orders, rating)",
    isset($getProfileRes['body']['data']['stats']['active_products']) &&
    isset($getProfileRes['body']['data']['stats']['sla_compliance']),
    "Active products: " . ($getProfileRes['body']['data']['stats']['active_products'] ?? 0)
);

// --- 4. SELLER UPDATES OWN STORE DETAILS ---
echo "\n[STEP 4] Seller A updates business details, phone, and address...\n";
$updateRes = callApi('PUT', "$baseUrl/buddy/seller/profile", $tokenSellerA, [
    'full_name'  => 'Vikram Patel',
    'phone'      => '+880 1711-902844',
    'store_name' => 'Apex Gear BD',
    'gst_number' => '27AABCT3518Q1ZP',
    'address'    => 'Shop 14, Multiplan Center, Elephant Road',
    'city'       => 'Dhaka',
    'state'      => 'Dhaka Division',
    'pincode'    => '1205'
]);
assertCheck(
    "Seller A updates profile -> 200 OK",
    $updateRes['code'] === 200 &&
    ($updateRes['body']['data']['store']['address'] ?? '') === 'Shop 14, Multiplan Center, Elephant Road',
    "Updated address: " . ($updateRes['body']['data']['store']['address'] ?? '')
);

// --- 5. PERMISSION ENFORCEMENT: SELLER CANNOT SELF-VERIFY ---
echo "\n[STEP 5] Verification status security (Seller cannot self-verify)...\n";
$selfVerifyAttempt = callApi('PUT', "$baseUrl/buddy/seller/profile", $tokenSellerA, [
    'is_verified' => 1
]);
assertCheck(
    "Seller attempting is_verified update -> 403 Forbidden",
    $selfVerifyAttempt['code'] === 403,
    "Message: " . ($selfVerifyAttempt['body']['message'] ?? '')
);

// --- 6. CROSS-STORE PERMISSION ENFORCEMENT ---
echo "\n[STEP 6] Cross-store isolation (Seller A modifying Seller B's profile)...\n";
$crossStoreAttempt = callApi('PUT', "$baseUrl/buddy/seller/profile", $tokenSellerA, [
    'seller_id'  => 4,
    'store_name' => 'Compromised GadgetPlanet'
]);
assertCheck(
    "Seller A modifying Seller B (ID 4) profile -> 403 Forbidden",
    $crossStoreAttempt['code'] === 403,
    "Message: " . ($crossStoreAttempt['body']['message'] ?? '')
);

// --- 7. DUPLICATE STORE NAME CONFLICT ---
echo "\n[STEP 7] Duplicate store name validation (409 Conflict)...\n";
$dupStoreAttempt = callApi('PUT', "$baseUrl/buddy/seller/profile", $tokenSellerA, [
    'store_name' => 'GadgetPlanet BD' // belongs to Seller B (ID 4)
]);
assertCheck(
    "Attempting to claim taken store name -> 409 Conflict",
    $dupStoreAttempt['code'] === 409,
    "Message: " . ($dupStoreAttempt['body']['message'] ?? '')
);

// --- 8. ADMIN DIRECTORY & STORE MANAGEMENT ---
echo "\n[STEP 8] Admin Registered Merchants Directory & Verification Controls...\n";
$adminDirRes = callApi('GET', "$baseUrl/buddy/admin/sellers", $tokenAdmin);
assertCheck(
    "Admin gets sellers directory -> 200 OK",
    $adminDirRes['code'] === 200 && !empty($adminDirRes['body']['data']['sellers']),
    "Merchants returned: " . count($adminDirRes['body']['data']['sellers'] ?? [])
);
assertCheck(
    "Admin directory contains aggregate metrics header",
    isset($adminDirRes['body']['data']['metrics']['total_merchants']) &&
    isset($adminDirRes['body']['data']['metrics']['compliance_rate'])
);

// Non-admin blocked from directory
$sellerDirAttempt = callApi('GET', "$baseUrl/buddy/admin/sellers", $tokenSellerA);
assertCheck("Seller blocked from admin sellers directory -> 403 Forbidden", $sellerDirAttempt['code'] === 403);

// Admin inspects Seller B via seller_id query
$adminInspectB = callApi('GET', "$baseUrl/buddy/seller/profile?seller_id=4", $tokenAdmin);
assertCheck(
    "Admin inspects Seller B (ID 4) -> 200 OK",
    $adminInspectB['code'] === 200 &&
    ($adminInspectB['body']['data']['user']['id'] ?? 0) === 4
);

// Admin updates Seller B's verification status
$adminVerifyB = callApi('PUT', "$baseUrl/buddy/admin/sellers/4/verification", $tokenAdmin, [
    'is_verified' => 1,
    'notes'       => 'KYC verified via physical trade license check'
]);
assertCheck(
    "Admin verifies Seller B -> 200 OK",
    $adminVerifyB['code'] === 200 && ($adminVerifyB['body']['data']['is_verified'] ?? 0) === 1
);

// --- 9. ACCOUNT DEACTIVATION STATUS ENFORCEMENT ---
echo "\n[STEP 9] Deactivated account enforcement on profile route...\n";
$pdo->exec('UPDATE users SET is_active = 0 WHERE id = 4');
$deactivatedProfile = callApi('GET', "$baseUrl/buddy/seller/profile", $tokenSellerB);
assertCheck(
    "Deactivated seller accessing profile -> 403 Forbidden",
    $deactivatedProfile['code'] === 403,
    "Message: " . ($deactivatedProfile['body']['message'] ?? '')
);
$pdo->exec('UPDATE users SET is_active = 1 WHERE id = 4');

// --- SUMMARY ---
echo "\n=================================================================\n";
echo " FINAL PROFILE & STORE TEST RESULTS: $passed PASSED / $failed FAILED\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo ">>> ALL SELLER PROFILE & STORE MANAGEMENT CHECKS PASSED! <<<\n";
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
    exit(1);
}
