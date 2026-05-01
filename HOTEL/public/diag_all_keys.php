<?php
// 独立诊断脚本 - 直接连接MySQL查询美团/携程配置数据
header('Content-Type: text/html; charset=utf-8');
echo "<h2>配置数据诊断 - 数据库直连</h2>";
echo "<style>body{font-family:Consolas,'Microsoft YaHei',monospace;font-size:14px;padding:20px} table{border-collapse:collapse;margin:10px 0;width:100%} td,th{border:1px solid #ccc;padding:6px 10px;text-align:left} .ok{color:green;font-weight:bold} .warn{color:#b8860b;font-weight:bold} .err{color:red;font-weight:bold} pre{background:#f5f5f5;padding:8px;border-radius:4px;overflow:auto;max-height:300px}</style>";

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=hotelx;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<p class='ok'>MySQL 连接成功 (hotelx@127.0.0.1:3306)</p>";
} catch (PDOException $e) {
    die("<p class='err'>数据库连接失败: " . htmlspecialchars($e->getMessage()) . "</p>");
}

// 查找所有含 meituan 或 ctrip 的 key
$stmt = $pdo->query("SELECT config_key, config_value FROM system_configs WHERE config_key LIKE '%meituan%' OR config_key LIKE '%ctrip%' ORDER BY config_key");
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "<p class='err'>system_configs 表: 未找到任何 meituan/ctrip 配置!</p>";
} else {
    showResults($rows);
}

// 也检查 system_config（单数）表
echo "<hr><h3>检查 system_config（单数）表...</h3>";
try {
    $stmt2 = $pdo->query("SELECT config_key, config_value FROM `system_config` WHERE config_key LIKE '%meituan%' OR config_key LIKE '%ctrip%' ORDER BY config_key");
    $rows2 = $stmt2->fetchAll();
    if (!empty($rows2)) {
        echo "<p class='warn'>system_config 表中也找到 " . count($rows2) . " 条记录!</p>";
        showResults($rows2);
    } else {
        echo "<p class='ok'>system_config 表中无相关数据</p>";
    }
} catch (Exception $e) {
    echo "<p class='warn'>system_config 表不存在或查询失败: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 额外：列出所有 config_keys 做参考
echo "<hr><h3>所有 system_configs 的 key 列表（前50条）...</h3>";
try {
    $all = $pdo->query("SELECT config_key, LENGTH(config_value) as len FROM system_configs ORDER BY config_key LIMIT 50")->fetchAll();
    echo "<table><tr><th>config_key</th><th>value长度</th></tr>";
    foreach ($all as $r) {
        echo "<tr><td>" . htmlspecialchars($r['config_key']) . "</td><td>{$r['len']}</td></tr>";
    }
    echo "</table>";
    
    // 总数
    $cnt = $pdo->query("SELECT COUNT(*) FROM system_configs")->fetchColumn();
    echo "<p>共 $cnt 条配置记录</p>";
} catch (Exception $e) {}

function showResults($rows) {
    if (empty($rows)) return;
    
    echo "<table><tr><th style='width:280px'>Key</th><th style='width:60px'>长度</th><th style='width:60px'>条数</th><th>详细内容(每条的name+user_id)</th></tr>";
    
    foreach ($rows as $row) {
        $key = $row['config_key'];
        $val = $row['config_value'] ?? '';
        $decoded = json_decode($val, true);
        
        if (is_array($decoded)) {
            $count = count($decoded);
            $detailHtml = '';
            if ($count > 0 && !isset($decoded[0])) {
                foreach ($decoded as $idx => $item) {
                    if (is_array($item)) {
                        $name = isset($item['name']) ? htmlspecialchars($item['name']) : (isset($item['hotel_id']) ? htmlspecialchars($item['hotel_id']) : '(无名称)');
                        $uid = array_key_exists('user_id', $item) 
                            ? ($item['user_id'] === null ? '<span class="warn">NULL</span>' : htmlspecialchars((string)$item['user_id'])) 
                            : '<span class="warn">(无user_id字段)</span>';
                        $idVal = isset($item['id']) ? htmlspecialchars($item['id']) : $idx;
                        $detailHtml .= "&nbsp;&nbsp;<b>[{$idVal}]</b> name={$name} | user_id={$uid}<br>";
                    }
                }
            } else if ($count > 0) {
                $detailHtml = '<pre>' . htmlspecialchars(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                $detailHtml = '<span class="warn">空数组</span>';
            }
        } else {
            $count = '非数组';
            $detailHtml = '<span style="word-break:break-all">' . htmlspecialchars(mb_substr($val, 0, 500, 'UTF-8')) . '</span>';
        }
        
        echo "<tr><td>" . htmlspecialchars($key) . "</td><td>" . strlen($val) . "</td><td>$count</td><td style='background:#fafafa'>$detailHtml</td></tr>";
    }
    echo "</table>";
}
?>
