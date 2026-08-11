<?php
declare(strict_types=1);

use app\service\OnlineDataAutoFetchStatusStore;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if (!$loader instanceof \Composer\Autoload\ClassLoader) {
        continue;
    }
    $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
}

$statusPath = (string)($argv[1] ?? '');
$lockDirectory = (string)($argv[2] ?? '');
$hotelId = (int)($argv[3] ?? 0);
$platform = strtolower(trim((string)($argv[4] ?? '')));
$attempts = (int)($argv[5] ?? 0);

try {
    if ($statusPath === ''
        || $lockDirectory === ''
        || $hotelId <= 0
        || !in_array($platform, ['ctrip', 'meituan'], true)
        || $attempts <= 0
    ) {
        throw new RuntimeException('Worker arguments are invalid.');
    }

    $store = new OnlineDataAutoFetchStatusStore(
        static function (int $_hotelId) use ($statusPath): array {
            $contents = @file_get_contents($statusPath);
            usleep(2_000);
            if (!is_string($contents) || trim($contents) === '') {
                return [];
            }
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        },
        static function (int $_hotelId, array $status, int $_ttl) use ($statusPath): bool {
            $encoded = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return file_put_contents($statusPath, $encoded) !== false;
        },
        $lockDirectory
    );

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $store->mutate($hotelId, static function (array $status) use ($platform): array {
            $updates = is_array($status['worker_updates'] ?? null) ? $status['worker_updates'] : [];
            $updates[$platform] = (int)($updates[$platform] ?? 0) + 1;
            $status['worker_updates'] = $updates;
            return $status;
        });
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_debug_type($exception) . ': ' . $exception->getMessage());
    exit(1);
}
