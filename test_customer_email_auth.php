<?php
/**
 * Test Customer Registration, Login, Profile Retrieval, and Email Integrity Validation.
 */

require_once __DIR__ . '/bootstrap/app.php';

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "========================================================\n";
echo "   TECHKART CUSTOMER AUTHENTICATION & PROFILE TEST      \n";
echo "========================================================\n\n";

$testEmail = 'customer.verify.' . time() . '@example.com';
$testPassword = 'TestPassword123!';
$testName = 'Priya Sharma';

// 1. Test invalid email format rejection
echo "[1] Testing invalid email format rejection...\n";
$invalidEmailRes = makeRequest('POST', "$baseUrl/customer/auth/register", [], [
    'full_name' => $testName,
    'email' => 'invalid-email-string',
    'password' => $testPassword
]);
if ($invalidEmailRes['code'] === 422) {
    echo "  PASS: Correctly rejected invalid email with HTTP 422.\n";
} else {
    echo "  FAIL: Expected 422, got {$invalidEmailRes['code']}. Body: {$invalidEmailRes['raw']}\n";
    exit(1);
}

// 2. Test customer registration with exact user-provided email & OTP verification
echo "\n[2] Registering customer with exact email: {$testEmail}...\n";
$registerRes = makeRequest('POST', "$baseUrl/customer/auth/register", [], [
    'full_name' => $testName,
    'email' => $testEmail,
    'password' => $testPassword
]);

if ($registerRes['code'] !== 200 || !($registerRes['body']['data']['requires_otp'] ?? false)) {
    echo "  FAIL: Registration staging failed with HTTP {$registerRes['code']}. Body: {$registerRes['raw']}\n";
    exit(1);
}

