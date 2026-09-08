<?php
declare(strict_types=1);

namespace Tests\Support;

use app\service\OtaLocalCollectorEvidenceStore;
use app\service\OtaLocalCollectorReadbackProofService;
use app\service\OtaLocalCollectorService;
use PDO;
use RuntimeException;
use think\App;
use think\Container;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/** Synthetic-only application, database and evidence root; no project .env is loaded. */
final class OtaLocalCollectorRealImportFixture
{
    public const TENANT_ID = 12;
    public const HOTEL_ID = 101;
    public const BUSINESS_DATE = '2026-09-01';
    public const PLATFORM_HOTEL_ID = 'SYNTHETIC-MT-101';

    public readonly string $root;
    public readonly string $databasePath;
    public readonly OtaLocalCollectorService $service;
    public readonly array $pair;
    public readonly array $task;
    public array $finalizationScopes = [];
    private readonly Container $previousContainer;

    public function __construct()
    {
        $this->previousContainer = Container::getInstance();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxios-real-import-synthetic-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0700, true)) {
            throw new RuntimeException('Cannot create isolated synthetic fixture directory.');
        }
        $this->databasePath = $this->root . DIRECTORY_SEPARATOR . 'fixture.sqlite';
        // Initializing this empty root cannot read the live checkout's .env/config/runtime.
        $app = new App($this->root);
        // ModelService resolves the cache while booting, before database setup.
        // Supply the isolated cache before initialize(), without live config.
        $app->config->set([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $this->root . '/cache/', 'prefix' => 'synthetic_', 'expire' => 0]],
        ], 'cache');
        $app->config->set([
            'default' => 'file',
            'channels' => ['file' => ['type' => 'File', 'path' => $this->root . '/log/']],
        ], 'log');
        try {
            $app->initialize();
        } finally {
            // ThinkPHP installs these handlers during boot; retain PHPUnit's.
            restore_error_handler();
            restore_exception_handler();
        }
        Config::set([
            'default' => 'synthetic_sqlite',
            'connections' => ['synthetic_sqlite' => [
                'type' => 'sqlite', 'database' => $this->databasePath,
                'prefix' => '', 'fields_strict' => false,
            ]],
        ], 'database');
        Config::set([
            'default' => 'file',
            'stores' => ['file' => ['type' => 'File', 'path' => $this->root . '/cache/', 'prefix' => 'synthetic_', 'expire' => 0]],
        ], 'cache');
        Db::connect(null, true);
        $this->createSchema();
        $this->seedActor();
        $evidenceStore = new OtaLocalCollectorEvidenceStore($this->root . '/evidence');
        $app->bind(OtaLocalCollectorReadbackProofService::class, fn() => new OtaLocalCollectorReadbackProofService($evidenceStore));
        $this->service = new OtaLocalCollectorService(
            collectionImporter: null,
            deliveryEvidenceStore: $evidenceStore,
            canonicalHistoryFinalizer: function (array $receipt, int $tenantId, int $hotelId): array {
                // A separate connection proves finalization sees committed business data.
                $reader = new PDO('sqlite:' . $this->databasePath);
                $this->finalizationScopes[] = [
                    'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
                    'target_date' => $receipt['target_date'] ?? null,
                    'committed_row_count' => (int)$reader->query('SELECT COUNT(*) FROM online_daily_data')->fetchColumn(),
                ];
                return [
                    'status' => 'blocked', 'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
                    'target_date' => $receipt['target_date'] ?? null,
                    'canonical_history_complete' => false,
                    'blockers' => ['synthetic_fixture_external_verification_not_run'],
                    'platform_results' => [], 'sensitive_values_exposed' => false,
                ];
            },
        );
        $actor = $this->actor();
        $code = $this->service->createPairCode($actor, ['device_name' => 'SYNTHETIC real import fixture']);
        $this->pair = $this->service->pairDevice(['pair_code' => $code['pair_code'], 'device_platform' => 'windows']);
        $account = $this->service->createAccount($actor, [
            'device_id' => $this->pair['device_id'], 'platform' => 'meituan',
            'account_alias' => 'SYNTHETIC fixture account', 'system_hotel_id' => self::HOTEL_ID,
            'platform_hotel_id' => self::PLATFORM_HOTEL_ID,
        ]);
        Db::name('ota_local_collector_accounts')->where('id', $account['account_id'])->update([
            'status' => 'active', 'session_status' => 'current_session_verified',
            'last_session_verified_at' => date('Y-m-d H:i:s'),
        ]);
        $this->service->createTask($actor, [
            'account_id' => $account['account_id'], 'system_hotel_id' => self::HOTEL_ID,
            'task_type' => 'collect', 'data_date' => self::BUSINESS_DATE,
        ]);
        $next = $this->service->nextTask($this->pair['device_public_id'], $this->pair['device_token']);
        if (!is_array($next['task'] ?? null)) {
            throw new RuntimeException('Synthetic collection task was not leased.');
        }
        $this->task = $next['task'];
    }

    public function businessResult(bool $includeTraffic = false): array
    {
        $common = [
            'system_hotel_id' => self::HOTEL_ID, 'platform' => 'meituan', 'source' => 'meituan',
            'platform_hotel_id' => self::PLATFORM_HOTEL_ID, 'hotel_id' => self::PLATFORM_HOTEL_ID,
            'data_date' => self::BUSINESS_DATE, 'data_period' => 'historical_daily',
            'fixture_label' => 'synthetic_reliability_test',
        ];
        $rows = [$common + [
            'data_type' => 'business', 'order_amount' => 688.5, 'room_nights' => 2,
            'order_count' => 1, 'source_trace_id' => 'synthetic-business-row',
        ]];
        if ($includeTraffic) {
            $rows[] = $common + [
                'data_type' => 'traffic', 'list_exposure' => 120, 'detail_exposure' => 40,
                'flow_rate' => '25%', 'source_trace_id' => 'synthetic-traffic-row',
                '_source_path' => '$.synthetic.traffic', 'source_url_hash' => hash('sha256', 'https://synthetic.invalid/traffic'),
            ];
        }
        return ['success' => true, 'rows' => $rows, 'capture_summary' => [
            'platform_identity_validation' => [
                'status' => 'matched', 'source_validation' => true,
                'validated_identifier' => self::PLATFORM_HOTEL_ID,
            ],
        ]];
    }

    public function envelope(array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $identity = [
            'result_id' => 'synthetic-result-' . bin2hex(random_bytes(12)),
            'result_hash' => hash('sha256', $json), 'attempt' => (int)$this->task['attempt'],
        ];
        $upload = $this->resume($identity);
        return $identity + ['result_json' => $json, 'lease_token' => $upload['lease_token']];
    }

    public function resume(array $identity): array
    {
        return $this->service->resumeResultUpload(
            $this->pair['device_public_id'], $this->pair['device_token'], (int)$this->task['id'], $identity,
        );
    }

    public function submit(array $envelope): array
    {
        return $this->service->submitTaskResult(
            $this->pair['device_public_id'], $this->pair['device_token'], (int)$this->task['id'], $envelope,
            strlen(json_encode($envelope, JSON_THROW_ON_ERROR)),
        );
    }

    public function close(): void
    {
        Cache::clear();
        Db::connect()->close();
        Container::setInstance($this->previousContainer);
        // Only this explicitly created temporary directory is removed.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function actor(): object
    {
        return new class {
            public int $id = 7;
            public int $tenant_id = OtaLocalCollectorRealImportFixture::TENANT_ID;
            public function getPermittedHotelIds(): array { return [OtaLocalCollectorRealImportFixture::HOTEL_ID]; }
            public function isSuperAdmin(): bool { return false; }
        };
    }

    private function seedActor(): void
    {
        Db::name('roles')->insert(['id' => 2, 'name' => 'beta_user', 'status' => 1, 'level' => 2, 'permissions' => '["ota.view","ota.collect"]']);
        Db::name('users')->insert(['id' => 7, 'tenant_id' => self::TENANT_ID, 'username' => 'synthetic-owner', 'password' => 'synthetic-unusable', 'status' => 1, 'role_id' => 2]);
        Db::name('hotels')->insert(['id' => self::HOTEL_ID, 'tenant_id' => self::TENANT_ID, 'name' => 'SYNTHETIC Hotel', 'status' => 1]);
        Db::name('user_hotel_permissions')->insert(['tenant_id' => self::TENANT_ID, 'user_id' => 7, 'hotel_id' => self::HOTEL_ID, 'status' => 'active', 'can_view' => 1, 'can_fetch_online_data' => 1, 'expires_at' => null]);
    }

    private function createSchema(): void
    {
        // Collector tables mirror OtaLocalCollectorServiceTest; sync tables add the
        // actual fields required by production normalization, persistence and logs.
        $tables = [
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, status INTEGER NOT NULL, role_id INTEGER',
            'roles' => 'id INTEGER PRIMARY KEY, name TEXT, status INTEGER NOT NULL, level INTEGER, permissions TEXT',
            'hotels' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL',
            'user_hotel_permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, status TEXT NOT NULL, can_view INTEGER, can_fetch_online_data INTEGER, expires_at TEXT',
            'ota_local_collector_devices' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, device_public_id TEXT NOT NULL UNIQUE, device_token_hash TEXT NOT NULL, device_name TEXT NOT NULL, device_platform TEXT NOT NULL, collector_version TEXT, capabilities_json TEXT, status TEXT NOT NULL, last_seen_at TEXT, last_error_code TEXT, last_error_summary TEXT, create_time TEXT, update_time TEXT',
            'ota_local_collector_accounts' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, device_id INTEGER NOT NULL, platform TEXT NOT NULL, account_alias TEXT NOT NULL, profile_key_hash TEXT NOT NULL, status TEXT NOT NULL, session_status TEXT NOT NULL, last_session_verified_at TEXT, last_success_at TEXT, last_error_code TEXT, last_error_summary TEXT, retry_count INTEGER, next_retry_at TEXT, create_time TEXT, update_time TEXT',
            'ota_local_collector_account_hotels' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, account_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, platform_hotel_id TEXT NOT NULL, platform_hotel_name TEXT, data_source_id INTEGER, status TEXT NOT NULL, create_time TEXT, update_time TEXT',
            'ota_local_collector_tasks' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, device_id INTEGER NOT NULL, account_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, task_type TEXT NOT NULL, data_date TEXT, data_type TEXT NOT NULL, status TEXT NOT NULL, priority INTEGER NOT NULL, attempt INTEGER NOT NULL, max_attempts INTEGER NOT NULL, available_at TEXT NOT NULL, lease_token_hash TEXT, lease_expires_at TEXT, idempotency_key TEXT NOT NULL UNIQUE, request_json TEXT, result_summary_json TEXT, error_code TEXT, error_summary TEXT, created_by INTEGER, started_at TEXT, finished_at TEXT, create_time TEXT, update_time TEXT',
            'platform_data_sources' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, system_hotel_id INTEGER, name TEXT NOT NULL, platform TEXT NOT NULL, data_type TEXT NOT NULL, ingestion_method TEXT NOT NULL, status TEXT NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time TEXT, last_sync_status TEXT, last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time TEXT, update_time TEXT',
            'platform_data_sync_tasks' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform TEXT NOT NULL, data_type TEXT NOT NULL, ingestion_method TEXT NOT NULL, trigger_type TEXT NOT NULL, status TEXT NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at TEXT, finished_at TEXT, next_retry_at TEXT, requested_by INTEGER, message TEXT, stats_json TEXT, create_time TEXT, update_time TEXT',
            'platform_data_sync_logs' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level TEXT, event TEXT, message TEXT, context_json TEXT, create_time TEXT',
            'platform_data_raw_records' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform TEXT, data_type TEXT, ingestion_method TEXT, payload_hash TEXT, raw_payload TEXT, http_status INTEGER, received_at TEXT, create_time TEXT',
            'online_daily_data' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER NOT NULL, data_source_id INTEGER, sync_task_id INTEGER, hotel_id TEXT, hotel_name TEXT, data_date TEXT NOT NULL, platform TEXT, source TEXT, data_type TEXT, data_period TEXT, readback_verified INTEGER, validation_status TEXT, validation_flags TEXT, compare_type TEXT, dimension TEXT, amount REAL, quantity INTEGER, book_order_num INTEGER, comment_score REAL, qunar_comment_score REAL, data_value REAL, list_exposure INTEGER, detail_exposure INTEGER, flow_rate REAL, order_filling_num INTEGER, order_submit_num INTEGER, raw_data TEXT, ingestion_method TEXT, source_trace_id TEXT, snapshot_time TEXT, snapshot_bucket TEXT, is_final INTEGER, persistence_identity_hash TEXT, create_time TEXT, update_time TEXT',
        ];
        foreach ($tables as $name => $columns) {
            Db::execute('CREATE TABLE ' . $name . ' (' . $columns . ')');
        }
        Db::execute('CREATE UNIQUE INDEX uq_synthetic_account_hotel ON ota_local_collector_account_hotels (account_id, system_hotel_id)');
        Db::execute("CREATE UNIQUE INDEX uq_synthetic_active_mapping ON ota_local_collector_account_hotels (tenant_id, system_hotel_id, platform) WHERE status = 'active'");
    }
}
