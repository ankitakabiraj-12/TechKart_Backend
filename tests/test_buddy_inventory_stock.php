<?php
/**
 * Verification test for Buddy Inventory & Stock Management APIs.
 *
 * Verifies:
 * 1. Unauthorized access (401).
 * 2. Seller 2 inventory retrieval (metrics + items) (200).
 * 3. Status filtering: low_stock, out_of_stock, healthy (200).
 * 4. Seller 2 absolute stock update via POST /api/v1/buddy/inventory/update-stock (200).
 * 5. Seller 2 delta adjustment (+10) via POST /api/v1/buddy/inventory/update-stock (200).
 * 6. Cross-seller RBAC isolation: Seller 4 attempting to update Seller 2 product (403).
 * 7. Admin updating stock for any seller product (200).
 * 8. Stock movement audit trail logging via GET /api/v1/buddy/inventory/movements (200).
 */

$baseUrl = 'http://127.0.0.1:8000';

function postJson($url, $data, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function getJson($url, $token = null) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function login($baseUrl, $email, $password) {
    $res = postJson($baseUrl . '/api/v1/buddy/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);
    $token = $res['data']['token'] ?? $res['data']['data']['token'] ?? null;
    if ($res['status'] !== 200 || !$token) {
        throw new Exception("Login failed for $email: " . $res['raw']);
    }
    return $token;
}

echo "=== TECHKART BUDDY INVENTORY & STOCK MANAGEMENT API VERIFICATION ===\n\n";

$passed = 0;
$total = 0;

function assertCondition($desc, $cond, $extra = '') {
    global $passed, $total;
    $total++;
    if ($cond) {
        $passed++;
        echo " [PASS] $desc\n";
    } else {
        echo " [FAIL] $desc " . ($extra ? "($extra)" : "") . "\n";
    }
}

// 1. Unauthorized Access
$res = getJson($baseUrl . '/api/v1/buddy/inventory');
assertCondition("Unauthorized GET /api/v1/buddy/inventory returns 401", $res['status'] === 401);

// 2. Log in Seller 2, Seller 4, and Admin
$seller2Token = login($baseUrl, 'vikram.store@techkart.com', 'password123');
$seller4Token = login($baseUrl, 'other.seller@techkart.com', 'password123');
$adminToken = login($baseUrl, 'admin.audit@techkart.com', 'password123');
echo "Tokens acquired for Seller 2, Seller 4, and Admin.\n";

// 3. Seller 2 inventory retrieval with Vault metrics
$invRes = getJson($baseUrl . '/api/v1/buddy/inventory', $seller2Token);
assertCondition("Seller 2 GET /api/v1/buddy/inventory returns 200", $invRes['status'] === 200);
$metrics = $invRes['data']['data']['metrics'] ?? [];
$items = $invRes['data']['data']['items'] ?? [];
assertCondition("Inventory response contains metrics object", isset($metrics['total_items']));
assertCondition("Metrics include total_vault_units, healthy_count, low_stock_count, out_of_stock_count",
    isset($metrics['total_vault_units']) && isset($metrics['healthy_count']) && isset($metrics['low_stock_count']) && isset($metrics['out_of_stock_count'])
);
assertCondition("Seller 2 items array is populated", count($items) > 0, "Count: " . count($items));

$targetItem = $items[0] ?? null;
$targetProductId = $targetItem ? $targetItem['id'] : null;
$initialStock = $targetItem ? $targetItem['available_stock'] : 0;
echo "Selected Product ID $targetProductId ('" . ($targetItem['title'] ?? '') . "') with Initial Stock: $initialStock\n";

// 4. Status Filter Tests
$filterLow = getJson($baseUrl . '/api/v1/buddy/inventory?status=low_stock', $seller2Token);
assertCondition("Status filter 'low_stock' returns 200", $filterLow['status'] === 200);

$filterOut = getJson($baseUrl . '/api/v1/buddy/inventory?status=out_of_stock', $seller2Token);
assertCondition("Status filter 'out_of_stock' returns 200", $filterOut['status'] === 200);

// 5. Absolute Stock Update (Set to 45)
$updateAbsRes = postJson($baseUrl . '/api/v1/buddy/inventory/update-stock', [
    'product_id' => $targetProductId,
    'stock_quantity' => 45,
    'movement_type' => 'manual_adjustment',
    'reason' => 'Warehouse cycle count reconciliation'
], $seller2Token);

assertCondition("Seller 2 absolute stock update returns 200", $updateAbsRes['status'] === 200);
$updatedStock = $updateAbsRes['data']['data']['new_quantity'] ?? null;
assertCondition("Stock quantity updated to 45", $updatedStock === 45, "Got: " . var_export($updatedStock, true));

// 6. Relative Adjustment Delta (+10) Quick Restock
$updateDeltaRes = postJson($baseUrl . '/api/v1/buddy/inventory/update-stock', [
    'product_id' => $targetProductId,
    'adjustment_delta' => 10,
    'movement_type' => 'restock',
    'reason' => 'Quick restock batch intake'
], $seller2Token);

assertCondition("Seller 2 delta adjustment (+10) returns 200", $updateDeltaRes['status'] === 200);
$deltaStock = $updateDeltaRes['data']['data']['new_quantity'] ?? null;
assertCondition("Stock quantity after +10 is 55", $deltaStock === 55, "Got: " . var_export($deltaStock, true));

// 7. Cross-Seller RBAC Isolation: Seller 4 cannot update Seller 2 product
$crossRes = postJson($baseUrl . '/api/v1/buddy/inventory/update-stock', [
    'product_id' => $targetProductId,
    'stock_quantity' => 999,
    'reason' => 'Malicious cross-store attempt'
], $seller4Token);

assertCondition("Cross-seller update attempt returns 403 Forbidden", $crossRes['status'] === 403, "Status: " . $crossRes['status']);

// 8. Admin Permission: Admin can update stock of any product
$adminUpdateRes = postJson($baseUrl . '/api/v1/buddy/inventory/update-stock', [
    'product_id' => $targetProductId,
    'stock_quantity' => 50,
    'movement_type' => 'manual_adjustment',
    'reason' => 'Platform admin warehouse sync'
], $adminToken);

assertCondition("Admin can update stock across sellers (200 OK)", $adminUpdateRes['status'] === 200);

// 9. Stock Movement Audit Trail Verification
$movementRes = getJson($baseUrl . '/api/v1/buddy/inventory/movements?product_id=' . $targetProductId, $seller2Token);
assertCondition("GET /api/v1/buddy/inventory/movements returns 200", $movementRes['status'] === 200);
$movements = $movementRes['data']['data'] ?? [];
if (isset($movements['items'])) {
    $movements = $movements['items'];
}
assertCondition("Stock movement records are logged in audit table", count($movements) >= 3, "Logged count: " . count($movements));

if (!empty($movements)) {
    $latest = $movements[0];
    echo "Latest Stock Movement:\n";
    echo " - Product ID: " . $latest['product_id'] . "\n";
    echo " - Type: " . $latest['movement_type'] . "\n";
    echo " - Prev: " . $latest['previous_quantity'] . " -> New: " . $latest['new_quantity'] . " (Change: " . $latest['changed_quantity'] . ")\n";
    echo " - Reason: " . $latest['reason'] . "\n";
    echo " - Actor: ID " . $latest['actor_id'] . " (" . $latest['actor_role'] . ")\n";
}

echo "\n======================================================\n";
echo "SUMMARY: Passed $passed of $total tests.\n";
echo "======================================================\n";
