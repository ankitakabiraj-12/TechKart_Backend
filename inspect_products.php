<?php
require_once __DIR__ . '/bootstrap/app.php';
$db = App\Core\Database::getConnection();

$stmt = $db->query("SELECT p.id, p.title, p.category_id, p.subcategory_id, c.name as cat_name, sub.name as sub_name,
                    (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) as primary_img
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN categories sub ON p.subcategory_id = sub.id
                    ORDER BY p.id ASC");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total products: " . count($products) . "\n";
foreach ($products as $p) {
    echo "ID: {$p['id']} | Cat({$p['category_id']}): {$p['cat_name']} | Sub({$p['subcategory_id']}): {$p['sub_name']} | Img: {$p['primary_img']} | Title: {$p['title']}\n";
}
