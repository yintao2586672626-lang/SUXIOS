<?php
declare(strict_types=1);
// CLI-only synthetic HTTP bridge; no application configuration or real account is loaded.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../bootstrap.php';
use app\controller\OperationManagement;
use Tests\Support\OperationTaskWorkflowFixture as Fixture;
use think\facade\Db;

$directory = realpath(__DIR__ . '/../../output/long-goals');
$database = str_replace('\\', '/', (string)($argv[1] ?? ''));
if (!$directory || !str_starts_with($database, str_replace('\\', '/', $directory) . '/ai-workflow-synthetic-') || !preg_match('/ai-workflow-synthetic-[0-9]+\.sqlite$/D', $database)) throw new RuntimeException('Synthetic task-local database required');
$newDatabase = !is_file($database);
Fixture::connect($database);
if ($newDatabase) {
    Fixture::schema(); Fixture::seed();
    Db::name('hotels')->insert(['id' => 904, 'tenant_id' => 9004]);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$body = (array)($input['body'] ?? []);
$reflection = new ReflectionClass(OperationManagement::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('service')->setValue($controller, new \app\service\OperationManagementService());
$request = new class($body) {
    public function __construct(private array $body) {}
    public function post(): array { return $this->body; }
    public function get(): array { return $this->body; }
    public function param(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->body : ($this->body[$key] ?? $default); }
};
$user = new class {
    public int $id = 3;
    public function getPermittedHotelIds(): array { return [904]; }
    public function hasHotelPermission(int $id, string $capability): bool { return $id === 904; }
};
foreach (['request' => $request, 'currentUser' => $user] as $key => $value) $reflection->getParentClass()->getProperty($key)->setValue($controller, $value);
$response = match ($input['action'] ?? '') {
    'propose' => $controller->proposeTaskWorkflow(),
    'read' => $controller->readExecutionIntent((int)($input['id'] ?? 0)),
    default => throw new RuntimeException('Unknown synthetic action'),
};
echo json_encode(['http_status' => $response->getCode(), 'body' => $response->getData(),
    'task_count' => (int)Db::name('operation_execution_tasks')->where('hotel_id', 904)->count(),
    'proposal_count' => (int)Db::name('operation_task_workflow_proposals')->where('hotel_id', 904)->count()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
Db::connect()->close();
