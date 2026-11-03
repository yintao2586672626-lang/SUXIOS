<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
$path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
$root=dirname(__DIR__,3);
$assets=['/'=>'tests/fixtures/hotel-learning/index.html','/vue.runtime.global.prod.js'=>'public/vue.runtime.global.prod.js','/components/system/hotel-learning-workbench.min.js'=>'public/components/system/hotel-learning-workbench.min.js'];
if(isset($assets[$path])) { header('Content-Type: '.($path==='/'?'text/html; charset=utf-8':'text/javascript; charset=utf-8'));header('Cache-Control: no-store');readfile($root.'/'.$assets[$path]);exit; }
if(!preg_match('#^/api/hotel-learning/(overview|preview|snapshots)(?:/([1-9]\d*))?$#D',$path,$match)) { http_response_code(404);exit; }
$database=(string)getenv('SUXIOS_LEARNING_TEST_DB');
if($database==='' || !str_contains(basename($database),'hotel-learning') || !str_ends_with($database,'.sqlite')) { http_response_code(503);echo 'Dedicated synthetic database not configured';exit; }
require __DIR__.'/bootstrap.php';
HotelLearningSyntheticEnvironment::connect($database);
$payload=$_SERVER['REQUEST_METHOD']==='POST'?json_decode(file_get_contents('php://input'),true):$_GET;
$action=$match[1]==='snapshots'?(!empty($match[2])?'read':'save'):$match[1];
$result=HotelLearningSyntheticEnvironment::dispatch($action,is_array($payload)?$payload:[],(int)($match[2]??0));
http_response_code($result['http_status']);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($result['body'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
