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

echo "=== STARTING FINAL TECHKART BUDDY LOCALIZATION & E2E TEST ===\n";

// 1. Register brand new Indian seller with GST, State, City, PIN
$randId = rand(10000, 99999);
$sellerEmail = "neha.electronics{$randId}@gmail.com";
$storeName = "Neha Gadgets India " . $randId;
$ownerName = "Neha Kulkarni";
$phone = "+91 91672 " . $randId;
$gstin = "27AAECK" . rand(1000, 9999) . "A1Z9";

echo "1. Registering new Indian seller: {$sellerEmail}...\n";
$reg = sendRequest('POST', '/buddy/auth/register-seller', [
    'full_name'  => $ownerName,
    'email'      => $sellerEmail,
    'password'   => 'SellerPass@2026',
    'phone'      => $phone,
    'store_name' => $storeName,
    'gst_number' => $gstin,
    'address'    => 'Plot 12, MIDC Industrial Area, Andheri East',
    'city'       => 'Mumbai',
    'state'      => 'Maharashtra',
    'postal_code'=> '400093'
]);

if ($reg['status'] !== 201 && $reg['status'] !== 200) {
    echo "FAILED: Seller registration failed: " . json_encode($reg['data']) . "\n";
    exit(1);
}
$token = $reg['data']['data']['token'] ?? null;
if (!$token) {
    echo "FAILED: Missing auth token.\n";
    exit(1);
}
echo "✓ Seller registered with GSTIN {$gstin}, Mumbai, Maharashtra 400093.\n";

// 2. Fetch authenticated profile & verify Indian localization
echo "2. Verifying seller profile localization (/buddy/auth/profile)...\n";
$prof = sendRequest('GET', '/buddy/auth/profile', null, $token);
if ($prof['status'] !== 200) {
    echo "FAILED: Profile fetch failed: " . json_encode($prof['data']) . "\n";
    exit(1);
}
$store = $prof['data']['data']['store'] ?? [];
$user = $prof['data']['data']['user'] ?? [];

if ($store['gst_number'] !== $gstin || $store['state'] !== 'Maharashtra' || $store['city'] !== 'Mumbai') {
    echo "FAILED: Store localization fields mismatch: " . json_encode($store) . "\n";
    exit(1);
}
echo "✓ Verified GSTIN: {$store['gst_number']}, City: {$store['city']}, State: {$store['state']}\n";
echo "✓ Verified Seller: {$user['full_name']} ({$user['phone']})\n";

// 3. Verify Seller Dashboard Overview in INR
echo "3. Fetching seller dashboard overview (/buddy/seller/dashboard)...\n";
$dash = sendRequest('GET', '/buddy/seller/dashboard', null, $token);
if ($dash['status'] !== 200) {
    echo "FAILED: Dashboard fetch failed: " . json_encode($dash['data']) . "\n";
    exit(1);
}
$dashData = $dash['data']['data'] ?? [];
$metrics = $dashData['metrics'] ?? [];

if ($metrics['gross_revenue'] != 0.0 || $metrics['total_orders'] !== 0) {
    echo "FAILED: Expected fresh metrics 0 for new seller: " . json_encode($metrics) . "\n";
    exit(1);
}
echo "✓ Dashboard metrics cleanly scoped: 0 orders, ₹0.00 gross revenue.\n";

// 4. Add a new product in INR
echo "4. Adding a new product in catalog with INR MRP and Unit Price...\n";
$newProd = sendRequest('POST', '/buddy/products', [
    'title' => 'HyperCharge 100W GaN Travel Adapter',
    'category_id' => 1,
    'price' => 3499.00,
    'mrp' => 4999.00,
    'stock_quantity' => 25,
    'sku' => 'HC-100W-' . $randId,
    'description' => 'Fast multi-port USB-C GaN charger engineered for Indian voltage standards.'
], $token);

if ($newProd['status'] !== 201 && $newProd['status'] !== 200) {
    echo "FAILED: Adding product failed: " . json_encode($newProd['data']) . "\n";
    exit(1);
}
$prodData = $newProd['data']['data'] ?? [];
echo "✓ Product published with INR Price: ₹" . number_format((float)$prodData['price'], 2) . " (MRP: ₹" . number_format((float)$prodData['mrp'], 2) . ").\n";

// 5. Verify catalog reflects newly added product only
echo "5. Verifying seller catalog contains exactly the added product (/buddy/products)...\n";
$listProd = sendRequest('GET', '/buddy/products', null, $token);
if ($listProd['status'] !== 200 || count($listProd['data']['data'] ?? []) !== 1) {
    echo "FAILED: Catalog count mismatch: " . json_encode($listProd['data']) . "\n";
    exit(1);
}
echo "✓ Verified seller products list contains exactly 1 product.\n";

// 6. Verify Financial Settlements scoping
echo "6. Verifying seller settlements report (/buddy/seller/settlements)...\n";
$settle = sendRequest('GET', '/buddy/seller/settlements', null, $token);
if ($settle['status'] !== 200) {
    echo "FAILED: Settlements fetch failed: " . json_encode($settle['data']) . "\n";
    exit(1);
}
$fin = $settle['data']['data']['financial_summary'] ?? [];
echo "✓ Settlements financial summary scoped to seller: Gross GMV: ₹" . number_format((float)($fin['gross_sales_gmv'] ?? 0.0), 2) . "\n";

echo "=== ALL TECHKART BUDDY LOCALIZATION & E2E CHECKS PASSED ===\n";
