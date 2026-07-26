<?php
declare(strict_types=1);

use app\service\LocalStatePathPolicy;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = [];

try {
    // Production keeps one shared /etc/suxios/suxios.env file and symlinks
    // each release's .env to it. Boot the application so this verifier reads
    // the same resolved configuration as PHP-FPM and CLI tasks.
    $app = new App(dirname(__DIR__));
    $app->initialize();
    $policy = $app->config->get('cache.local_state', []);
    if (!is_array($policy) || $policy === []) {
        $policy = LocalStatePathPolicy::resolve();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$root = realpath(dirname(__DIR__));
foreach (['cache_path', 'lock_path'] as $key) {
    $path = (string)$policy[$key];
    if ($path === '') {
        $failures[] = $key . ' is not configured';
        continue;
    }
    if (!is_dir($path)) {
        $failures[] = $key . ' directory does not exist: ' . $path;
        continue;
    }
    if (!is_writable($path)) {
        $failures[] = $key . ' directory is not writable: ' . $path;
    }

    $resolved = realpath($path);
    if ($root !== false && $resolved !== false) {
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, '\\/'))) . '/';
        $normalizedPath = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/'))) . '/';
        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            $failures[] = $key . ' must be outside the application release directory: ' . $path;
        }
    }
}

if (($app->config->get('cache.default') ?? '') !== 'file') {
    $failures[] = 'default cache store must be file in single-instance mode';
}

$configuredCachePath = (string)$app->config->get('cache.stores.file.path', '');
$resolvedCachePath = realpath((string)$policy['cache_path']);
$resolvedConfiguredCachePath = realpath($configuredCachePath);
if ($resolvedCachePath === false
    || $resolvedConfiguredCachePath === false
    || strcasecmp($resolvedCachePath, $resolvedConfiguredCachePath) !== 0) {
    $failures[] = 'active file cache path does not match SUXIOS_CACHE_PATH';
}

if ($failures === []) {
    $probeKey = 'single_instance_release_probe_' . bin2hex(random_bytes(12));
    $probeValue = bin2hex(random_bytes(16));
    try {
        if (cache($probeKey, $probeValue, 30) !== true || cache($probeKey) !== $probeValue) {
            $failures[] = 'active cache driver failed the write/read probe';
        }
    } catch (Throwable $exception) {
        $failures[] = 'active cache driver probe failed: ' . get_debug_type($exception);
    } finally {
        try {
            cache($probeKey, null);
        } catch (Throwable) {
            // The failed probe is already reported without exposing state paths.
        }
    }
}

if ($failures === []) {
    $lockProbeDirectory = LocalStatePathPolicy::scopedLockDirectory('deployment-probe');
    $lockProbePath = $lockProbeDirectory . DIRECTORY_SEPARATOR . 'probe.lock';
    $lockHandle = null;
    try {
        if (!is_dir($lockProbeDirectory)
            && !mkdir($lockProbeDirectory, 0700, true)
            && !is_dir($lockProbeDirectory)) {
            throw new RuntimeException('unable to create the scoped lock directory');
        }
        $lockHandle = fopen($lockProbePath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('unable to acquire the deployment probe lock');
        }
    } catch (Throwable $exception) {
        $failures[] = 'active lock path probe failed: ' . $exception->getMessage();
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
        if (is_file($lockProbePath)) {
            @unlink($lockProbePath);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, '[FAIL] ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, '[PASS] single-instance cache and lock paths are persistent, active, and writable' . PHP_EOL);
