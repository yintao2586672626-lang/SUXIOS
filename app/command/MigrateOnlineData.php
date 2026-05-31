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
            ->setDescription('杩佺Щ绾夸笂鏁版嵁妯″潡锛氭坊鍔犳柊瀛楁');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('Start online data migration...');

        try {
            $dbType = Env::get('DB_TYPE', 'mysql');
            $output->writeln("Database type: {$dbType}");

            // 娣诲姞绾夸笂鏁版嵁鏉冮檺瀛楁鍒?user_hotel_permissions 琛?
            $this->addOnlineDataPermissionFields($output, $dbType);

            // 妫€鏌ュ苟鍒涘缓 online_daily_data 琛?
            $this->ensureOnlineDailyDataTable($output, $dbType);

            $output->writeln('Online data migration completed.');

        } catch (\Exception $e) {
            $output->writeln('Error: ' . $e->getMessage());
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
                // 妫€鏌ュ瓧娈垫槸鍚﹀瓨鍦?
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
                        $output->writeln("Added {$field} to user_hotel_permissions.");
                    } else {
                        $output->writeln("- {$field} already exists, skipped.");
                    }
                } else {
                    // MySQL
                    $exists = Db::query("SHOW COLUMNS FROM user_hotel_permissions LIKE '{$field}'");
                    if (empty($exists)) {
                        Db::execute("ALTER TABLE user_hotel_permissions ADD COLUMN {$field} {$definition}");
                        $output->writeln("Added {$field} to user_hotel_permissions.");
                    } else {
                        $output->writeln("- {$field} already exists, skipped.");
                    }
                }
            } catch (\Exception $e) {
                $output->writeln("Failed to add {$field}: " . $e->getMessage());
            }
        }
    }

    protected function ensureOnlineDailyDataTable(Output $output, string $dbType): void
    {
        try {
            // 妫€鏌ヨ〃鏄惁瀛樺湪
            if ($dbType === 'sqlite') {
                $tables = Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name='online_daily_data'");
            } else {
                $tables = Db::query("SHOW TABLES LIKE 'online_daily_data'");
            }

            if (empty($tables)) {
                // 鍒涘缓琛?
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
                            data_value DECIMAL(15,2) DEFAULT 0,
                            source VARCHAR(50) DEFAULT 'ctrip',
                            dimension VARCHAR(100) DEFAULT '',
                            data_type VARCHAR(50) DEFAULT '',
                            validation_status VARCHAR(20) DEFAULT 'normal',
                            validation_flags TEXT,
                            data_source_id INTEGER,
                            sync_task_id BIGINT,
                            ingestion_method VARCHAR(30) NOT NULL DEFAULT 'legacy',
                            source_trace_id VARCHAR(80) DEFAULT NULL,
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
                            data_value DECIMAL(15,2) DEFAULT 0,
                            source VARCHAR(50) DEFAULT 'ctrip',
                            dimension VARCHAR(100) DEFAULT '',
                            data_type VARCHAR(50) DEFAULT '',
                            validation_status VARCHAR(20) DEFAULT 'normal',
                            validation_flags TEXT,
                            data_source_id INT UNSIGNED DEFAULT NULL,
                            sync_task_id BIGINT UNSIGNED DEFAULT NULL,
                            ingestion_method VARCHAR(30) NOT NULL DEFAULT 'legacy',
                            source_trace_id VARCHAR(80) DEFAULT NULL,
                            raw_data TEXT,
                            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                            update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX idx_hotel_date (hotel_id, data_date),
                            INDEX idx_system_hotel (system_hotel_id, data_date),
                            INDEX idx_online_daily_source_trace (data_source_id, sync_task_id),
                            INDEX idx_online_daily_ingestion (ingestion_method, source, data_type)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                $output->writeln('Created online_daily_data table.');
            } else {
                // 琛ㄥ瓨鍦紝妫€鏌ュ苟娣诲姞鏂板瓧娈?
                $fieldsToAdd = $this->onlineDailyDataFieldsToAdd();

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
                            $output->writeln("Added {$field} to online_daily_data.");
                        }
                    } else {
                        $exists = Db::query("SHOW COLUMNS FROM online_daily_data LIKE '{$field}'");
                        if (empty($exists)) {
                            Db::execute("ALTER TABLE online_daily_data ADD COLUMN {$field} {$definition}");
                            $output->writeln("Added {$field} to online_daily_data.");
                        }
                    }
                }

                // 娣诲姞绱㈠紩
                try {
                    if ($dbType === 'sqlite') {
                        // SQLite 涓嶆敮鎸?CREATE INDEX IF NOT EXISTS锛岄渶瑕佸厛妫€鏌?
                        $indexes = Db::query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='online_daily_data'");
                        $indexNames = array_column($indexes, 'name');
                        if (!in_array('idx_system_hotel', $indexNames)) {
                            Db::execute("CREATE INDEX idx_system_hotel ON online_daily_data(system_hotel_id, data_date)");
                            $output->writeln('Created index idx_system_hotel.');
                        }
                    } else {
                        Db::execute("ALTER TABLE online_daily_data ADD INDEX idx_system_hotel (system_hotel_id, data_date)");
                        $output->writeln('Created index idx_system_hotel.');
                    }
                } catch (\Exception $e) {
                    // 绱㈠紩鍙兘宸插瓨鍦?
                }
            }
        } catch (\Exception $e) {
            $output->writeln('Failed to process online_daily_data: ' . $e->getMessage());
        }
    }

    protected function onlineDailyDataFieldsToAdd(): array
    {
        return [
            'system_hotel_id' => 'INTEGER',
            'data_value' => 'DECIMAL(15,2) DEFAULT 0',
            'source' => "VARCHAR(50) DEFAULT 'ctrip'",
            'dimension' => 'VARCHAR(100) DEFAULT \'\'',
            'data_type' => 'VARCHAR(50) DEFAULT \'\'',
            'validation_status' => "VARCHAR(20) DEFAULT 'normal'",
            'validation_flags' => 'TEXT',
            'data_source_id' => 'INTEGER',
            'sync_task_id' => 'BIGINT',
            'ingestion_method' => "VARCHAR(30) NOT NULL DEFAULT 'legacy'",
            'source_trace_id' => 'VARCHAR(80) DEFAULT NULL',
        ];
    }
}
