<?php
declare(strict_types=1);
// Local queue CLI. JSON inputs describe evidence; they never contain commands.
date_default_timezone_set('Asia/Shanghai');
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require_once $root . '/vendor/topthink/framework/src/helper.php';
foreach (spl_autoload_functions() ?: [] as $autoload) {
    $loader = is_array($autoload) ? ($autoload[0] ?? null) : null;
    if ($loader instanceof \Composer\Autoload\ClassLoader) $loader->setPsr4('app\\', [$root . '/app']);
}
new \think\App($root);
$service = new \app\service\AutonomousMaintenanceService(getenv('SUXIOS_MAINTENANCE_STATE_DIR') ?: null);
$input = static function (string $path): array {
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) throw new \InvalidArgumentException('json_object_required');
    return $value;
};
$print = static fn($value) => print(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
$command = $argv[1] ?? 'status';
try {
    if ($command === 'guard') {
        $owner = $argv[2] ?? '';
        $handle = $service->acquireExecutor($owner);
        $print(['guard' => 'acquired', 'execution_id' => $owner, 'pid' => getmypid(), 'control' => $service->snapshot()['control']]);
        $started = time();
        try {
            while ($service->heartbeat($owner)) {
                if (time() - $started > 21600 && $service->snapshot()['control']['mode'] === 'running') {
                    $service->control('exception_stop', null, null, 'guard_max_duration_reached_save_checkpoint');
                }
                sleep(5);
            }
        } finally { $service->releaseExecutor($owner, $handle); }
        $print(['guard' => 'released', 'execution_id' => $owner]);
        exit(0);
    }
    $value = match ($command) {
        'status' => $service->snapshot(),
        'assert' => $service->assertExecution($argv[2] ?? '', isset($argv[3]) ? (int)$argv[3] : null),
        'enqueue' => $service->enqueue($input($argv[2] ?? '')),
        'claim' => $service->claim($argv[2] ?? '', $argv[3] ?? null),
        'update' => $service->updateTask($argv[2] ?? '', $argv[3] ?? '', (int)($argv[4] ?? -1), $input($argv[5] ?? '')),
        'recover' => $service->recover($argv[2] ?? '', (int)($argv[3] ?? -1), $input($argv[4] ?? '')),
        'recover-executor' => $service->recoverExecutor($input($argv[2] ?? '')),
        'restart-policy' => $service->configureRestartRecovery($argv[2] ?? '', (int)($argv[3] ?? -1), $input($argv[4] ?? '')),
        'recover-restart' => $service->recoverRestart((int)($argv[2] ?? -1)),
        'check' => $service->recordCheck($input($argv[2] ?? '')),
        'scheduler' => $service->scheduler($input($argv[2] ?? '')),
        'candidate' => $service->candidate($argv[2] ?? '', (int)($argv[3] ?? -1), $input($argv[4] ?? '')),
        'next' => $service->next($argv[2] ?? '', (int)($argv[3] ?? -1)),
        'run' => $service->recordRun($argv[2] ?? '', (int)($argv[3] ?? -1), $input($argv[4] ?? '')),
        'release' => $service->releaseRequested($argv[2] ?? '', $input($argv[3] ?? '')),
        'control' => $service->control($argv[2] ?? '', ($argv[3] ?? '-') === '-' ? null : (int)$argv[3],
            ($argv[4] ?? '-') === '-' ? null : $argv[4], $argv[5] ?? ''),
        'probe' => (function () use ($service, $root): array {
            $target = $root . '/public/components/system/autonomous-maintenance-workbench.js';
            $exists = is_file($target);
            $fingerprint = $exists ? (string)hash_file('sha256', $target) : hash('sha256', 'maintenance_workbench_source_missing_v1');
            $check = $service->recordCheck(['fingerprint' => $fingerprint,
                'outcome' => $exists ? 'source_present_not_page_acceptance' : 'reproduced_missing_page',
                'evidence' => $exists ? '维护组件源码存在；实际页面及保存回读需独立验收' : '实际文件检查：维护组件源码不存在，系统配置没有维护队列操作入口']);
            $task = $exists ? null : $service->enqueue([
                'issue_key' => 'maintenance_visible_queue_and_controls', 'title' => '补齐可查看、可控制和可回读的自主维护页面',
                'kind' => 'development', 'priority' => 'P1', 'evidence_fingerprint' => $fingerprint,
                'evidence' => ['probe 实际文件检查失败：自主维护页面组件缺失', '已有 os 调度与历史交接，尚无持久队列/页面闭环'],
                'scope' => ['workspace' => 'HOTEL-clean-20260901', 'source_kind' => 'local_development'],
                'acceptance' => ['队列任务操作与精确持久回读', '暂停恢复及中断恢复防重复', '页面刷新与失败状态', '调度配置和实际检查分层'],
                'suggested_action' => '复用现有系统配置页面和登录请求，完成隔离回归与本地完整触发流程']);
            return ['check' => $check, 'task' => $task];
        })(),
        default => throw new \InvalidArgumentException('maintenance_command_unknown'),
    };
    $print($value);
} catch (\Throwable $e) {
    $code = $e instanceof \DomainException ? 409 : ($e instanceof \InvalidArgumentException ? 422 : 503);
    $print(['code' => $code, 'state' => 'blocked', 'message' => $code === 503
        ? 'maintenance_storage_or_input_unavailable_original_preserved' : $e->getMessage()]);
    exit(1);
}
