<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class UserHotelPermission extends Model
{
    protected $name = 'user_hotel_permissions';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'hotel_id' => 'integer',
        'can_view_online_data' => 'integer',
        'can_fetch_online_data' => 'integer',
        'can_delete_online_data' => 'integer',
        'is_primary' => 'integer',
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 权限说明
     */
    public static function getPermissionLabels(): array
    {
        return [
            'can_view_online_data' => '查看线上数据',
            'can_fetch_online_data' => '获取线上数据',
            'can_delete_online_data' => '删除线上数据',
        ];
    }
}
