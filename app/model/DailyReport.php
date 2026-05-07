<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class DailyReport extends Model
{
    protected $name = 'daily_reports';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // JSON 字段自动转换
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'report_data' => 'json',
        'submitter_id' => 'integer',
        'status' => 'integer',
    ];
    
    // JSON 字段提取
    protected $json = ['report_data'];
    protected $jsonAssoc = true;

    // 状态常量
    const STATUS_DRAFT = 1;      // 草稿
    const STATUS_SUBMITTED = 2;  // 已提交

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
     * 搜索器 - 日期范围
     */
    public function searchDateRangeAttr($query, $value)
    {
        if (isset($value['start']) && isset($value['end'])) {
            $query->whereBetween('report_date', [$value['start'], $value['end']]);
        }
    }
    
    /**
     * 获取报表数据中的指定字段值
     */
    public function getReportValue(string $field, $default = null)
    {
        $data = $this->report_data;
        return $data[$field] ?? $default;
    }
    
    /**
     * 设置报表数据
     */
    public function setReportData(array $data): void
    {
        $this->report_data = $data;
    }
}
