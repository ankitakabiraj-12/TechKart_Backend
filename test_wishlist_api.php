<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Jwt;

$pdo = Database::getConnection();
$baseUrl = 'http://127.0.0.1:8000/api/v1';

function logResult(string $testName, bool $passed, string $detail = ''): void {
    if ($passed) {
        echo "[PASS] $testName" . ($detail ? " -> $detail" : "") . PHP_EOL;
    } else {
        echo "[FAIL] $testName" . ($detail ? " -> $detail" : "") . PHP_EOL;
        exit(1);
    }
}

function httpCall(string $method, string $url, ?array $body = null, ?string $token = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
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

// 1. Get customer
$customer = $pdo->query("SELECT * FROM users WHERE role = 'customer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    die("No customer found for test.\n");
}
$customerId = (int)$customer['id'];
$token = Jwt::generate([
    'sub'   => $customerId,
    'email' => $customer['email'],
    'role'  => 'customer',
]);

// Clear wishlist initially
$pdo->prepare("DELETE FROM wishlist_items WHERE customer_id = :cid")->execute(['cid' => $customerId]);

// 2. Fetch active product
$product = $pdo->query("SELECT id, title, price, stock_quantity FROM products WHERE status = 'published' AND stock_quantity > 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prodId = (int)$product['id'];

// Test 1: GET empty wishlist
$getEmpty = httpCall('GET', "$baseUrl/customer/wishlist", null, $token);
logResult("1. GET /customer/wishlist (Empty)", $getEmpty['status'] === 200 && count($getEmpty['body']['data']['items']) === 0, "Items: 0");

// Test 2: POST /customer/wishlist (Add product)
$addResp = httpCall('POST', "$baseUrl/customer/wishlist", ['product_id' => $prodId], $token);
$itemsAfterAdd = $addResp['body']['data']['items'] ?? [];
logResult("2. POST /customer/wishlist (Add)", $addResp['status'] === 201 && count($itemsAfterAdd) === 1 && $itemsAfterAdd[0]['product_id'] === $prodId, "Added Product #$prodId");

// Test 3: Idempotent Add / Duplicate handling
$addDupResp = httpCall('POST', "$baseUrl/customer/wishlist", ['product_id' => $prodId], $token);
logResult("3. POST /customer/wishlist (Duplicate Upsert)", $addDupResp['status'] === 201 && count($addDupResp['body']['data']['items']) === 1, "Count remained 1");

// Test 4: DELETE by product ID
$delResp = httpCall('DELETE', "$baseUrl/customer/wishlist/$prodId", null, $token);
logResult("4. DELETE /customer/wishlist/{id} (Remove by Product ID)", $delResp['status'] === 200 && count($delResp['body']['data']['items']) === 0, "Removed");

// Test 5: Re-add and Clear
httpCall('POST', "$baseUrl/customer/wishlist", ['product_id' => $prodId], $token);
$clearResp = httpCall('DELETE', "$baseUrl/customer/wishlist", null, $token);
logResult("5. DELETE /customer/wishlist (Clear all)", $clearResp['status'] === 200 && count($clearResp['body']['data']['items']) === 0, "Cleared");

// Test 6: Unauthorized access rejected
$unauth = httpCall('GET', "$baseUrl/customer/wishlist");
logResult("6. GET /customer/wishlist (Unauthenticated returns 401)", $unauth['status'] === 401, "Status: 401");

echo PHP_EOL . "ALL BACKEND WISHLIST TESTS PASSED!" . PHP_EOL;
