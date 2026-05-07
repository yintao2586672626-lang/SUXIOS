<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class CompetitorPriceLog extends Model
{
    protected $name = 'competitor_price_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'store_id' => 'integer',
        'hotel_id' => 'integer',
        'price' => 'float',
    ];
}
