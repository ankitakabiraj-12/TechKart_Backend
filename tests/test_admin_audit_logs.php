<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Models\User;
use App\Security\JwtService;

echo "=== RUNNING TECHKART BUDDY ADMIN AUDIT LOGS TEST ===\n\n";

$pdo = Database::getConnection();

// 1. Prepare Admin User
$adminUser = User::findByEmail('admin.audit@techkart.com');
if (!$adminUser) {
    $adminId = User::create([
        'name' => 'Security Audit Admin',
        'email' => 'admin.audit@techkart.com',
        'password' => 'password123',
        'role' => 'admin',
        'is_active' => 1,
    ]);
    $adminUser = User::findById($adminId);
}
$adminToken = JwtService::generateToken([
    'sub' => (string) $adminUser['id'],
    'role' => $adminUser['role'],
    'email' => $adminUser['email'],
]);

// 2. Prepare Seller User (to test 403 Forbidden)
$sellerUser = User::findByEmail('seller.audit@techkart.com');
if (!$sellerUser) {
    $sellerId = User::create([
        'name' => 'Audit Tester Seller',
        'email' => 'seller.audit@techkart.com',
        'password' => 'password123',
        'role' => 'seller',
        'is_active' => 1,
    ]);
    $sellerUser = User::findById($sellerId);
}
$sellerToken = JwtService::generateToken([
    'sub' => (string) $sellerUser['id'],
    'role' => $sellerUser['role'],
    'email' => $sellerUser['email'],
]);

// Helper for HTTP requests
function makeRequest($url, $token = null) {
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

$baseUrl = 'http://127.0.0.1:8000/api/v1/buddy/admin/audit-logs';

// Test 1: Unauthenticated request -> should fail with 401 or 403
echo "Test 1: Unauthenticated access verification\n";
$res1 = makeRequest($baseUrl);
if ($res1['code'] === 401 || $res1['code'] === 403) {
    echo "  [PASS] Unauthenticated request blocked with HTTP {$res1['code']}\n";
} else {
    echo "  [FAIL] Expected 401/403, got HTTP {$res1['code']}\n";
    exit(1);
}

// Test 2: Seller request -> should fail with 403 Forbidden
echo "\nTest 2: Seller role RBAC access verification (Should be 403)\n";
$res2 = makeRequest($baseUrl, $sellerToken);
if ($res2['code'] === 403) {
    echo "  [PASS] Non-admin seller properly blocked with HTTP 403 Forbidden\n";
} else {
    echo "  [FAIL] Expected 403 for seller, got HTTP {$res2['code']}\n";
    exit(1);
}

// Test 3: Admin request -> should succeed with 200 OK and valid structure
echo "\nTest 3: Admin access verification with full payload structure\n";
$res3 = makeRequest($baseUrl . '?page=1&limit=5', $adminToken);
if ($res3['code'] === 200 && $res3['body']['success'] === true) {
    $data = $res3['body']['data'];
    $logs = $data['logs'] ?? [];
    $pagination = $data['pagination'] ?? [];
    $metrics = $data['metrics'] ?? [];
    $actions = $data['available_actions'] ?? [];

    echo "  [PASS] Admin access granted with HTTP 200 OK\n";
    echo "  - Total matching logs: " . ($pagination['total'] ?? 0) . "\n";
    echo "  - Total pages: " . ($pagination['total_pages'] ?? 0) . "\n";
    echo "  - Fetched logs in page: " . count($logs) . "\n";
    echo "  - Total all logs in DB: " . ($metrics['total_logs'] ?? 0) . "\n";
    echo "  - Admin actions count: " . ($metrics['admin_actions_count'] ?? 0) . "\n";
    echo "  - Distinct available actions: " . implode(', ', $actions) . "\n";

    if (empty($logs)) {
        echo "  [WARN] No logs returned, expected at least the existing test logs.\n";
    } else {
        $firstLog = $logs[0];
        echo "  - Sample Log #{$firstLog['id']}: Action={$firstLog['action']}, Actor={$firstLog['actor_name']}, Target={$firstLog['target_type']} #{$firstLog['target_id']}\n";
    }
} else {
    echo "  [FAIL] Expected 200 OK, got HTTP {$res3['code']}: " . json_encode($res3['body']) . "\n";
    exit(1);
}

// Test 4: Action filter query
echo "\nTest 4: Action filter test (action=SETTLEMENT_RELEASE)\n";
$res4 = makeRequest($baseUrl . '?action=SETTLEMENT_RELEASE', $adminToken);
if ($res4['code'] === 200 && $res4['body']['success'] === true) {
    $filteredLogs = $res4['body']['data']['logs'] ?? [];
    echo "  [PASS] Action filter succeeded, returned " . count($filteredLogs) . " logs\n";
    foreach ($filteredLogs as $l) {
        if ($l['action'] !== 'SETTLEMENT_RELEASE') {
            echo "  [FAIL] Log contains non-matching action: {$l['action']}\n";
            exit(1);
        }
    }
} else {
    echo "  [FAIL] Action filter query failed with HTTP {$res4['code']}\n";
    exit(1);
}

// Test 5: Search query
echo "\nTest 5: Search filter test (search=Alex)\n";
$res5 = makeRequest($baseUrl . '?search=Alex', $adminToken);
if ($res5['code'] === 200 && $res5['body']['success'] === true) {
    $searchLogs = $res5['body']['data']['logs'] ?? [];
    echo "  [PASS] Search filter succeeded, returned " . count($searchLogs) . " logs\n";
} else {
    echo "  [FAIL] Search query failed with HTTP {$res5['code']}\n";
    exit(1);
}

echo "\n=== ALL AUDIT LOG API TESTS PASSED SUCCESSFULLY! ===\n";
