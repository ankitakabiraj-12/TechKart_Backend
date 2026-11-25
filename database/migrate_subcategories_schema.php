<?php
require_once __DIR__ . '/../bootstrap/app.php';

$db = App\Core\Database::getConnection();

// 1. Drop old single-column unique index on slug if still present
try {
    $db->exec('ALTER TABLE categories DROP INDEX slug');
    echo "Dropped index slug.\n";
} catch (Exception $e) {
    echo "Index slug not present or already dropped.\n";
}

// 2. Ensure uq_parent_slug exists
try {
    $db->exec('ALTER TABLE categories ADD UNIQUE KEY uq_parent_slug (parent_id, slug)');
    echo "Added unique key uq_parent_slug (parent_id, slug).\n";
} catch (Exception $e) {
    echo "uq_parent_slug already exists.\n";
}

// 3. Ensure products table has subcategory_id column
try {
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'subcategory_id'")->fetchAll();
    if (empty($cols)) {
        $db->exec('ALTER TABLE products ADD COLUMN subcategory_id INT(10) UNSIGNED NULL AFTER category_id, ADD CONSTRAINT fk_product_subcategory FOREIGN KEY (subcategory_id) REFERENCES categories(id) ON DELETE SET NULL');
        echo "Added subcategory_id column to products.\n";
    } else {
        echo "subcategory_id column already exists in products.\n";
    }
} catch (Exception $e) {
    echo "Column check/add note: " . $e->getMessage() . "\n";
}
