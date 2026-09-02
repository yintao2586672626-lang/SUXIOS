<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionReadbackIntegrityTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_question_integrity_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        foreach (['hotel_operating_questions', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    public function testReadRejectsTamperedAnswerJson(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'answer_json' => json_encode(['status' => 'evidence_ready', 'summary' => '篡改后的分析'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedEvidenceReferences(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'fact_refs_json' => json_encode(['online_daily_data#999'], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedMirroredStatus(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'answer_status' => 'answered_by_grounded_ai',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedDataGapMirror(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'data_gaps_json' => json_encode([['code' => 'fabricated_gap']], JSON_UNESCAPED_UNICODE),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    public function testReadRejectsTamperedScopeIdentity(): void
    {
        [$service, $id] = $this->savedQuestion();
        Db::name(OperatingQuestionService::TABLE)->where('id', $id)->update([
            'platform' => 'ctrip',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read($id, 10, [80]);
    }

    /** @return array{OperatingQuestionService,int} */
    private function savedQuestion(): array
    {
        $service = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#102476',
                'data_date' => '2026-08-23',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'history_status' => 'success',
                'validation_status' => 'verified',
                'readback_status' => 'readback_verified',
                'readback_verified' => true,
                'metric_values' => ['list_exposure' => 1422],
                'metric_units' => ['list_exposure' => 'exposure_count'],
            ]],
            'fact_count' => 1,
            'fact_platform_counts' => ['meituan' => 1],
            'fact_platform_dates' => ['meituan' => ['2026-08-23']],
            'memories' => [],
            'diagnoses' => [],
            'knowledge' => [],
            'executions' => [],
        ]);
        $created = $service->create(
            10,
            80,
            '当前选择范围最需要复核什么？',
            'meituan',
            '2026-08-23',
            '2026-08-23',
            7
        );
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame('passed', $created['question']['analysis_quality_receipt']['quality_status']);
        self::assertSame('limited', $created['question']['analysis_quality_receipt']['claim_status']);
        $exact = $service->read((int)$created['question']['id'], 10, [80]);
        self::assertSame(
            $created['question']['analysis_quality_receipt']['receipt_digest'],
            $exact['analysis_quality_receipt']['receipt_digest']
        );
        return [$service, (int)$created['question']['id']];
    }
}
