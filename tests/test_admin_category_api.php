<?php
/**
 * Test Admin Category Create/Update/Delete APIs with validation and RBAC
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== TECHKART BUDDY ADMIN CATEGORY API TEST ===\n\n";

// 1. Admin Login
echo "[1] Admin Login...\n";
$loginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

$adminToken = $loginRes['body']['token'] ?? $loginRes['body']['data']['token'] ?? null;
if (!$adminToken) {
    echo "FAILED: Admin login failed. Response: " . $loginRes['raw'] . "\n";
    exit(1);
}
$adminHeaders = ["Authorization: Bearer $adminToken"];
echo "  SUCCESS: Admin logged in.\n\n";

// 2. Authorization / RBAC check: non-admin (Seller) rejected with 403
echo "[2] Seller RBAC check on admin category endpoints...\n";
$sellerLogin = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
$sellerToken = $sellerLogin['body']['token'] ?? $sellerLogin['body']['data']['token'] ?? null;
if ($sellerToken) {
    $forbiddenGet = makeRequest('GET', "$baseUrl/buddy/admin/categories", ["Authorization: Bearer $sellerToken"]);
    $forbiddenPost = makeRequest('POST', "$baseUrl/buddy/admin/categories", ["Authorization: Bearer $sellerToken"], ['name' => 'Hacked Category']);
    if ($forbiddenGet['code'] === 403 && $forbiddenPost['code'] === 403) {
        echo "  SUCCESS: Seller correctly rejected with 403 Forbidden on category admin endpoints.\n\n";
    } else {
        echo "  FAILED: Expected 403 Forbidden for seller, got GET:{$forbiddenGet['code']}, POST:{$forbiddenPost['code']}.\n\n";
        exit(1);
    }
}

// 3. Unauthenticated request rejected with 401
echo "[3] Unauthenticated request check...\n";
$unauthRes = makeRequest('GET', "$baseUrl/buddy/admin/categories");
if ($unauthRes['code'] === 401) {
    echo "  SUCCESS: Unauthenticated request rejected with 401 Unauthorized.\n\n";
} else {
    echo "  FAILED: Expected 401 Unauthorized, got {$unauthRes['code']}.\n\n";
    exit(1);
}

// 4. Admin List Categories with Search & Pagination
echo "[4] Admin List Categories...\n";
$listRes = makeRequest('GET', "$baseUrl/buddy/admin/categories?page=1&limit=20", $adminHeaders);
if ($listRes['code'] !== 200 || !($listRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not retrieve categories. Response: {$listRes['raw']}\n";
    exit(1);
}
$data = $listRes['body']['data'];
$categories = $data['categories'];
$metrics = $data['metrics'];
$pagination = $data['pagination'];

echo "  SUCCESS: Categories directory retrieved.\n";
echo "  - Total Categories: {$metrics['total_categories']}\n";
echo "  - Total Roots: {$metrics['total_roots']}\n";
echo "  - Total Subcategories: {$metrics['total_subcategories']}\n";
echo "  - Returned: " . count($categories) . " (Total in DB: {$pagination['total']})\n\n";

// 5. Test Search & Filters
echo "[5] Testing Category Search & Filter Queries...\n";
$searchAudio = makeRequest('GET', "$baseUrl/buddy/admin/categories?search=Audio", $adminHeaders);
$audioCats = $searchAudio['body']['data']['categories'] ?? [];
echo "  - Search 'Audio': " . count($audioCats) . " found.\n";
if (empty($audioCats) || stripos($audioCats[0]['name'], 'Audio') === false) {
    echo "    FAILED: Search for 'Audio' did not return Audio category.\n";
    exit(1);
}

$rootFilter = makeRequest('GET', "$baseUrl/buddy/admin/categories?parent_id=root", $adminHeaders);
$rootCats = $rootFilter['body']['data']['categories'] ?? [];
echo "  - Filter 'parent_id=root': " . count($rootCats) . " root node(s) found.\n";
foreach ($rootCats as $rc) {
    if ($rc['parent_id'] !== null) {
        echo "    FAILED: Category {$rc['name']} has parent_id={$rc['parent_id']}, expected null\n";
        exit(1);
    }
}
echo "  SUCCESS: Search and parent filtering validated.\n\n";

// 6. Test Category Validation on Create
echo "[6] Testing Create Category Validation...\n";
$emptyNameRes = makeRequest('POST', "$baseUrl/buddy/admin/categories", $adminHeaders, ['name' => '   ']);
if ($emptyNameRes['code'] === 422) {
    echo "  SUCCESS: Empty category name rejected with 422 Unprocessable Entity.\n";
} else {
    echo "  FAILED: Expected 422 for empty name, got {$emptyNameRes['code']}.\n";
    exit(1);
}

$invalidParentRes = makeRequest('POST', "$baseUrl/buddy/admin/categories", $adminHeaders, [
    'name' => 'Test Subcategory',
    'parent_id' => 999999
]);
if ($invalidParentRes['code'] === 400) {
    echo "  SUCCESS: Non-existent parent_id rejected with 400 Bad Request.\n\n";
} else {
    echo "  FAILED: Expected 400 for invalid parent_id, got {$invalidParentRes['code']}.\n";
    exit(1);
}

// 7. Test Admin Category CRUD Flow
echo "[7] Testing Full Category Lifecycle (Create -> Update -> Delete Guard -> Delete)...\n";

// 7a. Create Category
$createRes = makeRequest('POST', "$baseUrl/buddy/admin/categories", $adminHeaders, [
    'name' => 'Gaming Consoles & VR',
    'slug' => 'gaming-consoles-vr',
    'parent_id' => 1, // Electronics
    'is_active' => 1
]);
if ($createRes['code'] !== 201 || !($createRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not create category. Response: {$createRes['raw']}\n";
    exit(1);
}
$createdCat = $createRes['body']['data'];
$catId = (int)$createdCat['id'];
echo "  - Created Category #$catId: {$createdCat['name']} (Slug: {$createdCat['slug']})\n";

// 7b. Update Category
$updateRes = makeRequest('PUT', "$baseUrl/buddy/admin/categories/$catId", $adminHeaders, [
    'name' => 'Gaming Consoles, Handhelds & VR',
    'slug' => 'gaming-consoles-vr-pro',
    'parent_id' => 1,
    'is_active' => 1
]);
if ($updateRes['code'] !== 200 || !($updateRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not update category #$catId. Response: {$updateRes['raw']}\n";
    exit(1);
}
echo "  - Updated Category #$catId: {$updateRes['body']['data']['name']}\n";

// 7c. Deletion Guard Check (Category with active products cannot be deleted)
$guardCheckRes = makeRequest('DELETE', "$baseUrl/buddy/admin/categories/1", $adminHeaders);
if ($guardCheckRes['code'] === 400) {
    echo "  - Guard verified: Category 1 with active listings blocked from deletion: " . $guardCheckRes['body']['message'] . "\n";
} else {
    echo "  FAILED: Expected 400 Bad Request when deleting category with products, got {$guardCheckRes['code']}.\n";
    exit(1);
}

// 7d. Delete Newly Created Category (No products bound)
$deleteRes = makeRequest('DELETE', "$baseUrl/buddy/admin/categories/$catId", $adminHeaders);
if ($deleteRes['code'] !== 200 || !($deleteRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not delete category #$catId. Response: {$deleteRes['raw']}\n";
    exit(1);
}
echo "  - Deleted Category #$catId: {$deleteRes['body']['message']}\n";

// Verify deletion in DB
$verifyDeleteRes = makeRequest('GET', "$baseUrl/buddy/categories/$catId", $adminHeaders);
if ($verifyDeleteRes['code'] === 404) {
    echo "  - Verified category #$catId no longer exists in database.\n";
} else {
    echo "  FAILED: Expected 404 for deleted category, got {$verifyDeleteRes['code']}.\n";
    exit(1);
}

echo "\n=== ALL ADMIN CATEGORY TESTS PASSED ===\n";
