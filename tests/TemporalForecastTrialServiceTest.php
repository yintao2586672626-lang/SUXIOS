<?php
declare(strict_types=1);

namespace Tests;

use app\service\TemporalForecastTrialService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class TemporalForecastTrialServiceTest extends TestCase
{
    private const TENANT_ID = 8;
    private const HOTEL_ID = 80;
    private const FORECAST_RUN_ID = 'tf_fixture_20260803_14d';
    private const AS_OF_DATE = '2026-08-03';

    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'temporal_forecast_trial_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

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
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove temporal forecast trial SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateSchema();
        Db::name('hotels')->insert([
            'id' => self::HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
        ]);
    }

    public function testEligibleFortyTwoPointRunSavesAndReadsBackExactly(): void
    {
        $this->insertForecastRun();
        $service = $this->serviceWithVerifiedSources();

        $result = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);

        self::assertFalse($result['idempotent_replay']);
        self::assertTrue($result['readback_verified']);
        self::assertSame(42, $result['readback_count']);
        self::assertCount(42, $result['points']);
        self::assertSame(self::TENANT_ID, $result['trial']['tenant_id']);
        self::assertSame(self::HOTEL_ID, $result['trial']['system_hotel_id']);
        self::assertSame(self::FORECAST_RUN_ID, $result['trial']['forecast_run_id']);
        self::assertSame('limited_pilot_v1', $result['trial']['policy_version']);
        self::assertSame('2026-08-04', $result['trial']['start_date']);
        self::assertSame('2026-08-17', $result['trial']['end_date']);
        self::assertSame(14, $result['trial']['required_target_days']);
        self::assertSame(14, $result['trial']['required_history_days']);
        self::assertSame('eligible', $result['trial']['eligibility_status']);
        self::assertSame('accruing', $result['trial']['maturity_status']);
        self::assertSame('draft', $result['trial']['status']);
        self::assertSame(64, strlen($result['trial']['immutable_digest']));
        self::assertSame(901, $result['trial']['created_by']);
        self::assertFalse($result['automatic_price_write']);
        self::assertSame('ota_channel', $result['metric_scope']);
        self::assertFalse($result['causality_claimed']);

        self::assertSame(42, (int)Db::name('temporal_forecast_trial_points')->count());
        self::assertSame(1, (int)Db::name('temporal_forecast_trials')->count());
        self::assertSame(
            ['ota_orders', 'ota_revenue', 'ota_room_nights'],
            array_values(array_unique(array_column($result['points'], 'metric_key')))
        );
        self::assertSame([14], array_values(array_unique(array_column($result['points'], 'sample_days'))));
        foreach ($result['points'] as $point) {
            self::assertNull($point['actual_value']);
            self::assertNull($point['absolute_error']);
            self::assertNull($point['within_range']);
            self::assertSame('pending_actual', $point['actual_status']);
            self::assertSame(64, strlen($point['point_digest']));
        }

        $readback = $service->readTrial((int)$result['trial']['id'], self::HOTEL_ID);
        self::assertTrue($readback['readback_verified']);
        self::assertSame($result['trial'], $readback['trial']);
        self::assertSame($result['points'], $readback['points']);
    }

    public function testEligibleRunReplayIsIdempotent(): void
    {
        $this->insertForecastRun();
        $service = $this->serviceWithVerifiedSources();

        $first = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);
        $second = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 999);

        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame($first['trial']['id'], $second['trial']['id']);
        self::assertSame($first['trial']['trial_version'], $second['trial']['trial_version']);
        self::assertSame($first['trial']['immutable_digest'], $second['trial']['immutable_digest']);
        self::assertSame(901, $second['trial']['created_by']);
        self::assertSame(1, (int)Db::name('temporal_forecast_trials')->count());
        self::assertSame(42, (int)Db::name('temporal_forecast_trial_points')->count());
    }

    public function testReadAndListDoNotReturnTrialsAfterHotelTenantBindingDrifts(): void
    {
        $this->insertForecastRun();
        $service = $this->serviceWithVerifiedSources();
        $created = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);

        // The system-hotel binding is part of the trial identity. If it drifts
        // to another tenant, neither detail nor list may expose the old row.
        Db::name('hotels')->where('id', self::HOTEL_ID)->update(['tenant_id' => 9]);

        try {
            $service->readTrial((int)$created['trial']['id'], self::HOTEL_ID);
            self::fail('A tenant-drifted hotel must not read the previous trial.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame([], $service->listTrials(self::HOTEL_ID));
    }

    public function testThirteenTrustedHistoryDaysAreRejectedWithoutCreatingTrialRows(): void
    {
        $this->insertForecastRun(13);
        $service = $this->serviceWithVerifiedSources();
        $eligibility = $service->assessEligibility(
            $this->forecastRows(),
            self::TENANT_ID,
            self::HOTEL_ID,
            self::FORECAST_RUN_ID
        );

        self::assertFalse($eligibility['eligible']);
        self::assertContains('trusted_history_lt_14', $eligibility['reason_codes']);
        $this->assertCreationRejectedWithoutWrites($service);
    }

    public function testMissingForecastPointIsRejectedWithoutCreatingTrialRows(): void
    {
        $this->insertForecastRun();
        Db::name('temporal_forecast_snapshots')
            ->where('forecast_run_id', self::FORECAST_RUN_ID)
            ->where('metric_key', 'ota_orders')
            ->where('target_date', '2026-08-10')
            ->delete();
        $service = $this->serviceWithVerifiedSources();
        $eligibility = $service->assessEligibility(
            $this->forecastRows(),
            self::TENANT_ID,
            self::HOTEL_ID,
            self::FORECAST_RUN_ID
        );

        self::assertFalse($eligibility['eligible']);
        self::assertContains('forecast_point_count_not_42', $eligibility['reason_codes']);
        self::assertContains('metric_target_dates_not_14_consecutive', $eligibility['reason_codes']);
        $this->assertCreationRejectedWithoutWrites($service);
    }

    public function testNonconsecutiveTargetDateIsRejectedWithoutCreatingTrialRows(): void
    {
        $this->insertForecastRun();
        Db::name('temporal_forecast_snapshots')
            ->where('forecast_run_id', self::FORECAST_RUN_ID)
            ->where('metric_key', 'ota_orders')
            ->where('target_date', '2026-08-17')
            ->update(['target_date' => '2026-08-18']);
        $service = $this->serviceWithVerifiedSources();
        $eligibility = $service->assessEligibility(
            $this->forecastRows(),
            self::TENANT_ID,
            self::HOTEL_ID,
            self::FORECAST_RUN_ID
        );

        self::assertFalse($eligibility['eligible']);
        self::assertContains('metric_target_dates_not_14_consecutive', $eligibility['reason_codes']);
        $this->assertCreationRejectedWithoutWrites($service);
    }

    public function testSourceVerifierFailureIsRejectedWithoutCreatingTrialRows(): void
    {
        $this->insertForecastRun();
        $service = new TemporalForecastTrialService(
            sourceVerifier: static fn(array $row): bool => (string)$row['metric_key'] !== 'ota_room_nights'
        );
        $eligibility = $service->assessEligibility(
            $this->forecastRows(),
            self::TENANT_ID,
            self::HOTEL_ID,
            self::FORECAST_RUN_ID
        );

        self::assertFalse($eligibility['eligible']);
        self::assertContains('forecast_source_identity_unverified', $eligibility['reason_codes']);
        $this->assertCreationRejectedWithoutWrites($service);
    }

    public function testReadbackRejectsHeaderDigestTamper(): void
    {
        $this->insertForecastRun();
        $service = $this->serviceWithVerifiedSources();
        $created = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);
        $trialId = (int)$created['trial']['id'];
        Db::name('temporal_forecast_trials')
            ->where('id', $trialId)
            ->update(['immutable_digest' => str_repeat('0', 64)]);

        $this->expectException(RuntimeException::class);
        $service->readTrial($trialId, self::HOTEL_ID);
    }

    public function testReadbackRejectsLockedPointValueTamperEvenWhenStoredDigestWasNotChanged(): void
    {
        $this->insertForecastRun();
        $service = $this->serviceWithVerifiedSources();
        $created = $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);
        $trialId = (int)$created['trial']['id'];
        Db::name('temporal_forecast_trial_points')
            ->where('trial_id', $trialId)
            ->where('metric_key', 'ota_revenue')
            ->where('target_date', '2026-08-04')
            ->update(['predicted_value' => 999999.0]);

        $this->expectException(RuntimeException::class);
        $service->readTrial($trialId, self::HOTEL_ID);
    }

    public function testSummarizePointsPreservesMissingActualsAndReportsDescriptiveAccuracyOnly(): void
    {
        $points = [
            $this->actualPoint('ota_revenue', '2026-08-04', 'ready', 100.0, 10.0, 1),
            $this->actualPoint('ota_revenue', '2026-08-05', 'ready', 200.0, 20.0, 0),
            $this->actualPoint('ota_revenue', '2026-08-06', 'pending_actual'),
            $this->actualPoint('ota_orders', '2026-08-04', 'ready', 10.0, 2.0, 1),
            $this->actualPoint('ota_orders', '2026-08-05', 'pending_actual'),
            $this->actualPoint('ota_room_nights', '2026-08-04', 'pending_actual'),
        ];

        $summary = $this->serviceWithVerifiedSources()->summarizePoints($points);
        $metrics = array_column($summary['metrics'], null, 'metric_key');

        self::assertNull($points[2]['actual_value']);
        self::assertNull($points[2]['absolute_error']);
        self::assertNull($points[2]['within_range']);
        self::assertSame(3, $summary['ready_points']);
        self::assertSame(0, $summary['matured_target_days']);
        self::assertTrue($summary['descriptive_only']);
        self::assertNull($summary['accuracy_pass_threshold']);
        self::assertSame('not_defined_do_not_invent', $summary['accuracy_pass_threshold_status']);
        self::assertFalse($summary['causality_claimed']);

        self::assertSame(15.0, $metrics['ota_revenue']['mae']);
        self::assertSame(10.0, $metrics['ota_revenue']['wape_percent']);
        self::assertSame(50.0, $metrics['ota_revenue']['range_hit_rate_percent']);
        self::assertSame(1, $metrics['ota_revenue']['pending_points']);
        self::assertSame(2.0, $metrics['ota_orders']['mae']);
        self::assertSame(20.0, $metrics['ota_orders']['wape_percent']);
        self::assertSame(100.0, $metrics['ota_orders']['range_hit_rate_percent']);
        self::assertNull($metrics['ota_room_nights']['mae']);
        self::assertNull($metrics['ota_room_nights']['wape_percent']);
        self::assertNull($metrics['ota_room_nights']['range_hit_rate_percent']);
    }

    private function serviceWithVerifiedSources(): TemporalForecastTrialService
    {
        return new TemporalForecastTrialService(
            sourceVerifier: static fn(array $row): bool => true
        );
    }

    private function assertCreationRejectedWithoutWrites(TemporalForecastTrialService $service): void
    {
        try {
            $service->createTrial(self::HOTEL_ID, self::FORECAST_RUN_ID, 901);
            self::fail('Expected ineligible forecast run to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, (int)Db::name('temporal_forecast_trials')->count());
            self::assertSame(0, (int)Db::name('temporal_forecast_trial_points')->count());
        }
    }

    /** @return array<string, mixed> */
    private function actualPoint(
        string $metricKey,
        string $targetDate,
        string $status,
        ?float $actualValue = null,
        ?float $absoluteError = null,
        ?int $withinRange = null
    ): array {
        return [
            'metric_key' => $metricKey,
            'target_date' => $targetDate,
            'actual_status' => $status,
            'actual_value' => $actualValue,
            'absolute_error' => $absoluteError,
            'within_range' => $withinRange,
        ];
    }

    private function insertForecastRun(int $sampleDays = 14): void
    {
        $asOf = new DateTimeImmutable(self::AS_OF_DATE);
        $rows = [];
        $snapshotId = 0;
        foreach (['ota_revenue', 'ota_orders', 'ota_room_nights'] as $metricIndex => $metricKey) {
            for ($horizon = 1; $horizon <= 14; $horizon++) {
                $snapshotId++;
                $predicted = 1000.0 + ($metricIndex * 100.0) + $horizon;
                $targetDate = $asOf->modify("+{$horizon} days")->format('Y-m-d');
                $rows[] = [
                    'id' => $snapshotId,
                    'tenant_id' => self::TENANT_ID,
                    'system_hotel_id' => self::HOTEL_ID,
                    'metric_scope' => 'ota_channel',
                    'platform' => 'all_ota',
                    'metric_key' => $metricKey,
                    'forecast_run_id' => self::FORECAST_RUN_ID,
                    'as_of_date' => self::AS_OF_DATE,
                    'as_of_time' => self::AS_OF_DATE . ' 23:59:00',
                    'target_date' => $targetDate,
                    'horizon_days' => $horizon,
                    'model_version' => 'fixture_model_v1',
                    'method' => 'fixture_method',
                    'predicted_direction' => 'stable',
                    'predicted_value' => $predicted,
                    'lower_bound' => $predicted - 10.0,
                    'upper_bound' => $predicted + 10.0,
                    'confidence_score' => 0.6,
                    'confidence_level' => 'medium',
                    'sample_days' => $sampleDays,
                    'source_start_date' => '2026-07-21',
                    'source_end_date' => self::AS_OF_DATE,
                    'source_refs_json' => $this->json([
                        'source_identity_digest' => hash('sha256', $metricKey . '|' . $targetDate),
                        'fixture_source_verified' => true,
                    ]),
                    'data_quality_status' => 'ready',
                    'created_by' => 901,
                    'created_at' => self::AS_OF_DATE . ' 23:59:00',
                ];
            }
        }
        Db::name('temporal_forecast_snapshots')->insertAll($rows);
    }

    /** @return array<int, array<string, mixed>> */
    private function forecastRows(): array
    {
        return Db::name('temporal_forecast_snapshots')
            ->where('forecast_run_id', self::FORECAST_RUN_ID)
            ->order('metric_key', 'asc')
            ->order('target_date', 'asc')
            ->select()
            ->toArray();
    }

    private function recreateSchema(): void
    {
        foreach ([
            'temporal_forecast_trial_points',
            'temporal_forecast_trials',
            'temporal_forecast_snapshots',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }

        Db::execute('CREATE TABLE hotels ('
            . 'id INTEGER PRIMARY KEY, '
            . 'tenant_id INTEGER NOT NULL'
            . ')');

        Db::execute('CREATE TABLE temporal_forecast_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'metric_scope TEXT NOT NULL, '
            . 'platform TEXT NOT NULL, '
            . 'metric_key TEXT NOT NULL, '
            . 'forecast_run_id TEXT NOT NULL, '
            . 'as_of_date TEXT NOT NULL, '
            . 'as_of_time TEXT NOT NULL, '
            . 'target_date TEXT NOT NULL, '
            . 'horizon_days INTEGER NOT NULL, '
            . 'model_version TEXT NOT NULL, '
            . 'method TEXT NOT NULL, '
            . 'predicted_direction TEXT NOT NULL, '
            . 'predicted_value REAL NULL, '
            . 'lower_bound REAL NULL, '
            . 'upper_bound REAL NULL, '
            . 'confidence_score REAL NULL, '
            . 'confidence_level TEXT NOT NULL, '
            . 'sample_days INTEGER NOT NULL, '
            . 'source_start_date TEXT NULL, '
            . 'source_end_date TEXT NULL, '
            . 'source_refs_json TEXT NULL, '
            . 'data_quality_status TEXT NOT NULL, '
            . 'created_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'UNIQUE(forecast_run_id, system_hotel_id, platform, metric_key, target_date)'
            . ')');

        Db::execute('CREATE TABLE temporal_forecast_trials ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'trial_version TEXT NOT NULL UNIQUE, '
            . 'forecast_run_id TEXT NOT NULL, '
            . 'policy_version TEXT NOT NULL, '
            . 'metric_scope TEXT NOT NULL, '
            . 'platform TEXT NOT NULL, '
            . 'start_date TEXT NOT NULL, '
            . 'end_date TEXT NOT NULL, '
            . 'required_target_days INTEGER NOT NULL, '
            . 'required_history_days INTEGER NOT NULL, '
            . 'core_metrics_json TEXT NOT NULL, '
            . 'pilot_policy_json TEXT NOT NULL, '
            . 'mature_policy_json TEXT NOT NULL, '
            . 'source_identity_json TEXT NOT NULL, '
            . 'immutable_digest TEXT NOT NULL, '
            . 'eligibility_status TEXT NOT NULL, '
            . 'maturity_status TEXT NOT NULL, '
            . 'status TEXT NOT NULL, '
            . 'operation_intent_id INTEGER NULL, '
            . 'final_review_json TEXT NULL, '
            . 'approved_by INTEGER NULL, '
            . 'approved_at TEXT NULL, '
            . 'reviewed_by INTEGER NULL, '
            . 'reviewed_at TEXT NULL, '
            . 'stopped_reason TEXT NULL, '
            . 'created_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id, system_hotel_id, forecast_run_id)'
            . ')');

        Db::execute('CREATE TABLE temporal_forecast_trial_points ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'trial_id INTEGER NOT NULL, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'forecast_snapshot_id INTEGER NOT NULL, '
            . 'metric_key TEXT NOT NULL, '
            . 'target_date TEXT NOT NULL, '
            . 'horizon_days INTEGER NOT NULL, '
            . 'predicted_value REAL NULL, '
            . 'lower_bound REAL NULL, '
            . 'upper_bound REAL NULL, '
            . 'sample_days INTEGER NOT NULL, '
            . 'source_refs_json TEXT NOT NULL, '
            . 'point_digest TEXT NOT NULL, '
            . 'actual_status TEXT NOT NULL, '
            . 'actual_value REAL NULL, '
            . 'absolute_error REAL NULL, '
            . 'within_range INTEGER NULL, '
            . 'actual_readback_json TEXT NULL, '
            . 'actual_reason_code TEXT NULL, '
            . 'actual_readback_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'UNIQUE(trial_id, forecast_snapshot_id), '
            . 'UNIQUE(trial_id, metric_key, target_date)'
            . ')');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
