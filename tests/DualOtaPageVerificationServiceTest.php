<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\DualOtaPageVerificationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class DualOtaPageVerificationServiceTest extends TestCase
{
    private static App $app;
    private static array $databaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'dual_ota_page_verification_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);

        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, role_id INTEGER, username TEXT)');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            user_id INTEGER,
            hotel_id INTEGER,
            module TEXT,
            action TEXT,
            description TEXT,
            ip TEXT,
            user_agent TEXT,
            create_time TEXT,
            error_info TEXT,
            extra_data TEXT
        )');
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 12, 'name' => 'Hotel 80', 'status' => 1]);
        Db::name('users')->insert(['id' => 7, 'tenant_id' => 12, 'role_id' => 3, 'username' => 'operator']);
        Db::name('users')->insert(['id' => 1, 'tenant_id' => 7, 'role_id' => Role::SUPER_ADMIN, 'username' => 'admin']);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        self::$app->instance('request', new Request());
        Db::name('operation_logs')->delete(true);
    }

    public function testCanonicalContractIsStableAcrossPlatformAndFieldOrder(): void
    {
        $trust = self::trustFixture();
        $first = DualOtaPageVerificationService::canonicalContract($trust, 12, 80, '2026-08-08');

        $reordered = $trust;
        $reordered['days'][0]['platforms'] = array_reverse($reordered['days'][0]['platforms']);
        foreach ($reordered['days'][0]['platforms'] as &$platform) {
            $platform['acceptance_receipt']['critical_fields']['complete'] = array_reverse(
                $platform['acceptance_receipt']['critical_fields']['complete']
            );
        }
        unset($platform);
        $second = DualOtaPageVerificationService::canonicalContract($reordered, 12, 80, '2026-08-08');

        self::assertSame(
            DualOtaPageVerificationService::contractHash($first),
            DualOtaPageVerificationService::contractHash($second)
        );
        self::assertSame(['ctrip', 'meituan'], array_column($second['platforms'], 'platform'));
    }

    public function testExactTaskSourceOrDisplayedFactDriftChangesHash(): void
    {
        $trust = self::trustFixture();
        $base = self::hash($trust);

        foreach ([
            ['data_source_id', 26],
            ['sync_task_id', 4001],
            ['captured_at', '2026-08-09 11:00:00'],
        ] as [$field, $value]) {
            $changed = $trust;
            $changed['days'][0]['platforms'][0]['acceptance_receipt'][$field] = $value;
            self::assertNotSame($base, self::hash($changed), $field . ' must invalidate the page contract');
        }

        $changed = $trust;
        $changed['days'][0]['platforms'][1]['acceptance_receipt']['counts']['target_readback'] = 5;
        self::assertNotSame($base, self::hash($changed));

        $changed = $trust;
        $changed['days'][0]['platforms'][1]['acceptance_receipt']['critical_fields']['missing'] = ['flow_rate'];
        self::assertNotSame($base, self::hash($changed));
    }

    public function testExactEvidenceAttachesWithoutPromotingOtaTruth(): void
    {
        $trust = self::trustFixture();
        $contract = DualOtaPageVerificationService::canonicalContract($trust, 12, 80, '2026-08-08');
        $hash = DualOtaPageVerificationService::contractHash($contract);
        $row = self::evidenceRow($contract, $hash);

        $attached = DualOtaPageVerificationService::attachEvidenceRows($trust, 12, 80, [$row]);

        self::assertSame('verified', $attached['page_verification']['status']);
        self::assertSame(91, $attached['page_verification']['receipt_id']);
        self::assertSame('verified', $attached['acceptance_status']);
        self::assertSame(1, $attached['accepted_days']);
        foreach ($attached['days'][0]['platforms'] as $platform) {
            self::assertTrue($platform['acceptance_receipt']['claim_allowed']);
            self::assertSame('verified', $platform['acceptance_receipt']['live_page_verification_status']);
            self::assertSame('verified', $platform['page_status_evidence']['live_page_verification_status']);
        }
    }

    public function testSameDateOldContractIsUnverifiedAndStale(): void
    {
        $trust = self::trustFixture();
        $old = $trust;
        $old['days'][0]['platforms'][0]['acceptance_receipt']['sync_task_id'] = 3092;
        $oldContract = DualOtaPageVerificationService::canonicalContract($old, 12, 80, '2026-08-08');
        $row = self::evidenceRow($oldContract, DualOtaPageVerificationService::contractHash($oldContract));

        $attached = DualOtaPageVerificationService::attachEvidenceRows($trust, 12, 80, [$row]);

        self::assertSame('unverified', $attached['page_verification']['status']);
        self::assertSame('stale_page_confirmation', $attached['page_verification']['reason']);
        self::assertSame('verified', $attached['acceptance_status']);
    }

    public function testInvalidEvidenceFailsClosedAndAnotherTenantIsIgnored(): void
    {
        $trust = self::trustFixture();
        $invalid = self::evidenceRow([], str_repeat('a', 64));
        $invalid['extra_data'] = '{broken';
        $invalidAttached = DualOtaPageVerificationService::attachEvidenceRows($trust, 12, 80, [$invalid]);
        self::assertSame('unverified', $invalidAttached['page_verification']['status']);
        self::assertSame('invalid_page_confirmation_evidence', $invalidAttached['page_verification']['reason']);

        $contract = DualOtaPageVerificationService::canonicalContract($trust, 13, 80, '2026-08-08');
        $otherTenant = self::evidenceRow($contract, DualOtaPageVerificationService::contractHash($contract));
        $otherTenant['tenant_id'] = 13;
        $attached = DualOtaPageVerificationService::attachEvidenceRows($trust, 12, 80, [$otherTenant]);
        self::assertSame('not_evaluated', $attached['page_verification']['status']);
    }

    public function testContractExcludesRawAndCredentialMaterial(): void
    {
        $trust = self::trustFixture();
        $trust['days'][0]['platforms'][0]['acceptance_receipt']['raw_data'] = ['cookie' => 'sentinel-cookie'];
        $trust['days'][0]['platforms'][0]['acceptance_receipt']['profile_path'] = 'sentinel-profile';
        $json = json_encode(
            DualOtaPageVerificationService::canonicalContract($trust, 12, 80, '2026-08-08'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        self::assertIsString($json);
        self::assertStringNotContainsString('sentinel-cookie', $json);
        self::assertStringNotContainsString('sentinel-profile', $json);
        self::assertStringNotContainsString('raw_data', $json);
    }

    public function testConfirmIsIdempotentAndExactlyReadableFromOperationLog(): void
    {
        $trust = self::trustFixture();
        $request = self::confirmationRequest($trust);
        $service = new DualOtaPageVerificationService();

        $first = $service->confirm($trust, 12, 80, 7, array_merge($request, [
            'cookie' => 'sentinel-cookie-that-must-not-be-saved',
        ]));
        $second = $service->confirm($trust, 12, 80, 7, $request);

        self::assertTrue($first['readback_verified']);
        self::assertSame($first['receipt_id'], $second['receipt_id']);
        self::assertSame(1, (int)Db::name('operation_logs')->count());
        $stored = Db::name('operation_logs')->where('id', (int)$first['receipt_id'])->find();
        self::assertIsArray($stored);
        self::assertSame(12, (int)$stored['tenant_id']);
        self::assertSame(80, (int)$stored['hotel_id']);
        self::assertStringNotContainsString('sentinel-cookie', (string)$stored['extra_data']);
        self::assertStringContainsString($request['contract_hash'], (string)$stored['extra_data']);

        $attached = $service->attach($trust, 12, 80);
        self::assertSame('verified', $attached['page_verification']['status']);
        self::assertSame($first['receipt_id'], $attached['page_verification']['receipt_id']);
    }

    public function testCrossTenantSuperAdminConfirmationUsesAuthoritativeHotelTenant(): void
    {
        $actor = new User();
        $actor->id = 1;
        $actor->tenant_id = 7;
        $actor->role_id = Role::SUPER_ADMIN;
        $request = new Request();
        $request->user = $actor;
        self::$app->instance('request', $request);

        $trust = self::trustFixture();
        $receipt = (new DualOtaPageVerificationService())->confirm(
            $trust,
            12,
            80,
            1,
            self::confirmationRequest($trust)
        );

        self::assertTrue($receipt['readback_verified']);
        $stored = Db::name('operation_logs')->where('id', (int)$receipt['receipt_id'])->find();
        self::assertIsArray($stored);
        self::assertSame(12, (int)$stored['tenant_id']);
        self::assertSame(1, (int)$stored['user_id']);
        self::assertSame(80, (int)$stored['hotel_id']);
    }

    public function testCrossTenantOrdinaryActorCannotUseSuperAdminAuditException(): void
    {
        $actor = new User();
        $actor->id = 1;
        $actor->tenant_id = 7;
        $actor->role_id = Role::NORMAL_USER;
        $request = new Request();
        $request->user = $actor;
        self::$app->instance('request', $request);

        $trust = self::trustFixture();
        try {
            (new DualOtaPageVerificationService())->confirm(
                $trust,
                12,
                80,
                1,
                self::confirmationRequest($trust)
            );
            self::fail('Expected the cross-tenant ordinary actor to be rejected by exact readback.');
        } catch (\RuntimeException $e) {
            self::assertSame(500, $e->getCode());
        }

        self::assertSame(0, (int)Db::name('operation_logs')->count());
    }

    public function testUnavailableEvidenceStoreIsExplicitlyUnverifiedWithoutChangingOtaTruth(): void
    {
        $trust = self::trustFixture();
        Db::execute('ALTER TABLE operation_logs RENAME TO operation_logs_unavailable');
        try {
            $attached = (new DualOtaPageVerificationService())->attach($trust, 12, 80);
        } finally {
            Db::execute('ALTER TABLE operation_logs_unavailable RENAME TO operation_logs');
        }

        self::assertSame('unverified', $attached['page_verification']['status']);
        self::assertSame('page_confirmation_evidence_unavailable', $attached['page_verification']['reason']);
        self::assertSame('verified', $attached['acceptance_status']);
        self::assertSame(1, $attached['accepted_days']);
        foreach ($attached['days'][0]['platforms'] as $platform) {
            self::assertTrue($platform['acceptance_receipt']['claim_allowed']);
            self::assertSame(
                'unverified',
                $platform['acceptance_receipt']['live_page_verification_status']
            );
        }
    }

    public function testClientHashOrTaskDriftReturnsConflictWithoutWriting(): void
    {
        $trust = self::trustFixture();
        $request = self::confirmationRequest($trust);
        $request['platforms'][0]['sync_task_id'] = 9999;

        try {
            (new DualOtaPageVerificationService())->confirm($trust, 12, 80, 7, $request);
            self::fail('Expected exact task drift to be rejected.');
        } catch (\RuntimeException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertSame(0, (int)Db::name('operation_logs')->count());
    }

    /** @param array<string, mixed> $trust */
    private static function hash(array $trust): string
    {
        return DualOtaPageVerificationService::contractHash(
            DualOtaPageVerificationService::canonicalContract($trust, 12, 80, '2026-08-08')
        );
    }

    /**
     * @param array<string, mixed> $trust
     * @return array<string, mixed>
     */
    private static function confirmationRequest(array $trust): array
    {
        $contract = DualOtaPageVerificationService::canonicalContract($trust, 12, 80, '2026-08-08');
        return [
            'system_hotel_id' => 80,
            'target_date' => '2026-08-08',
            'contract_hash' => DualOtaPageVerificationService::contractHash($contract),
            'platforms' => array_map(static fn(array $row): array => [
                'platform' => $row['platform'],
                'data_source_id' => $row['data_source_id'],
                'sync_task_id' => $row['sync_task_id'],
            ], $contract['platforms']),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private static function evidenceRow(array $contract, string $hash): array
    {
        return [
            'id' => 91,
            'tenant_id' => 12,
            'user_id' => 7,
            'hotel_id' => 80,
            'module' => DualOtaPageVerificationService::MODULE,
            'action' => DualOtaPageVerificationService::ACTION,
            'description' => 'dual_ota_page:v1:2026-08-08:' . $hash,
            'create_time' => '2026-08-09 10:30:00',
            'extra_data' => json_encode([
                'contract_version' => DualOtaPageVerificationService::CONTRACT_VERSION,
                'contract_hash' => $hash,
                'tenant_id' => 12,
                'hotel_id' => 80,
                'target_date' => '2026-08-08',
                'contract' => $contract,
                'outcome' => 'success',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array<string, mixed> */
    private static function trustFixture(): array
    {
        $platform = static function (
            string $name,
            string $platformHotelId,
            int $sourceId,
            int $taskId,
            array $fields
        ): array {
            return [
                'platform' => $name,
                'status' => 'verified',
                'acceptance_status' => 'verified',
                'p0_status' => 'ready',
                'page_status_evidence' => [
                    'status' => 'ready',
                    'live_page_verification_status' => 'not_evaluated',
                ],
                'acceptance_receipt' => [
                    'status' => 'verified',
                    'system_hotel_id' => 80,
                    'platform_hotel_id' => $platformHotelId,
                    'platform_hotel_status' => 'verified',
                    'target_date' => '2026-08-08',
                    'observed_target_date' => '2026-08-08',
                    'target_date_status' => 'matched',
                    'captured_at' => '2026-08-09 10:08:35',
                    'source_method' => 'browser_profile',
                    'capture_strategy' => [
                        'selected' => 'browser_response',
                        'status' => 'verified',
                        'response_evidence_type' => 'structured_json',
                    ],
                    'data_source_id' => $sourceId,
                    'sync_task_id' => $taskId,
                    'sync_task_status' => 'success',
                    'data_period' => 'historical_daily',
                    'counts' => [
                        'saved' => $name === 'ctrip' ? 12 : 133,
                        'readback' => $name === 'ctrip' ? 12 : 133,
                        'saved_readback_match' => true,
                        'target_saved' => $name === 'ctrip' ? 12 : 6,
                        'target_readback' => $name === 'ctrip' ? 12 : 6,
                        'target_saved_readback_match' => true,
                    ],
                    'critical_fields' => [
                        'complete' => $fields,
                        'missing' => [],
                        'status' => 'verified',
                    ],
                    'claim_allowed' => true,
                    'reason_codes' => [],
                    'live_page_verification_status' => 'not_evaluated',
                ],
            ];
        };

        return [
            'hotel_id' => 80,
            'hotel_name' => 'Hotel 80',
            'start_date' => '2026-08-08',
            'end_date' => '2026-08-08',
            'status' => 'verified',
            'acceptance_status' => 'verified',
            'accepted_days' => 1,
            'consecutive_accepted_days' => 1,
            'days' => [[
                'date' => '2026-08-08',
                'status' => 'verified',
                'acceptance_status' => 'verified',
                'platforms' => [
                    $platform('ctrip', '130079194', 25, 3094, [
                        'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
                    ]),
                    $platform('meituan', '1029642156589279', 68, 3093, [
                        'list_exposure', 'detail_exposure', 'flow_rate',
                    ]),
                ],
            ]],
        ];
    }
}
