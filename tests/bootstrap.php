<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

// Keep PHPUnit's framework cache and locks out of a running application's
// runtime directory. A local server may own that directory under another
// account, which must not make isolated tests fail before they reach their
// assertions. Explicit test/CI paths still take precedence.
if (trim((string)getenv('SUXIOS_CACHE_PATH')) === '') {
    $testProjectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $testProjectIdentity = strtolower(str_replace('\\', '/', $testProjectRoot));
    $testWorktreeHash = substr(hash('sha256', $testProjectIdentity), 0, 12);
    $testRunId = trim((string)getenv('SUXIOS_PHPUNIT_RUN_ID'));
    if ($testRunId === '') {
        $testRunId = getmypid() . '-' . bin2hex(random_bytes(6));
        putenv('SUXIOS_PHPUNIT_RUN_ID=' . $testRunId);
        $_ENV['SUXIOS_PHPUNIT_RUN_ID'] = $testRunId;
    }
    $testStateRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'suxios-phpunit-state'
        . DIRECTORY_SEPARATOR . $testWorktreeHash
        . DIRECTORY_SEPARATOR . $testRunId;
    $testCachePath = $testStateRoot . DIRECTORY_SEPARATOR . 'cache';
    $testLockPath = $testStateRoot . DIRECTORY_SEPARATOR . 'locks';
    @mkdir($testCachePath, 0777, true);
    @mkdir($testLockPath, 0777, true);
    putenv('SUXIOS_CACHE_PATH=' . $testCachePath);
    putenv('SUXIOS_LOCAL_LOCK_PATH=' . $testLockPath);
    $_ENV['SUXIOS_CACHE_PATH'] = $testCachePath;
    $_ENV['SUXIOS_LOCAL_LOCK_PATH'] = $testLockPath;
}

$composerLoaders = array_values(array_filter(
    array_map(
        static fn($autoloadFunction) => is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null,
        spl_autoload_functions() ?: []
    ),
    static fn($loader): bool => $loader instanceof \Composer\Autoload\ClassLoader
));
if ($composerLoaders === []) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// A linked worktree may intentionally reuse the main checkout's vendor
// directory. Composer then resolves the project's PSR-4 paths relative to the
// physical vendor directory and can silently load app classes from the main
// checkout while PHPUnit discovers tests from this worktree. Rebind every
// active Composer loader to the checkout that owns this bootstrap so tests and
// source always come from the same revision.
$projectRoot = dirname(__DIR__);
foreach (spl_autoload_functions() ?: [] as $autoloadFunction) {
    $loader = is_array($autoloadFunction) ? ($autoloadFunction[0] ?? null) : null;
    if (!$loader instanceof \Composer\Autoload\ClassLoader) {
        continue;
    }
    $loader->setPsr4('app\\', [$projectRoot . DIRECTORY_SEPARATOR . 'app']);
    $loader->setPsr4('Tests\\', [$projectRoot . DIRECTORY_SEPARATOR . 'tests']);
}

$thinkHelper = __DIR__ . '/../vendor/topthink/framework/src/helper.php';
if (!function_exists('json') && is_file($thinkHelper)) {
    require_once $thinkHelper;
}

// ThinkPHP otherwise derives its default root from the physical framework
// vendor path. With a shared worktree vendor directory that would also redirect
// runtime_path() and root_path() writes into the main checkout.
new \think\App($projectRoot);
