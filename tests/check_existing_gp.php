<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$o = $pdo->query("SELECT id, order_number FROM orders WHERE order_number = 'ORD-GP-4001'")->fetch(PDO::FETCH_ASSOC);
echo "Existing order: ";
print_r($o);

$oi = $pdo->query("SELECT id, order_id, seller_id FROM order_items WHERE order_id = " . ($o['id'] ?? 0))->fetchAll(PDO::FETCH_ASSOC);
echo "Order items: ";
print_r($oi);
