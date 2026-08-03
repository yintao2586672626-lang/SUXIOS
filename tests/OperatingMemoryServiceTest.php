<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingMemoryService;
use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingMemoryServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_memory_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

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
        Db::execute('DROP TABLE IF EXISTS hotels');
        Db::execute('DROP TABLE IF EXISTS hotel_operating_memories');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([
            ['id' => 20, 'tenant_id' => 10],
            ['id' => 21, 'tenant_id' => 11],
        ]);
        Db::execute(
            'CREATE TABLE hotel_operating_memories ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'memory_key TEXT NOT NULL, memory_layer TEXT NOT NULL, title TEXT NOT NULL, summary TEXT NOT NULL, '
            . 'business_date TEXT NULL, platform TEXT NOT NULL, source_scope TEXT NOT NULL, source_module TEXT NOT NULL, '
            . 'source_record_type TEXT NOT NULL, source_record_id INTEGER NOT NULL, evidence_refs_json TEXT NULL, '
            . 'context_json TEXT NULL, quality_status TEXT NOT NULL, usage_level TEXT NOT NULL, lifecycle_status TEXT NOT NULL, '
            . 'content_digest TEXT NOT NULL, previous_memory_id INTEGER NULL, recorded_by INTEGER NOT NULL, occurred_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL, '
            . 'UNIQUE(tenant_id, hotel_id, memory_key))'
        );
    }

    public function testSavesReadsBackAndVersionsOneReviewedExecutionWithoutDuplicatingFacts(): void
    {
        $source = $this->operationSource();
        $service = new OperatingMemoryService($source);

        $saved = $service->createFromExecutionTask(301, 10, [20], 7);
        self::assertTrue($saved['created']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['external_message']);

        $memory = $saved['memory'];
        self::assertSame(10, $memory['tenant_id']);
        self::assertSame(20, $memory['hotel_id']);
        self::assertSame('execution_review', $memory['memory_layer']);
        self::assertSame('partial', $memory['quality_status']);
        self::assertSame('reference', $memory['usage_level']);
        self::assertSame(301, $memory['source_record_id']);
        self::assertSame('继续观察，等待同口径次日数据', $memory['summary']);
        self::assertCount(4, $memory['evidence_refs']);
        self::assertArrayNotHasKey('current_value', $memory);
        self::assertArrayNotHasKey('target_value', $memory);

        $readback = $service->read((int)$memory['id'], 10, [20]);
        self::assertSame($memory['content_digest'], $readback['content_digest']);
        self::assertSame($memory['evidence_refs'], $readback['evidence_refs']);

        $same = $service->createFromExecutionTask(301, 10, [20], 7);
        self::assertFalse($same['created']);
        self::assertSame($memory['id'], $same['memory']['id']);
        self::assertSame(1, (int)Db::name(OperatingMemoryService::TABLE)->count());

        $source->task['result_summary'] = '继续观察，新增次日来源回读仍未完成';
        $source->task['evidence'][] = ['id' => 402, 'task_id' => 301, 'tenant_id' => 10];
        $versioned = $service->createFromExecutionTask(301, 10, [20], 7);
        self::assertTrue($versioned['created']);
        self::assertNotSame($memory['content_digest'], $versioned['memory']['content_digest']);
        self::assertSame((int)$memory['id'], $versioned['memory']['previous_memory_id']);
        self::assertSame('superseded', (string)Db::name(OperatingMemoryService::TABLE)
            ->where('id', (int)$memory['id'])->value('lifecycle_status'));
        self::assertSame(2, (int)Db::name(OperatingMemoryService::TABLE)->count());
    }

    public function testRejectsUnreviewedTaskAndKeepsTenantReadScope(): void
    {
        $source = $this->operationSource();
        $service = new OperatingMemoryService($source);

        $source->task['result_summary'] = '';
        try {
            $service->createFromExecutionTask(301, 10, [20], 7);
            self::fail('Unreviewed execution must not become operating memory.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('先保存复盘结论', $e->getMessage());
        }
        self::assertSame(0, (int)Db::name(OperatingMemoryService::TABLE)->count());

        $source->task['result_summary'] = '租户10的复盘';
        $service->createFromExecutionTask(301, 10, [20], 7);
        Db::name(OperatingMemoryService::TABLE)->insert([
            'tenant_id' => 11,
            'hotel_id' => 20,
            'memory_key' => 'other-tenant',
            'memory_layer' => 'fact',
            'title' => 'other tenant',
            'summary' => 'must stay hidden',
            'business_date' => '2026-08-01',
            'platform' => 'ctrip',
            'source_scope' => 'ota_channel',
            'source_module' => 'test',
            'source_record_type' => 'test',
            'source_record_id' => 1,
            'evidence_refs_json' => '[]',
            'context_json' => '{}',
            'quality_status' => 'verified',
            'usage_level' => 'decision_support',
            'lifecycle_status' => 'active',
            'content_digest' => str_repeat('a', 64),
            'previous_memory_id' => null,
            'recorded_by' => 1,
            'occurred_at' => '2026-08-01 12:00:00',
            'created_at' => '2026-08-01 12:00:00',
            'updated_at' => '2026-08-01 12:00:00',
            'deleted_at' => null,
        ]);

        $list = $service->list(10, [20], 20);
        self::assertSame('ok', $list['data_status']);
        self::assertCount(1, $list['list']);
        self::assertSame(10, $list['list'][0]['tenant_id']);
    }

    public function testMissingMigrationIsAVisibleFailureState(): void
    {
        $source = $this->operationSource(false);
        $service = new OperatingMemoryService($source);

        $list = $service->list(10, [20], 20);
        self::assertSame('migration_required', $list['data_status']);
        self::assertSame([], $list['list']);
        self::assertSame('operating_memory_table_missing', $list['data_gaps'][0]['code']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('请先执行数据库迁移');
        $service->createFromExecutionTask(301, 10, [20], 7);
    }

    public function testManualGrowthEventIsUnverifiedIdempotentAndStrictlyReadBack(): void
    {
        $service = new OperatingMemoryService($this->operationSource());
        $input = [
            'event_kind' => 'manual_background',
            'title' => '当地会议临时取消',
            'summary' => '确认会议取消后，暂不立即降价。',
            'owner_judgement' => '先观察同口径渠道数据一天。',
            'occurred_at' => '2026-08-03T10:30',
            'business_date' => '2026-08-03',
            'platform' => 'manual',
            'source_scope' => 'manual_background',
            'client_request_id' => 'growth-event-20260803-001',
        ];

        $saved = $service->createManualGrowthEvent(10, [20], 20, $input, 7);
        self::assertTrue($saved['created']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['external_message']);
        self::assertSame('unverified', $saved['memory']['quality_status']);
        self::assertSame('archive_only', $saved['memory']['usage_level']);
        self::assertSame('manual_background', $saved['memory']['event_kind']);
        self::assertSame('2026-08-03 10:30:00', $saved['memory']['occurred_at']);
        self::assertSame('先观察同口径渠道数据一天。', $saved['memory']['context']['owner_judgement']);

        $same = $service->createManualGrowthEvent(10, [20], 20, $input, 7);
        self::assertFalse($same['created']);
        self::assertSame($saved['memory']['id'], $same['memory']['id']);

        try {
            $service->createManualGrowthEvent(10, [20], 20, array_merge($input, [
                'client_request_id' => 'growth-event-20260803-002',
                'platform' => 'ctrip',
                'source_scope' => 'whole_hotel',
            ]), 7);
            self::fail('OTA channel input must not be elevated to whole-hotel scope.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('不能升级', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('无权保存该酒店经营档案');
        $service->createManualGrowthEvent(10, [21], 21, $input, 7);
    }

    public function testOwnerAnnotationAndMilestoneKeepOriginalAndVersionHistory(): void
    {
        $service = new OperatingMemoryService($this->operationSource());
        $base = $service->createManualGrowthEvent(10, [20], 20, [
            'event_kind' => 'decision',
            'title' => '周末价格保持不变',
            'summary' => '决定先观察竞品两天。',
            'occurred_at' => '2026-08-01 09:00:00',
            'platform' => 'ctrip',
            'source_scope' => 'ota_channel',
        ], 7)['memory'];

        $annotation = $service->addOwnerAnnotation((int)$base['id'], 10, [20], [
            'annotation' => '结果变好，但不能证明一定由价格动作造成。',
        ], 7);
        self::assertSame('readback_verified', $annotation['persistence_status']);
        self::assertSame('judgement', $annotation['memory']['memory_layer']);
        self::assertSame((int)$base['id'], $annotation['memory']['previous_memory_id']);
        self::assertTrue($annotation['memory']['is_owner_annotation']);

        $milestone = $service->markMilestone((int)$base['id'], 10, [20], ['note' => '首次保留完整判断链'], 7);
        self::assertSame('milestone', $milestone['memory']['event_kind']);
        self::assertSame(7, $milestone['memory']['context']['marked_by']);
        $milestoneV2 = $service->markMilestone((int)$base['id'], 10, [20], ['note' => '首次完成判断与批注闭环'], 7);
        self::assertSame((int)$milestone['memory']['id'], $milestoneV2['memory']['previous_memory_id']);
        self::assertSame('superseded', (string)Db::name(OperatingMemoryService::TABLE)
            ->where('id', (int)$milestone['memory']['id'])->value('lifecycle_status'));

        $baseReadback = $service->read((int)$base['id'], 10, [20]);
        self::assertSame('周末价格保持不变', $baseReadback['title']);
        self::assertSame('决定先观察竞品两天。', $baseReadback['summary']);
        self::assertSame('active', $baseReadback['lifecycle_status']);
    }

    public function testGrowthTimelineFiltersExactHotelDateLayerAndEventKind(): void
    {
        $service = new OperatingMemoryService($this->operationSource());
        $service->createManualGrowthEvent(10, [20], 20, [
            'event_kind' => 'judgement',
            'title' => '先观察',
            'summary' => '暂不调整活动。',
            'occurred_at' => '2026-08-03 08:00:00',
            'platform' => 'meituan',
            'source_scope' => 'ota_channel',
        ], 7);
        $service->createManualGrowthEvent(10, [20], 20, [
            'event_kind' => 'fact',
            'title' => '线下停房',
            'summary' => '装修停用一间客房。',
            'occurred_at' => '2026-08-02 08:00:00',
            'platform' => 'manual',
            'source_scope' => 'whole_hotel',
        ], 7);

        $timeline = $service->growthTimeline(10, [20], 20, [
            'date_start' => '2026-08-03',
            'date_end' => '2026-08-03',
            'memory_layer' => 'judgement',
            'event_kind' => 'judgement',
        ]);
        self::assertSame('ok', $timeline['data_status']);
        self::assertSame(20, $timeline['hotel_id']);
        self::assertCount(1, $timeline['list']);
        self::assertSame('先观察', $timeline['list'][0]['title']);
        self::assertNull($timeline['overview']['repeated_problem_count']);
        self::assertSame('not_available', $timeline['overview']['repeated_problem_status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('结束日期不能早于开始日期');
        $service->growthTimeline(10, [20], 20, [
            'date_start' => '2026-08-04',
            'date_end' => '2026-08-03',
        ]);
    }

    private function operationSource(bool $tableExists = true): OperationManagementService
    {
        return new class($tableExists) extends OperationManagementService {
            public array $task;
            public array $intent;

            public function __construct(private bool $memoryTableExists)
            {
                parent::__construct();
                $this->intent = [
                    'id' => 201,
                    'tenant_id' => 10,
                    'hotel_id' => 20,
                    'source_module' => 'agent_ota_diagnosis',
                    'source_record_id' => 101,
                    'platform' => 'ctrip',
                    'object_type' => 'price',
                    'action_type' => 'price_review',
                    'date_start' => '2026-07-31',
                    'date_end' => '2026-08-01',
                    'evidence' => ['source_scope' => 'ota_channel'],
                ];
                $this->task = [
                    'id' => 301,
                    'intent_id' => 201,
                    'tenant_id' => 10,
                    'hotel_id' => 20,
                    'status' => 'executed',
                    'result_status' => 'observing',
                    'result_summary' => '继续观察，等待同口径次日数据',
                    'executed_at' => '2026-08-01 09:00:00',
                    'updated_at' => '2026-08-02 10:00:00',
                    'evidence' => [[
                        'id' => 401,
                        'task_id' => 301,
                        'tenant_id' => 10,
                    ]],
                    'evidence_truth' => [
                        'status' => 'partial',
                        'operator_attested' => true,
                        'source_verified' => false,
                    ],
                    'outcome_truth' => [
                        'status' => 'unverified',
                        'outcome_verified' => false,
                        'positive_outcome_verified' => false,
                    ],
                    'truth_context' => [
                        'status' => 'partial',
                        'failure_reason' => 'operator_attested_only',
                    ],
                    'sop_candidate' => ['ready' => false],
                ];
            }

            public function tableExists(string $table): bool
            {
                return $table === OperatingMemoryService::TABLE && $this->memoryTableExists;
            }

            public function readExecutionTask(int $id, array $hotelIds): array
            {
                if ($id !== (int)$this->task['id'] || !in_array((int)$this->task['hotel_id'], $hotelIds, true)) {
                    throw new \RuntimeException('execution task not found');
                }
                return $this->task;
            }

            public function readExecutionIntent(int $id, array $hotelIds): array
            {
                if ($id !== (int)$this->intent['id'] || !in_array((int)$this->intent['hotel_id'], $hotelIds, true)) {
                    throw new \RuntimeException('execution intent not found');
                }
                return $this->intent;
            }
        };
    }
}
