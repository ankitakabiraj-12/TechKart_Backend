<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function apiRequest(string $method, string $path, array $data = [], ?string $token = null): array
{
    global $baseUrl;
    $ch = curl_init();
    $url = $baseUrl . $path;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body'   => json_decode($response, true) ?: $response,
    ];
}

echo "====================================================================\n";
echo "   TECHKART BUDDY: SHIPMENT TRACKING API AUDIT TEST SUITE           \n";
echo "====================================================================\n\n";

$pdo = Database::getConnection();

// --- 1. SEED REALISTIC ORDER & SHIPMENT EVENTS ---
echo "[Audit Step 1] Seeding test order with rich MariaDB shipment_events...\n";
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99881, 99882)");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99881, 99882)");
$pdo->exec("DELETE FROM orders WHERE id IN (99881, 99882)");

$testTrackingNum = 'APX-AUDIT-99881';
$testOrderNum = 'TK-ORD-AUDIT-99881';

$pdo->prepare(
    "INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, tracking_number, courier_name, courier_phone, estimated_delivery, estimated_time, current_location, next_stop, shipping_address)
     VALUES (99881, :ord_num, 3, 2499.00, 'paid', 'shipped', :track_num, 'Apex Courier Express', '+880 1711-223344', 'Oct 30, 2026', 'On Schedule', 'Dhanmondi Regional Sorting Facility', 'Mohakhali Central Hub', :addr)"
)->execute([
    'ord_num'   => $testOrderNum,
    'track_num' => $testTrackingNum,
    'addr'      => json_encode([
        'recipient_name' => 'Fahim Ahmed',
        'address'        => 'House 42, Road 7, Sector 3',
        'city'           => 'Uttara, Dhaka',
        'phone'          => '+880 1799-887766',
    ]),
]);

// Seed order item belonging to Seller 2 (Apex Gear BD)
$pdo->prepare(
    "INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
     VALUES (99881, 99881, 2, 2, 2499.00, 1, 2499.00, 'shipped')"
)->execute();

// Seed chronological shipment_events: placed -> processing -> shipped
$pdo->prepare(
    "INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id, created_at)
     VALUES (99881, 'placed', 'Order Placed & Verified', 'Customer confirmed payment and placed order.', 'Order Management Server', 'Placed', 'system', 3, '2026-10-28 08:30:00')"
)->execute();

$pdo->prepare(
    "INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id, created_at)
     VALUES (99881, 'processing', 'Package Scanned & Packed', 'Merchant accepted order and completed packing.', 'Seller Warehouse Station 4', 'Processing', 'seller', 2, '2026-10-28 09:15:00')"
)->execute();

$pdo->prepare(
    "INSERT INTO shipment_events (order_id, status, title, description, location, tag_label, actor_role, actor_id, created_at)
     VALUES (99881, 'shipped', 'Departed Sorting Facility', 'Package handed over to Apex Courier Express. In transit to destination hub.', 'Dhanmondi Regional Sorting Facility', 'In Transit', 'seller', 2, '2026-10-28 10:45:00')"
)->execute();

echo "SUCCESS: Seeded order 99881 with 3 chronological shipment_events.\n\n";

// --- 2. AUTHENTICATE ACTORS ---
echo "[Audit Step 2] Authenticating actors (Seller 2, Seller 4, Admin)...\n";
$seller2Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'vikram.store@techkart.com', 'password' => 'password123']);
$seller2Token = $seller2Login['body']['data']['token'] ?? null;

$seller4Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'other.seller@techkart.com', 'password' => 'password123']);
$seller4Token = $seller4Login['body']['data']['token'] ?? null;

$adminLogin = apiRequest('POST', '/buddy/auth/login', ['email' => 'admin.audit@techkart.com', 'password' => 'password123']);
$adminToken = $adminLogin['body']['data']['token'] ?? null;

if (!$seller2Token || !$seller4Token || !$adminToken) {
    echo "FAILED: Authentication failed.\n";
    exit(1);
}
echo "SUCCESS: Authenticated Seller 2, Seller 4, and Admin.\n\n";

// --- 3. TRACKING LOOKUP: NUMERIC ORDER ID ---
echo "[Audit Step 3] Testing tracking lookup by numeric ID (/buddy/orders/99881/tracking)...\n";
$resById = apiRequest('GET', '/buddy/orders/99881/tracking', [], $seller2Token);
if ($resById['status'] === 200 && ($resById['body']['data']['order_id'] ?? 0) === 99881) {
    echo "SUCCESS: Numeric ID lookup returned order 99881.\n\n";
} else {
    echo "FAILED: Numeric ID lookup failed. Code: {$resById['status']}\n";
    print_r($resById);
    exit(1);
}

// --- 4. TRACKING LOOKUP: ORDER NUMBER ---
echo "[Audit Step 4] Testing tracking lookup by order_number (/buddy/orders/{$testOrderNum}/tracking)...\n";
$resByOrdNum = apiRequest('GET', "/buddy/orders/{$testOrderNum}/tracking", [], $seller2Token);
if ($resByOrdNum['status'] === 200 && ($resByOrdNum['body']['data']['order_number'] ?? '') === $testOrderNum) {
    echo "SUCCESS: Order number lookup returned matching record.\n\n";
} else {
    echo "FAILED: Order number lookup failed. Code: {$resByOrdNum['status']}\n";
    print_r($resByOrdNum);
    exit(1);
}

