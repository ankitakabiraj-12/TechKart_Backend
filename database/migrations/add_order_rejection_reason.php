<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';

use App\Core\Database;

$pdo = Database::getConnection();

// 1. Add rejection_reason column to orders table if missing
$orderCols = array_column($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('rejection_reason', $orderCols, true)) {
    echo "Adding rejection_reason column to orders table...\n";
    $pdo->exec("ALTER TABLE orders ADD COLUMN rejection_reason TEXT NULL AFTER shipping_address");
    echo "SUCCESS: Added rejection_reason to orders.\n";
} else {
    echo "rejection_reason already exists in orders.\n";
}

// 2. Add rejection_reason column to order_items table if missing
$itemCols = array_column($pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('rejection_reason', $itemCols, true)) {
    echo "Adding rejection_reason column to order_items table...\n";
    $pdo->exec("ALTER TABLE order_items ADD COLUMN rejection_reason TEXT NULL AFTER item_status");
    echo "SUCCESS: Added rejection_reason to order_items.\n";
} else {
    echo "rejection_reason already exists in order_items.\n";
}

echo "Database schema verified.\n";
