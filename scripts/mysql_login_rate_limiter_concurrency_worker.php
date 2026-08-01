<?php
declare(strict_types=1);

use app\service\LoginRateLimiter;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if (!hash_equals('1', trim((string)getenv('SUXI_CI_MYSQL_VERIFY')))
        || !hash_equals('1', trim((string)getenv('SUXI_E2E_DB_OVERRIDE')))
    ) {
        throw new RuntimeException('Dedicated MySQL verifier gates are required');
    }

    $expectedDatabase = trim((string)getenv('SUXI_E2E_DB_NAME'));
    if (preg_match('/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD', $expectedDatabase) !== 1) {
        throw new RuntimeException('Worker requires a dedicated *_test/*_testing/*_e2e database');
    }
    $databaseHost = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1')));
    if (!in_array($databaseHost, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
        && !hash_equals('1', trim((string)getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB')))
    ) {
        throw new RuntimeException('Worker refused a non-loopback database host');
    }

    (new App(dirname(__DIR__)))->initialize();
    $databaseRow = Db::query('SELECT DATABASE() AS database_name');
    $activeDatabase = trim((string)($databaseRow[0]['database_name'] ?? ''));
    if ($activeDatabase === '' || !hash_equals($expectedDatabase, $activeDatabase)) {
        throw new RuntimeException('Worker database does not match the dedicated E2E database');
    }

    $worker = filter_var(getenv('SUXI_CI_WORKER_INDEX'), FILTER_VALIDATE_INT);
    $ip = trim((string)getenv('SUXI_CI_LOGIN_IP'));
    $username = trim((string)getenv('SUXI_CI_LOGIN_USERNAME'));
    if ($worker === false || $worker <= 0 || $ip === '' || $username === '') {
        throw new RuntimeException('Worker input is invalid');
    }

    if (!hash_equals('1', trim((string)getenv('SUXI_CI_SKIP_BARRIER')))) {
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
    }

    $result = (new LoginRateLimiter())->consumeAttempt($ip, $username);
    fwrite(STDOUT, (string)json_encode([
        'worker' => $worker,
        'allowed' => (bool)($result['allowed'] ?? false),
        'scope' => (string)($result['scope'] ?? ''),
        'reservation_bucket' => $result['reservation_bucket'] ?? null,
        'identity_failures' => (int)($result['identity_failures'] ?? 0),
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
