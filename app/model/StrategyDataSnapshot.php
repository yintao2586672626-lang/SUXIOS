<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class StrategyDataSnapshot extends Model
{
    protected $name = 'strategy_data_snapshots';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'raw_json' => 'json',
        'normalized_json' => 'json',
    ];

    protected $json = ['raw_json', 'normalized_json'];
    protected $jsonAssoc = true;
}
