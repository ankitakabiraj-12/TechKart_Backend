<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- EXISTING PRODUCTS STATUS BREAKDOWN ---\n";
foreach ($pdo->query('SELECT status, COUNT(*) as cnt FROM products GROUP BY status') as $r) {
    echo "Status {$r['status']}: {$r['cnt']}\n";
}

echo "\n--- SAMPLE PRODUCTS ---\n";
foreach ($pdo->query('SELECT id, seller_id, title, status FROM products LIMIT 5') as $r) {
    echo "ID {$r['id']}: [{$r['status']}] {$r['title']} (Seller {$r['seller_id']})\n";
}
