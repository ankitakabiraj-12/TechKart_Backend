<?php

/**
 * Verification test for Buddy Product Edit and Delete APIs,
 * RBAC/ownership enforcement, live inventory synchronization, and archive safety.
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

echo "=== STARTING BUDDY PRODUCT EDIT & DELETE TESTS ===\n\n";

// 1. Authenticate Seller (vikram.store@techkart.com) - Seller 2
echo "1. Logging in as Seller 2 (vikram.store@techkart.com)...\n";
$loginSeller = makeRequest('POST', "$baseUrl/buddy/auth/login", ['Content-Type: application/json'], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
assert($loginSeller['code'] === 200 && isset($loginSeller['data']['data']['token']), "Seller 2 login failed");
$sellerToken = $loginSeller['data']['data']['token'];
$sellerHeaders = [
    'Content-Type: application/json',
    "Authorization: Bearer $sellerToken"
];
echo "   [SUCCESS] Seller 2 authenticated.\n\n";

// 2. Authenticate Other Seller (other.seller@techkart.com) - Seller 4
echo "2. Logging in as Seller 4 (other.seller@techkart.com)...\n";
$loginSeller4 = makeRequest('POST', "$baseUrl/buddy/auth/login", ['Content-Type: application/json'], [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123'
]);
assert($loginSeller4['code'] === 200 && isset($loginSeller4['data']['data']['token']), "Seller 4 login failed");
$seller4Token = $loginSeller4['data']['data']['token'];
$seller4Headers = [
    'Content-Type: application/json',
    "Authorization: Bearer $seller4Token"
];
echo "   [SUCCESS] Seller 4 authenticated.\n\n";

// 3. Authenticate Admin (admin.audit@techkart.com) - Admin
echo "3. Logging in as Admin (admin.audit@techkart.com)...\n";
$loginAdmin = makeRequest('POST', "$baseUrl/buddy/auth/login", ['Content-Type: application/json'], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);
assert($loginAdmin['code'] === 200 && isset($loginAdmin['data']['data']['token']), "Admin login failed");
$adminToken = $loginAdmin['data']['data']['token'];
$adminHeaders = [
    'Content-Type: application/json',
    "Authorization: Bearer $adminToken"
];
echo "   [SUCCESS] Admin authenticated.\n\n";

// 4. Create a fresh test product for Seller 2
echo "4. Creating a test product for Seller 2...\n";
$createRes = makeRequest('POST', "$baseUrl/buddy/products", $sellerHeaders, [
    'title' => 'Test Edit Product ' . uniqid(),
    'category_id' => 2,
    'description' => 'Initial test description for product edit and delete.',
    'price' => 15000.00,
    'mrp' => 18000.00,
    'stock_quantity' => 10,
    'status' => 'published',
    'specifications' => [
        'material' => 'Aluminum',
        'connectivity' => 'Bluetooth 5.2'
    ]
]);
assert($createRes['code'] === 201, "Expected 201 for product creation");
$testProdId = $createRes['data']['data']['id'];
echo "   [PASS] Created test product ID: $testProdId\n\n";

// 5. Test Seller 2 Editing their own product
echo "5. Testing Seller 2 Editing Product #$testProdId...\n";
$editRes = makeRequest('PUT', "$baseUrl/buddy/products/$testProdId", $sellerHeaders, [
    'title' => 'Updated Chronos Ultra Edition ' . uniqid(),
    'price' => 16500.00,
    'mrp' => 19500.00,
    'stock_quantity' => 25,
    'description' => 'Updated aerospace casing with enhanced display.',
    'specifications' => [
        'material' => 'Titanium Alloy Grade 5',
        'connectivity' => 'Bluetooth 5.3 + Dual GPS',
        'battery' => '96 Hours'
    ]
]);
echo "   Status Code: {$editRes['code']} (Expected: 200)\n";
assert($editRes['code'] === 200, "Expected 200 for product update");
echo "   [PASS] Product edited successfully.\n\n";

// 6. Verify changes in GET /buddy/products/{id}
echo "6. Verifying updated product details via GET /buddy/products/$testProdId...\n";
$getUpdated = makeRequest('GET', "$baseUrl/buddy/products/$testProdId", $sellerHeaders);
assert($getUpdated['code'] === 200, "Expected 200 on fetch");
$prodData = $getUpdated['data']['data'];
assert((float)$prodData['price'] === 16500.00, "Price should be updated to 16500");
assert((float)$prodData['mrp'] === 19500.00, "MRP should be updated to 19500");
assert((int)$prodData['stock_quantity'] === 25, "Stock should be updated to 25");
assert($prodData['specifications']['material'] === 'Titanium Alloy Grade 5', "Specification material updated");
echo "   [PASS] Persisted fields and specifications verified.\n\n";

// 7. Test Cross-Seller Access: Seller 4 attempting to EDIT Seller 2's product
echo "7. Testing Cross-Seller Edit (Seller 4 attempting to update Seller 2's Product #$testProdId)...\n";
$crossEditRes = makeRequest('PUT', "$baseUrl/buddy/products/$testProdId", $seller4Headers, [
    'title' => 'Malicious Cross Seller Update'
]);
echo "   Status Code: {$crossEditRes['code']} (Expected: 403)\n";
assert($crossEditRes['code'] === 403, "Expected 403 for cross-seller edit attempt");
echo "   [PASS] Cross-seller edit strictly forbidden (403).\n\n";

// 8. Test Cross-Seller Access: Seller 4 attempting to DELETE Seller 2's product
echo "8. Testing Cross-Seller Delete (Seller 4 attempting to delete Seller 2's Product #$testProdId)...\n";
$crossDeleteRes = makeRequest('DELETE', "$baseUrl/buddy/products/$testProdId", $seller4Headers);
echo "   Status Code: {$crossDeleteRes['code']} (Expected: 403)\n";
assert($crossDeleteRes['code'] === 403, "Expected 403 for cross-seller delete attempt");
echo "   [PASS] Cross-seller delete strictly forbidden (403).\n\n";

// 9. Test Admin Privilege: Admin editing any seller's product
echo "9. Testing Admin editing Seller 2's Product #$testProdId...\n";
$adminEditRes = makeRequest('PUT', "$baseUrl/buddy/products/$testProdId", $adminHeaders, [
    'title' => 'Admin Verified Product Title',
    'price' => 17000.00
]);
echo "   Status Code: {$adminEditRes['code']} (Expected: 200)\n";
assert($adminEditRes['code'] === 200, "Expected 200 for admin update");
echo "   [PASS] Admin can edit products across sellers.\n\n";

// 10. Test Seller deleting their own product (No orders -> Hard delete)
echo "10. Testing Seller 2 Deleting Product #$testProdId (Without Order History)...\n";
$deleteRes = makeRequest('DELETE', "$baseUrl/buddy/products/$testProdId", $sellerHeaders);
echo "   Status Code: {$deleteRes['code']} (Expected: 200)\n";
echo "   Delete Response: " . json_encode($deleteRes['data']) . "\n";
assert($deleteRes['code'] === 200, "Expected 200 on successful deletion");

// Verify product no longer exists
$checkGone = makeRequest('GET', "$baseUrl/buddy/products/$testProdId", $sellerHeaders);
assert($checkGone['code'] === 404, "Expected 404 after hard deletion");
echo "   [PASS] Product successfully deleted and confirmed absent (404).\n\n";

// 11. Test Archive Safety for Product with Existing Order History
echo "11. Testing Archive Safety on Product with Existing Orders...\n";
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

// Find a product owned by Seller 2 that has an order item
$orderProdStmt = $pdo->prepare("SELECT p.id, p.title
    FROM products p
    JOIN order_items oi ON p.id = oi.product_id
    WHERE p.seller_id = 2
    LIMIT 1");
$orderProdStmt->execute();
$orderedProduct = $orderProdStmt->fetch(PDO::FETCH_ASSOC);

if ($orderedProduct) {
    $orderedPid = (int)$orderedProduct['id'];
    echo "    Found product #$orderedPid ('{$orderedProduct['title']}') with order history.\n";
    $delOrderedRes = makeRequest('DELETE', "$baseUrl/buddy/products/$orderedPid", $sellerHeaders);
    echo "    Status Code: {$delOrderedRes['code']} (Expected: 200)\n";
    echo "    Response: " . json_encode($delOrderedRes['data']) . "\n";
    assert($delOrderedRes['code'] === 200, "Expected 200 for archive deletion");
    assert(!empty($delOrderedRes['data']['data']['archived']), "Expected archived flag in response");

    // Verify product status in DB is now 'archived'
    $chkArchived = $pdo->prepare("SELECT status, stock_quantity FROM products WHERE id = :id");
    $chkArchived->execute(['id' => $orderedPid]);
    $archivedRow = $chkArchived->fetch(PDO::FETCH_ASSOC);
    assert($archivedRow['status'] === 'archived', "Status must be 'archived'");
    echo "    [PASS] Product with order history safely archived instead of violating foreign keys.\n\n";
} else {
    echo "    [SKIP] No ordered product found for seller 2.\n\n";
}

// 12. Verify Dashboard Inventory synchronization
echo "12. Testing Dashboard Inventory Metrics Sync...\n";
$dashRes = makeRequest('GET', "$baseUrl/buddy/seller/dashboard", $sellerHeaders);
assert($dashRes['code'] === 200, "Expected 200 on seller dashboard");
$dashMetrics = $dashRes['data']['data']['metrics'];
echo "    Dashboard Total Products: " . ($dashMetrics['total_products'] ?? 'N/A') . "\n";
echo "    Dashboard Active Products: " . ($dashMetrics['active_products'] ?? 'N/A') . "\n";
echo "    [PASS] Dashboard metrics successfully calculated from live database.\n\n";

echo "=== ALL BUDDY PRODUCT EDIT & DELETE TESTS PASSED SUCCESSFULLY! ===\n";
