<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

try {
    echo "Applying Migration 009: Product Approval Status & Review Feedback...\n";
    
    // Check if columns already exist
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM products') as $c) {
        $cols[] = $c['Field'];
    }

    // 1. Modify status enum
    $pdo->exec("ALTER TABLE `products` MODIFY COLUMN `status` ENUM('draft', 'pending_review', 'approved', 'rejected', 'published', 'archived') NOT NULL DEFAULT 'pending_review'");
    echo " - status enum updated successfully.\n";

    // 2. Add review_notes if not present
    if (!in_array('review_notes', $cols)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `review_notes` TEXT NULL AFTER `status`");
        echo " - review_notes added.\n";
    }

    // 3. Add reviewed_by if not present
    if (!in_array('reviewed_by', $cols)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `reviewed_by` BIGINT UNSIGNED NULL AFTER `review_notes`");
        $pdo->exec("ALTER TABLE `products` ADD CONSTRAINT `fk_product_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        echo " - reviewed_by added.\n";
    }

    // 4. Add reviewed_at if not present
    if (!in_array('reviewed_at', $cols)) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`");
        echo " - reviewed_at added.\n";
    }

    echo "Migration 009 completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
