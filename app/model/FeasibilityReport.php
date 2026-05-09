<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class FeasibilityReport extends Model
{
    protected $name = 'feasibility_reports';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['input_json', 'snapshot_json', 'report_json'];
    protected $jsonAssoc = true;

    protected $type = [
        'id' => 'integer',
        'input_json' => 'json',
        'snapshot_json' => 'json',
        'report_json' => 'json',
        'payback_months' => 'float',
        'total_investment' => 'float',
        'created_by' => 'integer',
    ];
}
