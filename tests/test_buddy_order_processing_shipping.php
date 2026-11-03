<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

echo "====================================================================\n";
echo "   TECHKART BUDDY: ORDER PROCESSING & SHIPPING STATUS TEST SUITE     \n";
echo "====================================================================\n\n";

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function apiRequest(string $method, string $path, array $data = [], ?string $token = null): array
{
    global $baseUrl;
    $url = $baseUrl . $path;
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return ['status' => $httpCode, 'error' => $error, 'body' => null];
    }

    $decoded = json_decode($rawResponse, true);
    return ['status' => $httpCode, 'raw' => $rawResponse, 'body' => $decoded];
}

$pdo = Database::getConnection();

// --- 1. SEED TEST ORDER FOR FULL FULFILLMENT CYCLE ---
echo "[Step 1] Seeding test order for complete fulfillment lifecycle (Order 99771)...\n";
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99771, 99772)");
$pdo->exec("DELETE FROM audit_logs WHERE target_id IN ('99771', '99772')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99771, 99772)");
$pdo->exec("DELETE FROM orders WHERE id IN (99771, 99772)");

$shippingJson = json_encode([
    'recipient_name' => 'Samiul Karim',
    'phone' => '+880 1819-223344',
    'address' => 'House 5, Road 2, Sector 9, Uttara',
    'city' => 'Dhaka',
]);

// Order 99771: Starts in 'placed'
$pdo->prepare("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address)
               VALUES (99771, 'TK-TEST-CYCLE-01', 1, 16500.00, 'paid', 'placed', :ship)")
    ->execute(['ship' => $shippingJson]);
$pdo->exec("INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
            VALUES (997711, 99771, 2, 2, 16500.00, 1, 16500.00, 'placed')");

// Order 99772: Multi-item or secondary seller test order (owned by seller 4)
$pdo->prepare("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address)
               VALUES (99772, 'TK-TEST-S4-02', 1, 7500.00, 'paid', 'processing', :ship)")
    ->execute(['ship' => $shippingJson]);
$pdo->exec("INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
            VALUES (997721, 99772, 4, 3, 7500.00, 1, 7500.00, 'processing')");

echo "SUCCESS: Seeded test orders 99771 and 99772.\n\n";

// --- 2. AUTHENTICATION ---
echo "[Step 2] Authenticating actors...\n";
$s2Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'vikram.store@techkart.com', 'password' => 'password123']);
$seller2Token = $s2Login['body']['data']['token'];

$s4Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'other.seller@techkart.com', 'password' => 'password123']);
$seller4Token = $s4Login['body']['data']['token'];

$admLogin = apiRequest('POST', '/buddy/auth/login', ['email' => 'admin.audit@techkart.com', 'password' => 'password123']);
$adminToken = $admLogin['body']['data']['token'];

echo "SUCCESS: Logged in Seller 2, Seller 4, and Admin.\n\n";

// --- 3. SELLER OWNERSHIP ENFORCEMENT ON STATUS & TRACKING ---
echo "[Step 3] Enforcing Seller Ownership (Seller 4 updating Order 99771 owned by Seller 2)...\n";
$crossUpdate = apiRequest('PATCH', '/buddy/orders/99771/status', ['status' => 'processing'], $seller4Token);
if ($crossUpdate['status'] === 403) {
    echo "SUCCESS: Cross-seller status update returned 403 Forbidden.\n";
} else {
    echo "FAILED: Expected 403, got {$crossUpdate['status']}\n";
    exit(1);
}

echo "[Step 4] Enforcing Seller Ownership on Tracking (Seller 4 viewing Order 99771 tracking)...\n";
$crossTracking = apiRequest('GET', '/buddy/orders/99771/tracking', [], $seller4Token);
if ($crossTracking['status'] === 403) {
    echo "SUCCESS: Cross-seller tracking view returned 403 Forbidden.\n\n";
} else {
    echo "FAILED: Expected 403, got {$crossTracking['status']}\n";
    exit(1);
}

// --- 4. PREVENT INVALID TRANSITIONS ---
echo "[Step 5] Testing Invalid State Transitions (Skipping placed -> delivered)...\n";
$skipTransition = apiRequest('PATCH', '/buddy/orders/99771/status', ['status' => 'delivered'], $seller2Token);
if ($skipTransition['status'] === 400) {
    echo "SUCCESS: Prevented illegal skip to delivered (400 Bad Request).\n";
} else {
    echo "FAILED: Expected 400 for skipping placed to delivered, got {$skipTransition['status']}\n";
    exit(1);
}

echo "[Step 6] Testing Invalid State Transitions (Skipping placed -> shipped directly)...\n";
$skipShipped = apiRequest('PATCH', '/buddy/orders/99771/status', ['status' => 'shipped'], $seller2Token);
if ($skipShipped['status'] === 400) {
    echo "SUCCESS: Prevented illegal skip from placed to shipped (400 Bad Request).\n\n";
} else {
    echo "FAILED: Expected 400 for skipping placed to shipped, got {$skipShipped['status']}\n";
    exit(1);
}

// --- 5. VALID TRANSITION: placed -> processing ---
echo "[Step 7] Valid Transition: placed -> processing (Order 99771)...\n";
$procRes = apiRequest('PATCH', '/buddy/orders/99771/status', [
    'status' => 'processing',
    'current_location' => 'Seller Warehouse Packaging Station',
    'description' => 'Order verified, items packaged and invoice attached.',
], $seller2Token);

