<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$sampleOrder = $pdo->query('SELECT shipping_address FROM orders LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo "Sample shipping_address:\n";
print_r($sampleOrder['shipping_address']);
echo "\n";
