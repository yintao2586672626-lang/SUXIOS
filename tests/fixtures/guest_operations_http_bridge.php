<?php
declare(strict_types=1);
// Test-only stdin -> controller bridge, bound exclusively to a unique temporary SQLite file.
require dirname(__DIR__) . '/bootstrap.php';
use app\controller\GuestOperations;
use app\model\User;
use think\App;
use think\facade\Config;
use think\facade\Db;

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$path = $input['database'] ?? '';
$tempRoot = realpath(sys_get_temp_dir());
$directory = realpath(dirname($path));
if (!$directory || !$tempRoot || !str_starts_with(strtolower($directory . DIRECTORY_SEPARATOR), strtolower($tempRoot . DIRECTORY_SEPARATOR)) || !str_starts_with(basename($directory), 'suxios-guest-operations-test-')) throw new RuntimeException('test_database_scope_invalid');
$app = new App(dirname(__DIR__, 2)); $app->initialize();
$config = Config::get('database'); $config['default'] = 'sqlite';
$config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => false];
Config::set($config, 'database');
$initialize = !is_file($path); Db::connect(null, true);
if ($initialize) \Tests\Support\GuestOperationsSqliteFixture::create();
$actor = (int)($input['actor'] ?? 11);
$user = $actor ? new User(Db::name('users')->where('id', $actor)->find()) : null;
$app->request->user = $user;
$reflection = new ReflectionClass(GuestOperations::class); $controller = $reflection->newInstanceWithoutConstructor();
$request = new class($input['params'] ?? [], $input['body'] ?? []) {
    public function __construct(private array $params, private array $body) {}
    public function param(string $name, mixed $default = null): mixed { return $this->params[$name] ?? $this->body[$name] ?? $default; }
    public function post(): array { return $this->body; }
    public function method(): string { return $this->body ? 'POST' : 'GET'; }
    public function getContent(): string { return ''; }
};
foreach (['currentUser' => $user, 'request' => $request] as $key => $value) $reflection->getParentClass()->getProperty($key)->setValue($controller, $value);
$allowed = ['overview', 'importStays', 'saveCoverage', 'saveFeedback', 'appendFact', 'saveEntry', 'read', 'history'];
$method = (string)($input['action'] ?? ''); if (!in_array($method, $allowed, true)) throw new RuntimeException('test_action_invalid');
$response = $controller->{$method}(...($input['args'] ?? []));
echo json_encode(['status' => $response->getCode(), 'body' => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
