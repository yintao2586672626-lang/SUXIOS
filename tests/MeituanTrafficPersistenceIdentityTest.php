<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDailyDataPersistenceService;
use app\service\TrustedOtaFactRepository;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MeituanTrafficPersistenceIdentityTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testCookieApiTrafficPersistsCanonicalPlatformAndIngestionIdentity(): void
    {
        (new App())->initialize();
        $original = Config::get('database');
        $databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'meituan_traffic_identity_' . getmypid() . '.sqlite';
        @unlink($databasePath);
        $config = $original;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        try {
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
            Db::execute(
                'CREATE TABLE online_daily_data ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, '
                . 'hotel_id TEXT NOT NULL, hotel_name TEXT NOT NULL DEFAULT \'\', '
                . 'data_date TEXT NOT NULL, source TEXT NOT NULL, platform TEXT NOT NULL, '
                . 'ingestion_method TEXT NOT NULL, data_type TEXT NOT NULL, dimension TEXT NOT NULL, '
                . 'list_exposure INTEGER NULL, detail_exposure INTEGER NULL, flow_rate REAL NULL, '
                . 'order_filling_num INTEGER NULL, order_submit_num INTEGER NULL, data_value REAL NULL, '
                . 'validation_status TEXT NULL, validation_flags TEXT NULL, source_trace_id TEXT NULL, '
                . 'raw_data TEXT NULL, persistence_identity_hash TEXT NULL, '
                . 'readback_verified INTEGER NOT NULL DEFAULT 0, readback_verified_at TEXT NULL'
                . ')'
            );
            Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 8]);

            $service = new OnlineDailyDataPersistenceService();
            $response = ['data' => ['list' => [[
                'poiId' => '1029642156589279',
                'poiName' => '宿析测试门店',
                'dataDate' => '2026-08-26',
                'exposure' => 1422,
                'uniqueVisitors' => 206,
                'source_trace_id' => 'meituan:' . str_repeat('a', 64),
            ]]]];

            self::assertSame(1, $service->parseAndSaveTrafficData(
                $response,
                '2026-08-26',
                '2026-08-26',
                'meituan',
                80,
                'meituan',
                '1029642156589279',
                'manual_cookie_api'
            ));

            $row = Db::name('online_daily_data')
                ->where('system_hotel_id', 80)
                ->where('source', 'meituan')
                ->find();
            self::assertIsArray($row);
            self::assertSame('meituan', $row['source']);
            self::assertSame('meituan', $row['platform']);
            self::assertSame('manual_cookie_api', $row['ingestion_method']);
            self::assertSame(80, (int)$row['system_hotel_id']);
            self::assertSame(8, (int)$row['tenant_id']);
            self::assertSame(1, (int)$row['readback_verified']);

            $platformIdentity = new \ReflectionMethod(TrustedOtaFactRepository::class, 'platformIdentity');
            $platformIdentity->setAccessible(true);
            $raw = json_decode((string)$row['raw_data'], true);
            self::assertSame(
                'meituan',
                $platformIdentity->invoke(new TrustedOtaFactRepository(), $row, is_array($raw) ? $raw : [])
            );

            try {
                $service->parseAndSaveTrafficData(
                    $response,
                    '2026-08-26',
                    '2026-08-26',
                    'meituan',
                    80,
                    null,
                    '1029642156589279',
                    null
                );
                self::fail('OTA traffic persistence must reject an incomplete source identity.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('traffic_source_identity_incomplete', $exception->getMessage());
            }
        } finally {
            try {
                Db::connect()->close();
            } catch (\Throwable) {
            }
            Config::set($original, 'database');
            Db::connect(null, true);
            if (is_file($databasePath) && !unlink($databasePath)) {
                throw new RuntimeException('Unable to remove Meituan traffic identity fixture.');
            }
        }
    }
}
