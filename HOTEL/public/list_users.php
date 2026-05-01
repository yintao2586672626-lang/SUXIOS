<?php
// 诊断 VIP001 登录 + 权限问题
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=hotelx;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. 重置密码
    $newHash = password_hash('123456', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
    $stmt->execute([$newHash, 'VIP001']);
    
    // 2. 查看 VIP001 完整信息
    $stmt2 = $pdo->prepare('SELECT id, username, realname, role_id, status, hotel_id FROM users WHERE username = ?');
    $stmt2->execute(['VIP001']);
    $user = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    echo "=== VIP001 用户信息 ===\n";
    print_r($user);
    
    echo "\n=== 密码验证 ===\n";
    $stmt3 = $pdo->prepare('SELECT password FROM users WHERE username = ?');
    $stmt3->execute(['VIP001']);
    $row = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify('123456', $row['password'])) {
        echo "✓ 密码 123456 验证通过\n";
    } else {
        echo "✗ 密码不匹配\n";
    }
    
    // 3. 查看 role_id=8 是什么角色
    echo "\n=== 角色信息 ===\n";
    $stmt4 = $pdo->query("SELECT * FROM roles WHERE id = 8");
    $role = $stmt4->fetch(PDO::FETCH_ASSOC);
    print_r($role);
    
    // 4. 查看所有角色
    echo "\n=== 所有角色 ===\n";
    $stmt5 = $pdo->query('SELECT id, name, slug, is_admin FROM roles');
    while($r = $stmt5->fetch(PDO::FETCH_ASSOC)) {
        echo "{$r['id']} | {$r['name']} | {$r['slug']} | admin=" . ($r['is_admin']??'') . "\n";
    }
    
    // 5. 检查 Token 缓存 (ThinkPHP cache)
    echo "\n=== 注意: Token缓存在ThinkPHP中，需通过框架查看 ===\n";
    echo "VIP001 hotel_id = " . ($user['hotel_id'] ?? 'NULL') . "\n";
    echo "如果 hotel_id 为空，requireHotel() 会返回 403！\n";
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
?>
