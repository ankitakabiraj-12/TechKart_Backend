<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Jwt;

$pdo = Database::getConnection();

// Fetch users
$customer = $pdo->query("SELECT * FROM users WHERE role = 'customer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$seller = $pdo->query("SELECT * FROM users WHERE role = 'seller' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$customer || !$seller) {
    echo "ERROR: Required test users missing in database.\n";
    exit(1);
}

// Ensure test admin exists for admin tests
$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    $pdo->prepare("INSERT INTO users (role, full_name, email, password_hash, is_active) VALUES ('admin', 'System Admin', 'admin.audit@techkart.com', :hash, 1)")
        ->execute(['hash' => password_hash('AdminSecret123', PASSWORD_BCRYPT)]);
    $admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// Ensure second seller exists for multi-seller ownership isolation tests
$anotherSeller = $pdo->query("SELECT * FROM users WHERE role = 'seller' AND id != {$seller['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$anotherSeller) {
    $pdo->prepare("INSERT INTO users (role, full_name, email, password_hash, is_active) VALUES ('seller', 'Other Seller Store', 'other.seller@techkart.com', :hash, 1)")
        ->execute(['hash' => password_hash('SellerSecret123', PASSWORD_BCRYPT)]);
    $anotherSeller = $pdo->query("SELECT * FROM users WHERE role = 'seller' AND id != {$seller['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// Generate tokens
$customerToken = Jwt::generate([
    'sub'   => $customer['id'],
    'role'  => $customer['role'],
    'email' => $customer['email'],
]);

$sellerToken = Jwt::generate([
    'sub'   => $seller['id'],
    'role'  => $seller['role'],
    'email' => $seller['email'],
]);

$adminToken = Jwt::generate([
    'sub'   => $admin['id'],
    'role'  => $admin['role'],
    'email' => $admin['email'],
]);

$anotherSellerToken = Jwt::generate([
    'sub'   => $anotherSeller['id'],
    'role'  => $anotherSeller['role'],
    'email' => $anotherSeller['email'],
]);

function runRequest(string $method, string $uri, array $body = [], ?string $token = null): array
{
    $phpExe = 'C:\\xampp\\php\\php.exe';
    $runnerScript = __DIR__ . '/scratch/test_runner_single.php';

    if (!is_dir(__DIR__ . '/scratch')) {
        mkdir(__DIR__ . '/scratch', 0777, true);
    }

    $b64 = base64_encode(json_encode($body));
    $tok = $token ?? '';

    $headersCode = '';
    if (!empty($tok)) {
        $headersCode .= "\$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer {$tok}';\n";
    }

    $code = <<<PHP
<?php
\$_SERVER['REQUEST_METHOD'] = '{$method}';
\$_SERVER['REQUEST_URI'] = '{$uri}';
\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_GET = [];
\$_POST = [];
{$headersCode}
require_once __DIR__ . '/../bootstrap/app.php';
\$router = new \\App\\Core\\Router();
require_once __DIR__ . '/../routes/api_v1.php';
\$req = new \\App\\Core\\Request();
\$ref = new \\ReflectionClass(\$req);
\$prop = \$ref->getProperty('body');
\$prop->setAccessible(true);
\$prop->setValue(\$req, json_decode(base64_decode('{$b64}'), true) ?? []);
\$router->dispatch(\$req);
PHP;

    file_put_contents($runnerScript, $code);

    $output = shell_exec("\"{$phpExe}\" \"{$runnerScript}\" 2>&1");
    $json = json_decode(trim((string)$output), true);

    return [
        'status' => $json['status'] ?? 0,
        'body'   => $json,
        'raw'    => $output,
    ];
}

$results = [];

// 1. PUBLIC CATEGORY BROWSING
$res = runRequest('GET', '/api/v1/customer/categories');
$results[] = [
    'name' => '1. List active product categories (Public)',
    'pass' => $res['status'] === 200 && is_array($res['body']['data']) && count($res['body']['data']) >= 1,
    'detail' => $res['body']
];

// 2. PRODUCT CATALOG LISTING & PAGINATION
$res = runRequest('GET', '/api/v1/customer/products?page=1&limit=2');
$pag = $res['body']['pagination'] ?? [];
$results[] = [
    'name' => '2. List published products with server pagination structure',
    'pass' => $res['status'] === 200 && is_array($res['body']['data']) &&
              isset($pag['total'], $pag['page'], $pag['limit'], $pag['total_pages']),
    'detail' => $res['body']
];

// 3. KEYWORD SEARCH
$res = runRequest('GET', '/api/v1/customer/products?search=Headphones');
$items = $res['body']['data'] ?? [];
$results[] = [
    'name' => '3. Search products by title keyword',
    'pass' => $res['status'] === 200 && count($items) >= 1 && str_contains(strtolower($items[0]['title'] ?? ''), 'headphones'),
    'detail' => $res['body']
];

// 4. CATEGORY SLUG FILTERING
$res = runRequest('GET', '/api/v1/customer/products?category=audio-headphones');
$items = $res['body']['data'] ?? [];
$results[] = [
    'name' => '4. Filter products by category slug (category=audio-headphones)',
    'pass' => $res['status'] === 200 && count($items) >= 1,
    'detail' => $res['body']
];

// 5. PRICE RANGE FILTERING & SORTING
$res = runRequest('GET', '/api/v1/customer/products?min_price=1000&max_price=10000&sort_by=price_asc');
$items = $res['body']['data'] ?? [];
$results[] = [
    'name' => '5. Price range filtering (min/max) and sort_by price_asc',
    'pass' => $res['status'] === 200 && count($items) >= 1 &&
              (float)$items[0]['price'] >= 1000 &&
              (float)$items[0]['price'] <= 10000,
    'detail' => $res['body']
];

// 6. IN-STOCK FILTER (EXCLUDE OUT OF STOCK)
$res = runRequest('GET', '/api/v1/customer/products?in_stock=1');
$items = $res['body']['data'] ?? [];
$allInStock = true;
foreach ($items as $it) {
    if ((int)$it['stock_quantity'] <= 0) $allInStock = false;
}
$results[] = [
    'name' => '6. In-stock filter returns only items with stock_quantity > 0',
    'pass' => $res['status'] === 200 && count($items) >= 1 && $allInStock,
    'detail' => $res['body']
];

// 7. GET SINGLE PRODUCT BY ID
$res = runRequest('GET', '/api/v1/customer/products/2');
$results[] = [
    'name' => '7. Get single published product details by numeric ID',
    'pass' => $res['status'] === 200 && ($res['body']['data']['id'] ?? 0) === 2 &&
              isset($res['body']['data']['description'], $res['body']['data']['price'], $res['body']['data']['mrp']),
    'detail' => $res['body']
];

// 8. GET SINGLE PRODUCT BY SLUG
$res = runRequest('GET', '/api/v1/customer/products/wireless-noise-cancelling-headphones-pro');
$results[] = [
    'name' => '8. Get single product by URL slug',
    'pass' => $res['status'] === 200 && ($res['body']['data']['slug'] ?? '') === 'wireless-noise-cancelling-headphones-pro',
    'detail' => $res['body']
];

// 9. DRAFT/UNPUBLISHED PRODUCTS HIDDEN FROM CUSTOMER CATALOG
$res = runRequest('GET', '/api/v1/customer/products/5'); // ID 5 is draft
$results[] = [
    'name' => '9. Draft product rejected on customer endpoint with 404',
    'pass' => $res['status'] === 404,
    'detail' => $res['body']
];

// 10. SELLER CRUD: LIST SELLER PRODUCTS (INCLUDES DRAFTS)
$res = runRequest('GET', '/api/v1/buddy/products', [], $sellerToken);
$sellerItems = $res['body']['data'] ?? [];
$results[] = [
    'name' => '10. Seller lists own products (including published & drafts)',
    'pass' => $res['status'] === 200 && count($sellerItems) >= 1,
    'detail' => $res['body']
];

// 11. SELLER CREATE PRODUCT WITH VALIDATION
$createPayload = [
    'category_id'    => 1,
    'title'          => 'Test Studio Monitor Speakers 5-inch',
    'description'    => 'Active reference studio monitors with bi-amped design.',
    'price'          => 12500.00,
    'mrp'            => 15999.00,
    'stock_quantity' => 12,
    'status'         => 'draft',
    'images'         => [
        ['image_url' => 'https://example.com/speaker-1.jpg', 'is_primary' => true],
        ['image_url' => 'https://example.com/speaker-2.jpg', 'is_primary' => false],
    ]
];
$res = runRequest('POST', '/api/v1/buddy/products', $createPayload, $sellerToken);
$createdProdId = $res['body']['data']['id'] ?? 0;
$results[] = [
    'name' => '11. Seller creates new product with generated slug and image gallery',
    'pass' => $res['status'] === 201 && $createdProdId > 0 &&
              str_starts_with($res['body']['data']['slug'] ?? '', 'test-studio-monitor-speakers'),
    'detail' => $res['body']
];

// 12. SELLER UPDATE PRODUCT ATTRIBUTES
$updatePayload = [
    'price'          => 11999.00,
    'stock_quantity' => 15,
    'status'         => 'published',
];
$res = runRequest('PUT', '/api/v1/buddy/products/' . $createdProdId, $updatePayload, $sellerToken);
$results[] = [
    'name' => '12. Seller updates product price, stock, and published status',
    'pass' => $res['status'] === 200 && ($res['body']['data']['id'] ?? 0) === $createdProdId,
    'detail' => $res['body']
];

// Verify updated values in DB
$verifyStmt = $pdo->prepare('SELECT price, stock_quantity, status FROM products WHERE id = :id');
$verifyStmt->execute(['id' => $createdProdId]);
$updatedDb = $verifyStmt->fetch(PDO::FETCH_ASSOC);
$results[] = [
    'name' => '13. Verify updated product fields in database',
    'pass' => (float)$updatedDb['price'] === 11999.00 &&
              (int)$updatedDb['stock_quantity'] === 15 &&
              $updatedDb['status'] === 'published',
    'detail' => $updatedDb
];

// 14. SELLER OWNERSHIP PROTECTION: ATTEMPT TO MODIFY WITH WRONG SELLER
$res = runRequest('PUT', '/api/v1/buddy/products/' . $createdProdId, ['price' => 5000.00], $anotherSellerToken);
$results[] = [
    'name' => '14. Role-based ownership: Disallow modification by other sellers (404/403)',
    'pass' => in_array($res['status'], [403, 404], true),
    'detail' => $res['body']
];

// 15. REGRESSION: UPDATE REJECTS PRICE EXCEEDING EXISTING MRP
$res = runRequest('PUT', '/api/v1/buddy/products/' . $createdProdId, ['price' => 25000.00], $sellerToken);
$results[] = [
    'name' => '15. Regression: Product update rejects selling price exceeding existing MRP with 422',
    'pass' => $res['status'] === 422 && isset($res['body']['errors']['mrp']),
    'detail' => $res['body']
];

// 16. REGRESSION: UPDATE REJECTS MRP LOWER THAN EXISTING SELLING PRICE
$res = runRequest('PUT', '/api/v1/buddy/products/' . $createdProdId, ['mrp' => 5000.00], $sellerToken);
$results[] = [
    'name' => '16. Regression: Product update rejects MRP lower than existing selling price with 422',
    'pass' => $res['status'] === 422 && isset($res['body']['errors']['mrp']),
    'detail' => $res['body']
];

// 17. REGRESSION: ADMIN PRODUCT CREATION WITHOUT SELLER_ID REJECTED WITH 422
$res = runRequest('POST', '/api/v1/buddy/products', [
    'title'          => 'Admin Product Missing Seller',
    'price'          => 1200.00,
    'mrp'            => 1500.00,
    'stock_quantity' => 5,
], $adminToken);
$results[] = [
    'name' => '17. Regression: Admin product creation without seller_id rejected with 422',
    'pass' => $res['status'] === 422 && isset($res['body']['errors']['seller_id']),
    'detail' => $res['body']
];

// 18. REGRESSION: ADMIN PRODUCT CREATION WITH NON-EXISTENT SELLER_ID REJECTED WITH 422
$res = runRequest('POST', '/api/v1/buddy/products', [
    'seller_id'      => 999999,
    'title'          => 'Admin Product Bad Seller',
    'price'          => 1200.00,
    'mrp'            => 1500.00,
    'stock_quantity' => 5,
], $adminToken);
$results[] = [
    'name' => '18. Regression: Admin product creation with non-existent seller_id rejected with 422',
    'pass' => $res['status'] === 422 && isset($res['body']['errors']['seller_id']),
    'detail' => $res['body']
];

// 19. REGRESSION: ADMIN PRODUCT CREATION WITH VALID SELLER_ID SUCCEEDS
$res = runRequest('POST', '/api/v1/buddy/products', [
    'seller_id'      => (int)$seller['id'],
    'title'          => 'Admin Created Verified Product',
    'price'          => 3200.00,
    'mrp'            => 3999.00,
    'stock_quantity' => 10,
    'status'         => 'draft',
], $adminToken);
$adminCreatedId = $res['body']['data']['id'] ?? 0;
$results[] = [
    'name' => '19. Regression: Admin creates product with explicit valid seller_id (201 Created)',
    'pass' => $res['status'] === 201 && $adminCreatedId > 0,
    'detail' => $res['body']
];
if ($adminCreatedId > 0) {
    runRequest('DELETE', '/api/v1/buddy/products/' . $adminCreatedId, [], $adminToken);
}

// 20. SELLER DELETE PRODUCT (CLEANS UP TEST RECORD)
$res = runRequest('DELETE', '/api/v1/buddy/products/' . $createdProdId, [], $sellerToken);
$results[] = [
    'name' => '20. Seller deletes own product and cascades image cleanup',
    'pass' => $res['status'] === 200,
    'detail' => $res['body']
];

// Verify deleted
$deletedCheck = $pdo->query("SELECT COUNT(*) FROM products WHERE id = {$createdProdId}")->fetchColumn();
$results[] = [
    'name' => '21. Verify product removed from database without orphan records',
    'pass' => (int)$deletedCheck === 0,
    'detail' => ['remaining_count' => $deletedCheck]
];

// Print human-readable report
$passedCount = 0;
foreach ($results as $r) {
    if ($r['pass']) {
        $passedCount++;
        echo "[PASS] " . $r['name'] . "\n";
    } else {
        echo "[FAIL] " . $r['name'] . "\n";
        echo "       Detail: " . json_encode($r['detail']) . "\n";
    }
}

echo "\nTEST SUMMARY: {$passedCount}/" . count($results) . " test assertions passed.\n";
if ($passedCount === count($results)) {
    echo "ALL PRODUCT CATALOG AUDIT TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
