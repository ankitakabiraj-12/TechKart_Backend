<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- TABLES IN DATABASE ---\n";
foreach ($pdo->query('SHOW TABLES') as $tbl) {
    echo array_values($tbl)[0] . "\n";
}