// --- 5. TRACKING LOOKUP: TRACKING NUMBER ---
echo "[Audit Step 5] Testing tracking lookup by tracking_number (/buddy/orders/{$testTrackingNum}/tracking)...\n";
$resByTrackNum = apiRequest('GET', "/buddy/orders/{$testTrackingNum}/tracking", [], $seller2Token);
if ($resByTrackNum['status'] === 200 && ($resByTrackNum['body']['data']['tracking_number'] ?? '') === $testTrackingNum) {
    echo "SUCCESS: Tracking number lookup returned matching record.\n\n";
} else {
    echo "FAILED: Tracking number lookup failed. Code: {$resByTrackNum['status']}\n";
    print_r($resByTrackNum);
    exit(1);
}

// --- 6. CHRONOLOGICAL TIMELINE & COURIER/LOCATION/ETA AUDIT ---
echo "[Audit Step 6] Auditing chronological timeline, courier, location, and ETA data...\n";
$data = $resById['body']['data'];
$timeline = $data['timeline'] ?? [];

if (count($timeline) !== 3) {
    echo "FAILED: Expected 3 timeline events, got " . count($timeline) . "\n";
    exit(1);
}

// Verify chronological order: placed (08:30) -> processing (09:15) -> shipped (10:45)
if ($timeline[0]['status'] !== 'placed' || $timeline[1]['status'] !== 'processing' || $timeline[2]['status'] !== 'shipped') {
    echo "FAILED: Timeline events are not in chronological order!\n";
    print_r($timeline);
    exit(1);
}

// Verify courier details
if ($data['courier_name'] !== 'Apex Courier Express' || $data['courier_phone'] !== '+880 1711-223344') {
    echo "FAILED: Courier details mismatch.\n";
    exit(1);
}

// Verify current location and next stop
if ($data['current_location'] !== 'Dhanmondi Regional Sorting Facility' || $data['next_stop'] !== 'Mohakhali Central Hub') {
    echo "FAILED: Location details mismatch.\n";
    exit(1);
}

// Verify ETA and headline
if ($data['estimated_delivery'] !== 'Oct 30, 2026' || $data['status_step'] !== 3) {
    echo "FAILED: ETA or status step mismatch.\n";
    exit(1);
}
echo "SUCCESS: Chronological timeline (3 events), courier, location, and ETA verified.\n\n";

// --- 7. SELLER ISOLATION ---
echo "[Audit Step 7] Testing Seller Isolation (Seller 4 accessing Order 99881 owned by Seller 2)...\n";
$unauthSeller = apiRequest('GET', '/buddy/orders/99881/tracking', [], $seller4Token);
if ($unauthSeller['status'] === 403) {
    echo "SUCCESS: Cross-seller tracking view correctly rejected with 403 Forbidden.\n\n";
} else {
    echo "FAILED: Expected 403 Forbidden for unauthorized seller, got {$unauthSeller['status']}\n";
    exit(1);
}

// --- 8. ADMIN MARKETPLACE ACCESS ---
echo "[Audit Step 8] Testing Admin Marketplace Oversight Access...\n";
$adminRes = apiRequest('GET', '/buddy/orders/99881/tracking', [], $adminToken);
if ($adminRes['status'] === 200 && ($adminRes['body']['data']['order_id'] ?? 0) === 99881) {
    echo "SUCCESS: Admin successfully accessed complete marketplace tracking data.\n\n";
} else {
    echo "FAILED: Admin access failed. Status: {$adminRes['status']}\n";
    exit(1);
}

// --- 9. TERMINAL STATE DELIVERY TRANSITION & AUDIT ---
echo "[Audit Step 9] Transitioning order to delivered and verifying final event append...\n";
$deliverRes = apiRequest('PATCH', '/buddy/orders/99881/status', [
    'status'           => 'delivered',
    'current_location' => 'Delivered to Customer Doorstep',
    'next_stop'        => 'Recipient Verified & Signed',
    'description'      => 'Package handed over and signed by recipient.',
], $seller2Token);

if ($deliverRes['status'] === 200) {
    $finalTrack = apiRequest('GET', '/buddy/orders/99881/tracking', [], $seller2Token);
    $finalData = $finalTrack['body']['data'] ?? [];
    $finalTimeline = $finalData['timeline'] ?? [];
    if (count($finalTimeline) === 4 && $finalData['status'] === 'delivered' && $finalData['status_step'] === 4) {
        echo "SUCCESS: Delivered event appended to timeline (total 4 events, step 4/4).\n\n";
    } else {
        echo "FAILED: Expected 4 events and delivered status. Got: " . count($finalTimeline) . "\n";
        exit(1);
    }
} else {
    echo "FAILED: Failed to update status to delivered.\n";
    exit(1);
}

// Clean up test records
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99881, 99882)");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99881, 99882)");
$pdo->exec("DELETE FROM orders WHERE id IN (99881, 99882)");
echo "SUCCESS: Cleaned up audit test records.\n\n";

echo "====================================================================\n";
echo "   SHIPMENT TRACKING AUDIT SUITE PASSED ALL CHECKS (9/9)            \n";
echo "====================================================================\n";
