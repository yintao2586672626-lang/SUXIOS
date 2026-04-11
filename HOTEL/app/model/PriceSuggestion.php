<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 定价建议模型
 * 用于收益管理Agent生成定价建议
 */
class PriceSuggestion extends Model
{
    protected $name = 'price_suggestions';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'room_type_id' => 'integer',
        'suggestion_type' => 'integer',
        'status' => 'integer',
        'current_price' => 'float',
        'suggested_price' => 'float',
        'min_price' => 'float',
        'max_price' => 'float',
        'confidence_score' => 'float',
        'competitor_data' => 'json',
        'factors' => 'json',
        'applied_by' => 'integer',
    ];
    
    protected $json = ['competitor_data', 'factors'];
    protected $jsonAssoc = true;

    // 建议类型常量
    const TYPE_DYNAMIC = 1;      // 动态定价
    const TYPE_COMPETITOR = 2;   // 竞对跟价
    const TYPE_EVENT = 3;        // 事件驱动
    const TYPE_FORECAST = 4;     // 预测驱动

    // 状态常量
    const STATUS_PENDING = 1;      // 待审批
    const STATUS_APPROVED = 2;     // 已批准
    const STATUS_REJECTED = 3;     // 已拒绝
    const STATUS_APPLIED = 4;      // 已应用
    const STATUS_EXPIRED = 5;      // 已过期

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联房型
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    /**
     * 关联审批人
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'applied_by', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - 房型
     */
    public function searchRoomTypeIdAttr($query, $value)
    {
        $query->where('room_type_id', $value);
    }

    /**
     * 搜索器 - 状态
     */
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
    }

    /**
     * 搜索器 - 日期范围
     */
    public function searchDateRangeAttr($query, $value)
    {
        if (isset($value['start']) && isset($value['end'])) {
            $query->whereBetween('suggestion_date', [$value['start'], $value['end']]);
        }
    }

    /**
     * 获取建议类型名称
     */
    public function getSuggestionTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_DYNAMIC => '动态定价',
            self::TYPE_COMPETITOR => '竞对跟价',
            self::TYPE_EVENT => '事件驱动',
            self::TYPE_FORECAST => '预测驱动',
        ];
        return $names[$data['suggestion_type']] ?? '未知';
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_PENDING => '待审批',
            self::STATUS_APPROVED => '已批准',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_APPLIED => '已应用',
            self::STATUS_EXPIRED => '已过期',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 计算价格变化百分比
     */
    public function getPriceChangePercentAttr($value, $data)
    {
        if ($data['current_price'] > 0) {
            $change = $data['suggested_price'] - $data['current_price'];
            return round(($change / $data['current_price']) * 100, 2);
        }
        return 0;
    }

    /**
     * 批准建议
     */
    public function approve(int $userId, string $remark = ''): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->applied_by = $userId;
        $this->remark = $remark;
        $this->save();
    }

    /**
     * 拒绝建议
     */
    public function reject(int $userId, string $reason = ''): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->applied_by = $userId;
        $this->remark = $reason;
        $this->save();
    }

    /**
     * 应用建议
     */
    public function apply(int $userId): void
    {
        $this->status = self::STATUS_APPLIED;
        $this->applied_by = $userId;
        $this->applied_time = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 获取今日待审批建议
     */
    public static function getTodayPending(int $hotelId)
    {
        $today = date('Y-m-d');
        return self::where('hotel_id', $hotelId)
            ->where('status', self::STATUS_PENDING)
            ->where('suggestion_date', $today)
            ->select();
    }

    /**
     * 获取历史建议统计
     */
    public static function getStatistics(int $hotelId, string $startDate, string $endDate)
    {
        return self::where('hotel_id', $hotelId)
            ->whereBetween('suggestion_date', [$startDate, $endDate])
            ->field('status, COUNT(*) as count')
            ->group('status')
            ->select();
    }
}
