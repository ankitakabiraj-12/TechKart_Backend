<?php

declare(strict_types=1);

/**
 * TechKart Returns & Refunds Integration Test Suite
 *
 * Tests:
 * 1. Health check & database connection
 * 2. Customer login (Rahul Sharma)
 * 3. Fetch return eligibility for Order 1 (delivered/shipped order)
 * 4. Submit return request for Order 1
 * 5. Duplicate return prevention on same order item (HTTP 409)
 * 6. Fetch return status & logistics tracking (SCR-36)
 * 7. Seller login (Vikram Patel)
 * 8. Seller unauthorized attempt on another seller's return (HTTP 403)
 * 9. Seller inspection decision ('quality_inspection')
 * 10. Admin decision & refund release ('refund_released')
 * 11. Verify payment record created & linked with status 'refunded'
 * 12. Verify order payment status updated to 'refunded' in MariaDB
 */

function runTest(string $title, callable $testFn): void {
    echo "Running: {$title}... ";
    try {
        $testFn();
        echo "[PASSED]\n";
    } catch (Throwable $e) {
        echo "[FAILED] - " . $e->getMessage() . "\n";
        exit(1);
    }
}

function request(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]
    ];
    if ($token !== null) {
        $opts['http']['header'] .= "Authorization: Bearer {$token}\r\n";
    }
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }

    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        throw new Exception("HTTP request failed: {$method} {$url}");
    }
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        throw new Exception("Invalid JSON response: " . substr($res, 0, 200));
    }
    return $decoded;
}

echo "=========================================================\n";
echo "TechKart Returns & Refunds End-to-End Test Suite\n";
echo "=========================================================\n";

$customerToken = '';
$sellerToken = '';
$adminToken = '';
$orderId = 1;
$orderNumber = '';
$testReturnId = 0;
$testReturnNumber = '';
$orderItemId = 0;

// Test 1: Health check
runTest('Server Health Check', function() {
    $res = request('GET', '/health');
    if (($res['status'] ?? 0) !== 200) {
        throw new Exception('Health check failed: ' . json_encode($res));
    }
});

// Test 2: Customer login
runTest('Customer Authentication (Rahul Sharma)', function() use (&$customerToken) {
    $res = request('POST', '/customer/auth/login', [
        'email'    => 'rahul.sharma@techkart.com',
        'password' => 'Password@123',
    ]);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['token'])) {
        throw new Exception('Customer login failed: ' . json_encode($res));
    }
    $customerToken = $res['data']['token'];
});

// Clean previous test return records for clean test run
$pdo = new PDO('mysql:host=127.0.0.1;dbname=techkart_db;charset=utf8mb4', 'root', '');
$pdo->prepare("DELETE FROM return_requests WHERE order_id = ?")->execute([$orderId]);

// Test 3: Check return eligibility for Order 1
runTest('Check Return Eligibility for Order 1', function() use ($customerToken, $orderId, &$orderNumber, &$orderItemId) {
    $res = request('GET', "/customer/orders/{$orderId}/return-eligibility", null, $customerToken);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['items'])) {
        throw new Exception('Eligibility check failed: ' . json_encode($res));
    }
    $data = $res['data'];
    $orderNumber = $data['order_number'];
    $orderItemId = (int)$data['items'][0]['order_item_id'];

    if (!isset($data['is_eligible'], $data['window_valid_until'])) {
        throw new Exception('Missing eligibility fields: ' . json_encode($data));
    }
});

// Test 4: Submit return request
runTest('Submit Return Request for Order Item', function() use ($customerToken, $orderId, $orderItemId, &$testReturnId, &$testReturnNumber) {
    $res = request('POST', '/customer/returns', [
        'order_id'       => $orderId,
        'order_item_id'  => $orderItemId,
        'reason'         => 'defective',
        'resolution'     => 'original',
        'symptoms'       => 'Screen flickers when adjusting brightness dial, power drops intermittently...',
        'photos'         => ['asset/chronos_watch_x.jpg'],
    ], $customerToken);

    if (($res['status'] ?? 0) !== 201 || empty($res['data']['return_id'])) {
        throw new Exception('Return submission failed: ' . json_encode($res));
    }
    $testReturnId = (int)$res['data']['return_id'];
    $testReturnNumber = $res['data']['return_number'];

    if (empty($testReturnNumber) || $res['data']['status'] !== 'pickup_scheduled') {
        throw new Exception('Unexpected return response fields: ' . json_encode($res['data']));
    }
});

// Test 5: Duplicate return request prevention (HTTP 409)
runTest('Prevent Duplicate Return Request on Same Order Item (HTTP 409)', function() use ($customerToken, $orderId, $orderItemId) {
    $res = request('POST', '/customer/returns', [
        'order_id'       => $orderId,
        'order_item_id'  => $orderItemId,
        'reason'         => 'wrong_item',
    ], $customerToken);

    if (($res['status'] ?? 0) !== 409) {
        throw new Exception('Expected HTTP 409 Conflict on duplicate return. Received: ' . json_encode($res));
    }
});

