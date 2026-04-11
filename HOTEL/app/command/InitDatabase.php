<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Env;

class InitDatabase extends Command
{
    protected function configure()
    {
        $this->setName('db:init')
            ->setDescription('初始化数据库表结构');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始初始化数据库...');
        
        try {
            $dbType = Env::get('DB_TYPE', 'mysql');
            $output->writeln("数据库类型: {$dbType}");
            
            // 根据数据库类型选择SQL语法
            if ($dbType === 'sqlite') {
                $this->initSQLite($output);
            } else {
                $this->initMySQL($output);
            }
            
            // 插入默认数据（MySQL和SQLite通用）
            $this->insertDefaultData($output);

            $output->writeln('数据库初始化完成！');
            
        } catch (\Exception $e) {
            $output->writeln('错误: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
    
    /**
     * MySQL 数据库初始化
     */
    protected function initMySQL(Output $output)
    {
        // 设置字符集
        Db::execute("SET NAMES utf8mb4");
        
        // 创建酒店表
        Db::execute("
            CREATE TABLE IF NOT EXISTS hotels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20) UNIQUE,
                address VARCHAR(255),
                contact_person VARCHAR(50),
                contact_phone VARCHAR(20),
                status TINYINT DEFAULT 1,
                description TEXT,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 酒店表创建成功');

        // 创建角色表（细化权限）
        Db::execute("
            CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(50),
                description TEXT,
                level INT DEFAULT 0,
                permissions TEXT,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 角色表创建成功');

        // 创建用户表
        Db::execute("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                realname VARCHAR(50),
                email VARCHAR(100),
                phone VARCHAR(20),
                role_id INT DEFAULT 3,
                status TINYINT DEFAULT 1,
                hotel_id INT,
                last_login_time DATETIME,
                last_login_ip VARCHAR(50),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 用户表创建成功');

        // 创建用户酒店权限表（核心：绑定酒店和报表权限）
        Db::execute("
            CREATE TABLE IF NOT EXISTS user_hotel_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                hotel_id INT NOT NULL,
                can_view_report TINYINT DEFAULT 0,
                can_fill_daily_report TINYINT DEFAULT 0,
                can_fill_monthly_task TINYINT DEFAULT 0,
                can_edit_report TINYINT DEFAULT 0,
                can_delete_report TINYINT DEFAULT 0,
                can_view_online_data TINYINT DEFAULT 0,
                can_fetch_online_data TINYINT DEFAULT 0,
                can_delete_online_data TINYINT DEFAULT 0,
                is_primary TINYINT DEFAULT 0,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_hotel (user_id, hotel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 用户酒店权限表创建成功');

        // 创建线上数据表
        Db::execute("
            CREATE TABLE IF NOT EXISTS online_daily_data (
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
        $output->writeln('✓ 线上数据表创建成功');

        // 创建日报表
        Db::execute("
            CREATE TABLE IF NOT EXISTS daily_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hotel_id INT NOT NULL,
                report_date DATE NOT NULL,
                occupancy_rate DECIMAL(5,2),
                room_count INT DEFAULT 0,
                guest_count INT DEFAULT 0,
                revenue DECIMAL(12,2) DEFAULT 0,
                expenses DECIMAL(12,2) DEFAULT 0,
                notes TEXT,
                submitter_id INT,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_hotel_date (hotel_id, report_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 日报表创建成功');

        // 创建月任务表
        Db::execute("
            CREATE TABLE IF NOT EXISTS monthly_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hotel_id INT NOT NULL,
                year INT NOT NULL,
                month INT NOT NULL,
                revenue_target DECIMAL(12,2) DEFAULT 0,
                occupancy_target DECIMAL(5,2) DEFAULT 0,
                guest_target INT DEFAULT 0,
                description TEXT,
                submitter_id INT,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_hotel_month (hotel_id, year, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 月任务表创建成功');

        // 创建操作日志表
        Db::execute("
            CREATE TABLE IF NOT EXISTS operation_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                hotel_id INT,
                module VARCHAR(50),
                action VARCHAR(50),
                description TEXT,
                error_info TEXT,
                extra_data TEXT,
                ip VARCHAR(50),
                user_agent VARCHAR(255),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hotel_id (hotel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 操作日志表创建成功');

        // 创建报表配置表
        Db::execute("
            CREATE TABLE IF NOT EXISTS report_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_type VARCHAR(20) NOT NULL,
                field_name VARCHAR(50) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                field_type VARCHAR(20) NOT NULL DEFAULT 'number',
                unit VARCHAR(20),
                options TEXT,
                sort_order INT DEFAULT 0,
                is_required TINYINT DEFAULT 0,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_report_field (report_type, field_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 报表配置表创建成功');

        // 创建系统配置表
        Db::execute("
            CREATE TABLE IF NOT EXISTS system_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                config_key VARCHAR(50) NOT NULL UNIQUE,
                config_value TEXT,
                description VARCHAR(255),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $output->writeln('✓ 系统配置表创建成功');
    }
    
    /**
     * SQLite 数据库初始化
     */
    protected function initSQLite(Output $output)
    {
        // 创建酒店表
        Db::execute("
            CREATE TABLE IF NOT EXISTS hotels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20) UNIQUE,
                address VARCHAR(255),
                contact_person VARCHAR(50),
                contact_phone VARCHAR(20),
                status TINYINT DEFAULT 1,
                description TEXT,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $output->writeln('✓ 酒店表创建成功');

        // 创建角色表（细化权限）
        Db::execute("
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL UNIQUE,
                display_name VARCHAR(50),
                description TEXT,
                level INTEGER DEFAULT 0,
                permissions TEXT,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $output->writeln('✓ 角色表创建成功');

        // 创建用户表
        Db::execute("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                realname VARCHAR(50),
                email VARCHAR(100),
                phone VARCHAR(20),
                role_id INTEGER DEFAULT 3,
                status TINYINT DEFAULT 1,
                hotel_id INTEGER,
                last_login_time DATETIME,
                last_login_ip VARCHAR(50),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $output->writeln('✓ 用户表创建成功');

        // 创建用户酒店权限表（核心：绑定酒店和报表权限）
        Db::execute("
            CREATE TABLE IF NOT EXISTS user_hotel_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                hotel_id INTEGER NOT NULL,
                can_view_report TINYINT DEFAULT 0,
                can_fill_daily_report TINYINT DEFAULT 0,
                can_fill_monthly_task TINYINT DEFAULT 0,
                can_edit_report TINYINT DEFAULT 0,
                can_delete_report TINYINT DEFAULT 0,
                can_view_online_data TINYINT DEFAULT 0,
                can_fetch_online_data TINYINT DEFAULT 0,
                can_delete_online_data TINYINT DEFAULT 0,
                is_primary TINYINT DEFAULT 0,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, hotel_id)
            )
        ");
        $output->writeln('✓ 用户酒店权限表创建成功');

        // 创建线上数据表
        Db::execute("
            CREATE TABLE IF NOT EXISTS online_daily_data (
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
        $output->writeln('✓ 线上数据表创建成功');

        // 创建日报表
        Db::execute("
            CREATE TABLE IF NOT EXISTS daily_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hotel_id INTEGER NOT NULL,
                report_date DATE NOT NULL,
                occupancy_rate DECIMAL(5,2),
                room_count INTEGER DEFAULT 0,
                guest_count INTEGER DEFAULT 0,
                revenue DECIMAL(12,2) DEFAULT 0,
                expenses DECIMAL(12,2) DEFAULT 0,
                notes TEXT,
                submitter_id INTEGER,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(hotel_id, report_date)
            )
        ");
        $output->writeln('✓ 日报表创建成功');

        // 创建月任务表
        Db::execute("
            CREATE TABLE IF NOT EXISTS monthly_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hotel_id INTEGER NOT NULL,
                year INTEGER NOT NULL,
                month INTEGER NOT NULL,
                revenue_target DECIMAL(12,2) DEFAULT 0,
                occupancy_target DECIMAL(5,2) DEFAULT 0,
                guest_target INTEGER DEFAULT 0,
                description TEXT,
                submitter_id INTEGER,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(hotel_id, year, month)
            )
        ");
        $output->writeln('✓ 月任务表创建成功');

        // 创建操作日志表
        Db::execute("
            CREATE TABLE IF NOT EXISTS operation_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                hotel_id INTEGER,
                module VARCHAR(50),
                action VARCHAR(50),
                description TEXT,
                error_info TEXT,
                extra_data TEXT,
                ip VARCHAR(50),
                user_agent VARCHAR(255),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $output->writeln('✓ 操作日志表创建成功');

        // 创建报表配置表
        Db::execute("
            CREATE TABLE IF NOT EXISTS report_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_type VARCHAR(20) NOT NULL,
                field_name VARCHAR(50) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                field_type VARCHAR(20) NOT NULL DEFAULT 'number',
                unit VARCHAR(20),
                options TEXT,
                sort_order INTEGER DEFAULT 0,
                is_required TINYINT DEFAULT 0,
                status TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(report_type, field_name)
            )
        ");
        $output->writeln('✓ 报表配置表创建成功');

        // 创建系统配置表
        Db::execute("
            CREATE TABLE IF NOT EXISTS system_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_key VARCHAR(50) NOT NULL UNIQUE,
                config_value TEXT,
                description VARCHAR(255),
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                update_time DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $output->writeln('✓ 系统配置表创建成功');
    }
    
    /**
     * 插入默认数据
     */
    protected function insertDefaultData(Output $output)
    {
        // 插入默认角色（三级权限体系）
        $roles = [
            ['name' => 'super_admin', 'display_name' => '超级管理员', 'description' => '拥有系统所有权限，可管理所有酒店', 'level' => 1, 'permissions' => json_encode(['all'])],
            ['name' => 'hotel_manager', 'display_name' => '门店管理员', 'description' => '管理指定酒店，可查看、填写、编辑报表', 'level' => 2, 'permissions' => json_encode(['hotel_view', 'report_view', 'report_fill', 'report_edit', 'report_delete'])],
            ['name' => 'hotel_staff', 'display_name' => '店员', 'description' => '只能填写指定酒店的报表', 'level' => 3, 'permissions' => json_encode(['report_view', 'report_fill'])],
        ];
        
        foreach ($roles as $role) {
            $roleExists = Db::table('roles')->where('name', $role['name'])->find();
            if (!$roleExists) {
                Db::table('roles')->insert(array_merge($role, [
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                ]));
            }
        }
        $output->writeln('✓ 默认角色创建成功');

        // 插入默认超级管理员
        $adminExists = Db::table('users')->where('username', 'admin')->find();
        if (!$adminExists) {
            Db::table('users')->insert([
                'username' => 'admin',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'realname' => '超级管理员',
                'role_id' => 1,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            $output->writeln('✓ 默认超级管理员创建成功 (用户名: admin, 密码: admin123)');
        }

        // 插入示例酒店
        $hotels = [
            ['name' => '东方大酒店', 'code' => 'DF001', 'address' => '北京市朝阳区', 'contact_person' => '张经理', 'contact_phone' => '010-12345678'],
            ['name' => '西湖度假村', 'code' => 'XH002', 'address' => '杭州市西湖区', 'contact_person' => '李经理', 'contact_phone' => '0571-87654321'],
            ['name' => '海滨国际酒店', 'code' => 'HB003', 'address' => '上海市浦东新区', 'contact_person' => '王经理', 'contact_phone' => '021-11112222'],
        ];
        
        foreach ($hotels as $hotel) {
            $hotelExists = Db::table('hotels')->where('code', $hotel['code'])->find();
            if (!$hotelExists) {
                Db::table('hotels')->insert(array_merge($hotel, [
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                ]));
            }
        }
        $output->writeln('✓ 示例酒店数据创建成功');

        // 创建示例门店管理员
        $managerExists = Db::table('users')->where('username', 'manager1')->find();
        if (!$managerExists) {
            Db::table('users')->insert([
                'username' => 'manager1',
                'password' => password_hash('manager123', PASSWORD_DEFAULT),
                'realname' => '东方酒店经理',
                'role_id' => 2,
                'hotel_id' => 1,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            // 绑定酒店权限
            Db::table('user_hotel_permissions')->insert([
                'user_id' => 2,
                'hotel_id' => 1,
                'can_view_report' => 1,
                'can_fill_daily_report' => 1,
                'can_fill_monthly_task' => 1,
                'can_edit_report' => 1,
                'can_delete_report' => 1,
                'is_primary' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            $output->writeln('✓ 示例门店管理员创建成功 (用户名: manager1, 密码: manager123)');
        }

        // 创建示例店员
        $staffExists = Db::table('users')->where('username', 'staff1')->find();
        if (!$staffExists) {
            Db::table('users')->insert([
                'username' => 'staff1',
                'password' => password_hash('staff123', PASSWORD_DEFAULT),
                'realname' => '前台员工',
                'role_id' => 3,
                'hotel_id' => 1,
                'status' => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            // 绑定酒店权限（只能填写报表）
            Db::table('user_hotel_permissions')->insert([
                'user_id' => 3,
                'hotel_id' => 1,
                'can_view_report' => 1,
                'can_fill_daily_report' => 1,
                'can_fill_monthly_task' => 1,
                'can_edit_report' => 0,
                'can_delete_report' => 0,
                'is_primary' => 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            $output->writeln('✓ 示例店员创建成功 (用户名: staff1, 密码: staff123)');
        }

        // 插入默认报表配置
        $reportConfigs = [
            // 日报表配置
            ['report_type' => 'daily', 'field_name' => 'occupancy_rate', 'display_name' => '入住率', 'field_type' => 'number', 'unit' => '%', 'sort_order' => 1, 'is_required' => 1],
            ['report_type' => 'daily', 'field_name' => 'room_count', 'display_name' => '房间数', 'field_type' => 'number', 'unit' => '间', 'sort_order' => 2, 'is_required' => 0],
            ['report_type' => 'daily', 'field_name' => 'guest_count', 'display_name' => '客人数', 'field_type' => 'number', 'unit' => '人', 'sort_order' => 3, 'is_required' => 0],
            ['report_type' => 'daily', 'field_name' => 'revenue', 'display_name' => '收入', 'field_type' => 'number', 'unit' => '元', 'sort_order' => 4, 'is_required' => 1],
            ['report_type' => 'daily', 'field_name' => 'expenses', 'display_name' => '支出', 'field_type' => 'number', 'unit' => '元', 'sort_order' => 5, 'is_required' => 0],
            ['report_type' => 'daily', 'field_name' => 'notes', 'display_name' => '备注', 'field_type' => 'textarea', 'unit' => '', 'sort_order' => 6, 'is_required' => 0],
            // 月任务配置
            ['report_type' => 'monthly', 'field_name' => 'revenue_target', 'display_name' => '收入目标', 'field_type' => 'number', 'unit' => '元', 'sort_order' => 1, 'is_required' => 1],
            ['report_type' => 'monthly', 'field_name' => 'occupancy_target', 'display_name' => '入住率目标', 'field_type' => 'number', 'unit' => '%', 'sort_order' => 2, 'is_required' => 0],
            ['report_type' => 'monthly', 'field_name' => 'guest_target', 'display_name' => '客人目标', 'field_type' => 'number', 'unit' => '人', 'sort_order' => 3, 'is_required' => 0],
            ['report_type' => 'monthly', 'field_name' => 'description', 'display_name' => '备注说明', 'field_type' => 'textarea', 'unit' => '', 'sort_order' => 4, 'is_required' => 0],
        ];
        
        foreach ($reportConfigs as $config) {
            $configExists = Db::table('report_configs')
                ->where('report_type', $config['report_type'])
                ->where('field_name', $config['field_name'])
                ->find();
            if (!$configExists) {
                Db::table('report_configs')->insert(array_merge($config, [
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                ]));
            }
        }
        $output->writeln('✓ 默认报表配置创建成功');
    }
}
