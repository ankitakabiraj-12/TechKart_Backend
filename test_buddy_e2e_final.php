<?php

declare(strict_types=1);

function sendRequest(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'data' => json_decode((string)$res, true)];
}

echo "=== STARTING TECHKART BUDDY SELLER END-TO-END AUDIT TEST ===\n";

// 1. Register a new seller
$randId = rand(10000, 99999);
$sellerEmail = "new.seller{$randId}@electrotech.com";
$sellerName = "ElectroTech Hub " . $randId;
$ownerName = "Sameer Sen";

echo "1. Registering new seller: {$sellerEmail} with store {$sellerName}...\n";
$reg = sendRequest('POST', '/buddy/auth/register-seller', [
    'full_name'  => $ownerName,
    'email'      => $sellerEmail,
    'password'   => 'SellerPass@2026',
    'phone'      => '+91 98765' . rand(10000, 99999),
    'store_name' => $sellerName,
    'gst_number' => '27ABCDE' . rand(1000, 9999) . 'F1Z5',
    'address'    => 'Sector 5, Salt Lake',
    'city'       => 'Kolkata',
    'state'      => 'West Bengal',
    'postal_code'=> '700091'
]);

if ($reg['status'] !== 201 && $reg['status'] !== 200) {
    echo "FAILED: Seller registration failed with status {$reg['status']}: " . json_encode($reg['data']) . "\n";
    exit(1);
}

$token = $reg['data']['data']['token'] ?? null;
if (!$token) {
    echo "FAILED: No token received for registered seller.\n";
    exit(1);
}
echo "✓ Seller registered successfully. Token received.\n";

// 2. Fetch Buddy Profile
echo "2. Fetching seller profile (/buddy/auth/profile)...\n";
$prof = sendRequest('GET', '/buddy/auth/profile', null, $token);
if ($prof['status'] !== 200) {
    echo "FAILED: GET /buddy/auth/profile failed with status {$prof['status']}: " . json_encode($prof['data']) . "\n";
    exit(1);
}
$storeProfile = $prof['data']['data']['store'] ?? null;
$userProfile = $prof['data']['data']['user'] ?? null;

if (($storeProfile['store_name'] ?? '') !== $sellerName) {
    echo "FAILED: Store name mismatch. Expected '{$sellerName}', got '" . ($storeProfile['store_name'] ?? 'null') . "'\n";
    exit(1);
}
echo "✓ Real seller store verified: {$storeProfile['store_name']}\n";
echo "✓ Real owner verified: {$userProfile['full_name']} ({$userProfile['email']})\n";

// 3. Fetch Seller Dashboard Overview
echo "3. Fetching seller dashboard overview (/buddy/seller/dashboard)...\n";
$dash = sendRequest('GET', '/buddy/seller/dashboard', null, $token);
if ($dash['status'] !== 200) {
    echo "FAILED: GET /buddy/seller/dashboard failed with status {$dash['status']}: " . json_encode($dash['data']) . "\n";
    exit(1);
}

$dashData = $dash['data']['data'] ?? [];
$storeInfo = $dashData['store'] ?? [];
$metrics = $dashData['metrics'] ?? [];

echo "✓ Dashboard store name matches registered store: " . $storeInfo['store_name'] . "\n";

// Verify metrics start clean for new seller
if ($metrics['total_orders'] !== 0) {
    echo "FAILED: Expected 0 total_orders for new seller, got " . $metrics['total_orders'] . "\n";
    exit(1);
}
if ($metrics['gross_revenue'] != 0.0) {
    echo "FAILED: Expected 0.0 gross_revenue for new seller, got " . $metrics['gross_revenue'] . "\n";
    exit(1);
}
if ($metrics['catalog_total'] !== 0) {
    echo "FAILED: Expected 0 catalog_total for new seller, got " . $metrics['catalog_total'] . "\n";
    exit(1);
}
if ($metrics['top_selling_product'] !== 'None') {
    echo "FAILED: Expected 'None' top_selling_product for new seller, got " . $metrics['top_selling_product'] . "\n";
    exit(1);
}

echo "✓ Metrics cleanly initialized: 0 orders, 0 revenue, 0 products, top product: None.\n";

// 4. Verify Seller Products List starts clean
echo "4. Verifying seller product inventory starts empty (/buddy/products)...\n";
$prod = sendRequest('GET', '/buddy/products', null, $token);
if ($prod['status'] !== 200) {
    echo "FAILED: GET /buddy/products failed with status {$prod['status']}: " . json_encode($prod['data']) . "\n";
    exit(1);
}
$products = $prod['data']['data'] ?? [];
if (!is_array($products) || count($products) !== 0) {
    echo "FAILED: Expected 0 products for new seller, got " . count($products) . "\n";
    exit(1);
}
echo "✓ Products catalog is completely clean (0 products) for new seller.\n";

// 5. Verify Seller Orders Stream starts clean
echo "5. Verifying seller orders list starts empty (/buddy/orders)...\n";
$orders = sendRequest('GET', '/buddy/orders', null, $token);
if ($orders['status'] !== 200) {
    echo "FAILED: GET /buddy/orders failed with status {$orders['status']}: " . json_encode($orders['data']) . "\n";
    exit(1);
}
$orderStream = $orders['data']['data'] ?? [];
if (!is_array($orderStream) || count($orderStream) !== 0) {
    echo "FAILED: Expected 0 orders for new seller, got " . count($orderStream) . "\n";
    exit(1);
}
echo "✓ Orders list is completely clean (0 orders) for new seller.\n";

// 6. Verify Seller Settlements list starts clean
echo "6. Verifying seller settlements starts clean (/buddy/seller/settlements)...\n";
$earn = sendRequest('GET', '/buddy/seller/settlements', null, $token);
if ($earn['status'] !== 200) {
    echo "FAILED: GET /buddy/seller/settlements failed with status {$earn['status']}: " . json_encode($earn['data']) . "\n";
    exit(1);
}
$settlementsList = $earn['data']['data']['settlements'] ?? [];
if (!is_array($settlementsList) || count($settlementsList) !== 0) {
    echo "FAILED: Expected 0 settlements for new seller, got " . count($settlementsList) . "\n";
    exit(1);
}
$finSummary = $earn['data']['data']['financial_summary'] ?? [];
if (($finSummary['gross_sales_gmv'] ?? 0.0) != 0.0) {
    echo "FAILED: Expected 0.0 gross_sales_gmv for new seller, got: " . json_encode($finSummary) . "\n";
    exit(1);
}
echo "✓ Settlements list is cleanly scoped: 0 previous settlements, 0.0 GMV.\n";

echo "=== ALL TECHKART BUDDY SELLER END-TO-END VERIFICATIONS PASSED ===\n";