// Test 6: Fetch return status & logistics tracking (SCR-36)
runTest('Fetch Return Status & Logistics Timeline (SCR-36)', function() use ($customerToken, $testReturnNumber) {
    $res = request('GET', "/customer/returns/{$testReturnNumber}", null, $customerToken);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['timeline'])) {
        throw new Exception('Fetch return status failed: ' . json_encode($res));
    }
    $data = $res['data'];
    if ($data['case_id'] !== $testReturnNumber || count($data['timeline']) !== 4) {
        throw new Exception('Invalid return tracking payload: ' . json_encode($data));
    }
});

// Test 7: Seller authentication
runTest('Seller Authentication (Vikram Patel)', function() use (&$sellerToken) {
    $res = request('POST', '/buddy/auth/login', [
        'email'    => 'vikram.store@techkart.com',
        'password' => 'Password@123',
    ]);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['token'])) {
        throw new Exception('Seller login failed: ' . json_encode($res));
    }
    $sellerToken = $res['data']['token'];
});

// Test 8: Seller unauthorized attempt on other store (HTTP 403)
runTest('Forbidden: Other Seller Cannot Decision on Vikram Store Item (HTTP 403)', function() use ($testReturnId) {
    // Login as other.seller@techkart.com
    $resLogin = request('POST', '/buddy/auth/login', [
        'email'    => 'other.seller@techkart.com',
        'password' => 'Password@123',
    ]);
    $otherToken = $resLogin['data']['token'] ?? '';
    if (!$otherToken) {
        throw new Exception('Failed to login as other seller: ' . json_encode($resLogin));
    }

    $resDec = request('PUT', "/buddy/returns/{$testReturnId}/decision", [
        'status' => 'rejected',
        'decision_reason' => 'Unauthorized rejection',
    ], $otherToken);

    if (($resDec['status'] ?? 0) !== 403) {
        throw new Exception('Expected HTTP 403 Forbidden on other seller decision. Received: ' . json_encode($resDec));
    }
});

// Test 9: Authorized seller updates to 'quality_inspection'
runTest('Authorized Seller Decision (Quality Inspection)', function() use ($sellerToken, $testReturnId) {
    $res = request('PUT', "/buddy/returns/{$testReturnId}/decision", [
        'status'          => 'quality_inspection',
        'decision_reason' => 'Package received at hub; inspecting hardware condition.',
    ], $sellerToken);

    if (($res['status'] ?? 0) !== 200 || ($res['data']['status'] ?? '') !== 'quality_inspection') {
        throw new Exception('Seller decision update failed: ' . json_encode($res));
    }
});

// Test 10: Admin authentication & refund release
runTest('Admin Authentication & Refund Release', function() use (&$adminToken, $testReturnId) {
    $resLogin = request('POST', '/buddy/auth/login', [
        'email'    => 'admin.audit@techkart.com',
        'password' => 'Password@123',
    ]);
    if (($resLogin['status'] ?? 0) !== 200 || empty($resLogin['data']['token'])) {
        throw new Exception('Admin login failed: ' . json_encode($resLogin));
    }
    $adminToken = $resLogin['data']['token'];

    $res = request('PUT', "/buddy/returns/{$testReturnId}/decision", [
        'status'          => 'refund_released',
        'decision_reason' => 'Inspection passed: full refund approved to original payment method.',
        'admin_notes'     => 'Processed by finance concierge.',
    ], $adminToken);

    if (($res['status'] ?? 0) !== 200 || ($res['data']['status'] ?? '') !== 'refund_released') {
        throw new Exception('Admin refund release failed: ' . json_encode($res));
    }
    if (empty($res['data']['refund_payment_id'])) {
        throw new Exception('Expected refund_payment_id to be populated upon refund release.');
    }
});

// Test 11: Verify payment record created & linked with status 'refunded'
runTest('Verify Payment Record Created with Status Refunded', function() use ($customerToken, $orderId) {
    $res = request('GET', "/customer/orders/{$orderId}", null, $customerToken);
    if (($res['status'] ?? 0) !== 200 || empty($res['data']['payments'])) {
        throw new Exception('Order lookup failed: ' . json_encode($res));
    }
    $payments = $res['data']['payments'];
    $hasRefundedPayment = false;
    foreach ($payments as $p) {
        if ($p['payment_status'] === 'refunded') {
            $hasRefundedPayment = true;
            break;
        }
    }
    if (!$hasRefundedPayment) {
        throw new Exception('No refunded payment record found in order payments: ' . json_encode($payments));
    }
});

// Test 12: Verify order payment status updated in MariaDB
runTest('Verify Order Payment Status Updated to Refunded', function() use ($customerToken, $orderId) {
    $res = request('GET', "/customer/orders/{$orderId}", null, $customerToken);
    if (($res['data']['payment_status'] ?? '') !== 'refunded') {
        throw new Exception("Expected order payment_status to be 'refunded'. Got: " . ($res['data']['payment_status'] ?? 'null'));
    }
});

echo "=========================================================\n";
echo "All 12 Returns & Refunds Backend Integration Tests Passed!\n";
echo "=========================================================\n";
