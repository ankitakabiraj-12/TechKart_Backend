<?php

require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "Running Migration 011...\n";
try {
    // Check columns
    $cols = $pdo->query("SHOW COLUMNS FROM product_reviews LIKE 'seller_reply'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `product_reviews`
            MODIFY COLUMN `status` ENUM('approved', 'pending', 'rejected', 'flagged', 'hidden') NOT NULL DEFAULT 'approved',
            ADD COLUMN `seller_reply` TEXT NULL AFTER `review_text`,
            ADD COLUMN `replied_at` DATETIME NULL AFTER `seller_reply`,
            ADD COLUMN `moderated_by` BIGINT UNSIGNED NULL AFTER `replied_at`,
            ADD COLUMN `moderation_reason` VARCHAR(255) NULL AFTER `moderated_by`,
            ADD COLUMN `moderated_at` DATETIME NULL AFTER `moderation_reason`,
            ADD CONSTRAINT `fk_reviews_moderator` FOREIGN KEY (`moderated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        echo " - Extended `product_reviews` with seller_reply, status enum, and moderation fields.\n";
    } else {
        echo " - `seller_reply` column already exists in `product_reviews`.\n";
    }

} catch (\Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration 011 completed successfully.\n";

