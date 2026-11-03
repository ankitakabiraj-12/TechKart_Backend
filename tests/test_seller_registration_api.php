<?php
/**
 * Verification test for Seller Registration API
 * POST /api/v1/buddy/auth/register-seller
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function postJson($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

function getJson($url, $token = null) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

echo "=== TEST 1: Validation Failure (Missing Required Fields) ===\n";
$res1 = postJson("$baseUrl/buddy/auth/register-seller", [
    'email' => 'bademail',
    'password' => '123'
]);
echo "Code: " . $res1['code'] . "\n";
echo "Response: " . $res1['raw'] . "\n\n";

echo "=== TEST 2: Successful Live Seller Registration ===\n";
$testEmail = 'nexus.mart.' . time() . '@testmerchant.com';
$testStore = 'Nexus Mart BD ' . rand(100, 999);
$res2 = postJson("$baseUrl/buddy/auth/register-seller", [
    'full_name' => 'Fahim Rahman',
    'email' => $testEmail,
    'password' => 'SecurePass#2026',
    'store_name' => $testStore,
    'phone' => '+880 1799-887766',
    'category' => 'Consumer Electronics & Gadgets',
    'trade_license' => 'TRAD/DNCC/778899/2026',
    'tin_number' => 'eTIN-99887766554',
    'address' => 'Floor 5, Software Tech Park, Kawran Bazar',
    'city' => 'Dhaka',
    'pincode' => '1215'
]);
echo "Code: " . $res2['code'] . "\n";
echo "Response: " . $res2['raw'] . "\n\n";

$newSellerToken = $res2['body']['data']['token'] ?? null;
$appNumber = $res2['body']['data']['application']['app_number'] ?? null;

echo "=== TEST 3: Duplicate Account Prevention (Same Email) ===\n";
$res3 = postJson("$baseUrl/buddy/auth/register-seller", [
    'full_name' => 'Duplicate Test',
    'email' => $testEmail,
    'password' => 'AnotherPass123',
    'store_name' => 'Another Store ' . rand(100, 999),
]);
echo "Code: " . $res3['code'] . "\n";
echo "Response: " . $res3['raw'] . "\n\n";

echo "=== TEST 4: Duplicate Account Prevention (Same Store Name) ===\n";
$res4 = postJson("$baseUrl/buddy/auth/register-seller", [
    'full_name' => 'Duplicate Store Test',
    'email' => 'different.' . time() . '@testmerchant.com',
    'password' => 'AnotherPass123',
    'store_name' => $testStore,
]);
echo "Code: " . $res4['code'] . "\n";
echo "Response: " . $res4['raw'] . "\n\n";

echo "=== TEST 5: Verify Login with New Seller Account & Password Hash ===\n";
$res5 = postJson("$baseUrl/buddy/auth/login", [
    'email' => $testEmail,
    'password' => 'SecurePass#2026'
]);
echo "Code: " . $res5['code'] . "\n";
echo "Store Name: " . ($res5['body']['data']['store_name'] ?? 'N/A') . "\n\n";

echo "=== TEST 6: Verify New Registration in Admin Seller Applications Queue ===\n";
$adminLogin = postJson("$baseUrl/buddy/auth/login", [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
$adminToken = $adminLogin['body']['data']['token'] ?? null;
$res6 = getJson("$baseUrl/buddy/admin/seller-applications", $adminToken);
echo "Code: " . $res6['code'] . "\n";
$found = false;
foreach ($res6['body']['data']['applications'] ?? [] as $app) {
    if (($app['email'] ?? '') === $testEmail || ($app['app_number'] ?? '') === $appNumber) {
        $found = true;
        echo "Found Application in Admin Queue: App #{$app['app_number']} | Store: {$app['business_name']} | Status: {$app['status']}\n";
        break;
    }
}
if (!$found) {
    echo "Application not found in queue!\n";
}

echo "\n=== ALL VERIFICATION CHECKS COMPLETE ===\n";
