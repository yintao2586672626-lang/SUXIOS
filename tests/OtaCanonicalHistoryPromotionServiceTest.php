<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCanonicalHistoryPromotionService;
use app\service\CanonicalOtaDailyNaturalAcceptanceService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaCanonicalHistoryPromotionServiceTest extends TestCase
{
    private const REQUIRED_METRICS = [
        'detail_exposure',
        'flow_rate',
        'list_exposure',
        'order_filling_num',
        'order_submit_num',
    ];

    private const MEITUAN_REQUIRED_METRICS = [
        'detail_exposure',
        'flow_rate',
        'list_exposure',
    ];

    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$connection = 'ota_canonical_promotion_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        @unlink(self::$sqlitePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove canonical promotion SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('platform_data_sync_tasks')->delete(true);
        Db::name('platform_data_sources')->delete(true);
        Db::name('ota_profile_bindings')->delete(true);
        Db::name('hotel_collection_plan_run_sources')->delete(true);
        Db::name('hotel_collection_plan_runs')->delete(true);
        Db::name('hotels')->delete(true);
    }

    public function testPromotesOnlyExactAuthoritativeRowAndReadbacksGeneratedHistory(): void
    {
        [$collection, $verifier] = $this->seedFixture();

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(1, $result['promoted_count']);
        self::assertSame(1, $result['verified_count']);
        self::assertSame([501], $result['row_ids']);
        self::assertTrue($result['readback_verified']);
        self::assertFalse($result['idempotent']);
        self::assertSame([
            ['id' => 501, 'validation_status' => 'verified', 'history_status' => 'success'],
            ['id' => 502, 'validation_status' => 'partial', 'history_status' => 'partial'],
        ], Db::name('online_daily_data')
            ->field('id,validation_status,history_status')
            ->order('id', 'asc')
            ->select()
            ->toArray());

        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true);
        self::assertIsArray($storedStats);
        $promotion = $storedStats['canonical_history_promotion'] ?? null;
        self::assertIsArray($promotion);
        self::assertSame(80, $promotion['tenant_id']);
        self::assertSame(80, $promotion['system_hotel_id']);
        self::assertSame(25, $promotion['data_source_id']);
        self::assertSame(3001, $promotion['sync_task_id']);
        self::assertSame([501], $promotion['row_ids']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $promotion['authoritative_fact_digest']);
        self::assertSame([501], array_keys($promotion['authoritative_row_fact_digests']));
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $promotion['authoritative_row_fact_digests'][501]
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $promotion['content_digest']);
        self::assertFalse($promotion['sensitive_values_exposed']);
    }

    public function testRejectsUndeclaredExtraRowInExactDbScopeBeforeAnyWrite(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('online_daily_data')->insert($this->dailyRow(503, '', 'normal'));

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame([501, 502], $collection['source_tasks'][0]['row_ids']);
        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_collection_rows_identity_mismatch', $result['reason']);
        self::assertSame([
            ['id' => 501, 'validation_status' => 'normal', 'history_status' => 'partial'],
            ['id' => 502, 'validation_status' => 'partial', 'history_status' => 'partial'],
            ['id' => 503, 'validation_status' => 'normal', 'history_status' => 'partial'],
        ], Db::name('online_daily_data')
            ->field('id,validation_status,history_status')
            ->order('id', 'asc')
            ->select()
            ->toArray());
        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([501, 502], $storedStats['run_readback']['row_ids']);
        self::assertArrayNotHasKey('canonical_history_promotion', $storedStats);
    }

    public function testPromotesExactMeituanHistoricalScopeWithoutAcceptingCtripFacts(): void
    {
        [$collection, $verifier] = $this->seedMeituanHistoricalFixture();

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(80, $result['tenant_id']);
        self::assertSame(80, $result['system_hotel_id']);
        self::assertSame('meituan', $result['platform']);
        self::assertSame('2026-08-08', $result['target_date']);
        self::assertSame(68, $result['data_source_id']);
        self::assertSame(6800, $result['sync_task_id']);
        self::assertSame([6801], $result['row_ids']);
        self::assertSame(1, $result['promoted_count']);
        self::assertTrue($result['readback_verified']);
        self::assertSame(1, $result['snapshot_time_backfilled_count']);

        $meituanRow = Db::name('online_daily_data')->where('id', 6801)->find();
        self::assertIsArray($meituanRow);
        self::assertSame(80, (int)$meituanRow['system_hotel_id']);
        self::assertSame(68, (int)$meituanRow['data_source_id']);
        self::assertSame('meituan', $meituanRow['source']);
        self::assertSame('meituan', $meituanRow['platform']);
        self::assertSame('2026-08-08', $meituanRow['data_date']);
        self::assertSame('historical_daily', $meituanRow['data_period']);
        self::assertSame(1, (int)$meituanRow['readback_verified']);
        self::assertSame('verified', $meituanRow['validation_status']);
        self::assertSame('success', $meituanRow['history_status']);
        self::assertSame('2026-08-09 05:09:55', $meituanRow['snapshot_time']);
        self::assertSame(1200.0, (float)$meituanRow['list_exposure']);
        self::assertSame(300.0, (float)$meituanRow['detail_exposure']);
        self::assertSame(25.0, (float)$meituanRow['flow_rate']);

        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 6800)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(true, $storedStats['run_readback']['readback_verified']);
        self::assertSame(68, $storedStats['run_readback']['data_source_id']);
        self::assertSame(80, $storedStats['run_readback']['system_hotel_id']);
        self::assertSame('meituan', $storedStats['run_readback']['platform']);
        self::assertSame('historical_daily', $storedStats['run_readback']['data_period']);
        self::assertSame(self::MEITUAN_REQUIRED_METRICS, $storedStats['run_readback']['required_traffic_metric_keys']);
        self::assertSame(self::MEITUAN_REQUIRED_METRICS, $storedStats['run_readback']['complete_traffic_metric_keys']);
        self::assertSame([], $storedStats['run_readback']['missing_traffic_metric_keys']);

        $promotion = $storedStats['canonical_history_promotion'];
        self::assertSame(80, $promotion['tenant_id']);
        self::assertSame(80, $promotion['system_hotel_id']);
        self::assertSame(68, $promotion['data_source_id']);
        self::assertSame('meituan', $promotion['platform']);
        self::assertSame('2026-08-08', $promotion['target_date']);
        self::assertSame('historical_daily', $promotion['data_period']);
        self::assertSame([6801], $promotion['row_ids']);
        self::assertSame(1, $promotion['nonzero_required_metric_rows']);
        self::assertSame(0, $promotion['explicit_zero_confirmed_rows']);
        self::assertSame('ready', $promotion['observed_traffic_metric_provenance_status']);
        self::assertSame(0, $promotion['synthetic_normalization_provenance_missing_rows']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $promotion['authoritative_fact_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $promotion['content_digest']);

        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        self::assertSame('partial', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
        self::assertSame(0, Db::name('online_daily_data')
            ->where('source', 'ctrip')
            ->where('validation_status', 'verified')
            ->count());
        self::assertNotContains(501, $promotion['row_ids']);
    }

    public function testHistoricalPromotionCannotBorrowOrderCoreFromAnOlderTask(): void
    {
        [$collection, $verifier] = $this->seedMeituanHistoricalFixture(false);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_platform_core_contract_db_incomplete', $result['reason']);
        self::assertSame(
            'normal',
            Db::name('online_daily_data')->where('id', 6801)->value('validation_status')
        );
        self::assertSame(6799, (int)Db::name('online_daily_data')
            ->where('id', 6791)
            ->value('sync_task_id'));
    }

    public function testPromotionRejectsCoreStatusTamperWithTheOldAnchor(): void
    {
        [$collection, $verifier] = $this->seedMeituanHistoricalFixture();
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'blocked';
        $collection['collection_anchor_hash'] = $this->collectionAnchor($collection['source_tasks']);
        $verifier['collection_anchor_hash'] = $collection['collection_anchor_hash'];
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'ready';

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_collection_anchor_mismatch', $result['reason']);
        self::assertSame(
            'normal',
            Db::name('online_daily_data')->where('id', 6801)->value('validation_status')
        );
    }

    public function testNaturalAcceptanceDefaultDbLoaderRequiresPersistedCoreColumns(): void
    {
        [$collection] = $this->seedMeituanHistoricalFixture();
        $runId = '99999999-9999-4999-8999-999999999999';
        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 6800)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $stats['dispatcher_run_id'] = $runId;
        $stats['saved_count'] = 2;
        $stats['run_readback']['dispatcher_run_id'] = $runId;
        $stats['run_readback']['readback_count'] = 2;
        Db::name('platform_data_sync_tasks')->where('id', 6800)->update([
            'trigger_type' => 'daily_profile_reuse',
            'started_at' => '2026-08-09 08:30:10',
            'stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $task = $collection['source_tasks'][0];
        $task['dispatcher_run_id'] = $runId;
        $task['trigger_type'] = 'daily_profile_reuse';

        $method = new \ReflectionMethod(CanonicalOtaDailyNaturalAcceptanceService::class, 'loadTaskFact');
        $service = new CanonicalOtaDailyNaturalAcceptanceService();
        $fact = $method->invoke($service, 80, 80, '2026-08-08', $task);
        self::assertSame('ready', $fact['historical_core_contract_status']);
        self::assertSame([], $fact['missing_core_metric_keys']);
        self::assertFalse($fact['producer_evidence_verified']);

        $orderRaw = json_decode((string)Db::name('online_daily_data')
            ->where('id', 6891)
            ->value('raw_data'), true, 512, JSON_THROW_ON_ERROR);
        $orderRaw['field_facts'] = [[
            'metric_key' => 'order_amount',
            'status' => 'captured',
            'stored_value_present' => true,
        ]];
        Db::name('online_daily_data')->where('id', 6891)->update([
            'amount' => null,
            'raw_data' => json_encode($orderRaw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $olderOrder = $this->meituanHistoricalOrderRow();
        $olderOrder['id'] = 6791;
        $olderOrder['sync_task_id'] = 6799;
        $olderOrder['source_trace_id'] = 'meituan-orders-trace-6791';
        Db::name('online_daily_data')->insert($olderOrder);

        $blocked = $method->invoke($service, 80, 80, '2026-08-08', $task);
        self::assertSame('blocked', $blocked['historical_core_contract_status']);
        self::assertContains('order_amount', $blocked['missing_core_metric_keys']);
    }

    public function testMeituanDomFallbackRemainsReferenceOnlyWhenExactXhrRowCoexists(): void
    {
        [$collection, $verifier] = $this->seedMeituanHistoricalFixture();
        $domRow = $this->meituanHistoricalRow();
        $domRow['id'] = 6802;
        $domRow['source_trace_id'] = 'meituan-dom-trace-6802';
        $raw = json_decode((string)$domRow['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['source_trace_id'] = $domRow['source_trace_id'];
        $raw['row']['_capture_source'] = 'dom:traffic:flow_funnel';
        $raw['capture_evidence']['source_trace_id'] = $domRow['source_trace_id'];
        $raw['capture_evidence']['capture_source'] = 'dom:traffic:flow_funnel';
        foreach ($raw['field_facts'] as &$fact) {
            $fact['capture_evidence']['source_trace_id'] = $domRow['source_trace_id'];
            $fact['capture_evidence']['capture_source'] = 'dom:traffic:flow_funnel';
        }
        unset($fact);
        $domRow['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('online_daily_data')->insert($domRow);

        $collectionRowIds = [6801, 6802, 6891];
        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 6800)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $stats['run_readback']['row_ids'] = $collectionRowIds;
        Db::name('platform_data_sync_tasks')->where('id', 6800)->update([
            'stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $collection['source_tasks'][0]['row_ids'] = $collectionRowIds;
        $collection['collection_anchor_hash'] = $this->collectionAnchor(
            $collection['source_tasks']
        );
        $verifier['collection_anchor_hash'] = $collection['collection_anchor_hash'];

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame([6801], $result['row_ids']);
        self::assertSame(1, $result['promoted_count']);
        self::assertSame('verified', Db::name('online_daily_data')->where('id', 6801)->value('validation_status'));
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 6802)->value('validation_status'));
        self::assertSame('partial', Db::name('online_daily_data')->where('id', 6802)->value('history_status'));
    }

    public function testEquivalentMeituanRowsPublishDeterministicOperationRowSelection(): void
    {
        [$collection, $verifier] = $this->seedEquivalentMeituanMultiRowFixture(false);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame([6801, 6802], $result['row_ids']);
        self::assertSame('ready', $result['operation_row_selection_status']);
        self::assertSame(6801, $result['selected_operation_row_id']);
        self::assertSame([6801, 6802], $result['operation_row_candidate_ids']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $result['operation_row_selection_digest']
        );

        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 6800)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $promotion = $stats['canonical_history_promotion'];
        self::assertSame('ota_operation_row_selection.v1', $promotion['operation_row_selection_version']);
        self::assertSame('ready', $promotion['operation_row_selection_status']);
        self::assertSame(6801, $promotion['selected_operation_row_id']);
        self::assertSame([6801, 6802], array_keys($promotion['operation_row_metric_digests']));
        self::assertSame(
            1,
            count(array_unique(array_values($promotion['operation_row_metric_digests'])))
        );
    }

    public function testDifferentMeituanRowsRemainPromotedButOperationSelectionFailsClosed(): void
    {
        [$collection, $verifier] = $this->seedEquivalentMeituanMultiRowFixture(true);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'meituan',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame([6801, 6802], $result['row_ids']);
        self::assertSame('ambiguous', $result['operation_row_selection_status']);
        self::assertSame(0, $result['selected_operation_row_id']);
        self::assertSame([6801, 6802], $result['operation_row_candidate_ids']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $result['operation_row_selection_digest']
        );
    }

    public function testExcludesCatalogProjectionAndPromotesOnlyStrictDirectRowWithPerRowDigests(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('online_daily_data')->where('id', 502)->update([
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_exposure',
            'validation_status' => 'normal',
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(1, $result['promoted_count']);
        self::assertSame([501], $result['row_ids']);
        self::assertSame('verified', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 502)->value('validation_status'));
        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $promotion = $stats['canonical_history_promotion'];
        self::assertSame([501], $promotion['row_ids']);
        self::assertSame([501], array_keys($promotion['authoritative_row_fact_digests']));
        self::assertSame(
            [501],
            array_keys($promotion['authoritative_row_platform_hotel_identity_digests'])
        );
        foreach ($promotion['authoritative_row_fact_digests'] as $digest) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $digest);
        }
        self::assertSame(0, $promotion['nonzero_required_metric_rows']);
        self::assertSame(1, $promotion['explicit_zero_confirmed_rows']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $promotion['content_digest']);
    }

    public function testRejectsCatalogOnlyVerifierScopeEvenWhenCatalogFactsAreInvalid(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $raw = json_decode((string)Db::name('online_daily_data')
            ->where('id', 502)
            ->value('raw_data'), true, 512, JSON_THROW_ON_ERROR);
        $raw['field_facts'] = array_values(array_filter(
            $raw['field_facts'],
            static fn(array $fact): bool => ($fact['metric_key'] ?? '') !== 'flow_rate'
        ));
        Db::name('online_daily_data')->where('id', 502)->update([
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+detail_exposure',
            'validation_status' => 'normal',
            'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        Db::name('online_daily_data')->where('id', 501)->update(['compare_type' => 'competitor']);
        $verifier['platform_storage_scopes']['ctrip']['sample_row_ids'] = [502];

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_row_count_mismatch', $result['reason']);
        self::assertSame(0, Db::name('online_daily_data')->where('validation_status', 'verified')->count());
    }

    public function testPreflightRevalidatesExactScopeWithoutWritingRowsOrReceipt(): void
    {
        [$collection, $verifier] = $this->seedFixture();

        $result = (new OtaCanonicalHistoryPromotionService())->preflight(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['execute']);
        self::assertTrue($result['preflight_verified']);
        self::assertSame(1, $result['would_promote_count']);
        self::assertSame([501], $result['row_ids']);
        self::assertSame(0, $result['nonzero_required_metric_rows']);
        self::assertSame(1, $result['explicit_zero_confirmed_rows']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true);
        self::assertArrayNotHasKey('canonical_history_promotion', $storedStats);
    }

    public function testRejectsSyntheticZeroWhenObservedMetricMarkerIsMissingOneRequiredMetric(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $raw = json_decode((string)Db::name('online_daily_data')
            ->where('id', 501)
            ->value('raw_data'), true, 512, JSON_THROW_ON_ERROR);
        $raw['row']['_observed_traffic_metric_keys'] = array_values(array_diff(
            self::REQUIRED_METRICS,
            ['flow_rate']
        ));
        Db::name('online_daily_data')->where('id', 501)->update([
            'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->preflight(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_synthetic_normalization_provenance_missing', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('canonical_history_promotion', $stats);
    }

    public function testRejectsObservedMetricMarkerOutsideRawDataRow(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $raw = json_decode((string)Db::name('online_daily_data')
            ->where('id', 501)
            ->value('raw_data'), true, 512, JSON_THROW_ON_ERROR);
        $raw['_observed_traffic_metric_keys'] = $raw['row']['_observed_traffic_metric_keys'];
        unset($raw['row']['_observed_traffic_metric_keys']);
        Db::name('online_daily_data')->where('id', 501)->update([
            'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->preflight(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_synthetic_normalization_provenance_missing', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testSecondPromotionIsStrictlyIdempotent(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $service = new OtaCanonicalHistoryPromotionService();

        self::assertSame('verified', $service->promote($collection, $verifier, 'ctrip', 80, 80)['status']);
        $second = $service->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('verified', $second['status']);
        self::assertSame(0, $second['promoted_count']);
        self::assertTrue($second['idempotent']);
        self::assertSame('success', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
    }

    public function testIdempotentReplayRejectsLegacySingletonReceiptWithoutOperationSelector(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $service = new OtaCanonicalHistoryPromotionService();
        self::assertSame('verified', $service->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        )['status']);

        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $legacyReceipt = $stats['canonical_history_promotion'];
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $selectorField) {
            unset($legacyReceipt[$selectorField]);
        }
        unset($legacyReceipt['content_digest']);
        ksort($legacyReceipt, SORT_STRING);
        $legacyReceipt['content_digest'] = hash('sha256', json_encode(
            $legacyReceipt,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $stats['canonical_history_promotion'] = $legacyReceipt;
        Db::name('platform_data_sync_tasks')->where('id', 3001)->update([
            'stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $result = $service->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('verified_row_without_matching_promotion_receipt', $result['reason']);
        self::assertSame('verified', Db::name('online_daily_data')
            ->where('id', 501)
            ->value('validation_status'));
        self::assertSame('success', Db::name('online_daily_data')
            ->where('id', 501)
            ->value('history_status'));
    }

    public function testHistoricalPromotionBackfillsOnlyRawProvenCaptureTime(): void
    {
        [$collection, $verifier] = $this->seedHistoricalFixture('2026-08-08T21:09:55.123456Z');
        $service = new OtaCanonicalHistoryPromotionService();

        $preflight = $service->preflight($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('ready', $preflight['status']);
        self::assertSame(1, $preflight['would_promote_count']);
        self::assertSame(1, $preflight['snapshot_time_backfill_count']);
        self::assertNull(Db::name('online_daily_data')->where('id', 501)->value('snapshot_time'));
        $preflightStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true);
        self::assertArrayNotHasKey('canonical_history_promotion', $preflightStats);

        $promoted = $service->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('verified', $promoted['status']);
        self::assertSame(1, $promoted['promoted_count']);
        self::assertSame(1, $promoted['snapshot_time_backfilled_count']);
        self::assertSame('2026-08-09 05:09:55', Db::name('online_daily_data')
            ->where('id', 501)
            ->value('snapshot_time'));
        self::assertSame('success', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
        self::assertNull(Db::name('online_daily_data')->where('id', 502)->value('snapshot_time'));

        $idempotent = $service->preflight($collection, $verifier, 'ctrip', 80, 80);
        self::assertSame('ready', $idempotent['status']);
        self::assertTrue($idempotent['idempotent']);
        self::assertSame(0, $idempotent['would_promote_count']);
        self::assertSame(0, $idempotent['snapshot_time_backfill_count']);
    }

    public function testHistoricalPromotionRejectsMissingRawCaptureTimeWithoutWrites(): void
    {
        [$collection, $verifier] = $this->seedHistoricalFixture(null);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_capture_time_missing', $result['reason']);
        self::assertNull(Db::name('online_daily_data')->where('id', 501)->value('snapshot_time'));
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true);
        self::assertArrayNotHasKey('canonical_history_promotion', $storedStats);
    }

    public function testHistoricalPromotionRejectsAutoRolloverCaptureTime(): void
    {
        [$collection, $verifier] = $this->seedHistoricalFixture('2026-08-09 05:60:00');

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_capture_time_invalid', $result['reason']);
        self::assertNull(Db::name('online_daily_data')->where('id', 501)->value('snapshot_time'));
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testHistoricalPromotionRejectsFutureCaptureTime(): void
    {
        $futureCaptureTime = (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Asia/Shanghai')
        ))->modify('+1 day')->format('Y-m-d H:i:s');
        [$collection, $verifier] = $this->seedHistoricalFixture($futureCaptureTime);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_capture_time_invalid', $result['reason']);
        self::assertNull(Db::name('online_daily_data')->where('id', 501)->value('snapshot_time'));
    }

    public function testRealtimePromotionStillRejectsMissingStoredSnapshotTime(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('online_daily_data')->where('id', 501)->update(['snapshot_time' => null]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_capture_time_missing', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testRejectsTamperedCollectionAnchorWithoutWrites(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $collection['collection_anchor_hash'] = str_repeat('0', 64);
        $verifier['collection_anchor_hash'] = str_repeat('0', 64);

        $result = (new OtaCanonicalHistoryPromotionService())->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_collection_anchor_mismatch', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        self::assertSame('partial', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
    }

    public function testRejectsFactDriftBetweenVerifierAndPromotion(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('online_daily_data')->where('id', 501)->update(['list_exposure' => 1]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_metric_fact_invalid:list_exposure', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        self::assertNull(json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true)['canonical_history_promotion'] ?? null);
    }

    public function testRejectsAdditionalAuthoritativeRowNotCountedByStrictReceipt(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('online_daily_data')->where('id', 502)->update([
            'dimension' => '',
            'validation_status' => 'normal',
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_row_count_mismatch', $result['reason']);
        self::assertSame(0, Db::name('online_daily_data')->where('validation_status', 'verified')->count());
    }

    public function testExternalVerifierMaySettleAnAnchoredStaleLocalP0Diagnosis(): void
    {
        [$collection, $verifier] = $this->seedFixture('blocked');

        $result = (new OtaCanonicalHistoryPromotionService())->promote($collection, $verifier, 'ctrip', 80, 80);

        self::assertSame('verified', $result['status']);
        self::assertSame('verified', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testRejectsProfileHotelRebindingAfterVerifierReceipt(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('platform_data_sources')->where('id', 25)->update([
            'config_json' => json_encode([
                'profile_id' => 'ctrip-profile-80',
                'hotel_id' => 'different-ctrip-hotel',
                'capture_sections' => ['traffic'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_platform_hotel_identifier_mismatch', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testRejectsVerifierTenantDifferentFromTrustedSchedulerScope(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $verifier['platform_storage_scopes']['ctrip']['tenant_id'] = 81;

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_storage_scope_mismatch', $result['reason']);
        self::assertSame(0, Db::name('online_daily_data')->where('validation_status', 'verified')->count());
    }

    public function testRejectsVerifierNonzeroCountContradictingLockedExplicitZeroRow(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        $verifier['platform_storage_scopes']['ctrip']['nonzero_required_metric_rows'] = 1;
        $verifier['platform_storage_scopes']['ctrip']['explicit_zero_confirmed_rows'] = 0;

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_authoritative_metric_value_count_mismatch', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
    }

    public function testRejectsActiveProfileBindingMovedToAnotherHotel(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('ota_profile_bindings')->where('id', 1)->update([
            'tenant_id' => 81,
            'system_hotel_id' => 81,
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->promote(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_profile_binding_unverified', $result['reason']);
        self::assertSame('partial', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
    }

    public function testRejectsSameOtaHotelIdentifierAcrossDifferentProfilesAndHotelScopes(): void
    {
        [$collection, $verifier] = $this->seedFixture();
        Db::name('hotels')->insert([
            'id' => 81,
            'tenant_id' => 81,
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 26,
            'tenant_id' => 81,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'status' => 'success',
            'enabled' => 1,
            'ingestion_method' => 'browser_profile',
            'config_json' => json_encode([
                'profile_id' => 'ctrip-profile-81',
                'hotel_id' => 'ctrip-hotel-80',
                'capture_sections' => ['traffic'],
            ], JSON_THROW_ON_ERROR),
        ]);
        Db::name('ota_profile_bindings')->insert([
            'id' => 2,
            'tenant_id' => 81,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', 'ctrip-profile-81'),
            'binding_status' => 'active',
        ]);

        $result = (new OtaCanonicalHistoryPromotionService())->preflight(
            $collection,
            $verifier,
            'ctrip',
            80,
            80
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('promotion_platform_identifier_scope_conflict', $result['reason']);
        self::assertSame('normal', Db::name('online_daily_data')->where('id', 501)->value('validation_status'));
        self::assertSame('partial', Db::name('online_daily_data')->where('id', 501)->value('history_status'));
        $storedStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true);
        self::assertArrayNotHasKey('canonical_history_promotion', $storedStats);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function seedFixture(string $sourceTaskP0Status = 'ready'): array
    {
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 80,
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 25,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'status' => 'success',
            'enabled' => 1,
            'ingestion_method' => 'browser_profile',
            'config_json' => json_encode([
                'profile_id' => 'ctrip-profile-80',
                'hotel_id' => 'ctrip-hotel-80',
                'capture_sections' => ['traffic'],
            ], JSON_THROW_ON_ERROR),
        ]);
        Db::name('ota_profile_bindings')->insert([
            'id' => 1,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', 'ctrip-profile-80'),
            'binding_status' => 'active',
        ]);
        $rowIds = [501, 502];
        $runReadback = [
            'readback_verified' => true,
            'sync_task_id' => 3001,
            'data_source_id' => 25,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'row_ids' => $rowIds,
            'p0_status' => 'ready',
            'field_fact_status' => 'ready',
            'required_traffic_metric_keys' => self::REQUIRED_METRICS,
            'complete_traffic_metric_keys' => self::REQUIRED_METRICS,
            'missing_traffic_metric_keys' => [],
        ];
        Db::name('platform_data_sync_tasks')->insert([
            'id' => 3001,
            'tenant_id' => 80,
            'data_source_id' => 25,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'status' => 'success',
            'stats_json' => json_encode(['run_readback' => $runReadback], JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ]);
        Db::name('online_daily_data')->insertAll([
            $this->dailyRow(501, '', 'normal'),
            $this->dailyRow(
                502,
                'catalog:traffic_report:hotel_bi_mixed_business:list_exposure+detail_exposure',
                'partial'
            ),
        ]);

        $sourceTask = [
            'data_source_id' => 25,
            'sync_task_id' => 3001,
            'platform' => 'ctrip',
            'collection_status' => 'success',
            'p0_status' => $sourceTaskP0Status,
            'historical_core_contract_status' => 'not_required',
            'row_ids' => $rowIds,
        ];
        $anchor = $this->collectionAnchor([$sourceTask]);
        $collection = [
            'schema_version' => 3,
            'hotel_id' => 80,
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'source_tasks' => [$sourceTask],
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchor,
        ];
        $verifier = [
            'schema_version' => 2,
            'verification_source' => 'external_p0_verifier',
            'status' => 'passed',
            'exit_code' => 0,
            'authority_ready' => true,
            'target_date' => '2026-08-09',
            'hotel_id' => 80,
            'required_platforms' => ['ctrip'],
            'verified_platforms' => ['ctrip'],
            'collection_anchor_hash' => $anchor,
            'platform_storage_scopes' => [
                'ctrip' => [
                    'tenant_id' => 80,
                    'system_hotel_id' => 80,
                    'platform' => 'ctrip',
                    'target_date' => '2026-08-09',
                    'data_source_id' => 25,
                    'sync_task_id' => 3001,
                    'authoritative_traffic_row_count' => 1,
                    'sample_row_ids' => [501],
                    'required_metric_keys' => self::REQUIRED_METRICS,
                    'complete_metric_keys' => self::REQUIRED_METRICS,
                    'missing_metric_keys' => [],
                    'nonzero_required_metric_rows' => 0,
                    'explicit_zero_confirmed_rows' => 1,
                    'observed_traffic_metric_provenance_status' => 'ready',
                    'synthetic_normalization_provenance_missing_rows' => 0,
                    'readback_status' => 'ready',
                ],
            ],
            'verifier_report_hash' => str_repeat('b', 64),
            'sensitive_values_exposed' => false,
        ];
        return [$collection, $verifier];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function seedMeituanHistoricalFixture(bool $includeOrderCore = true): array
    {
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 80,
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 68,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'data_type' => 'business',
            'status' => 'success',
            'enabled' => 1,
            'ingestion_method' => 'browser_profile',
            'config_json' => json_encode([
                'store_id' => 'meituan-store-80',
                'poi_id' => 'meituan-poi-80',
                'capture_sections' => ['traffic'],
            ], JSON_THROW_ON_ERROR),
        ]);
        Db::name('ota_profile_bindings')->insert([
            'id' => 68,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'profile_key_hash' => hash('sha256', 'meituan-store-80'),
            'binding_status' => 'active',
        ]);

        $rowIds = $includeOrderCore ? [6801, 6891] : [6801];
        $runReadback = [
            'readback_verified' => true,
            'sync_task_id' => 6800,
            'data_source_id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'row_ids' => $rowIds,
            'p0_status' => 'ready',
            'field_fact_status' => 'ready',
            'required_traffic_metric_keys' => self::MEITUAN_REQUIRED_METRICS,
            'complete_traffic_metric_keys' => self::MEITUAN_REQUIRED_METRICS,
            'missing_traffic_metric_keys' => [],
        ];
        Db::name('platform_data_sync_tasks')->insert([
            'id' => 6800,
            'tenant_id' => 80,
            'data_source_id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'status' => 'success',
            'stats_json' => json_encode(['run_readback' => $runReadback], JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ]);
        Db::name('online_daily_data')->insert($this->meituanHistoricalRow());
        if ($includeOrderCore) {
            Db::name('online_daily_data')->insert($this->meituanHistoricalOrderRow());
        } else {
            $olderOrder = $this->meituanHistoricalOrderRow();
            $olderOrder['id'] = 6791;
            $olderOrder['sync_task_id'] = 6799;
            $olderOrder['source_trace_id'] = 'meituan-orders-trace-6791';
            Db::name('online_daily_data')->insert($olderOrder);
        }

        // Same hotel/date/period Ctrip facts deliberately coexist. They must
        // remain outside the source-68 Meituan promotion receipt and writes.
        $ctripRow = $this->dailyRow(501, '', 'normal');
        $ctripRaw = json_decode((string)$ctripRow['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $ctripRaw['row']['date'] = '2026-08-08';
        $ctripRaw['captured_at'] = '2026-08-08T21:09:55.123456Z';
        $ctripRow['data_date'] = '2026-08-08';
        $ctripRow['data_period'] = 'historical_daily';
        $ctripRow['snapshot_time'] = '2026-08-09 05:09:55';
        $ctripRow['raw_data'] = json_encode($ctripRaw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('online_daily_data')->insert($ctripRow);

        $sourceTask = [
            'data_source_id' => 68,
            'sync_task_id' => 6800,
            'platform' => 'meituan',
            'collection_status' => 'success',
            'p0_status' => 'ready',
            'historical_core_contract_status' => 'ready',
            'row_ids' => $rowIds,
        ];
        $anchor = $this->collectionAnchor([$sourceTask]);
        $collection = [
            'schema_version' => 3,
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'required_platforms' => ['meituan'],
            'source_tasks' => [$sourceTask],
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchor,
        ];
        $verifier = [
            'schema_version' => 2,
            'verification_source' => 'external_p0_verifier',
            'status' => 'passed',
            'exit_code' => 0,
            'authority_ready' => true,
            'target_date' => '2026-08-08',
            'hotel_id' => 80,
            'required_platforms' => ['meituan'],
            'verified_platforms' => ['meituan'],
            'collection_anchor_hash' => $anchor,
            'platform_storage_scopes' => [
                'meituan' => [
                    'tenant_id' => 80,
                    'system_hotel_id' => 80,
                    'platform' => 'meituan',
                    'target_date' => '2026-08-08',
                    'data_source_id' => 68,
                    'sync_task_id' => 6800,
                    'authoritative_traffic_row_count' => 1,
                    'sample_row_ids' => [6801],
                    'required_metric_keys' => self::MEITUAN_REQUIRED_METRICS,
                    'complete_metric_keys' => self::MEITUAN_REQUIRED_METRICS,
                    'missing_metric_keys' => [],
                    'nonzero_required_metric_rows' => 1,
                    'explicit_zero_confirmed_rows' => 0,
                    'observed_traffic_metric_provenance_status' => 'ready',
                    'synthetic_normalization_provenance_missing_rows' => 0,
                    'readback_status' => 'ready',
                ],
            ],
            'verifier_report_hash' => str_repeat('c', 64),
            'sensitive_values_exposed' => false,
        ];
        return [$collection, $verifier];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function seedEquivalentMeituanMultiRowFixture(bool $differentMetrics): array
    {
        [$collection, $verifier] = $this->seedMeituanHistoricalFixture();
        $row = $this->meituanHistoricalRow();
        $row['id'] = 6802;
        $row['source_trace_id'] = 'meituan-trace-6802';
        $raw = json_decode((string)$row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $raw['source_trace_id'] = $row['source_trace_id'];
        $raw['source_url_hash'] = hash('sha256', 'meituan-source-url-6802');
        $raw['capture_evidence']['source_trace_id'] = $row['source_trace_id'];
        $raw['capture_evidence']['source_url_hash'] = $raw['source_url_hash'];
        foreach ($raw['field_facts'] as &$fact) {
            $fact['capture_evidence']['source_trace_id'] = $row['source_trace_id'];
            $fact['capture_evidence']['source_url_hash'] = $raw['source_url_hash'];
        }
        unset($fact);
        if ($differentMetrics) {
            $row['detail_exposure'] = 301;
            $raw['row']['detailExposure'] = 301;
        }
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('online_daily_data')->insert($row);

        $rowIds = [6801, 6802, 6891];
        $stats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 6800)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $stats['run_readback']['row_ids'] = $rowIds;
        Db::name('platform_data_sync_tasks')->where('id', 6800)->update([
            'stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $collection['source_tasks'][0]['row_ids'] = $rowIds;
        $collection['collection_anchor_hash'] = $this->collectionAnchor(
            $collection['source_tasks']
        );
        $verifier['collection_anchor_hash'] = $collection['collection_anchor_hash'];
        $scope =& $verifier['platform_storage_scopes']['meituan'];
        $scope['authoritative_traffic_row_count'] = 2;
        $scope['sample_row_ids'] = [6801, 6802];
        $scope['nonzero_required_metric_rows'] = 2;
        return [$collection, $verifier];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function seedHistoricalFixture(?string $capturedAt): array
    {
        [$collection, $verifier] = $this->seedFixture();
        foreach ([501, 502] as $rowId) {
            $raw = json_decode((string)Db::name('online_daily_data')
                ->where('id', $rowId)
                ->value('raw_data'), true, 512, JSON_THROW_ON_ERROR);
            $raw['row']['date'] = '2026-08-08';
            if ($capturedAt === null) {
                unset($raw['captured_at'], $raw['capturedAt']);
            } else {
                $raw['captured_at'] = $capturedAt;
            }
            Db::name('online_daily_data')->where('id', $rowId)->update([
                'data_date' => '2026-08-08',
                'data_period' => 'historical_daily',
                'snapshot_time' => null,
                'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }
        Db::name('online_daily_data')->insert($this->ctripHistoricalBusinessRow());

        $taskStats = json_decode((string)Db::name('platform_data_sync_tasks')
            ->where('id', 3001)
            ->value('stats_json'), true, 512, JSON_THROW_ON_ERROR);
        $taskStats['run_readback']['target_date'] = '2026-08-08';
        $taskStats['run_readback']['data_period'] = 'historical_daily';
        $taskStats['run_readback']['row_ids'] = [501, 502, 503];
        Db::name('platform_data_sync_tasks')->where('id', 3001)->update([
            'stats_json' => json_encode($taskStats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $collection['target_date'] = '2026-08-08';
        $collection['data_period'] = 'historical_daily';
        $collection['source_tasks'][0]['historical_core_contract_status'] = 'ready';
        $collection['source_tasks'][0]['row_ids'] = [501, 502, 503];
        $collection['collection_anchor_hash'] = $this->collectionAnchor(
            $collection['source_tasks']
        );
        $verifier['target_date'] = '2026-08-08';
        $verifier['platform_storage_scopes']['ctrip']['target_date'] = '2026-08-08';
        $verifier['collection_anchor_hash'] = $collection['collection_anchor_hash'];
        return [$collection, $verifier];
    }

    /** @param array<int,array<string,mixed>> $sourceTasks */
    private function collectionAnchor(array $sourceTasks): string
    {
        return OtaCollectionAnchorService::hash($sourceTasks);
    }

    /** @return array<string,mixed> */
    private function dailyRow(int $id, string $dimension, string $validationStatus): array
    {
        $traceId = 'trace-' . $id;
        $urlHash = hash('sha256', 'source-url-' . $id);
        $sourceKeys = [
            'list_exposure' => 'listExposure',
            'detail_exposure' => 'detailExposure',
            'flow_rate' => 'flowRate',
            'order_filling_num' => 'orderFillingNum',
            'order_submit_num' => 'orderSubmitNum',
        ];
        $sourceRow = [
            'date' => '2026-08-09',
            'hotelId' => 'ctrip-hotel-80',
            '_capture_source' => 'xhr:traffic',
            '_observed_traffic_metric_keys' => array_keys($sourceKeys),
        ];
        $facts = [];
        foreach ($sourceKeys as $metricKey => $sourceKey) {
            $sourceRow[$sourceKey] = 0;
            $facts[] = [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'source_key' => $sourceKey,
                'source_path' => 'data.traffic.' . $sourceKey,
                'storage_field' => 'online_daily_data.' . $metricKey,
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic',
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ];
        }
        $raw = [
            'row' => $sourceRow,
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'date_source' => 'request.payload.statDate',
            'field_facts' => $facts,
        ];
        return [
            'id' => $id,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'data_source_id' => 25,
            'sync_task_id' => 3001,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'data_type' => 'traffic',
            'dimension' => $dimension,
            'compare_type' => '',
            'validation_status' => $validationStatus,
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $traceId,
            'snapshot_time' => '2026-08-09 05:10:05',
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanHistoricalRow(): array
    {
        $traceId = 'meituan-trace-6801';
        $urlHash = hash('sha256', 'meituan-source-url-6801');
        $sourceKeys = [
            'list_exposure' => 'listExposure',
            'detail_exposure' => 'detailExposure',
            'flow_rate' => 'flowRate',
        ];
        $values = [
            'list_exposure' => 1200,
            'detail_exposure' => 300,
            'flow_rate' => 25,
        ];
        $sourceRow = [
            'date' => '2026-08-08',
            'poiId' => 'meituan-poi-80',
            '_capture_source' => 'xhr:traffic',
            '_observed_traffic_metric_keys' => array_keys($sourceKeys),
        ];
        $facts = [];
        foreach ($sourceKeys as $metricKey => $sourceKey) {
            $sourceRow[$sourceKey] = $values[$metricKey];
            $facts[] = [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'source_key' => $sourceKey,
                'source_path' => 'data.traffic.' . $sourceKey,
                'storage_field' => 'online_daily_data.' . $metricKey,
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic',
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ];
        }
        $raw = [
            'row' => $sourceRow,
            'captured_at' => '2026-08-08T21:09:55.123456Z',
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'date_source' => 'request.payload.statDate',
            'field_facts' => $facts,
        ];
        return [
            'id' => 6801,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-poi-80',
            'data_source_id' => 68,
            'sync_task_id' => 6800,
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'data_type' => 'traffic',
            'dimension' => '',
            'compare_type' => '',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $traceId,
            'snapshot_time' => null,
            'list_exposure' => $values['list_exposure'],
            'detail_exposure' => $values['detail_exposure'],
            'flow_rate' => $values['flow_rate'],
            'order_filling_num' => null,
            'order_submit_num' => null,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanHistoricalOrderRow(): array
    {
        return [
            'id' => 6891,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-poi-80',
            'data_source_id' => 68,
            'sync_task_id' => 6800,
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'data_type' => 'orders',
            'dimension' => '',
            'compare_type' => '',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'meituan-orders-trace-6891',
            'snapshot_time' => '2026-08-09 05:09:54',
            'amount' => 1280.0,
            'quantity' => 8,
            'book_order_num' => 5,
            'list_exposure' => null,
            'detail_exposure' => null,
            'flow_rate' => null,
            'order_filling_num' => null,
            'order_submit_num' => null,
            'raw_data' => json_encode([
                'row' => ['date' => '2026-08-08', 'poiId' => 'meituan-poi-80'],
                'source_trace_id' => 'meituan-orders-trace-6891',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ];
    }

    /** @return array<string,mixed> */
    private function ctripHistoricalBusinessRow(): array
    {
        return [
            'id' => 503,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'data_source_id' => 25,
            'sync_task_id' => 3001,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => '',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'ctrip-business-trace-503',
            'snapshot_time' => '2026-08-09 05:10:04',
            'amount' => 980.0,
            'quantity' => 6,
            'book_order_num' => 4,
            'list_exposure' => null,
            'detail_exposure' => null,
            'flow_rate' => null,
            'order_filling_num' => null,
            'order_submit_num' => null,
            'raw_data' => json_encode([
                'row' => ['date' => '2026-08-08', 'hotelId' => 'ctrip-hotel-80'],
                'source_trace_id' => 'ctrip-business-trace-503',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-08-09 05:10:06',
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
            CREATE TABLE hotels (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE platform_data_sources (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                system_hotel_id INTEGER NOT NULL,
                platform TEXT NOT NULL,
                data_type TEXT NOT NULL,
                status TEXT NOT NULL,
                enabled INTEGER NOT NULL,
                ingestion_method TEXT NOT NULL,
                config_json TEXT NULL
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE ota_profile_bindings (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                system_hotel_id INTEGER NOT NULL,
                platform TEXT NOT NULL,
                profile_key_hash TEXT NOT NULL,
                binding_status TEXT NOT NULL
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE platform_data_sync_tasks (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                data_source_id INTEGER NOT NULL,
                system_hotel_id INTEGER NOT NULL,
                platform TEXT NOT NULL,
                status TEXT NOT NULL,
                trigger_type TEXT NULL,
                started_at TEXT NULL,
                stats_json TEXT NULL,
                update_time TEXT NULL
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE online_daily_data (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                system_hotel_id INTEGER NOT NULL,
                hotel_id TEXT NOT NULL,
                data_source_id INTEGER NOT NULL,
                sync_task_id INTEGER NOT NULL,
                source TEXT NOT NULL,
                platform TEXT NOT NULL,
                data_date TEXT NOT NULL,
                data_period TEXT NOT NULL,
                data_type TEXT NOT NULL,
                dimension TEXT NOT NULL,
                compare_type TEXT NOT NULL,
                validation_status TEXT NOT NULL,
                readback_verified INTEGER NOT NULL,
                ingestion_method TEXT NOT NULL,
                source_trace_id TEXT NOT NULL,
                snapshot_time TEXT NULL,
                amount REAL NULL,
                quantity REAL NULL,
                book_order_num REAL NULL,
                list_exposure REAL NULL,
                detail_exposure REAL NULL,
                flow_rate REAL NULL,
                order_filling_num REAL NULL,
                order_submit_num REAL NULL,
                raw_data TEXT NULL,
                update_time TEXT NULL,
                history_status TEXT GENERATED ALWAYS AS (
                    CASE
                        WHEN lower(trim(coalesce(validation_status, ''))) IN (
                            'abnormal', 'invalid', 'failed', 'fail', 'error',
                            'collection_failed', 'capture_failed', 'permission_denied',
                            'binding_missing', 'mismatched', 'mismatch', 'login_required'
                        ) THEN 'failed'
                        WHEN lower(trim(coalesce(validation_status, ''))) IN ('unverified', 'stale')
                            THEN 'unverified'
                        WHEN lower(trim(coalesce(validation_status, ''))) IN ('warning', 'partial', 'partial_success')
                            THEN 'partial'
                        WHEN coalesce(readback_verified, 0) <> 1 THEN 'unverified'
                        WHEN coalesce(system_hotel_id, 0) <= 0
                            OR trim(coalesce(CAST(hotel_id AS TEXT), '')) = ''
                            OR coalesce(nullif(trim(CAST(platform AS TEXT)), ''), nullif(trim(CAST(source AS TEXT)), ''), '') = ''
                            OR data_date IS NULL
                            THEN 'unverified'
                        WHEN lower(trim(coalesce(ingestion_method, ''))) IN (
                            '', 'legacy', 'manual', 'manual_import', 'manual_override',
                            'user_provided', 'user_provided_unverified', 'import_csv', 'import_json'
                        ) OR trim(coalesce(source_trace_id, '')) = '' OR snapshot_time IS NULL
                            THEN 'partial'
                        WHEN lower(trim(coalesce(validation_status, ''))) = 'verified' THEN 'success'
                        ELSE 'partial'
                    END
                ) STORED
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE hotel_collection_plan_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dispatcher_run_id TEXT NOT NULL UNIQUE,
                system_hotel_id INTEGER NOT NULL,
                business_date TEXT NOT NULL,
                collection_anchor_hash TEXT NULL,
                receipt_json TEXT NOT NULL
            )
        SQL);
        Db::execute(<<<'SQL'
            CREATE TABLE hotel_collection_plan_run_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                platform TEXT NOT NULL,
                receipt_json TEXT NOT NULL,
                UNIQUE (run_id, platform)
            )
        SQL);
    }
}
