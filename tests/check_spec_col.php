<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$prod = $pdo->query('SELECT id, title, description, specifications FROM products WHERE specifications IS NOT NULL LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($prod) {
    echo "Found product with specifications column:\n";
    print_r($prod);
} else {
    echo "No product has specifications set or column doesn't exist yet.\n";
}
