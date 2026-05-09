<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class StrategySimulationRecord extends Model
{
    protected $name = 'strategy_simulation_records';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'property_area' => 'float',
        'room_count' => 'integer',
        'monthly_rent' => 'float',
        'decoration_budget' => 'float',
        'lease_years' => 'integer',
        'rent_free_months' => 'integer',
        'competitor_count' => 'integer',
        'input_json' => 'json',
        'data_snapshot_json' => 'json',
        'score_json' => 'json',
        'recommendation_json' => 'json',
        'risk_json' => 'json',
        'created_by' => 'integer',
    ];

    protected $json = [
        'input_json',
        'data_snapshot_json',
        'score_json',
        'recommendation_json',
        'risk_json',
    ];
    protected $jsonAssoc = true;
}