if ($procRes['status'] === 200) {
    echo "SUCCESS: Order 99771 updated to processing.\n";
    echo "  -> Location: {$procRes['body']['data']['current_location']}\n";
} else {
    echo "FAILED: Transition to processing failed. Status: {$procRes['status']}\n";
    print_r($procRes);
    exit(1);
}

// --- 6. VALID TRANSITION: processing -> shipped with Courier & Tracking Details ---
echo "[Step 8] Valid Transition: processing -> shipped with Courier & Tracking Details...\n";
$trackingNum = 'APX-982140';
$courierName = 'Apex Courier Express';
$courierPhone = '+880 1711-889900';
$curLoc = 'Dhanmondi Regional Sorting Facility';
$nextStop = 'Mohakhali Central Hub';
$shipDesc = 'Dispatched from seller hub and handed over to courier for regional sorting.';

$shipRes = apiRequest('PATCH', '/buddy/orders/99771/status', [
    'status'           => 'shipped',
    'tracking_number'  => $trackingNum,
    'courier_name'     => $courierName,
    'courier_phone'    => $courierPhone,
    'current_location' => $curLoc,
    'next_stop'        => $nextStop,
    'description'      => $shipDesc,
], $seller2Token);

if ($shipRes['status'] === 200) {
    echo "SUCCESS: Order 99771 updated to shipped.\n";
    echo "  -> Courier: {$shipRes['body']['data']['courier_name']}, Tracking: {$shipRes['body']['data']['tracking_number']}\n";
    echo "  -> Current: {$shipRes['body']['data']['current_location']}, Next: {$shipRes['body']['data']['next_stop']}\n\n";
} else {
    echo "FAILED: Transition to shipped failed. Status: {$shipRes['status']}\n";
    print_r($shipRes);
    exit(1);
}

// --- 7. VERIFY LIVE TRACKING TIMELINE API (GET /buddy/orders/{id}/tracking) ---
echo "[Step 9] Verifying Live Tracking Timeline (GET /buddy/orders/99771/tracking)...\n";
$trackRes = apiRequest('GET', '/buddy/orders/99771/tracking', [], $seller2Token);
if ($trackRes['status'] === 200) {
    $trackData = $trackRes['body']['data'] ?? [];
    echo "SUCCESS: Tracking details retrieved.\n";
    echo "  -> Status: {$trackData['status']}, Status Step: {$trackData['status_step']}/4\n";
    echo "  -> Headline: {$trackData['headline']}\n";
    echo "  -> Courier: {$trackData['courier_name']} ({$trackData['courier_phone']})\n";
    echo "  -> Timeline Events Count: " . count($trackData['timeline']) . "\n";
    foreach ($trackData['timeline'] as $idx => $evt) {
        echo "     [Event #" . ($idx + 1) . "] Status: {$evt['status']} | Title: {$evt['title']} | Location: {$evt['location']}\n";
    }
    echo "\n";
} else {
    echo "FAILED: Tracking details query failed. Status: {$trackRes['status']}\n";
    print_r($trackRes);
    exit(1);
}

// --- 8. VALID TRANSITION: shipped -> delivered ---
echo "[Step 10] Valid Transition: shipped -> delivered (Order 99771)...\n";
$delivRes = apiRequest('PATCH', '/buddy/orders/99771/status', [
    'status'           => 'delivered',
    'current_location' => 'Delivered to Customer Doorstep',
    'next_stop'        => 'Recipient Verified & Signed',
    'description'      => 'Package handed over to Samiul Karim. Delivery signed.',
], $seller2Token);

if ($delivRes['status'] === 200) {
    echo "SUCCESS: Order 99771 updated to delivered.\n";
    echo "  -> New Status: {$delivRes['body']['data']['order_status']}\n";
} else {
    echo "FAILED: Transition to delivered failed. Status: {$delivRes['status']}\n";
    print_r($delivRes);
    exit(1);
}

// --- 9. TERMINAL STATE PROTECTION: Cannot transition out of delivered ---
echo "[Step 11] Testing Terminal State Guard (Cannot modify delivered order -> Expect 400)...\n";
$illegalFromDelivered = apiRequest('PATCH', '/buddy/orders/99771/status', ['status' => 'processing'], $seller2Token);
if ($illegalFromDelivered['status'] === 400) {
    echo "SUCCESS: Terminal state protected! Delivered order cannot revert.\n\n";
} else {
    echo "FAILED: Expected 400 from delivered, got {$illegalFromDelivered['status']}\n";
    exit(1);
}

// --- 10. ADMIN MARKETPLACE STATUS AND TRACKING ACCESS ---
echo "[Step 12] Testing Admin Oversight on Tracking & Fulfillment...\n";
$adminTrack = apiRequest('GET', '/buddy/orders/99771/tracking', [], $adminToken);
if ($adminTrack['status'] === 200 && ($adminTrack['body']['data']['status'] ?? '') === 'delivered') {
    echo "SUCCESS: Admin successfully accessed complete multi-event tracking timeline.\n";
} else {
    echo "FAILED: Admin tracking access failed. Status: {$adminTrack['status']}\n";
    print_r($adminTrack);
    exit(1);
}

// Clean up test orders
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99771, 99772)");
$pdo->exec("DELETE FROM audit_logs WHERE target_id IN ('99771', '99772')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99771, 99772)");
$pdo->exec("DELETE FROM orders WHERE id IN (99771, 99772)");
echo "SUCCESS: Cleaned up test records.\n\n";

echo "====================================================================\n";
echo "   ALL PROCESSING & SHIPPING STATUS CHECKS PASSED (12/12)           \n";
echo "====================================================================\n";
