<?php
declare(strict_types=1);

namespace Tests\Support;

use think\App;

/** Bootstrap framework services without loading project configuration or .env. */
final class IsolatedSqliteAppFixture
{
    public static function create(string $prefix): App
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR;
        if (!mkdir($root, 0777, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create isolated test root.');
        }
        $app = new App($root);
        $app->setRuntimePath($root . 'runtime' . DIRECTORY_SEPARATOR);
        $configure = static function () use ($app, $root): void {
            $app->config->set(['default' => 'file', 'stores' => ['file' => [
                'type' => 'File', 'path' => $root . 'cache' . DIRECTORY_SEPARATOR,
            ]]], 'cache');
            $app->config->set(['default' => 'file', 'close' => false, 'channels' => ['file' => [
                'type' => 'File', 'path' => $root . 'log' . DIRECTORY_SEPARATOR,
            ]]], 'log');
            $app->config->set(['default' => 'isolated_restore', 'connections' => ['isolated_restore' => [
                'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => true,
            ]]], 'database');
        };
        $configure();
        $app->initialize();
        $configure();
        return $app;
    }
}
