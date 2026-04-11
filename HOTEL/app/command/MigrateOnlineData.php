<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Env;

class MigrateOnlineData extends Command
{
    protected function configure()
    {
        $this->setName('migrate:online-data')
            ->setDescription('迁移线上数据模块：添加新字段');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始迁移线上数据模块...');
        
        try {
            $dbType = Env::get('DB_TYPE', 'mysql');
            $output->writeln("数据库类型: {$dbType}");
            
            // 添加线上数据权限字段到 user_hotel_permissions 表
            $this->addOnlineDataPermissionFields($output, $dbType);
            
            // 检查并创建 online_daily_data 表
            $this->ensureOnlineDailyDataTable($output, $dbType);
            
            $output->writeln('迁移完成！');
            
        } catch (\Exception $e) {
            $output->writeln('错误: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
    
    protected function addOnlineDataPermissionFields(Output $output, string $dbType): void
    {
        $fields = [
            'can_view_online_data' => 'TINYINT DEFAULT 0',
            'can_fetch_online_data' => 'TINYINT DEFAULT 0',
            'can_delete_online_data' => 'TINYINT DEFAULT 0',
        ];
        
        foreach ($fields as $field => $definition) {
            try {
                // 检查字段是否存在
                if ($dbType === 'sqlite') {
                    $exists = Db::query("PRAGMA table_info(user_hotel_permissions)");
                    $fieldExists = false;
                    foreach ($exists as $col) {
                        if ($col['name'] === $field) {
                            $fieldExists = true;
                            break;
                        }
                    }
                    
                    if (!$fieldExists) {
                        Db::execute("ALTER TABLE user_hotel_permissions ADD COLUMN {$field} {$definition}");
                        $output->writeln("✓ 添加字段 {$field} 到 user_hotel_permissions 表");
                    } else {
                        $output->writeln("- 字段 {$field} 已存在，跳过");
                    }
                } else {
                    // MySQL
                    $exists = Db::query("SHOW COLUMNS FROM user_hotel_permissions LIKE '{$field}'");
                    if (empty($exists)) {
                        Db::execute("ALTER TABLE user_hotel_permissions ADD COLUMN {$field} {$definition}");
                        $output->writeln("✓ 添加字段 {$field} 到 user_hotel_permissions 表");
                    } else {
                        $output->writeln("- 字段 {$field} 已存在，跳过");
                    }
                }
            } catch (\Exception $e) {
                $output->writeln("⚠ 添加字段 {$field} 时出错: " . $e->getMessage());
            }
        }
    }
    
    protected function ensureOnlineDailyDataTable(Output $output, string $dbType): void
    {
        try {
            // 检查表是否存在
            if ($dbType === 'sqlite') {
                $tables = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name='online_daily_data'");
            } else {
                $tables = Db::query("SHOW TABLES LIKE 'online_daily_data'");
            }
            
            if (empty($tables)) {
                // 创建表
                if ($dbType === 'sqlite') {
                    Db::execute("
                        CREATE TABLE online_daily_data (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            hotel_id VARCHAR(50) NOT NULL,
                            hotel_name VARCHAR(100),
                            system_hotel_id INTEGER,
                            data_date DATE NOT NULL,
                            amount DECIMAL(12,2) DEFAULT 0,
                            quantity INTEGER DEFAULT 0,
                            book_order_num INTEGER DEFAULT 0,
                            comment_score DECIMAL(3,1) DEFAULT 0,
                            qunar_comment_score DECIMAL(3,1) DEFAULT 0,
                            raw_data TEXT,
                            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                            update_time DATETIME DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                } else {
                    Db::execute("
                        CREATE TABLE online_daily_data (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            hotel_id VARCHAR(50) NOT NULL,
                            hotel_name VARCHAR(100),
                            system_hotel_id INT,
                            data_date DATE NOT NULL,
                            amount DECIMAL(12,2) DEFAULT 0,
                            quantity INT DEFAULT 0,
                            book_order_num INT DEFAULT 0,
                            comment_score DECIMAL(3,1) DEFAULT 0,
                            qunar_comment_score DECIMAL(3,1) DEFAULT 0,
                            raw_data TEXT,
                            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                            update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX idx_hotel_date (hotel_id, data_date),
                            INDEX idx_system_hotel (system_hotel_id, data_date)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                $output->writeln('✓ 创建 online_daily_data 表成功');
            } else {
                // 表存在，检查并添加新字段
                $fieldsToAdd = [
                    'system_hotel_id' => 'INTEGER',
                    'data_value' => 'DECIMAL(15,2) DEFAULT 0',
                    'source' => "VARCHAR(50) DEFAULT 'ctrip'",
                    'dimension' => 'VARCHAR(100) DEFAULT \'\'',
                    'data_type' => 'VARCHAR(50) DEFAULT \'\'',
                ];
                
                foreach ($fieldsToAdd as $field => $definition) {
                    if ($dbType === 'sqlite') {
                        $columns = Db::query("PRAGMA table_info(online_daily_data)");
                        $hasField = false;
                        foreach ($columns as $col) {
                            if ($col['name'] === $field) {
                                $hasField = true;
                                break;
                            }
                        }
                        if (!$hasField) {
                            Db::execute("ALTER TABLE online_daily_data ADD COLUMN {$field} {$definition}");
                            $output->writeln("✓ 添加 {$field} 字段到 online_daily_data 表");
                        }
                    } else {
                        $exists = Db::query("SHOW COLUMNS FROM online_daily_data LIKE '{$field}'");
                        if (empty($exists)) {
                            Db::execute("ALTER TABLE online_daily_data ADD COLUMN {$field} {$definition}");
                            $output->writeln("✓ 添加 {$field} 字段到 online_daily_data 表");
                        }
                    }
                }
                
                // 添加索引
                try {
                    if ($dbType === 'sqlite') {
                        // SQLite 不支持 CREATE INDEX IF NOT EXISTS，需要先检查
                        $indexes = Db::query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='online_daily_data'");
                        $indexNames = array_column($indexes, 'name');
                        if (!in_array('idx_system_hotel', $indexNames)) {
                            Db::execute("CREATE INDEX idx_system_hotel ON online_daily_data(system_hotel_id, data_date)");
                            $output->writeln('✓ 创建索引 idx_system_hotel');
                        }
                    } else {
                        Db::execute("ALTER TABLE online_daily_data ADD INDEX idx_system_hotel (system_hotel_id, data_date)");
                        $output->writeln('✓ 创建索引 idx_system_hotel');
                    }
                } catch (\Exception $e) {
                    // 索引可能已存在
                }
            }
        } catch (\Exception $e) {
            $output->writeln("⚠ 处理 online_daily_data 表时出错: " . $e->getMessage());
        }
    }
}
