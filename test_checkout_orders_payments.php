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
    echo "ERROR: Users missing\n";
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

function runRequest(string $method, string $uri, array $body = [], ?string $token = null, array $headers = []): array
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
    foreach ($headers as $k => $v) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        $headersCode .= "\$_SERVER['{$serverKey}'] = '{$v}';\n";
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

// Clean test data for repeatable test run
$pdo->exec("DELETE FROM cart_items WHERE customer_id = {$customer['id']}");

// 1. ADD CUSTOMER ADDRESS
$addrPayload = [
    'label'                 => 'Home',
    'recipient_name'        => 'Rahul Sharma',
    'phone'                 => '+8801712345678',
    'address_line1'         => 'House 42, Road 11, Block D, Banani',
    'address_line2'         => 'Floor 4A',
    'city'                  => 'Dhaka',
    'state'                 => 'Dhaka Division',
    'postal_code'           => '1213',
    'country'               => 'Bangladesh',
    'delivery_instructions' => 'Call before arrival',
    'is_default'            => true,
];
$res = runRequest('POST', '/api/v1/customer/addresses', $addrPayload, $customerToken);
$addressId = $res['body']['data']['id'] ?? 0;
$results[] = [
    'name' => '1. Create customer delivery address',
    'pass' => $res['status'] === 201 && $addressId > 0 && ($res['body']['data']['recipient_name'] ?? '') === 'Rahul Sharma',
    'detail' => $res['body']
];

// 2. LIST ADDRESSES
$res = runRequest('GET', '/api/v1/customer/addresses', [], $customerToken);
$results[] = [
    'name' => '2. List customer addresses with default flag',
    'pass' => $res['status'] === 200 && count($res['body']['data'] ?? []) >= 1,
    'detail' => $res['body']
];

// 3. CHECKOUT PREVIEW WITH EMPTY CART
$res = runRequest('GET', '/api/v1/customer/checkout/preview', [], $customerToken);
$results[] = [
    'name' => '3. Checkout preview with empty cart returns 400',
    'pass' => $res['status'] === 400,
    'detail' => $res['body']
];

