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

    public function testScopeWhitespaceDoesNotHideSavedPlans(): void
    {
        $scope = Fixture::scope();
        foreach (['platform_store_id', 'room_scope'] as $key) $scope[$key] = '  ' . $scope[$key] . "\t";
        self::assertSame(Fixture::scope(), $this->controller($scope, false)->context()->getData()['data']['scope']);
        $saved = $this->controller($scope + Fixture::input())->save()->getData();
        self::assertSame(200, $saved['code']);
        $canonicalScope = Fixture::scope(); ksort($canonicalScope);
        self::assertSame($canonicalScope, $saved['data']['payload']['scope']);
        $canonical = $this->controller(Fixture::scope(), false)->detail($saved['data']['id'])->getData();
        self::assertSame($saved['data']['payload'], $canonical['data']['payload']);
        self::assertCount(1, $this->controller($scope, false)->history()->getData()['data']['items']);
        self::assertCount(1, $this->controller(Fixture::scope(), false)->history()->getData()['data']['items']);
    }

    public function testStorageFailuresIdentifySaveHistoryAndDetailWithoutExposingPaths(): void
    {
        file_put_contents($this->root . '/plans', 'synthetic blocked storage');
        $save = $this->controller(Fixture::scope() + Fixture::input())->save();
        self::assertSame(500, $save->getCode());
        self::assertSame('save', $save->getData()['data']['operation'] ?? null);
        self::assertStringContainsString('方案保存失败', $save->getData()['message']);
        $history = $this->controller(Fixture::scope(), false)->history();
        self::assertSame(500, $history->getCode());
        self::assertSame('history', $history->getData()['data']['operation']);
        self::assertStringContainsString('历史方案读取失败', $history->getData()['message']);
        self::assertStringNotContainsString($this->root, json_encode($history->getData()));
        unlink($this->root . '/plans');
        $saved = $this->controller(Fixture::scope() + Fixture::input())->save()->getData()['data'];
        $file = glob($this->root . '/plans/*/' . $saved['id'] . '.json')[0];
        file_put_contents($file, '{synthetic corruption');
        $detail = $this->controller(Fixture::scope(), false)->detail($saved['id']);
        self::assertSame(500, $detail->getCode());
        self::assertSame('read', $detail->getData()['data']['operation']);
        self::assertStringContainsString('方案回读校验失败', $detail->getData()['message']);
        self::assertFileExists($file);
    }

    public function testHistoryRejectsInvalidPaginationAndReturnsExplicitTotals(): void
    {
        foreach ([0, -1, '2x', true, ['1']] as $page) {
            self::assertSame(422, $this->controller(Fixture::scope() + ['page' => $page], false)->history()->getCode());
        }
        $data = $this->controller(Fixture::scope(), false)->history()->getData()['data'];
        self::assertSame(0, $data['total']); self::assertSame(1, $data['page']);
        self::assertSame(50, $data['page_size']); self::assertSame(1, $data['total_pages']);
    }

    public function testContextValidatesTheSameScopeContractAsReplay(): void
    {
        $valid = $this->controller(Fixture::scope(), false)->context();
        self::assertSame(200, $valid->getCode());
        self::assertSame(Fixture::scope(), $valid->getData()['data']['scope']);
        foreach (['platform' => 'unsupported', 'platform_store_id' => 123, 'room_scope' => '', 'hotel_id' => true] as $key => $value) {
            $scope = Fixture::scope(); $scope[$key] = $value;
            self::assertSame(422, $this->controller($scope, false)->context()->getCode());
        }
    }
}
