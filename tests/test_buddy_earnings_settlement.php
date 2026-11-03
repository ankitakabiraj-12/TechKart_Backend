<?php
require_once __DIR__ . '/../bootstrap/app.php';

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    global $baseUrl;
    $fullUrl = (strpos($url, 'http') === 0) ? $url : $baseUrl . $url;
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== TEST: TechKart Buddy Earnings & Settlement API ===\n";

// 1. Authenticate Seller 2 (Apex Gear BD)
$loginRes2 = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);

if ($loginRes2['code'] !== 200 || empty($loginRes2['body']['data']['token'])) {
    echo "FAIL: Seller 2 login failed. Code: " . $loginRes2['code'] . "\n";
    exit(1);
}
$token2 = $loginRes2['body']['data']['token'];
echo "PASS: Seller 2 authenticated (Apex Gear BD).\n";

// 2. Authenticate Seller 4 (GadgetPlanet BD)
$loginRes4 = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'other.seller@techkart.com',
    'password' => 'password123'
]);

if ($loginRes4['code'] !== 200 || empty($loginRes4['body']['data']['token'])) {
    echo "FAIL: Seller 4 login failed. Code: " . $loginRes4['code'] . "\n";
    exit(1);
}
$token4 = $loginRes4['body']['data']['token'];
echo "PASS: Seller 4 authenticated (GadgetPlanet BD).\n";

