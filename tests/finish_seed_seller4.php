<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

$orderId = 27;
$prodId = 20;
$oi4Id = 32;

// Add return request for this order item with status 'requested'
$pdo->prepare('INSERT INTO return_requests (return_number, order_id, order_item_id, customer_id, seller_id, product_id, reason, status, estimated_refund, created_at, updated_at)
    VALUES ("RET-GP-4001", :oid, :oiid, 1, 4, :pid, "defective", "requested", 4500.00, NOW(), NOW())')->execute([
        'oid' => $orderId,
        'oiid' => $oi4Id,
        'pid' => $prodId
    ]);
$retId = (int)$pdo->lastInsertId();
echo "SUCCESS: Inserted return request ID $retId for order item $oi4Id\n";
