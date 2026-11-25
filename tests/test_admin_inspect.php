<?php
require_once __DIR__ . '/../bootstrap/app.php';
$baseUrl = 'http://127.0.0.1:8000/api/v1';

// Login Admin
$ch = curl_init("$baseUrl/buddy/auth/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => 'admin.audit@techkart.com', 'password' => 'password123']));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
$res = curl_exec($ch);
$loginData = json_decode($res, true);
$token = $loginData['data']['token'] ?? null;
curl_close($ch);

echo "Admin Token: $token\n\n";

// Call GET /buddy/seller/profile?seller_id=4
$ch2 = curl_init("$baseUrl/buddy/seller/profile?seller_id=4");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
$res2 = curl_exec($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "GET /buddy/seller/profile?seller_id=4:\nCode: $code2\nBody: $res2\n";
