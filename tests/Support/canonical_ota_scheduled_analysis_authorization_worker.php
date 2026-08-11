<?php
declare(strict_types=1);

use app\service\CanonicalOtaScheduledAnalysisAuthorizationProvisioningService;

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
$platform = strtolower(trim((string)($argv[3] ?? '')));
$planId = strtolower(trim((string)($argv[4] ?? '')));

try {
    if ($statusPath === ''
        || $lockDirectory === ''
        || !in_array($platform, ['ctrip', 'meituan'], true)
        || $planId === ''
    ) {
        throw new RuntimeException('Worker arguments are invalid.');
    }
    putenv('SUXIOS_LOCAL_LOCK_PATH=' . $lockDirectory);

    $service = new CanonicalOtaScheduledAnalysisAuthorizationProvisioningService(
        static function (int $_hotelId) use ($statusPath): array {
            $contents = @file_get_contents($statusPath);
            usleep(5_000);
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
        static fn(int $_hotelId): int => 80,
        static fn(): string => '2026-08-10T00:00:00+08:00'
    );
    $receipt = $service->execute(80, 80, $platform, $planId);
    if (($receipt['readback_verified'] ?? false) !== true) {
        throw new RuntimeException('Worker authorization readback was not verified.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_debug_type($exception) . ': ' . $exception->getMessage());
    exit(1);
}
