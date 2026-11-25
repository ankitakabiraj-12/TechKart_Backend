<?php
require_once __DIR__ . '/bootstrap/app.php';
$db = App\Core\Database::getConnection();
$total = $db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$roots = $db->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL')->fetchColumn();
$subcats = $db->query('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL')->fetchColumn();
echo "Total categories in DB: {$total}\n";
echo "Main categories (roots): {$roots}\n";
echo "Subcategories: {$subcats}\n";

$stmt = $db->query("SELECT parent_id, COUNT(*) as cnt FROM categories WHERE parent_id IS NOT NULL GROUP BY parent_id ORDER BY parent_id ASC");
$rows = $stmt->fetchAll();
echo "\nSubcategories count per main category (Parent ID 1 to 45):\n";
foreach ($rows as $r) {
    echo "Parent {$r['parent_id']}: {$r['cnt']}\n";
}
