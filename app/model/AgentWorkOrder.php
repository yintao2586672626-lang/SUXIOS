<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Agent工单模型
 * 用于智能员工Agent的工单自动派发和管理
 */
class AgentWorkOrder extends Model
{
    protected $name = 'agent_work_orders';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'agent_id' => 'integer',
        'source_type' => 'integer',
        'order_type' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'room_id' => 'integer',
        'assigned_to' => 'integer',
        'created_by' => 'integer',
        'emotion_score' => 'float',
        'tags' => 'json',
        'attachments' => 'json',
    ];
    
    protected $json = ['tags', 'attachments'];
    protected $jsonAssoc = true;

    // 来源类型常量
    const SOURCE_CHAT = 1;            // 客服对话
    const SOURCE_VOICE = 2;           // 语音投诉
    const SOURCE_SYSTEM = 3;          // 系统告警
    const SOURCE_MANUAL = 4;          // 人工创建

    // 工单类型常量
    const TYPE_COMPLAINT = 1;         // 客诉处理
    const TYPE_MAINTENANCE = 2;       // 维修需求
    const TYPE_SERVICE = 3;           // 服务请求
    const TYPE_CLEANING = 4;          // 清洁需求
    const TYPE_OTHER = 5;             // 其他

    // 优先级常量
    const PRIORITY_LOW = 1;           // 低
    const PRIORITY_NORMAL = 2;        // 中
    const PRIORITY_HIGH = 3;          // 高
    const PRIORITY_URGENT = 4;        // 紧急

    // 状态常量
    const STATUS_PENDING = 1;         // 待处理
    const STATUS_PROCESSING = 2;      // 处理中
    const STATUS_WAITING = 3;         // 等待反馈
    const STATUS_RESOLVED = 4;        // 已解决
    const STATUS_CLOSED = 5;          // 已关闭
    const STATUS_ESCALATED = 6;       // 已升级

    // 情绪等级常量
    const EMOTION_CALM = 1;           // 平静
    const EMOTION_MILD = 2;           // 轻微不满
    const EMOTION_MODERATE = 3;       // 中度不满
    const EMOTION_SEVERE = 4;         // 严重不满
    const EMOTION_ANGRY = 5;          // 愤怒

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联处理人
     */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to', 'id');
    }

    /**
     * 关联创建人
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * 关联房间
     */
    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    /**
     * 获取来源类型名称
     */
    public function getSourceTypeNameAttr($value, $data)
    {
        $names = [
            self::SOURCE_CHAT => '客服对话',
            self::SOURCE_VOICE => '语音投诉',
            self::SOURCE_SYSTEM => '系统告警',
            self::SOURCE_MANUAL => '人工创建',
        ];
        return $names[$data['source_type']] ?? '未知';
    }

    /**
     * 获取工单类型名称
     */
    public function getOrderTypeNameAttr($value, $data)
    {
        $names = [
            self::TYPE_COMPLAINT => '客诉处理',
            self::TYPE_MAINTENANCE => '维修需求',
            self::TYPE_SERVICE => '服务请求',
            self::TYPE_CLEANING => '清洁需求',
            self::TYPE_OTHER => '其他',
        ];
        return $names[$data['order_type']] ?? '未知';
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
            self::PRIORITY_URGENT => '紧急',
        ];
        return $names[$data['priority']] ?? '未知';
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_PENDING => '待处理',
            self::STATUS_PROCESSING => '处理中',
            self::STATUS_WAITING => '等待反馈',
            self::STATUS_RESOLVED => '已解决',
            self::STATUS_CLOSED => '已关闭',
            self::STATUS_ESCALATED => '已升级',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 获取情绪等级
     */
    public function getEmotionLevelAttr($value, $data)
    {
        $score = $data['emotion_score'] ?? 0;
        if ($score >= 0.8) return self::EMOTION_ANGRY;
        if ($score >= 0.6) return self::EMOTION_SEVERE;
        if ($score >= 0.4) return self::EMOTION_MODERATE;
        if ($score >= 0.2) return self::EMOTION_MILD;
        return self::EMOTION_CALM;
    }

    /**
     * 获取情绪等级名称
     */
    public function getEmotionLevelNameAttr($value, $data)
    {
        $level = $this->getEmotionLevelAttr($value, $data);
        $names = [
            self::EMOTION_CALM => '平静',
            self::EMOTION_MILD => '轻微不满',
            self::EMOTION_MODERATE => '中度不满',
            self::EMOTION_SEVERE => '严重不满',
            self::EMOTION_ANGRY => '愤怒',
        ];
        return $names[$level] ?? '未知';
    }

    /**
     * 是否需要转人工
     */
    public function getNeedHumanTransferAttr($value, $data)
    {
        $emotionLevel = $this->getEmotionLevelAttr($value, $data);
        return $emotionLevel >= self::EMOTION_MODERATE || $data['priority'] == self::PRIORITY_URGENT;
    }

    /**
     * 创建工单
     */
    public static function createOrder(int $hotelId, array $data): self
    {
        $order = new self();
        $order->hotel_id = $hotelId;
        $order->agent_id = $data['agent_id'] ?? 0;
        $order->source_type = $data['source_type'] ?? self::SOURCE_MANUAL;
        $order->order_type = $data['order_type'] ?? self::TYPE_OTHER;
        $order->priority = $data['priority'] ?? self::PRIORITY_NORMAL;
        $order->status = self::STATUS_PENDING;
        $order->title = $data['title'] ?? '';
        $order->content = $data['content'] ?? '';
        $order->guest_name = $data['guest_name'] ?? '';
        $order->guest_phone = $data['guest_phone'] ?? '';
        $order->room_id = $data['room_id'] ?? 0;
        $order->room_number = $data['room_number'] ?? '';
        $order->emotion_score = $data['emotion_score'] ?? 0;
        $order->tags = $data['tags'] ?? [];
        $order->attachments = $data['attachments'] ?? [];
        $order->created_by = $data['created_by'] ?? 0;
        $order->assigned_to = $data['assigned_to'] ?? 0;
        $order->save();
        
        return $order;
    }

    /**
     * 分配工单
     */
    public function assign(int $userId): void
    {
        $this->assigned_to = $userId;
        $this->status = self::STATUS_PROCESSING;
        $this->assigned_time = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 解决工单
     */
    public function resolve(string $solution = ''): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->solution = $solution;
        $this->resolved_time = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 升级工单
     */
    public function escalate(string $reason = ''): void
    {
        $this->status = self::STATUS_ESCALATED;
        $this->escalate_reason = $reason;
        $this->escalated_time = date('Y-m-d H:i:s');
        $this->save();
    }

    /**
     * 获取待处理工单统计
     */
    public static function getPendingStats(int $hotelId)
    {
        $stats = self::where('hotel_id', $hotelId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->field([
                'COUNT(*) as total_pending',
                'SUM(CASE WHEN priority = ' . self::PRIORITY_URGENT . ' THEN 1 ELSE 0 END) as urgent_count',
                'SUM(CASE WHEN priority = ' . self::PRIORITY_HIGH . ' THEN 1 ELSE 0 END) as high_count',
                'SUM(CASE WHEN emotion_score >= 0.4 THEN 1 ELSE 0 END) as emotion_alert_count',
            ])
            ->find();
        
        return $stats ?: [
            'total_pending' => 0,
            'urgent_count' => 0,
            'high_count' => 0,
            'emotion_alert_count' => 0,
        ];
    }

    /**
     * 获取今日工单统计
     */
    public static function getTodayStats(int $hotelId)
    {
        $today = date('Y-m-d');
        return self::where('hotel_id', $hotelId)
            ->whereDay('create_time', $today)
            ->field([
                'COUNT(*) as total',
                'SUM(CASE WHEN status = ' . self::STATUS_RESOLVED . ' THEN 1 ELSE 0 END) as resolved',
                'AVG(CASE WHEN resolved_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, create_time, resolved_time) END) as avg_resolve_time',
            ])
            ->find();
    }
}
