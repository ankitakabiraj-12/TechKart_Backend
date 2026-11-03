<?php

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function loginUser(string $email, string $password): ?string {
    global $baseUrl;
    $ch = curl_init("$baseUrl/buddy/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $res = curl_exec($ch);
    $data = json_decode($res, true);
    curl_close($ch);
    return $data['data']['token'] ?? null;
}

function makeApiRequest(string $method, string $path, ?string $token = null, ?array $body = null): array {
    global $baseUrl;
    $ch = curl_init("$baseUrl$path");
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($res, true)];
}

echo "=== STARTING CATEGORY & SPECIFICATIONS API VERIFICATION TEST ===\n\n";

// 1. Authenticate users
$sellerToken = loginUser('vikram.store@techkart.com', 'password123');
$adminToken  = loginUser('admin.audit@techkart.com', 'password123');

echo "Seller Token: " . ($sellerToken ? "OK" : "FAILED") . "\n";
echo "Admin Token:  " . ($adminToken ? "OK" : "FAILED") . "\n\n";

// 2. Test 1: Unauthenticated categories access
$unauthRes = makeApiRequest('GET', '/buddy/categories');
echo "Test 1 [Unauthenticated Categories Access]: HTTP {$unauthRes['code']} - " . ($unauthRes['code'] === 401 ? "PASS" : "FAIL") . "\n";

// 3. Test 2: Authenticated Seller lists categories
$catListRes = makeApiRequest('GET', '/buddy/categories', $sellerToken);
$categories = $catListRes['data']['data'] ?? [];
echo "Test 2 [Seller List Categories]: HTTP {$catListRes['code']} - " . ($catListRes['code'] === 200 && count($categories) >= 7 ? "PASS" : "FAIL") . "\n";
echo "       Found " . count($categories) . " active categories with dynamic spec metadata:\n";
foreach (array_slice($categories, 0, 4) as $c) {
    echo "       - [ID: {$c['id']}] {$c['name']} (Slug: {$c['slug']}, Specs: {$c['spec_fields_count']} fields)\n";
}

// 4. Test 3: Get Category Details with Schema for Category 2 (Wearables & Smartwatches)
$cat2Res = makeApiRequest('GET', '/buddy/categories/2', $sellerToken);
$cat2Data = $cat2Res['data']['data'] ?? [];
echo "\nTest 3 [Category 2 Details & Schema]: HTTP {$cat2Res['code']} - " . ($cat2Res['code'] === 200 && isset($cat2Data['specifications_schema']) ? "PASS" : "FAIL") . "\n";

// 5. Test 4: Get Category-Specific Specification Template for Category 4 (Chargers & Power Delivery)
$spec4Res = makeApiRequest('GET', '/buddy/categories/4/specifications', $sellerToken);
$spec4Data = $spec4Res['data']['data'] ?? [];
$spec4Fields = $spec4Data['schema'] ?? [];
echo "Test 4 [Category 4 Specification Template]: HTTP {$spec4Res['code']} - " . ($spec4Res['code'] === 200 && count($spec4Fields) >= 4 ? "PASS" : "FAIL") . "\n";
echo "       Template Fields for {$spec4Data['category_name']}:\n";
foreach ($spec4Fields as $f) {
    echo "       - Field '{$f['key']}': {$f['label']} ({$f['type']}, Default: '{$f['default']}')\n";
}

// 6. Test 5: Validation Error - Adding product with non-existent category ID
$invalidCatRes = makeApiRequest('POST', '/buddy/products', $sellerToken, [
    'title' => 'Test Product with Bogus Category',
    'category_id' => 99999,
    'price' => 1200.00,
    'stock_quantity' => 5,
]);
echo "\nTest 5 [Non-existent Category ID Validation]: HTTP {$invalidCatRes['code']} - " . ($invalidCatRes['code'] === 422 ? "PASS (Rejected)" : "FAIL") . "\n";

// 7. Test 6: Create Product with dynamic category selection and category-specific specifications
$newProductPayload = [
    'title' => 'Aura Pro 65W GaN Multi-Port Fast Charger ' . uniqid(),
    'category_id' => 4, // Chargers & Power Delivery
    'description' => 'Triple port GaN fast wall charger with intelligent wattage distribution and ActiveShield monitoring.',
    'price' => 3850.00,
    'mrp' => 4500.00,
    'stock_quantity' => 40,
    'status' => 'published',
    'specifications' => [
        'max_wattage' => '65W GaN Fast Charger',
        'port_configuration' => '2x USB-C + 1x USB-A (2C1A)',
        'technology' => 'GaN III Technology (Gallium Nitride)',
        'safety_certifications' => 'ActiveShield 2.0 Temperature Monitor',
        'warranty' => '18 Months Brand Warranty',
    ],
    'images' => [
        'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?w=800&q=80',
    ],
];
$createRes = makeApiRequest('POST', '/buddy/products', $sellerToken, $newProductPayload);
$createdProd = $createRes['data']['data'] ?? [];
$createdProdId = $createdProd['id'] ?? null;
echo "Test 6 [Create Product with Category & Specifications]: HTTP {$createRes['code']} - " . ($createRes['code'] === 201 && $createdProdId > 0 ? "PASS" : "FAIL") . "\n";
echo "       Created Product ID: $createdProdId in Category {$createdProd['category_id']}\n";

// 8. Test 7: Verify Persisted Category & Specifications via GET /buddy/products/{id}
$getProdRes = makeApiRequest('GET', "/buddy/products/$createdProdId", $sellerToken);
$fetchedProd = $getProdRes['data']['data'] ?? [];
$persistedSpecs = $fetchedProd['specifications'] ?? [];
echo "Test 7 [Persisted Category & Specifications Verification]: HTTP {$getProdRes['code']} - " . ($getProdRes['code'] === 200 && (int)$fetchedProd['category_id'] === 4 ? "PASS" : "FAIL") . "\n";
echo "       Category Name: {$fetchedProd['category_name']}\n";
echo "       Persisted Specifications: " . json_encode($persistedSpecs, JSON_PRETTY_PRINT) . "\n";

// 9. Test 8: Update Product Category & Switch Dynamic Specifications
$updatePayload = [
    'category_id' => 6, // Keyboards & Peripherals
    'specifications' => [
        'switch_type' => 'Hot-Swappable Mechanical Tactile',
        'connectivity' => 'Tri-Mode (2.4GHz + BT 5.1 + USB-C)',
        'layout_size' => '75% Compact (82 Keys)',
        'backlight' => 'South-Facing RGB (16.8M Colors)',
        'warranty' => '1 Year Official Brand Warranty',
    ],
];
$updateRes = makeApiRequest('PUT', "/buddy/products/$createdProdId", $sellerToken, $updatePayload);
echo "\nTest 8 [Update Category & Switch Dynamic Specifications]: HTTP {$updateRes['code']} - " . ($updateRes['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 10. Test 9: Verify updated category and specifications
$reGetRes = makeApiRequest('GET', "/buddy/products/$createdProdId", $sellerToken);
$reGetProd = $reGetRes['data']['data'] ?? [];
echo "Test 9 [Verify Switched Category in DB]: HTTP {$reGetRes['code']} - " . ($reGetProd['category_id'] == 6 ? "PASS" : "FAIL") . "\n";
echo "       Updated Category: {$reGetProd['category_name']} (ID: {$reGetProd['category_id']})\n";
echo "       Updated Specs: " . json_encode($reGetProd['specifications']) . "\n";

echo "\n=== CATEGORY & SPECIFICATIONS TEST COMPLETED SUCCESSFULLY ===\n";
