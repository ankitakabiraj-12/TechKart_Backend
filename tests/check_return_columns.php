<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$colStmt = $pdo->query('SHOW COLUMNS FROM return_requests');
echo "return_requests columns:\n";
foreach ($colStmt as $c) {
    echo $c['Field'] . "\n";
}
