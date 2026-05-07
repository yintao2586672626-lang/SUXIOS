<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 维护计划模型
 * 用于资产运维Agent的预防性维护计划
 */
class MaintenancePlan extends Model
{
    protected $name = 'maintenance_plans';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'device_id' => 'integer',
        'category_id' => 'integer',
        'plan_type' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'frequency_days' => 'integer',
        'estimated_duration' => 'integer',
        'estimated_cost' => 'float',
        'created_by' => 'integer',
        'items' => 'json',
        'materials' => 'json',
    ];
    
    protected $json = ['items', 'materials'];
    protected $jsonAssoc = true;

    // 计划类型常量
    const TYPE_DAILY = 1;             // 日常保养
    const TYPE_WEEKLY = 2;            // 周保养
    const TYPE_MONTHLY = 3;           // 月保养
    const TYPE_QUARTERLY = 4;         // 季度保养
    const TYPE_YEARLY = 5;            // 年度保养
    const TYPE_CUSTOM = 9;            // 自定义

    // 优先级常量
    const PRIORITY_LOW = 1;           // 低
    const PRIORITY_NORMAL = 2;        // 中
    const PRIORITY_HIGH = 3;          // 高
    const PRIORITY_CRITICAL = 4;      // 紧急

    // 状态常量
    const STATUS_ACTIVE = 1;          // 启用
    const STATUS_PAUSED = 2;          // 暂停
    const STATUS_COMPLETED = 3;       // 完成
    const STATUS_CANCELLED = 4;       // 取消

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
     * 关联分类
     */
    public function category()
    {
        return $this->belongsTo(DeviceCategory::class, 'category_id', 'id');
    }

    /**
     * 获取计划类型名称
     */
    public function getPlanTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_DAILY => '日常保养',
            self::TYPE_WEEKLY => '周保养',
            self::TYPE_MONTHLY => '月保养',
            self::TYPE_QUARTERLY => '季度保养',
            self::TYPE_YEARLY => '年度保养',
            self::TYPE_CUSTOM => '自定义',
        ];
        return $names[$data['plan_type']] ?? '未知';
    }

    /**
     * 获取优先级名称
     */
    public function getPriorityNameAttr($value, $data)
    {
        $names = [
            self::PRIORITY_LOW => '低',
            self::PRIORITY_NORMAL => '中',
            self::PRIORITY_HIGH => '高',
            self::PRIORITY_CRITICAL => '紧急',
        ];
        return $names[$data['priority']] ?? '未知';
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_ACTIVE => '启用',
            self::STATUS_PAUSED => '暂停',
            self::STATUS_COMPLETED => '完成',
            self::STATUS_CANCELLED => '取消',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 获取下次维护日期
     */
    public function getNextMaintenanceDateAttr($value, $data)
    {
        if ($data['last_maintenance_date']) {
            return date('Y-m-d', strtotime($data['last_maintenance_date'] . ' + ' . $data['frequency_days'] . ' days'));
        }
        return date('Y-m-d', strtotime('+ ' . $data['frequency_days'] . ' days'));
    }

    /**
     * 获取剩余天数
     */
    public function getDaysRemainingAttr($value, $data)
    {
        $nextDate = $this->getNextMaintenanceDateAttr($value, $data);
        $today = date('Y-m-d');
        return (strtotime($nextDate) - strtotime($today)) / 86400;
    }

    /**
     * 是否需要维护提醒
     */
    public function getNeedsReminderAttr($value, $data)
    {
        $daysRemaining = $this->getDaysRemainingAttr($value, $data);
        return $daysRemaining <= $data['reminder_days'] && $daysRemaining >= 0;
    }

    /**
     * 创建设备维护计划
     */
    public static function createForDevice(int $hotelId, int $deviceId, array $data): self
    {
        $device = Device::find($deviceId);
        if (!$device) {
            throw new \Exception('设备不存在');
        }
        
        $plan = new self();
        $plan->hotel_id = $hotelId;
        $plan->device_id = $deviceId;
        $plan->category_id = $device->category_id;
        $plan->plan_name = $data['plan_name'] ?? $device->name . '维护计划';
        $plan->plan_type = $data['plan_type'] ?? self::TYPE_MONTHLY;
        $plan->priority = $data['priority'] ?? self::PRIORITY_NORMAL;
        $plan->status = self::STATUS_ACTIVE;
        $plan->frequency_days = $data['frequency_days'] ?? 30;
        $plan->estimated_duration = $data['estimated_duration'] ?? 60; // 分钟
        $plan->estimated_cost = $data['estimated_cost'] ?? 0;
        $plan->items = $data['items'] ?? [];
        $plan->materials = $data['materials'] ?? [];
        $plan->description = $data['description'] ?? '';
        $plan->created_by = $data['created_by'] ?? 0;
        $plan->save();
        
        // 更新设备的下次维护日期
        $device->next_maintenance_date = $plan->next_maintenance_date;
        $device->save();
        
        return $plan;
    }

    /**
     * 执行维护计划
     */
    public function execute(string $actualDate, int $executorId, string $result = '', float $actualCost = 0): DeviceMaintenance
    {
        // 创建设备维护记录
        $maintenance = DeviceMaintenance::createRecord([
            'hotel_id' => $this->hotel_id,
            'device_id' => $this->device_id,
            'maintenance_type' => DeviceMaintenance::TYPE_PLANNED,
            'maintenance_date' => $actualDate,
            'description' => $this->description,
            'result' => $result,
            'cost' => $actualCost,
            'executor_id' => $executorId,
        ]);
        
        // 更新计划状态
        $this->last_maintenance_date = $actualDate;
        $this->execution_count = ($this->execution_count ?? 0) + 1;
        $this->total_cost = ($this->total_cost ?? 0) + $actualCost;
        $this->save();
        
        // 更新设备维护日期
        $device = Device::find($this->device_id);
        if ($device) {
            $device->last_maintenance_date = $actualDate;
            $device->next_maintenance_date = $this->next_maintenance_date;
            $device->save();
        }
        
        return $maintenance;
    }

    /**
     * 获取待执行计划（未来7天）
     */
    public static function getUpcomingPlans(int $hotelId, int $days = 7)
    {
        $endDate = date('Y-m-d', strtotime("+{$days} days"));
        
        $plans = self::where('hotel_id', $hotelId)
            ->where('status', self::STATUS_ACTIVE)
            ->select();
        
        $upcoming = [];
        foreach ($plans as $plan) {
            $nextDate = $plan->next_maintenance_date;
            if ($nextDate <= $endDate) {
                $upcoming[] = $plan;
            }
        }
        
        return $upcoming;
    }

    /**
     * 获取逾期计划
     */
    public static function getOverduePlans(int $hotelId)
    {
        $today = date('Y-m-d');
        
        $plans = self::where('hotel_id', $hotelId)
            ->where('status', self::STATUS_ACTIVE)
            ->select();
        
        $overdue = [];
        foreach ($plans as $plan) {
            $nextDate = $plan->next_maintenance_date;
            if ($nextDate < $today) {
                $overdue[] = $plan;
            }
        }
        
        return $overdue;
    }

    /**
     * 自动生成默认维护计划
     */
    public static function autoGenerateDefaultPlans(int $hotelId): array
    {
        $created = [];
        
        // 获取所有设备
        $devices = Device::where('hotel_id', $hotelId)
            ->where('status', Device::STATUS_NORMAL)
            ->select();
        
        foreach ($devices as $device) {
            $category = DeviceCategory::find($device->category_id);
            if (!$category) continue;
            
            // 根据设备分类创建不同的维护计划
            switch ($category->code) {
                case 'hvac': // 空调系统
                    $created[] = self::createForDevice($hotelId, $device->id, [
                        'plan_name' => $device->name . ' - 月度滤网清洁',
                        'plan_type' => self::TYPE_MONTHLY,
                        'frequency_days' => 30,
                        'estimated_duration' => 30,
                        'items' => [
                            '清洁空调滤网',
                            '检查制冷剂压力',
                            '检查风机运行状态',
                            '记录运行参数',
                        ],
                    ]);
                    break;
                    
                case 'elevator': // 电梯
                    $created[] = self::createForDevice($hotelId, $device->id, [
                        'plan_name' => $device->name . ' - 半月保养',
                        'plan_type' => self::TYPE_CUSTOM,
                        'frequency_days' => 15,
                        'priority' => self::PRIORITY_HIGH,
                        'estimated_duration' => 120,
                        'items' => [
                            '检查电梯运行平稳性',
                            '检查安全装置',
                            '润滑导轨',
                            '清洁轿厢和机房',
                        ],
                    ]);
                    break;
                    
                case 'water_heater': // 热水器
                    $created[] = self::createForDevice($hotelId, $device->id, [
                        'plan_name' => $device->name . ' - 季度除垢',
                        'plan_type' => self::TYPE_QUARTERLY,
                        'frequency_days' => 90,
                        'estimated_duration' => 180,
                        'items' => [
                            '清除水垢',
                            '检查加热元件',
                            '检查温控器',
                            '测试安全阀',
                        ],
                    ]);
                    break;
                    
                default:
                    // 默认月度检查
                    $created[] = self::createForDevice($hotelId, $device->id, [
                        'plan_name' => $device->name . ' - 定期检查',
                        'plan_type' => self::TYPE_MONTHLY,
                        'frequency_days' => 30,
                        'estimated_duration' => 30,
                        'items' => [
                            '检查设备运行状态',
                            '清洁设备表面',
                            '记录运行参数',
                            '检查安全装置',
                        ],
                    ]);
            }
        }
        
        return $created;
    }

    /**
     * 获取计划执行统计
     */
    public static function getExecutionStats(int $hotelId)
    {
        $stats = self::where('hotel_id', $hotelId)
            ->field([
                'COUNT(*) as total_plans',
                'SUM(CASE WHEN status = ' . self::STATUS_ACTIVE . ' THEN 1 ELSE 0 END) as active',
                'SUM(CASE WHEN status = ' . self::STATUS_PAUSED . ' THEN 1 ELSE 0 END) as paused',
                'SUM(execution_count) as total_executions',
                'SUM(total_cost) as total_cost',
            ])
            ->find();
        
        // 获取待执行数量
        $upcoming = count(self::getUpcomingPlans($hotelId, 7));
        $overdue = count(self::getOverduePlans($hotelId));
        
        return array_merge($stats->toArray(), [
            'upcoming_7days' => $upcoming,
            'overdue' => $overdue,
        ]);
    }
}
