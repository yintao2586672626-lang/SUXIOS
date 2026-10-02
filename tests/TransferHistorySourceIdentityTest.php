<?php
declare(strict_types=1);

namespace Tests;

use app\controller\TransferDecision;
use app\model\User;
use app\service\TransferDecisionService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Db;
use think\Request;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TransferHistorySourceIdentityTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        // No initialize(), .env, account or application DB: all history is synthetic.
        $app = $this->app = new App(dirname(__DIR__));
        $state = sys_get_temp_dir() . '/suxi-transfer-history-' . bin2hex(random_bytes(6)) . '/';
        $app->setRuntimePath($state);
        $app->config->set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $state . 'log/']]], 'log');
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $state . 'cache/']]], 'cache');
        $app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        (new \think\service\ModelService($app))->boot();
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('CREATE TABLE transfer_records (id INTEGER PRIMARY KEY, record_type TEXT, tenant_id INTEGER, hotel_id INTEGER, hotel_name TEXT, source_date TEXT, input_json TEXT, result_json TEXT, snapshot_json TEXT, decision TEXT, risk_level TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, source_record_id INTEGER, status TEXT, deleted_at TEXT)');
        Db::name('hotels')->insertAll([['id' => 7, 'tenant_id' => 9], ['id' => 8, 'tenant_id' => 9]]);
        $this->put(38);
    }

    public function testAnotherHotelSnapshotCannotBeReadUnderAnAllowedRowIdentity(): void
    {
        $this->put(39, ['hotel_id' => 7], ['hotel_id' => 8, 'source_identity' => ['hotel_id' => 8, 'tenant_id' => 9]]);
        $service = new TransferDecisionService();
        self::assertSame([38], array_column($service->records([7], 3, false), 'id'));
        try {
            $service->detail(39, [7], 3, false);
            self::fail('A snapshot for another hotel must not be exposed under the row hotel.');
        } catch (RuntimeException $exception) {
            self::assertSame('Transfer record does not exist or is outside current tenant scope', $exception->getMessage());
        }
        self::assertNull(Db::name('transfer_records')->where('id', 39)->value('deleted_at'));
    }

    public function testIdentityConflictAndCorruptionNeverExposeHistoricalBusinessFields(): void
    {
        $service = new TransferDecisionService();
        foreach ([
            ['input_json' => '{"hotel_id":8}'],
            ['input_json' => '{"tenant_id":10}'],
            ['snapshot_json' => '{"source_identity":{"hotel_id":7,"tenant_id":10}}'],
            ['snapshot_json' => '{"source_identity":{"hotel_id":8,"tenant_id":9}}'],
            ['snapshot_json' => '{"source_identity":{"hotel_id":"7-invalid","tenant_id":9}}'],
            ['snapshot_json' => '{"source_identity":true}'],
            ['input_json' => '{"hotel_id":8'],
            ['result_json' => '{"hotel_id":8,"profit":{"monthly_net_profit":99}}'],
        ] as $offset => $override) {
            $id = 70 + $offset;
            $this->put($id);
            Db::name('transfer_records')->where('id', $id)->update($override);
            try {
                $service->detail($id, [7], 3, false);
                self::fail('Conflicting/corrupt source identity must fail closed: ' . json_encode($override));
            } catch (RuntimeException $exception) {
                self::assertSame('Transfer record does not exist or is outside current tenant scope', $exception->getMessage());
            }
        }
        self::assertSame([38], array_column($service->records([7], 3, false), 'id'));
    }

    public function testInvalidNewerRowsDoNotStarveTheExistingEightyVisibleRecordLimit(): void
    {
        for ($id = 100; $id < 180; $id++) $this->put($id);
        for ($id = 500; $id < 650; $id++) $this->put($id, ['hotel_id' => 8]);
        self::assertSame(range(179, 100), array_column((new TransferDecisionService())->records([7], 3, false), 'id'));
    }

    public function testMissingLegacyAndMatchingSourceIdentityPreserveDateSourceAndObservedZero(): void
    {
        $snapshot = ['source_identity' => ['hotel_id' => '7', 'tenant_id' => '9'],
            'source_date' => '2026-08-13', 'source_scope' => 'ota_channel', 'data_status' => 'unverified'];
        $this->put(39, ['hotel_id' => '7', 'tenant_id' => '9'], $snapshot);
        $this->put(40, ['hotel_id' => 0, 'tenant_id' => 0]);
        $service = new TransferDecisionService();
        self::assertSame([40, 39, 38], array_column($service->records([7], 3, false), 'id'));
        foreach ([38, 39, 40] as $id) {
            $detail = $service->detail($id, [7], 3, false);
            self::assertSame(0, $detail['summary']['monthly_net_profit']);
            self::assertSame('2026-08-13', $detail['source_date']);
        }
        self::assertSame($snapshot, $service->detail(39, [7], 3, false)['snapshot']);
    }

    public function testLegacyNullAndEmptyJsonRemainReadableButScalarJsonIsRejected(): void
    {
        $service = new TransferDecisionService();
        foreach ([null, 'null', ''] as $offset => $raw) {
            $id = 70 + $offset;
            $this->put($id);
            Db::name('transfer_records')->where('id', $id)->update(['input_json' => $raw, 'snapshot_json' => $raw]);
            $detail = $service->detail($id, [7], 3, false);
            self::assertSame([], $detail['input']);
            self::assertSame([], $detail['snapshot']);
            self::assertSame(0, $detail['summary']['monthly_net_profit']);
        }
        foreach (['true', '7', '"scope"'] as $offset => $raw) {
            $id = 80 + $offset;
            $this->put($id);
            Db::name('transfer_records')->where('id', $id)->update(['snapshot_json' => $raw]);
            try {
                $service->detail($id, [7], 3, false);
                self::fail('Scalar JSON cannot be accepted as an absent legacy source scope.');
            } catch (RuntimeException $exception) {
                self::assertSame('Transfer record does not exist or is outside current tenant scope', $exception->getMessage());
            }
        }
        self::assertSame([72, 71, 70, 38], array_column($service->records([7], 3, false), 'id'));
    }

    public function testActualControllerReadbackDoesNotExposeBusinessDataFromRejectedHistory(): void
    {
        $this->put(39, ['hotel_id' => 7], ['source_identity' => ['hotel_id' => 8, 'tenant_id' => 9]]);
        $controller = $this->controller();
        $detail = $controller->detail(39);
        self::assertSame(400, $detail->getCode());
        self::assertNull($detail->getData()['data']);
        self::assertSame(200, $controller->detail(38)->getCode());
        self::assertSame([38], array_column($controller->records()->getData()['data']['list'], 'id'));
    }

    public function testNonFiniteSavedValuesCannotProduceAnUnserializableSuccessfulControllerResponse(): void
    {
        $controller = $this->controller();
        foreach ([
            ['input_json' => '{"hotel_id":7,"monthly_rent":1e999}'],
            ['snapshot_json' => '{"hotel_id":7,"reference_value":1e999}'],
            ['result_json' => '{"profit":{"monthly_net_profit":1e999}}'],
        ] as $offset => $override) {
            $id = 70 + $offset;
            $this->put($id);
            Db::name('transfer_records')->where('id', $id)->update($override);
            $response = $controller->detail($id);
            $serializationError = '';
            try { $response->getContent(); } catch (\Throwable $exception) { $serializationError = $exception->getMessage(); }
            self::assertSame(400, $response->getCode(), 'Rejected JSON must remain serializable; existing serializer error: ' . $serializationError);
            self::assertNull($response->getData()['data']);
        }
        self::assertSame([38], array_column($controller->records()->getData()['data']['list'], 'id'));
        self::assertSame(0, $controller->detail(38)->getData()['data']['summary']['monthly_net_profit']);
    }

    private function controller(): TransferDecision
    {
        $request = (new Request())->withGet(['hotel_id' => 7]);
        $request->user = new class extends User {
            public function __construct() { parent::__construct(['id' => 3, 'tenant_id' => 9]); }
            public function getPermittedHotelIds(): array { return [7]; }
            public function isSuperAdmin(): bool { return false; }
        };
        $this->app->instance('request', $request);
        return new TransferDecision($this->app, new TransferDecisionService());
    }

    private function put(int $id, array $input = [], array $snapshot = []): void
    {
        Db::name('transfer_records')->insert([
            'id' => $id, 'record_type' => 'pricing', 'tenant_id' => 9, 'hotel_id' => 7,
            'hotel_name' => 'Synthetic hotel', 'source_date' => '2026-08-13',
            'input_json' => json_encode($input), 'snapshot_json' => json_encode($snapshot),
            'result_json' => '{"profit":{"monthly_net_profit":0}}', 'decision' => 'review', 'risk_level' => 'medium',
            'created_by' => 3, 'created_at' => '2026-08-13 09:00:00', 'updated_at' => '2026-08-13 09:00:00', 'deleted_at' => null,
        ]);
    }
}
