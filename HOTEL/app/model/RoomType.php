<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 房型模型
 * 用于收益管理Agent的房型定价
 */
class RoomType extends Model
{
    protected $name = 'room_types';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'base_price' => 'float',
        'min_price' => 'float',
        'max_price' => 'float',
        'room_count' => 'integer',
        'sort_order' => 'integer',
        'is_enabled' => 'integer',
        'facilities' => 'json',
    ];
    
    protected $json = ['facilities'];
    protected $jsonAssoc = true;

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联定价建议
     */
    public function priceSuggestions()
    {
        return $this->hasMany(PriceSuggestion::class, 'room_type_id', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - 状态
     */
    public function searchIsEnabledAttr($query, $value)
    {
        $query->where('is_enabled', $value);
    }

    /**
     * 搜索器 - 名称
     */
    public function searchNameAttr($query, $value)
    {
        $query->whereLike('name', '%' . $value . '%');
    }

    /**
     * 获取酒店的房型列表
     */
    public static function getHotelRoomTypes(int $hotelId, bool $onlyEnabled = true)
    {
        $query = self::where('hotel_id', $hotelId);
        if ($onlyEnabled) {
            $query->where('is_enabled', self::STATUS_ENABLED);
        }
        return $query->order('sort_order', 'asc')->select();
    }
}
