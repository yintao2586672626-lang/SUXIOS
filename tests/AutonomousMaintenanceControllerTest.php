<?php
declare(strict_types=1);
namespace Tests;
use app\controller\AutonomousMaintenance;
use app\controller\Base;
use app\service\AutonomousMaintenanceService;
use PHPUnit\Framework\TestCase;

final class AutonomousMaintenanceControllerTest extends TestCase
{
    private string $dir;
    private string|false $previousDir;
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/suxios-maintenance-controller-' . bin2hex(random_bytes(8));
        $this->previousDir = getenv('SUXIOS_MAINTENANCE_STATE_DIR');
        putenv('SUXIOS_MAINTENANCE_STATE_DIR=' . $this->dir);
    }
    protected function tearDown(): void
    {
        putenv($this->previousDir === false ? 'SUXIOS_MAINTENANCE_STATE_DIR' : 'SUXIOS_MAINTENANCE_STATE_DIR=' . $this->previousDir);
    }
    private function controller(bool $admin, array $data = []): AutonomousMaintenance
    {
        $controller = new AutonomousMaintenance(new \think\App(dirname(__DIR__)));
        $user = new class($admin) {
            public function __construct(private bool $admin) {}
            public function isSuperAdmin(): bool { return $this->admin; }
        };
        $request = new class($data) {
            public function __construct(private array $data) {}
            public function post(): array { return $this->data; }
            public function method(): string { return 'POST'; }
            public function getContent(): string { return ''; }
        };
        (new \ReflectionProperty(Base::class, 'currentUser'))->setValue($controller, $user);
        (new \ReflectionProperty(Base::class, 'request'))->setValue($controller, $request);
        return $controller;
    }
    public function testNonAdminCannotReadOrWriteMaintenance(): void
    {
        foreach (['overview', 'control'] as $action) {
            try { $this->controller(false)->$action(); self::fail('non-admin access accepted'); }
            catch (\think\exception\HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
        }
        self::assertFileDoesNotExist($this->dir . '/state.json');
    }
    public function testAdminControlSavesAndExactOverviewReadbackUsesSameState(): void
    {
        $saved = $this->controller(true, ['action' => 'resume', 'expected_revision' => 0])->control()->getData();
        self::assertSame(200, $saved['code']);
        $readback = $this->controller(true)->overview()->getData();
        self::assertSame($saved['data']['control'], $readback['data']['control']);
        $paused = $this->controller(true, ['action' => 'pause', 'expected_revision' => 1])->control()->getData();
        self::assertSame('paused', $paused['data']['control']['mode']);
        self::assertSame($paused['data']['control'], (new AutonomousMaintenanceService($this->dir))->snapshot()['control']);
        $stale = $this->controller(true, ['action' => 'resume', 'expected_revision' => 1])->control();
        self::assertSame(409, $stale->getCode());
    }
    public function testMalformedControlAndCorruptionFailExplicitlyWithoutEmptyQueue(): void
    {
        self::assertSame(422, $this->controller(true, ['action' => 'resume'])->control()->getCode());
        (new AutonomousMaintenanceService($this->dir))->snapshot();
        file_put_contents($this->dir . '/state.json', '{corrupt');
        $response = $this->controller(true)->overview();
        self::assertSame(503, $response->getCode());
        self::assertSame('blocked', $response->getData()['data']['state']);
        self::assertSame('{corrupt', file_get_contents($this->dir . '/state.json'));
    }
}
