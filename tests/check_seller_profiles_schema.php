<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

echo "--- SELLER_PROFILES COLUMNS ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM seller_profiles') as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- USERS COLUMNS ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- SAMPLE SELLER PROFILE DATA ---\n";
foreach ($pdo->query('SELECT sp.*, u.full_name, u.email, u.phone FROM seller_profiles sp JOIN users u ON sp.user_id = u.id') as $r) {
    print_r($r);
}
