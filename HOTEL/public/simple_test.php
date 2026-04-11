<?php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== 简单测试 ===\n\n";

// 测试 MySQL 连接
echo "1. MySQL 连接: ";
$conn = new mysqli('127.0.0.1', 'root', '', 'hotelx', 3306);
if ($conn->connect_error) {
    echo "❌ " . $conn->connect_error . "\n";
} else {
    echo "✅ 成功\n";
    
    echo "\n2. 查询用户: ";
    $result = $conn->query("SELECT id, username, password, status FROM users WHERE username='admin'");
    if ($row = $result->fetch_assoc()) {
        echo "✅ 找到用户\n";
        echo "   ID: " . $row['id'] . "\n";
        echo "   用户名: " . $row['username'] . "\n";
        echo "   密码长度: " . strlen($row['password']) . "\n";
        echo "   密码哈希: " . substr($row['password'], 0, 20) . "...\n";
        
        echo "\n3. PHP password_verify 测试: ";
        $hash_in_db = $row['password'];
        $test_result = password_verify('admin123', $hash_in_db);
        echo $test_result ? "✅ true" : "❌ false";
        echo "\n";
    } else {
        echo "❌ 未找到\n";
    }
    $conn->close();
}

echo "\n4. 当前数据库中的密码: ";
$conn2 = new mysqli('127.0.0.1', 'root', '', 'hotelx', 3306);
$res = $conn2->query("SELECT password FROM users WHERE username='admin'");
$row = $res->fetch_assoc();
echo $row['password'];
$conn2->close();
