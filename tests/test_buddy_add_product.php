<?php

/**
 * Verification test for Buddy Product Add API and Ownership Enforcement
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($response, true)];
}

echo "=== STARTING BUDDY PRODUCT ADD API TESTS ===\n\n";

// 1. Login as Seller (vikram.store@techkart.com)
echo "1. Logging in as Seller (vikram.store@techkart.com)...\n";
$loginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", ['Content-Type: application/json'], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);

if ($loginRes['code'] !== 200 || !isset($loginRes['data']['data']['token'])) {
    die("FATAL: Seller login failed! Code: {$loginRes['code']}, Res: " . json_encode($loginRes['data']) . "\n");
}
$sellerToken = $loginRes['data']['data']['token'];
$sellerHeaders = [
    'Content-Type: application/json',
    "Authorization: Bearer $sellerToken"
];
echo "   [SUCCESS] Seller authenticated. Token received.\n\n";

// 2. Login as Admin (admin.audit@techkart.com)
echo "2. Logging in as Admin (admin.audit@techkart.com)...\n";
$adminLoginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", ['Content-Type: application/json'], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
if ($adminLoginRes['code'] !== 200 || !isset($adminLoginRes['data']['data']['token'])) {
    die("FATAL: Admin login failed! Code: {$adminLoginRes['code']}, Res: " . json_encode($adminLoginRes['data']) . "\n");
}
$adminToken = $adminLoginRes['data']['data']['token'];
$adminHeaders = [
    'Content-Type: application/json',
    "Authorization: Bearer $adminToken"
];
echo "   [SUCCESS] Admin authenticated. Token received.\n\n";

// 3. Test Unauthenticated Access
echo "3. Testing Unauthenticated POST /buddy/products...\n";
$unauthRes = makeRequest('POST', "$baseUrl/buddy/products", ['Content-Type: application/json'], [
    'title' => 'Unauthorized Product',
    'price' => 1000
]);
echo "   Status Code: {$unauthRes['code']} (Expected: 401)\n";
assert($unauthRes['code'] === 401, "Expected 401 for unauthenticated request");
echo "   [PASS] Unauthenticated access properly rejected.\n\n";

// 4. Test Validation Failures
echo "4. Testing Validation Failures (Negative price, MRP < price, Short title)...\n";
$valRes = makeRequest('POST', "$baseUrl/buddy/products", $sellerHeaders, [
    'title' => 'AB', // too short
    'price' => -50,  // negative price
    'mrp'   => 10,   // mrp < price
    'status' => 'invalid_status'
]);
echo "   Status Code: {$valRes['code']} (Expected: 422)\n";
echo "   Errors: " . json_encode($valRes['data']['errors'] ?? []) . "\n";
assert($valRes['code'] === 422, "Expected 422 for validation error");
assert(isset($valRes['data']['errors']['title']), "Title validation error expected");
assert(isset($valRes['data']['errors']['price']), "Price validation error expected");
echo "   [PASS] Input validation enforced accurately.\n\n";

// 5. Test Seller Successfully Adds Product with Specifications
echo "5. Testing Seller Adding Product with Specifications & Details...\n";
$uniqueTitle = 'Chronos Pulse Watch ' . uniqid();
$productPayload = [
    'title'          => $uniqueTitle,
    'category_id'    => 2, // Wearables & Smartwatches
    'description'    => 'Aerospace titanium casing with sapphire glass and AMOLED 3000-nit display.',
    'specifications' => [
        'case_material'    => 'Grade 5 Titanium',
        'water_resistance' => '100m (10 ATM)',
        'connectivity'     => 'BT 5.3 + Dual GPS',
        'battery_life'     => '72 hours'
    ],
    'price'          => 42900.00,
    'mrp'            => 49900.00,
    'stock_quantity' => 12,
    'status'         => 'published',
    'images'         => [
        'https://images.unsplash.com/photo-1579586337278-3befd40fd17a?w=800&q=80',
        'https://images.unsplash.com/photo-1508685096489-7aacd43bd3b1?w=800&q=80'
    ]
];

$addRes = makeRequest('POST', "$baseUrl/buddy/products", $sellerHeaders, $productPayload);
echo "   Status Code: {$addRes['code']} (Expected: 201)\n";
echo "   Created Product: " . json_encode($addRes['data']['data'] ?? []) . "\n";
assert($addRes['code'] === 201, "Expected 201 for successful product creation");
$createdProduct = $addRes['data']['data'];
$productId = $createdProduct['id'];
assert((int)$createdProduct['seller_id'] === 2, "Product seller_id must match authenticated seller #2");
assert((float)$createdProduct['price'] === 42900.00, "Price must match");
assert((int)$createdProduct['stock_quantity'] === 12, "Stock must match");
echo "   [PASS] Seller product created with correct ownership and specifications.\n\n";

// 6. Test Retrieval of the Created Product
echo "6. Testing GET /buddy/products/$productId...\n";
$getRes = makeRequest('GET', "$baseUrl/buddy/products/$productId", $sellerHeaders);
echo "   Status Code: {$getRes['code']} (Expected: 200)\n";
$retrievedProduct = $getRes['data']['data'];
echo "   Retrieved Title: {$retrievedProduct['title']}\n";
echo "   Retrieved Specifications: " . json_encode($retrievedProduct['specifications'] ?? []) . "\n";
assert($getRes['code'] === 200, "Expected 200 for product retrieval");
assert(isset($retrievedProduct['specifications']['case_material']), "Specifications JSON must be decoded");
assert($retrievedProduct['specifications']['case_material'] === 'Grade 5 Titanium', "Spec case_material mismatch");
echo "   [PASS] Product specifications and inventory persisted and retrieved.\n\n";

// 7. Test Cross-Seller Ownership Enforcement
echo "7. Testing Cross-Seller Ownership Enforcement (Seller attempting to update Seller 4 product)...\n";
// Product 2 belongs to seller 2; let's check product owned by seller 4
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();
$s4ProdId = $pdo->query("SELECT id FROM products WHERE seller_id = 4 LIMIT 1")->fetchColumn();

if ($s4ProdId) {
    $crossRes = makeRequest('PUT', "$baseUrl/buddy/products/$s4ProdId", $sellerHeaders, [
        'title' => 'Hacked Product Title'
    ]);
    echo "   Status Code: {$crossRes['code']} (Expected: 403)\n";
    assert($crossRes['code'] === 403, "Expected 403 when updating another seller's product");
    echo "   [PASS] Cross-seller access forbidden (403).\n\n";
} else {
    echo "   [SKIP] No seller 4 product found to test cross-access.\n\n";
}

// 8. Test Admin Product Creation with seller_id delegation
echo "8. Testing Admin Product Creation with delegated seller_id = 4...\n";
$adminAddRes = makeRequest('POST', "$baseUrl/buddy/products", $adminHeaders, [
    'seller_id'      => 4,
    'title'          => 'Admin Added Product ' . uniqid(),
    'category_id'    => 3,
    'price'          => 5500.00,
    'mrp'            => 6000.00,
    'stock_quantity' => 20,
    'status'         => 'published'
]);
echo "   Status Code: {$adminAddRes['code']} (Expected: 201)\n";
assert($adminAddRes['code'] === 201, "Expected 201 for admin product creation");
assert((int)$adminAddRes['data']['data']['seller_id'] === 4, "Admin created product must be assigned to seller 4");
echo "   [PASS] Admin successfully added product for delegated seller.\n\n";

// 9. Test Admin Product Creation without seller_id (Must fail with 422)
echo "9. Testing Admin Product Creation without seller_id...\n";
$adminNoSellerRes = makeRequest('POST', "$baseUrl/buddy/products", $adminHeaders, [
    'title' => 'Admin Product Without Seller',
    'price' => 5000
]);
echo "   Status Code: {$adminNoSellerRes['code']} (Expected: 422)\n";
assert($adminNoSellerRes['code'] === 422, "Expected 422 when admin omits seller_id");
echo "   [PASS] Admin required seller_id validation passed.\n\n";

echo "=== ALL BUDDY PRODUCT ADD API TESTS PASSED SUCCESSFULLY! ===\n";