// Complete verification with OTP from staging table
$pdo = \App\Core\Database::getConnection();
$stagedOtpStmt = $pdo->prepare('SELECT id FROM registration_otps WHERE email = :email AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
$stagedOtpStmt->execute(['email' => $testEmail]);
$stagedId = (int)$stagedOtpStmt->fetchColumn();

$knownTestOtp = '928471';
$pdo->prepare('UPDATE registration_otps SET otp_hash = :hash WHERE id = :id')
    ->execute(['hash' => hash('sha256', $knownTestOtp), 'id' => $stagedId]);

$verifyRes = makeRequest('POST', "$baseUrl/customer/auth/verify-register-otp", [], [
    'email' => $testEmail,
    'otp' => $knownTestOtp,
]);

if ($verifyRes['code'] !== 201) {
    echo "  FAIL: Registration OTP verification failed with HTTP {$verifyRes['code']}. Body: {$verifyRes['raw']}\n";
    exit(1);
}

$regUserData = $verifyRes['body']['data']['user'] ?? null;
$regToken = $verifyRes['body']['data']['token'] ?? null;

if (!$regUserData || $regUserData['email'] !== $testEmail) {
    echo "  FAIL: Registered user email mismatch. Expected '{$testEmail}', got '" . ($regUserData['email'] ?? 'null') . "'\n";
    exit(1);
}
echo "  PASS: Registration and OTP verification succeeded. Created user ID {$regUserData['id']} with exact email: {$regUserData['email']}\n";

// 3. Test uniqueness validation (duplicate registration attempt)
echo "\n[3] Testing email uniqueness validation (duplicate registration)...\n";
$dupRes = makeRequest('POST', "$baseUrl/customer/auth/register", [], [
    'full_name' => 'Duplicate User',
    'email' => $testEmail,
    'password' => 'AnotherPassword123!'
]);
if ($dupRes['code'] === 409) {
    echo "  PASS: Duplicate registration rejected with HTTP 409 Conflict.\n";
} else {
    echo "  FAIL: Expected 409 Conflict, got {$dupRes['code']}. Body: {$dupRes['raw']}\n";
    exit(1);
}

// 4. Test customer login with the exact email
echo "\n[4] Logging in with exact email: {$testEmail}...\n";
$loginRes = makeRequest('POST', "$baseUrl/customer/auth/login", [], [
    'email' => $testEmail,
    'password' => $testPassword
]);

if ($loginRes['code'] !== 200) {
    echo "  FAIL: Login failed with HTTP {$loginRes['code']}. Body: {$loginRes['raw']}\n";
    exit(1);
}

$loginUserData = $loginRes['body']['data']['user'] ?? null;
$authToken = $loginRes['body']['data']['token'] ?? null;

if (!$loginUserData || $loginUserData['email'] !== $testEmail || empty($authToken)) {
    echo "  FAIL: Login response email mismatch or missing auth token.\n";
    exit(1);
}
echo "  PASS: Login succeeded. Returned user profile with exact email: {$loginUserData['email']}\n";

// 5. Test authenticated customer profile retrieval
echo "\n[5] Fetching authenticated customer profile (/customer/auth/profile)...\n";
$profileRes = makeRequest('GET', "$baseUrl/customer/auth/profile", [
    "Authorization: Bearer {$authToken}"
]);

if ($profileRes['code'] !== 200) {
    echo "  FAIL: Profile retrieval failed with HTTP {$profileRes['code']}. Body: {$profileRes['raw']}\n";
    exit(1);
}

$profileUser = $profileRes['body']['data']['user'] ?? null;
if (!$profileUser || $profileUser['email'] !== $testEmail) {
    echo "  FAIL: Authenticated profile email mismatch. Expected '{$testEmail}', got '" . ($profileUser['email'] ?? 'null') . "'\n";
    exit(1);
}
echo "  PASS: Authenticated profile retrieved successfully. Displayed email: {$profileUser['email']}\n";

// 6. Test update profile ensures email is preserved and never overwritten
echo "\n[6] Testing profile update (ensuring user-provided email is preserved)...\n";
$updateRes = makeRequest('PUT', "$baseUrl/customer/auth/profile", [
    "Authorization: Bearer {$authToken}"
], [
    'full_name' => 'Priya Sharma Updated',
    'phone' => '+919876543219'
]);

if ($updateRes['code'] !== 200) {
    echo "  FAIL: Profile update failed with HTTP {$updateRes['code']}. Body: {$updateRes['raw']}\n";
    exit(1);
}

$updatedUser = $updateRes['body']['data']['user'] ?? null;
if (!$updatedUser || $updatedUser['email'] !== $testEmail || $updatedUser['full_name'] !== 'Priya Sharma Updated') {
    echo "  FAIL: Profile updated incorrectly or email corrupted.\n";
    exit(1);
}
echo "  PASS: Profile updated. User email remains strictly intact: {$updatedUser['email']}\n";

// 7. Verify TechKart Buddy seller & admin logic remain intact
echo "\n[7] Verifying TechKart Buddy seller login & admin audit flow are unaffected...\n";
$buddySellerLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'Password@123'
]);
if ($buddySellerLogin['code'] === 200 && ($buddySellerLogin['body']['data']['user']['role'] ?? '') === 'seller') {
    echo "  PASS: TechKart Buddy seller login verified successfully.\n";
} else {
    echo "  FAIL: TechKart Buddy seller login failed: {$buddySellerLogin['raw']}\n";
    exit(1);
}

$buddyAdminLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'Password@123'
]);
if ($buddyAdminLogin['code'] === 200 && ($buddyAdminLogin['body']['data']['user']['role'] ?? '') === 'admin') {
    echo "  PASS: TechKart Buddy admin login verified successfully.\n";
} else {
    echo "  FAIL: TechKart Buddy admin login failed: {$buddyAdminLogin['raw']}\n";
    exit(1);
}

// Clean up test customer user from database to keep data clean
require_once __DIR__ . '/bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();
$pdo->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $testEmail]);

echo "\n========================================================\n";
echo "   ALL CUSTOMER REGISTRATION, LOGIN & PROFILE TESTS PASSED\n";
echo "========================================================\n";
