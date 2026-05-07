<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Hotel extends Model
{
    protected $name = 'hotels';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
    ];

    // 状态常量
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->hasMany(User::class, 'hotel_id', 'id');
    }

    /**
     * 关联日报表
     */
    public function dailyReports()
    {
        return $this->hasMany(DailyReport::class, 'hotel_id', 'id');
    }

    /**
     * 关联月任务
     */
    public function monthlyTasks()
    {
        return $this->hasMany(MonthlyTask::class, 'hotel_id', 'id');
    }

    /**
     * 搜索器 - 名称
     */
    public function searchNameAttr($query, $value)
    {
        $query->whereLike('name', '%' . $value . '%');
    }

    /**
     * 搜索器 - 状态
     */
    public function searchStatusAttr($query, $value)
    {
        $query->where('status', $value);
    }
}
