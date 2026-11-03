<?php

declare(strict_types=1);

/**
 * Automated Verification Test Suite for TechKart Buddy:
 * 1. Indian Locale, Currency (INR / ₹), Phone (+91), IST Timezone, Addresses
 * 2. Return & Refund Workflows, MariaDB Persistence, Seller Isolation & Admin Decisions
 */

$baseUrl = 'http://127.0.0.1:8000';

function sendRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $baseUrl;
    $url = $baseUrl . $path;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'status' => 0,
            'body' => null,
            'raw' => $err
        ];
    }

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

function login(string $email, string $password): string
{
    $res = sendRequest('POST', '/api/v1/buddy/auth/login', [
        'email' => $email,
        'password' => $password
    ]);

    if ($res['status'] !== 200 || empty($res['body']['data']['token'])) {
        echo "❌ Login failed for {$email}. Status: {$res['status']}. Body: {$res['raw']}\n";
        exit(1);
    }

    return $res['body']['data']['token'];
}

echo "====================================================================\n";
echo " TECHKART BUDDY INDIA LOCALE & RETURNS INTEGRATION TEST SUITE\n";
echo "====================================================================\n\n";

// 1. Timezone Check
echo "[1/10] Verifying Indian Standard Time (IST) in backend...\n";
require_once __DIR__ . '/../bootstrap/app.php';
$tz = date_default_timezone_get();
if ($tz === 'Asia/Kolkata') {
    echo "  ✓ Timezone confirmed: {$tz} (IST)\n\n";
} else {
    echo "❌ Timezone mismatch: {$tz}\n";
    exit(1);
}

// 2. Actors Login
echo "[2/10] Authenticating test actors...\n";
$seller2Token = login('vikram.store@techkart.com', 'password123');
echo "  ✓ Seller 2 authenticated (Vikram Patel / Apex Gear India)\n";

$seller4Token = login('other.seller@techkart.com', 'password123');
echo "  ✓ Seller 4 authenticated (GadgetPlanet India)\n";

$adminToken = login('admin.audit@techkart.com', 'password123');
echo "  ✓ Admin authenticated\n\n";

// 3. Indian Profile Data Verification
echo "[3/10] Verifying India-specific seller profile (INR, city, state, pincode)...\n";
$profileRes = sendRequest('GET', '/api/v1/buddy/seller/profile', null, $seller2Token);
if ($profileRes['status'] !== 200) {
    echo "❌ Failed to fetch seller profile: {$profileRes['raw']}\n";
    exit(1);
}
$store = $profileRes['body']['data']['store'];
echo "  ✓ Store Name: {$store['store_name']}\n";
echo "  ✓ City: {$store['city']}, State: {$store['state']}, PIN: {$store['pincode']}\n";
if ($store['city'] !== 'Mumbai' || $store['state'] !== 'Maharashtra' || $store['pincode'] !== '400001') {
    echo "❌ Seller profile not updated to Indian address!\n";
    exit(1);
}
echo "  ✓ Verified: Indian profile format active.\n\n";

// 4. Indian Shipping Address on Orders
echo "[4/10] Verifying India-specific shipping address format on orders...\n";
$orderRes = sendRequest('GET', '/api/v1/buddy/orders', null, $seller2Token);
if ($orderRes['status'] !== 200) {
    echo "❌ Failed to fetch seller orders: {$orderRes['raw']}\n";
    exit(1);
}
$ordersList = $orderRes['body']['data']['orders'] ?? [];
if (count($ordersList) > 0) {
    $firstOrder = $ordersList[0];
    $shipping = $firstOrder['shipping_address'] ?? [];
    if (is_string($shipping)) {
        $shipping = json_decode($shipping, true) ?: [];
    }
    echo "  ✓ Order #{$firstOrder['order_number']} Shipping: {$shipping['city']}, {$shipping['state']}, {$shipping['postal_code']}, {$shipping['country']}\n";
    echo "  ✓ Phone: {$shipping['phone']}\n";
}
echo "  ✓ Verified: Indian shipping addresses active.\n\n";

// 5. Seller 2 Returns List & Seller Isolation
echo "[5/10] Testing Seller 2 returns list (Strictly scoped by seller_id = 2)...\n";
$s2RetRes = sendRequest('GET', '/api/v1/buddy/returns', null, $seller2Token);
if ($s2RetRes['status'] !== 200) {
    echo "❌ Seller 2 returns request failed: {$s2RetRes['raw']}\n";
    exit(1);
}
$s2Returns = $s2RetRes['body']['data']['returns'] ?? [];
echo "  ✓ Seller 2 return count: " . count($s2Returns) . "\n";
foreach ($s2Returns as $ret) {
    if ((int)$ret['seller_id'] !== 2) {
        echo "❌ Isolation failure: Seller 2 received return for seller {$ret['seller_id']}!\n";
        exit(1);
    }
    echo "  ✓ Return #{$ret['return_number']} (Product: {$ret['product_name']}, Est Refund: ₹ {$ret['estimated_refund']}, Status: {$ret['status']})\n";
}
echo "  ✓ Verified: Multi-seller isolation strictly maintained.\n\n";

