<?php
declare(strict_types=1);

use app\service\ExpansionService;
use app\service\OperationManagementService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if (!hash_equals('1', trim((string)getenv('SUXI_CI_MYSQL_VERIFY')))) {
        throw new RuntimeException('SUXI_CI_MYSQL_VERIFY=1 is required');
    }
    if (!hash_equals('1', trim((string)getenv('SUXI_E2E_DB_OVERRIDE')))) {
        throw new RuntimeException('SUXI_E2E_DB_OVERRIDE=1 is required');
    }

    $expectedDatabase = trim((string)getenv('SUXI_E2E_DB_NAME'));
    if (preg_match('/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD', $expectedDatabase) !== 1) {
        throw new RuntimeException('Worker requires a dedicated *_test/*_testing/*_e2e database');
    }

    $databaseHost = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1')));
    $localHosts = ['127.0.0.1', 'localhost', '::1', '[::1]'];
    if (!in_array($databaseHost, $localHosts, true)
        && !hash_equals('1', trim((string)getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB')))
    ) {
        throw new RuntimeException('Worker refused a non-loopback database host');
    }

    $app = new App();
    $app->initialize();
    $databaseRow = Db::query('SELECT DATABASE() AS database_name');
    $activeDatabase = trim((string)($databaseRow[0]['database_name'] ?? ''));
    if ($activeDatabase === '' || !hash_equals($expectedDatabase, $activeDatabase)) {
        throw new RuntimeException('Worker database does not match the dedicated E2E database');
    }

    $hotelId = filter_var(getenv('SUXI_CI_HOTEL_ID'), FILTER_VALIDATE_INT);
    $tenantId = filter_var(getenv('SUXI_CI_TENANT_ID'), FILTER_VALIDATE_INT);
    $userId = filter_var(getenv('SUXI_CI_USER_ID'), FILTER_VALIDATE_INT);
    $sourceRecordId = filter_var(getenv('SUXI_CI_SOURCE_RECORD_ID'), FILTER_VALIDATE_INT);
    $worker = filter_var(getenv('SUXI_CI_WORKER_INDEX'), FILTER_VALIDATE_INT);
    if ($hotelId === false || $hotelId <= 0
        || $tenantId === false || $tenantId <= 0
        || $userId === false || $userId <= 0
        || $sourceRecordId === false || $sourceRecordId <= 0
        || $worker === false
    ) {
        throw new RuntimeException('Worker input is invalid');
    }

    $barrierDir = trim((string)getenv('SUXI_CI_BARRIER_DIR'));
    if ($barrierDir === '' || !is_dir($barrierDir)) {
        throw new RuntimeException('Worker barrier directory is unavailable');
    }
    $readyPath = $barrierDir . DIRECTORY_SEPARATOR . 'ready_' . $worker;
    if (file_put_contents($readyPath, (string)getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Worker could not signal readiness');
    }
    $goPath = $barrierDir . DIRECTORY_SEPARATOR . 'go';
    $deadline = microtime(true) + 30;
    while (!is_file($goPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker timed out waiting at the concurrency barrier');
        }
        usleep(10000);
    }

    $result = Db::transaction(static function () use ($hotelId, $tenantId, $userId, $sourceRecordId): array {
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->find();
        if (!is_array($hotel)) {
            throw new RuntimeException('Tenant-bound hotel fixture is unavailable');
        }
        $sourceRecord = Db::name('expansion_records')
            ->where('id', $sourceRecordId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->lock(true)
            ->find();
        if (!is_array($sourceRecord)) {
            throw new RuntimeException('Tenant-bound expansion source fixture is unavailable');
        }
        $input = (new ExpansionService())->buildExecutionIntentInput($sourceRecord, $hotelId, [
            'date_start' => '2026-07-16',
            'date_end' => '2026-07-31',
        ]);

        return (new OperationManagementService())->createExecutionIntent(
            [$hotelId],
            $hotelId,
            $input,
            $userId,
            trustedExpansionSource: true
        );
    });
    $intentId = (int)($result['id'] ?? 0);
    if ($intentId <= 0) {
        throw new RuntimeException('Worker did not receive an execution intent id');
    }

    fwrite(STDOUT, (string)json_encode([
        'worker' => $worker,
        'intent_id' => $intentId,
        'database' => $activeDatabase,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, (string)json_encode([
        'error' => $throwable->getMessage(),
        'type' => get_class($throwable),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
