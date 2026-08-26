<?php
declare(strict_types=1);

namespace app\model;

use app\model\base\BaseTenantModel;
use think\facade\Db;

/**
 * 需求预测模型
 * 用于收益管理Agent的需求预测和RevPAR优化
 */
class DemandForecast extends BaseTenantModel
{
    public const MANUAL_INPUT_TYPE = 'manual_demand_forecast';

    protected $name = 'demand_forecasts';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
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
     * Save the operator-provided pricing forecast as one editable record.
     * Model-generated forecasts for the same hotel/room/date are deliberately
     * kept separate and are never overwritten by this workflow.
     *
     * @param array<string, mixed> $data
     * @return array{forecast:self,write_action:string,readback_verified:bool}
     */
    public static function saveManualForecast(int $hotelId, string $forecastDate, array $data): array
    {
        foreach ([
            'forecast_method',
            'predicted_occupancy',
            'predicted_demand',
            'confidence_score',
            'is_event_driven',
            'event_factors',
            'historical_data',
            'remark',
        ] as $requiredField) {
            if (!array_key_exists($requiredField, $data)) {
                throw new \InvalidArgumentException($requiredField . ' is required');
            }
        }

        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        $historicalData = self::normalizeJsonArray($data['historical_data'] ?? null);
        if ($roomTypeId <= 0 || (string)($historicalData['input_type'] ?? '') !== self::MANUAL_INPUT_TYPE) {
            throw new \InvalidArgumentException('manual demand forecast identity is required');
        }

        $existing = self::latestManualForecast($hotelId, $roomTypeId, $forecastDate);
        $writeAction = $existing instanceof self ? 'updated' : 'created';
        if (!$existing instanceof self) {
            $forecast = new self();
            $forecast->hotel_id = $hotelId;
            $forecast->forecast_date = $forecastDate;
            $forecast->room_type_id = $roomTypeId;
            $forecast->forecast_method = (int)$data['forecast_method'];
            $forecast->predicted_occupancy = (float)$data['predicted_occupancy'];
            $forecast->predicted_demand = (int)$data['predicted_demand'];
            $forecast->confidence_score = (float)$data['confidence_score'];
            $forecast->is_event_driven = (int)$data['is_event_driven'];
            $forecast->event_factors = self::normalizeJsonArray($data['event_factors']);
            $forecast->historical_data = $historicalData;
            $forecast->remark = (string)$data['remark'];
            $forecast->save();
        } else {
            // An ORM model loaded from a scoped collection can retain that broad
            // query state. Use the complete immutable identity for an exact-row
            // update so another manual/model forecast can never be overwritten.
            $affected = Db::name('demand_forecasts')
                ->where('tenant_id', (int)$existing->tenant_id)
                ->where('id', (int)$existing->id)
                ->where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId)
                ->where('forecast_date', $forecastDate)
                ->update([
                    'forecast_method' => (int)$data['forecast_method'],
                    'predicted_occupancy' => (float)$data['predicted_occupancy'],
                    'predicted_demand' => (int)$data['predicted_demand'],
                    'confidence_score' => (float)$data['confidence_score'],
                    'is_event_driven' => (int)$data['is_event_driven'],
                    'event_factors' => json_encode(
                        self::normalizeJsonArray($data['event_factors']),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'historical_data' => json_encode(
                        $historicalData,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'remark' => (string)$data['remark'],
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            if ($affected > 1) {
                throw new \RuntimeException('manual demand forecast update affected multiple rows');
            }

            $forecast = self::find((int)$existing->id);
            if (!$forecast instanceof self) {
                throw new \RuntimeException('manual demand forecast disappeared before readback');
            }
        }

        $readback = self::find((int)$forecast->id);
        if (!$readback instanceof self) {
            return [
                'forecast' => $forecast,
                'write_action' => $writeAction,
                'readback_verified' => false,
            ];
        }

        return [
            'forecast' => $readback,
            'write_action' => $writeAction,
            'readback_verified' => self::manualReadbackMatches($readback, $hotelId, $forecastDate, $data),
        ];
    }

    public static function latestForPricing(int $hotelId, int $roomTypeId, string $forecastDate): ?self
    {
        $forecasts = self::orderedForecasts($hotelId, $roomTypeId, $forecastDate);
        $latest = null;
        foreach ($forecasts as $forecast) {
            if (!$forecast instanceof self) {
                continue;
            }
            $latest ??= $forecast;
            if (self::isManualForecast($forecast)) {
                return $forecast;
            }
        }

        return $latest;
    }

    private static function latestManualForecast(int $hotelId, int $roomTypeId, string $forecastDate): ?self
    {
        foreach (self::orderedForecasts($hotelId, $roomTypeId, $forecastDate) as $forecast) {
            if ($forecast instanceof self && self::isManualForecast($forecast)) {
                return $forecast;
            }
        }

        return null;
    }

    /** @return list<self> */
    private static function orderedForecasts(int $hotelId, int $roomTypeId, string $forecastDate): array
    {
        $rows = self::where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('forecast_date', $forecastDate)
            ->select();

        $forecasts = [];
        foreach ($rows as $row) {
            if ($row instanceof self) {
                $forecasts[] = $row;
            }
        }
        usort($forecasts, static function (self $left, self $right): int {
            foreach (['update_time', 'create_time'] as $field) {
                $comparison = self::timestampValue($right->{$field} ?? null)
                    <=> self::timestampValue($left->{$field} ?? null);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return (int)$right->id <=> (int)$left->id;
        });

        return $forecasts;
    }

    private static function timestampValue(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }

        $timestamp = strtotime((string)$value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function isManualForecast(self $forecast): bool
    {
        $metadata = self::normalizeJsonArray($forecast->historical_data ?? null);
        return (string)($metadata['input_type'] ?? '') === self::MANUAL_INPUT_TYPE;
    }

    /** @return array<string|int, mixed> */
    private static function normalizeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private static function manualReadbackMatches(
        self $readback,
        int $hotelId,
        string $forecastDate,
        array $data
    ): bool {
        return (int)$readback->hotel_id === $hotelId
            && (int)$readback->room_type_id === (int)($data['room_type_id'] ?? 0)
            && (string)$readback->forecast_date === $forecastDate
            && (int)$readback->forecast_method === (int)($data['forecast_method'] ?? 0)
            && abs((float)$readback->predicted_occupancy - (float)($data['predicted_occupancy'] ?? 0)) < 0.0001
            && (int)$readback->predicted_demand === (int)($data['predicted_demand'] ?? 0)
            && abs((float)$readback->confidence_score - (float)($data['confidence_score'] ?? 0)) < 0.0001
            && (int)$readback->is_event_driven === (int)($data['is_event_driven'] ?? 0)
            && self::normalizeJsonArray($readback->event_factors ?? null) == self::normalizeJsonArray($data['event_factors'] ?? [])
            && self::normalizeJsonArray($readback->historical_data ?? null) == self::normalizeJsonArray($data['historical_data'] ?? [])
            && (string)$readback->remark === (string)($data['remark'] ?? '');
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
    public static function getHighDemandDates(int $hotelId, float $threshold = 80.0, ?string $businessDate = null)
    {
        $anchorDate = $businessDate ?: date('Y-m-d');
        if (date('Y-m-d', (int)strtotime($anchorDate)) !== $anchorDate) {
            throw new \InvalidArgumentException('businessDate must be YYYY-MM-DD');
        }
        $futureDate = date('Y-m-d', strtotime($anchorDate . ' +30 days'));
        
        return self::where('hotel_id', $hotelId)
            ->whereBetween('forecast_date', [$anchorDate, $futureDate])
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
