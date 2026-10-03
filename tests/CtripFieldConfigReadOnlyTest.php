<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CtripFieldConfigReadOnlyTest extends TestCase
{
    use ReflectionHelper;

    private static array $originalDatabaseConfig = [];
    private OnlineData $controller;
    private string $configKey;
    private string $moduleConfigKey;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key TEXT UNIQUE, config_value TEXT, description TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, module TEXT, action TEXT, description TEXT, error_info TEXT, extra_data TEXT, ip TEXT, user_agent TEXT, create_time TEXT)');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
    }

    protected function setUp(): void
    {
        Db::name('system_configs')->delete(true);
        $reflection = new ReflectionClass(OnlineData::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
        while (!$reflection->hasConstant('CTRIP_PROFILE_FIELDS_CONFIG_KEY') && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }
        $this->configKey = (string)$reflection->getConstant('CTRIP_PROFILE_FIELDS_CONFIG_KEY');
        $this->moduleConfigKey = (string)$reflection->getConstant('CTRIP_PROFILE_MODULES_CONFIG_KEY');
        self::assertNotSame('', $this->configKey);
    }

    private function read(bool $includeDeleted = true): array
    {
        return $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureFields', [$includeDeleted]);
    }

    private function storeRaw(string $value, ?string $key = null): array
    {
        Db::name('system_configs')->insert([
            'config_key' => $key ?? $this->configKey,
            'config_value' => $value,
            'description' => 'isolated fixture',
            'create_time' => '2026-09-01 00:00:00',
            'update_time' => '2026-09-01 00:00:00',
        ]);
        return Db::name('system_configs')->where('config_key', $key ?? $this->configKey)->find();
    }

    private function assertStoredRowUnchanged(array $before): void
    {
        self::assertSame($before, Db::name('system_configs')->where('config_key', $before['config_key'])->find());
    }

    public function testMissingConfigurationIsOnlyPreviewedAndRepeatedReadsDoNotCreateIt(): void
    {
        $first = $this->read();
        self::assertNotEmpty($first);
        self::assertSame(0, (int)Db::name('system_configs')->count());
        self::assertSame(array_keys($first), array_keys($this->read(false)));
        self::assertSame(0, (int)Db::name('system_configs')->count());
    }

    public function testMalformedConfigurationIsPreservedWhileDefaultsArePreviewed(): void
    {
        $before = $this->storeRaw('{invalid-fixture-catalog');
        self::assertNotEmpty($this->read());
        $this->assertStoredRowUnchanged($before);
    }

    public function testEmptySavedCatalogIsNotSilentlyReplacedDuringRead(): void
    {
        $before = $this->storeRaw(json_encode(['version' => 0, 'fields' => []], JSON_THROW_ON_ERROR));
        self::assertNotEmpty($this->read());
        $this->assertStoredRowUnchanged($before);
    }

    public function testLegacyMetadataPreviewPreservesTheStoredConfigurationAndOperatorState(): void
    {
        $defaults = $this->read();
        $id = array_key_first($defaults);
        $field = $defaults[$id];
        $field['enabled'] = false;
        $field['status'] = 'paused';
        $field['sample_verification_status'] = 'unverified';
        $before = $this->storeRaw(json_encode(['version' => 0, 'fields' => [$id => $field]], JSON_THROW_ON_ERROR));
        $preview = $this->read();
        self::assertFalse($preview[$id]['enabled']);
        self::assertSame('paused', $preview[$id]['status']);
        self::assertGreaterThan(1, count($preview));
        $this->assertStoredRowUnchanged($before);
    }

    public function testReadFiltersUnsupportedFieldsWithoutDeletingThemFromStorage(): void
    {
        $before = $this->storeRaw(json_encode(['version' => 0, 'fields' => [
            'fixture_extra' => ['id' => 'fixture_extra', 'field_key' => 'fixture_unsupported_metric', 'field_name' => '隔离扩展字段'],
        ]], JSON_THROW_ON_ERROR));
        $preview = $this->read();
        self::assertArrayNotHasKey('fixture_extra', $preview);
        self::assertNotEmpty($preview);
        $this->assertStoredRowUnchanged($before);
    }

    public function testExplicitSaveStillPersistsAndPreciselyReadsBackAnEdit(): void
    {
        $fields = $this->read();
        $id = array_key_first($fields);
        $fields[$id]['enabled'] = false;
        $fields[$id]['status'] = 'paused';
        $fields[$id]['notes'] = '隔离保存回读备注';
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [$fields]);
        self::assertSame(1, (int)Db::name('system_configs')->count());
        $stored = Db::name('system_configs')->where('config_key', $this->configKey)->find();
        $readback = $this->read();
        self::assertFalse($readback[$id]['enabled']);
        self::assertSame('paused', $readback[$id]['status']);
        self::assertSame('隔离保存回读备注', $readback[$id]['notes']);
        $this->assertStoredRowUnchanged($stored);
    }

    public function testExplicitSyncPersistsPreviewEvenWhenNoNewCandidatesExist(): void
    {
        $preview = $this->read();
        self::assertSame(0, (int)Db::name('system_configs')->count());
        $reflection = new ReflectionClass(OnlineData::class);
        $reflection->getProperty('currentUser')->setValue($this->controller, new class {
            public int $id = 0;
            public function isSuperAdmin(): bool { return true; }
        });
        $reflection->getProperty('request')->setValue($this->controller, new \think\Request());
        $response = $this->controller->syncCtripProfileFields()->getData();
        self::assertSame(200, $response['code']);
        self::assertSame(0, $response['data']['sync_result']['added_count']);
        $stored = Db::name('system_configs')->where('config_key', $this->configKey)->find();
        self::assertNotNull($stored);
        self::assertEqualsCanonicalizing(array_keys($preview), array_keys($this->read()));
        $this->assertStoredRowUnchanged($stored);
    }

    private function saveRequest(array $data): array
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $reflection->getProperty('currentUser')->setValue($this->controller, new class {
            public int $id = 0;
            public function isSuperAdmin(): bool { return true; }
        });
        $reflection->getProperty('request')->setValue($this->controller, (new \think\Request())->withPost($data));
        return $this->controller->saveCtripProfileField()->getData();
    }

    public function testUnsupportedNewFieldCannotReportSavedOrCreateConfiguration(): void
    {
        $modules = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        $response = $this->saveRequest([
            'field_key' => 'fixture_unsupported_metric', 'field_name' => '隔离未支持字段',
            'section' => array_key_first($modules), 'page_url' => 'https://fixture.invalid/overview',
            'request_url' => 'https://fixture.invalid/api/overview', 'json_path' => 'data.fixture_metric',
            'target_value' => 'fixture_metric', 'value_meaning' => '隔离未支持指标',
        ]);
        self::assertSame(400, $response['code']);
        self::assertStringContainsString('本次未保存', $response['message']);
        self::assertSame(0, (int)Db::name('system_configs')->count());
    }

    public function testUnsupportedEditIsRejectedWithoutDroppingTheSavedField(): void
    {
        $fields = $this->read();
        $id = array_key_first($fields);
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [$fields]);
        $stored = Db::name('system_configs')->where('config_key', $this->configKey)->find();
        $response = $this->saveRequest(['id' => $id, 'field_key' => 'fixture_unsupported_metric']);
        self::assertSame(400, $response['code']);
        $this->assertStoredRowUnchanged($stored);
        self::assertArrayHasKey($id, $this->read());
    }

    public function testSupportedPublicSaveAndEditPreciselyReadBackTheSavedValues(): void
    {
        $modules = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        $input = [
            'field_key' => 'order_amount', 'field_name' => '隔离订单金额',
            'section' => array_key_first($modules), 'page_url' => 'https://fixture.invalid/overview',
            'request_url' => 'https://fixture.invalid/api/overview', 'json_path' => 'data.order_amount',
            'target_value' => 'order_amount', 'value_meaning' => '隔离渠道订单金额',
            'enabled' => 0, 'status' => 'paused', 'notes' => '隔离公开保存备注',
        ];
        $created = $this->saveRequest($input);
        self::assertSame(200, $created['code']);
        $id = $created['data']['id'];
        $readback = $this->read();
        self::assertArrayHasKey($id, $readback);
        foreach (['field_key', 'field_name', 'section', 'page_url', 'request_url', 'json_path', 'target_value', 'value_meaning', 'status', 'notes'] as $key) {
            self::assertSame($input[$key], $readback[$id][$key], $key);
        }
        self::assertFalse($readback[$id]['enabled']);
        $edited = $this->saveRequest(['id' => $id, 'notes' => '隔离公开编辑回读备注']);
        self::assertSame(200, $edited['code']);
        self::assertSame('隔离公开编辑回读备注', $this->read()[$id]['notes']);
    }

    public function testNewMatchedFieldWithoutSelectedSampleCannotBeSaved(): void
    {
        $response = $this->saveRequest([
            'field_key' => 'order_amount', 'field_name' => '隔离无值相符',
            'section' => 'business_overview', 'page_url' => 'https://fixture.invalid/overview',
            'request_url' => 'https://fixture.invalid/api', 'json_path' => 'data.order_amount',
            'target_value' => 'order_amount', 'value_meaning' => '隔离指标',
            'sample_verification_status' => 'matched', 'status' => 'confirmed',
        ]);
        self::assertSame(400, $response['code']);
        self::assertSame(0, (int)Db::name('system_configs')->count());
    }

    private function verifyRequest(string $id, string $status): array
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $reflection->getProperty('currentUser')->setValue($this->controller, new class {
            public int $id = 0;
            public function isSuperAdmin(): bool { return true; }
        });
        $reflection->getProperty('request')->setValue($this->controller, (new \think\Request())->withPost(['id' => $id, 'sample_verification_status' => $status]));
        return $this->controller->verifyCtripProfileFieldSample()->getData();
    }

    public function testDirectMatchWithoutSelectedSampleCannotChangeStoredConfiguration(): void
    {
        $fields = $this->read();
        $id = 'profile_field_order_amount';
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [$fields]);
        $before = Db::name('system_configs')->where('config_key', $this->configKey)->find();
        $response = $this->verifyRequest($id, 'matched');
        self::assertSame(400, $response['code']);
        $this->assertStoredRowUnchanged($before);
    }

    public function testLegacyMatchWithoutValueIsOnlyPreviewedAsUnverifiedWithoutWriting(): void
    {
        $fields = $this->read();
        $id = 'profile_field_order_amount';
        $fields[$id]['sample_verification_status'] = 'matched';
        $fields[$id]['verified_sample_value'] = '';
        $fields[$id]['sample_verified_at'] = '2026-09-30 00:00:00';
        $before = $this->storeRaw(json_encode(['version' => 1, 'fields' => [$id => $fields[$id]]], JSON_THROW_ON_ERROR));
        $preview = $this->read()[$id];
        self::assertSame('unverified', $preview['sample_verification_status']);
        self::assertSame('', $preview['sample_verified_at']);
        $this->assertStoredRowUnchanged($before);
    }

    public function testSelectedZeroRemainsMatchableAndPreciselyReadsBack(): void
    {
        $original = $this->storeVerifiedFieldFixture();
        $response = $this->saveRequest(array_merge($original, ['verified_sample_value' => 0]));
        self::assertSame(200, $response['code']);
        self::assertSame('0', $this->read()[$original['id']]['verified_sample_value']);
        self::assertSame(200, $this->verifyRequest($original['id'], 'matched')['code']);
        self::assertSame('matched', $this->read()[$original['id']]['sample_verification_status']);
    }

    public function testMismatchRemainsAnExplicitOperatorFlagWithoutSelectedValue(): void
    {
        $fields = $this->read();
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [$fields]);
        $id = 'profile_field_order_amount';
        self::assertSame(200, $this->verifyRequest($id, 'mismatched')['code']);
        self::assertSame('mismatched', $this->read()[$id]['sample_verification_status']);
    }

    private function storeVerifiedFieldFixture(): array
    {
        $fields = $this->read();
        $id = 'profile_field_order_amount';
        $fields[$id] = array_merge($fields[$id], [
            'page_url' => 'https://fixture.invalid/overview',
            'request_url' => 'https://fixture.invalid/api/overview',
            'json_path' => 'data.order_amount', 'target_value' => 'order_amount',
            'status' => 'confirmed', 'sample_verification_status' => 'matched',
            'sample_verified_at' => '2026-09-30 10:00:00', 'sample_verified_by' => 0,
            'verified_sample_value' => '50', 'verified_sample_unit' => '元',
            'verified_sample_source_key' => 'fixture_source', 'verified_sample_source_path' => 'data.order_amount',
            'verified_sample_endpoint_id' => 'fixture_endpoint', 'verified_sample_data_date' => '2026-09-30',
            'verified_sample_hotel_name' => '隔离样例门店', 'verified_sample_captured_at' => '2026-09-30 09:00:00',
        ]);
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [$fields]);
        return $this->read()[$id];
    }

    public static function materialEvidenceChanges(): array
    {
        return [
            'source path' => ['json_path', 'data.revised_amount'],
            'source endpoint' => ['request_url', 'https://fixture.invalid/api/revised'],
            'unit' => ['unit', '隔离新单位'],
            'conversion rule' => ['transform_rule', 'fixture_divide_by_100'],
            'paused source path' => ['json_path', 'data.revised_amount', true],
        ];
    }

    #[DataProvider('materialEvidenceChanges')]
    public function testChangedEvidenceCannotReuseOldVerification(string $key, string $value, bool $paused = false): void
    {
        $original = $this->storeVerifiedFieldFixture();
        if ($paused) {
            $original['status'] = 'paused';
            $original['enabled'] = false;
            $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureFields', [[$original['id'] => $original]]);
        }
        self::assertSame('matched', $original['sample_verification_status']);
        $response = $this->saveRequest(array_merge($original, [$key => $value]));
        self::assertSame(200, $response['code']);
        $readback = $this->read()[$original['id']];
        self::assertSame('unverified', $readback['sample_verification_status']);
        self::assertSame($paused ? 'paused' : 'pending', $readback['status']);
        if ($paused) {
            self::assertFalse($readback['enabled']);
        }
        self::assertSame('', $readback['sample_verified_at']);
        self::assertNull($readback['sample_verified_by']);
        foreach (['verified_sample_value', 'verified_sample_unit', 'verified_sample_source_key', 'verified_sample_source_path', 'verified_sample_endpoint_id', 'verified_sample_data_date', 'verified_sample_hotel_name', 'verified_sample_captured_at'] as $evidenceKey) {
            self::assertSame('', $readback[$evidenceKey], $evidenceKey);
        }
        self::assertStringContainsString('需重新核验', $response['message']);
    }

    public function testNotesOnlyEditPreservesTheValidSampleVerification(): void
    {
        $original = $this->storeVerifiedFieldFixture();
        $response = $this->saveRequest(array_merge($original, ['notes' => '隔离备注修改']));
        self::assertSame(200, $response['code']);
        $readback = $this->read()[$original['id']];
        foreach (['sample_verification_status', 'sample_verified_at', 'sample_verified_by', 'verified_sample_value', 'verified_sample_source_path', 'status'] as $key) {
            self::assertSame($original[$key], $readback[$key], $key);
        }
        self::assertSame('隔离备注修改', $readback['notes']);
    }

    public function testMissingModulesAreAlsoReadWithoutCreatingConfiguration(): void
    {
        self::assertNotEmpty($this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]));
        self::assertSame(0, (int)Db::name('system_configs')->count());
    }

    public function testMalformedModuleConfigurationIsPreservedDuringRead(): void
    {
        $before = $this->storeRaw('{invalid-fixture-modules', $this->moduleConfigKey);
        self::assertNotEmpty($this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]));
        $this->assertStoredRowUnchanged($before);
    }

    public function testLegacyModulePreviewPreservesStoredOperatorChoices(): void
    {
        $modules = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        $id = array_key_first($modules);
        $module = $modules[$id];
        $module['enabled'] = false;
        $module['label'] = '隔离模块自定义名称';
        $before = $this->storeRaw(json_encode(['version' => 0, 'modules' => [$id => $module]], JSON_THROW_ON_ERROR), $this->moduleConfigKey);
        $preview = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        self::assertFalse($preview[$id]['enabled']);
        self::assertSame('隔离模块自定义名称', $preview[$id]['label']);
        self::assertGreaterThan(1, count($preview));
        $this->assertStoredRowUnchanged($before);
    }

    public function testExplicitModuleSaveStillPersistsAndPreciselyReadsBackAnEdit(): void
    {
        $modules = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        $id = array_key_first($modules);
        $modules[$id]['enabled'] = false;
        $modules[$id]['label'] = '隔离模块保存回读名称';
        $this->invokeNonPublic($this->controller, 'writeCtripProfileCaptureModules', [$modules]);
        self::assertSame(1, (int)Db::name('system_configs')->count());
        $stored = Db::name('system_configs')->where('config_key', $this->moduleConfigKey)->find();
        $readback = $this->invokeNonPublic($this->controller, 'readCtripProfileCaptureModules', [true]);
        self::assertFalse($readback[$id]['enabled']);
        self::assertSame('隔离模块保存回读名称', $readback[$id]['label']);
        $this->assertStoredRowUnchanged($stored);
    }
}
