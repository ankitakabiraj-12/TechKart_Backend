<?php

require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "Running Migration 010...\n";
try {
    // Check if column exists
    $cols = $pdo->query("SHOW COLUMNS FROM products LIKE 'low_stock_threshold'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `low_stock_threshold` INT NOT NULL DEFAULT 5 AFTER `stock_quantity`");
        echo " - Added `low_stock_threshold` to `products`.\n";
    } else {
        echo " - `low_stock_threshold` column already exists in `products`.\n";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_alerts` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `seller_id` BIGINT UNSIGNED NOT NULL,
        `product_id` BIGINT UNSIGNED NOT NULL,
        `alert_type` ENUM('low_stock', 'out_of_stock', 'restock_recommendation') NOT NULL DEFAULT 'low_stock',
        `severity` ENUM('warning', 'critical') NOT NULL DEFAULT 'warning',
        `current_stock` INT NOT NULL DEFAULT 0,
        `threshold` INT NOT NULL DEFAULT 5,
        `title` VARCHAR(150) NOT NULL,
        `message` TEXT NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `read_at` DATETIME NULL,
        `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_invalert_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_invalert_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
        INDEX `idx_invalert_seller_read` (`seller_id`, `is_read`, `is_dismissed`),
        INDEX `idx_invalert_product` (`product_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo " - `inventory_alerts` table verified/created successfully.\n";

} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration 010 completed successfully.\n";
