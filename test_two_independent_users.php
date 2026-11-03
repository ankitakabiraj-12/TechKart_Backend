<?php
require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Database;

function testApi($method, $uri, $body = null, $token = null) {
    $ch = curl_init('http://127.0.0.1:8000/api/v1' . $uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = [];
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true)];
}

echo "========================================================\n";
echo "   TWO INDEPENDENT USERS AUTHENTICATION TEST            \n";
echo "========================================================\n\n";

$ts = time();
$user1_email = "tanmoy.das.{$ts}@gmail.com";
$user1_pass = "Tanmoy@Pass123";
$user1_name = "Tanmoy Das";
$user1_phone = "98" . rand(10000000, 99999999);
$user1_gender = "male";

$user2_email = "riya.sen.{$ts}@gmail.com";
$user2_pass = "Riya@Pass123";
$user2_name = "Riya Sen";
$user2_phone = "97" . rand(10000000, 99999999);
$user2_gender = "female";

// 1. Register User 1 (Tanmoy Das - Male)
echo "[1] Registering User 1: {$user1_name} ({$user1_email}, {$user1_gender})...\n";
$reg1 = testApi('POST', '/customer/auth/register', [
    'full_name' => $user1_name,
    'email' => $user1_email,
    'phone' => $user1_phone,
    'gender' => $user1_gender,
    'password' => $user1_pass,
]);
if ($reg1['code'] !== 201) {
    echo "  FAIL: User 1 registration failed: " . json_encode($reg1) . "\n";
    exit(1);
}
$token1 = $reg1['data']['data']['token'];
$u1 = $reg1['data']['data']['user'];
echo "  PASS: User 1 registered with ID {$u1['id']}, Name: {$u1['full_name']}, Gender: {$u1['gender']}\n";

// 2. Fetch profile of User 1
echo "\n[2] Fetching Profile of User 1...\n";
$prof1 = testApi('GET', '/customer/auth/profile', null, $token1);
$p1 = $prof1['data']['data']['user'];
if ($p1['full_name'] !== $user1_name || $p1['gender'] !== 'male' || $p1['email'] !== $user1_email) {
    echo "  FAIL: User 1 profile mismatch: " . json_encode($p1) . "\n";
    exit(1);
}
echo "  PASS: User 1 profile verified from DB: {$p1['full_name']}, {$p1['email']}, Gender: {$p1['gender']}\n";

// 3. Logout User 1
echo "\n[3] Logging out User 1...\n";
$logout1 = testApi('POST', '/customer/auth/logout', null, $token1);
if ($logout1['code'] !== 200) {
    echo "  FAIL: User 1 logout failed\n";
    exit(1);
}
echo "  PASS: User 1 successfully logged out and session revoked.\n";

// 4. Verify User 1 token is now rejected
echo "\n[4] Verifying User 1 revoked token...\n";
$rev1 = testApi('GET', '/customer/auth/profile', null, $token1);
if ($rev1['code'] !== 401) {
    echo "  FAIL: Expected 401 for revoked token, got {$rev1['code']}\n";
    exit(1);
}
echo "  PASS: User 1 token cleanly rejected after logout.\n";

// 5. Register User 2 (Riya Sen - Female)
echo "\n[5] Registering User 2: {$user2_name} ({$user2_email}, {$user2_gender})...\n";
$reg2 = testApi('POST', '/customer/auth/register', [
    'full_name' => $user2_name,
    'email' => $user2_email,
    'phone' => $user2_phone,
    'gender' => $user2_gender,
    'password' => $user2_pass,
]);
if ($reg2['code'] !== 201) {
    echo "  FAIL: User 2 registration failed: " . json_encode($reg2) . "\n";
    exit(1);
}
$token2 = $reg2['data']['data']['token'];
$u2 = $reg2['data']['data']['user'];
echo "  PASS: User 2 registered with ID {$u2['id']}, Name: {$u2['full_name']}, Gender: {$u2['gender']}\n";

// 6. Verify User 2 profile has strictly User 2 identity
echo "\n[6] Fetching Profile of User 2...\n";
$prof2 = testApi('GET', '/customer/auth/profile', null, $token2);
$p2 = $prof2['data']['data']['user'];
if ($p2['full_name'] !== $user2_name || $p2['gender'] !== 'female' || $p2['email'] !== $user2_email) {
    echo "  FAIL: User 2 profile mismatch: " . json_encode($p2) . "\n";
    exit(1);
}
echo "  PASS: User 2 profile verified from DB: {$p2['full_name']}, {$p2['email']}, Gender: {$p2['gender']}\n";

// 7. Login User 1 again with credentials
echo "\n[7] Re-authenticating User 1 with credentials ({$user1_email})...\n";
$login1 = testApi('POST', '/customer/auth/login', [
    'email' => $user1_email,
    'password' => $user1_pass,
]);
if ($login1['code'] !== 200) {
    echo "  FAIL: User 1 login failed: " . json_encode($login1) . "\n";
    exit(1);
}
$newToken1 = $login1['data']['data']['token'];
$l1 = $login1['data']['data']['user'];
if ($l1['full_name'] !== $user1_name || $l1['gender'] !== 'male') {
    echo "  FAIL: User 1 re-login data corrupted: " . json_encode($l1) . "\n";
    exit(1);
}
echo "  PASS: User 1 re-login successful! Identity: {$l1['full_name']}, Gender: {$l1['gender']}\n";

// 8. Test phone-number login for User 2
echo "\n[8] Authenticating User 2 using mobile number ({$user2_phone})...\n";
$phoneLogin2 = testApi('POST', '/customer/auth/login', [
    'email' => $user2_phone,
    'password' => $user2_pass,
]);
if ($phoneLogin2['code'] !== 200) {
    echo "  FAIL: User 2 phone login failed: " . json_encode($phoneLogin2) . "\n";
    exit(1);
}
$lp2 = $phoneLogin2['data']['data']['user'];
if ($lp2['full_name'] !== $user2_name || $lp2['gender'] !== 'female') {
    echo "  FAIL: User 2 phone login data corrupted: " . json_encode($lp2) . "\n";
    exit(1);
}
echo "  PASS: User 2 phone login successful! Identity: {$lp2['full_name']}, Gender: {$lp2['gender']}\n";

// Clean up
$pdo = Database::getConnection();
$pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute([$user1_email, $user2_email]);

echo "\n========================================================\n";
echo "   TWO INDEPENDENT USERS VERIFICATION: ALL PASSED!      \n";
echo "========================================================\n";
