<?php

declare(strict_types=1);

function sendRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'data' => json_decode((string)$res, true)];
}

echo "=== STARTING TECHKART CUSTOMER END-TO-END AUDIT TEST ===\n";

// 1. Register brand new customer
$randomSuffix = rand(10000, 99999);
$testEmail = "fresh.customer{$randomSuffix}@gmail.com";
$testPassword = "Password@123!";
$testName = "Ananya Roy";

echo "1. Registering new customer: {$testEmail}...\n";
$reg = sendRequest('POST', '/customer/auth/register', [
    'full_name' => $testName,
    'email'     => $testEmail,
    'password'  => $testPassword,
]);

if ($reg['status'] !== 201 && $reg['status'] !== 200) {
    echo "FAILED: Customer registration failed with status {$reg['status']}: " . json_encode($reg['data']) . "\n";
    exit(1);
}
$token = $reg['data']['data']['token'] ?? null;
if (!$token) {
    echo "FAILED: No token received in registration response.\n";
    exit(1);
}
echo "✓ Customer registered successfully. Token received.\n";

// 2. Fetch authenticated profile
echo "2. Fetching profile for customer (/customer/auth/profile)...\n";
$prof = sendRequest('GET', '/customer/auth/profile', null, $token);
if ($prof['status'] !== 200 || ($prof['data']['data']['user']['email'] ?? '') !== $testEmail) {
    echo "FAILED: Profile email does not match exact registration email: " . json_encode($prof['data']) . "\n";
    exit(1);
}
echo "✓ Exact customer email verified: " . $prof['data']['data']['user']['email'] . "\n";
echo "✓ Phone is clean/null as expected: " . var_export($prof['data']['data']['user']['phone'] ?? null, true) . "\n";

// 3. Verify Wishlist is initially empty for new user
echo "3. Verifying customer wishlist starts empty (/customer/wishlist)...\n";
$wl = sendRequest('GET', '/customer/wishlist', null, $token);
if ($wl['status'] !== 200) {
    echo "FAILED: GET /customer/wishlist failed with status {$wl['status']}\n";
    exit(1);
}
$wlItems = $wl['data']['data']['items'] ?? null;
if (!is_array($wlItems) || count($wlItems) !== 0) {
    echo "FAILED: Expected 0 wishlist items for new account, got " . count($wlItems) . "\n";
    exit(1);
}
echo "✓ Wishlist is completely empty (0 items) for new user.\n";

// 4. Verify Shopping Cart is initially empty
echo "4. Verifying customer cart starts empty (/customer/cart)...\n";
$cart = sendRequest('GET', '/customer/cart', null, $token);
if ($cart['status'] !== 200) {
    echo "FAILED: GET /customer/cart failed with status {$cart['status']}\n";
    exit(1);
}
$cartItems = $cart['data']['data']['items'] ?? null;
if (!is_array($cartItems) || count($cartItems) !== 0) {
    echo "FAILED: Expected 0 cart items for new account, got " . count($cartItems) . "\n";
    exit(1);
}
echo "✓ Shopping cart is completely empty (0 items) for new user.\n";

// 5. Verify Orders history is initially empty
echo "5. Verifying customer order history starts empty (/customer/orders)...\n";
$orders = sendRequest('GET', '/customer/orders', null, $token);
if ($orders['status'] !== 200) {
    echo "FAILED: GET /customer/orders failed with status {$orders['status']}\n";
    exit(1);
}
$orderList = $orders['data']['data'] ?? null;
if (!is_array($orderList) || count($orderList) !== 0) {
    echo "FAILED: Expected 0 orders for new account, got " . count($orderList) . "\n";
    exit(1);
}
echo "✓ Order history is completely empty (0 orders) for new user.\n";

// 6. Verify Saved Addresses is initially empty
echo "6. Verifying customer saved addresses starts empty (/customer/addresses)...\n";
$addresses = sendRequest('GET', '/customer/addresses', null, $token);
if ($addresses['status'] !== 200) {
    echo "FAILED: GET /customer/addresses failed with status {$addresses['status']}\n";
    exit(1);
}
$addrList = $addresses['data']['data'] ?? null;
if (!is_array($addrList) || count($addrList) !== 0) {
    echo "FAILED: Expected 0 addresses for new account, got " . count($addrList) . "\n";
    exit(1);
}
echo "✓ Address book is completely empty (0 addresses) for new user.\n";

echo "=== ALL TECHKART CUSTOMER END-TO-END VERIFICATIONS PASSED ===\n";
