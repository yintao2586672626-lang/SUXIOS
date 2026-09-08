<?php
declare(strict_types=1);
namespace Tests;

use app\controller\Knowledge;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class KnowledgeKeywordQueryTest extends TestCase
{
    private static array $originalConfig;
    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalConfig = Config::get('database');
        $config = self::$originalConfig;
        $connection = 'knowledge_keyword_' . bin2hex(random_bytes(5));
        $config['default'] = $connection;
        $config['connections'][$connection] = ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE knowledge_search_fixture (unit_id INTEGER PRIMARY KEY, hotel_id INTEGER, created_by INTEGER, name TEXT, description TEXT)');
        Db::name('knowledge_search_fixture')->insertAll([
            ['unit_id'=>1,'hotel_id'=>7,'created_by'=>42,'name'=>'收益分析','description'=>'经营日报'],
            ['unit_id'=>2,'hotel_id'=>7,'created_by'=>42,'name'=>'经营办法','description'=>'收益改善'],
            ['unit_id'=>3,'hotel_id'=>8,'created_by'=>42,'name'=>'另一门店','description'=>'收益改善'],
            ['unit_id'=>4,'hotel_id'=>7,'created_by'=>42,'name'=>'客房整理','description'=>'清洁流程'],
            ['unit_id'=>5,'hotel_id'=>7,'created_by'=>43,'name'=>'其他账号','description'=>'收益改善'],
        ]);
    }
    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
    }
    public function testNameAndDescriptionSearchRemainInsideOwnerAndHotelScope(): void
    {
        self::assertSame([1,2], $this->search('收益'));
        self::assertSame([], $this->search('不存在的关键词'));
        self::assertSame([1,2,4], $this->search(''));
    }
    public function testChunkMatchesDoNotBroadenHotelOrOwnerScope(): void
    {
        self::assertSame([4], $this->search('只有知识片段匹配', [3,4,5]));
    }
    private function search(string $keyword, array $chunkIds = []): array
    {
        $controller = (new \ReflectionClass(Knowledge::class))->newInstanceWithoutConstructor();
        $query = Db::name('knowledge_search_fixture')->where('hotel_id',7)->where('created_by',42);
        (new \ReflectionMethod($controller,'applyKnowledgeKeywordFilter'))->invoke($controller,$query,$keyword,$chunkIds);
        return array_map('intval',$query->order('unit_id')->column('unit_id'));
    }
}
