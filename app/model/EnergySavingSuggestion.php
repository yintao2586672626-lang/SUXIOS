<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 节能建议模型
 * 用于资产运维Agent的节能建议生成和管理
 */
class EnergySavingSuggestion extends Model
{
    protected $name = 'energy_saving_suggestions';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'energy_type' => 'integer',
        'suggestion_type' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'potential_saving' => 'float',
        'cost_estimate' => 'float',
        'payback_period' => 'integer',
        'implemented_by' => 'integer',
        'related_devices' => 'json',
        'calculation_basis' => 'json',
    ];
    
    protected $json = ['related_devices', 'calculation_basis'];
    protected $jsonAssoc = true;

    // 能耗类型常量
    const TYPE_ELECTRICITY = 1;       // 电
    const TYPE_WATER = 2;             // 水
    const TYPE_GAS = 3;               // 燃气
    const TYPE_ALL = 9;               // 综合

    // 建议类型常量
    const SUGGESTION_EQUIPMENT = 1;   // 设备优化
    const SUGGESTION_OPERATION = 2;   // 运营调整
    const SUGGESTION_BEHAVIOR = 3;    // 行为改变
    const SUGGESTION_UPGRADE = 4;     // 设备升级
    const SUGGESTION_RENEWABLE = 5;   // 可再生能源

    // 优先级常量
    const PRIORITY_LOW = 1;           // 低
    const PRIORITY_MEDIUM = 2;        // 中
    const PRIORITY_HIGH = 3;          // 高
    const PRIORITY_CRITICAL = 4;      // 紧急

    // 状态常量
    const STATUS_PENDING = 1;         // 待评估
    const STATUS_APPROVED = 2;        // 已批准
    const STATUS_IMPLEMENTING = 3;    // 实施中
    const STATUS_COMPLETED = 4;       // 已完成
    const STATUS_REJECTED = 5;        // 已拒绝

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联实施人
     */
    public function implementer()
    {
        return $this->belongsTo(User::class, 'implemented_by', 'id');
    }

    /**
     * 获取能耗类型名称
     */
    public function getEnergyTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_ELECTRICITY => '电',
            self::TYPE_WATER => '水',
            self::TYPE_GAS => '燃气',
            self::TYPE_ALL => '综合',
        ];
        return $names[$data['energy_type']] ?? '未知';
    }

    /**
     * 获取建议类型名称
     */
    public function getSuggestionTypeNameAttr($value, $data)
    {
        $names = [
            self::SUGGESTION_EQUIPMENT => '设备优化',
            self::SUGGESTION_OPERATION => '运营调整',
            self::SUGGESTION_BEHAVIOR => '行为改变',
            self::SUGGESTION_UPGRADE => '设备升级',
            self::SUGGESTION_RENEWABLE => '可再生能源',
        ];
        return $names[$data['suggestion_type']] ?? '未知';
    }

    /**
     * 获取优先级名称
     */
    public function getPriorityNameAttr($value, $data)
    {
        $names = [
            self::PRIORITY_LOW => '低',
            self::PRIORITY_MEDIUM => '中',
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
            self::STATUS_PENDING => '待评估',
            self::STATUS_APPROVED => '已批准',
            self::STATUS_IMPLEMENTING => '实施中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_REJECTED => '已拒绝',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 获取预计年化节省金额
     */
    public function getAnnualSavingAttr($value, $data)
    {
        return $data['potential_saving'] * 365; // 按天计算
    }

    /**
     * 创建节能建议
     */
    public static function createSuggestion(int $hotelId, array $data): self
    {
        $suggestion = new self();
        $suggestion->hotel_id = $hotelId;
        $suggestion->energy_type = $data['energy_type'] ?? self::TYPE_ELECTRICITY;
        $suggestion->suggestion_type = $data['suggestion_type'] ?? self::SUGGESTION_OPERATION;
        $suggestion->priority = $data['priority'] ?? self::PRIORITY_MEDIUM;
        $suggestion->status = self::STATUS_PENDING;
        $suggestion->title = $data['title'] ?? '';
        $suggestion->description = $data['description'] ?? '';
        $suggestion->implementation_steps = $data['implementation_steps'] ?? '';
        $suggestion->potential_saving = $data['potential_saving'] ?? 0; // 每天节省量
        $suggestion->cost_estimate = $data['cost_estimate'] ?? 0;
        $suggestion->payback_period = $data['payback_period'] ?? 0; // 回本周期（天）
        $suggestion->related_devices = $data['related_devices'] ?? [];
        $suggestion->calculation_basis = $data['calculation_basis'] ?? [];
        $suggestion->save();
        
        return $suggestion;
    }

    /**
     * 批准建议
     */
    public function approve(): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->save();
    }

    /**
     * 开始实施
     */
    public function startImplementation(int $userId): void
    {
        $this->status = self::STATUS_IMPLEMENTING;
        $this->implemented_by = $userId;
        $this->implementation_start = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 完成实施
     */
    public function complete(float $actualSaving = 0): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->actual_saving = $actualSaving;
        $this->implementation_end = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 自动生成节能建议（基于异常数据）
     */
    public static function autoGenerate(int $hotelId): array
    {
        $suggestions = [];
        
        // 1. 检查高能耗设备
        $highConsumptionDevices = Device::where('hotel_id', $hotelId)
            ->where('status', Device::STATUS_NORMAL)
            ->where('is_monitored', 1)
            ->select();
        
        foreach ($highConsumptionDevices as $device) {
            // 检查是否超过基准线
            $benchmark = EnergyBenchmark::where('hotel_id', $hotelId)
                ->where('device_id', $device->id)
                ->where('is_active', 1)
                ->find();
            
            if ($benchmark) {
                $recent = EnergyConsumption::where('device_id', $device->id)
                    ->order('record_date', 'desc')
                    ->limit(3)
                    ->column('consumption_value');
                
                $avgRecent = count($recent) > 0 ? array_sum($recent) / count($recent) : 0;
                
                if ($benchmark->isAnomaly($avgRecent)) {
                    $suggestion = self::createSuggestion($hotelId, [
                        'energy_type' => $benchmark->energy_type,
                        'suggestion_type' => self::SUGGESTION_EQUIPMENT,
                        'priority' => self::PRIORITY_HIGH,
                        'title' => "设备 {$device->name} 能耗异常优化",
                        'description' => "设备 {$device->name} 最近3天平均能耗 {$avgRecent} 超过基准线 {$benchmark->benchmark_value}，建议检查设备运行状态或进行维护。",
                        'potential_saving' => round(($avgRecent - $benchmark->benchmark_value) * 0.3, 2),
                        'cost_estimate' => 0,
                        'payback_period' => 0,
                        'related_devices' => [$device->id],
                        'calculation_basis' => [
                            'recent_avg' => $avgRecent,
                            'benchmark' => $benchmark->benchmark_value,
                            'excess_percent' => $benchmark->calculateAnomalyScore($avgRecent),
                        ],
                    ]);
                    
                    $suggestions[] = $suggestion;
                }
            }
        }
        
        // 2. 检查空房能耗（可关闭未入住房间的部分设备）
        // 这里简化处理，实际应该结合PMS的入住数据
        
        // 3. 夜间模式建议
        $suggestions[] = self::createSuggestion($hotelId, [
            'energy_type' => self::TYPE_ELECTRICITY,
            'suggestion_type' => self::SUGGESTION_OPERATION,
            'priority' => self::PRIORITY_MEDIUM,
            'title' => '优化公共区域夜间照明策略',
            'description' => '建议将公共区域（大堂、走廊）照明在23:00-06:00期间自动调至节能模式，可降低约30%的夜间照明能耗。',
            'potential_saving' => 50, // kWh/天
            'cost_estimate' => 2000,
            'payback_period' => 40,
            'implementation_steps' => "1. 评估当前照明布局\n2. 安装智能调光系统\n3. 设置定时任务\n4. 测试并调整参数",
        ]);
        
        return $suggestions;
    }

    /**
     * 获取实施统计
     */
    public static function getImplementationStats(int $hotelId)
    {
        return self::where('hotel_id', $hotelId)
            ->field([
                'COUNT(*) as total_suggestions',
                'SUM(CASE WHEN status = ' . self::STATUS_COMPLETED . ' THEN 1 ELSE 0 END) as completed',
                'SUM(CASE WHEN status = ' . self::STATUS_IMPLEMENTING . ' THEN 1 ELSE 0 END) as implementing',
                'SUM(CASE WHEN status = ' . self::STATUS_PENDING . ' THEN 1 ELSE 0 END) as pending',
                'SUM(actual_saving) as total_saving',
                'SUM(potential_saving * 365) as potential_annual_saving',
            ])
            ->find();
    }

    /**
     * 获取高优先级建议
     */
    public static function getHighPriority(int $hotelId, int $limit = 5)
    {
        return self::where('hotel_id', $hotelId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED])
            ->where('priority', '>=', self::PRIORITY_HIGH)
            ->order('priority', 'desc')
            ->limit($limit)
            ->select();
    }
}
