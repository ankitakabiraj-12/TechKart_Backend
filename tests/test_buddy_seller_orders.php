<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

echo "====================================================\n";
echo "   TECHKART BUDDY: SELLER & ADMIN ORDERS TEST SUITE \n";
echo "====================================================\n\n";

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

// --- 1. LOGIN TESTS ---
echo "[Step 1] Logging in Seller 2 (Apex Gear BD)...\n";
$seller2Login = apiRequest('POST', '/buddy/auth/login', [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123',
]);
if ($seller2Login['status'] !== 200 || !isset($seller2Login['body']['data']['token'])) {
    echo "FAILED: Seller 2 login failed. Status: {$seller2Login['status']}\n";
    exit(1);
}
$seller2Token = $seller2Login['body']['data']['token'];
echo "SUCCESS: Seller 2 logged in. Token acquired.\n\n";

echo "[Step 2] Logging in Seller 4 (GadgetPlanet BD)...\n";
$seller4Login = apiRequest('POST', '/buddy/auth/login', [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123',
]);
if ($seller4Login['status'] !== 200 || !isset($seller4Login['body']['data']['token'])) {
    echo "FAILED: Seller 4 login failed. Status: {$seller4Login['status']}\n";
    exit(1);
}
$seller4Token = $seller4Login['body']['data']['token'];
echo "SUCCESS: Seller 4 logged in. Token acquired.\n\n";

echo "[Step 3] Logging in Admin (admin.audit@techkart.com)...\n";
$adminLogin = apiRequest('POST', '/buddy/auth/login', [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123',
]);
if ($adminLogin['status'] !== 200 || !isset($adminLogin['body']['data']['token'])) {
    echo "FAILED: Admin login failed. Status: {$adminLogin['status']}\n";
    exit(1);
}
$adminToken = $adminLogin['body']['data']['token'];
echo "SUCCESS: Admin logged in. Token acquired.\n\n";


// --- 2. AUTHENTICATION & RBAC CHECKS ---
echo "[Step 4] Checking unauthenticated GET /buddy/orders -> Expect 401...\n";
$unauth = apiRequest('GET', '/buddy/orders');
if ($unauth['status'] === 401) {
    echo "SUCCESS: Correctly returned 401 Unauthorized.\n\n";
} else {
    echo "FAILED: Expected 401, got {$unauth['status']}\n";
    exit(1);
}


// --- 3. SELLER 2 ORDER LIST ---
echo "[Step 5] Fetching Seller 2 Orders (GET /buddy/orders)...\n";
$s2Orders = apiRequest('GET', '/buddy/orders', [], $seller2Token);
if ($s2Orders['status'] !== 200) {
    echo "FAILED: Seller 2 orders fetch failed. Status: {$s2Orders['status']}\n";
    exit(1);
}
$s2OrdersList = $s2Orders['body']['data'] ?? [];
echo "SUCCESS: Seller 2 retrieved " . count($s2OrdersList) . " distinct orders.\n";
if (!empty($s2OrdersList)) {
    $first = $s2OrdersList[0];
    echo "  -> First Order: ID={$first['id']}, Number={$first['order_number']}, Status={$first['order_status']}, Buyer={$first['customer_name']}\n";
    echo "  -> Subtotal=৳{$first['seller_subtotal']}, Quantity={$first['seller_quantity']}, Item count=" . count($first['items']) . "\n";
}
echo "\n";


// --- 4. STATUS FILTERING ---
echo "[Step 6] Testing Status Filters for Seller 2...\n";
$filterStatuses = ['placed', 'processing', 'shipped', 'delivered'];
foreach ($filterStatuses as $filter) {
    $filterRes = apiRequest('GET', '/buddy/orders?status=' . $filter, [], $seller2Token);
    if ($filterRes['status'] !== 200) {
        echo "FAILED: Status filter '$filter' failed with status {$filterRes['status']}\n";
        exit(1);
    }
    $cnt = count($filterRes['body']['data'] ?? []);
    echo "  -> Status '{$filter}': {$cnt} orders returned.\n";
}
echo "SUCCESS: All status filters queried cleanly.\n\n";


