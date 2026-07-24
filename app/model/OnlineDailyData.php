<?php
declare(strict_types=1);

namespace app\model;

use app\model\base\BaseTenantModel;

class OnlineDailyData extends BaseTenantModel
{
    protected $name = 'online_daily_data';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'system_hotel_id' => 'integer',
        'data_source_id' => 'integer',
        'sync_task_id' => 'integer',
        'quantity' => 'integer',
        'readback_verified' => 'integer',
        'is_final' => 'integer',
    ];
}