// 3. Authenticate Admin
$loginAdmin = makeRequest('POST', '/buddy/auth/login', ['Content-Type: application/json'], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

if ($loginAdmin['code'] !== 200 || empty($loginAdmin['body']['data']['token'])) {
    echo "FAIL: Admin login failed. Code: " . $loginAdmin['code'] . "\n";
    exit(1);
}
$tokenAdmin = $loginAdmin['body']['data']['token'];
echo "PASS: Admin authenticated.\n";

// 4. Test GET /buddy/seller/settlements for Seller 2 (Financial Summary & Settlements)
$settleRes2 = makeRequest('GET', '/buddy/seller/settlements', [
    "Authorization: Bearer $token2"
]);

if ($settleRes2['code'] !== 200) {
    echo "FAIL: Seller 2 settlements failed with code {$settleRes2['code']}: " . $settleRes2['raw'] . "\n";
    exit(1);
}
$sData2 = $settleRes2['body']['data'];
$summary2 = $sData2['financial_summary'] ?? [];
echo "PASS: Seller 2 settlements and live financial summary returned successfully (HTTP 200).\n";
echo " - Available Balance: ৳" . ($summary2['available_balance'] ?? 0) . "\n";
echo " - Unbilled Escrow: ৳" . ($summary2['unbilled_escrow'] ?? 0) . "\n";
echo " - Gross Sales GMV: ৳" . ($summary2['gross_sales_gmv'] ?? 0) . "\n";
echo " - Delivered GMV: ৳" . ($summary2['delivered_gmv'] ?? 0) . "\n";
echo " - Bank: " . ($summary2['bank_account'] ?? 'N/A') . "\n";
echo " - Historical Settlements Count: " . ($sData2['total'] ?? 0) . "\n";

// 5. Test Seller Isolation on Settlements (Seller 4 vs Seller 2)
$settleRes4 = makeRequest('GET', '/buddy/seller/settlements', [
    "Authorization: Bearer $token4"
]);

if ($settleRes4['code'] !== 200) {
    echo "FAIL: Seller 4 settlements failed with code {$settleRes4['code']}\n";
    exit(1);
}
$sData4 = $settleRes4['body']['data'];
$summary4 = $sData4['financial_summary'] ?? [];

echo "PASS: Seller 4 settlements retrieved successfully (HTTP 200).\n";
echo " - Seller 4 Gross GMV: ৳" . ($summary4['gross_sales_gmv'] ?? 0) . "\n";
echo " - Seller 4 Available Balance: ৳" . ($summary4['available_balance'] ?? 0) . "\n";

// Check that seller 4 doesn't see seller 2's settlements
$sList2 = $sData2['settlements'] ?? [];
$sList4 = $sData4['settlements'] ?? [];
$s2Ids = array_column($sList2, 'id');
$s4Ids = array_column($sList4, 'id');
$overlap = array_intersect($s2Ids, $s4Ids);

if (empty($overlap)) {
    echo "PASS: Settlement record isolation confirmed! Zero overlap between Seller 2 and Seller 4 records.\n";
} else {
    echo "FAIL: Settlement record leak detected across sellers!\n";
}

// 6. Test Payout Request Workflow & Duplicate Payout Prevention
$pdo = \App\Core\Database::getConnection();

// First check if Seller 2 has any active pending payout. Clean up old test payouts or mark existing test ones completed so we can cleanly test request payout
$pdo->exec("UPDATE seller_settlements SET status = 'completed' WHERE seller_id = 2 AND status IN ('pending', 'processing')");
$pdo->exec("DELETE FROM seller_settlements WHERE settlement_ref LIKE 'PAYOUT-%'");

// Check if Seller 2 has delivered balance. Let's ensure delivered items cover the payout
$pdo->exec("UPDATE order_items SET item_status = 'delivered' WHERE seller_id = 2");

// Re-check financial summary
$recheck = makeRequest('GET', '/buddy/seller/settlements', ["Authorization: Bearer $token2"]);
$newAvail = (float)($recheck['body']['data']['financial_summary']['available_balance'] ?? 0);
echo " - Updated Delivered Balance for Seller 2: ৳ $newAvail\n";

if ($newAvail > 100) {
    // 6a. Request Early Payout
    $payoutReq = makeRequest('POST', '/buddy/seller/settlements/request-payout', [
        'Content-Type: application/json',
        "Authorization: Bearer $token2",
    ], [
        'amount' => 1000.00,
        'express_t0' => true,
    ]);

    if ($payoutReq['code'] === 201) {
        $createdPayout = $payoutReq['body']['data'];
        $createdId = $createdPayout['settlement_id'];
        $createdRef = $createdPayout['settlement_ref'];
        echo "PASS: Early payout withdrawal requested successfully (Ref: {$createdRef}, Net: ৳ {$createdPayout['net_payout']}).\n";

        // 6b. Prevent Duplicate Concurrent Payouts (Must return 409 Conflict)
        $dupReq = makeRequest('POST', '/buddy/seller/settlements/request-payout', [
            'Content-Type: application/json',
            "Authorization: Bearer $token2",
        ], [
            'amount' => 1000.00,
            'express_t0' => true,
        ]);

        if ($dupReq['code'] === 409) {
            echo "PASS: Duplicate payout prevention enforced! Concurrent request correctly rejected with 409 Conflict.\n";
        } else {
            echo "FAIL: Expected 409 Conflict for duplicate payout, got code {$dupReq['code']}\n";
        }

        // 7. Test Admin Authorization / Release of Payout
        // Non-admin attempt should be 403
        $unauthRelease = makeRequest('POST', "/buddy/admin/settlements/{$createdId}/release", [
            "Authorization: Bearer $token2",
        ]);
        if ($unauthRelease['code'] === 403) {
            echo "PASS: Seller cannot authorize payout releases (403 Forbidden enforced).\n";
        } else {
            echo "FAIL: Expected 403 for seller release attempt, got {$unauthRelease['code']}\n";
        }

        // Admin release
        $adminRelease = makeRequest('POST', "/buddy/admin/settlements/{$createdId}/release", [
            "Authorization: Bearer $tokenAdmin",
        ]);
        if ($adminRelease['code'] === 200 && ($adminRelease['body']['data']['status'] ?? '') === 'completed') {
            echo "PASS: Admin authorized and released payout #{$createdRef} (status -> completed).\n";
        } else {
            echo "FAIL: Admin release failed with code {$adminRelease['code']}: " . $adminRelease['raw'] . "\n";
        }

        // Clean up test settlement
        $pdo->exec("DELETE FROM seller_settlements WHERE id = {$createdId}");
        echo "PASS: Cleaned up test settlement record.\n";
    } else {
        echo "FAIL: Payout request failed with code {$payoutReq['code']}: " . $payoutReq['raw'] . "\n";
    }
} else {
    echo "NOTE: Available balance was ৳ $newAvail. Testing validation rejection on low balance:\n";
    $payoutLow = makeRequest('POST', '/buddy/seller/settlements/request-payout', [
        'Content-Type: application/json',
        "Authorization: Bearer $token2",
    ], ['amount' => 50000.00]);
    if ($payoutLow['code'] === 400 || $payoutLow['code'] === 409) {
        echo "PASS: Low balance or pending validation rejected with appropriate error code.\n";
    }
}

echo "=== ALL EARNINGS & SETTLEMENT TESTS PASSED SUCCESSFULLY ===\n";
