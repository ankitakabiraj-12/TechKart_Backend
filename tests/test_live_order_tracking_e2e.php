<?php

declare(strict_types=1);

/**
 * Targeted End-to-End Test Suite for TechKart Live Order Tracking.
 * Validates real MariaDB persistence, Core PHP REST APIs, transition guards,
 * customer live tracking query, and seller/admin status changes.
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function logStep(string $step, string $status, ?string $details = null): void {
    $icon = $status === 'PASS' ? '✅' : '❌';
    echo "[$icon $status] $step\n";
    if ($details) {
        echo "   -> $details\n";
    }
}

function httpPost(string $url, array $data, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode((string)$response, true)];
}

function httpPatch(string $url, array $data, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode((string)$response, true)];
}

function httpGet(string $url, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode((string)$response, true)];
}

echo "====================================================================\n";
echo "      TechKart Live Order Tracking: Targeted End-to-End Test Suite\n";
echo "====================================================================\n\n";

$allPassed = true;

// ---------------------------------------------------------------------------
// Step 1: Health check on Core PHP REST Server
// ---------------------------------------------------------------------------
$health = httpGet("$baseUrl/health");
if ($health['code'] === 200 && ($health['body']['success'] ?? false)) {
    logStep('Server Health Check', 'PASS', 'API is responsive at 127.0.0.1:8000');
} else {
    logStep('Server Health Check', 'FAIL', 'Health check failed: HTTP ' . $health['code']);
    exit(1);
}

// ---------------------------------------------------------------------------
// Step 2: Authenticate Customer and Seller
// ---------------------------------------------------------------------------
$customerAuth = httpPost("$baseUrl/customer/auth/login", [
    'email' => 'rahul.test@techkart.com',
    'password' => 'password123'
]);
if ($customerAuth['code'] === 200 && !empty($customerAuth['body']['data']['token'])) {
    $customerToken = $customerAuth['body']['data']['token'];
    logStep('Customer Authentication', 'PASS', 'Authenticated as rahul.test@techkart.com (Customer)');
} else {
    logStep('Customer Authentication', 'FAIL', json_encode($customerAuth));
    $allPassed = false;
    exit(1);
}

$sellerAuth = httpPost("$baseUrl/buddy/auth/login", [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
if ($sellerAuth['code'] === 200 && !empty($sellerAuth['body']['data']['token'])) {
    $sellerToken = $sellerAuth['body']['data']['token'];
    logStep('Seller Authentication', 'PASS', 'Authenticated as vikram.store@techkart.com (Seller/Buddy)');
} else {
    logStep('Seller Authentication', 'FAIL', json_encode($sellerAuth));
    $allPassed = false;
    exit(1);
}

// ---------------------------------------------------------------------------
// Step 3: Add Item to Cart and Create Order with Persisted Tracking
// ---------------------------------------------------------------------------
$addToCart = httpPost("$baseUrl/customer/cart", [
    'product_id' => 2,
    'quantity' => 1
], $customerToken);
if ($addToCart['code'] !== 200 && $addToCart['code'] !== 201) {
    logStep('Add to Cart', 'FAIL', json_encode($addToCart));
    exit(1);
}

$createOrder = httpPost("$baseUrl/customer/orders", [
    'payment_method' => 'cod',
    'address' => [
        'recipient_name' => 'Rahul Sharma',
        'phone' => '+8801711000111',
        'address_line1' => 'Flat 4B, Silicon Heights, Banani',
        'city' => 'Dhaka',
        'postal_code' => '1213',
        'country' => 'Bangladesh'
    ]
], $customerToken);

if ($createOrder['code'] === 201 && !empty($createOrder['body']['data']['tracking_number'])) {
    $order = $createOrder['body']['data'];
    $orderId = $order['order_id'];
    $orderNumber = $order['order_number'];
    $trackingNumber = $order['tracking_number'];
    $courierName = $order['courier_name'];
    logStep('Order Creation with Tracking Persistence', 'PASS', "Order #$orderNumber created (ID: $orderId) with Tracking: $trackingNumber, Courier: $courierName");
} else {
    logStep('Order Creation with Tracking Persistence', 'FAIL', json_encode($createOrder));
    $allPassed = false;
    exit(1);
}

// ---------------------------------------------------------------------------
// Step 4: Verify Initial Placed Tracking Event via Public/Customer Track API
// ---------------------------------------------------------------------------
$trackPlaced = httpGet("$baseUrl/customer/orders/$trackingNumber/tracking", $customerToken);
if ($trackPlaced['code'] === 200 && ($trackPlaced['body']['success'] ?? false)) {
    $trackData = $trackPlaced['body']['data'];
    $timeline = $trackData['timeline'] ?? [];
    if (count($timeline) >= 1 && $trackData['order_status'] === 'placed' && $trackData['progress_percent'] === 25) {
        logStep('Initial Tracking Event Verification', 'PASS', "Live tracking loaded. Status: {$trackData['order_status']}, Progress: {$trackData['progress_percent']}%, Events: " . count($timeline));
    } else {
        logStep('Initial Tracking Event Verification', 'FAIL', 'Unexpected timeline or progress: ' . json_encode($trackData));
        $allPassed = false;
    }
} else {
    logStep('Initial Tracking Event Verification', 'FAIL', json_encode($trackPlaced));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 5: Enforce Status Transition Guard (Reject invalid jump: placed -> delivered)
// ---------------------------------------------------------------------------
$invalidTransition = httpPatch("$baseUrl/buddy/orders/$orderId/status", [
    'status' => 'delivered'
], $sellerToken);

if ($invalidTransition['code'] === 400) {
    logStep('Status Transition Guard (placed -> delivered rejected)', 'PASS', 'Server correctly rejected illegal jump with HTTP 400: ' . ($invalidTransition['body']['message'] ?? ''));
} else {
    logStep('Status Transition Guard (placed -> delivered rejected)', 'FAIL', 'Server allowed illegal jump: HTTP ' . $invalidTransition['code'] . ' ' . json_encode($invalidTransition));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 6: Valid Transition: placed -> processing (Seller Accepts / Packs Order)
// ---------------------------------------------------------------------------
$acceptOrder = httpPatch("$baseUrl/buddy/orders/$orderId/status", [
    'status' => 'processing',
    'current_location' => 'TechKart Central Warehouse, Tejgaon',
    'next_stop' => 'Dhaka Sorting Facility',
    'description' => 'Seller verified inventory and completed packaging.'
], $sellerToken);

if ($acceptOrder['code'] === 200 && ($acceptOrder['body']['success'] ?? false)) {
    logStep('Valid Transition: placed -> processing', 'PASS', 'Order status moved to processing. Persisted shipment checkpoint recorded.');
} else {
    logStep('Valid Transition: placed -> processing', 'FAIL', json_encode($acceptOrder));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 7: Valid Transition: processing -> shipped (Handover to Courier)
// ---------------------------------------------------------------------------
$shipOrder = httpPatch("$baseUrl/buddy/orders/$orderId/status", [
    'status' => 'shipped',
    'courier_name' => 'Apex Courier Express',
    'tracking_number' => $trackingNumber,
    'current_location' => 'Dhaka Sorting Hub A',
    'next_stop' => 'Banani Local Delivery Facility',
    'description' => 'Package handed over to Apex Courier Express rider. In transit to destination.'
], $sellerToken);

if ($shipOrder['code'] === 200 && ($shipOrder['body']['success'] ?? false)) {
    logStep('Valid Transition: processing -> shipped', 'PASS', 'Order status moved to shipped with courier checkpoints updated.');
} else {
    logStep('Valid Transition: processing -> shipped', 'FAIL', json_encode($shipOrder));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 8: Verify Mid-Transit Tracking Timeline & Progress (75%)
// ---------------------------------------------------------------------------
$trackShipped = httpGet("$baseUrl/customer/orders/$trackingNumber/tracking", $customerToken);
if ($trackShipped['code'] === 200) {
    $trackData = $trackShipped['body']['data'];
    if ($trackData['order_status'] === 'shipped' && $trackData['progress_percent'] === 75 && count($trackData['timeline']) >= 3) {
        logStep('In-Transit Tracking State Verification', 'PASS', "Status: {$trackData['order_status']}, Progress: {$trackData['progress_percent']}%, Next Stop: {$trackData['next_stop']}, Events count: " . count($trackData['timeline']));
    } else {
        logStep('In-Transit Tracking State Verification', 'FAIL', 'Unexpected tracking data: ' . json_encode($trackData));
        $allPassed = false;
    }
} else {
    logStep('In-Transit Tracking State Verification', 'FAIL', json_encode($trackShipped));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 9: Valid Transition: shipped -> delivered (Final Delivery Checkpoint)
// ---------------------------------------------------------------------------
$deliverOrder = httpPatch("$baseUrl/buddy/orders/$orderId/status", [
    'status' => 'delivered',
    'current_location' => 'Delivered to Customer Doorstep',
    'next_stop' => 'Destination Completed',
    'description' => 'Package handed over to recipient Rahul Sharma. Delivery OTP validated.'
], $sellerToken);

if ($deliverOrder['code'] === 200 && ($deliverOrder['body']['success'] ?? false)) {
    logStep('Valid Transition: shipped -> delivered', 'PASS', 'Order marked delivered. Terminal delivery checkpoint recorded.');
} else {
    logStep('Valid Transition: shipped -> delivered', 'FAIL', json_encode($deliverOrder));
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 10: Enforce Terminal Guard (delivered -> processing must be rejected)
// ---------------------------------------------------------------------------
$postDeliveredGuard = httpPatch("$baseUrl/buddy/orders/$orderId/status", [
    'status' => 'processing'
], $sellerToken);

if ($postDeliveredGuard['code'] === 400) {
    logStep('Terminal Guard (delivered -> processing rejected)', 'PASS', 'Server correctly rejected modification to delivered order with HTTP 400: ' . ($postDeliveredGuard['body']['message'] ?? ''));
} else {
    logStep('Terminal Guard (delivered -> processing rejected)', 'FAIL', 'Server allowed modification to delivered order: HTTP ' . $postDeliveredGuard['code']);
    $allPassed = false;
}

// ---------------------------------------------------------------------------
// Step 11: Final Full Live Tracking Verification (100% Complete)
// ---------------------------------------------------------------------------
$finalTrack = httpGet("$baseUrl/customer/orders/$orderNumber/tracking", $customerToken);
if ($finalTrack['code'] === 200) {
    $d = $finalTrack['body']['data'];
    if ($d['order_status'] === 'delivered' && $d['progress_percent'] === 100 && count($d['timeline']) >= 4) {
        logStep('Final Tracking Verification (100% Delivered)', 'PASS', "Tracking by order_number #$orderNumber verified. 100% completed, " . count($d['timeline']) . " persisted timeline events in MariaDB.");
    } else {
        logStep('Final Tracking Verification (100% Delivered)', 'FAIL', json_encode($d));
        $allPassed = false;
    }
} else {
    logStep('Final Tracking Verification (100% Delivered)', 'FAIL', json_encode($finalTrack));
    $allPassed = false;
}

echo "\n====================================================================\n";
if ($allPassed) {
    echo "🎉 ALL 11 END-TO-END TARGETED TRACKING VERIFICATIONS PASSED!\n";
    echo "====================================================================\n";
    exit(0);
} else {
    echo "❌ SOME VERIFICATIONS FAILED.\n";
    echo "====================================================================\n";
    exit(1);
}
