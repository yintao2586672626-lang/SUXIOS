<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class CompetitorHotel extends Model
{
    protected $name = 'competitor_hotel';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'store_id' => 'integer',
        'status' => 'integer',
    ];
}
