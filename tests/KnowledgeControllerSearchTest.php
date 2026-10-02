<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Knowledge;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Config;
use think\facade\Db;

/** Map schema inspection only; search expressions still run through the real ORM. */
final class KnowledgeSearchSqlite extends \think\db\connector\Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match("/^SHOW TABLES LIKE '([a-z_]+)'$/i", $sql, $match)) {
            return parent::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$match[1]], $master);
        }
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/i', $sql, $match)) {
            return array_map(static fn(array $row): array => ['Field' => $row['name']],
                parent::query('PRAGMA table_info(' . $match[1] . ')', [], $master));
        }
        return parent::query($sql, $bind, $master);
    }
}

final class KnowledgeControllerSearchTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-knowledge-search-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
        Db::connect()->close();
        Config::set(['default' => 'knowledge_search', 'connections' => ['knowledge_search' => [
            'type' => KnowledgeSearchSqlite::class, 'database' => $this->path,
            'builder' => \think\db\builder\Sqlite::class,
            'prefix' => '', 'fields_strict' => false, 'debug' => false,
        ]]], 'database');
        Db::connect(null, true);
        foreach ([
            [1, 20, 7, '黔宿带教参考', '来源为外部源码'],
            [2, 20, 7, '交接检查', '黔宿机制整理'],
            [3, 21, 7, '黔宿另一门店资料', '不可跨店检索'],
            [4, 0, 0, '黔宿全局参考', '公共参考资料'],
            [5, 20, 7, '其他资料', '无关摘要'],
        ] as [$id, $hotelId, $creator, $name, $description]) {
            Db::name('knowledge_units')->insert([
                'unit_id' => $id, 'hotel_id' => $hotelId, 'created_by' => $creator,
                'name' => $name, 'description' => $description, 'source' => 'manual',
                'status' => 'done', 'tags' => '[]',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        @unlink($this->path);
    }

    private function search(string $keyword): array
    {
        $reflection = new \ReflectionClass(Knowledge::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('request')->setValue($controller, new class($keyword) {
            public function __construct(private string $keyword) {}
            public function param($key, $default = null): mixed
            { return $key === 'keyword' ? $this->keyword : $default; }
        });
        $reflection->getProperty('currentUser')->setValue($controller, new class {
            public int $id = 7;
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [20]; }
        });
        return $controller->unitList()->getData();
    }

    public function testKeywordMatchesNameOrDescriptionWithinAuthorizedScope(): void
    {
        $result = $this->search('黔宿');
        self::assertSame(0, $result['code'], $result['msg'] ?? 'Knowledge search failed');
        self::assertSame([4, 2, 1], array_column($result['data']['list'], 'unit_id'));
        self::assertSame(3, $result['data']['pagination']['total']);
    }

    public function testUnknownKeywordReturnsAnActualEmptySuccess(): void
    {
        $result = $this->search('没有此项的关键词');
        self::assertSame(0, $result['code'], $result['msg'] ?? 'Knowledge search failed');
        self::assertSame([], $result['data']['list']);
        self::assertSame(0, $result['data']['pagination']['total']);
    }
}
