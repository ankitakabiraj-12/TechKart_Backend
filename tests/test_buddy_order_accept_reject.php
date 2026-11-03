<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

echo "====================================================\n";
echo "   TECHKART BUDDY: ORDER ACCEPT / REJECT TEST SUITE \n";
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

// --- 1. SEED TEST ORDERS ---
echo "[Step 1] Preparing test orders in MariaDB...\n";
// Create test order A for Accept testing (owned by seller 2)
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99881, 99882, 99883)");
$pdo->exec("DELETE FROM audit_logs WHERE target_id IN ('99881', '99882', '99883')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99881, 99882, 99883)");
$pdo->exec("DELETE FROM orders WHERE id IN (99881, 99882, 99883)");

$shippingJson = json_encode([
    'recipient_name' => 'Farhan Ahmed',
    'phone' => '+880 1712-345678',
    'address' => 'House 14, Road 7, Sector 3, Uttara',
    'city' => 'Dhaka',
]);

// Order 99881: placed status for Accept test
$pdo->prepare("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address)
               VALUES (99881, 'TK-TEST-ACC-01', 1, 42900.00, 'paid', 'placed', :ship)")
    ->execute(['ship' => $shippingJson]);
$pdo->exec("INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
            VALUES (998811, 99881, 2, 2, 42900.00, 1, 42900.00, 'placed')");

// Order 99882: placed status for Reject test
$pdo->prepare("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address)
               VALUES (99882, 'TK-TEST-REJ-02', 1, 3900.00, 'paid', 'placed', :ship)")
    ->execute(['ship' => $shippingJson]);
$pdo->exec("INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
            VALUES (998821, 99882, 2, 2, 3900.00, 1, 3900.00, 'placed')");

// Order 99883: shipped status for Invalid Transition test
$pdo->prepare("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address)
               VALUES (99883, 'TK-TEST-INV-03', 1, 5500.00, 'paid', 'shipped', :ship)")
    ->execute(['ship' => $shippingJson]);
$pdo->exec("INSERT INTO order_items (id, order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status)
            VALUES (998831, 99883, 2, 2, 5500.00, 1, 5500.00, 'shipped')");

echo "SUCCESS: Seeded test orders 99881 (placed), 99882 (placed), 99883 (shipped).\n\n";

// --- 2. AUTHENTICATION ---
echo "[Step 2] Authenticating test actors...\n";
$s2Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'vikram.store@techkart.com', 'password' => 'password123']);
$seller2Token = $s2Login['body']['data']['token'];

$s4Login = apiRequest('POST', '/buddy/auth/login', ['email' => 'other.seller@techkart.com', 'password' => 'password123']);
$seller4Token = $s4Login['body']['data']['token'];

$admLogin = apiRequest('POST', '/buddy/auth/login', ['email' => 'admin.audit@techkart.com', 'password' => 'password123']);
$adminToken = $admLogin['body']['data']['token'];

echo "SUCCESS: Logged in Seller 2, Seller 4, and Admin.\n\n";


// --- 3. SELLER OWNERSHIP ENFORCEMENT ---
echo "[Step 3] Testing Seller Ownership Enforcement (Seller 4 trying to accept Order 99881 owned by Seller 2)...\n";
$crossAccept = apiRequest('POST', '/buddy/orders/99881/accept', ['notes' => 'Attempting unauthorized accept'], $seller4Token);
if ($crossAccept['status'] === 403) {
    echo "SUCCESS: Correctly returned 403 Forbidden! Cross-seller accept blocked.\n\n";
} else {
    echo "FAILED: Expected 403, got {$crossAccept['status']}\n";
    exit(1);
}

echo "[Step 4] Testing Seller Ownership Enforcement (Seller 4 trying to reject Order 99882 owned by Seller 2)...\n";
$crossReject = apiRequest('POST', '/buddy/orders/99882/reject', ['reason' => 'Unauthorized reject attempt'], $seller4Token);
if ($crossReject['status'] === 403) {
    echo "SUCCESS: Correctly returned 403 Forbidden! Cross-seller reject blocked.\n\n";
} else {
    echo "FAILED: Expected 403, got {$crossReject['status']}\n";
    exit(1);
}


// --- 4. VALIDATION & MANDATORY REJECTION REASON ---
echo "[Step 5] Testing Mandatory Rejection Reason (Empty reason -> Expect 422)...\n";
$emptyReason = apiRequest('POST', '/buddy/orders/99882/reject', ['reason' => ''], $seller2Token);
if ($emptyReason['status'] === 422) {
    echo "SUCCESS: Correctly returned 422 Unprocessable Entity for missing rejection reason.\n";
    echo "  -> Error message: " . json_encode($emptyReason['body']) . "\n\n";
} else {
    echo "FAILED: Expected 422 for empty rejection reason, got {$emptyReason['status']}\n";
    exit(1);
}

echo "[Step 6] Testing Short Rejection Reason (<5 chars -> Expect 422)...\n";
$shortReason = apiRequest('POST', '/buddy/orders/99882/reject', ['reason' => 'No'], $seller2Token);
if ($shortReason['status'] === 422) {
    echo "SUCCESS: Correctly returned 422 for short reason.\n\n";
} else {
    echo "FAILED: Expected 422, got {$shortReason['status']}\n";
    exit(1);
}


// --- 5. PREVENT INVALID TRANSITIONS ---
echo "[Step 7] Testing Invalid Transition (Attempting to accept/reject order already 'shipped' -> Expect 400)...\n";
$invAccept = apiRequest('POST', '/buddy/orders/99883/accept', [], $seller2Token);
if ($invAccept['status'] === 400) {
    echo "SUCCESS: Prevented accepting already shipped order (400 Bad Request).\n";
} else {
    echo "FAILED: Expected 400, got {$invAccept['status']}\n";
    exit(1);
}

$invReject = apiRequest('POST', '/buddy/orders/99883/reject', ['reason' => 'Warehouse closed'], $seller2Token);
if ($invReject['status'] === 400) {
    echo "SUCCESS: Prevented rejecting already shipped order (400 Bad Request).\n\n";
} else {
    echo "FAILED: Expected 400, got {$invReject['status']}\n";
    exit(1);
}


// --- 6. ACCEPT ORDER (SUCCESS PATH & AUDIT LOG) ---
echo "[Step 8] Accepting Order 99881 (POST /buddy/orders/99881/accept)...\n";
$acceptRes = apiRequest('POST', '/buddy/orders/99881/accept', [
    'notes' => 'Seller verified inventory and accepted order #TK-TEST-ACC-01',
], $seller2Token);
if ($acceptRes['status'] === 200) {
    echo "SUCCESS: Order 99881 accepted.\n";
    echo "  -> Response: " . json_encode($acceptRes['body']['data']) . "\n";
} else {
    echo "FAILED: Accept failed with status {$acceptRes['status']}\n";
    print_r($acceptRes);
    exit(1);
}

// Verify database status and audit log for Accept
$dbOrd1 = $pdo->query("SELECT order_status FROM orders WHERE id = 99881")->fetch(PDO::FETCH_ASSOC);
$dbItem1 = $pdo->query("SELECT item_status FROM order_items WHERE order_id = 99881")->fetch(PDO::FETCH_ASSOC);
$dbAudit1 = $pdo->query("SELECT * FROM audit_logs WHERE target_id = '99881' AND action = 'ORDER_ACCEPT'")->fetch(PDO::FETCH_ASSOC);
$dbEvent1 = $pdo->query("SELECT * FROM shipment_events WHERE order_id = 99881 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($dbOrd1['order_status'] === 'processing' && $dbItem1['item_status'] === 'processing' && $dbAudit1 && $dbEvent1) {
    echo "SUCCESS: Order 99881 persisted as 'processing' with verified Audit Log and Shipment Event!\n";
    echo "  -> Audit Log ID: {$dbAudit1['id']}, Action: {$dbAudit1['action']}, Actor: {$dbAudit1['actor_name']}\n";
    echo "  -> Event: {$dbEvent1['title']} ({$dbEvent1['status']})\n\n";
} else {
    echo "FAILED: Verification in DB failed for accept.\n";
    exit(1);
}


// --- 7. REJECT ORDER (SUCCESS PATH & AUDIT LOG) ---
echo "[Step 9] Rejecting Order 99882 (POST /buddy/orders/99882/reject with mandatory reason)...\n";
$rejectionReasonText = 'Item out of stock due to warehouse inventory count variance';
$rejectRes = apiRequest('POST', '/buddy/orders/99882/reject', [
    'reason' => $rejectionReasonText,
], $seller2Token);
if ($rejectRes['status'] === 200) {
    echo "SUCCESS: Order 99882 rejected.\n";
    echo "  -> Response: " . json_encode($rejectRes['body']['data']) . "\n";
} else {
    echo "FAILED: Reject failed with status {$rejectRes['status']}\n";
    print_r($rejectRes);
    exit(1);
}

// Verify database status, rejection reason, and audit log for Reject
$dbOrd2 = $pdo->query("SELECT order_status, rejection_reason FROM orders WHERE id = 99882")->fetch(PDO::FETCH_ASSOC);
$dbItem2 = $pdo->query("SELECT item_status, rejection_reason FROM order_items WHERE order_id = 99882")->fetch(PDO::FETCH_ASSOC);
$dbAudit2 = $pdo->query("SELECT * FROM audit_logs WHERE target_id = '99882' AND action = 'ORDER_REJECT'")->fetch(PDO::FETCH_ASSOC);
$dbEvent2 = $pdo->query("SELECT * FROM shipment_events WHERE order_id = 99882 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($dbOrd2['order_status'] === 'cancelled' &&
    $dbOrd2['rejection_reason'] === $rejectionReasonText &&
    $dbItem2['item_status'] === 'cancelled' &&
    $dbItem2['rejection_reason'] === $rejectionReasonText &&
    $dbAudit2 && $dbEvent2) {
    echo "SUCCESS: Order 99882 persisted as 'cancelled' with mandatory rejection reason and audit log!\n";
    echo "  -> Persisted Rejection Reason: {$dbOrd2['rejection_reason']}\n";
    echo "  -> Audit Log ID: {$dbAudit2['id']}, Action: {$dbAudit2['action']}, Details: {$dbAudit2['details']}\n";
    echo "  -> Shipment Event: {$dbEvent2['title']} ({$dbEvent2['description']})\n\n";
} else {
    echo "FAILED: Verification in DB failed for reject.\n";
    print_r($dbOrd2);
    exit(1);
}


// --- 8. ADMIN ACCESS & ACCEPT/REJECT CAPABILITIES ---
echo "[Step 10] Testing Admin Accept/Reject Oversight...\n";
// Admin viewing order details of rejected order
$adminView = apiRequest('GET', '/buddy/orders/99882', [], $adminToken);
if ($adminView['status'] === 200 && ($adminView['body']['data']['rejection_reason'] ?? '') === $rejectionReasonText) {
    echo "SUCCESS: Admin successfully inspected rejected order details and persisted rejection reason.\n";
} else {
    echo "FAILED: Admin details view did not return expected rejection reason.\n";
    print_r($adminView);
    exit(1);
}

// Clean up test records
$pdo->exec("DELETE FROM shipment_events WHERE order_id IN (99881, 99882, 99883)");
$pdo->exec("DELETE FROM audit_logs WHERE target_id IN ('99881', '99882', '99883')");
$pdo->exec("DELETE FROM order_items WHERE order_id IN (99881, 99882, 99883)");
$pdo->exec("DELETE FROM orders WHERE id IN (99881, 99882, 99883)");
echo "SUCCESS: Test records cleaned up.\n\n";

echo "====================================================\n";
echo "   ALL ACCEPT & REJECT TEST CHECKS PASSED (10/10)   \n";
echo "====================================================\n";
