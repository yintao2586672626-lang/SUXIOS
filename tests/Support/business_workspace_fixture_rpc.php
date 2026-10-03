<?php
declare(strict_types=1);

// CLI-only isolated fixture. Never use the application database or credentials.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();restore_error_handler();restore_exception_handler();
$path=(string)($argv[1]??'');
if(!str_starts_with(basename($path),'business-workspace-browser-')||!str_ends_with($path,'.sqlite'))throw new RuntimeException('isolated fixture path required');
think\facade\Config::set(['default'=>'workspace_browser','connections'=>['workspace_browser'=>['type'=>'sqlite','database'=>$path,'prefix'=>'','fields_strict'=>true]]],'database');
think\facade\Db::connect(null,true);
think\facade\Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER,name TEXT)');
think\facade\Db::execute("INSERT OR IGNORE INTO hotels VALUES(80,10,'合成酒店A'),(81,10,'合成酒店B')");
think\facade\Db::execute('CREATE TABLE IF NOT EXISTS hotel_business_workspace_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,owner_user_id INTEGER,kind TEXT,previous_id INTEGER,idempotency_key TEXT,payload_json TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,owner_user_id,kind,idempotency_key))');
$call=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$parts=parse_url((string)$call['url']);parse_str($parts['query']??'',$query);$body=$call['options']['body']??[];
if(is_string($body))$body=json_decode($body,true,512,JSON_THROW_ON_ERROR);
$route=$parts['path'];$method=match(true){$route==='/business-workspace/overview'=>'overview',$route==='/business-workspace/snapshots'=>'save',$route==='/business-workspace/source-preview'=>'mappingPreview',preg_match('#^/business-workspace/snapshots/(\d+)$#D',$route,$match)===1=>'read',default=>throw new RuntimeException('fixture route not allowed')};
if($method==='read')$query['id']=(int)$match[1];
$controller=(new ReflectionClass(app\controller\BusinessWorkspace::class))->newInstanceWithoutConstructor();
$request=new class($query,$body){public function __construct(private array $query,private array $body){}
    public function param($key=null,$default=null):mixed{return$key===null?$this->query+$this->body:($this->query[$key]??$this->body[$key]??$default);}
    public function post():array{return$this->body;}public function method():string{return$this->body?'POST':'GET';}public function getContent():string{return'';}
};
$user=new class{public int $id=7;public int $tenant_id=10;public function getPermittedHotelIds():array{return[80,81];}public function hasHotelPermission(int $hotel,string $capability):bool{return in_array($hotel,[80,81],true)&&in_array($capability,['operation.view','operation.execute'],true);}};
(new ReflectionProperty(app\controller\BusinessWorkspace::class,'request'))->setValue($controller,$request);
(new ReflectionProperty(app\controller\BusinessWorkspace::class,'currentUser'))->setValue($controller,$user);
echo json_encode($controller->{$method}()->getData(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
think\facade\Db::connect()->close();
