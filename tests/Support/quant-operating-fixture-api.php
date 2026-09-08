<?php
declare(strict_types=1);
// Explicit synthetic-only CLI adapter. No app initialization, credentials or shared database.
require dirname(__DIR__) . '/bootstrap.php';
use Tests\Support\QuantOperatingFixture;
use app\service\QuantSimulationService;
use Tests\Support\QuantOperatingControllerFixture;

$database = $argv[1] ?? '';
if (!str_contains(basename($database), 'l09-synthetic-') || !str_ends_with($database, '.sqlite')) {
    throw new RuntimeException('Only an explicitly named L09 synthetic SQLite file is allowed');
}
QuantOperatingFixture::database($database);
$body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$service = new QuantSimulationService();
try {
    if (in_array($body['action'], ['calculate','detail','records'], true)) {
        $controller=(new QuantOperatingControllerFixture(app()))->input($body['payload'] ?? []);
        $response=match ($body['action']) {
            'calculate'=>$controller->calculate(), 'detail'=>$controller->detail((int)$body['id']), 'records'=>$controller->records(),
        };
        echo json_encode($response->getData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = match ($body['action']) {
        'input' => array_merge(QuantOperatingFixture::calculate(QuantOperatingFixture::input())[0], ['hotel_id'=>901]),
        'calculate' => $service->calculateAndSave($body['payload'], 91, [901]),
        'detail' => $service->detail((int)$body['id'], 91, false),
        'records' => ['list' => $service->records(91, false)],
        default => throw new RuntimeException('Unsupported synthetic action'),
    };
    echo json_encode(['code'=>200,'message'=>'synthetic fixture','data'=>$data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['code'=>400,'message'=>$e->getMessage(),'data'=>null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
