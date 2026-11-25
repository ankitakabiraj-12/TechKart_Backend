<?php
require_once __DIR__ . '/bootstrap/app.php';

$baseDir = 'C:/Users/user/OneDrive/Desktop/techkart/asset/product_images';
$subdirs = array_filter(glob($baseDir . '/*'), 'is_dir');

$assetLines = [];
foreach ($subdirs as $dir) {
    $dirName = basename($dir);
    $assetLines[] = "    - asset/product_images/{$dirName}/";
}

echo implode("\n", $assetLines);
