<?php
declare(strict_types=1);
// Synthetic test-only controller/SQLite bridge. Does not initialize app config or authentication.
require __DIR__ . '/../bootstrap.php';

use app\controller\PromotionExperiment;
use Tests\Support\PromotionExperimentFixture as F;

$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($request['action'] ?? '') === 'fixture') { echo json_encode(F::input(), JSON_THROW_ON_ERROR); exit; }
$directory = realpath(__DIR__ . '/../../output/long-goal');
$database = (string)($argv[1] ?? '');
if (!$directory || !str_starts_with(str_replace('\\', '/', $database), str_replace('\\', '/', $directory) . '/promotion-ui-') || !str_ends_with($database, '.sqlite')) throw new RuntimeException('Fixture database must be a task-local synthetic file');
$old = F::database($database);
$reflection = new ReflectionClass(PromotionExperiment::class);
$controller = $reflection->newInstanceWithoutConstructor();
$httpRequest = new class($request['body'] ?? []) {
    public function __construct(private array $body) {}
    public function post(): array { return $this->body; }
    public function get(): array { return $this->body; }
};
$user = new class {
    public int $id = 7001;
    public function getPermittedHotelIds(): array { return [701]; }
    public function hasHotelPermission(int $hotel, string $cap): bool { return $hotel === 701; }
};
foreach (['request' => $httpRequest, 'currentUser' => $user] as $key => $value) $reflection->getParentClass()->getProperty($key)->setValue($controller, $value);
$response = match ($request['action'] ?? '') {
    'preview' => $controller->preview(), 'save' => $controller->save(), 'history' => $controller->history(), 'read' => $controller->read((int)$request['id']),
    default => throw new RuntimeException('Unknown fixture action'),
};
echo json_encode(['http_status' => $response->getCode(), 'body' => $response->getData()], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
F::restoreDatabase($old);
