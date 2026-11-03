<?php
/**
 * Test Admin Payments, Sales & Revenue, and Settlement Payout Authorization APIs
 */

$baseUrl = 'http://127.0.0.1:8000/api/v1';

function makeRequest($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== TECHKART BUDDY ADMIN PAYMENTS & SETTLEMENTS TEST ===\n\n";

// 1. Admin Login
echo "[1] Admin Login...\n";
$loginRes = makeRequest('POST', "$baseUrl/buddy/auth/login", [], [
    'email' => 'admin.audit@techkart.com',
    'password' => 'password123'
]);

$adminToken = $loginRes['body']['token'] ?? $loginRes['body']['data']['token'] ?? null;
if (!$adminToken) {
    echo "FAILED: Admin login failed. Response: " . $loginRes['raw'] . "\n";
    exit(1);
}
$adminHeaders = ["Authorization: Bearer $adminToken"];
echo "  SUCCESS: Admin logged in.\n\n";

// 2. Fetch Sales & Revenue Reports API
echo "[2] Fetching Live Sales & Revenue Report (INR ₹)...\n";
$reportRes = makeRequest('GET', "$baseUrl/buddy/admin/reports/sales-revenue", $adminHeaders);
if ($reportRes['code'] !== 200 || !($reportRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not retrieve sales & revenue report. Response: {$reportRes['raw']}\n";
    exit(1);
}
$reportData = $reportRes['body']['data'];
$summary = $reportData['metrics'];
echo "  SUCCESS: Sales report retrieved.\n";
echo "  - Total Orders: {$summary['total_orders']}\n";
echo "  - Gross Sales GMV: ₹ {$summary['gross_sales_gmv']}\n";
echo "  - Platform Commission (6%): ₹ {$summary['platform_commission']}\n";
echo "  - Payment Processing Fee (1.5%): ₹ {$summary['payment_processing_fee']}\n";
echo "  - Net Projected Payout: ₹ {$summary['net_payout']}\n";

// Verify INR calculations: Gross - Commission - Fee = Net
$expectedNet = round($summary['gross_sales_gmv'] - $summary['platform_commission'] - $summary['payment_processing_fee'] - $summary['logistics_subsidies'] + $summary['promotional_rebates'], 2);
if (abs($summary['net_payout'] - $expectedNet) > 0.05) {
    echo "  FAILED: Net payout calculation mismatch. Expected $expectedNet, got {$summary['net_payout']}\n";
    exit(1);
}
echo "  - Verified: INR financial arithmetic holds.\n\n";

// 3. Fetch Admin Settlements Directory with Pagination
echo "[3] Fetching Admin Settlements Directory (Page 1)...\n";
$settleRes = makeRequest('GET', "$baseUrl/buddy/admin/settlements?page=1&limit=10", $adminHeaders);
if ($settleRes['code'] !== 200 || !($settleRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not retrieve settlements. Response: {$settleRes['raw']}\n";
    exit(1);
}
$settleData = $settleRes['body']['data'];
$settlements = $settleData['settlements'];
$finSummary = $settleData['financial_summary'];
$pagination = $settleData['pagination'];

echo "  SUCCESS: Settlements directory retrieved.\n";
echo "  - Total Dossiers in DB: {$pagination['total']}\n";
echo "  - Completed Payouts: ₹ {$finSummary['completed_payouts']}\n";
echo "  - Pending Payouts: ₹ {$finSummary['pending_payouts']}\n";
echo "  - Held Payouts: ₹ {$finSummary['held_payouts']}\n";
echo "  - Unbilled Escrow: ₹ {$finSummary['unbilled_escrow']}\n";
echo "  - Dossiers Returned: " . count($settlements) . "\n\n";

// 4. Test Filters & Search
echo "[4] Testing Search and Status Filtering...\n";
// 4a. Search for "SoundCore" or "STL-2024-1031-01"
$searchRes = makeRequest('GET', "$baseUrl/buddy/admin/settlements?search=SoundCore", $adminHeaders);
$searchList = $searchRes['body']['data']['settlements'] ?? [];
echo "  - Search 'SoundCore': " . count($searchList) . " dossier(s) found.\n";
if (empty($searchList)) {
    echo "    FAILED: Expected to find SoundCore settlement dossier.\n";
    exit(1);
}

// 4b. Filter by status 'completed'
$compRes = makeRequest('GET', "$baseUrl/buddy/admin/settlements?status=completed", $adminHeaders);
$compList = $compRes['body']['data']['settlements'] ?? [];
echo "  - Filter 'completed': " . count($compList) . " dossier(s) found.\n";
foreach ($compList as $cd) {
    if ($cd['status'] !== 'completed') {
        echo "    FAILED: Dossier {$cd['settlement_ref']} status is {$cd['status']}, expected completed.\n";
        exit(1);
    }
}

// 4c. Filter by status 'pending'
$pendRes = makeRequest('GET', "$baseUrl/buddy/admin/settlements?status=pending", $adminHeaders);
$pendList = $pendRes['body']['data']['settlements'] ?? [];
echo "  - Filter 'pending': " . count($pendList) . " dossier(s) found.\n";
foreach ($pendList as $pd) {
    if ($pd['status'] !== 'pending') {
        echo "    FAILED: Dossier {$pd['settlement_ref']} status is {$pd['status']}, expected pending.\n";
        exit(1);
    }
}
echo "  SUCCESS: Search and status filter queries validated.\n\n";

// 5. Test Release / Authorize Payout API
echo "[5] Testing Admin Payout Release / Authorization...\n";
// Find a pending dossier (e.g. dossier ID 3)
$targetDossierId = 3; // SoundCore STL-2024-1031-01
$releaseRes = makeRequest('PUT', "$baseUrl/buddy/admin/settlements/$targetDossierId/release", $adminHeaders);
if ($releaseRes['code'] !== 200 || !($releaseRes['body']['success'] ?? false)) {
    echo "  FAILED: Could not authorize settlement release. Response: {$releaseRes['raw']}\n";
    exit(1);
}
echo "  - Authorized payout for dossier #$targetDossierId: " . $releaseRes['body']['message'] . "\n";

// Verify in DB that status is now 'completed'
$verifyStmtRes = makeRequest('GET', "$baseUrl/buddy/admin/settlements?search=STL-2024-1031-01", $adminHeaders);
$verifyDossier = $verifyStmtRes['body']['data']['settlements'][0] ?? null;
if (!$verifyDossier || $verifyDossier['status'] !== 'completed') {
    echo "  FAILED: Dossier status not updated to completed in MariaDB.\n";
    exit(1);
}
echo "  - Verified in MariaDB: Dossier STL-2024-1031-01 status = {$verifyDossier['status']}\n\n";

// Reset dossier 3 back to pending for repeatability
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();
$pdo->prepare("UPDATE seller_settlements SET status = 'pending', payout_date = NULL WHERE id = 3")->execute();
echo "  - Reset test dossier #3 back to pending for idempotency.\n\n";

echo "=== ALL ADMIN PAYMENTS & SETTLEMENT TESTS PASSED ===\n";
