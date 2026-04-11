<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class CompetitorDevice extends Model
{
    protected $name = 'competitor_device';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
    ];
}
