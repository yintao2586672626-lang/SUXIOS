<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { throw new RuntimeException('Synthetic fixture is CLI-only.'); }
// Test-only CLI adapter: target-root services, independent synthetic SQLite files.
// No production routes, credentials, MySQL or external providers are loaded.
$root = realpath($argv[1] ?? '') ?: '';
$state = realpath($argv[2] ?? '') ?: '';
if (!$root || !$state || !str_contains(basename($state), 'l10-integrated-synthetic-')) {
    throw new RuntimeException('Explicit target root and synthetic state directory required');
}
require $root . '/tests/bootstrap.php';
use think\facade\Db;
use think\facade\Config;
use Tests\Support\PreciseQuerySyntheticFixture;
use Tests\Support\OperationTaskWorkflowFixture;
use app\service\OperationTaskWorkflowService;
use app\service\RevenueFactLayerService;
use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;

function isolatedPaths(string $state): void {
    Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>$state.'/cache/']]], 'cache');
    Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>$state.'/logs/']]], 'log');
}
function taskService(string $state): OperationTaskWorkflowService {
    $path = $state . '/tasks.sqlite'; $initialize = !is_file($path);
    OperationTaskWorkflowFixture::connect($path); isolatedPaths($state);
    if ($initialize) {
        OperationTaskWorkflowFixture::schema(); OperationTaskWorkflowFixture::seed();
        Db::name('hotels')->where('id', 7)->update(['id'=>80,'tenant_id'=>10]);
        Db::name('hotels')->where('id', 8)->update(['id'=>81,'tenant_id'=>10]);
        foreach (['operation_execution_intents','operation_execution_tasks'] as $table) {
            Db::name($table)->where('hotel_id',7)->update(['hotel_id'=>80,'tenant_id'=>10]);
            Db::name($table)->where('hotel_id',8)->update(['hotel_id'=>81,'tenant_id'=>10]);
        }
    }
    return new OperationTaskWorkflowService(static fn(): string => '2026-09-16 12:00:00',
        static fn(int $id, array $scope): bool => $id === 3 && in_array($scope['hotel_id'],[80,81],true));
}
try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $url = parse_url((string)$input['path']); parse_str($url['query'] ?? '', $query);
    $route = $url['path']; $body = $input['body'] ?? []; $method = $input['method'] ?? 'GET';
    isolatedPaths($state);
    if ($route === '/__fixture/init') {
        taskService($state);
        $db = $state . '/queries.sqlite'; PreciseQuerySyntheticFixture::connect($db,!is_file($db)); isolatedPaths($state);
        $data = ['root'=>$root,'query_class'=>(new ReflectionClass(app\service\PreciseQueryRouterService::class))->getFileName(),
            'workflow_class'=>(new ReflectionClass(OperationTaskWorkflowService::class))->getFileName(),'synthetic'=>true];
    } elseif ($route === '/api/dashboard/revenue-facts') {
        $hotel = (int)($query['hotel_id'] ?? 0); $date = (string)($query['business_date'] ?? '');
        if (!in_array($hotel,[80,81],true)) throw new RuntimeException('synthetic hotel forbidden',403);
        $data = (new RevenueFactLayerService())->assemble(
            ['id'=>$hotel,'tenant_id'=>10,'name'=>'synthetic 酒店 '.($hotel===80?'A':'B'),'status'=>1],
            $date, [], [], [], []
        );
    } elseif (str_starts_with($route, '/api/agent/precise-queries')) {
        $db = $state . '/queries.sqlite';
        PreciseQuerySyntheticFixture::connect($db, !is_file($db)); isolatedPaths($state);
        $router = PreciseQuerySyntheticFixture::router(static function(int $hotel,string $date): array {
            $result = PreciseQuerySyntheticFixture::closure($hotel,$date);
            if (in_array($date,['2026-09-02','2026-09-04'],true)) $result['platforms']['ctrip']['fields'] = [];
            return $result;
        });
        if ($route === '/api/agent/precise-queries' && $method === 'POST') $data = $router->route(10,[80,81],3,$body);
        elseif (preg_match('~^/api/agent/precise-queries/([1-9][0-9]*)$~D',$route,$match)) $data = $router->read((int)$match[1],10,[80,81]);
        else throw new RuntimeException('Unknown precise query fixture route',501);
    } elseif (str_starts_with($route, '/api/operation/')) {
        $service = taskService($state);
        $hotel = (int)($query['hotel_id'] ?? $body['hotel_id'] ?? 80);
        if (!in_array($hotel,[80,81],true)) throw new RuntimeException('synthetic hotel forbidden',403);
        if ($route === '/api/operation/task-workflows') $data = $service->listing([$hotel],$hotel);
        elseif (preg_match('~^/api/operation/execution-tasks/([1-9][0-9]*)/workflow$~D',$route,$match)) {
            $data = $method === 'POST' ? $service->mutate((int)$match[1],[$hotel],$body,3)
                : $service->read((int)$match[1],[$hotel],isset($query['version'])?(int)$query['version']:null);
        } elseif ($route === '/api/operation/execution-flow' || $route === '/api/operation/my-tasks') {
            $reader = new ExecutionFlowReadService(new ExecutionOutcomeService());
            $items = [];
            foreach (Db::name('operation_execution_intents')->where('hotel_id',$hotel)->select()->toArray() as $intent) {
                if (isset($query['intent_id']) && (int)$query['intent_id'] !== (int)$intent['id']) continue;
                $tasks = Db::name('operation_execution_tasks')->where('intent_id',$intent['id'])->select()->toArray();
                $items[] = $reader->buildItem($intent,$tasks,[]);
            }
            $summary = $reader->buildSummary($items);
            $data = ['list'=>$items,'summary'=>$summary,'stages'=>$reader->buildStages($summary),'data_status'=>'synthetic',
                'scope'=>['hotel_id'=>$hotel,'user_id'=>3],
                'capabilities'=>['hotel_id'=>$hotel,'can_view'=>true,'can_execute'=>true],
                'data_gaps'=>['synthetic_source','field_effect_unverified']];
        } else throw new RuntimeException('Unknown operations fixture route',501);
    } else throw new RuntimeException('Unknown synthetic fixture route',501);
    echo json_encode(['code'=>200,'message'=>'synthetic target-root service','data'=>$data], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (str_starts_with($route ?? '', '/api/agent/precise-queries')) {
        $errors = (new ReflectionClass(app\controller\PreciseQuery::class))->getConstant('BUSINESS_ERRORS');
        $response = app\service\ApiExceptionMapper::response($error,'synthetic precise query failed',$errors);
        echo $response->getContent(); exit;
    }
    $status = in_array($error->getCode(), [400,403,404,409,422,429,501,503],true) ? $error->getCode()
        : ($error instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['code'=>$status,'message'=>$error->getMessage(),'synthetic'=>true], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
