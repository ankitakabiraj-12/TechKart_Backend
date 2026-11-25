<?php
$data = require __DIR__ . '/subcategories_master.php';
$total = 0;
echo "Total Main Categories: " . count($data) . "\n";
foreach ($data as $id => $info) {
    $count = count($info['subcategories']);
    $total += $count;
    echo "Cat {$id} ({$info['name']}): {$count} subcategories\n";
}
echo "TOTAL SUBCATEGORIES: {$total}\n";
