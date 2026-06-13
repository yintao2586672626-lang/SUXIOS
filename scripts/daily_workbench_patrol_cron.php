<?php
declare(strict_types=1);

/**
 * OTA 每日经营工作台巡检脚本。
 *
 * 建议由 Windows 任务计划或 Linux cron 调用：
 * php scripts/daily_workbench_patrol_cron.php --target-date=2026-06-13
 *
 * 实际执行统一委托给 ThinkPHP 命令：
 * php think online-data:daily-workbench-patrol
 */

date_default_timezone_set('Asia/Shanghai');

$phpBinary = PHP_BINARY ?: 'php';
$thinkPath = realpath(__DIR__ . '/../think');

if ($thinkPath === false) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] 错误: 未找到 think 入口\n");
    exit(1);
}

$allowedPrefixes = ['--base-url=', '--target-date=', '--limit=', '--timeout='];
$forwardArgs = [];
foreach (array_slice($argv, 1) as $arg) {
    foreach ($allowedPrefixes as $prefix) {
        if (strncmp((string)$arg, $prefix, strlen($prefix)) === 0) {
            $forwardArgs[] = (string)$arg;
            continue 2;
        }
    }

    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] 错误: 不支持的参数 {$arg}\n");
    exit(1);
}

$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($thinkPath) . ' online-data:daily-workbench-patrol';
foreach ($forwardArgs as $arg) {
    $command .= ' ' . escapeshellarg($arg);
}

passthru($command, $exitCode);
exit((int)$exitCode);
