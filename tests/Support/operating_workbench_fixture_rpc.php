<?php
declare(strict_types=1);

// CLI-only synthetic API acceptance. No real database, account or upstream.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize(); restore_error_handler(); restore_exception_handler();
$path = (string)($argv[1] ?? '');
if (!str_starts_with(basename($path), 'operating-workbench-browser-') || !str_ends_with($path, '.sqlite')) throw new RuntimeException('isolated fixture path required');
$fresh = !is_file($path);
think\facade\Config::set(['default' => 'workbench_browser', 'connections' => ['workbench_browser' => ['type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => true]]], 'database');
think\facade\Db::connect(null, true);
if ($fresh) {
    Tests\Support\OperatingWorkbenchSqliteFixture::create();
    foreach ([80=>12,81=>9] as $hotel=>$rooms) (new app\service\BookingMonitoringService())->saveSnapshots(10,[80,81],[['hotel_id'=>$hotel,'platform'=>'ctrip','fact_scope'=>'ota_channel','stay_date'=>'2026-10-04','captured_at'=>'2026-10-03 09:00:00','on_books_room_nights'=>$rooms,'source_ref'=>'synthetic booking scope','source_method'=>'manual_entry','operator_attested'=>true]],7);
    for ($date = new DateTimeImmutable('2026-09-27'); $date->format('Y-m-d') <= '2026-10-03'; $date = $date->modify('+1 day')) {
        $day = $date->format('Y-m-d');
        think\facade\Db::name('daily_reports')->insert(['tenant_id' => 10, 'hotel_id' => 80, 'report_date' => $day, 'status' => 2,
            'report_data' => json_encode(['revenue'=>100,'online_revenue'=>60,'offline_revenue'=>40,'total_rooms'=>10,'overnight_rooms'=>8,'salable_rooms'=>20])]);
        (new app\service\OperatingTargetService())->save(10,80,7,['target_date'=>$day,'target_revenue'=>200,'actual_revenue'=>100,
            'fact_scope'=>'whole_hotel','source_type'=>'manual','source_reference'=>'synthetic-day#'.$day,'quality_status'=>'manual_confirmed','change_reason'=>'synthetic seed']);
    }
}
$call=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$parts=parse_url((string)$call['url']);parse_str($parts['query']??'',$query);$body=$call['options']['body']??[];
if(is_string($body))$body=json_decode($body,true,64,JSON_THROW_ON_ERROR);
$method=match(true){$parts['path']==='/operating-workbench/overview'=>'overview',$parts['path']==='/operating-workbench/report'=>'report',
    $parts['path']==='/operating-workbench/appeals'=>'cases',$parts['path']==='/operating-workbench/booking'=>'booking',
    $parts['path']==='/operating-workbench/snapshots'=>'save',preg_match('#^/operating-workbench/snapshots/(\d+)$#D',$parts['path'],$match)===1=>'read',default=>throw new RuntimeException('fixture route not allowed')};
if($method==='read')$query['id']=(int)$match[1];
$request=new class($query,$body){public function __construct(private array $query,private array $body){}
    public function param($key=null,$default=null):mixed{return$key===null?$this->query+$this->body:($this->query[$key]??$this->body[$key]??$default);}
    public function post():array{return$this->body;}public function method():string{return$this->body?'POST':'GET';}public function getContent():string{return'';}
};
$user=new class{public int $id=7;public int $tenant_id=10;public function getPermittedHotelIds():array{return[80,81,82];}
    public function hasHotelPermission(int $hotel,string $capability):bool{return in_array($hotel,[80,81,82],true)&&in_array($capability,['operation.view','operation.execute'],true);}};
$controller=(new ReflectionClass(app\controller\OperatingWorkbench::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(app\controller\OperatingWorkbench::class,'request'))->setValue($controller,$request);
(new ReflectionProperty(app\controller\OperatingWorkbench::class,'currentUser'))->setValue($controller,($call['anonymous']??false)?null:$user);
echo json_encode($controller->{$method}()->getData(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
think\facade\Db::connect()->close();
