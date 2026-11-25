<?php
require_once __DIR__ . '/bootstrap/app.php';
$db = App\Core\Database::getConnection();

$stmt = $db->query("SELECT id, parent_id, name, slug FROM categories WHERE parent_id = 1 ORDER BY id ASC");
$subcats = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Category 1 subcategories in DB:\n";
foreach ($subcats as $s) {
    echo "ID: {$s['id']} | Parent: {$s['parent_id']} | Name: {$s['name']} | Slug: {$s['slug']}\n";
}
