<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

// Keep PHPUnit's framework cache and locks out of a running application's
// runtime directory. A local server may own that directory under another
// account, which must not make isolated tests fail before they reach their
// assertions. Explicit test/CI paths still take precedence.
if (trim((string)getenv('SUXIOS_CACHE_PATH')) === '') {
    $testStateRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'suxios-phpunit-state';
    $testCachePath = $testStateRoot . DIRECTORY_SEPARATOR . 'cache';
    $testLockPath = $testStateRoot . DIRECTORY_SEPARATOR . 'locks';
    @mkdir($testCachePath, 0777, true);
    @mkdir($testLockPath, 0777, true);
    putenv('SUXIOS_CACHE_PATH=' . $testCachePath);
    putenv('SUXIOS_LOCAL_LOCK_PATH=' . $testLockPath);
    $_ENV['SUXIOS_CACHE_PATH'] = $testCachePath;
    $_ENV['SUXIOS_LOCAL_LOCK_PATH'] = $testLockPath;
}

// Release worktrees may reuse the main checkout's vendor directory through a
// junction. Composer then resolves app\ to that checkout as well, so register
// the current worktree first and ensure tests exercise the candidate sources.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $relative;
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);

require_once __DIR__ . '/../vendor/autoload.php';

$thinkHelper = __DIR__ . '/../vendor/topthink/framework/src/helper.php';
if (!function_exists('json') && is_file($thinkHelper)) {
    require_once $thinkHelper;
}
