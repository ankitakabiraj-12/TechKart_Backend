<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$pdo->exec("INSERT INTO orders (id, order_number, customer_id, total_amount, payment_status, order_status, shipping_address, created_at, updated_at)
VALUES (999, 'ORD-TEST-SELLER4', 1, 4500.00, 'paid', 'placed', '{\"city\":\"Bengaluru\",\"country\":\"India\"}', NOW(), NOW())
ON DUPLICATE KEY UPDATE order_number = 'ORD-TEST-SELLER4'");

$pdo->exec("UPDATE order_items SET order_id = 999 WHERE id = 99");
echo "Order 999 assigned to Seller 4 item 99.\n";
