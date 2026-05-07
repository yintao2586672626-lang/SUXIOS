<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Agent任务模型
 * 用于管理Agent的定时任务和执行记录
 */
class AgentTask extends Model
{
    protected $name = 'agent_tasks';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'agent_type' => 'integer',
        'task_type' => 'integer',
        'status' => 'integer',
        'priority' => 'integer',
        'result_data' => 'json',
        'execute_time' => 'datetime',
        'completed_time' => 'datetime',
    ];
    
    protected $json = ['result_data'];
    protected $jsonAssoc = true;

    // Agent类型常量
    const AGENT_TYPE_STAFF = 1;
    const AGENT_TYPE_REVENUE = 2;
    const AGENT_TYPE_ASSET = 3;

    // 任务类型常量
    const TASK_TYPE_DATA_COLLECT = 1;    // 数据采集
    const TASK_TYPE_ANALYSIS = 2;        // 数据分析
    const TASK_TYPE_NOTIFICATION = 3;    // 通知推送
    const TASK_TYPE_ACTION = 4;          // 执行动作

    // 状态常量
    const STATUS_PENDING = 1;      // 待执行
    const STATUS_RUNNING = 2;      // 执行中
    const STATUS_COMPLETED = 3;    // 已完成
    const STATUS_FAILED = 4;       // 失败
    const STATUS_CANCELLED = 5;    // 已取消

    // 优先级常量
    const PRIORITY_LOW = 1;
    const PRIORITY_NORMAL = 2;
    const PRIORITY_HIGH = 3;
    const PRIORITY_URGENT = 4;

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
     * 搜索器 - 任务状态
     */
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
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
     * 获取任务类型名称
     */
    public function getTaskTypeNameAttr($value, $data)
    {
        $names = [
            self::TASK_TYPE_DATA_COLLECT => '数据采集',
            self::TASK_TYPE_ANALYSIS => '数据分析',
            self::TASK_TYPE_NOTIFICATION => '通知推送',
            self::TASK_TYPE_ACTION => '执行动作',
        ];
        return $names[$data['task_type']] ?? '未知';
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttr($value, $data)
    {
        $names = [
            self::STATUS_PENDING => '待执行',
            self::STATUS_RUNNING => '执行中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
        ];
        return $names[$data['status']] ?? '未知';
    }

    /**
     * 标记任务为执行中
     */
    public function markAsRunning(): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->execute_time = date('Y-m-d H:i:s');;
        $this->save();
    }

    /**
     * 标记任务为完成
     */
    public function markAsCompleted(array $result = []): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_time = date('Y-m-d H:i:s');
        $this->result_data = $result;
        $this->save();
    }

    /**
     * 标记任务为失败
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->completed_time = date('Y-m-d H:i:s');
        $this->result_data = ['error' => $errorMessage];
        $this->save();
    }

    /**
     * 创建新任务
     */
    public static function createTask(int $hotelId, int $agentType, int $taskType, string $taskName, array $params = [], int $priority = self::PRIORITY_NORMAL): self
    {
        return self::create([
            'hotel_id' => $hotelId,
            'agent_type' => $agentType,
            'task_type' => $taskType,
            'task_name' => $taskName,
            'params' => $params,
            'status' => self::STATUS_PENDING,
            'priority' => $priority,
        ]);
    }
}
