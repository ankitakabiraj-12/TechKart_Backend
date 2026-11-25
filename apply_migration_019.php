<?php
require_once __DIR__ . '/bootstrap/app.php';

use App\Core\Database;

try {
    $pdo = Database::getConnection();
    echo "Applying Migration 019...\n";

    // Check if column already exists
    $cols = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('published_at', $cols, true)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `published_at` DATETIME NULL AFTER `status`, ADD INDEX `idx_prod_published_at` (`published_at`)");
        echo "Column published_at added.\n";
    } else {
        echo "Column published_at already exists.\n";
    }

    $updated = $pdo->exec("UPDATE `products` SET `published_at` = `created_at` WHERE `status` = 'published' AND `published_at` IS NULL");
    echo "Updated existing published products: {$updated} rows.\n";

    echo "Migration 019 Applied Successfully!\n";
} catch (\Throwable $e) {
    echo "Migration 019 Failed: " . $e->getMessage() . "\n";
    exit(1);
}
