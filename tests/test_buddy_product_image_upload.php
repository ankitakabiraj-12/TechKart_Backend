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

echo "=== STARTING PRODUCT IMAGE UPLOAD TEST ===\n\n";

// 1. Authenticate users
$seller2Token = loginUser('vikram.store@techkart.com', 'password123');
$seller4Token = loginUser('other.seller@techkart.com', 'password123');
$adminToken   = loginUser('admin.audit@techkart.com', 'password123');

echo "Seller 2 Token: " . ($seller2Token ? "OK" : "FAILED") . "\n";
echo "Seller 4 Token: " . ($seller4Token ? "OK" : "FAILED") . "\n";
echo "Admin Token: " . ($adminToken ? "OK" : "FAILED") . "\n\n";

// 2. Test 1: Unauthenticated upload attempt
$ch = curl_init("$baseUrl/buddy/products/upload-image");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Test 1 [Unauthenticated Upload]: HTTP $httpCode - " . ($httpCode === 401 ? "PASS" : "FAIL") . "\n";

// 3. Test 2: Authenticated but missing files
$ch = curl_init("$baseUrl/buddy/products/upload-image");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Test 2 [Missing Files Validation]: HTTP $httpCode - " . ($httpCode === 422 ? "PASS" : "FAIL") . "\n";

// 4. Test 3: Disallowed file type (.txt / text file disguised)
$invalidFile = sys_get_temp_dir() . '/test_script.txt';
file_put_contents($invalidFile, "This is not an image file.");
$cFile = new CURLFile($invalidFile, 'text/plain', 'test_script.txt');

$ch = curl_init("$baseUrl/buddy/products/upload-image");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'images[0]' => $cFile,
    ],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Test 3 [Invalid File Type Rejection]: HTTP $httpCode - " . ($httpCode === 422 ? "PASS" : "FAIL") . "\n";

// 5. Create a valid 1x1 PNG image for testing
$validPngPath = sys_get_temp_dir() . '/test_product.png';
$pngData = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==");
file_put_contents($validPngPath, $pngData);

// 6. Test 4: Standalone upload by Seller 2 (single image & multiple images support)
$cFileValid = new CURLFile($validPngPath, 'image/png', 'product_shot.png');
$ch = curl_init("$baseUrl/buddy/products/upload-image");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'images[0]' => $cFileValid,
    ],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$json = json_decode($res, true);
curl_close($ch);
$uploadedUrl = $json['data']['uploaded'][0]['url'] ?? null;
echo "Test 4 [Standalone Image Upload]: HTTP $httpCode - " . ($httpCode === 201 && !empty($uploadedUrl) ? "PASS" : "FAIL") . "\n";
echo "       Uploaded URL: $uploadedUrl\n";

// Verify file is accessible via web server
if ($uploadedUrl) {
    $fileCh = curl_init($uploadedUrl);
    curl_setopt($fileCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($fileCh, CURLOPT_NOBODY, true);
    curl_exec($fileCh);
    $fileHttp = curl_getinfo($fileCh, CURLINFO_HTTP_CODE);
    curl_close($fileCh);
    echo "       Direct URL HTTP Access Check: HTTP $fileHttp - " . ($fileHttp === 200 ? "PASS" : "FAIL") . "\n";
}

// 7. Test 5: Seller 2 uploading image for their own product (Get Seller 2 product ID first)
$ch = curl_init("$baseUrl/buddy/products");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$prodsData = json_decode($res, true);
curl_close($ch);
$seller2ProductId = $prodsData['data'][0]['id'] ?? 2;

$cFileProd = new CURLFile($validPngPath, 'image/png', 'product_angle_1.png');
$ch = curl_init("$baseUrl/buddy/products/$seller2ProductId/images");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'images[0]' => $cFileProd,
    ],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$json = json_decode($res, true);
curl_close($ch);
$persistedUrl = $json['data']['uploaded'][0]['url'] ?? null;
echo "Test 5 [Product-associated Upload by Owner]: HTTP $httpCode - " . ($httpCode === 201 && !empty($persistedUrl) ? "PASS" : "FAIL") . "\n";
echo "       Persisted to Product ID $seller2ProductId: $persistedUrl\n";

// 8. Test 6: Cross-seller authorization check (Seller 4 attempting to upload to Seller 2's product)
$cFileHacker = new CURLFile($validPngPath, 'image/png', 'hacked_pic.png');
$ch = curl_init("$baseUrl/buddy/products/$seller2ProductId/images");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'images[0]' => $cFileHacker,
    ],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller4Token"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Test 6 [Cross-seller Upload RBAC]: HTTP $httpCode - " . ($httpCode === 403 ? "PASS (Forbidden)" : "FAIL") . "\n";

// 9. Test 7: Admin uploading image to Seller 2's product (Admin privilege)
$cFileAdmin = new CURLFile($validPngPath, 'image/png', 'admin_curated.png');
$ch = curl_init("$baseUrl/buddy/products/$seller2ProductId/images");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'images[0]' => $cFileAdmin,
    ],
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $adminToken"],
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Test 7 [Admin Upload for Any Product]: HTTP $httpCode - " . ($httpCode === 201 ? "PASS" : "FAIL") . "\n";

// 10. Check MariaDB persistence for Product ID
$ch = curl_init("$baseUrl/buddy/products/$seller2ProductId");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $seller2Token"],
]);
$res = curl_exec($ch);
$prodDetail = json_decode($res, true);
curl_close($ch);
$imagesList = $prodDetail['data']['images'] ?? [];

echo "\nLive API Persisted `product_images` for Product $seller2ProductId (Count: " . count($imagesList) . "):\n";
foreach ($imagesList as $r) {
    echo " - ID: {$r['id']} | Primary: " . ($r['is_primary'] ? '1' : '0') . " | Sort: {$r['sort_order']} | URL: {$r['image_url']}\n";
}

echo "\n=== PRODUCT IMAGE UPLOAD TEST COMPLETE ===\n";
