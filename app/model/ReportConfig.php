<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 报表配置模型
 * 用于配置日报表和月任务的填报项目
 */
class ReportConfig extends Model
{
    // 表名
    protected $name = 'report_configs';

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 类型转换
    protected $type = [
        'sort_order' => 'integer',
        'is_required' => 'integer',
        'status' => 'integer',
    ];

    // 报表类型常量
    const TYPE_DAILY = 'daily';      // 日报表
    const TYPE_MONTHLY = 'monthly';  // 月任务

    // 字段类型常量
    const FIELD_TYPE_NUMBER = 'number';
    const FIELD_TYPE_TEXT = 'text';
    const FIELD_TYPE_TEXTAREA = 'textarea';
    const FIELD_TYPE_SELECT = 'select';
    const FIELD_TYPE_DATE = 'date';

    // 状态常量
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;

    /**
     * 获取日报表配置
     */
    public static function getDailyConfigs(): array
    {
        return self::where('report_type', self::TYPE_DAILY)
            ->where('status', self::STATUS_ENABLED)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取月任务配置
     */
    public static function getMonthlyConfigs(): array
    {
        return self::where('report_type', self::TYPE_MONTHLY)
            ->where('status', self::STATUS_ENABLED)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取字段类型选项
     */
    public static function getFieldTypeOptions(): array
    {
        return [
            self::FIELD_TYPE_NUMBER => '数字',
            self::FIELD_TYPE_TEXT => '文本',
            self::FIELD_TYPE_TEXTAREA => '多行文本',
            self::FIELD_TYPE_SELECT => '下拉选择',
            self::FIELD_TYPE_DATE => '日期',
        ];
    }
}
