<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

final class BusinessFeatureCatalog
{
    public const CONTRACT = 'business_feature_catalog.v1';
    public static function modules(): array
    {
        $rows = [
            [24,'OTA数据','online-data','',1], [19,'收益期预订监测','finance','booking',1],
            [26,'广告数据','ctrip-ebooking','ctrip-ads',1], [25,'价格数据','ctrip-ebooking','ctrip-market-competition',1],
            [18,'收益驾驶舱','trusted-revenue-analysis','',1], [1,'工作台','compass','',1],
            [2,'酒店管理','hotels','',1], [15,'间夜与均价联动','revenue-research-center','',1],
            [17,'点评数据','ctrip-ebooking','ctrip-review-match',1], [30,'数据来源配置','data-config','',1],
            [6,'月任务与经营预算','operating-targets','',1], [7,'多店经营比较','finance','portfolio',1],
            [31,'操作日志','operation-logs','',1], [3,'用户管理','users','',1], [4,'角色管理','roles','',1],
            [27,'系统设置','system-config','',1], [29,'来源接口与字段映射','source','',2],
            [10,'酒店周报','review','weekly_review',2], [12,'店总周报','review','manager_review',2],
            [8,'复购率','guests','repeat',2], [9,'宾客舆情与客诉','guests','feedback',2],
            [23,'反馈跟进','guests','feedback',2], [22,'反馈二维码配置','guests','entry',2],
            [16,'OTA目标与措施复盘','review','ota_review',2],
            [14,'抖音与营销作品数据','campaigns','campaign',3], [20,'节日营销海报','campaigns','poster',3],
            [21,'酒店短视频','campaigns','video',3], [5,'必要日报与补录','campaigns','daily',3],
            [28,'日报接口适配','source','daily',3], [11,'班次交接','campaigns','shift',3],
            [13,'报表补录与记录核对','campaigns','reconcile',3],
        ];
        return array_map(static fn(array $row, int $rank): array => [
            'module_id'=>$row[0], 'name'=>$row[1], 'target'=>$row[2], 'tab'=>$row[3],
            'phase'=>$row[4], 'rank'=>$rank+1,
            'availability'=>in_array($row[2],['guests','campaigns','review','source'],true)?'workspace':'native',
        ], $rows, array_keys($rows));
    }

    public static function defaults(): array
    {
        return ['profile'=>'self_investment','preferred_platform'=>'ctrip',
            'booking_fixed_time'=>'09:00','booking_horizon_days'=>7,'repeat_window_days'=>90,
            'review_cycle'=>'weekly','modules'=>array_map(static fn(array $row):array=>[
                'module_id'=>$row['module_id'],'enabled'=>true,'phase'=>$row['phase'],'rank'=>$row['rank'],
            ],self::modules())];
    }

    public static function normalize(array $input): array
    {
        if (!in_array($input['profile']??null,['self_investment','third_party_ota','custom'],true)
            || !in_array($input['preferred_platform']??null,['ctrip','meituan'],true)
            || !in_array($input['review_cycle']??null,['weekly','monthly'],true)) throw new InvalidArgumentException('business_configuration_profile_invalid');
        $time=(string)($input['booking_fixed_time']??'');
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$time)) throw new InvalidArgumentException('business_configuration_time_invalid');
        foreach(['booking_horizon_days'=>[1,30],'repeat_window_days'=>[7,365]] as $key=>$range) {
            if(!is_int($input[$key]??null)||$input[$key]<$range[0]||$input[$key]>$range[1]) throw new InvalidArgumentException('business_configuration_window_invalid');
        }
        $modules=$input['modules']??null;
        if(!is_array($modules)||!array_is_list($modules)||count($modules)!==31) throw new InvalidArgumentException('business_configuration_requires_all_31_modules');
        $ids=array_column(self::modules(),'module_id'); $seen=[]; $ranks=[]; $normalized=[];
        foreach($modules as $module) {
            if(!is_array($module)||!is_int($module['module_id']??null)||!in_array($module['module_id'],$ids,true)
                ||isset($seen[$module['module_id']])||!is_bool($module['enabled']??null)
                ||!is_int($module['phase']??null)||$module['phase']<1||$module['phase']>3
                ||!is_int($module['rank']??null)||$module['rank']<1||$module['rank']>31||isset($ranks[$module['rank']])) throw new InvalidArgumentException('business_configuration_module_invalid');
            $seen[$module['module_id']]=true; $ranks[$module['rank']]=true;
            $normalized[]=['module_id'=>$module['module_id'],'enabled'=>$module['enabled'],'phase'=>$module['phase'],'rank'=>$module['rank']];
        }
        usort($normalized,static fn(array $a,array $b):int=>$a['rank']<=>$b['rank']);
        return ['profile'=>$input['profile'],'preferred_platform'=>$input['preferred_platform'],
            'booking_fixed_time'=>$time,'booking_horizon_days'=>$input['booking_horizon_days'],
            'repeat_window_days'=>$input['repeat_window_days'],'review_cycle'=>$input['review_cycle'],'modules'=>$normalized];
    }
}
