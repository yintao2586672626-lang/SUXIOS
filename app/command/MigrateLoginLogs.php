<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 登录日志表迁移命令
 * 创建登录日志表和添加用户登录次数字段
 */
class MigrateLoginLogs extends Command
{
    protected function configure()
    {
        $this->setName('migrate:login-logs')
            ->setDescription('创建登录日志表和相关字段');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始创建登录日志表...');
        
        try {
            // 创建登录日志表
            $this->createLoginLogsTable($output);
            
            // 添加用户登录次数字段
            $this->addLoginCountField($output);
            
            $output->writeln('登录日志表迁移完成！');
            
        } catch (\Exception $e) {
            $output->writeln('错误: ' . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
    
    /**
     * 创建登录日志表
     */
    protected function createLoginLogsTable(Output $output)
    {
        // 检查表是否已存在
        $tableExists = Db::query("SHOW TABLES LIKE 'login_logs'");
        if (!empty($tableExists)) {
            $output->writeln('登录日志表已存在，跳过创建');
            return;
        }
        
        Db::execute("CREATE TABLE IF NOT EXISTS `login_logs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED DEFAULT NULL COMMENT '用户ID',
            `username` VARCHAR(50) NOT NULL COMMENT '用户名',
            `action` VARCHAR(20) NOT NULL COMMENT '操作类型: login/logout/refresh',
            `status` VARCHAR(20) NOT NULL COMMENT '状态: success/failed',
            `message` VARCHAR(255) DEFAULT NULL COMMENT '消息或失败原因',
            `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP地址',
            `user_agent` VARCHAR(500) DEFAULT NULL COMMENT '用户代理',
            `client_info` JSON DEFAULT NULL COMMENT '客户端信息(浏览器、操作系统等)',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_username` (`username`),
            INDEX `idx_action` (`action`),
            INDEX `idx_status` (`status`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_user_time` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录日志表'");
        
        $output->writeln('✓ 登录日志表创建成功');
    }
    
    /**
     * 添加用户登录次数字段
     */
    protected function addLoginCountField(Output $output)
    {
        // 检查字段是否已存在
        $columnExists = Db::query("SHOW COLUMNS FROM `users` LIKE 'login_count'");
        if (!empty($columnExists)) {
            $output->writeln('登录次数字段已存在，跳过添加');
            return;
        }
        
        Db::execute("ALTER TABLE `users` 
            ADD COLUMN `login_count` INT UNSIGNED DEFAULT 0 COMMENT '登录次数' 
            AFTER `last_login_ip`");
        
        $output->writeln('✓ 用户登录次数字段添加成功');
    }
}