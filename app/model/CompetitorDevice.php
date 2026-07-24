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

    protected $hidden = [
        'token_hash',
    ];

    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'store_id' => 'integer',
        'status' => 'integer',
        'token_version' => 'integer',
    ];
}
