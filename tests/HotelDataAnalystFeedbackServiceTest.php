<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelDataAnalystFeedbackService;
use app\service\OperatingQuestionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelDataAnalystFeedbackServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private OperatingQuestionService $questionService;
    private HotelDataAnalystFeedbackService $service;
    private array $question = [];

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hotel_data_analyst_feedback_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite', 'database' => self::$sqlitePath, 'prefix' => '', 'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try { Db::connect('sqlite')->close(); } catch (\Throwable) {}
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        foreach (['hotel_data_analyst_feedbacks', 'hotel_operating_questions', 'ai_evaluation_cases', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1),(81,20,'Hotel 81',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
        Db::execute(
            'CREATE TABLE hotel_data_analyst_feedbacks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, contract_version TEXT, tenant_id INTEGER, hotel_id INTEGER, question_id INTEGER, '
            . 'source_scope_json TEXT, source_scope_digest TEXT, source_content_digest TEXT, quality_receipt_contract_version TEXT, '
            . 'quality_receipt_digest TEXT, feedback_kind TEXT, correction_json TEXT, correction_digest TEXT, usage_policy TEXT, '
            . 'evaluation_projection_json TEXT, idempotency_key TEXT, input_digest TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,created_by,idempotency_key))'
        );
        Db::execute('CREATE TABLE ai_evaluation_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, case_key TEXT)');
        $this->questionService = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#102476',
                'data_date' => '2026-08-23',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'quality_status' => 'verified',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'readback_verified' => true,
                'metric_values' => ['list_exposure' => 1422],
                'metric_units' => ['list_exposure' => 'people'],
            ]],
            'fact_count' => 1,
            'fact_platform_counts' => ['meituan' => 1],
            'fact_platform_dates' => ['meituan' => ['2026-08-23']],
            'memories' => [], 'diagnoses' => [], 'knowledge' => [], 'executions' => [],
        ]);
        $created = $this->questionService->create(
            10, 80, '美团曝光人数是多少？', 'meituan', '2026-08-23', '2026-08-23', 7
        );
        $this->question = $created['question'];
        $this->service = new HotelDataAnalystFeedbackService($this->questionService);
    }

    public function testUsefulFeedbackIsAppendOnlyAndDoesNotCreateFormalEvaluationCase(): void
    {
        $before = $this->questionRow();
        $saved = $this->service->save(10, [80], (int)$this->question['id'], 7, $this->input('useful', '', 'feedback-useful-0001'));
        $after = $this->questionRow();

        self::assertTrue($saved['created']);
        self::assertFalse($saved['replayed']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame('useful', $saved['feedback_kind']);
        self::assertSame([], $saved['correction']);
        self::assertSame('not_applicable', $saved['evaluation_projection']['replay_status']);
        self::assertFalse($saved['formal_evaluation_case_created']);
        self::assertFalse($saved['model_training_triggered']);
        self::assertFalse($saved['external_action_authorized']);
        self::assertSame(0, (int)Db::name('ai_evaluation_cases')->count());
        foreach (['answer_json', 'answer_summary', 'answer_status', 'content_digest', 'updated_at'] as $field) {
            self::assertSame($before[$field], $after[$field], 'feedback must not mutate original ' . $field);
        }
        $exact = $this->service->read((int)$saved['id'], 10, [80], (int)$this->question['id'], 7);
        self::assertSame($saved['content_digest'], $exact['content_digest']);
        $list = $this->service->listMine(10, [80], (int)$this->question['id'], 7);
        self::assertSame(1, $list['summary']['useful']);
        self::assertSame($saved['id'], $list['latest']['id']);
    }

    public function testReadbackFailureRollsBackAppendOnlyFeedbackRow(): void
    {
        $service = new HotelDataAnalystFeedbackService(
            $this->questionService,
            null,
            static function (): array {
                throw new RuntimeException('feedback_readback_injected_failure');
            }
        );

        try {
            $service->save(
                10,
                [80],
                (int)$this->question['id'],
                7,
                $this->input('useful', '', 'feedback-readback-failure-0001')
            );
            self::fail('readback failure must fail the save');
        } catch (RuntimeException $error) {
            self::assertSame('feedback_readback_injected_failure', $error->getMessage());
        }

        self::assertSame(0, (int)Db::name(HotelDataAnalystFeedbackService::TABLE)->count());
    }

    public function testCorrectionCreatesDetachedReplayCandidateAndOnlyRetriesTheSameSubmissionIdempotently(): void
    {
        $input = $this->input(
            'needs_correction',
            '结论必须明确这是美团渠道曝光人数，不是全酒店曝光量。',
            'feedback-correction-0001'
        );
        $first = $this->service->save(10, [80], (int)$this->question['id'], 7, $input);
        $second = $this->service->save(10, [80], (int)$this->question['id'], 7, $input);
        $sameContentNewKey = $this->service->save(10, [80], (int)$this->question['id'], 7, [
            ...$input,
            'idempotency_key' => 'feedback-correction-0002',
        ]);

        self::assertSame('needs_correction', $first['feedback_kind']);
        self::assertSame('ready_for_dry_run', $first['evaluation_projection']['replay_status']);
        self::assertSame('active', $first['evaluation_projection']['case']['status']);
        self::assertFalse($first['evaluation_projection']['formal_evaluation_case_created']);
        self::assertSame($first['id'], $second['id']);
        self::assertFalse($second['created']);
        self::assertTrue($second['replayed']);
        self::assertNotSame($first['id'], $sameContentNewKey['id']);
        self::assertTrue($sameContentNewKey['created']);
        self::assertFalse($sameContentNewKey['replayed']);
        self::assertSame(2, (int)Db::name(HotelDataAnalystFeedbackService::TABLE)->count());
        $list = $this->service->listMine(10, [80], (int)$this->question['id'], 7);
        self::assertSame($sameContentNewKey['id'], $list['latest']['id']);
        self::assertSame(0, (int)Db::name('ai_evaluation_cases')->count());
    }

    public function testListLimitDoesNotTruncateSummaryCounts(): void
    {
        for ($index = 1; $index <= 55; $index++) {
            $kind = $index <= 51 ? 'useful' : 'needs_correction';
            $this->service->save(
                10,
                [80],
                (int)$this->question['id'],
                7,
                $this->input(
                    $kind,
                    $kind === 'needs_correction' ? '需要纠正统计口径。' : '',
                    sprintf('feedback-summary-%04d', $index)
                )
            );
        }

        $result = $this->service->listMine(10, [80], (int)$this->question['id'], 7, 50);

        self::assertCount(50, $result['list']);
        self::assertSame(55, $result['summary']['total']);
        self::assertSame(51, $result['summary']['useful']);
        self::assertSame(4, $result['summary']['needs_correction']);
    }

    public function testSameIdempotencyKeyWithDifferentFeedbackIsRejected(): void
    {
        $this->service->save(10, [80], (int)$this->question['id'], 7, $this->input('useful', '', 'feedback-conflict-0001'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('feedback_idempotency_key_conflict');
        $this->service->save(10, [80], (int)$this->question['id'], 7, $this->input(
            'needs_correction',
            '需要纠正渠道范围。',
            'feedback-conflict-0001'
        ));
    }

    public function testStaleAnalysisDigestAndCrossHotelScopeAreRejectedWithoutWrites(): void
    {
        try {
            $this->service->save(10, [80], (int)$this->question['id'], 7, [
                ...$this->input('useful', '', 'feedback-stale-0001'),
                'source_content_digest' => str_repeat('f', 64),
            ]);
            self::fail('stale digest must fail');
        } catch (RuntimeException $e) {
            self::assertSame('analysis_snapshot_drift', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }
        try {
            $this->service->save(20, [81], (int)$this->question['id'], 8, $this->input('useful', '', 'feedback-cross-0001'));
            self::fail('cross-hotel feedback must fail');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not found', strtolower($e->getMessage()));
        }
        self::assertSame(0, (int)Db::name(HotelDataAnalystFeedbackService::TABLE)->count());
    }

    public function testInvalidKindsEmptyCorrectionsAndSensitiveTextAreRejected(): void
    {
        foreach ([
            $this->input('helpful', '', 'feedback-invalid-0001'),
            $this->input('needs_correction', '   ', 'feedback-invalid-0002'),
            $this->input('useful', '不应携带纠正', 'feedback-invalid-0003'),
            $this->input('needs_correction', 'Authorization: Bearer secret-token-value', 'feedback-invalid-0004'),
        ] as $input) {
            try {
                $this->service->save(10, [80], (int)$this->question['id'], 7, $input);
                self::fail('invalid feedback must fail');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, (int)Db::name(HotelDataAnalystFeedbackService::TABLE)->count());
    }

    public function testTamperedFeedbackPayloadFailsExactReadback(): void
    {
        $saved = $this->service->save(10, [80], (int)$this->question['id'], 7, $this->input(
            'needs_correction', '需要纠正日期口径。', 'feedback-tamper-0001'
        ));
        Db::name(HotelDataAnalystFeedbackService::TABLE)->where('id', (int)$saved['id'])->update([
            'correction_json' => json_encode(['summary' => '被篡改'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->expectException(RuntimeException::class);
        $this->service->read((int)$saved['id'], 10, [80], (int)$this->question['id'], 7);
    }

    public function testMissingTableReturnsExplicitMigrationStateForReadOnlyList(): void
    {
        Db::execute('DROP TABLE hotel_data_analyst_feedbacks');
        $result = $this->service->listMine(10, [80], (int)$this->question['id'], 7);
        self::assertSame('migration_required', $result['data_status']);
        self::assertSame([], $result['list']);
    }

    /** @return array<string,mixed> */
    private function input(string $kind, string $correction, string $key): array
    {
        return [
            'feedback_kind' => $kind,
            'correction_text' => $correction,
            'issue_codes' => $kind === 'needs_correction' ? ['scope_overreach'] : [],
            'idempotency_key' => $key,
            'source_content_digest' => (string)$this->question['content_digest'],
            'quality_receipt_digest' => (string)$this->question['analysis_quality_receipt']['receipt_digest'],
        ];
    }

    /** @return array<string,mixed> */
    private function questionRow(): array
    {
        return (array)Db::name(OperatingQuestionService::TABLE)
            ->where('id', (int)$this->question['id'])
            ->find();
    }
}
