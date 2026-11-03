<?php
declare(strict_types=1);

use app\service\HotelLearningMechanismService;
use app\service\HotelLearningSnapshotService;
use app\service\OperatingEvidenceSnapshotStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelLearningSnapshotServiceTest extends TestCase
{
    private array $databaseConfig;
    private string $path;
    private HotelLearningSnapshotService $service;

    protected function setUp(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $this->databaseConfig = Config::get('database');
        $this->path = sys_get_temp_dir() . '/hotel-learning-snapshot-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set(['default' => 'hotel_learning_snapshot_test', 'connections' => ['hotel_learning_snapshot_test' => [
            'type' => 'sqlite', 'database' => $this->path, 'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,7),(81,7),(90,8)');
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,period_month TEXT,platform TEXT,payload_json TEXT,content_digest TEXT,idempotency_key TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
        $this->service = new HotelLearningSnapshotService();
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->databaseConfig, 'database');
        Db::connect(null, true);
        if (is_file($this->path)) unlink($this->path);
    }

    private function scope(string $mode = 'profile', string $platform = 'whole_hotel', string $month = '2026-10', int $hotel = 80, int $tenant = 7): array
    {
        return $this->service->scope($tenant, [80, 81, 90], $hotel, $month, $platform, $mode);
    }

    private function fixtureResult(string $mode = 'profile'): array
    {
        return ['mode' => $mode, 'inputs' => ['value' => 0, 'unknown' => null, 'empty' => '', 'enabled' => false,
            'source_ref' => 'synthetic-reference', 'as_of' => '2026-10-02'], 'status' => 'recorded',
            'source_quality' => 'manual_reference', 'value' => 0, 'unknown' => null, 'decision_safe' => false];
    }

    public static function modes(): array
    {
        $rows = [];
        foreach (HotelLearningMechanismService::KINDS as $mode => $kind) $rows[$mode] = [$mode, in_array($mode, ['ota_scene', 'market_sample'], true) ? 'ctrip' : 'whole_hotel', $kind];
        $rows['ota meituan'] = ['ota_scene', 'meituan', 'jhira_ota_scene'];
        $rows['market meituan'] = ['market_sample', 'meituan', 'jhira_market'];
        return $rows;
    }

    #[DataProvider('modes')]
    public function testEveryModeSavesAndReadsExactlyWithoutAModeDatabaseColumn(string $mode, string $platform, string $kind): void
    {
        $scope = $this->scope($mode, $platform);
        self::assertSame($kind, $scope['kind']);
        $saved = $this->service->save($scope, $this->fixtureResult($mode), 'synthetic-snapshot-key', 1);
        $read = $this->service->read($scope, $saved['snapshot_id']);
        self::assertTrue($read['readback_verified']);
        self::assertSame($scope, $read['scope']);
        self::assertSame($saved['inputs'], $read['inputs']);
        self::assertSame($this->fixtureResult($mode)['inputs'], $read['inputs']);
        self::assertSame($saved['result'], $read['result']);
        self::assertSame($saved['content_digest'], $read['content_digest']);
        self::assertSame(0, $read['result']['value']);
        self::assertNull($read['result']['unknown']);
        self::assertFalse($read['result']['decision_safe']);
        self::assertSame($saved['snapshot_id'], $this->service->latest($scope)['snapshot_id']);
        self::assertSame([$read], $this->service->history($scope));
        $stored = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $saved['snapshot_id'])->find();
        $payload = json_decode($stored['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('mode', $payload['scope']);
        self::assertSame(5, count($payload['scope']));
    }

    public function testMissingIsExplicitAndDoesNotInventAnEmptyResult(): void
    {
        $scope = $this->scope();
        $latest = $this->service->latest($scope);
        self::assertSame('missing', $latest['status']);
        self::assertFalse($latest['readback_verified']);
        self::assertSame($scope, $latest['scope']);
        self::assertArrayNotHasKey('result', $latest);
        self::assertSame([], $this->service->history($scope));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->service->read($scope, 1000);
    }

    public function testSameKeyIsIdempotentAndChangedPayloadCannotOverwrite(): void
    {
        $scope = $this->scope();
        $result = $this->fixtureResult();
        $saved = $this->service->save($scope, $result, 'synthetic-idempotency', 1);
        $retry = $this->service->save($scope, $result, 'synthetic-idempotency', 1);
        self::assertTrue($retry['idempotent']);
        self::assertSame($saved['snapshot_id'], $retry['snapshot_id']);
        self::assertSame(1, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
        $result['inputs']['value'] = 1;
        try {
            $this->service->save($scope, $result, 'synthetic-idempotency', 1);
            self::fail('Conflicting payload must not overwrite');
        } catch (RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        self::assertSame($saved['inputs'], $this->service->read($scope, $saved['snapshot_id'])['inputs']);
    }

    public static function invalidScopes(): array
    {
        return ['foreign tenant' => [7, [90], 90, '2026-10', 'whole_hotel', 'profile', 403],
            'hotel not permitted' => [7, [81], 80, '2026-10', 'whole_hotel', 'profile', 403],
            'malformed allowed hotel' => [7, ['80-other'], 80, '2026-10', 'whole_hotel', 'profile', 403],
            'missing hotel' => [7, [100], 100, '2026-10', 'whole_hotel', 'profile', 403],
            'zero tenant' => [0, [80], 80, '2026-10', 'whole_hotel', 'profile', 403],
            'invalid month' => [7, [80], 80, '2026-13', 'whole_hotel', 'profile', 422],
            'short month' => [7, [80], 80, '2026-1', 'whole_hotel', 'profile', 422],
            'zero year' => [7, [80], 80, '0000-10', 'whole_hotel', 'profile', 422],
            'ota whole hotel' => [7, [80], 80, '2026-10', 'whole_hotel', 'ota_scene', 422],
            'market whole hotel' => [7, [80], 80, '2026-10', 'whole_hotel', 'market_sample', 422],
            'profile ota' => [7, [80], 80, '2026-10', 'ctrip', 'profile', 422],
            'unknown platform' => [7, [80], 80, '2026-10', 'booking', 'ota_scene', 422],
            'unknown mode' => [7, [80], 80, '2026-10', 'whole_hotel', 'other', 422]];
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidScopeIsRejectedBeforeAnyPersistence(int $tenant, array $allowed, int $hotel, string $month, string $platform, string $mode, int $code): void
    {
        try {
            $this->service->scope($tenant, $allowed, $hotel, $month, $platform, $mode);
            self::fail('Invalid scope must be rejected');
        } catch (InvalidArgumentException $error) { self::assertSame(422, $code); }
        catch (RuntimeException $error) { self::assertSame($code, $error->getCode()); }
        self::assertSame(0, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    public static function differentReadScopes(): array
    {
        return ['hotel' => ['profile', 'whole_hotel', '2026-10', 81, 7], 'tenant' => ['profile', 'whole_hotel', '2026-10', 90, 8],
            'month' => ['profile', 'whole_hotel', '2026-09', 80, 7], 'mode' => ['geo_observation', 'whole_hotel', '2026-10', 80, 7],
            'platform' => ['ota_scene', 'meituan', '2026-10', 80, 7]];
    }

    #[DataProvider('differentReadScopes')]
    public function testAnotherValidatedScopeCannotReadTheSnapshot(string $mode, string $platform, string $month, int $hotel, int $tenant): void
    {
        $originalMode = $mode === 'ota_scene' ? 'ota_scene' : 'profile';
        $originalPlatform = $mode === 'ota_scene' ? 'ctrip' : 'whole_hotel';
        $original = $this->scope($originalMode, $originalPlatform);
        $saved = $this->service->save($original, $this->fixtureResult($originalMode), 'synthetic-scoped-read', 1);
        $other = $this->scope($mode, $platform, $month, $hotel, $tenant);
        self::assertSame('missing', $this->service->latest($other)['status']);
        self::assertSame([], $this->service->history($other));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->service->read($other, $saved['snapshot_id']);
    }

    public static function scopeMutations(): array
    {
        return ['hotel' => ['hotel_id', 81], 'tenant' => ['tenant_id', 8], 'month' => ['period_month', '2026-09'],
            'platform' => ['platform', 'ctrip'], 'mode' => ['mode', 'geo_observation'], 'kind' => ['kind', 'jhira_geo'],
            'extra key' => ['client_authorized', true]];
    }

    #[DataProvider('scopeMutations')]
    public function testClientScopeMutationCannotAuthorizeAWrite(string $field, mixed $value): void
    {
        $scope = $this->scope();
        $scope[$field] = $value;
        try {
            $this->service->save($scope, $this->fixtureResult(), 'synthetic-forged-scope', 1);
            self::fail('Mutated scope must not authorize a save');
        } catch (RuntimeException $error) { self::assertSame(403, $error->getCode()); }
        self::assertSame(0, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    public function testWellFormedButNeverValidatedScopeCannotBypassPermissions(): void
    {
        $scope = $this->scope();
        $otherService = new HotelLearningSnapshotService();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $otherService->save($scope, $this->fixtureResult(), 'synthetic-without-scope', 1);
    }

    public function testInputsCannotOverrideEffectiveScopeAndConflictingResultScopeIsRejected(): void
    {
        $scope = $this->scope();
        $result = $this->fixtureResult();
        $result['inputs']['scope'] = ['tenant_id' => 8, 'hotel_id' => 90, 'mode' => 'ota_scene'];
        $saved = $this->service->save($scope, $result, 'synthetic-input-claims', 1);
        self::assertSame($scope, $saved['scope']);
        self::assertSame($result['inputs'], $saved['inputs']);
        $result['mode'] = 'geo_observation';
        $this->expectException(InvalidArgumentException::class);
        $this->service->save($scope, $result, 'synthetic-mode-conflict', 1);
    }

    public function testHotelTenantIsRecheckedWhenWritingAnIssuedScope(): void
    {
        $scope = $this->scope();
        Db::name('hotels')->where('id', 80)->update(['tenant_id' => 8]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->service->save($scope, $this->fixtureResult(), 'synthetic-moved-hotel', 1);
    }

    public function testTamperedPayloadFailsDigestReadback(): void
    {
        $scope = $this->scope();
        $saved = $this->service->save($scope, $this->fixtureResult(), 'synthetic-tamper-test', 1);
        Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id', $saved['snapshot_id'])->update(['payload_json' => '{}']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->service->read($scope, $saved['snapshot_id']);
    }
}