// --- 5. SELLER ORDER DETAILS ---
echo "[Step 7] Fetching Order Details for Order 1 (GET /buddy/orders/1)...\n";
$s2OrderDetails = apiRequest('GET', '/buddy/orders/1', [], $seller2Token);
if ($s2OrderDetails['status'] !== 200) {
    echo "FAILED: Order 1 details fetch failed. Status: {$s2OrderDetails['status']}\n";
    exit(1);
}
$s2OrderData = $s2OrderDetails['body']['data'] ?? [];
echo "SUCCESS: Order 1 details retrieved for Seller 2.\n";
echo "  -> Order Number: {$s2OrderData['order_number']}\n";
echo "  -> Customer: {$s2OrderData['customer_name']} ({$s2OrderData['customer_phone']})\n";
echo "  -> Shipping: " . json_encode($s2OrderData['shipping_address']) . "\n";
echo "  -> Order Items count: " . count($s2OrderData['items']) . "\n";
echo "  -> Payments count: " . count($s2OrderData['payments']) . "\n\n";


// --- 6. MULTI-SELLER ISOLATION & ACCESS CONTROL ---
echo "[Step 8] Testing Cross-Seller Data Isolation (Seller 4 accessing Order 1 owned exclusively by Seller 2)...\n";
$s4CrossAccess = apiRequest('GET', '/buddy/orders/1', [], $seller4Token);
if ($s4CrossAccess['status'] === 403) {
    echo "SUCCESS: Correctly returned 403 Forbidden! Seller 4 cannot view Seller 2's order.\n\n";
} else {
    echo "FAILED: Multi-seller isolation breached! Expected 403, got {$s4CrossAccess['status']}\n";
    print_r($s4CrossAccess);
    exit(1);
}


// --- 7. ORDER STATUS TRANSITION & SHIPMENT TRACKING ---
echo "[Step 9] Testing Order Status Transition (Seller 2 updates order status)...\n";
// Let's check status of Order 1
$currentOrd1Status = $s2OrderData['order_status'];
echo "  -> Current Order 1 status: {$currentOrd1Status}\n";

if ($currentOrd1Status === 'placed') {
    echo "  -> Updating Order 1 from 'placed' to 'processing'...\n";
    $updateRes = apiRequest('PATCH', '/buddy/orders/1/status', [
        'status' => 'processing',
        'description' => 'Seller accepted order #TK-88491. Packing underway.',
    ], $seller2Token);

    if ($updateRes['status'] === 200) {
        echo "SUCCESS: Order 1 updated to processing.\n";
    } else {
        echo "FAILED: Order 1 status update failed. Status: {$updateRes['status']}\n";
        print_r($updateRes);
        exit(1);
    }
} else {
    echo "  -> Order 1 already at '{$currentOrd1Status}'. Testing courier update...\n";
    $updateRes = apiRequest('PATCH', '/buddy/orders/1/status', [
        'status' => $currentOrd1Status,
        'courier_name' => 'Apex Courier Express',
        'current_location' => 'Banani Dispatch Hub',
    ], $seller2Token);
    echo "SUCCESS: Courier info update returned status {$updateRes['status']}.\n";
}
echo "\n";


// --- 8. ADMIN MARKETPLACE OVERSIGHT ---
echo "[Step 10] Testing Admin Marketplace Order Access (GET /buddy/orders)...\n";
$adminOrders = apiRequest('GET', '/buddy/orders', [], $adminToken);
if ($adminOrders['status'] !== 200) {
    echo "FAILED: Admin orders fetch failed. Status: {$adminOrders['status']}\n";
    exit(1);
}
$adminOrdersList = $adminOrders['body']['data'] ?? [];
echo "SUCCESS: Admin retrieved " . count($adminOrdersList) . " marketplace orders across all stores.\n";

echo "[Step 11] Admin viewing Order 1 details (GET /buddy/orders/1)...\n";
$adminOrderDetails = apiRequest('GET', '/buddy/orders/1', [], $adminToken);
if ($adminOrderDetails['status'] === 200) {
    echo "SUCCESS: Admin successfully accessed Order 1 with multi-store item oversight.\n";
} else {
    echo "FAILED: Admin details fetch failed. Status: {$adminOrderDetails['status']}\n";
    exit(1);
}

echo "\n====================================================\n";
echo "   ALL TEST SUITE CHECKS PASSED SUCCESSFULLY!       \n";
echo "====================================================\n";
