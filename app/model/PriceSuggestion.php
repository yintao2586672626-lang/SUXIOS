<?php
declare(strict_types=1);

namespace app\model;

use app\model\base\BaseTenantModel;
use think\facade\Db;
use think\Model;

/**
 * 定价建议模型
 * 用于收益管理Agent生成定价建议
 */
class PriceSuggestion extends BaseTenantModel
{
    protected $name = 'price_suggestions';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'tenant_id' => 'integer',
        'hotel_id' => 'integer',
        'room_type_id' => 'integer',
        'demand_forecast_id' => 'integer',
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
    protected $hidden = ['active_dedupe_key'];

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

    public static function activeDedupeKey(
        int $tenantId,
        int $hotelId,
        int $roomTypeId,
        string $suggestionDate
    ): string {
        $suggestionDate = trim($suggestionDate);
        if ($tenantId <= 0 || $hotelId <= 0 || $roomTypeId <= 0
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $suggestionDate) !== 1
        ) {
            throw new \InvalidArgumentException('Pending price suggestion identity is incomplete');
        }

        return hash('sha256', implode('|', [
            'price_suggestion_pending_v1',
            $tenantId,
            $hotelId,
            $roomTypeId,
            $suggestionDate,
        ]));
    }

    protected static function onBeforeWrite(Model $model): void
    {
        parent::onBeforeWrite($model);
        if (!$model instanceof self) {
            return;
        }

        $data = $model->getData();
        $status = (int)($data['status'] ?? self::STATUS_PENDING);
        if ($status !== self::STATUS_PENDING) {
            $model->setAttr('active_dedupe_key', null);
            return;
        }

        $model->setAttr('active_dedupe_key', self::activeDedupeKey(
            (int)($data['tenant_id'] ?? 0),
            (int)($data['hotel_id'] ?? 0),
            (int)($data['room_type_id'] ?? 0),
            (string)($data['suggestion_date'] ?? '')
        ));
    }

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
    public function approve(int $userId, string $remark = ''): self
    {
        return $this->transitionFromStatus(
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            $userId,
            $remark
        );
    }

    /**
     * 拒绝建议
     */
    public function reject(int $userId, string $reason = ''): self
    {
        return $this->transitionFromStatus(
            self::STATUS_PENDING,
            self::STATUS_REJECTED,
            $userId,
            $reason
        );
    }

    /**
     * 应用建议
     */
    public function apply(int $userId): self
    {
        return $this->transitionFromStatus(
            self::STATUS_APPROVED,
            self::STATUS_APPLIED,
            $userId,
            (string)($this->remark ?? ''),
            null,
            ['applied_time' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Persist one human review decision with a compare-and-swap status guard.
     *
     * @param array<string, mixed>|null $factors
     */
    public function reviewPending(
        int $targetStatus,
        int $userId,
        string $remark = '',
        ?array $factors = null
    ): self {
        if (!in_array($targetStatus, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            throw new \InvalidArgumentException('price_suggestion_review_target_status_invalid');
        }

        return $this->transitionFromStatus(
            self::STATUS_PENDING,
            $targetStatus,
            $userId,
            $remark,
            $factors
        );
    }

    /**
     * @param array<string, mixed>|null $factors
     * @param array<string, mixed> $extra
     */
    private function transitionFromStatus(
        int $expectedStatus,
        int $targetStatus,
        int $userId,
        string $remark,
        ?array $factors = null,
        array $extra = []
    ): self {
        $data = $this->getData();
        $id = (int)($data['id'] ?? 0);
        $tenantId = (int)($data['tenant_id'] ?? 0);
        $hotelId = (int)($data['hotel_id'] ?? 0);
        if ($id <= 0 || $tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('price_suggestion_transition_identity_invalid');
        }

        $payload = array_merge($extra, [
            'status' => $targetStatus,
            'applied_by' => $userId,
            'remark' => $remark,
            'active_dedupe_key' => null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        if ($factors !== null) {
            $payload['factors'] = json_encode(
                $factors,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        return Db::transaction(function () use (
            $id,
            $tenantId,
            $hotelId,
            $expectedStatus,
            $targetStatus,
            $payload
        ): self {
            $affected = self::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('status', $expectedStatus)
                ->update($payload);
            if ((int)$affected !== 1) {
                $current = self::where('id', $id)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->lock(true)
                    ->find();
                if (!$current) {
                    throw new \RuntimeException('price_suggestion_not_found', 404);
                }

                throw new \RuntimeException(
                    $expectedStatus === self::STATUS_PENDING
                        ? 'price_suggestion_not_pending_review'
                        : 'price_suggestion_status_transition_conflict',
                    409
                );
            }

            $fresh = self::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('status', $targetStatus)
                ->lock(true)
                ->find();
            if (!$fresh || ($fresh->getData()['active_dedupe_key'] ?? null) !== null) {
                throw new \RuntimeException('price_suggestion_transition_readback_mismatch', 409);
            }

            return $fresh;
        });
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
