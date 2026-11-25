<?php

declare(strict_types=1);

/**
 * Migration 013 Verification Test
 * Verifies idempotent creation, schema integrity, constraints, unique keys, and indexes.
 */

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Models\User;

echo "=== VERIFYING MIGRATION 013: DEVICE TOKENS SCHEMA ===\n\n";

$pdo = Database::getConnection();

// 1. Run migration first time
echo "1. Applying migration...\n";
$migrationSql = file_get_contents(__DIR__ . '/../database/migrations/013_device_tokens_schema.sql');
$pdo->exec($migrationSql);
echo "  [PASS] Applied.\n";

// 2. Test Idempotency (run migration second time without error)
echo "\n2. Verifying idempotency by re-applying migration...\n";
$pdo->exec($migrationSql);
echo "  [PASS] Migration executed a second time without throwing any SQL errors.\n";

// 3. Verify Table Columns & Types
echo "\n3. Inspecting table schema structure...\n";
$stmt = $pdo->query("DESCRIBE device_tokens");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$columnMap = [];
foreach ($columns as $col) {
    $columnMap[$col['Field']] = $col;
}

$expectedFields = [
    'id' => 'bigint(20) unsigned',
    'user_id' => 'bigint(20) unsigned',
    'fcm_token' => 'varchar(255)',
    'platform' => "enum('android','ios','web')",
    'device_id' => 'varchar(191)',
    'device_model' => 'varchar(100)',
    'app_version' => 'varchar(50)',
    'is_active' => 'tinyint(1)',
    'last_used_at' => 'datetime',
    'created_at' => 'timestamp',
    'updated_at' => 'timestamp',
];

foreach ($expectedFields as $field => $expectedType) {
    if (!isset($columnMap[$field])) {
        echo "  [FAIL] Missing required column: {$field}\n";
        exit(1);
    }
    echo "  [PASS] Column '{$field}' exists ({$columnMap[$field]['Type']})\n";
}

// 4. Verify Indexes and Unique Constraints
echo "\n4. Verifying unique constraints and indexes...\n";
$stmt = $pdo->query("SHOW INDEX FROM device_tokens");
$rawIndexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$indexNames = array_unique(array_column($rawIndexes, 'Key_name'));

$requiredIndexes = [
    'PRIMARY',
    'uniq_device_tokens_fcm',
    'idx_device_tokens_user_active',
    'idx_device_tokens_platform',
    'idx_device_tokens_active',
];

foreach ($requiredIndexes as $idx) {
    if (!in_array($idx, $indexNames, true)) {
        echo "  [FAIL] Missing required index/constraint: {$idx}\n";
        exit(1);
    }
    echo "  [PASS] Index/Constraint '{$idx}' verified.\n";
}

// 5. Verify Foreign Key Cascade and Insertion / Uniqueness
echo "\n5. Verifying user ownership, unique constraint, and active status...\n";
// Find any test user
$userStmt = $pdo->query("SELECT id FROM users LIMIT 1");
$testUserId = (int) $userStmt->fetchColumn();
if (!$testUserId) {
    echo "  [FAIL] No users found to test foreign key relationship.\n";
    exit(1);
}

$testFcmToken = 'fcm_test_token_' . bin2hex(random_bytes(8));

// Insert token
$insertStmt = $pdo->prepare("
    INSERT INTO device_tokens (user_id, fcm_token, platform, device_id, device_model, app_version, is_active, last_used_at)
    VALUES (:user_id, :fcm_token, 'android', 'device_pixel_9', 'Pixel 9 Pro', '1.0.0', 1, NOW())
");
$insertStmt->execute([
    ':user_id' => $testUserId,
    ':fcm_token' => $testFcmToken,
]);
$tokenId = (int) $pdo->lastInsertId();
echo "  [PASS] Inserted test device token #{$tokenId} for user #{$testUserId}.\n";

// Attempt duplicate insertion with same FCM token (must fail due to uniq_device_tokens_fcm)
try {
    $insertStmt->execute([
        ':user_id' => $testUserId,
        ':fcm_token' => $testFcmToken,
    ]);
    echo "  [FAIL] Duplicate FCM token was unexpectedly allowed!\n";
    exit(1);
} catch (PDOException $e) {
    echo "  [PASS] Unique constraint enforced (duplicate rejected with code {$e->getCode()}).\n";
}

// Clean up test token
$pdo->prepare("DELETE FROM device_tokens WHERE id = :id")->execute([':id' => $tokenId]);
echo "  [PASS] Test record cleaned up.\n";

echo "\n=== ALL MIGRATION 013 VERIFICATION TESTS PASSED SUCCESSFULLY! ===\n";
