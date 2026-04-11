<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Agent配置模型
 * 用于管理三个Agent的配置信息
 */
class AgentConfig extends Model
{
    protected $name = 'agent_configs';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'agent_type' => 'integer',
        'is_enabled' => 'integer',
        'config_data' => 'json',
    ];
    
    protected $json = ['config_data'];
    protected $jsonAssoc = true;

    // Agent类型常量
    const AGENT_TYPE_STAFF = 1;      // 智能员工Agent
    const AGENT_TYPE_REVENUE = 2;    // 收益管理Agent
    const AGENT_TYPE_ASSET = 3;      // 资产运维Agent

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
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
     * 获取配置值
     */
    public function getConfigValue(string $key, $default = null)
    {
        $data = $this->config_data;
        return $data[$key] ?? $default;
    }

    /**
     * 设置配置值
     */
    public function setConfigValue(string $key, $value): void
    {
        $data = $this->config_data ?? [];
        $data[$key] = $value;
        $this->config_data = $data;
    }
}
