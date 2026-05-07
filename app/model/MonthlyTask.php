<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class MonthlyTask extends Model
{
    protected $name = 'monthly_tasks';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // JSON 字段自动转换
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'year' => 'integer',
        'month' => 'integer',
        'task_data' => 'json',
        'submitter_id' => 'integer',
        'status' => 'integer',
    ];
    
    // JSON 字段提取
    protected $json = ['task_data'];
    protected $jsonAssoc = true;

    // 状态常量
    const STATUS_ENABLED = 1;

    /**
     * 关联酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联提交人
     */
    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitter_id', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - 年月
     */
    public function searchYearMonthAttr($query, $value)
    {
        if (isset($value['year'])) {
            $query->where('year', $value['year']);
        }
        if (isset($value['month'])) {
            $query->where('month', $value['month']);
        }
    }
    
    /**
     * 获取任务数据中的指定字段值
     */
    public function getTaskValue(string $field, $default = null)
    {
        $data = $this->task_data;
        return $data[$field] ?? $default;
    }
    
    /**
     * 设置任务数据
     */
    public function setTaskData(array $data): void
    {
        $this->task_data = $data;
    }
}