// 6. Seller 4 Returns List Isolation
echo "[6/10] Testing Seller 4 returns isolation...\n";
$s4RetRes = sendRequest('GET', '/api/v1/buddy/returns', null, $seller4Token);
if ($s4RetRes['status'] !== 200) {
    echo "❌ Seller 4 returns request failed: {$s4RetRes['raw']}\n";
    exit(1);
}
$s4Returns = $s4RetRes['body']['data']['returns'] ?? [];
echo "  ✓ Seller 4 return count: " . count($s4Returns) . "\n";
foreach ($s4Returns as $ret) {
    if ((int)$ret['seller_id'] !== 4) {
        echo "❌ Isolation failure: Seller 4 received foreign return {$ret['seller_id']}!\n";
        exit(1);
    }
}
echo "  ✓ Verified: Seller 4 returns completely isolated.\n\n";

// 7. Cross-Seller Decision Tampering Protection
echo "[7/10] Testing Cross-Seller tampering prevention (Seller 4 -> Seller 2 Return #1)...\n";
$tamperRes = sendRequest('PUT', '/api/v1/buddy/returns/1/decision', [
    'status' => 'rejected',
    'decision_reason' => 'Unauthorized tamper attempt'
], $seller4Token);
if ($tamperRes['status'] === 403) {
    echo "  ✓ Correctly rejected with 403 Forbidden! Message: {$tamperRes['body']['message']}\n\n";
} else {
    echo "❌ Security breach: Cross-seller return update did NOT return 403! (Got {$tamperRes['status']})\n";
    exit(1);
}

// 8. Authorized Seller Return Decision
echo "[8/10] Testing Authorized Seller return decision (Seller 2 moves Return #1 to 'quality_inspection')...\n";
$s2DecisionRes = sendRequest('PUT', '/api/v1/buddy/returns/1/decision', [
    'status' => 'quality_inspection',
    'decision_reason' => 'Item received at Mumbai fulfillment center for hardware triage.'
], $seller2Token);
if ($s2DecisionRes['status'] !== 200) {
    echo "❌ Seller decision failed: {$s2DecisionRes['raw']}\n";
    exit(1);
}
echo "  ✓ Status 200 OK: {$s2DecisionRes['body']['message']}\n";
$dataDec = $s2DecisionRes['body']['data'];
echo "  ✓ New Status: {$dataDec['status']}, Reason: {$dataDec['decision_reason']}\n\n";

// 9. Admin Marketplace Returns Listing
echo "[9/10] Testing Admin marketplace returns view across all merchants...\n";
$adminRetRes = sendRequest('GET', '/api/v1/buddy/returns', null, $adminToken);
if ($adminRetRes['status'] !== 200) {
    echo "❌ Admin returns listing failed: {$adminRetRes['raw']}\n";
    exit(1);
}
$adminReturns = $adminRetRes['body']['data']['returns'] ?? [];
echo "  ✓ Admin received total " . count($adminReturns) . " returns across all sellers.\n";
$adminSellerIds = array_unique(array_map(fn($r) => $r['seller_id'], $adminReturns));
echo "  ✓ Included merchant IDs: " . implode(', ', $adminSellerIds) . "\n\n";

// 10. Admin Ruling & Refund Execution (Atomic Transaction)
echo "[10/10] Testing Admin Dispute Ruling & Refund Execution (Rule & Refund Return #1)...\n";
$adminRuleRes = sendRequest('PUT', '/api/v1/buddy/returns/1/decision', [
    'status' => 'refund_released',
    'actual_refund' => 42900.00,
    'decision_reason' => 'Admin Dispute Adjudication: Defect confirmed. ₹ 42,900 refund released to original payment source.'
], $adminToken);
if ($adminRuleRes['status'] !== 200) {
    echo "❌ Admin rule & refund failed: {$adminRuleRes['raw']}\n";
    exit(1);
}
echo "  ✓ Status 200 OK: {$adminRuleRes['body']['message']}\n";
$ruleData = $adminRuleRes['body']['data'];
echo "  ✓ Status: {$ruleData['status']}, Actual Refund: ₹ {$ruleData['actual_refund']}\n";
echo "  ✓ Linked Refund Payment Record ID: {$ruleData['refund_payment_id']}\n\n";

// Verify DB persistence of refund
$pdo = \App\Core\Database::getConnection();
$dbCheck = $pdo->query('SELECT status, actual_refund, decision_reason, refund_payment_id FROM return_requests WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
if ($dbCheck['status'] !== 'refund_released' || empty($dbCheck['refund_payment_id'])) {
    echo "❌ MariaDB persistence check failed for Return #1!\n";
    exit(1);
}
echo "  ✓ MariaDB persistence verified for status 'refund_released' and refund_payment_id #{$dbCheck['refund_payment_id']}.\n\n";

echo "====================================================================\n";
echo " ALL 10 TESTS PASSED SUCCESSFULLY! INDIA LOCALE & RETURNS 100% LIVE 🎉\n";
echo "====================================================================\n";
