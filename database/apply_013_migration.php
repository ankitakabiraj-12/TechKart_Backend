<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

echo "=== APPLYING MIGRATION 013: DEVICE TOKENS SCHEMA ===\n\n";

try {
    $pdo = Database::getConnection();

    $migrationSql = file_get_contents(__DIR__ . '/migrations/013_device_tokens_schema.sql');
    if ($migrationSql === false) {
        throw new Exception("Unable to load 013_device_tokens_schema.sql");
    }

    $pdo->exec($migrationSql);

    echo "[PASS] Successfully applied migration 013 (device_tokens).\n";
} catch (Throwable $e) {
    echo "[FAIL] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
