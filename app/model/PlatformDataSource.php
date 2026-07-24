<?php
declare(strict_types=1);

namespace app\model;

use app\model\base\BaseTenantModel;

class PlatformDataSource extends BaseTenantModel
{
    protected $name = 'platform_data_sources';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'system_hotel_id' => 'integer',
        'user_id' => 'integer',
        'enabled' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];
}
