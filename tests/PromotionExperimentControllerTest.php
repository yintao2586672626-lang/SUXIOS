<?php
declare(strict_types=1);
namespace Tests;

use app\controller\PromotionExperiment;
use PHPUnit\Framework\TestCase;
use Tests\Support\PromotionExperimentFixture as F;
use think\facade\Db;

final class PromotionExperimentControllerTest extends TestCase
{
    private array $old;
    protected function setUp(): void { $this->old = F::database(); }
    protected function tearDown(): void { F::restoreDatabase($this->old); }
    private function controller(array $body, ?bool $permission = true, bool $readOnly = false): PromotionExperiment
    {
        $reflection = new \ReflectionClass(PromotionExperiment::class);
        $c = $reflection->newInstanceWithoutConstructor();
        $request = new class($body) {
            public function __construct(private array $body) {}
            public function post(): array { return $this->body; }
            public function get(): array { return $this->body; }
        };
        $user = $permission === null ? null : new class($permission, $readOnly) {
            public int $id = 7001;
            public function __construct(private bool $permission, private bool $readOnly) {}
            public function getPermittedHotelIds(): array { return [701]; }
            public function hasHotelPermission(int $hotel, string $cap): bool { return $this->permission && (!$this->readOnly || $cap === 'operation.view'); }
        };
        foreach (['request' => $request, 'currentUser' => $user] as $k => $v) $reflection->getParentClass()->getProperty($k)->setValue($c, $v);
        return $c;
    }
    private function body(): array { return ['scope' => F::scope(), 'input' => F::input(), 'expected_version' => 0, 'experiment_key' => 'test-experiment', 'idempotency_key' => 'test-request']; }

    public function testApiSaveAndReadbackUseSameVersionAndDowngradeManualFactClaims(): void
    {
        $response = $this->controller($this->body())->save();
        self::assertSame(200, $response->getCode(), $response->getContent());
        $body = $response->getData(); $saved = $body['data'];
        self::assertSame('manual_unverified', $saved['result']['observation']['source_quality']);
        self::assertSame('manual_unverified', $saved['result']['accounting']['records'][0]['source_quality']);
        self::assertNull($saved['result']['incrementality']['room_nights']);
        $read = $this->controller(F::scope())->read($saved['id'])->getData()['data'];
        self::assertSame($saved['payload_digest'], $read['payload_digest']);
        self::assertSame($saved['result'], $read['result']);
        $history = $this->controller(F::scope())->history()->getData()['data'];
        self::assertSame($saved['id'], $history['items'][0]['id']);
    }
    public function testPermissionTenantAndHotelFailuresHaveMatchingHttpStatus(): void
    {
        foreach ([[null, 401], [false, 403]] as [$permission, $code]) {
            $r = $this->controller($this->body(), $permission)->save();
            self::assertSame($code, $r->getCode()); self::assertSame($code, $r->getData()['code']);
        }
        self::assertSame(403, $this->controller($this->body(), true, true)->save()->getCode());
        self::assertSame(200, $this->controller($this->body(), true, true)->preview()->getCode());
        foreach (['tenant_id' => 999, 'system_hotel_id' => 999] as $k => $v) {
            $b = $this->body(); $b['scope'][$k] = $v;
            self::assertSame(403, $this->controller($b)->save()->getCode());
        }
        self::assertSame(0, Db::name('promotion_experiment_versions')->count());
    }
    public function testMismatchedRecordsAndValidationFailureRecoverWithoutWriting(): void
    {
        $b = $this->body(); $b['input']['records'][0]['platform_store_id'] = 'OTHER';
        self::assertSame(422, $this->controller($b)->save()->getCode());
        self::assertSame(0, Db::name('promotion_experiment_versions')->count());
        self::assertSame(200, $this->controller($this->body())->save()->getCode());
    }
    public function testMissingSchemaIsUnavailableAndNeverSuccessfulSave(): void
    {
        Db::execute('DROP TABLE promotion_experiment_versions');
        $r = $this->controller($this->body())->save();
        self::assertSame(503, $r->getCode()); self::assertSame(503, $r->getData()['code']);
        self::assertStringContainsString('未确认保存成功', $r->getData()['message']);
    }
}
