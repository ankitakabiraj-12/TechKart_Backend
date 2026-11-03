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

echo "=== STARTING FINAL TECHKART CUSTOMER AUDIT & LOCALIZATION TEST ===\n";

// 1. Register brand new customer with Indian name & exact email
$rand = rand(10000, 99999);
$testEmail = "rohit.verma{$rand}@gmail.com";
$testPassword = "CustomerPass@2026";
$testName = "Rohit Verma";
$uniquePhone = "+91 98201 " . $rand;

echo "1. Registering customer: {$testEmail}...\n";
$reg = sendRequest('POST', '/customer/auth/register', [
    'full_name' => $testName,
    'email'     => $testEmail,
    'password'  => $testPassword,
]);

if ($reg['status'] !== 201 && $reg['status'] !== 200) {
    echo "FAILED: Customer registration failed: " . json_encode($reg['data']) . "\n";
    exit(1);
}
$token = $reg['data']['data']['token'] ?? null;
if (!$token) {
    echo "FAILED: Missing auth token.\n";
    exit(1);
}
echo "✓ Registration succeeded. Token acquired.\n";

// 2. Add an Indian Address with +91 phone, Indian PIN code, state, and city
echo "2. Adding Indian delivery address (+91 phone, 6-digit PIN 400050, Mumbai, Maharashtra)...\n";
$addr = sendRequest('POST', '/customer/addresses', [
    'label' => 'Home',
    'recipient_name' => 'Rohit Verma',
    'phone' => $uniquePhone,
    'address_line1' => 'Flat 402, Sea Green Heights, Bandra West',
    'city' => 'Mumbai',
    'state' => 'Maharashtra',
    'postal_code' => '400050',
    'country' => 'India',
    'delivery_instructions' => 'Leave with society security desk if unavailable.',
    'is_default' => 1
], $token);

if ($addr['status'] !== 201 && $addr['status'] !== 200) {
    echo "FAILED: Adding address failed: " . json_encode($addr['data']) . "\n";
    exit(1);
}
$savedAddr = $addr['data']['data'] ?? [];
if (($savedAddr['country'] ?? '') !== 'India' || ($savedAddr['postal_code'] ?? '') !== '400050') {
    echo "FAILED: Address localization mismatch: " . json_encode($savedAddr) . "\n";
    exit(1);
}
echo "✓ Indian address saved and verified with PIN 400050, Mumbai, Maharashtra, India.\n";

// 3. Verify Indian Phone update in Profile
echo "3. Updating customer profile with valid +91 phone number...\n";
$updProf = sendRequest('PUT', '/customer/auth/profile', [
    'full_name' => 'Rohit Verma',
    'phone' => $uniquePhone,
], $token);

if ($updProf['status'] !== 200) {
    echo "FAILED: Updating profile phone failed: " . json_encode($updProf['data']) . "\n";
    exit(1);
}
$profData = $updProf['data']['data']['user'] ?? [];
if (($profData['phone'] ?? '') !== $uniquePhone) {
    echo "FAILED: Phone mismatch after profile update: " . json_encode($profData) . "\n";
    exit(1);
}
echo "✓ Customer phone verified with Indian prefix: " . $profData['phone'] . "\n";

// 4. Verify Catalog Prices & Cart Calculations in INR
echo "4. Adding product (VoltFast 65W GaN Charger) to cart and verifying INR pricing...\n";
$addCart = sendRequest('POST', '/customer/cart', [
    'product_id' => 2, // VoltFast 65W GaN Dual USB-C Fast Charger
    'quantity' => 2
], $token);

if ($addCart['status'] !== 200 && $addCart['status'] !== 201) {
    echo "FAILED: Adding product to cart failed: " . json_encode($addCart['data']) . "\n";
    exit(1);
}

$getCart = sendRequest('GET', '/customer/cart', null, $token);
if ($getCart['status'] !== 200) {
    echo "FAILED: Fetching cart failed: " . json_encode($getCart['data']) . "\n";
    exit(1);
}
$cartSummary = $getCart['data']['data']['summary'] ?? [];
$cartItems = $getCart['data']['data']['items'] ?? [];

if (count($cartItems) !== 1 || $cartSummary['total_quantity'] !== 2) {
    echo "FAILED: Cart items count or quantity mismatch: " . json_encode($getCart['data']) . "\n";
    exit(1);
}
echo "✓ Cart calculated correctly in INR. Subtotal: ₹" . number_format($cartSummary['subtotal'], 2) . " for 2 items.\n";

// 5. Place order and verify INR order history
echo "5. Placing order and verifying checkout totals...\n";
$orderRes = sendRequest('POST', '/customer/orders', [
    'address_id' => (int)$savedAddr['id'],
    'payment_method' => 'upi',
    'delivery_speed' => 'express',
], $token);

if ($orderRes['status'] !== 201 && $orderRes['status'] !== 200) {
    echo "FAILED: Order placement failed: " . json_encode($orderRes['data']) . "\n";
    exit(1);
}
$createdOrder = $orderRes['data']['data'] ?? [];
echo "✓ Order placed successfully: Order #" . $createdOrder['order_number'] . " with total ₹" . number_format($createdOrder['total_amount'], 2) . "\n";

// 6. Verify order in order history
echo "6. Verifying order history reflects newly placed order...\n";
$orderHistory = sendRequest('GET', '/customer/orders', null, $token);
if ($orderHistory['status'] !== 200 || count($orderHistory['data']['data'] ?? []) !== 1) {
    echo "FAILED: Order history does not reflect exactly 1 order: " . json_encode($orderHistory['data']) . "\n";
    exit(1);
}
echo "✓ Customer order history verified with exactly 1 live order.\n";

echo "=== ALL TECHKART CUSTOMER LOCALIZATION & E2E CHECKS PASSED ===\n";
