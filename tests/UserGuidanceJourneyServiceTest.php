<?php
declare(strict_types=1);

namespace Tests;

use app\service\UserGuidanceJourneyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class UserGuidanceJourneyServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'user_guidance_journey_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        Db::execute('DROP TABLE IF EXISTS user_guidance_journeys');
        Db::execute(
            'CREATE TABLE user_guidance_journeys ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL DEFAULT 0, journey_key TEXT NOT NULL, version_no INTEGER NOT NULL, goal TEXT NOT NULL, '
            . 'original_query_digest TEXT NOT NULL, active_key TEXT NOT NULL, journey_keys_json TEXT NOT NULL, '
            . 'current_step_status TEXT NOT NULL, blocker_code TEXT NOT NULL, blocker_summary TEXT NOT NULL, '
            . 'lifecycle_status TEXT NOT NULL, content_digest TEXT NOT NULL, previous_journey_id INTEGER NULL, '
            . 'recorded_by INTEGER NOT NULL, created_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id,user_id,hotel_id,journey_key,version_no))'
        );
    }

    public function testSavesExactReadbackAndVersionsTheSameJourney(): void
    {
        $service = new UserGuidanceJourneyService();
        $first = $service->save(10, 7, 80, $this->journey(), 7);
        self::assertTrue($first['created']);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertSame(['data-health', 'ai-daily-report'], $first['journey']['journey_keys']);
        self::assertFalse($first['write_boundaries']['ota_write']);

        $same = $service->save(10, 7, 80, $this->journey(), 7);
        self::assertFalse($same['created']);
        self::assertSame($first['journey']['id'], $same['journey']['id']);

        $changed = $this->journey();
        $changed['active_key'] = 'ai-daily-report';
        $changed['current_step_status'] = 'in_progress';
        $versioned = $service->save(10, 7, 80, $changed, 7);
        self::assertTrue($versioned['created']);
        self::assertSame(2, $versioned['journey']['version_no']);
        self::assertSame($first['journey']['id'], $versioned['journey']['previous_journey_id']);
        self::assertSame('superseded', Db::name(UserGuidanceJourneyService::TABLE)
            ->where('id', $first['journey']['id'])->value('lifecycle_status'));

        $active = $service->readActive(10, 7, 80);
        self::assertSame('ready', $active['data_status']);
        self::assertSame('ai-daily-report', $active['journey']['active_key']);
    }

    public function testHotelUserAndTenantScopesNeverBleed(): void
    {
        $service = new UserGuidanceJourneyService();
        $service->save(10, 7, 80, $this->journey(), 7);

        self::assertSame('empty', $service->readActive(10, 8, 80)['data_status']);
        self::assertSame('empty', $service->readActive(11, 7, 80)['data_status']);
        self::assertSame('empty', $service->readActive(10, 7, 81)['data_status']);
    }

    public function testHotelJourneyOverridesGlobalJourneyWithoutHidingGlobalFallback(): void
    {
        $service = new UserGuidanceJourneyService();
        $global = $this->journey();
        $global['goal'] = '保持每天只处理一条经营主线';
        $global['journey_keys'] = ['daily-workbench'];
        $global['active_key'] = 'daily-workbench';
        $service->save(10, 7, null, $global, 7);

        self::assertSame(
            '保持每天只处理一条经营主线',
            $service->readActive(10, 7, 80)['journey']['goal']
        );

        $service->save(10, 7, 80, $this->journey(), 7);
        self::assertSame(
            '恢复携程数据后生成经营日报',
            $service->readActive(10, 7, 80)['journey']['goal']
        );
        self::assertSame(
            '保持每天只处理一条经营主线',
            $service->readActive(10, 7, 81)['journey']['goal']
        );
    }

    public function testClientJourneyKeyCannotJoinGlobalAndHotelVersionChains(): void
    {
        $service = new UserGuidanceJourneyService();
        $sharedKey = str_repeat('a', 64);
        $global = $this->journey();
        $global['journey_key'] = $sharedKey;
        $global['goal'] = '全局任务';
        $hotel = $this->journey();
        $hotel['journey_key'] = $sharedKey;
        $hotel['goal'] = '酒店任务';

        $globalSaved = $service->save(10, 7, null, $global, 7);
        $hotelSaved = $service->save(10, 7, 80, $hotel, 7);

        self::assertSame(1, $globalSaved['journey']['version_no']);
        self::assertSame(1, $hotelSaved['journey']['version_no']);
        self::assertNull($hotelSaved['journey']['previous_journey_id']);
        self::assertSame('全局任务', $service->readActive(10, 7, 81)['journey']['goal']);
        self::assertSame('酒店任务', $service->readActive(10, 7, 80)['journey']['goal']);
    }

    public function testReadbackRecomputesDigestAndRejectsStoredFieldDrift(): void
    {
        $service = new UserGuidanceJourneyService();
        $saved = $service->save(10, 7, 80, $this->journey(), 7);
        Db::name(UserGuidanceJourneyService::TABLE)
            ->where('id', $saved['journey']['id'])
            ->update(['goal' => '被篡改的任务目标']);

        $this->expectException(RuntimeException::class);
        $service->readActive(10, 7, 80);
    }

    public function testArchiveCreatesAReadbackVersionAndStopsContinuation(): void
    {
        $service = new UserGuidanceJourneyService();
        $service->save(10, 7, 80, $this->journey(), 7);
        $archived = $service->archiveActive(10, 7, 80, 7);

        self::assertSame('archived', $archived['journey']['lifecycle_status']);
        self::assertSame(2, $archived['journey']['version_no']);
        self::assertSame('empty', $service->readActive(10, 7, 80)['data_status']);
    }

    public function testResumeCardTransitionsByExactIdAndDigestWithoutClaimingBusinessCompletion(): void
    {
        $service = new UserGuidanceJourneyService();
        $saved = $service->save(10, 7, 80, $this->journey(), 7);
        $card = $service->readResumeCard(10, 7, 80);

        self::assertSame('ready', $card['data_status']);
        self::assertSame($saved['journey']['id'], $card['card']['journey_id']);
        self::assertSame('data-health', $card['card']['next_step']['topic_key']);
        self::assertSame('blocked', $card['card']['next_step']['status']);
        self::assertArrayNotHasKey('original_query_digest', $card['card']);
        self::assertFalse($card['boundaries']['business_completion_claimed']);

        $completed = $service->transitionExact(
            10,
            7,
            80,
            (int)$card['card']['journey_id'],
            (string)$card['card']['content_digest'],
            'complete',
            7
        );
        self::assertSame('exact_readback_verified', $completed['status']);
        self::assertSame('completed', $completed['journey']['lifecycle_status']);
        self::assertSame('completed', $completed['journey']['current_step_status']);
        self::assertFalse($completed['boundaries']['business_completion_claimed']);
        self::assertSame('empty', $service->readResumeCard(10, 7, 80)['data_status']);

        $replay = $service->transitionExact(
            10,
            7,
            80,
            (int)$card['card']['journey_id'],
            (string)$card['card']['content_digest'],
            'complete',
            7
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($completed['journey']['id'], $replay['journey']['id']);

        try {
            $service->transitionExact(
                10,
                7,
                80,
                (int)$card['card']['journey_id'],
                (string)$card['card']['content_digest'],
                'ignore',
                7
            );
            self::fail('A completed resume card cannot be replayed as ignored.');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
            self::assertSame('journey_transition_conflict', $error->getMessage());
        }
    }

    public function testGlobalResumeFallbackIsTransitionedInItsOriginalScope(): void
    {
        $service = new UserGuidanceJourneyService();
        $global = $this->journey();
        $global['goal'] = '每天继续一个经营主线';
        $global['journey_keys'] = ['daily-workbench'];
        $global['active_key'] = 'daily-workbench';
        $service->save(10, 7, null, $global, 7);

        $card = $service->readResumeCard(10, 7, 81);
        self::assertSame('global', $card['scope']['type']);
        self::assertNull($card['scope']['hotel_id']);
        $ignored = $service->transitionExact(
            10,
            7,
            81,
            (int)$card['card']['journey_id'],
            (string)$card['card']['content_digest'],
            'ignore',
            7
        );

        self::assertSame('archived', $ignored['journey']['lifecycle_status']);
        self::assertNull($ignored['journey']['hotel_id']);
        self::assertSame(
            0,
            (int)Db::name(UserGuidanceJourneyService::TABLE)->where('hotel_id', 81)->count()
        );
        self::assertSame('empty', $service->readResumeCard(10, 7, 81)['data_status']);
    }

    public function testSensitiveCredentialMaterialAndForeignRecorderAreRejected(): void
    {
        $service = new UserGuidanceJourneyService();
        $sensitive = $this->journey();
        $sensitive['original_query'] = 'cookie=very-sensitive-session-value';

        $this->expectException(InvalidArgumentException::class);
        $service->save(10, 7, 80, $sensitive, 7);
    }

    public function testForeignRecorderCannotChangeJourney(): void
    {
        $this->expectException(RuntimeException::class);
        (new UserGuidanceJourneyService())->save(10, 7, 80, $this->journey(), 8);
    }

    public function testMissingTableReturnsMigrationRequiredOnRead(): void
    {
        Db::execute('DROP TABLE user_guidance_journeys');
        $result = (new UserGuidanceJourneyService())->readActive(10, 7, 80);
        self::assertSame('migration_required', $result['data_status']);
        self::assertSame('user_guidance_journey_table_missing', $result['reason_code']);
    }

    /** @return array<string,mixed> */
    private function journey(): array
    {
        return [
            'goal' => '恢复携程数据后生成经营日报',
            'original_query' => '继续昨天的数据修复任务',
            'active_key' => 'data-health',
            'journey_keys' => ['data-health', 'ai-daily-report'],
            'current_step_status' => 'blocked',
            'blocker_code' => 'ctrip_readback_missing',
            'blocker_summary' => '携程目标日期数据尚未完成精确回读',
        ];
    }
}
