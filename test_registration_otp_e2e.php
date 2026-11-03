<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';

use App\Core\Database;

echo "========================================================================\n";
echo "   TECHKART REGISTRATION OTP & AUTHENTICATION END-TO-END AUDIT        \n";
echo "========================================================================\n\n";

$pdo = Database::getConnection();
$baseUrl = 'http://127.0.0.1:8000/api/v1';

function postJson(string $url, array $data, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode((string)$res, true)];
}

function getJson(string $url, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode((string)$res, true)];
}

$suffix = time() . mt_rand(100, 999);
$testEmail = "customer.otp.{$suffix}@techkart.in";
$testPhone = "98" . mt_rand(10000000, 99999999);
$testPass = 'SecretP@ss123';

// 1. Initiate registration
echo "[1] Testing Registration OTP Initiation ($testEmail)...\n";
$regRes = postJson("$baseUrl/customer/auth/register", [
    'full_name' => 'Aditi Sen',
    'email'     => $testEmail,
    'phone'     => $testPhone,
    'gender'    => 'female',
    'password'  => $testPass,
]);

if ($regRes['code'] === 200 && ($regRes['data']['data']['requires_otp'] ?? false) === true) {
    echo "  PASS: Registration OTP staged successfully without immediate account creation.\n";
} else {
    echo "  FAIL: Expected 200 with requires_otp=true, got {$regRes['code']}: " . json_encode($regRes['data']) . "\n";
    exit(1);
}

// Verify user does NOT exist in users table yet
$chkUser = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$chkUser->execute(['email' => $testEmail]);
if ($chkUser->fetch()) {
    echo "  FAIL: User account was created prematurely!\n";
    exit(1);
}
echo "  PASS: Confirmed customer account is NOT in users table yet (staging isolation verified).\n";

// 2. Test Invalid OTP
echo "\n[2] Testing Invalid OTP Verification...\n";
$badOtpRes = postJson("$baseUrl/customer/auth/verify-register-otp", [
    'email' => $testEmail,
    'otp'   => '000000',
]);
if ($badOtpRes['code'] === 400) {
    echo "  PASS: Invalid OTP cleanly rejected with HTTP 400.\n";
} else {
    echo "  FAIL: Expected 400 for bad OTP, got {$badOtpRes['code']}\n";
    exit(1);
}

// 3. Retrieve staged OTP hash from DB for verification test
$stmt = $pdo->prepare('SELECT id, otp_hash FROM registration_otps WHERE email = :email AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
$stmt->execute(['email' => $testEmail]);
$staged = $stmt->fetch(PDO::FETCH_ASSOC);

// Simulate finding correct 6-digit match by reversing hash or setting test OTP
$knownOtp = '736291';
$pdo->prepare('UPDATE registration_otps SET otp_hash = :hash WHERE id = :id')
    ->execute(['hash' => hash('sha256', $knownOtp), 'id' => $staged['id']]);

// 4. Test Valid OTP Verification and Account Activation
echo "\n[3] Testing Valid OTP Verification & Account Activation...\n";
$goodOtpRes = postJson("$baseUrl/customer/auth/verify-register-otp", [
    'email' => $testEmail,
    'otp'   => $knownOtp,
]);

if ($goodOtpRes['code'] === 201 && !empty($goodOtpRes['data']['data']['token'])) {
    $jwtToken = $goodOtpRes['data']['data']['token'];
    $userId = $goodOtpRes['data']['data']['user']['id'];
    echo "  PASS: Account activated with HTTP 201. User ID: $userId, Token issued.\n";
} else {
    echo "  FAIL: Expected 201 with JWT token, got {$goodOtpRes['code']}: " . json_encode($goodOtpRes['data']) . "\n";
    exit(1);
}

// Verify user now exists in users table
$chkUser->execute(['email' => $testEmail]);
$userRow = $chkUser->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    echo "  FAIL: User account missing from users table after verification!\n";
    exit(1);
}
echo "  PASS: Verified user record is now active in database.\n";

// 5. Test Duplicate Registration (Conflict)
echo "\n[4] Testing Duplicate Registration Rejection...\n";
$dupRes = postJson("$baseUrl/customer/auth/register", [
    'full_name' => 'Aditi Sen',
    'email'     => $testEmail,
    'phone'     => $testPhone,
    'password'  => $testPass,
]);
if ($dupRes['code'] === 409) {
    echo "  PASS: Duplicate registration correctly rejected with HTTP 409 Conflict.\n";
} else {
    echo "  FAIL: Expected 409 Conflict, got {$dupRes['code']}\n";
    exit(1);
}

// 6. Test Logging in with activated credentials
echo "\n[5] Testing Login with Activated Credentials...\n";
$loginRes = postJson("$baseUrl/customer/auth/login", [
    'email'    => $testEmail,
    'password' => $testPass,
]);
if ($loginRes['code'] === 200) {
    echo "  PASS: Login successful with activated credentials.\n";
} else {
    echo "  FAIL: Expected 200 login, got {$loginRes['code']}\n";
    exit(1);
}

// 7. Verify zero records across all customer domains for fresh customer
echo "\n[6] Verifying Fresh Account Data Isolation...\n";
$cartRes = getJson("$baseUrl/customer/cart", $jwtToken);
$wishRes = getJson("$baseUrl/customer/wishlist", $jwtToken);
$orderRes = getJson("$baseUrl/customer/orders", $jwtToken);

$cartItems = $cartRes['data']['data']['items'] ?? [];
$wishItems = $wishRes['data']['data']['items'] ?? $wishRes['data']['data'] ?? [];
$orderItems = $orderRes['data']['data'] ?? [];

if (count($cartItems) === 0 && count($wishItems) === 0 && count($orderItems) === 0) {
    echo "  PASS: Brand new customer starts with 0 cart items, 0 wishlist items, and 0 orders.\n";
} else {
    echo "  FAIL: Unexpected data found for new customer!\n";
    exit(1);
}

// 8. Test Logout and Session Invalidation
echo "\n[7] Testing Logout & Token Blacklist...\n";
$logoutRes = postJson("$baseUrl/customer/auth/logout", [], $jwtToken);
if ($logoutRes['code'] === 200) {
    echo "  PASS: Logout succeeded and session invalidated.\n";
} else {
    echo "  FAIL: Expected 200 logout, got {$logoutRes['code']}\n";
    exit(1);
}

// 9. Verify blacklisted token rejected
$profileRes = getJson("$baseUrl/customer/auth/profile", $jwtToken);
if ($profileRes['code'] === 401) {
    echo "  PASS: Revoked token successfully rejected with HTTP 401 Unauthorized.\n";
} else {
    echo "  FAIL: Revoked token was not rejected! Code: {$profileRes['code']}\n";
    exit(1);
}

echo "\n========================================================================\n";
echo "   REGISTRATION OTP & AUTHENTICATION AUDIT: ALL TESTS PASSED!          \n";
echo "========================================================================\n";
