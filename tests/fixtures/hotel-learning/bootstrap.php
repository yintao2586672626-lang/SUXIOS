<?php
declare(strict_types=1);
// This fixture is deliberately outside public/. It cannot run in a production PHP SAPI.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/bootstrap.php';

use app\controller\HotelLearning;
use app\model\User;
use think\App;
use think\Request;
use think\facade\Config;
use think\facade\Db;

final class HotelLearningSyntheticUser extends User
{
    public function isSuperAdmin(): bool { return true; }
    public function getPermittedHotelIds(): array { return [80, 81, 82]; }
}

final class HotelLearningSyntheticEnvironment
{
    public static App $app;
    public static function connect(string $path): array
    {
        if (!str_contains(basename($path), 'hotel-learning') || !str_ends_with($path, '.sqlite')) throw new RuntimeException('Dedicated synthetic SQLite path required');
        self::$app = (new App())->initialize();
        $previous = Config::get('database');
        $config = $previous; $config['default'] = 'hotel_learning_test';
        $config['connections']['hotel_learning_test'] = ['type'=>'sqlite','database'=>$path,'prefix'=>'','fields_strict'=>false];
        Config::set($config, 'database'); Db::connect(null, true);
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER,status INTEGER)');
        Db::execute('INSERT OR IGNORE INTO hotels VALUES (80,7,1),(81,8,1),(82,7,1)');
        Db::execute('CREATE TABLE IF NOT EXISTS hotel_operating_evidence_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,period_month TEXT,platform TEXT,payload_json TEXT,content_digest TEXT,idempotency_key TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
        return $previous;
    }
    public static function dispatch(string $action, array $payload, int $id = 0, bool $authenticated = true): array
    {
        $request = (new Request())->setMethod(in_array($action,['save','preview'],true) ? 'POST' : 'GET')->withPost($payload)->withGet($payload);
        $user = $authenticated ? new HotelLearningSyntheticUser(['id'=>7,'tenant_id'=>7]) : null;
        $request->user = $user;
        self::$app->instance('request', $request);
        $controller = new HotelLearning(self::$app);
        (new ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        (new ReflectionProperty($controller, 'currentUser'))->setValue($controller, $user);
        $response = $action === 'read' ? $controller->read($id) : $controller->{$action}();
        return ['http_status'=>$response->getCode(),'body'=>$response->getData()];
    }
    public static function scope(string $mode): array
    {
        return ['hotel_id'=>80,'period_month'=>'2026-10','platform'=>in_array($mode,['ota_scene','market_sample'],true)?'ctrip':'whole_hotel','mode'=>$mode];
    }
    public static function inputs(string $mode): array
    {
        $review=['period_start'=>'2026-10-01','period_end'=>'2026-10-31','source_ref'=>'synthetic-monthly','basis'=>'whole_hotel','available_room_nights'=>1000,'sold_room_nights'=>800,'revenue'=>100000,'operating_cost'=>70000,'debt_service'=>2000,'project_net_cash'=>28000,'investor_received_cash'=>0];
        $scenario=(new \app\service\InvestmentScenarioCalculator())->referenceExample();
        $scenario=array_replace($scenario,['scenario_name'=>'合成一年反求','as_of'=>'2026-10-01','rooms'=>1,'leased_rooms'=>0,'years'=>1,'adr_first_year'=>100,'occupancy_first_year'=>1,'occupancy_mature'=>1,'operating_cost_per_night'=>0,'monthly_rent_per_room'=>0,'renovation_cash'=>36500,'franchise_cash'=>0,'refundable_deposit_cash'=>0,'other_initial_cash'=>0,'working_capital_cash'=>0,'depreciable_amount'=>0,'management_fee_rate'=>0,'cash_adjustments'=>[['year'=>1,'tax_cash'=>0,'financing_net_cash'=>0,'maintenance_capex'=>0,'working_capital_change'=>0,'deposit_refund'=>0,'salvage_cash'=>0]]]);
        return match($mode) {
            'profile'=>['fields'=>[['key'=>'room_count','value'=>'60','unit'=>'间','source_ref'=>'synthetic-floorplan','as_of'=>'2026-10-02','quality'=>'operator_attested']]],
            'consumables_reconciliation'=>['occupied_room_nights'=>100,'occupied_room_nights_source_ref'=>'synthetic-pms','denominator_scope'=>'whole_hotel','operator_attested'=>true,'cleaning_count'=>50,'cleaning_count_source_ref'=>'synthetic-cleaning','items'=>[['id'=>'synthetic-towel','name'=>'合成用品','enabled'=>true,'unit'=>'piece','source_ref'=>'synthetic-stock','source_date'=>'2026-10-02','opening_quantity'=>30,'purchased_quantity'=>100,'transfer_in_quantity'=>0,'closing_quantity'=>20,'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>10,'unit_price'=>2,'issued_quantity'=>90,'issued_quantity_source_ref'=>'synthetic-issue','book_closing_quantity'=>25,'book_closing_quantity_source_ref'=>'synthetic-book']]],
            'investment_target'=>['scenario'=>$scenario,'request'=>['solve_for'=>'adr','bounds'=>['lower'=>0,'upper'=>200],'target_payback_months'=>12]],
            'contract_review'=>['as_of'=>'2026-10-01','payback_months'=>12,'constraints'=>['contract_start_on'=>'2026-10-01','contract_end_on'=>'2027-10-01','contract_source'=>'synthetic-lease','contract_confirmed'=>true,'target_payback_months'=>12]],
            'ota_scene'=>['scene'=>['keyword'=>'合成地区酒店','location'=>'合成地区','device'=>'desktop','login_state'=>'anonymous','sort'=>'推荐','filters'=>'无','observed_at'=>'2026-10-02T12:00','source_ref'=>'synthetic-screen','platform_store_id'=>'store-80','check_in'=>'2026-10-10','check_out'=>'2026-10-11','page_capacity'=>20],'visibility'=>'observed','rank_min'=>3,'rank_max'=>5,'price'=>100,'price_terms'=>['room_type'=>'双床','cancellation'=>'可取消','breakfast'=>'双早','guest_count'=>'2','membership'=>'非会员','tax_basis'=>'含税','payment'=>'预付'],'conversion_rate'=>1,'rate_unit'=>'percentage_point'],
            'market_sample'=>['sample_ref'=>'synthetic-sample','model_version'=>'manual-v1','comparison_key'=>'same-scene','weights'=>['traffic'=>0.4,'conversion'=>0.3,'revenue'=>0.3],'hotels'=>[['platform_store_id'=>'store-80','name'=>'合成A','comparison_key'=>'same-scene','traffic'=>100,'conversion'=>1,'rate_unit'=>'percentage_point','revenue'=>1000],['platform_store_id'=>'store-81','name'=>'合成B','comparison_key'=>'same-scene','traffic'=>200,'conversion'=>2,'rate_unit'=>'percentage_point','revenue'=>2000]]],
            'operating_review'=>['period_start'=>'2026-10-01','period_end'=>'2026-10-31','actual'=>$review,'plan'=>array_replace($review,['source_ref'=>'synthetic-plan','revenue'=>120000])],
            'geo_observation'=>['question'=>'合成酒店问答','model'=>'manual-record','model_version'=>'v1','region'=>'合成地区','network'=>'合成环境','observed_at'=>'2026-10-02T12:00','response_summary'=>'合成回答摘要','source_ref'=>'synthetic-record','citations'=>[['url'=>'https://example.com/evidence','fact_consistency'=>'unverified']]],
            default=>throw new InvalidArgumentException('Unknown synthetic mode'),
        };
    }
}
