<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 能耗基准模型
 * 用于资产运维Agent的能耗基准线和异常检测
 */
class EnergyBenchmark extends Model
{
    protected $name = 'energy_benchmarks';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'energy_type' => 'integer',
        'area_id' => 'integer',
        'device_id' => 'integer',
        'benchmark_value' => 'float',
        'alert_threshold_high' => 'float',
        'alert_threshold_low' => 'float',
        'season_factor' => 'json',
        'is_active' => 'integer',
    ];
    
    protected $json = ['season_factor'];
    protected $jsonAssoc = true;

    // 能耗类型常量（与EnergyConsumption保持一致）
    const TYPE_ELECTRICITY = 1;       // 电
    const TYPE_WATER = 2;             // 水
    const TYPE_GAS = 3;               // 燃气
    const TYPE_STEAM = 4;             // 蒸汽

    // 基准类型常量
    const TYPE_DAILY = 1;             // 日基准
    const TYPE_MONTHLY = 2;           // 月基准
    const TYPE_HOURLY = 3;            // 小时基准

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
     * 获取能耗类型名称
     */
    public function getEnergyTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_ELECTRICITY => '电',
            self::TYPE_WATER => '水',
            self::TYPE_GAS => '燃气',
            self::TYPE_STEAM => '蒸汽',
        ];
        return $names[$data['energy_type']] ?? '未知';
    }

    /**
     * 获取能耗单位
     */
    public function getUnitAttr($value, $data)
    {
        $units = [
            self::TYPE_ELECTRICITY => 'kWh',
            self::TYPE_WATER => 'm³',
            self::TYPE_GAS => 'm³',
            self::TYPE_STEAM => 't',
        ];
        return $units[$data['energy_type']] ?? '';
    }

    /**
     * 获取基准类型名称
     */
    public function getBenchmarkTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_DAILY => '日基准',
            self::TYPE_MONTHLY => '月基准',
            self::TYPE_HOURLY => '小时基准',
        ];
        return $names[$data['benchmark_type']] ?? '未知';
    }

    /**
     * 计算异常得分
     */
    public function calculateAnomalyScore(float $actualValue): float
    {
        $benchmark = $this->benchmark_value;
        if ($benchmark <= 0) return 0;
        
        $deviation = abs($actualValue - $benchmark) / $benchmark;
        return min($deviation * 100, 100); // 最高100分
    }

    /**
     * 判断是否异常
     */
    public function isAnomaly(float $actualValue): bool
    {
        $high = $this->benchmark_value * (1 + $this->alert_threshold_high / 100);
        $low = $this->benchmark_value * (1 - $this->alert_threshold_low / 100);
        
        return $actualValue > $high || $actualValue < $low;
    }

    /**
     * 获取异常等级
     */
    public function getAnomalyLevel(float $actualValue): int
    {
        if (!$this->isAnomaly($actualValue)) return 0;
        
        $score = $this->calculateAnomalyScore($actualValue);
        if ($score >= 50) return 3; // 严重
        if ($score >= 30) return 2; // 中度
        return 1; // 轻微
    }

    /**
     * 创建或更新基准
     */
    public static function setBenchmark(int $hotelId, array $data): self
    {
        $benchmark = self::where('hotel_id', $hotelId)
            ->where('energy_type', $data['energy_type'])
            ->where('area_id', $data['area_id'] ?? 0)
            ->where('device_id', $data['device_id'] ?? 0)
            ->find();
        
        if (!$benchmark) {
            $benchmark = new self();
            $benchmark->hotel_id = $hotelId;
            $benchmark->energy_type = $data['energy_type'];
            $benchmark->area_id = $data['area_id'] ?? 0;
            $benchmark->device_id = $data['device_id'] ?? 0;
        }
        
        $benchmark->benchmark_type = $data['benchmark_type'] ?? self::TYPE_DAILY;
        $benchmark->benchmark_value = $data['benchmark_value'] ?? 0;
        $benchmark->alert_threshold_high = $data['alert_threshold_high'] ?? 20;
        $benchmark->alert_threshold_low = $data['alert_threshold_low'] ?? 10;
        $benchmark->season_factor = $data['season_factor'] ?? [
            'spring' => 1.0,
            'summer' => 1.2,
            'autumn' => 1.0,
            'winter' => 1.3,
        ];
        $benchmark->is_active = $data['is_active'] ?? 1;
        $benchmark->save();
        
        return $benchmark;
    }

    /**
     * 获取当前季节因子
     */
    public function getCurrentSeasonFactor(): float
    {
        $month = (int) date('n');
        $seasons = $this->season_factor ?? [];
        
        if ($month >= 3 && $month <= 5) return $seasons['spring'] ?? 1.0;
        if ($month >= 6 && $month <= 8) return $seasons['summer'] ?? 1.2;
        if ($month >= 9 && $month <= 11) return $seasons['autumn'] ?? 1.0;
        return $seasons['winter'] ?? 1.3;
    }

    /**
     * 获取调整后基准值（考虑季节因素）
     */
    public function getAdjustedBenchmark(): float
    {
        return $this->benchmark_value * $this->getCurrentSeasonFactor();
    }

    /**
     * 自动计算基准值（基于历史数据）
     */
    public static function autoCalculateBenchmark(int $hotelId, int $energyType, int $days = 30): float
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        $endDate = date('Y-m-d', strtotime('-1 day'));
        
        $result = EnergyConsumption::where('hotel_id', $hotelId)
            ->where('energy_type', $energyType)
            ->whereBetween('record_date', [$startDate, $endDate])
            ->field('AVG(consumption_value) as avg_value')
            ->find();
        
        return $result ? round($result['avg_value'], 2) : 0;
    }

    /**
     * 获取能耗对比报表
     */
    public static function getComparisonReport(int $hotelId, string $date)
    {
        $benchmarks = self::where('hotel_id', $hotelId)
            ->where('is_active', 1)
            ->select();
        
        $report = [];
        foreach ($benchmarks as $benchmark) {
            $actual = EnergyConsumption::where('hotel_id', $hotelId)
                ->where('energy_type', $benchmark->energy_type)
                ->where('record_date', $date)
                ->where('area_id', $benchmark->area_id)
                ->value('consumption_value') ?? 0;
            
            $adjustedBenchmark = $benchmark->getAdjustedBenchmark();
            $variance = $actual - $adjustedBenchmark;
            $variancePercent = $adjustedBenchmark > 0 ? round($variance / $adjustedBenchmark * 100, 2) : 0;
            
            $report[] = [
                'energy_type' => $benchmark->energy_type,
                'energy_type_name' => $benchmark->energy_type_name,
                'benchmark' => $adjustedBenchmark,
                'actual' => $actual,
                'variance' => round($variance, 2),
                'variance_percent' => $variancePercent,
                'is_anomaly' => $benchmark->isAnomaly($actual),
                'anomaly_level' => $benchmark->getAnomalyLevel($actual),
            ];
        }
        
        return $report;
    }
}
