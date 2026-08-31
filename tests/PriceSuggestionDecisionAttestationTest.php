<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PriceSuggestionDecisionAttestationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'price_suggestion_decision_attestation_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove price suggestion attestation fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('price_suggestions')->delete(true);
    }

    public function testNewPendingSuggestionPersistsAttestedInputsAndDuplicateRunIsIdempotent(): void
    {
        $targetDate = date('Y-m-d', strtotime('+7 days'));
        $signals = $this->signals($targetDate);
        $base = new RevenuePricingRecommendationService();
        $recommendation = $base->recommendFromSignals([
            'id' => 11,
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
        ], $signals);
        self::assertTrue($recommendation['should_create']);

        $service = new class($recommendation) extends RevenuePricingRecommendationService {
            public function __construct(private array $fixtureRecommendation)
            {
                parent::__construct();
            }

            public function recommendBatch(int $hotelId, array $roomTypes, array $targetDates): array
            {
                $result = [];
                foreach ($targetDates as $targetDate) {
                    foreach ($roomTypes as $roomType) {
                        $result[self::batchRecommendationKey(
                            (int)$roomType['id'],
                            (string)$targetDate
                        )] = $this->fixtureRecommendation;
                    }
                }
                return $result;
            }
        };

        $room = Db::name('room_types')->where('id', 11)->find();
        self::assertIsArray($room);
        [$created, $skipped] = $service->generatePendingBatch(7, [$room], [$targetDate]);
        self::assertSame([], $skipped);
        self::assertCount(1, $created);

        $stored = Db::name('price_suggestions')->where('id', (int)$created[0]['id'])->find();
        self::assertIsArray($stored);
        self::assertSame('ctrip', $stored['platform']);
        self::assertSame($stored['create_time'], $stored['decision_as_of_time']);
        self::assertSame('advisory_revenue_pricing_v1', $stored['model_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$stored['decision_input_digest']);
        $storedRefs = json_decode((string)$stored['decision_source_refs'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($storedRefs, array_values(array_unique($storedRefs)));
        $sortedRefs = $storedRefs;
        sort($sortedRefs, SORT_STRING);
        self::assertSame($sortedRefs, $storedRefs);
        self::assertContains('room_types#11', $storedRefs);
        self::assertContains('demand_forecasts#81', $storedRefs);
        self::assertContains(
            'competitor_analysis#hotel:7:room_type:11:date:' . $targetDate,
            $storedRefs
        );
        self::assertContains('online_daily_data#pricing_history:7:verified', $storedRefs);

        $stored['factors'] = json_decode((string)$stored['factors'], true, flags: JSON_THROW_ON_ERROR);
        $rebuilt = $service->buildPriceSuggestionDecisionAttestation($stored);
        self::assertSame($stored['decision_input_digest'], $rebuilt['decision_input_digest']);
        self::assertSame($storedRefs, $rebuilt['decision_source_refs']);
        self::assertTrue($rebuilt['advisory_only']);
        self::assertFalse($rebuilt['automatic_approval']);
        self::assertFalse($rebuilt['automatic_price_write']);
        self::assertSame(0, $rebuilt['external_write_count']);
        $view = $service->describePriceSuggestionDecisionAttestation($stored);
        self::assertSame('attested', $view['status']);
        self::assertSame('决策输入已冻结', $view['status_label']);
        self::assertTrue($view['readback_verified']);
        self::assertSame(count($storedRefs), $view['source_ref_count']);
        self::assertSame(substr((string)$stored['decision_input_digest'], 0, 12), $view['digest_prefix']);

        [$duplicateCreated, $duplicateSkipped] = $service->generatePendingBatch(
            7,
            [$room],
            [$targetDate]
        );
        self::assertSame([], $duplicateCreated);
        self::assertCount(1, $duplicateSkipped);
        self::assertSame('pending_suggestion_exists', $duplicateSkipped[0]['reason']);
        self::assertSame(1, (int)Db::name('price_suggestions')->count());
        self::assertSame(
            $stored['decision_input_digest'],
            Db::name('price_suggestions')->where('id', (int)$created[0]['id'])
                ->value('decision_input_digest')
        );
    }

    public function testAttestationRejectsAnInputObservedAfterDecisionAsOf(): void
    {
        $targetDate = '2026-09-10';
        $signals = $this->signals($targetDate);
        $signals['competitor']['collected_at'] = '2026-09-02 10:00:01';
        $recommendation = (new RevenuePricingRecommendationService())->recommendFromSignals([
            'id' => 11,
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
        ], $signals);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('future_input_detected');
        (new RevenuePricingRecommendationService())->buildPriceSuggestionDecisionAttestation([
            'tenant_id' => 42,
            'hotel_id' => 7,
            'room_type_id' => 11,
            'suggestion_date' => $targetDate,
            'current_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'platform' => 'ctrip',
            'decision_as_of_time' => '2026-09-02 10:00:00',
            'model_version' => $recommendation['factors']['model'],
            'factors' => $recommendation['factors'],
        ]);
    }

    public function testFollowupMigrationIsNullableIdempotentAndNeverBackfillsLegacyRows(): void
    {
        $migration = file_get_contents(
            __DIR__ . '/../database/migrations/20260831_z_add_price_suggestion_decision_attestation.sql'
        );
        self::assertIsString($migration);
        foreach ([
            'platform',
            'decision_as_of_time',
            'model_version',
            'decision_input_digest',
            'decision_source_refs',
        ] as $column) {
            self::assertStringContainsString(
                'ADD COLUMN IF NOT EXISTS `' . $column . '`',
                $migration
            );
        }
        self::assertSame(5, substr_count($migration, 'DEFAULT NULL'));
        self::assertDoesNotMatchRegularExpression('/\bUPDATE\s+`?price_suggestions`?/i', $migration);
        self::assertDoesNotMatchRegularExpression('/\bINSERT\s+INTO\s+`?price_suggestions`?/i', $migration);
        self::assertStringContainsString('legacy_reconstructed', $migration);
    }

    public function testAttestationDisplayKeepsLegacyAndPartialRowsDistinct(): void
    {
        $service = new RevenuePricingRecommendationService();
        $legacy = $service->describePriceSuggestionDecisionAttestation([]);
        self::assertSame('legacy_reconstructed', $legacy['status']);
        self::assertSame('历史建议·未冻结证明', $legacy['status_label']);
        self::assertFalse($legacy['readback_verified']);

        $partial = $service->describePriceSuggestionDecisionAttestation([
            'platform' => 'ctrip',
            'decision_as_of_time' => '2026-08-30 10:00:00',
        ]);
        self::assertSame('invalid', $partial['status']);
        self::assertSame('决策证明不完整', $partial['status_label']);
        self::assertFalse($partial['readback_verified']);
        self::assertFalse($partial['automatic_price_write']);
        self::assertSame(0, $partial['external_write_count']);
    }

    /** @return array<string,mixed> */
    private function signals(string $targetDate): array
    {
        return [
            'demand_forecast' => [
                'id' => 81,
                'source' => 'demand_forecasts',
                'room_type_id' => 11,
                'forecast_date' => $targetDate,
                'data_status' => 'ok',
                'predicted_occupancy' => 93,
                'confidence_score' => 0.86,
            ],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 145],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -0.3],
            'competitor' => [
                'data_status' => 'ok',
                'source_scope' => 'room_type',
                'source_date' => $targetDate,
                'sample_count' => 1,
                'gap_percent' => 18,
                'avg_price' => 236,
            ],
            'holiday' => [
                'data_status' => 'ok',
                'is_holiday_window' => true,
                'is_in_holiday' => false,
            ],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 98],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 78, 'sample_count' => 18],
            'history_evidence' => [
                'ref' => 'online_daily_data#pricing_history:7:verified',
                'readback_verified' => true,
            ],
            'data_gaps' => [],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
        Db::execute(<<<'SQL'
CREATE TABLE room_types (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    name TEXT, base_price REAL, min_price REAL, max_price REAL,
    room_count INTEGER, facilities TEXT, is_enabled INTEGER
)
SQL);
        Db::name('room_types')->insert([
            'id' => 11,
            'tenant_id' => 42,
            'hotel_id' => 7,
            'name' => '高级大床房',
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
            'facilities' => '[]',
            'is_enabled' => 1,
        ]);
        Db::execute(<<<'SQL'
CREATE TABLE price_suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    room_type_id INTEGER NOT NULL, demand_forecast_id INTEGER, platform TEXT,
    decision_as_of_time TEXT, model_version TEXT, decision_input_digest TEXT,
    decision_source_refs TEXT, suggestion_date TEXT NOT NULL, suggestion_type INTEGER NOT NULL,
    current_price REAL NOT NULL, suggested_price REAL NOT NULL, min_price REAL NOT NULL,
    max_price REAL NOT NULL, confidence_score REAL, competitor_data TEXT, factors TEXT,
    reason TEXT, active_dedupe_key TEXT NULL UNIQUE, status INTEGER NOT NULL,
    applied_by INTEGER, applied_time TEXT, remark TEXT, create_time TEXT, update_time TEXT
)
SQL);
    }
}
