<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=techkart_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->prepare('UPDATE ai_chat_sessions SET title = :t, last_message = :m WHERE session_id = :sid');
$stmt->execute([
    't' => 'বাংলা পরামর্শ — বাজেট গেমিং ল্যাপটপ',
    'm' => 'Multilingual Engine (Bangla)',
    'sid' => 'session_6'
]);
echo "Updated Bengali title in MariaDB: " . $stmt->rowCount() . "\n";
$stmt->execute([
    't' => 'বাংলা পরামর্শ — বাজেট গেমিং ল্যাপটপ',
    'm' => 'Multilingual Engine (Bangla)',
    'sid' => 'session_6'
]);
echo "Updated Bengali title in MariaDB: " . $stmt->rowCount() . "\n";
