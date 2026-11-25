<?php
require_once __DIR__ . '/../bootstrap/app.php';
$pdo = \App\Core\Database::getConnection();

// Check if seller 4 already has any product
$p4 = $pdo->query('SELECT id, title, seller_id FROM products WHERE seller_id = 4 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$p4) {
    echo "Creating demo product for seller 4...\n";
    $stmt = $pdo->prepare('INSERT INTO products (seller_id, category_id, title, slug, description, price, mrp, stock_quantity, status, created_at, updated_at) 
        VALUES (4, 1, "GadgetPlanet Pro Mechanical Keyboard", "gadgetplanet-pro-mechanical-keyboard", "Exclusive mechanical keyboard from GadgetPlanet BD", 4500.00, 5200.00, 25, "published", NOW(), NOW())');
    $stmt->execute();
    $prodId = (int)$pdo->lastInsertId();
    echo "Created product ID $prodId for seller 4\n";
} else {
    $prodId = (int)$p4['id'];
    echo "Seller 4 already has product ID {$prodId}\n";
}

// Check if seller 4 has an order item
$oi4 = $pdo->query('SELECT oi.id, oi.order_id, oi.seller_id, oi.product_id FROM order_items oi WHERE oi.seller_id = 4 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$oi4) {
    echo "Creating demo order for seller 4...\n";
    $p4Id = $prodId;
    $shippingJson = json_encode([
        'address_id' => 1,
        'label' => 'Home',
        'recipient_name' => 'Rahul Sharma',
        'phone' => '+8801712345678',
        'address_line1' => 'House 42, Road 11, Block D, Banani',
        'address_line2' => 'Floor 4A',
        'city' => 'Dhaka',
        'state' => 'Dhaka Division',
        'postal_code' => '1213',
        'country' => 'Bangladesh',
    ]);
    $stmt = $pdo->prepare('INSERT INTO orders (customer_id, order_number, total_amount, payment_status, order_status, shipping_address, created_at, updated_at) 
        VALUES (1, "ORD-GP-4001", 4500.00, "paid", "placed", :shipping, NOW(), NOW())');
    $stmt->execute(['shipping' => $shippingJson]);
    $orderId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO order_items (order_id, seller_id, product_id, unit_price, quantity, subtotal, item_status, created_at)
        VALUES (:oid, 4, :pid, 4500.00, 1, 4500.00, "placed", NOW())')->execute([
            'oid' => $orderId,
            'pid' => $p4Id
        ]);
    echo "Created order ID $orderId with item for seller 4\n";
} else {
    $orderId = (int)$oi4['order_id'];
    echo "Seller 4 already has order item in order ID {$orderId}\n";
}

// Check if seller 4 has a return request
$ret4 = $pdo->query('SELECT id, return_number, seller_id FROM return_requests WHERE seller_id = 4 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$ret4) {
    echo "Creating demo return request for seller 4...\n";
    $ord4Id = $orderId;
    $p4Id = $prodId;
    $oi4Id = (int)$pdo->query("SELECT id FROM order_items WHERE order_id = $ord4Id AND seller_id = 4 LIMIT 1")->fetchColumn();
    $pdo->prepare('INSERT INTO return_requests (return_number, order_id, order_item_id, customer_id, seller_id, product_id, reason, details, status, estimated_refund, created_at, updated_at)
        VALUES ("RET-GP-4001", :oid, :oiid, 1, 4, :pid, "defective", "Key switch unresponsive", "pending", 4500.00, NOW(), NOW())')->execute([
            'oid' => $ord4Id,
            'oiid' => $oi4Id,
            'pid' => $p4Id
        ]);
    $retId = (int)$pdo->lastInsertId();
    echo "Created return request ID $retId for seller 4\n";
} else {
    echo "Seller 4 already has return request ID {$ret4['id']}\n";
}
