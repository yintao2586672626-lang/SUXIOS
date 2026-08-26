<?php
declare(strict_types=1);

namespace Tests;

use app\model\SystemConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class SystemConfigPaletteAcceptanceTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'palette_acceptance_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
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
        Db::execute(<<<'SQL'
CREATE TABLE system_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    config_key VARCHAR(191) NOT NULL UNIQUE,
    config_value TEXT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    create_time INTEGER NULL,
    update_time INTEGER NULL
)
SQL);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    public function testCandidateRegistryIsIncludedInDefaultsAndDisplayGroup(): void
    {
        self::assertSame(
            'suxios_anchor',
            SystemConfig::getDefaultConfigs()[SystemConfig::KEY_PALETTE_ACCEPTANCE_CANDIDATE]
        );
        self::assertContains(
            SystemConfig::KEY_PALETTE_ACCEPTANCE_CANDIDATE,
            SystemConfig::getConfigGroups()['display']['keys']
        );
        self::assertSame(
            [
                'suxios_anchor',
                'editorial_coral_cyan',
                'boardroom_navy_gold',
                'night_signal',
                'data_neutral',
                'training_warm',
            ],
            array_keys(SystemConfig::PALETTE_ACCEPTANCE_CANDIDATES)
        );
    }

    public function testCandidateNormalizationAcceptsOnlyTheRegisteredIds(): void
    {
        self::assertSame(
            'boardroom_navy_gold',
            SystemConfig::normalizePaletteAcceptanceCandidate('  BOARDROOM_NAVY_GOLD  ')
        );
    }

    public function testUnknownCandidateIsRejectedInsteadOfFallingBack(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('候选配色无效');

        SystemConfig::normalizePaletteAcceptanceCandidate('approved_or_deployed');
    }

    public function testNonStringCandidateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SystemConfig::normalizePaletteAcceptanceCandidate(['training_warm']);
    }

    public function testCandidatePersistsAndReadsBackExactlyInsideARolledBackTransaction(): void
    {
        $key = SystemConfig::KEY_PALETTE_ACCEPTANCE_CANDIDATE;
        SystemConfig::clearValueCaches([$key]);
        $before = SystemConfig::getValue($key, null);

        Db::startTrans();
        try {
            self::assertTrue(SystemConfig::setValue($key, 'training_warm', '候选配色验收测试'));
            SystemConfig::clearValueCaches([$key]);
            self::assertSame('training_warm', SystemConfig::getValue($key));
        } finally {
            Db::rollback();
            SystemConfig::clearValueCaches([$key]);
        }

        self::assertSame($before, SystemConfig::getValue($key, null));
    }
}
