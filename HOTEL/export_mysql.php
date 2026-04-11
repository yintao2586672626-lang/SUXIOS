<?php
/**
 * SQLite 转 MySQL 导出脚本
 * 将当前 SQLite 数据库转换为 MySQL 格式的 SQL 文件
 */

// 数据库路径
$sqliteDb = __DIR__ . '/runtime/hotel_admin.db';
$outputFile = __DIR__ . '/database_mysql.sql';

if (!file_exists($sqliteDb)) {
    die("SQLite数据库不存在: $sqliteDb\n");
}

// 连接SQLite
$pdo = new PDO("sqlite:$sqliteDb");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 获取所有表
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$sql = "-- ============================================\n";
$sql .= "-- 酒店管理系统 MySQL 数据库导出\n";
$sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
$sql .= "-- ============================================\n\n";
$sql .= "SET NAMES utf8mb4;\n";
$sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $table) {
    echo "处理表: $table\n";
    
    // 获取表结构
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    
    // 创建表结构
    $sql .= "-- ----------------------------\n";
    $sql .= "-- 表结构: $table\n";
    $sql .= "-- ----------------------------\n";
    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
    $sql .= "CREATE TABLE `{$table}` (\n";
    
    $primaryKeys = [];
    $fields = [];
    
    foreach ($columns as $col) {
        $name = $col['name'];
        $type = $col['type'];
        $notNull = $col['notnull'] ? 'NOT NULL' : 'NULL';
        $default = $col['dflt_value'] !== null ? "DEFAULT {$col['dflt_value']}" : '';
        $pk = $col['pk'];
        
        // 转换类型
        $mysqlType = convertType($type);
        
        if ($pk) {
            $primaryKeys[] = $name;
            if ($type === 'INTEGER') {
                $mysqlType = 'INT';
                if (count($columns) === 1 || $col['pk'] === 1) {
                    $mysqlType = 'INT AUTO_INCREMENT';
                }
            }
        }
        
        $fieldDef = "  `{$name}` {$mysqlType} {$notNull}";
        if ($default) {
            $fieldDef .= " {$default}";
        }
        $fields[] = $fieldDef;
    }
    
    $sql .= implode(",\n", $fields);
    
    if (!empty($primaryKeys)) {
        $sql .= ",\n  PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
    }
    
    $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
    
    // 获取数据
    $rows = $pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        $sql .= "-- ----------------------------\n";
        $sql .= "-- 数据: $table (" . count($rows) . " 条记录)\n";
        $sql .= "-- ----------------------------\n";
        
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(function($v) use ($pdo) {
                if ($v === null) return 'NULL';
                return $pdo->quote($v);
            }, array_values($row));
            
            $sql .= "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }
}

$sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

// 写入文件
file_put_contents($outputFile, $sql);

echo "\n导出完成!\n";
echo "输出文件: $outputFile\n";
echo "文件大小: " . round(filesize($outputFile) / 1024, 2) . " KB\n";

/**
 * SQLite类型转MySQL类型
 */
function convertType($type) {
    $type = strtoupper($type);
    
    $map = [
        'INTEGER' => 'INT',
        'TEXT' => 'TEXT',
        'REAL' => 'DECIMAL(12,2)',
        'BLOB' => 'BLOB',
        'VARCHAR(50)' => 'VARCHAR(50)',
        'VARCHAR(100)' => 'VARCHAR(100)',
        'VARCHAR(255)' => 'VARCHAR(255)',
        'DATE' => 'DATE',
        'DATETIME' => 'DATETIME',
        'TINYINT' => 'TINYINT',
        'DECIMAL(12,2)' => 'DECIMAL(12,2)',
        'DECIMAL(5,2)' => 'DECIMAL(5,2)',
        'DECIMAL(3,1)' => 'DECIMAL(3,1)',
    ];
    
    return $map[$type] ?? 'TEXT';
}
