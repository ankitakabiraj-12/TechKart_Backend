<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

// Check user 4
$u4 = $pdo->query('SELECT * FROM users WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
echo "User 4:\n";
print_r($u4);

$sp4 = $pdo->query('SELECT * FROM seller_profiles WHERE user_id = 4')->fetch(PDO::FETCH_ASSOC);
echo "Seller Profile 4:\n";
print_r($sp4);

// Check if user 4 has a password hash that matches password123
if ($u4) {
    echo "Password check: " . (password_verify('password123', $u4['password_hash']) ? 'MATCH' : 'MISMATCH') . "\n";
}
