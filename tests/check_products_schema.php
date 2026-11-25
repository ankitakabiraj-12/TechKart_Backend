<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- PRODUCTS COLUMNS ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM products') as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- CATEGORIES ---\n";
foreach ($pdo->query('SELECT id, name, slug FROM categories') as $cat) {
    echo "ID {$cat['id']}: {$cat['name']} ({$cat['slug']})\n";
}
