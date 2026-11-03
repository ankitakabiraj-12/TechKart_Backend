<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Router;

$pdo = Database::getConnection();

// Fetch users
$customer = $pdo->query("SELECT * FROM users WHERE role = 'customer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$seller = $pdo->query("SELECT * FROM users WHERE role = 'seller' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$customer || !$seller) {
    echo json_encode(['error' => 'Missing test fixtures']);
    exit(1);
}

// Generate JWT tokens
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

// Product fixtures
$publishedInStock = $pdo->query("SELECT * FROM products WHERE status = 'published' AND stock_quantity >= 10 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$lowStock = $pdo->query("SELECT * FROM products WHERE status = 'published' AND stock_quantity = 3 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$outOfStock = $pdo->query("SELECT * FROM products WHERE status = 'published' AND stock_quantity = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$draftProduct = $pdo->query("SELECT * FROM products WHERE status = 'draft' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$pdo->exec("DELETE FROM cart_items WHERE customer_id = {$customer['id']}");

function runDirectRequest(string $method, string $uri, array $body = [], ?string $token = null): array
{
    $phpExe = 'C:\\xampp\\php\\php.exe';
    $runnerScript = __DIR__ . '/scratch/test_runner_single.php';

    if (!is_dir(__DIR__ . '/scratch')) {
        mkdir(__DIR__ . '/scratch', 0777, true);
    }

    $b64 = base64_encode(json_encode($body));
    $tok = $token ?? '';

    $code = <<<PHP
<?php
\$_SERVER['REQUEST_METHOD'] = '{$method}';
\$_SERVER['REQUEST_URI'] = '{$uri}';
\$_SERVER['SCRIPT_NAME'] = '/index.php';
\$_GET = [];
\$_POST = [];
if ('{$tok}' !== '') {
    \$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer {$tok}';
}
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
        'body' => $json,
        'raw'  => $output,
    ];
}

$results = [];

// 1. Unauthenticated request
$res = runDirectRequest('GET', '/api/v1/customer/cart');
$results[] = [
    'name' => '1. Unauthenticated access rejected with 401',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 401 && $res['body']['success'] === false,
    'detail' => $res['body']
];

// 2. Seller access to customer cart
$res = runDirectRequest('GET', '/api/v1/customer/cart', [], $sellerToken);
$results[] = [
    'name' => '2. Seller role rejected with 403 Forbidden',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 403,
    'detail' => $res['body']
];

// 3. Customer access empty cart
$res = runDirectRequest('GET', '/api/v1/customer/cart', [], $customerToken);
$results[] = [
    'name' => '3. Customer views empty cart (quantity 0, subtotal 0)',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 200 && ($res['body']['data']['summary']['total_quantity'] ?? -1) === 0,
    'detail' => $res['body']
];

// 4. Add out of stock product
$res = runDirectRequest('POST', '/api/v1/customer/cart', ['product_id' => $outOfStock['id'], 'quantity' => 1], $customerToken);
$results[] = [
    'name' => '4. Add out of stock item returns 400',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 400,
    'detail' => $res['body']
];

// 5. Add draft product
$res = runDirectRequest('POST', '/api/v1/customer/cart', ['product_id' => $draftProduct['id'], 'quantity' => 1], $customerToken);
$results[] = [
    'name' => '5. Add draft product returns 400 unavailable',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 400,
    'detail' => $res['body']
];

// 6. Add in-stock product with tampered client price
$res = runDirectRequest('POST', '/api/v1/customer/cart', [
    'product_id' => $publishedInStock['id'],
    'quantity'   => 2,
    'price'      => 1.00 // Client tampering attempt
], $customerToken);
$item = $res['body']['data']['items'][0] ?? [];
$results[] = [
    'name' => '6. Add item ignores client price and calculates server DB price',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 201 &&
              (float)($item['unit_price'] ?? 0) === (float)$publishedInStock['price'] &&
              (float)($item['line_total'] ?? 0) === round((float)$publishedInStock['price'] * 2, 2),
    'detail' => $res['body']
];

// 7. Duplicate add (should increment quantity on existing line, not duplicate row)
$res = runDirectRequest('POST', '/api/v1/customer/cart', [
    'product_id' => $publishedInStock['id'],
    'quantity'   => 1
], $customerToken);
$items = $res['body']['data']['items'] ?? [];
$results[] = [
    'name' => '7. Duplicate product addition increments quantity without duplicate row',
    'pass' => count($items) === 1 &&
              ($items[0]['quantity'] ?? 0) === 3 &&
              ($res['body']['data']['summary']['total_quantity'] ?? 0) === 3,
    'detail' => $res['body']
];

// 8. Add item exceeding stock
$res = runDirectRequest('POST', '/api/v1/customer/cart', [
    'product_id' => $lowStock['id'],
    'quantity'   => 10
], $customerToken);
$results[] = [
    'name' => '8. Adding item exceeding stock is rejected with 400',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 400,
    'detail' => $res['body']
];

// 9. Add second valid product
$res = runDirectRequest('POST', '/api/v1/customer/cart', [
    'product_id' => $lowStock['id'],
    'quantity'   => 2
], $customerToken);
$items = $res['body']['data']['items'] ?? [];
$summary = $res['body']['data']['summary'] ?? [];
$results[] = [
    'name' => '9. Multiple products in cart with recalculated totals',
    'pass' => count($items) === 2 &&
              ($summary['total_quantity'] ?? 0) === 5 &&
              (float)($summary['subtotal'] ?? 0) === round((float)$publishedInStock['price'] * 3 + (float)$lowStock['price'] * 2, 2),
    'detail' => $res['body']
];

// 10. Update quantity
$res = runDirectRequest('PUT', '/api/v1/customer/cart/' . $lowStock['id'], ['quantity' => 3], $customerToken);
$items = $res['body']['data']['items'] ?? [];
$targetItem = null;
foreach ($items as $it) {
    if ($it['product_id'] == $lowStock['id']) $targetItem = $it;
}
$results[] = [
    'name' => '10. Update cart item quantity updates line total and cart summary',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 200 &&
              $targetItem !== null &&
              $targetItem['quantity'] === 3 &&
              (float)$targetItem['line_total'] === round((float)$lowStock['price'] * 3, 2),
    'detail' => $res['body']
];

// 11. Update quantity exceeding stock
$res = runDirectRequest('PATCH', '/api/v1/customer/cart/' . $lowStock['id'], ['quantity' => 99], $customerToken);
$results[] = [
    'name' => '11. Update quantity exceeding stock returns 400',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 400,
    'detail' => $res['body']
];

// 12. Delete single item
$res = runDirectRequest('DELETE', '/api/v1/customer/cart/' . $lowStock['id'], [], $customerToken);
$items = $res['body']['data']['items'] ?? [];
$results[] = [
    'name' => '12. Delete single item from cart removes line and updates summary',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 200 &&
              count($items) === 1 &&
              $items[0]['product_id'] == $publishedInStock['id'],
    'detail' => $res['body']
];

// 13. Clear cart
$res = runDirectRequest('DELETE', '/api/v1/customer/cart', [], $customerToken);
$items = $res['body']['data']['items'] ?? [];
$results[] = [
    'name' => '13. Clear cart empties all items',
    'pass' => isset($res['body']['status']) && $res['body']['status'] === 200 &&
              count($items) === 0 &&
              ($res['body']['data']['summary']['total_quantity'] ?? -1) === 0,
    'detail' => $res['body']
];

// Print human-readable report
$passedCount = 0;
foreach ($results as $r) {
    if ($r['pass']) {
        $passedCount++;
        echo "[PASS] " . $r['name'] . "\n";
    } else {
        echo "[FAIL] " . $r['name'] . "\n";
        echo "       Raw: " . json_encode($r['detail']) . "\n";
    }
}

echo "\nResult: {$passedCount}/" . count($results) . " test assertions passed.\n";
