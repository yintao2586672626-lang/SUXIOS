<?php
declare(strict_types=1);
namespace Tests\Support;

use app\service\PreciseQueryRouterService;
use app\service\SystemUsageAssistantService;
use DateTimeImmutable;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Synthetic data only. No real database or OTA request is made. */
final class PreciseQuerySyntheticFixture
{
    public static function connect(string $path, bool $initialize = true): void
    {
        (new App(dirname(__DIR__, 2)))->initialize();
        Config::set(['default'=>'sqlite', 'connections'=>['sqlite'=>[
            'type'=>'sqlite','database'=>$path,'prefix'=>'','fields_strict'=>false,
        ]]], 'database');
        Db::connect(null, true);
        if (!$initialize) return;
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER)');
        Db::execute("INSERT INTO hotels VALUES (80,10,'湖畔酒店',1),(81,10,'湖畔酒店',1),(82,11,'江南客栈',1),(90,12,'禁止访问酒店',1)");
        Db::execute('CREATE TABLE hotel_operating_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, UNIQUE(tenant_id,hotel_id,request_key))');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, platform TEXT, data_date TEXT, validation_status TEXT, history_status TEXT, readback_verified INTEGER)');
    }

    public static function router(?\Closure $reader = null): PreciseQueryRouterService
    {
        return new PreciseQueryRouterService(
            systemGuideResolver: static fn(array $payload): array => (new SystemUsageAssistantService())->guide($payload),
            knowledgeResolver: static fn(): array => [],
            clock: static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-07T16:30:00Z'),
            fieldClosureReader: $reader ?? static fn(int $hotel, string $date): array => self::closure($hotel, $date)
        );
    }

    public static function closure(int $hotel, string $date): array
    {
        $day = (int)substr($date, -2);
        $tenant = $hotel === 82 ? 11 : 10;
        $platforms = [];
        foreach (['ctrip','meituan'] as $platform) {
            $fields = [];
            foreach (['revenue'=>100+$day,'order_count'=>$day,'room_nights'=>$day+1,'exposure'=>$day*100,'visits'=>$day*10,'conversion'=>10] as $key=>$value) {
                $ref = 'online_daily_data#' . ((int)str_replace('-','',$date) * 1000 + $hotel * 10 + ($platform === 'ctrip' ? 1 : 2));
                $fields[] = [
                    'key'=>$key,'metric_key'=>$key,'label'=>$key,'value'=>$value,
                    'unit'=>match($key) {'revenue'=>'CNY','order_count'=>'orders','room_nights'=>'room_nights','conversion'=>'percent',default=>'people'},
                    'semantic_metric_key'=>match($key) {'exposure'=>$platform.'_exposure_users','visits'=>$platform.'_detail_visitors','conversion'=>'exposure_to_visit_rate',default=>$key},
                    'semantic_contract_version'=>'ota_field_semantics.v1','semantic_metric_status'=>'source_defined',
                    'status'=>'strict_readback','readback_status'=>'readback_verified','validation_status'=>'verified',
                    'strict_final_gate'=>true,'revenue_analysis_consumable'=>true,'history_statuses'=>['success'],
                    'source_record_refs'=>[$ref], 'collected_at'=>$date.'T23:10:00+08:00',
                    'tenant_id'=>$tenant,'system_hotel_id'=>$hotel,'platform'=>$platform,'platform_store_id'=>'synthetic-'.$platform.'-'.$hotel,
                    'business_date'=>$date,'source_method'=>'synthetic',
                    'source_paths'=>['synthetic.business_market_overview.order_amount'],
                    'field_fact_identities'=>[['normalized_metric_key'=>$key === 'revenue' ? 'order_amount' : $key,'source_key'=>'order_amount','source_path'=>'synthetic.business_market_overview.order_amount']],
                ];
            }
            $platforms[$platform] = ['fields'=>$fields];
        }
        return ['contract_version'=>'dual_ota_field_closure.v1','tenant_id'=>$tenant,'hotel_id'=>$hotel,'business_date'=>$date,
            'metric_scope'=>'ota_channel_only','page_identity'=>'synthetic:'.$hotel.':'.$date,'closure_digest'=>hash('sha256',$hotel.$date),
            'consumer_contract'=>['contract_version'=>'trusted_ota_daily_fact_consumer.v1','allowed_fact_statuses'=>['strict_readback','verified_calculation']],
            'platforms'=>$platforms];
    }
}
