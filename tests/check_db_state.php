<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- USERS ---\n";
foreach ($pdo->query('SELECT id, email, role, is_active FROM users') as $r) {
    echo "ID {$r['id']}: {$r['email']} [{$r['role']}] active={$r['is_active']}\n";
}

echo "\n--- PRODUCTS BY SELLER ---\n";
foreach ($pdo->query('SELECT seller_id, COUNT(*) as cnt FROM products GROUP BY seller_id') as $r) {
    echo "Seller {$r['seller_id']}: {$r['cnt']} products\n";
}

echo "\n--- ORDERS BY SELLER ITEMS ---\n";
foreach ($pdo->query('SELECT seller_id, COUNT(DISTINCT order_id) as cnt FROM order_items GROUP BY seller_id') as $r) {
    echo "Seller {$r['seller_id']}: {$r['cnt']} orders\n";
}

echo "\n--- RETURNS BY SELLER ---\n";
foreach ($pdo->query('SELECT seller_id, COUNT(*) as cnt FROM return_requests GROUP BY seller_id') as $r) {
    echo "Seller {$r['seller_id']}: {$r['cnt']} returns\n";
}
