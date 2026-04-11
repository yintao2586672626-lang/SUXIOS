<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 设备维护记录模型
 */
class DeviceMaintenance extends Model
{
    protected $name = 'device_maintenance';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'device_id' => 'integer',
        'maintenance_type' => 'integer',
        'status' => 'integer',
        'cost' => 'float',
        'operator_id' => 'integer',
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'parts_replaced' => 'json',
    ];
    
    protected $json = ['parts_replaced'];
    protected $jsonAssoc = true;

    // 维护类型常量
    const TYPE_PREVENTIVE = 1;     // 预防性维护
    const TYPE_CORRECTIVE = 2;     // 纠正性维护
    const TYPE_PREDICTIVE = 3;     // 预测性维护
    const TYPE_EMERGENCY = 4;      // 紧急维修

    // 状态常量
    const STATUS_SCHEDULED = 1;      // 已计划
    const STATUS_IN_PROGRESS = 2;    // 进行中
    const STATUS_COMPLETED = 3;      // 已完成
    const STATUS_CANCELLED = 4;      // 已取消

    // 优先级常量
    const PRIORITY_LOW = 1;
    const PRIORITY_NORMAL = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

    /**
     * 关联设备
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'id');
    }

    /**
     * 关联操作人
     */
    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id', 'id');
    }

    /**
     * 搜索器 - 设备
     */
    public function searchDeviceIdAttr($query, $value)
    {
        $query->where('device_id', $value);
    }

    /**
     * 搜索器 - 维护类型
     */
    public function searchMaintenanceTypeAttr($query, $value)
    {
        $query->where('maintenance_type', $value);
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
            $query->whereBetween('scheduled_date', [$value['start'], $value['end']]);
        }
    }

    /**
     * 获取维护类型名称
     */
    public function getMaintenanceTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_PREVENTIVE => '预防性维护',
            self::TYPE_CORRECTIVE => '纠正性维护',
            self::TYPE_PREDICTIVE => '预测性维护',
            self::TYPE_EMERGENCY => '紧急维修',
        ];
        return $names[$data['maintenance_type']] ?? '未知';
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_SCHEDULED => '已计划',
            self::STATUS_IN_PROGRESS => '进行中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_CANCELLED => '已取消',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 标记为维护中
     */
    public function startMaintenance(int $operatorId): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->operator_id = $operatorId;
        $this->actual_start = date('Y-m-d H:i:s');
        $this->save();

        // 更新设备状态
        $this->device->status = Device::STATUS_MAINTENANCE;
        $this->device->save();
    }

    /**
     * 标记为完成
     */
    public function complete(int $operatorId, float $cost = 0, array $parts = []): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->operator_id = $operatorId;
        $this->completed_date = date('Y-m-d');
        $this->actual_end = date('Y-m-d H:i:s');
        $this->cost = $cost;
        $this->parts_replaced = $parts;
        $this->save();

        // 更新设备状态
        $device = $this->device;
        $device->status = Device::STATUS_NORMAL;
        $device->updateMaintenanceDate();
    }

    /**
     * 获取今日维护任务
     */
    public static function getTodayTasks(int $hotelId)
    {
        $today = date('Y-m-d');
        return self::alias('m')
            ->join('devices d', 'm.device_id = d.id')
            ->where('d.hotel_id', $hotelId)
            ->where('m.scheduled_date', $today)
            ->whereIn('m.status', [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS])
            ->field('m.*, d.name as device_name, d.location')
            ->select();
    }

    /**
     * 获取维护统计
     */
    public static function getStatistics(int $hotelId, string $startDate, string $endDate)
    {
        $stats = self::alias('m')
            ->join('devices d', 'm.device_id = d.id')
            ->where('d.hotel_id', $hotelId)
            ->whereBetween('m.scheduled_date', [$startDate, $endDate])
            ->field('m.maintenance_type, COUNT(*) as count, SUM(m.cost) as total_cost')
            ->group('m.maintenance_type')
            ->select();
        
        $result = [
            'total_count' => 0,
            'total_cost' => 0,
            'by_type' => [],
        ];
        
        foreach ($stats as $stat) {
            $result['total_count'] += $stat['count'];
            $result['total_cost'] += $stat['total_cost'];
            $result['by_type'][$stat['maintenance_type']] = [
                'count' => $stat['count'],
                'cost' => $stat['total_cost'],
            ];
        }
        
        return $result;
    }
}
