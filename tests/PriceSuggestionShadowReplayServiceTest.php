<?php
declare(strict_types=1);

namespace Tests;

use app\service\PriceSuggestionShadowReplayService;
use app\service\RevenuePricingRecommendationService;
use app\service\TrustedOtaFactRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PriceSuggestionShadowReplayServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'price_suggestion_shadow_replay_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

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
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove price shadow replay SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('price_suggestion_shadow_replays')->delete(true);
        Db::name('online_daily_data')->delete(true);
        Db::name('price_suggestions')->delete(true);
        Db::name('room_types')->where('id', 11)->update(['name' => '高级大床房']);
        $this->seedSuggestion();
    }

    public function testSavedSuggestionReplaysExactlyAndAppendsOnlyWhenActualEvidenceChanges(): void
    {
        $this->insertActualIdentityRow(501, 11);
        $service = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(501, 690.0, 3.0, 2.0)
        );

        $first = $service->createFromSuggestion(31, 7, 9);
        Db::name('room_types')->where('id', 11)->update(['name' => '后来改名的房型']);
        $replayed = $service->createFromSuggestion(31, 7, 9);

        self::assertTrue($first['created']);
        self::assertFalse($replayed['created']);
        self::assertTrue($replayed['idempotent_replay']);
        self::assertSame($first['replay']['id'], $replayed['replay']['id']);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertTrue($first['replay']['readback_verified']);
        self::assertSame('direction_aligned', $first['replay']['verdict']);
        self::assertSame('increase', $first['replay']['recommendation_direction']);
        self::assertSame('increase', $first['replay']['observed_direction']);
        self::assertSame(230.0, $first['replay']['actual_snapshot']['ota_sales_avg_price']);
        self::assertSame('ota_order_amount_not_proven_room_revenue', $first['replay']['actual_snapshot']['amount_semantics']);
        self::assertSame('legacy_reconstructed', $first['replay']['input_snapshot']['freeze_status']);
        self::assertTrue($first['replay']['input_snapshot']['replay_match_required_for_direction_verdict']);
        self::assertTrue($first['replay']['input_snapshot']['observed_time_check']['all_at_or_before_as_of']);
        self::assertTrue($first['replay']['recommendation_snapshot']['replay_match']);
        self::assertSame(
            'sign(ota_order_amount / ota_room_nights - frozen_current_price)',
            $first['replay']['recommendation_snapshot']['direction_verdict_basis']
        );
        self::assertFalse($first['replay']['causality_claimed']);
        self::assertSame(0, $first['replay']['external_write_count']);
        self::assertFalse($first['boundaries']['automatic_approval']);
        self::assertFalse($first['boundaries']['automatic_price_write']);
        self::assertFalse($first['boundaries']['execution_intent_created']);
        self::assertSame(1, (int)Db::name('price_suggestion_shadow_replays')->count());
        self::assertSame(2, (int)Db::name('price_suggestions')->where('id', 31)->value('status'));

        $this->insertActualIdentityRow(502, 11);
        $opposedService = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(502, 450.0, 3.0, 2.0)
        );
        $opposed = $opposedService->createFromSuggestion(31, 7, 9);
        self::assertTrue($opposed['created']);
        self::assertSame('direction_opposed', $opposed['replay']['verdict']);
        self::assertSame('decrease', $opposed['replay']['observed_direction']);
        self::assertNotSame($first['replay']['actual_digest'], $opposed['replay']['actual_digest']);
        self::assertNotSame($first['replay']['content_digest'], $opposed['replay']['content_digest']);
        self::assertSame(2, (int)Db::name('price_suggestion_shadow_replays')->count());

        $list = $opposedService->listForSuggestion(42, 7, 31);
        self::assertSame(2, $list['count']);
        self::assertSame($opposed['replay']['id'], $list['list'][0]['id']);
        self::assertSame($first['replay']['id'], $list['list'][1]['id']);
    }

    public function testMissingExactSystemRoomTypeActualStaysIndeterminateWithoutHotelFallback(): void
    {
        $this->insertActualIdentityRow(501, 99);
        $service = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(501, 690.0, 3.0, 2.0)
        );

        $result = $service->createFromSuggestion(31, 7, 9);

        self::assertSame('indeterminate', $result['replay']['verdict']);
        self::assertSame('unknown', $result['replay']['observed_direction']);
        self::assertSame('unavailable', $result['replay']['actual_snapshot']['status']);
        self::assertNull($result['replay']['actual_snapshot']['ota_sales_avg_price']);
        self::assertSame([], $result['replay']['actual_snapshot']['source_refs']);
        self::assertContains(
            'final_same_system_room_type_actual_missing',
            $result['replay']['actual_snapshot']['reason_codes']
        );
        self::assertFalse($result['replay']['actual_snapshot']['readback_verified']);
        self::assertSame(1, (int)Db::name('price_suggestion_shadow_replays')->count());
    }

    public function testAveragePriceRequiresAmountAndQuantityFromSameFactRows(): void
    {
        $this->insertActualIdentityRow(501, 11);
        $this->insertActualIdentityRow(502, 11);
        $repository = $this->createMock(TrustedOtaFactRepository::class);
        $repository->method('pricingHistory')->willReturn([
            'data_status' => 'ready',
            'rows' => [
                [
                    'row_id' => 501,
                    'system_hotel_id' => 7,
                    'data_date' => '2026-08-12',
                    'source' => 'ctrip',
                    'metric_scope' => 'ota_channel',
                    'readback_verified' => true,
                    'amount' => 690.0,
                    'quantity' => null,
                    'book_order_num' => 2.0,
                    'collected_at' => '2026-08-13 08:00:00',
                ],
                [
                    'row_id' => 502,
                    'system_hotel_id' => 7,
                    'data_date' => '2026-08-12',
                    'source' => 'ctrip',
                    'metric_scope' => 'ota_channel',
                    'readback_verified' => true,
                    'amount' => null,
                    'quantity' => 3.0,
                    'book_order_num' => 2.0,
                    'collected_at' => '2026-08-13 08:05:00',
                ],
            ],
        ]);
        $service = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $repository
        );

        $result = $service->createFromSuggestion(31, 7, 9);

        self::assertSame('indeterminate', $result['replay']['verdict']);
        self::assertSame('unavailable', $result['replay']['actual_snapshot']['status']);
        self::assertNull($result['replay']['actual_snapshot']['ota_sales_avg_price']);
        self::assertContains(
            'same_room_ota_sales_avg_price_not_calculable',
            $result['replay']['actual_snapshot']['reason_codes']
        );
    }

    public function testAttestedSuggestionUsesVerifiedAsOfModelDigestAndSourceRefs(): void
    {
        $this->attestSeedSuggestion();
        $this->insertActualIdentityRow(501, 11);
        $service = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(501, 690.0, 3.0, 2.0)
        );

        $first = $service->createFromSuggestion(31, 7, 9);
        $duplicate = $service->createFromSuggestion(31, 7, 9);

        self::assertSame('attested', $first['replay']['input_snapshot']['freeze_status']);
        self::assertSame(
            'decision_as_of_time_equals_saved_create_time_and_digest_verified',
            $first['replay']['input_snapshot']['as_of_policy']
        );
        self::assertSame('2026-08-10 10:00:00', $first['replay']['as_of_at']);
        self::assertSame(
            RevenuePricingRecommendationService::PRICE_SUGGESTION_DECISION_ATTESTATION_VERSION,
            $first['replay']['input_snapshot']['decision_attestation']['contract_version']
        );
        self::assertTrue($first['replay']['input_snapshot']['decision_attestation']['digest_verified']);
        self::assertTrue($first['replay']['input_snapshot']['decision_attestation']['source_refs_verified']);
        self::assertTrue($first['replay']['input_snapshot']['decision_attestation']['model_version_verified']);
        self::assertContains(
            'demand_forecasts#81',
            $first['replay']['input_snapshot']['source_refs']
        );
        self::assertFalse($duplicate['created']);
        self::assertTrue($duplicate['idempotent_replay']);
        self::assertSame($first['replay']['id'], $duplicate['replay']['id']);
        self::assertFalse($first['replay']['causality_claimed']);
        self::assertSame(0, $first['replay']['external_write_count']);
    }

    public function testPartialAttestationCannotDowngradeToLegacyReplay(): void
    {
        Db::name('price_suggestions')->where('id', 31)->update(['platform' => 'ctrip']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不得降级为旧建议回放');
        try {
            (new PriceSuggestionShadowReplayService(
                new RevenuePricingRecommendationService(),
                $this->repositoryFor(501, 690.0, 3.0, 2.0)
            ))->createFromSuggestion(31, 7, 9);
        } finally {
            self::assertSame(0, (int)Db::name('price_suggestion_shadow_replays')->count());
        }
    }

    public function testTamperedAttestedSignalsFailDigestVerificationBeforeReplayWrite(): void
    {
        $this->attestSeedSuggestion();
        $factors = json_decode(
            (string)Db::name('price_suggestions')->where('id', 31)->value('factors'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $factors['signals']['pickup']['pace_index'] = 60;
        Db::name('price_suggestions')->where('id', 31)->update([
            'factors' => json_encode(
                $factors,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('摘要或来源引用校验失败');
        try {
            (new PriceSuggestionShadowReplayService(
                new RevenuePricingRecommendationService(),
                $this->repositoryFor(501, 690.0, 3.0, 2.0)
            ))->createFromSuggestion(31, 7, 9);
        } finally {
            self::assertSame(0, (int)Db::name('price_suggestion_shadow_replays')->count());
        }
    }

    public function testSuggestionCreatedOnTargetDateIsRejectedAsHindsightBeforeWrite(): void
    {
        Db::name('price_suggestions')->where('id', 31)->update([
            'create_time' => '2026-08-12 08:00:00',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('目标入住日前生成');
        try {
            (new PriceSuggestionShadowReplayService(
                new RevenuePricingRecommendationService(),
                $this->repositoryFor(501, 690.0, 3.0, 2.0)
            ))->createFromSuggestion(31, 7, 9);
        } finally {
            self::assertSame(0, (int)Db::name('price_suggestion_shadow_replays')->count());
        }
    }

    public function testOriginalPricingEvidenceGateBlocksWeakSavedSignalsBeforeWrite(): void
    {
        $factors = json_decode((string)Db::name('price_suggestions')->where('id', 31)->value('factors'), true);
        $factors['signals']['data_gaps'] = ['trusted_pricing_history_missing'];
        Db::name('price_suggestions')->where('id', 31)->update([
            'factors' => json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('原有证据与风险门');
        try {
            (new PriceSuggestionShadowReplayService(
                new RevenuePricingRecommendationService(),
                $this->repositoryFor(501, 690.0, 3.0, 2.0)
            ))->createFromSuggestion(31, 7, 9);
        } finally {
            self::assertSame(0, (int)Db::name('price_suggestion_shadow_replays')->count());
        }
    }

    public function testExplicitSourceObservationAfterAsOfIsRejectedBeforeWrite(): void
    {
        $factors = json_decode((string)Db::name('price_suggestions')->where('id', 31)->value('factors'), true);
        $factors['signals']['competitor']['collected_at'] = '2026-08-11 09:00:00';
        Db::name('price_suggestions')->where('id', 31)->update([
            'factors' => json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('晚于 as-of 时点');
        try {
            (new PriceSuggestionShadowReplayService(
                new RevenuePricingRecommendationService(),
                $this->repositoryFor(501, 690.0, 3.0, 2.0)
            ))->createFromSuggestion(31, 7, 9);
        } finally {
            self::assertSame(0, (int)Db::name('price_suggestion_shadow_replays')->count());
        }
    }

    public function testExactReadbackRejectsSnapshotTampering(): void
    {
        $this->insertActualIdentityRow(501, 11);
        $service = new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(501, 690.0, 3.0, 2.0)
        );
        $created = $service->createFromSuggestion(31, 7, 9);
        $id = (int)$created['replay']['id'];
        $tampered = $created['replay']['actual_snapshot'];
        $tampered['ota_sales_avg_price'] = 999.0;
        Db::name('price_suggestion_shadow_replays')->where('id', $id)->update([
            'actual_snapshot_json' => json_encode(
                $tampered,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('快照摘要校验失败');
        $service->readVerified($id, 42, 7, 31);
    }

    public function testRecommendationReplayMismatchCannotProduceDirectionalVerdict(): void
    {
        Db::name('price_suggestions')->where('id', 31)->update(['suggested_price' => 229.0]);
        $this->insertActualIdentityRow(501, 11);
        $result = (new PriceSuggestionShadowReplayService(
            new RevenuePricingRecommendationService(),
            $this->repositoryFor(501, 690.0, 3.0, 2.0)
        ))->createFromSuggestion(31, 7, 9);

        self::assertFalse($result['replay']['recommendation_snapshot']['replay_match']);
        self::assertContains(
            'suggested_price',
            $result['replay']['recommendation_snapshot']['mismatch_fields']
        );
        self::assertSame('indeterminate', $result['replay']['verdict']);
        self::assertSame('recommendation_replay_mismatch', $result['replay']['verdict_reason']);
        self::assertSame('unknown', $result['replay']['observed_direction']);
    }

    public function testMigrationRoutesAndRegistryKeepReplayAppendOnlyAndReadOnly(): void
    {
        $migration = file_get_contents(__DIR__ . '/../database/migrations/20260831_create_price_suggestion_shadow_replays.sql');
        $routes = file_get_contents(__DIR__ . '/../route/app.php')
            . file_get_contents(__DIR__ . '/../route/domain/agent_guidance.php');
        $controller = file_get_contents(__DIR__ . '/../app/controller/Agent.php');
        $service = file_get_contents(__DIR__ . '/../app/service/PriceSuggestionShadowReplayService.php');
        $registry = file_get_contents(__DIR__ . '/../scripts/cloud_hotel_id_column_registry.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `price_suggestion_shadow_replays`', $migration);
        self::assertStringContainsString('trg_price_shadow_replay_no_update', $migration);
        self::assertStringContainsString('trg_price_shadow_replay_no_delete', $migration);
        self::assertStringContainsString("Route::post('/price-suggestions/:id/shadow-replays'", $routes);
        self::assertStringContainsString("Route::get('/price-suggestions/:id/shadow-replays'", $routes);
        self::assertStringContainsString('createPriceSuggestionShadowReplay', $controller);
        self::assertStringContainsString('priceSuggestionShadowReplays', $controller);
        self::assertStringContainsString('recommendFromSignals($roomSnapshot, $signals)', $service);
        self::assertStringContainsString("['price_suggestion_shadow_replays', 'hotel_id', 'hotel_id']", $registry);
        self::assertStringNotContainsString('generatePendingBatch(', $service);
        self::assertStringNotContainsString('createExecutionIntent(', $service);
        self::assertStringNotContainsString('automatic_approval' . "' => true", $service);
        self::assertStringNotContainsString('automatic_price_write' . "' => true", $service);
    }

    private function seedSuggestion(): void
    {
        $signals = [
            'demand_forecast' => [
                'id' => 81,
                'data_status' => 'ok',
                'predicted_occupancy' => 93,
                'confidence_score' => 0.86,
            ],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 145],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -0.3],
            'competitor' => [
                'data_status' => 'ok',
                'gap_percent' => 18,
                'avg_price' => 236,
                'source_date' => '2026-08-12',
                'room_type_id' => 11,
            ],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => true, 'is_in_holiday' => false],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 98],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 78, 'sample_count' => 18],
            'history_evidence' => [
                'ref' => 'online_daily_data#pricing_history:7:2026-06-10:2026-08-10',
                'date' => '2026-08-10',
                'platform' => 'ctrip',
                'readback_verified' => true,
            ],
            'data_gaps' => [],
        ];
        $recommendation = (new RevenuePricingRecommendationService())->recommendFromSignals([
            'id' => 11,
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 230,
            'room_count' => 20,
        ], $signals);
        self::assertTrue($recommendation['should_create']);

        Db::name('price_suggestions')->insert([
            'id' => 31,
            'tenant_id' => 42,
            'hotel_id' => 7,
            'room_type_id' => 11,
            'suggestion_type' => 1,
            'status' => 2,
            'suggestion_date' => '2026-08-12',
            'current_price' => $recommendation['current_price'],
            'suggested_price' => $recommendation['suggested_price'],
            'min_price' => 160,
            'max_price' => 230,
            'confidence_score' => $recommendation['confidence_score'],
            'competitor_data' => json_encode($recommendation['competitor_data'], JSON_THROW_ON_ERROR),
            'factors' => json_encode($recommendation['factors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'reason' => $recommendation['reason'],
            'create_time' => '2026-08-10 10:00:00',
            'update_time' => '2026-08-10 10:00:00',
        ]);
    }

    private function attestSeedSuggestion(): void
    {
        $row = Db::name('price_suggestions')->where('id', 31)->find();
        self::assertIsArray($row);
        $row['platform'] = RevenuePricingRecommendationService::PRICE_SUGGESTION_PLATFORM;
        $row['decision_as_of_time'] = (string)$row['create_time'];
        $row['model_version'] = 'advisory_revenue_pricing_v1';
        $row['factors'] = json_decode((string)$row['factors'], true, flags: JSON_THROW_ON_ERROR);
        $attestation = (new RevenuePricingRecommendationService())
            ->buildPriceSuggestionDecisionAttestation($row);
        Db::name('price_suggestions')->where('id', 31)->update([
            'platform' => $attestation['platform'],
            'decision_as_of_time' => $attestation['decision_as_of_time'],
            'model_version' => $attestation['model_version'],
            'decision_input_digest' => $attestation['decision_input_digest'],
            'decision_source_refs' => json_encode(
                $attestation['decision_source_refs'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    private function insertActualIdentityRow(int $id, int $systemRoomTypeId): void
    {
        Db::name('online_daily_data')->insert([
            'id' => $id,
            'raw_data' => json_encode([
                'system_room_type_id' => $systemRoomTypeId,
                'metric_scope' => 'room_type',
            ], JSON_THROW_ON_ERROR),
            'data_period' => 'historical_daily',
            'is_final' => 1,
        ]);
    }

    private function repositoryFor(
        int $rowId,
        float $amount,
        float $roomNights,
        float $orders
    ): TrustedOtaFactRepository {
        $repository = $this->createMock(TrustedOtaFactRepository::class);
        $repository->method('pricingHistory')->willReturn([
            'data_status' => 'ready',
            'rows' => [[
                'row_id' => $rowId,
                'system_hotel_id' => 7,
                'data_date' => '2026-08-12',
                'source' => 'ctrip',
                'metric_scope' => 'ota_channel',
                'readback_verified' => true,
                'amount' => $amount,
                'quantity' => $roomNights,
                'book_order_num' => $orders,
                'collected_at' => '2026-08-13 08:00:00',
            ]],
            'data_gaps' => [],
            'source_policy' => ['readback_policy' => 'readback_verified_required_equals_1'],
            'data_quality' => ['trusted_rows' => 1],
        ]);
        return $repository;
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::name('room_types')->insert(['id' => 11, 'tenant_id' => 42, 'hotel_id' => 7, 'name' => '高级大床房']);
        Db::execute(<<<'SQL'
CREATE TABLE price_suggestions (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    room_type_id INTEGER NOT NULL, suggestion_type INTEGER NOT NULL, status INTEGER NOT NULL,
    suggestion_date TEXT NOT NULL, current_price REAL NOT NULL, suggested_price REAL NOT NULL,
    min_price REAL NOT NULL, max_price REAL NOT NULL, confidence_score REAL,
    competitor_data TEXT, factors TEXT, reason TEXT, platform TEXT,
    decision_as_of_time TEXT, model_version TEXT, decision_input_digest TEXT,
    decision_source_refs TEXT, create_time TEXT, update_time TEXT
)
SQL);
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, raw_data TEXT NOT NULL, data_period TEXT, is_final INTEGER)');
        Db::execute(<<<'SQL'
CREATE TABLE price_suggestion_shadow_replays (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    price_suggestion_id INTEGER NOT NULL, room_type_id INTEGER NOT NULL, platform TEXT NOT NULL,
    target_stay_date TEXT NOT NULL, as_of_at TEXT NOT NULL, contract_version TEXT NOT NULL,
    model_version TEXT NOT NULL, input_snapshot_json TEXT NOT NULL, input_digest TEXT NOT NULL,
    recommendation_snapshot_json TEXT NOT NULL, recommendation_digest TEXT NOT NULL,
    actual_snapshot_json TEXT NOT NULL, actual_digest TEXT NOT NULL,
    recommendation_direction TEXT NOT NULL, observed_direction TEXT NOT NULL,
    verdict TEXT NOT NULL, verdict_reason TEXT NOT NULL, causality_claimed INTEGER NOT NULL,
    external_write_count INTEGER NOT NULL, content_digest TEXT NOT NULL,
    created_by INTEGER NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (tenant_id, hotel_id, price_suggestion_id, content_digest)
)
SQL);
    }
}
