<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Agent日志模型
 * 记录Agent的运行日志和操作记录
 */
class AgentLog extends Model
{
    protected $name = 'agent_logs';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = false;
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'agent_type' => 'integer',
        'log_level' => 'integer',
        'user_id' => 'integer',
        'context_data' => 'json',
    ];
    
    protected $json = ['context_data'];
    protected $jsonAssoc = true;

    // Agent类型常量
    const AGENT_TYPE_STAFF = 1;
    const AGENT_TYPE_REVENUE = 2;
    const AGENT_TYPE_ASSET = 3;

    // 日志级别常量
    const LEVEL_DEBUG = 1;
    const LEVEL_INFO = 2;
    const LEVEL_WARNING = 3;
    const LEVEL_ERROR = 4;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - Agent类型
     */
    public function searchAgentTypeAttr($query, $value)
    {
        $query->where('agent_type', $value);
    }

    /**
     * 搜索器 - 日志级别
     */
    public function searchLogLevelAttr($query, $value)
    {
        $query->where('log_level', $value);
    }

    /**
     * 搜索器 - 时间范围
     */
    public function searchDateRangeAttr($query, $value)
    {
        if (isset($value['start']) && isset($value['end'])) {
            $query->whereBetween('create_time', [$value['start'], $value['end']]);
        }
    }

    /**
     * 获取Agent类型名称
     */
    public function getAgentTypeNameAttr($value, $data)
    {
        $names = [
            self::AGENT_TYPE_STAFF => '智能员工Agent',
            self::AGENT_TYPE_REVENUE => '收益管理Agent',
            self::AGENT_TYPE_ASSET => '资产运维Agent',
        ];
        return $names[$data['agent_type']] ?? '未知';
    }

    /**
     * 获取日志级别名称
     */
    public function getLogLevelNameAttr($value, $data)
    {
        $names = [
            self::LEVEL_DEBUG => '调试',
            self::LEVEL_INFO => '信息',
            self::LEVEL_WARNING => '警告',
            self::LEVEL_ERROR => '错误',
        ];
        return $names[$data['log_level']] ?? '未知';
    }

    /**
     * 快速记录日志
     */
    public static function record(int $hotelId, int $agentType, string $action, string $message, int $level = self::LEVEL_INFO, array $context = [], int $userId = 0): self
    {
        return self::create([
            'hotel_id' => $hotelId,
            'agent_type' => $agentType,
            'action' => $action,
            'message' => $message,
            'log_level' => $level,
            'context_data' => $context,
            'user_id' => $userId,
        ]);
    }
}
