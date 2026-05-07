<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 竞对分析模型
 * 用于收益管理Agent的竞争对手价格监控和分析
 */
class CompetitorAnalysis extends Model
{
    protected $name = 'competitor_analysis';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'competitor_hotel_id' => 'integer',
        'room_type_id' => 'integer',
        'competitor_room_type_id' => 'integer',
        'our_price' => 'float',
        'competitor_price' => 'float',
        'price_difference' => 'float',
        'price_index' => 'float',
        'ota_platform' => 'integer',
        'competitor_data' => 'json',
    ];
    
    protected $json = ['competitor_data'];
    protected $jsonAssoc = true;

    // OTA平台常量
    const PLATFORM_CTRIP = 1;         // 携程
    const PLATFORM_MEITUAN = 2;       // 美团
    const PLATFORM_FLIGGY = 3;        // 飞猪
    const PLATFORM_BOOKING = 4;       // Booking.com
    const PLATFORM_EXPEDIA = 5;       // Expedia

    // 价格状态常量
    const STATUS_HIGHER = 1;          // 我方价格高
    const STATUS_LOWER = 2;           // 我方价格低
    const STATUS_EQUAL = 3;           // 价格相等
    const STATUS_UNKNOWN = 4;         // 未知

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联竞对酒店
     */
    public function competitorHotel()
    {
        return $this->belongsTo(Hotel::class, 'competitor_hotel_id', 'id');
    }

    /**
     * 关联房型
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    /**
     * 获取OTA平台名称
     */
    public function getOtaPlatformNameAttr($value, $data)
    {
        $names = [
            self::PLATFORM_CTRIP => '携程',
            self::PLATFORM_MEITUAN => '美团',
            self::PLATFORM_FLIGGY => '飞猪',
            self::PLATFORM_BOOKING => 'Booking.com',
            self::PLATFORM_EXPEDIA => 'Expedia',
        ];
        return $names[$data['ota_platform']] ?? '未知';
    }

    /**
     * 获取价格对比状态
     */
    public function getPriceStatusAttr($value, $data)
    {
        if ($data['price_difference'] > 0) {
            return self::STATUS_HIGHER;
        } elseif ($data['price_difference'] < 0) {
            return self::STATUS_LOWER;
        }
        return self::STATUS_EQUAL;
    }

    /**
     * 获取价格对比状态名称
     */
    public function getPriceStatusNameAttr($value, $data)
    {
        $status = $this->getPriceStatusAttr($value, $data);
        $names = [
            self::STATUS_HIGHER => '我方高',
            self::STATUS_LOWER => '我方低',
            self::STATUS_EQUAL => '价格相等',
            self::STATUS_UNKNOWN => '未知',
        ];
        return $names[$status] ?? '未知';
    }

    /**
     * 获取价格差异百分比
     */
    public function getPriceDiffPercentAttr($value, $data)
    {
        if ($data['competitor_price'] > 0) {
            return round(($data['our_price'] - $data['competitor_price']) / $data['competitor_price'] * 100, 2);
        }
        return 0;
    }

    /**
     * 记录竞对价格
     */
    public static function recordAnalysis(int $hotelId, int $competitorId, array $data): self
    {
        $analysis = new self();
        $analysis->hotel_id = $hotelId;
        $analysis->competitor_hotel_id = $competitorId;
        $analysis->analysis_date = $data['analysis_date'] ?? date('Y-m-d');
        $analysis->room_type_id = $data['room_type_id'] ?? 0;
        $analysis->competitor_room_type_id = $data['competitor_room_type_id'] ?? 0;
        $analysis->our_price = $data['our_price'] ?? 0;
        $analysis->competitor_price = $data['competitor_price'] ?? 0;
        $analysis->price_difference = $analysis->our_price - $analysis->competitor_price;
        $analysis->price_index = $data['price_index'] ?? 100;
        $analysis->ota_platform = $data['ota_platform'] ?? self::PLATFORM_CTRIP;
        $analysis->competitor_data = $data['competitor_data'] ?? [];
        $analysis->save();
        
        return $analysis;
    }

    /**
     * 获取竞对价格矩阵
     */
    public static function getPriceMatrix(int $hotelId, string $date)
    {
        $results = self::where('hotel_id', $hotelId)
            ->where('analysis_date', $date)
            ->with(['roomType', 'competitorHotel'])
            ->select();
        
        $matrix = [];
        foreach ($results as $item) {
            $roomTypeName = $item->roomType->name ?? '未知房型';
            $competitorName = $item->competitorHotel->name ?? '未知竞对';
            
            if (!isset($matrix[$roomTypeName])) {
                $matrix[$roomTypeName] = [];
            }
            
            $matrix[$roomTypeName][$competitorName] = [
                'our_price' => $item->our_price,
                'competitor_price' => $item->competitor_price,
                'difference' => $item->price_difference,
                'diff_percent' => $item->price_diff_percent,
                'status' => $item->price_status_name,
            ];
        }
        
        return $matrix;
    }

    /**
     * 获取价格趋势（最近7天）
     */
    public static function getPriceTrend(int $hotelId, int $competitorId, int $roomTypeId = 0)
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-7 days'));
        
        $query = self::where('hotel_id', $hotelId)
            ->where('competitor_hotel_id', $competitorId)
            ->whereBetween('analysis_date', [$startDate, $endDate]);
        
        if ($roomTypeId > 0) {
            $query->where('room_type_id', $roomTypeId);
        }
        
        return $query->order('analysis_date', 'asc')
            ->select();
    }

    /**
     * 获取需要关注的竞对（价格波动大）
     */
    public static function getAlertCompetitors(int $hotelId, float $threshold = 20)
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        
        return self::where('hotel_id', $hotelId)
            ->where('analysis_date', $today)
            ->whereRaw("ABS(price_difference) >= {$threshold}")
            ->with('competitorHotel')
            ->order('price_difference', 'desc')
            ->select();
    }
}
