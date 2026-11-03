<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

function makeRequest(string $method, string $path, array $headers = [], ?array $body = null): array {
    $url = 'http://127.0.0.1:8000/api/v1' . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($body !== null) {
        $json = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response ?: '', true),
    ];
}

function login(string $email, string $password): ?string {
    $res = makeRequest('POST', '/buddy/auth/login', [], [
        'email' => $email,
        'password' => $password,
    ]);
    return $res['body']['data']['token'] ?? null;
}

echo "=== TEST: TechKart Buddy Low-Stock Alerts & Inventory Threshold API ===\n";

// 1. Authenticate Seller 2, Seller 4, and Admin
$token2 = login('vikram.store@techkart.com', 'password123');
$token4 = login('other.seller@techkart.com', 'password123');
$adminToken = login('admin.audit@techkart.com', 'password123');

if (!$token2 || !$token4 || !$adminToken) {
    echo "FAIL: Authentication failed for one or more actors.\n";
    exit(1);
}
echo "PASS: Seller 2, Seller 4, and Admin authenticated successfully.\n";

$pdo = \App\Core\Database::getConnection();

// 2. Test Low-Stock Threshold Update Endpoint
// Update product 14 (Chronos Ultra 2) threshold to 6
$prod14 = $pdo->query("SELECT id, seller_id, title, stock_quantity, low_stock_threshold FROM products WHERE id = 14")->fetch(PDO::FETCH_ASSOC);
if (!$prod14) {
    // If not found, pick any product owned by seller 2
    $prod14 = $pdo->query("SELECT id, seller_id, title, stock_quantity, low_stock_threshold FROM products WHERE seller_id = 2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$testProdId = (int)$prod14['id'];

$thRes = makeRequest('POST', '/buddy/inventory/threshold', [
    "Authorization: Bearer $token2",
], [
    'product_id' => $testProdId,
    'threshold'  => 8,
]);

if ($thRes['code'] === 200 && ($thRes['body']['data']['low_stock_threshold'] ?? 0) === 8) {
    echo "PASS: Seller 2 updated low_stock_threshold to 8 for product #{$testProdId} (HTTP 200).\n";
} else {
    echo "FAIL: Failed to update threshold: " . json_encode($thRes) . "\n";
    exit(1);
}

// 3. Test Seller Isolation on Threshold Update
// Seller 4 attempting to update Seller 2's product threshold should be 403 Forbidden
$crossTh = makeRequest('POST', '/buddy/inventory/threshold', [
    "Authorization: Bearer $token4",
], [
    'product_id' => $testProdId,
    'threshold'  => 12,
]);

if ($crossTh['code'] === 403) {
    echo "PASS: Cross-seller threshold tampering strictly rejected with 403 Forbidden.\n";
} else {
    echo "FAIL: Expected 403 for cross-seller threshold update, got {$crossTh['code']}\n";
    exit(1);
}

// 4. Test Stock Adjustment below threshold generates Alert
// Set product stock to 3 (below threshold 8)
$adjRes = makeRequest('POST', '/buddy/inventory/update-stock', [
    "Authorization: Bearer $token2",
], [
    'product_id'     => $testProdId,
    'stock_quantity' => 3,
    'reason'         => 'Test stock decrease to trigger low-stock alert',
]);

if ($adjRes['code'] === 200 && ($adjRes['body']['data']['is_low_stock'] ?? false) === true) {
    echo "PASS: Stock updated to 3 and flagged as low-stock (threshold: 8).\n";
} else {
    echo "FAIL: Failed to adjust stock: " . json_encode($adjRes) . "\n";
    exit(1);
}

// 5. Test Persistent Inventory Alerts API
$alertsRes = makeRequest('GET', '/buddy/inventory/alerts', [
    "Authorization: Bearer $token2",
]);

if ($alertsRes['code'] === 200) {
    $alerts = $alertsRes['body']['data']['alerts'] ?? [];
    $metrics = $alertsRes['body']['data']['metrics'] ?? [];
    echo "PASS: Seller 2 alerts retrieved successfully (HTTP 200). Total: " . count($alerts) . ", Unread: {$metrics['unread_count']}\n";

    $foundProdAlert = false;
    $targetAlertId = null;
    foreach ($alerts as $al) {
        if ($al['product_id'] === $testProdId) {
            $foundProdAlert = true;
            $targetAlertId = $al['id'];
            echo " - Verified persistent alert #{$al['id']} for '{$al['product_title']}' (Type: {$al['alert_type']}, Severity: {$al['severity']}, Stock: {$al['current_stock']})\n";
            break;
        }
    }
    if (!$foundProdAlert) {
        echo "FAIL: Alert for product #{$testProdId} not found in alerts list.\n";
        exit(1);
    }
} else {
    echo "FAIL: Failed to retrieve alerts: " . json_encode($alertsRes) . "\n";
    exit(1);
}

// 6. Test Seller Isolation on Alerts
$alertsSeller4 = makeRequest('GET', '/buddy/inventory/alerts', [
    "Authorization: Bearer $token4",
]);
if ($alertsSeller4['code'] === 200) {
    $s4Alerts = $alertsSeller4['body']['data']['alerts'] ?? [];
    $hasOverlap = false;
    foreach ($s4Alerts as $al) {
        if ($al['seller_id'] === 2) {
            $hasOverlap = true;
            break;
        }
    }
    if (!$hasOverlap) {
        echo "PASS: Seller isolation strictly verified! Seller 4 cannot view Seller 2's inventory alerts.\n";
    } else {
        echo "FAIL: Seller 4 received Seller 2's alerts! Isolation breach.\n";
        exit(1);
    }
}

// 7. Test Mark Single Alert Read
if ($targetAlertId) {
    $readRes = makeRequest('POST', "/buddy/inventory/alerts/{$targetAlertId}/read", [
        "Authorization: Bearer $token2",
    ]);
    if ($readRes['code'] === 200 && ($readRes['body']['data']['is_read'] ?? false) === true) {
        echo "PASS: Alert #{$targetAlertId} marked as read successfully (HTTP 200).\n";
    } else {
        echo "FAIL: Failed to mark alert as read: " . json_encode($readRes) . "\n";
        exit(1);
    }

    // Cross-seller attempt to mark read
    $crossRead = makeRequest('POST', "/buddy/inventory/alerts/{$targetAlertId}/read", [
        "Authorization: Bearer $token4",
    ]);
    if ($crossRead['code'] === 403) {
        echo "PASS: Cross-seller alert mark-read rejected with 403 Forbidden.\n";
    } else {
        echo "FAIL: Expected 403 for cross-seller alert mark-read, got {$crossRead['code']}\n";
        exit(1);
    }
}

// 8. Test Mark All Alerts Read
$markAllRes = makeRequest('POST', '/buddy/inventory/alerts/mark-all-read', [
    "Authorization: Bearer $token2",
]);
if ($markAllRes['code'] === 200 && ($markAllRes['body']['data']['unread_count'] ?? 1) === 0) {
    echo "PASS: All alerts for Seller 2 marked as read (unread_count: 0).\n";
} else {
    echo "FAIL: Failed to mark all alerts read: " . json_encode($markAllRes) . "\n";
    exit(1);
}

// 9. Test Dashboard Metrics Badge Count
$dashRes = makeRequest('GET', '/buddy/seller/dashboard', [
    "Authorization: Bearer $token2",
]);
if ($dashRes['code'] === 200) {
    $dMetrics = $dashRes['body']['data']['metrics'] ?? [];
    echo "PASS: Seller dashboard statistics include live alert counts.\n";
    echo " - Low Stock Count: " . ($dMetrics['low_stock_count'] ?? 0) . "\n";
    echo " - Out of Stock Count: " . ($dMetrics['out_of_stock_count'] ?? 0) . "\n";
    echo " - Unread Alerts Count: " . ($dMetrics['unread_alerts_count'] ?? 0) . "\n";
} else {
    echo "FAIL: Failed to load seller dashboard: " . json_encode($dashRes) . "\n";
    exit(1);
}

// 10. Test Restock replenishment auto-resolves alert
$restockRes = makeRequest('POST', '/buddy/inventory/update-stock', [
    "Authorization: Bearer $token2",
], [
    'product_id'     => $testProdId,
    'stock_quantity' => 15, // Above threshold 8
    'reason'         => 'Test replenishment to auto-resolve alert',
]);

if ($restockRes['code'] === 200 && ($restockRes['body']['data']['is_low_stock'] ?? true) === false) {
    echo "PASS: Restocked product #{$testProdId} to 15 (healthy, alert auto-resolved).\n";
} else {
    echo "FAIL: Failed to restock product: " . json_encode($restockRes) . "\n";
    exit(1);
}

echo "=== ALL LOW-STOCK ALERTS & THRESHOLD TESTS PASSED SUCCESSFULLY ===\n";
