<?php
declare(strict_types=1);

namespace Tests;

use app\controller\RevenueForecastWorkbench;
use app\service\RevenueForecastWorkbenchService;
use PHPUnit\Framework\TestCase;
use Tests\fixtures\RevenueForecastReplayFixture as Fixture;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class RevenueForecastWorkbenchControllerTest extends TestCase
{
    private string $root;
    private array $dbConfig;
    private array $cacheConfig;
    private array $logConfig;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/suxi-l05-api-' . bin2hex(random_bytes(5));
        mkdir($this->root);
        $this->dbConfig = Config::get('database', []);
        $this->cacheConfig = Config::get('cache', []);
        $this->logConfig = Config::get('log', []);
        Config::set(['default' => 'file', 'close' => true, 'channels' => ['file' => ['type' => 'File', 'path' => $this->root . '/logs/', 'close' => true]]], 'log');
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $this->root . '/cache/']]], 'cache');
        Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => $this->root . '/fixture.sqlite', 'prefix' => '', 'fields_strict' => false]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 90001, 'tenant_id' => 9001]);
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->dbConfig, 'database');
        Config::set($this->cacheConfig, 'cache');
        Config::set($this->logConfig, 'log');
        $target = str_replace('\\', '/', (string)realpath($this->root));
        $prefix = str_replace('\\', '/', (string)realpath(sys_get_temp_dir())) . '/suxi-l05-api-';
        self::assertStringStartsWith($prefix, $target);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    private function controller(array $input, bool $post = true, string $actor = 'allowed'): RevenueForecastWorkbench
    {
        $app = app();
        $request = (new Request())->setMethod($post ? 'POST' : 'GET');
        $post ? $request->withPost($input) : $request->withGet($input);
        $request->user = $actor === 'anonymous' ? null : new class($actor) {
            public function __construct(private string $actor) {}
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return $this->actor === 'allowed' ? [90001] : [90002]; }
        };
        $app->instance('request', $request);
        return new RevenueForecastWorkbench($app, new RevenueForecastWorkbenchService($this->root . '/plans'));
    }

    public function testApiPreviewSaveAndExactReadbackWithSyntheticSqliteHotelBinding(): void
    {
        $input = Fixture::scope() + Fixture::input();
        $preview = $this->controller($input)->preview();
        self::assertSame(200, $preview->getCode());
        self::assertSame(200, $preview->getData()['code']);
        $saved = $this->controller($input)->save()->getData();
        self::assertSame(200, $saved['code']); self::assertTrue($saved['data']['readback_verified']);
        $id = $saved['data']['id'];
        $read = $this->controller(Fixture::scope(), false)->detail($id)->getData();
        self::assertSame($saved['data']['payload'], $read['data']['payload']);
        self::assertCount(1, $this->controller(Fixture::scope(), false)->history()->getData()['data']['items']);
        // A hotel moved to a different tenant cannot see the prior tenant's documents.
        Db::name('hotels')->where('id', 90001)->update(['tenant_id' => 9002]);
        self::assertSame(422, $this->controller(Fixture::scope(), false)->detail($id)->getCode());
        self::assertSame(422, $this->controller($input)->save()->getCode());
    }

    public function testApiRejectsUnauthenticatedAndUnpermittedBeforeStorage(): void
    {
        $input = Fixture::scope() + Fixture::input();
        self::assertSame(401, $this->controller($input, true, 'anonymous')->save()->getCode());
        self::assertSame(403, $this->controller($input, true, 'denied')->save()->getCode());
        self::assertDirectoryDoesNotExist($this->root . '/plans');
        $input['evidence']['observations'][0]['hotel_id'] = 90002;
        self::assertSame(422, $this->controller($input)->preview()->getCode());
        $input['evidence'] = 'bad';
        self::assertSame(422, $this->controller($input)->preview()->getCode());
    }
}
