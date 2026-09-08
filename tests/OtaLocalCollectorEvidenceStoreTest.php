<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaLocalCollectorEvidenceStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtaLocalCollectorEvidenceStoreTest extends TestCase
{
    private function store(): OtaLocalCollectorEvidenceStore
    {
        return new OtaLocalCollectorEvidenceStore((string)getenv('SUXIOS_CACHE_PATH') . '/business-evidence-' . bin2hex(random_bytes(6)));
    }

    public function testLargeSanitizedInputIsRetainedInFullAndCanBeReadAgain(): void
    {
        $store = $this->store();
        $scope = ['tenant_id' => 12, 'device_id' => 8, 'task_id' => 14, 'attempt' => 1];
        $result = ['success' => true, 'rows' => [['data_date' => '2026-09-04', 'fixture_description' => str_repeat('合成业务字段', 30_000)]]];
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertGreaterThan(262144, strlen($json));
        $hash = hash('sha256', $json);
        $receipt = $store->put($scope, $json, $hash);
        self::assertSame(strlen($json), $receipt['bytes']);
        self::assertTrue($receipt['replayable']);
        self::assertSame($result, $store->read($scope, $hash));
        self::assertSame($receipt, $store->put($scope, $json, $hash));
    }

    public function testOtherTenantCannotReadTheSameHash(): void
    {
        $store = $this->store();
        $scope = ['tenant_id' => 12, 'device_id' => 8, 'task_id' => 14, 'attempt' => 1];
        $json = '{"success":true,"rows":[]}';
        $hash = hash('sha256', $json);
        $store->put($scope, $json, $hash);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $store->read(array_replace($scope, ['tenant_id' => 99]), $hash);
    }

    public function testChangedBytesCannotBeStoredUnderAnExistingHash(): void
    {
        $store = $this->store();
        $scope = ['tenant_id' => 12, 'device_id' => 8, 'task_id' => 14, 'attempt' => 1];
        $json = '{"success":true,"rows":[]}';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $store->put($scope, $json . ' ', hash('sha256', $json));
    }
}
