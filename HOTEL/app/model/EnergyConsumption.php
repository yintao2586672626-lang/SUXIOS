<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 能耗数据模型
 * 用于资产运维Agent的能耗监控
 */
class EnergyConsumption extends Model
{
    protected $name = 'energy_consumption';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = false;
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'device_id' => 'integer',
        'energy_type' => 'integer',
        'area_id' => 'integer',
        'consumption_value' => 'float',
        'cost_amount' => 'float',
        'peak_value' => 'float',
        'valley_value' => 'float',
        'is_anomaly' => 'integer',
        'anomaly_score' => 'float',
        'metadata' => 'json',
    ];
    
    protected $json = ['metadata'];
    protected $jsonAssoc = true;

    // 能源类型常量
    const TYPE_ELECTRICITY = 1;    // 电力
    const TYPE_WATER = 2;          // 水
    const TYPE_GAS = 3;            // 燃气
    const TYPE_STEAM = 4;          // 蒸汽
    const TYPE_HOT_WATER = 5;      // 热水

    // 周期类型常量
    const PERIOD_HOURLY = 1;
    const PERIOD_DAILY = 2;
    const PERIOD_MONTHLY = 3;

    // 异常状态常量
    const ANOMALY_NORMAL = 0;
    const ANOMALY_DETECTED = 1;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联设备
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - 设备
     */
    public function searchDeviceIdAttr($query, $value)
    {
        $query->where('device_id', $value);
    }

    /**
     * 搜索器 - 能源类型
     */
    public function searchEnergyTypeAttr($query, $value)
    {
        $query->where('energy_type', $value);
    }

    /**
     * 搜索器 - 异常状态
     */
    public function searchIsAnomalyAttr($query, $value)
    {
        $query->where('is_anomaly', $value);
    }

    /**
     * 搜索器 - 日期范围
     */
    public function searchDateRangeAttr($query, $value)
    {
        if (isset($value['start']) && isset($value['end'])) {
            $query->whereBetween('record_date', [$value['start'], $value['end']]);
        }
    }

    /**
     * 获取能源类型名称
     */
    public function getEnergyTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_ELECTRICITY => '电力',
            self::TYPE_WATER => '水',
            self::TYPE_GAS => '燃气',
            self::TYPE_STEAM => '蒸汽',
            self::TYPE_HOT_WATER => '热水',
        ];
        return $names[$data['energy_type']] ?? '未知';
    }

    /**
     * 获取单位
     */
    public function getUnitAttr($value, $data)
    {
        $units = [
            self::TYPE_ELECTRICITY => 'kWh',
            self::TYPE_WATER => 'm³',
            self::TYPE_GAS => 'm³',
            self::TYPE_STEAM => 'ton',
            self::TYPE_HOT_WATER => 'm³',
        ];
        return $units[$data['energy_type']] ?? '';
    }

    /**
     * 获取今日总能耗
     */
    public static function getTodayTotal(int $hotelId, int $energyType = 0)
    {
        $today = date('Y-m-d');
        $query = self::where('hotel_id', $hotelId)
            ->where('record_date', $today);
        
        if ($energyType > 0) {
            $query->where('energy_type', $energyType);
        }
        
        return $query->sum('consumption_value');
    }

    /**
     * 获取能耗趋势
     */
    public static function getTrend(int $hotelId, int $energyType, string $startDate, string $endDate)
    {
        return self::where('hotel_id', $hotelId)
            ->where('energy_type', $energyType)
            ->whereBetween('record_date', [$startDate, $endDate])
            ->field('record_date, SUM(consumption_value) as total_consumption, SUM(cost_amount) as total_cost')
            ->group('record_date')
            ->order('record_date', 'asc')
            ->select();
    }

    /**
     * 获取异常记录
     */
    public static function getAnomalies(int $hotelId, string $startDate, string $endDate, int $limit = 50)
    {
        return self::where('hotel_id', $hotelId)
            ->where('is_anomaly', self::ANOMALY_DETECTED)
            ->whereBetween('record_date', [$startDate, $endDate])
            ->order('anomaly_score', 'desc')
            ->limit($limit)
            ->select();
    }

    /**
     * 标记为异常
     */
    public function markAsAnomaly(float $score): void
    {
        $this->is_anomaly = self::ANOMALY_DETECTED;
        $this->anomaly_score = $score;
        $this->save();
    }
}
