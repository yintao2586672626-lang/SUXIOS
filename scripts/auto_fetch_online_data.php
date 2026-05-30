<?php
declare(strict_types=1);

/**
 * 自动获取线上数据脚本
 *
 * 保留旧 cron 入口，但实际执行统一委托给 ThinkPHP 命令：
 * php think online-data:auto-fetch
 */

date_default_timezone_set('Asia/Shanghai');

$phpBinary = PHP_BINARY ?: 'php';
$thinkPath = realpath(__DIR__ . '/../think');

if ($thinkPath === false) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] 错误: 未找到 think 入口\n");
    exit(1);
}

$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($thinkPath) . ' online-data:auto-fetch';
passthru($command, $exitCode);
exit((int)$exitCode);
