<?php
declare(strict_types=1);

use app\service\FixedWindowRateLimiter;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if (!$loader instanceof \Composer\Autoload\ClassLoader) {
        continue;
    }
    $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
}

$counterPath = (string)($argv[1] ?? '');
$lockDirectory = (string)($argv[2] ?? '');
$key = (string)($argv[3] ?? '');
$now = (int)($argv[4] ?? 0);
$attempts = (int)($argv[5] ?? 0);

try {
    $limiter = new FixedWindowRateLimiter(
        static function (string $_key) use ($counterPath): ?int {
            $contents = @file_get_contents($counterPath);
            usleep(2_000);
            return $contents === false ? null : (int)trim($contents);
        },
        static function (string $_key, int $value, int $_ttl) use ($counterPath): bool {
            return file_put_contents($counterPath, (string)$value) !== false;
        },
        static fn(): int => $now,
        $lockDirectory
    );

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $result = $limiter->consume($key, 10_000, 60);
        if (!$result['allowed']) {
            throw new RuntimeException('Unexpected worker rate-limit denial.');
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_debug_type($exception) . ': ' . $exception->getMessage());
    exit(1);
}
