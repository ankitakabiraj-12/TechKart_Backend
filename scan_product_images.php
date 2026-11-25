<?php
require_once __DIR__ . '/bootstrap/app.php';

$dir = 'C:/Users/user/OneDrive/Desktop/techkart/asset/product-images/product-images';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

echo "Scanning images:\n";
$images = [];
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $path = $file->getPathname();
        $relPath = str_replace('C:\Users\user\OneDrive\Desktop\techkart\\', '', $path);
        $relPath = str_replace('\\', '/', $relPath);
        $images[] = [
            'folder' => basename(dirname($path)),
            'file' => $file->getFilename(),
            'path' => $relPath,
        ];
    }
}

echo "Found " . count($images) . " images in asset/product-images/product-images\n";
foreach (array_slice($images, 0, 30) as $img) {
    echo "Folder: {$img['folder']} | File: {$img['file']} | Rel: {$img['path']}\n";
}