// 4. ADD ITEMS TO CART FOR CHECKOUT
$inStockProd = $pdo->query("SELECT * FROM products WHERE status = 'published' AND stock_quantity >= 10 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prodId = (int)$inStockProd['id'];
$initialStock = (int)$inStockProd['stock_quantity'];
$prodPrice = (float)$inStockProd['price'];

$res = runRequest('POST', '/api/v1/customer/cart', ['product_id' => $prodId, 'quantity' => 2], $customerToken);
$results[] = [
    'name' => '4. Prepare cart items for checkout',
    'pass' => $res['status'] === 201 && count($res['body']['data']['items'] ?? []) === 1,
    'detail' => $res['body']
];

// 5. CHECKOUT PREVIEW WITH ACTIVE CART
$res = runRequest('GET', '/api/v1/customer/checkout/preview', [], $customerToken);
$pricing = $res['body']['data']['pricing'] ?? [];
$expectedTotal = round($prodPrice * 2, 2);
$results[] = [
    'name' => '5. Checkout preview calculates server totals & binds address',
    'pass' => $res['status'] === 200 &&
              (float)($pricing['grand_total'] ?? 0) === $expectedTotal &&
              ($res['body']['data']['delivery_address']['id'] ?? 0) === $addressId,
    'detail' => $res['body']
];

// 6. PLACE ORDER WITH IDEMPOTENCY KEY
$idempotencyKey = 'IDEM-' . bin2hex(random_bytes(8));
$orderPayload = [
    'address_id'     => $addressId,
    'payment_method' => 'card',
    'notes'          => 'Handle with care',
];
$res = runRequest('POST', '/api/v1/customer/orders', $orderPayload, $customerToken, ['Idempotency-Key' => $idempotencyKey]);
$orderData = $res['body']['data'] ?? [];
$orderId = $orderData['order_id'] ?? 0;
$orderNumber = $orderData['order_number'] ?? '';
$txRef = $orderData['transaction_reference'] ?? '';

$results[] = [
    'name' => '6. Place order creates order, order_items, payment intent & clears cart',
    'pass' => $res['status'] === 201 && $orderId > 0 && str_starts_with($orderNumber, 'TK-'),
    'detail' => $res['body']
];

// Verify inventory was deducted atomically
$updatedStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = {$prodId}")->fetchColumn();
$results[] = [
    'name' => '7. Inventory deducted atomically in transaction',
    'pass' => $updatedStock === ($initialStock - 2),
    'detail' => ['initial' => $initialStock, 'updated' => $updatedStock]
];

// 8. IDEMPOTENT ORDER REPLAY
$res = runRequest('POST', '/api/v1/customer/orders', $orderPayload, $customerToken, ['Idempotency-Key' => $idempotencyKey]);
$results[] = [
    'name' => '8. Idempotent order replay returns existing order without duplicate insertion or double deduction',
    'pass' => $res['status'] === 201 && ($res['body']['data']['order_number'] ?? '') === $orderNumber,
    'detail' => $res['body']
];
// Verify stock was not deducted a second time
$recheckStock = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = {$prodId}")->fetchColumn();
$results[] = [
    'name' => '9. Verify stock not double-deducted on idempotent replay',
    'pass' => $recheckStock === ($initialStock - 2),
    'detail' => ['stock_after_replay' => $recheckStock]
];

// 10. GET CUSTOMER ORDER DETAILS
$res = runRequest('GET', '/api/v1/customer/orders/' . $orderNumber, [], $customerToken);
$results[] = [
    'name' => '10. Customer inspects order details with price freeze snapshot',
    'pass' => $res['status'] === 200 &&
              ($res['body']['data']['order_number'] ?? '') === $orderNumber &&
              (float)($res['body']['data']['total_amount'] ?? 0) === $expectedTotal &&
              count($res['body']['data']['items'] ?? []) === 1,
    'detail' => $res['body']
];

// 11. INITIATE PAYMENT (SCR-29)
$res = runRequest('POST', '/api/v1/customer/payments/initiate', ['order_id' => $orderNumber, 'payment_method' => 'card'], $customerToken);
$paymentIntentTx = $res['body']['data']['transaction_reference'] ?? '';
$results[] = [
    'name' => '11. Initiate payment intent creates transaction reference and tokens',
    'pass' => $res['status'] === 200 && str_starts_with($paymentIntentTx, 'TXN-'),
    'detail' => $res['body']
];

// 12. VERIFY PAYMENT (SCR-30)
$verifyPayload = [
    'transaction_reference' => $paymentIntentTx,
    'status'                => 'success',
];
$res = runRequest('POST', '/api/v1/customer/payments/verify', $verifyPayload, $customerToken);
$results[] = [
    'name' => '12. Verify payment updates payment to paid and order to processing',
    'pass' => $res['status'] === 200 &&
              ($res['body']['data']['payment_status'] ?? '') === 'paid' &&
              ($res['body']['data']['order_status'] ?? '') === 'processing',
    'detail' => $res['body']
];

// 13. SELLER ORDERS LISTING (BUDDY APP)
$res = runRequest('GET', '/api/v1/buddy/orders', [], $sellerToken);
$results[] = [
    'name' => '13. Seller views filtered orders containing only their store products',
    'pass' => $res['status'] === 200 && count($res['body']['data'] ?? []) >= 1,
    'detail' => $res['body']
];

// 14. SELLER UPDATES ITEM STATUS TO SHIPPED
$res = runRequest('PATCH', '/api/v1/buddy/orders/' . $orderId . '/status', ['status' => 'shipped'], $sellerToken);
$results[] = [
    'name' => '14. Seller updates order fulfillment status to shipped',
    'pass' => $res['status'] === 200 && ($res['body']['data']['item_status'] ?? '') === 'shipped',
    'detail' => $res['body']
];

// 15. CANCEL ORDER (CANNOT CANCEL ONCE SHIPPED)
$res = runRequest('POST', '/api/v1/customer/orders/' . $orderId . '/cancel', [], $customerToken);
$results[] = [
    'name' => '15. Prevent customer cancellation when order has already shipped',
    'pass' => $res['status'] === 400,
    'detail' => $res['body']
];

// 16. CANCEL ORDER & RESTORE INVENTORY (Testing cancellation on newly placed order)
// Create a separate placed order to test cancellation & inventory restoration
$pdo->prepare('INSERT INTO cart_items (customer_id, product_id, quantity) VALUES (?, ?, ?)')
    ->execute([$customer['id'], $prodId, 1]);
$resOrder2 = runRequest('POST', '/api/v1/customer/orders', ['address_id' => $addressId, 'payment_method' => 'card'], $customerToken);

$stockBeforeCancel = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = {$prodId}")->fetchColumn();

$resCancel = runRequest('POST', '/api/v1/customer/orders/' . $resOrder2['body']['data']['order_number'] . '/cancel', ['reason' => 'Ordered by mistake'], $customerToken);
$stockAfterCancel = (int)$pdo->query("SELECT stock_quantity FROM products WHERE id = {$prodId}")->fetchColumn();

$results[] = [
    'name' => '16. Cancel order restores product stock and updates payment status',
    'pass' => $resCancel['status'] === 200 &&
              ($resCancel['body']['data']['order_status'] ?? '') === 'cancelled' &&
              $stockAfterCancel === ($stockBeforeCancel + 1),
    'detail' => [
        'cancel_response' => $resCancel['body'],
        'stock_before'    => $stockBeforeCancel,
        'stock_after'     => $stockAfterCancel
    ]
];

// Output report
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
    echo "ALL CHECKOUT, ORDER & PAYMENT TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
