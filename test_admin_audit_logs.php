<?php

declare(strict_types=1);

/**
 * TechKart Buddy Admin Audit Logs Integration Test
 */

function makeHttp(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout'       => 10,
        ]
    ];
    if ($token !== null) {
        $opts['http']['header'] .= "Authorization: Bearer {$token}\r\n";
    }
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }

    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        throw new Exception("HTTP request failed: {$method} {$url}");
    }
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        throw new Exception("Invalid JSON response: " . substr($res, 0, 200));
    }
    return [
        'body' => $decoded,
        'headers' => $http_response_header ?? []
    ];
}

echo "=== RUNNING TECHKART BUDDY ADMIN AUDIT LOGS TEST ===\n\n";

// 1. Admin Login
echo "Logging in as admin...\n";
$adminLogin = makeHttp('POST', '/buddy/auth/login', [
    'email' => 'admin.audit@techkart.com',
    'password' => 'Password@123'
]);

$adminToken = $adminLogin['body']['data']['token'] ?? null;
if (!$adminToken) {
    echo "[FAIL] Could not log in as admin: " . json_encode($adminLogin['body']) . "\n";
    exit(1);
}
echo "[PASS] Admin logged in successfully.\n\n";

// 2. Seller Login
echo "Logging in as seller...\n";
$sellerLogin = makeHttp('POST', '/buddy/auth/login', [
    'email' => 'vikram.store@techkart.com',
    'password' => 'password123'
]);
if (!isset($sellerLogin['body']['data']['token'])) {
    $sellerLogin = makeHttp('POST', '/buddy/auth/login', [
        'email' => 'other.seller@techkart.com',
        'password' => 'Password@123'
    ]);
}
if (!isset($sellerLogin['body']['data']['token'])) {
    $sellerLogin = makeHttp('POST', '/buddy/auth/login', [
        'email' => 'vikram.store@techkart.com',
        'password' => 'Password@123'
    ]);
}
$sellerToken = $sellerLogin['body']['data']['token'] ?? null;
if (!$sellerToken) {
    echo "[FAIL] Could not log in as seller.\n";
    exit(1);
}
echo "[PASS] Seller logged in successfully.\n\n";

// Test 1: Unauthenticated request -> should fail with 401
echo "Test 1: Unauthenticated access verification\n";
$res1 = makeHttp('GET', '/buddy/admin/audit-logs');
if (isset($res1['body']['success']) && $res1['body']['success'] === false) {
    echo "  [PASS] Unauthenticated request blocked correctly: " . ($res1['body']['message'] ?? '') . "\n";
} else {
    echo "  [FAIL] Unauthenticated request was unexpectedly allowed!\n";
    exit(1);
}

// Test 2: Seller access -> should fail with 403 Forbidden
echo "\nTest 2: Seller RBAC verification (Must be rejected)\n";
$res2 = makeHttp('GET', '/buddy/admin/audit-logs', null, $sellerToken);
if (isset($res2['body']['success']) && $res2['body']['success'] === false) {
    echo "  [PASS] Seller blocked with message: " . ($res2['body']['message'] ?? '') . "\n";
} else {
    echo "  [FAIL] Seller was unexpectedly allowed access to admin audit logs!\n";
    exit(1);
}

// Test 3: Admin access -> 200 OK with pagination, metrics, and logs
echo "\nTest 3: Admin access verification with full payload structure\n";
$res3 = makeHttp('GET', '/buddy/admin/audit-logs?page=1&limit=5', null, $adminToken);
if (isset($res3['body']['success']) && $res3['body']['success'] === true) {
    $data = $res3['body']['data'];
    $logs = $data['logs'] ?? [];
    $pagination = $data['pagination'] ?? [];
    $metrics = $data['metrics'] ?? [];
    $actions = $data['available_actions'] ?? [];

    echo "  [PASS] Admin retrieved audit logs successfully.\n";
    echo "  - Total matching logs: " . ($pagination['total'] ?? 0) . "\n";
    echo "  - Total pages: " . ($pagination['total_pages'] ?? 0) . "\n";
    echo "  - Fetched logs in page: " . count($logs) . "\n";
    echo "  - Total all logs in DB: " . ($metrics['total_logs'] ?? 0) . "\n";
    echo "  - Admin actions count: " . ($metrics['admin_actions_count'] ?? 0) . "\n";
    echo "  - Distinct available actions: " . implode(', ', $actions) . "\n";

    if (!empty($logs)) {
        $firstLog = $logs[0];
        echo "  - Latest Log #{$firstLog['id']}: Action={$firstLog['action']}, Actor={$firstLog['actor_name']}, Target={$firstLog['target_type']} #{$firstLog['target_id']}\n";
    }
} else {
    echo "  [FAIL] Admin access failed: " . json_encode($res3['body']) . "\n";
    exit(1);
}

// Test 4: Action filter query
echo "\nTest 4: Action filter test (action=SETTLEMENT_RELEASE)\n";
$res4 = makeHttp('GET', '/buddy/admin/audit-logs?action=SETTLEMENT_RELEASE', null, $adminToken);
if (isset($res4['body']['success']) && $res4['body']['success'] === true) {
    $filteredLogs = $res4['body']['data']['logs'] ?? [];
    echo "  [PASS] Action filter returned " . count($filteredLogs) . " records\n";
    foreach ($filteredLogs as $l) {
        if ($l['action'] !== 'SETTLEMENT_RELEASE') {
            echo "  [FAIL] Mismatched action found in filtered results: {$l['action']}\n";
            exit(1);
        }
    }
} else {
    echo "  [FAIL] Action filter query failed: " . json_encode($res4['body']) . "\n";
    exit(1);
}

// Test 5: Search query
echo "\nTest 5: Search filter test (search=Alex)\n";
$res5 = makeHttp('GET', '/buddy/admin/audit-logs?search=Alex', null, $adminToken);
if (isset($res5['body']['success']) && $res5['body']['success'] === true) {
    $searchLogs = $res5['body']['data']['logs'] ?? [];
    echo "  [PASS] Search filter returned " . count($searchLogs) . " records\n";
} else {
    echo "  [FAIL] Search query failed: " . json_encode($res5['body']) . "\n";
    exit(1);
}

echo "\n=== ALL AUDIT LOG API TESTS PASSED SUCCESSFULLY! ===\n";
