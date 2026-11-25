<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- ORDER ITEMS BY SELLER AND STATUS ---\n";
$rows = $pdo->query('SELECT oi.seller_id, oi.item_status, COUNT(*) as cnt, COUNT(DISTINCT oi.order_id) as order_cnt 
                     FROM order_items oi 
                     GROUP BY oi.seller_id, oi.item_status')->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

echo "\n--- SAMPLE ORDER ITEMS FOR SELLER 2 ---\n";
$sample = $pdo->query('SELECT oi.id, oi.order_id, oi.seller_id, oi.product_id, oi.item_status, oi.quantity, oi.subtotal, o.order_number, o.order_status, o.created_at
                       FROM order_items oi
                       JOIN orders o ON oi.order_id = o.id
                       WHERE oi.seller_id = 2
                       ORDER BY o.id DESC
                       LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
print_r($sample);
