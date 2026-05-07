<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 设备模型
 * 用于资产运维Agent的设备管理
 */
class Device extends Model
{
    protected $name = 'devices';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'category_id' => 'integer',
        'area_id' => 'integer',
        'status' => 'integer',
        'install_date' => 'date',
        'warranty_expire' => 'date',
        'last_maintenance' => 'date',
        'next_maintenance' => 'date',
        'maintenance_cycle' => 'integer',
        'purchase_cost' => 'float',
        'energy_consumption' => 'float',
        'is_monitored' => 'integer',
        'metadata' => 'json',
    ];
    
    protected $json = ['metadata'];
    protected $jsonAssoc = true;

    // 设备状态常量
    const STATUS_NORMAL = 1;           // 正常
    const STATUS_MAINTENANCE = 2;      // 维护中
    const STATUS_FAULT = 3;            // 故障
    const STATUS_RETIRED = 4;          // 报废

    // 维护周期单位（天）
    const CYCLE_WEEKLY = 7;
    const CYCLE_MONTHLY = 30;
    const CYCLE_QUARTERLY = 90;
    const CYCLE_YEARLY = 365;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联分类
     */
    public function category()
    {
        return $this->belongsTo(DeviceCategory::class, 'category_id', 'id');
    }

    /**
     * 关联维护记录
     */
    public function maintenanceRecords()
    {
        return $this->hasMany(DeviceMaintenance::class, 'device_id', 'id');
    }

    /**
     * 关联能耗记录
     */
    public function energyRecords()
    {
        return $this->hasMany(EnergyConsumption::class, 'device_id', 'id');
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
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
    }

    /**
     * 搜索器 - 分类
     */
    public function searchCategoryIdAttr($query, $value)
    {
        $query->where('category_id', $value);
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_NORMAL => '正常',
            self::STATUS_MAINTENANCE => '维护中',
            self::STATUS_FAULT => '故障',
            self::STATUS_RETIRED => '报废',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 获取状态样式
     */
    public function getStatusClassAttr($value, $data)
    {
        $classes = [
            self::STATUS_NORMAL => 'success',
            self::STATUS_MAINTENANCE => 'warning',
            self::STATUS_FAULT => 'danger',
            self::STATUS_RETIRED => 'secondary',
        ];
        return $classes[$data['status']] ?? 'secondary';
    }

    /**
     * 是否需要维护
     */
    public function getNeedsMaintenanceAttr($value, $data)
    {
        if (empty($data['next_maintenance'])) {
            return false;
        }
        return strtotime($data['next_maintenance']) <= strtotime('+7 days');
    }

    /**
     * 获取设备年龄（年）
     */
    public function getAgeYearsAttr($value, $data)
    {
        if (empty($data['install_date'])) {
            return 0;
        }
        $install = new \DateTime($data['install_date']);
        $now = new \DateTime();
        return $install->diff($now)->y;
    }

    /**
     * 更新维护日期
     */
    public function updateMaintenanceDate(): void
    {
        $this->last_maintenance = date('Y-m-d');
        if ($this->maintenance_cycle > 0) {
            $this->next_maintenance = date('Y-m-d', strtotime("+{$this->maintenance_cycle} days"));
        }
        $this->save();
    }

    /**
     * 获取待维护设备列表
     */
    public static function getPendingMaintenance(int $hotelId, int $days = 7)
    {
        $threshold = date('Y-m-d', strtotime("+{$days} days"));
        return self::where('hotel_id', $hotelId)
            ->where('status', self::STATUS_NORMAL)
            ->where('next_maintenance', '<=', $threshold)
            ->order('next_maintenance', 'asc')
            ->select();
    }

    /**
     * 获取故障设备列表
     */
    public static function getFaultyDevices(int $hotelId)
    {
        return self::where('hotel_id', $hotelId)
            ->where('status', self::STATUS_FAULT)
            ->select();
    }

    /**
     * 获取设备统计
     */
    public static function getStatistics(int $hotelId)
    {
        $stats = self::where('hotel_id', $hotelId)
            ->field('status, COUNT(*) as count')
            ->group('status')
            ->column('count', 'status');
        
        return [
            'total' => array_sum($stats),
            'normal' => $stats[self::STATUS_NORMAL] ?? 0,
            'maintenance' => $stats[self::STATUS_MAINTENANCE] ?? 0,
            'fault' => $stats[self::STATUS_FAULT] ?? 0,
            'retired' => $stats[self::STATUS_RETIRED] ?? 0,
        ];
    }
}
