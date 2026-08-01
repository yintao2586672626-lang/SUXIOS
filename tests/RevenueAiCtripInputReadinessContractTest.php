<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

require_once dirname(__DIR__) . '/scripts/report_revenue_ai_ctrip_input_readiness.php';

final class RevenueAiCtripInputReadinessContractTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'revenue_ai_ctrip_input_readiness_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        Db::execute(
            'CREATE TABLE competitor_price_log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'store_id INTEGER NOT NULL,'
            . 'hotel_id INTEGER NOT NULL,'
            . 'platform TEXT NOT NULL,'
            . 'collected_at TEXT NULL,'
            . 'fetch_time TEXT NULL,'
            . 'create_time TEXT NULL,'
            . 'readback_verified INTEGER NOT NULL DEFAULT 0,'
            . 'validation_status TEXT NOT NULL DEFAULT "unverified",'
            . 'comparison_key TEXT NULL,'
            . 'deleted_at TEXT NULL'
            . ')'
        );
        Db::execute(
            'CREATE TABLE competitor_analysis ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'hotel_id INTEGER NOT NULL,'
            . 'ota_platform TEXT NOT NULL,'
            . 'analysis_date TEXT NOT NULL,'
            . 'readback_verified INTEGER NOT NULL DEFAULT 0,'
            . 'validation_status TEXT NOT NULL DEFAULT "unverified",'
            . 'comparison_key TEXT NULL,'
            . 'deleted_at TEXT NULL'
            . ')'
        );
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);

        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove Ctrip readiness SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('competitor_price_log')->delete(true);
        Db::name('competitor_analysis')->delete(true);
    }

    public function testMissingTargetDateCandidateIsReportedAsRequiredRealInput(): void
    {
        self::assertSame(
            ['target_date_ctrip_rows_missing'],
            \ctrip_input_readiness_current_required_inputs([])
        );
    }

    public function testExistingTargetDateCandidatePreservesItsMissingInputs(): void
    {
        self::assertSame(
            ['room_types_enabled', 'competitor_price_samples'],
            \ctrip_input_readiness_current_required_inputs([
                'date' => '2026-07-17',
                'missing_inputs' => ['room_types_enabled', 'competitor_price_samples'],
            ])
        );
    }

    public function testCompleteTargetDateCandidateHasNoRequiredMissingInput(): void
    {
        self::assertSame(
            [],
            \ctrip_input_readiness_current_required_inputs([
                'date' => '2026-07-17',
                'missing_inputs' => [],
            ])
        );
    }

    public function testCompetitorSamplesUseStorePlatformAndCompatibleCollectionTimes(): void
    {
        $rows = array_map(static fn(array $row): array => array_merge([
            'readback_verified' => 1,
            'validation_status' => 'valid',
            'comparison_key' => str_repeat('a', 64),
        ], $row), [
            [
                'store_id' => 17,
                'hotel_id' => 901,
                'platform' => 'ctrip',
                'collected_at' => '2026-07-17 08:00:00',
                'fetch_time' => null,
                'create_time' => '2026-07-17 08:01:00',
                'deleted_at' => null,
            ],
            [
                'store_id' => 17,
                'hotel_id' => 902,
                'platform' => 'ctrip',
                'collected_at' => null,
                'fetch_time' => '2026-07-16 09:00:00',
                'create_time' => '2026-07-16 09:01:00',
                'deleted_at' => null,
            ],
            [
                'store_id' => 17,
                'hotel_id' => 903,
                'platform' => 'ctrip',
                'collected_at' => null,
                'fetch_time' => null,
                'create_time' => '2026-07-15 10:00:00',
                'deleted_at' => null,
            ],
            [
                'store_id' => 88,
                'hotel_id' => 17,
                'platform' => 'ctrip',
                'collected_at' => '2026-07-17 11:00:00',
                'fetch_time' => null,
                'create_time' => '2026-07-17 11:01:00',
                'deleted_at' => null,
            ],
            [
                'store_id' => 17,
                'hotel_id' => 904,
                'platform' => 'meituan',
                'collected_at' => '2026-07-17 12:00:00',
                'fetch_time' => null,
                'create_time' => '2026-07-17 12:01:00',
                'deleted_at' => null,
            ],
            [
                'store_id' => 17,
                'hotel_id' => 905,
                'platform' => 'ctrip',
                'collected_at' => '2026-07-10 23:59:59',
                'fetch_time' => null,
                'create_time' => '2026-07-10 23:59:59',
                'deleted_at' => null,
            ],
            [
                'store_id' => 17,
                'hotel_id' => 906,
                'platform' => 'ctrip',
                'collected_at' => '2026-07-17 13:00:00',
                'fetch_time' => null,
                'create_time' => '2026-07-17 13:01:00',
                'deleted_at' => '2026-07-17 13:02:00',
            ],
            [
                'store_id' => 17,
                'hotel_id' => 907,
                'platform' => 'ctrip',
                'collected_at' => '2026-07-17 14:00:00',
                'fetch_time' => null,
                'create_time' => '2026-07-17 14:01:00',
                'readback_verified' => 0,
                'validation_status' => 'unverified',
                'comparison_key' => '',
                'deleted_at' => null,
            ],
        ]);
        Db::name('competitor_price_log')->insertAll($rows);

        self::assertSame(
            3,
            \ctrip_input_readiness_competitor_price_log_count(
                17,
                '2026-07-11',
                '2026-07-17',
                [
                    'store_id' => true,
                    'hotel_id' => true,
                    'platform' => true,
                    'collected_at' => true,
                    'fetch_time' => true,
                    'create_time' => true,
                    'readback_verified' => true,
                    'validation_status' => true,
                    'comparison_key' => true,
                    'deleted_at' => true,
                ]
            )
        );
    }

    public function testCompetitorAnalysisExcludesUnverifiedSamplesFromReadiness(): void
    {
        Db::name('competitor_analysis')->insertAll([
            [
                'hotel_id' => 17,
                'ota_platform' => 'ctrip',
                'analysis_date' => '2026-07-17',
                'readback_verified' => 1,
                'validation_status' => 'valid',
                'comparison_key' => str_repeat('a', 64),
                'deleted_at' => null,
            ],
            [
                'hotel_id' => 17,
                'ota_platform' => 'ctrip',
                'analysis_date' => '2026-07-17',
                'readback_verified' => 0,
                'validation_status' => 'valid',
                'comparison_key' => str_repeat('b', 64),
                'deleted_at' => null,
            ],
            [
                'hotel_id' => 17,
                'ota_platform' => 'ctrip',
                'analysis_date' => '2026-07-17',
                'readback_verified' => 1,
                'validation_status' => 'unverified',
                'comparison_key' => str_repeat('c', 64),
                'deleted_at' => null,
            ],
            [
                'hotel_id' => 17,
                'ota_platform' => 'ctrip',
                'analysis_date' => '2026-07-17',
                'readback_verified' => 1,
                'validation_status' => 'valid',
                'comparison_key' => '',
                'deleted_at' => null,
            ],
        ]);

        self::assertSame(
            1,
            \ctrip_input_readiness_competitor_analysis_count(
                17,
                '2026-07-11',
                '2026-07-17',
                [
                    'hotel_id' => true,
                    'ota_platform' => true,
                    'analysis_date' => true,
                    'readback_verified' => true,
                    'validation_status' => true,
                    'comparison_key' => true,
                    'deleted_at' => true,
                ]
            )
        );
    }

    public function testCompetitorAnalysisWithoutEvidenceColumnsCannotSatisfyReadiness(): void
    {
        self::assertSame(
            0,
            \ctrip_input_readiness_competitor_analysis_count(
                17,
                '2026-07-11',
                '2026-07-17',
                [
                    'hotel_id' => true,
                    'ota_platform' => true,
                    'analysis_date' => true,
                ]
            )
        );
    }
}
