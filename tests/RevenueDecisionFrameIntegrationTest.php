<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class RevenueDecisionFrameIntegrationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'revenue_decision_frame_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        Db::execute('DROP TABLE IF EXISTS hotel_operating_questions');
        Db::execute('DROP TABLE IF EXISTS hotels');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'decision frame test',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    public function testSelectedFrameIsSavedExactlyReadBackAndReused(): void
    {
        $service = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#8801',
                'platform' => 'ctrip',
                'data_date' => '2026-08-16',
                'data_type' => 'price',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'metric_values' => ['amount' => 588],
                'metric_units' => ['amount' => 'CNY'],
            ]],
            'fact_count' => 1,
        ]);

        $saved = $service->create(
            10,
            80,
            '今天价格要复核哪些输入？',
            'ctrip',
            '2026-08-16',
            '2026-08-16',
            7,
            'deepseek_v4_pro',
            'price'
        );

        self::assertTrue($saved['created']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame('price', $saved['question']['answer']['decision_frame']['primary_object']);
        self::assertSame('price', $saved['question']['answer']['decision_frame']['requested_object']);

        $readback = $service->read((int)$saved['question']['id'], 10, [80]);
        self::assertSame($saved['question']['content_digest'], $readback['content_digest']);
        self::assertSame($saved['question']['answer']['decision_frame'], $readback['answer']['decision_frame']);

        $replay = $service->create(
            10,
            80,
            '今天价格要复核哪些输入？',
            'ctrip',
            '2026-08-16',
            '2026-08-16',
            7,
            'deepseek_v4_pro',
            'price'
        );
        self::assertFalse($replay['created']);
        self::assertSame($readback['id'], $replay['question']['id']);
        self::assertSame($readback['content_digest'], $replay['question']['content_digest']);
    }

    public function testMissingFactReadbackKeepsFrameBlocked(): void
    {
        $service = new OperatingQuestionService(static fn(): array => [
            'facts' => [],
            'fact_count' => 0,
        ]);
        $saved = $service->create(
            10,
            80,
            '库存与进度现在能否调整？',
            'meituan',
            '2026-08-16',
            '2026-08-16',
            7,
            'deepseek_v4_pro',
            'inventory_progress'
        );

        self::assertSame('blocked_by_missing_facts', $saved['question']['answer_status']);
        self::assertSame('blocked_by_missing_facts', $saved['question']['answer']['decision_frame']['evidence_gate']['status']);
        self::assertFalse($saved['question']['answer']['decision_frame']['evidence_gate']['can_execute']);
    }
}
