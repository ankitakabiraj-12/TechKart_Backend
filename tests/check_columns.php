<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$colStmt = $pdo->query('SHOW COLUMNS FROM order_items');
echo "order_items columns:\n";
foreach ($colStmt as $c) {
    echo $c['Field'] . "\n";
}
