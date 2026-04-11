<?php
$db = new PDO('sqlite:C:/Users/Admin/Desktop/JDXM/JDSJ/HOTEL/runtime/hotel_admin.db');

echo "=== 数据库表 ===\n";
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
echo "表数量: " . count($tables) . "\n";
foreach ($tables as $t) {
    $count = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "- $t ($count 条记录)\n";
}
