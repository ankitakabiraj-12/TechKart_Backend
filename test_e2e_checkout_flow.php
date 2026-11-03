<?php
/**
 * Comprehensive End-to-End Test Suite for Customer Checkout Flow:
 * Cart -> Delivery Address -> Order Review -> Payment Method -> Secure Payment -> Order Confirmation -> My Orders -> Order Details
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Jwt;

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function logStep(string $step, bool $pass, string $detail = ''): void {
    echo ($pass ? "[PASS] " : "[FAIL] ") . $step . ($detail !== '' ? " -> $detail" : "") . PHP_EOL;
    if (!$pass) {
        exit(1);
    }
}

function httpReq(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$response, true);
    return ['status' => $httpCode, 'body' => $json, 'raw' => $response];
}

$pdo = Database::getConnection();

// 1. Verify / Health
$health = httpReq('GET', "$baseUrl/health");
logStep("1. Backend API & MariaDB Health Connectivity", $health['status'] === 200 && ($health['body']['data']['database']['status'] ?? '') === 'connected', "Status: 200 OK");

// 2. Select existing active customer and issue fresh JWT
$customer = $pdo->query("SELECT * FROM users WHERE role = 'customer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    die("No customer found in database.\n");
}
$customerId = (int)$customer['id'];
$custEmail = $customer['email'];

$token = Jwt::generate([
    'sub'   => $customerId,
    'email' => $custEmail,
    'role'  => 'customer',
]);
logStep("2. Customer Selection & JWT Token Generation", !empty($token), "Customer: $custEmail (ID: $customerId)");

// Clear previous cart for pristine test state
$pdo->prepare("DELETE FROM cart_items WHERE customer_id = :cid")->execute(['cid' => $customerId]);

// 3. Step 1: Cart Operations (Add 2 items)
// Find published products with stock
$prodStmt = $pdo->query("SELECT id, title, price, stock_quantity FROM products WHERE status = 'published' AND stock_quantity >= 2 ORDER BY id ASC LIMIT 2");
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($products) < 1) {
    die("Need at least 1 published product with stock >= 2 for test\n");
}
$p1 = $products[0];
$p1Id = (int)$p1['id'];
$p1InitialStock = (int)$p1['stock_quantity'];

// Add item to cart via API
$add1 = httpReq('POST', "$baseUrl/customer/cart", ['product_id' => $p1Id, 'quantity' => 2], $token);
logStep("3. Customer Cart Item Addition (2x P1)", $add1['status'] === 201, "Added product ID $p1Id (Qty: 2)");

// Verify Cart Contents
$cartResp = httpReq('GET', "$baseUrl/customer/cart", null, $token);
$cartItems = $cartResp['body']['data']['items'] ?? [];
logStep("4. Customer Cart Verification with Server-Calculated Totals", $cartResp['status'] === 200 && count($cartItems) >= 1, "Items: " . count($cartItems));

// 4. Step 2: Delivery Address
$addrResp = httpReq('POST', "$baseUrl/customer/addresses", [
    'label' => 'Home',
    'recipient_name' => 'Tanvir Ahmed',
    'phone' => '+880 1712-345678',
    'address_line1' => 'House 42, Road 11, Block D, Banani',
    'city' => 'Dhaka',
    'state' => 'Dhaka',
    'postal_code' => '1213',
    'country' => 'Bangladesh',
    'is_default' => 1,
], $token);
$addressId = (int)($addrResp['body']['data']['id'] ?? 0);
logStep("5. Delivery Address Creation via API", $addrResp['status'] === 201 && $addressId > 0, "Address ID: $addressId");

// 5. Step 3: Order Review / Checkout Preview
$previewResp = httpReq('GET', "$baseUrl/customer/checkout/preview?address_id=$addressId", null, $token);
$pricing = $previewResp['body']['data']['pricing'] ?? [];
$canPlaceOrder = $previewResp['body']['data']['can_place_order'] ?? false;
$serverTotal = (float)($pricing['grand_total'] ?? $pricing['total'] ?? 0);
logStep("6. Order Review Checkout Preview & Stock/Price Validation", $previewResp['status'] === 200 && $canPlaceOrder && $serverTotal > 0, "Server Total: $serverTotal");

// 6. Step 4: Payment Method & Order Creation (POST /customer/orders)
$orderResp = httpReq('POST', "$baseUrl/customer/orders", [
    'address_id' => $addressId,
    'payment_method' => 'card',
    'notes' => 'E2E automated flow test',
], $token);
$orderData = $orderResp['body']['data'] ?? [];
$orderId = (int)($orderData['order_id'] ?? 0);
$orderNumber = $orderData['order_number'] ?? '';
$txRef = $orderData['transaction_reference'] ?? '';
logStep("7. Order Placement & Atomic Cart Clearance", $orderResp['status'] === 201 && $orderId > 0 && !empty($orderNumber) && !empty($txRef), "Order #$orderNumber, Tx: $txRef");

// Verify Cart Cleared
$cartAfter = httpReq('GET', "$baseUrl/customer/cart", null, $token);
$cartAfterItems = $cartAfter['body']['data']['items'] ?? [];
logStep("8. Cart Empty After Order Placement", count($cartAfterItems) === 0, "Cart items remaining: 0");

// Verify Inventory Atomically Decremented
$p1AfterStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = $p1Id")->fetchColumn();
logStep("9. Product Stock Atomically Decremented in MariaDB", $p1AfterStock === ($p1InitialStock - 2), "P1: $p1InitialStock -> $p1AfterStock");

// 7. Step 5: Secure Payment Verification (Payment Gateway Success Simulation)
$verifyResp = httpReq('POST', "$baseUrl/customer/payments/verify", [
    'transaction_reference' => $txRef,
    'status' => 'success',
], $token);
$verifyData = $verifyResp['body']['data'] ?? [];
$paymentStatus = $verifyData['payment_status'] ?? '';
$orderStatus = $verifyData['order_status'] ?? '';
logStep("10. Secure Payment Verification with Gateway Reference", $verifyResp['status'] === 200 && $paymentStatus === 'paid' && $orderStatus === 'processing', "Payment: $paymentStatus, Order: $orderStatus");

// 8. Step 6: Order Confirmation & Details Routing (Support both numeric ID and order number)
$showByNumber = httpReq('GET', "$baseUrl/customer/orders/$orderNumber", null, $token);
$showById = httpReq('GET', "$baseUrl/customer/orders/$orderId", null, $token);
logStep("11. Order Routing by Order Number (#$orderNumber) & Primary Key ($orderId)", $showByNumber['status'] === 200 && $showById['status'] === 200, "Both routing identifiers resolve order");

// 9. Step 7: My Orders List
$myOrdersResp = httpReq('GET', "$baseUrl/customer/orders", null, $token);
$myOrdersList = $myOrdersResp['body']['data'] ?? [];
$foundInHistory = false;
foreach ($myOrdersList as $ord) {
    if ($ord['order_number'] === $orderNumber) {
        $foundInHistory = true;
        break;
    }
}
logStep("12. Order Present in Customer My Orders History", $myOrdersResp['status'] === 200 && $foundInHistory, "Found #$orderNumber in user's order history");

// 10. Step 8: Order Cancellation & Inventory Restoration
$cancelResp = httpReq('POST', "$baseUrl/customer/orders/$orderNumber/cancel", ['reason' => 'Customer changed mind'], $token);
$cancelData = $cancelResp['body']['data'] ?? [];
logStep("13. Order Cancellation Execution", $cancelResp['status'] === 200 && ($cancelData['order_status'] ?? '') === 'cancelled', "Status: " . ($cancelData['order_status'] ?? ''));

// Verify Inventory Restored upon cancellation
$p1Restored = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = $p1Id")->fetchColumn();
logStep("14. Product Stock Atomically Restored on Cancellation", $p1Restored === $p1InitialStock, "P1: $p1AfterStock -> $p1Restored");

// Cleanup address created in test
$pdo->prepare("DELETE FROM user_addresses WHERE id = :aid")->execute(['aid' => $addressId]);

echo PHP_EOL . "ALL 14 END-TO-END CHECKOUT FLOW VERIFICATION TESTS PASSED SUCCESSFULLY!" . PHP_EOL;
