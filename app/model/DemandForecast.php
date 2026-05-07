<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 需求预测模型
 * 用于收益管理Agent的需求预测和RevPAR优化
 */
class DemandForecast extends Model
{
    protected $name = 'demand_forecasts';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'room_type_id' => 'integer',
        'forecast_method' => 'integer',
        'predicted_occupancy' => 'float',
        'predicted_demand' => 'integer',
        'confidence_score' => 'float',
        'is_event_driven' => 'integer',
        'event_factors' => 'json',
        'historical_data' => 'json',
    ];
    
    protected $json = ['event_factors', 'historical_data'];
    protected $jsonAssoc = true;

    // 预测方法常量
    const METHOD_ARIMA = 1;           // ARIMA时间序列
    const METHOD_LLM = 2;             // LLM语义增强
    const METHOD_HYBRID = 3;          // 混合模型
    const METHOD_ML = 4;              // 机器学习

    // 事件类型常量
    const EVENT_NONE = 0;             // 无特殊事件
    const EVENT_HOLIDAY = 1;          // 节假日
    const EVENT_EXHIBITION = 2;       // 展会
    const EVENT_WEEKEND = 3;          // 周末高峰
    const EVENT_WEATHER = 4;          // 天气影响
    const EVENT_COMPETITOR = 5;       // 竞对活动

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
     * 获取预测方法名称
     */
    public function getForecastMethodNameAttr($value, $data)
    {
        $names = [
            self::METHOD_ARIMA => 'ARIMA时间序列',
            self::METHOD_LLM => 'LLM语义增强',
            self::METHOD_HYBRID => '混合模型',
            self::METHOD_ML => '机器学习',
        ];
        return $names[$data['forecast_method']] ?? '未知';
    }

    /**
     * 获取预测RevPAR
     */
    public function getPredictedRevparAttr($value, $data)
    {
        if ($data['predicted_occupancy'] > 0 && $data['room_type_id']) {
            $roomType = RoomType::find($data['room_type_id']);
            if ($roomType) {
                return round($roomType->base_price * $data['predicted_occupancy'] / 100, 2);
            }
        }
        return 0;
    }

    /**
     * 创建预测
     */
    public static function createForecast(int $hotelId, string $forecastDate, array $data): self
    {
        $forecast = new self();
        $forecast->hotel_id = $hotelId;
        $forecast->forecast_date = $forecastDate;
        $forecast->room_type_id = $data['room_type_id'] ?? 0;
        $forecast->forecast_method = $data['forecast_method'] ?? self::METHOD_HYBRID;
        $forecast->predicted_occupancy = $data['predicted_occupancy'] ?? 0;
        $forecast->predicted_demand = $data['predicted_demand'] ?? 0;
        $forecast->confidence_score = $data['confidence_score'] ?? 0.8;
        $forecast->is_event_driven = $data['is_event_driven'] ?? 0;
        $forecast->event_factors = $data['event_factors'] ?? [];
        $forecast->historical_data = $data['historical_data'] ?? [];
        $forecast->remark = $data['remark'] ?? '';
        $forecast->save();
        
        return $forecast;
    }

    /**
     * 获取日期范围的预测
     */
    public static function getForecastRange(int $hotelId, string $startDate, string $endDate)
    {
        return self::where('hotel_id', $hotelId)
            ->whereBetween('forecast_date', [$startDate, $endDate])
            ->with('roomType')
            ->order('forecast_date', 'asc')
            ->select();
    }

    /**
     * 获取高需求日期（用于动态定价）
     */
    public static function getHighDemandDates(int $hotelId, float $threshold = 80.0)
    {
        $today = date('Y-m-d');
        $futureDate = date('Y-m-d', strtotime('+30 days'));
        
        return self::where('hotel_id', $hotelId)
            ->whereBetween('forecast_date', [$today, $futureDate])
            ->where('predicted_occupancy', '>=', $threshold)
            ->order('predicted_occupancy', 'desc')
            ->column('forecast_date');
    }

    /**
     * 获取预测准确率统计
     */
    public static function getAccuracyStats(int $hotelId, int $days = 30)
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $forecasts = self::where('hotel_id', $hotelId)
            ->whereBetween('forecast_date', [$startDate, $endDate])
            ->where('actual_occupancy', '>', 0)
            ->field([
                'AVG(ABS(predicted_occupancy - actual_occupancy)) as avg_error',
                'COUNT(*) as total_count',
                'SUM(CASE WHEN ABS(predicted_occupancy - actual_occupancy) <= 10 THEN 1 ELSE 0 END) as accurate_count',
            ])
            ->find();
        
        if ($forecasts && $forecasts['total_count'] > 0) {
            return [
                'avg_error' => round($forecasts['avg_error'], 2),
                'accuracy_rate' => round($forecasts['accurate_count'] / $forecasts['total_count'] * 100, 2),
                'total_forecasts' => $forecasts['total_count'],
            ];
        }
        
        return [
            'avg_error' => 0,
            'accuracy_rate' => 0,
            'total_forecasts' => 0,
        ];
    }
}
