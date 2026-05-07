<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Agent对话记录模型
 * 用于智能员工Agent的对话历史存储和分析
 */
class AgentConversation extends Model
{
    protected $name = 'agent_conversations';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'session_id' => 'string',
        'channel' => 'integer',
        'guest_id' => 'string',
        'guest_name' => 'string',
        'room_id' => 'integer',
        'message_type' => 'integer',
        'intent_type' => 'integer',
        'emotion_score' => 'float',
        'is_ai_reply' => 'integer',
        'confidence_score' => 'float',
        'entities' => 'json',
        'context' => 'json',
    ];
    
    protected $json = ['entities', 'context'];
    protected $jsonAssoc = true;

    // 渠道常量
    const CHANNEL_WECHAT = 1;         // 微信
    const CHANNEL_WORKWECHAT = 2;     // 企业微信
    const CHANNEL_IPAD = 3;           // iPad前台
    const CHANNEL_PHONE = 4;          // 电话
    const CHANNEL_APP = 5;            // APP

    // 消息类型常量
    const MSG_TYPE_TEXT = 1;          // 文本
    const MSG_TYPE_IMAGE = 2;         // 图片
    const MSG_TYPE_VOICE = 3;         // 语音
    const MSG_TYPE_RICH = 4;          // 富文本

    // 意图类型常量
    const INTENT_GREETING = 1;        // 问候
    const INTENT_INQUIRY = 2;         // 咨询
    const INTENT_COMPLAINT = 3;       // 投诉
    const INTENT_BOOKING = 4;         // 预订
    const INTENT_SERVICE = 5;         // 服务请求
    const INTENT_CHECKOUT = 6;        // 退房
    const INTENT_OTHER = 99;          // 其他

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联房间
     */
    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    /**
     * 获取渠道名称
     */
    public function getChannelNameAttr($value, $data)
    {
        $names = [
            self::CHANNEL_WECHAT => '微信',
            self::CHANNEL_WORKWECHAT => '企业微信',
            self::CHANNEL_IPAD => 'iPad前台',
            self::CHANNEL_PHONE => '电话',
            self::CHANNEL_APP => 'APP',
        ];
        return $names[$data['channel']] ?? '未知';
    }

    /**
     * 获取消息类型名称
     */
    public function getMessageTypeNameAttr($value, $data)
    {
        $names = [
            self::MSG_TYPE_TEXT => '文本',
            self::MSG_TYPE_IMAGE => '图片',
            self::MSG_TYPE_VOICE => '语音',
            self::MSG_TYPE_RICH => '富文本',
        ];
        return $names[$data['message_type']] ?? '未知';
    }

    /**
     * 获取意图类型名称
     */
    public function getIntentTypeNameAttr($value, $data)
    {
        $names = [
            self::INTENT_GREETING => '问候',
            self::INTENT_INQUIRY => '咨询',
            self::INTENT_COMPLAINT => '投诉',
            self::INTENT_BOOKING => '预订',
            self::INTENT_SERVICE => '服务请求',
            self::INTENT_CHECKOUT => '退房',
            self::INTENT_OTHER => '其他',
        ];
        return $names[$data['intent_type']] ?? '未知';
    }

    /**
     * 记录对话
     */
    public static function record(int $hotelId, array $data): self
    {
        $conversation = new self();
        $conversation->hotel_id = $hotelId;
        $conversation->session_id = $data['session_id'] ?? uniqid('sess_');
        $conversation->channel = $data['channel'] ?? self::CHANNEL_WECHAT;
        $conversation->guest_id = $data['guest_id'] ?? '';
        $conversation->guest_name = $data['guest_name'] ?? '';
        $conversation->room_id = $data['room_id'] ?? 0;
        $conversation->room_number = $data['room_number'] ?? '';
        $conversation->message_type = $data['message_type'] ?? self::MSG_TYPE_TEXT;
        $conversation->user_message = $data['user_message'] ?? '';
        $conversation->ai_response = $data['ai_response'] ?? '';
        $conversation->intent_type = $data['intent_type'] ?? self::INTENT_OTHER;
        $conversation->emotion_score = $data['emotion_score'] ?? 0;
        $conversation->is_ai_reply = $data['is_ai_reply'] ?? 1;
        $conversation->confidence_score = $data['confidence_score'] ?? 0;
        $conversation->knowledge_id = $data['knowledge_id'] ?? 0;
        $conversation->entities = $data['entities'] ?? [];
        $conversation->context = $data['context'] ?? [];
        $conversation->save();
        
        return $conversation;
    }

    /**
     * 获取会话历史
     */
    public static function getSessionHistory(string $sessionId, int $limit = 50)
    {
        return self::where('session_id', $sessionId)
            ->order('id', 'asc')
            ->limit($limit)
            ->select();
    }

    /**
     * 获取今日对话统计
     */
    public static function getTodayStats(int $hotelId)
    {
        $today = date('Y-m-d');
        return self::where('hotel_id', $hotelId)
            ->whereDay('create_time', $today)
            ->field([
                'COUNT(DISTINCT session_id) as total_sessions',
                'COUNT(*) as total_messages',
                'SUM(CASE WHEN is_ai_reply = 1 THEN 1 ELSE 0 END) as ai_replies',
                'AVG(confidence_score) as avg_confidence',
                'SUM(CASE WHEN emotion_score >= 0.4 THEN 1 ELSE 0 END) as emotion_alerts',
            ])
            ->find();
    }

    /**
     * 获取热门意图统计
     */
    public static function getIntentStats(int $hotelId, int $days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        return self::where('hotel_id', $hotelId)
            ->where('create_time', '>=', $startDate)
            ->field('intent_type, COUNT(*) as count')
            ->group('intent_type')
            ->order('count', 'desc')
            ->select();
    }

    /**
     * 获取情绪分析统计
     */
    public static function getEmotionStats(int $hotelId, int $days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $results = self::where('hotel_id', $hotelId)
            ->where('create_time', '>=', $startDate)
            ->where('emotion_score', '>', 0)
            ->field([
                'AVG(emotion_score) as avg_emotion',
                'SUM(CASE WHEN emotion_score >= 0.6 THEN 1 ELSE 0 END) as negative_count',
                'SUM(CASE WHEN emotion_score < 0.6 AND emotion_score >= 0.3 THEN 1 ELSE 0 END) as neutral_count',
                'SUM(CASE WHEN emotion_score < 0.3 THEN 1 ELSE 0 END) as positive_count',
            ])
            ->find();
        
        return $results ?: [
            'avg_emotion' => 0,
            'negative_count' => 0,
            'neutral_count' => 0,
            'positive_count' => 0,
        ];
    }

    /**
     * 搜索对话
     */
    public static function search(int $hotelId, string $keyword, int $channel = 0, int $page = 1, int $pageSize = 20)
    {
        $query = self::where('hotel_id', $hotelId)
            ->where(function($q) use ($keyword) {
                $q->whereLike('user_message', '%' . $keyword . '%')
                  ->whereOrLike('ai_response', '%' . $keyword . '%')
                  ->whereOrLike('guest_name', '%' . $keyword . '%');
            });
        
        if ($channel > 0) {
            $query->where('channel', $channel);
        }
        
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();
        
        return ['total' => $total, 'list' => $list];
    }
}
