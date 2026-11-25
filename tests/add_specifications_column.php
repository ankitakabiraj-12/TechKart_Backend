<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$hasSpec = false;
foreach ($pdo->query('SHOW COLUMNS FROM products') as $c) {
    if ($c['Field'] === 'specifications') {
        $hasSpec = true;
        break;
    }
}

if (!$hasSpec) {
    echo "Adding specifications JSON column to products table...\n";
    $pdo->exec("ALTER TABLE products ADD COLUMN specifications JSON NULL AFTER description");
    echo "SUCCESS: specifications column added to products table.\n";
} else {
    echo "specifications column already exists in products table.\n";
}
