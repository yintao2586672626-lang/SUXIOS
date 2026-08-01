<?php
declare(strict_types=1);

namespace Tests;

use app\model\PriceSuggestion;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

require_once dirname(__DIR__) . '/scripts/execute_revenue_ai_ctrip_review_decision.php';

final class PriceSuggestionCliTenantScopeTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private string $decisionFile = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'price_suggestion_cli_tenant_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
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
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$sqlitePath);
        Db::connect(null, true);

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), status INTEGER)');
        Db::execute('CREATE TABLE price_suggestions (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, room_type_id INTEGER, demand_forecast_id INTEGER, suggestion_date DATE, suggestion_type INTEGER, current_price DECIMAL(10,2), suggested_price DECIMAL(10,2), min_price DECIMAL(10,2), max_price DECIMAL(10,2), competitor_data TEXT, factors TEXT, status INTEGER, applied_by INTEGER, remark TEXT, create_time DATETIME, update_time DATETIME)');
        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10, 'name' => 'Tenant 10 Hotel', 'status' => 1]);
        $common = [
            'hotel_id' => 20,
            'room_type_id' => 5,
            'suggestion_date' => '2026-07-22',
            'suggestion_type' => PriceSuggestion::TYPE_DYNAMIC,
            'current_price' => 300,
            'suggested_price' => 320,
            'min_price' => 280,
            'max_price' => 360,
            'competitor_data' => '{}',
            'factors' => json_encode([
                'source_scope' => 'ctrip_ota_channel',
                'source_channels' => ['ctrip'],
            ], JSON_THROW_ON_ERROR),
            'status' => PriceSuggestion::STATUS_PENDING,
        ];
        Db::name('price_suggestions')->insertAll([
            array_merge($common, ['id' => 1, 'tenant_id' => 10]),
            array_merge($common, ['id' => 2, 'tenant_id' => 99]),
        ]);

        $this->decisionFile = (string)tempnam(sys_get_temp_dir(), 'ctrip-review-tenant-');
        file_put_contents($this->decisionFile, json_encode($this->decisionPayload(1), JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if ($this->decisionFile !== '' && is_file($this->decisionFile)) {
            unlink($this->decisionFile);
        }
    }

    public function testValidSuggestionIsReviewedInsideAuthoritativeTenantAndKeepsLineage(): void
    {
        $result = \ctrip_review_decision_run($this->options(20));
        self::assertSame(0, $result['exit_code']);
        self::assertSame('passed', $result['payload']['status']);
        self::assertSame(10, $result['payload']['scope']['tenant_id']);

        $row = Db::name('price_suggestions')->where('id', 1)->find();
        self::assertSame(10, (int)$row['tenant_id']);
        self::assertSame(20, (int)$row['hotel_id']);
        self::assertSame(PriceSuggestion::STATUS_REJECTED, (int)$row['status']);
    }

    public function testWrongRequestedHotelAndMissingIdCannotWrite(): void
    {
        $wrongHotel = \ctrip_review_decision_run($this->options(21));
        self::assertSame(1, $wrongHotel['exit_code']);
        self::assertSame(PriceSuggestion::STATUS_PENDING, (int)Db::name('price_suggestions')->where('id', 1)->value('status'));

        file_put_contents($this->decisionFile, json_encode($this->decisionPayload(999), JSON_THROW_ON_ERROR));
        $missing = \ctrip_review_decision_run($this->options(20));
        self::assertSame(1, $missing['exit_code']);
        self::assertSame(2, Db::name('price_suggestions')->count());
    }

    public function testPollutedSuggestionTenantIsRejectedBeforeAnyWrite(): void
    {
        file_put_contents($this->decisionFile, json_encode($this->decisionPayload(2), JSON_THROW_ON_ERROR));
        try {
            \ctrip_review_decision_run($this->options(20));
            self::fail('A suggestion whose tenant does not match its hotel must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
        }

        $row = Db::name('price_suggestions')->where('id', 2)->find();
        self::assertSame(99, (int)$row['tenant_id']);
        self::assertSame(PriceSuggestion::STATUS_PENDING, (int)$row['status']);
        self::assertNull($row['applied_by']);
    }

    /** @return array<string, mixed> */
    private function options(int $hotelId): array
    {
        return [
            'file' => $this->decisionFile,
            'date' => '2026-07-22',
            'hotel_id' => $hotelId,
            'execute' => true,
            'create_intent' => false,
            'user_id' => 9,
            'print_template' => false,
            'output' => '',
            'force' => false,
            'suggestion_id' => 0,
            'manage_transaction' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function decisionPayload(int $suggestionId): array
    {
        return [
            'business_date' => '2026-07-22',
            'hotel_id' => 20,
            'platform' => 'ctrip',
            'source_scope' => 'ctrip_ota_channel',
            'evidence_status' => 'verifier_transaction_only',
            'auto_write_ota' => false,
            'review_decision' => [
                'suggestion_id' => $suggestionId,
                'action' => 'reject',
                'remark' => 'tenant scoped manual review rejection',
                'operator_review_evidence' => [
                    'reviewed_by' => 'tenant scope test operator',
                    'reviewed_at' => '2026-07-22 10:00:00',
                    'decision_basis' => 'tenant scope test decision evidence',
                    'price_guard_source' => 'tenant scope test price guard',
                    'operation_intent_source' => 'tenant scope test operation decision',
                ],
                'create_execution_intent_after_approval' => false,
            ],
        ];
    }
}
