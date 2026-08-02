<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\CollectionReliabilityConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CollectionReliabilityHotelPortraitContractTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'collection_reliability_hotel_portrait_' . getmypid() . '.sqlite';
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
        Db::execute(<<<'SQL'
CREATE TABLE hotels (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    status INTEGER NOT NULL,
    create_time TEXT DEFAULT NULL,
    update_time TEXT DEFAULT NULL
)
SQL);
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
            throw new RuntimeException('Unable to remove collection reliability SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 7, 'name' => 'Enabled hotel', 'status' => 1],
            ['id' => 8, 'name' => 'Disabled hotel', 'status' => 0],
        ]);
    }

    public function testPortraitTargetDoesNotSynthesizeMissingOrDisabledHotels(): void
    {
        $subject = $this->subject();

        self::assertSame([
            'status' => 'hotel_not_found',
            'hotel' => null,
        ], $this->resolvePortraitTarget($subject, 999));
        self::assertSame([
            'status' => 'hotel_not_found',
            'hotel' => null,
        ], $this->resolvePortraitTarget($subject, 8));

        $enabled = $this->resolvePortraitTarget($subject, 7);
        self::assertSame('ok', $enabled['status']);
        self::assertSame(7, (int)($enabled['hotel']['id'] ?? 0));
        self::assertSame('Enabled hotel', $enabled['hotel']['name'] ?? null);
    }

    public function testPortraitTargetReportsDatabaseFailureInsteadOfSynthesizingHotelId(): void
    {
        Db::execute('DROP TABLE hotels');

        try {
            self::assertSame([
                'status' => 'request_failed',
                'hotel' => null,
            ], $this->resolvePortraitTarget($this->subject(), 7));
        } finally {
            Db::execute(<<<'SQL'
CREATE TABLE hotels (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    status INTEGER NOT NULL,
    create_time TEXT DEFAULT NULL,
    update_time TEXT DEFAULT NULL
)
SQL);
        }
    }

    private function subject(): object
    {
        return new class {
            use CollectionReliabilityConcern;

            public mixed $currentUser = null;
        };
    }

    /** @return array{status: string, hotel: array<string, mixed>|null} */
    private function resolvePortraitTarget(object $subject, int $hotelId): array
    {
        $method = new ReflectionMethod($subject, 'resolveDashboardHotelPortraitTarget');
        return $method->invoke($subject, $hotelId);
    }
}
