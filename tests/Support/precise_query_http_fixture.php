<?php
declare(strict_types=1);
// Isolated synthetic HTTP adapter. It never bootstraps the production route table.
require dirname(__DIR__).'/bootstrap.php';
use Tests\Support\PreciseQuerySyntheticFixture as Fixture;
use app\service\ApiExceptionMapper;

$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=dirname(__DIR__,2);
$assets=['/vue.js'=>'vue.global.prod.js','/analyst.js'=>'components/system/hotel-data-analyst-components.js',
    '/query.js'=>'components/system/operating-intelligence-components.js','/style.css'=>'style.css'];
if (isset($assets[$path])) {header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript'));readfile($root.'/public/'.$assets[$path]);return;}
if ($path==='/') {header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/precise_query_fixture.html');return;}
$database=(string)getenv('L03_SYNTHETIC_DATABASE');
if ($database==='' || !str_contains($database,'l03-synthetic-http-')) {http_response_code(500);echo 'Synthetic database required';return;}
Fixture::connect($database,!is_file($database));
$router=Fixture::router(static function(int $hotel,string $date): array {
    $c=Fixture::closure($hotel,$date);
    if (in_array($date,['2026-09-02','2026-09-04'],true)) $c['platforms']['ctrip']['fields']=[];
    return $c;
});
header('Content-Type: application/json; charset=utf-8');
try {
    if ($path==='/api/agent/precise-queries' && $_SERVER['REQUEST_METHOD']==='POST') {
        $payload=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
        $data=$router->route(10,[80,81],7,$payload);
    } elseif (preg_match('~^/api/agent/precise-queries/([1-9][0-9]*)$~D',$path,$m)) {
        $data=$router->read((int)$m[1],10,[80,81]);
    } else { $data=['data_status'=>'empty','synthetic'=>true]; }
    echo json_encode(['code'=>200,'message'=>'synthetic fixture','data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $errors=(new ReflectionClass(app\controller\PreciseQuery::class))->getConstant('BUSINESS_ERRORS');
    $response=ApiExceptionMapper::response($e,'synthetic fixture failed',$errors);
    http_response_code($response->getCode());echo $response->getContent();
}
