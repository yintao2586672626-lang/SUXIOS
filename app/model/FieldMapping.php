<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 字段映射配置模型
 */
class FieldMapping extends Model
{
    // 表名
    protected $name = 'field_mappings';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    
    // 类型转换
    protected $type = [
        'priority' => 'integer',
        'is_active' => 'integer',
    ];
    
    // 状态常量
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;
    
    /**
     * 获取启用的映射配置（支持门店过滤）
     */
    public static function getActiveMappings(?int $hotelId = null): array
    {
        $query = self::where('is_active', self::STATUS_ENABLED);
        
        if ($hotelId !== null) {
            // 优先获取门店专属映射，其次获取全局映射
            $query->where(function($q) use ($hotelId) {
                $q->where('hotel_id', $hotelId)->whereOr('hotel_id', null);
            });
        }
        
        return $query->order('priority', 'desc')
            ->order('hotel_id', 'desc') // 门店映射优先于全局
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }
    
    /**
     * 根据行号和门店查找映射
     */
    public static function findByRowAndHotel(int $rowNum, int $hotelId): ?array
    {
        // 优先匹配门店+行号
        $mapping = self::where('row_num', $rowNum)
            ->where('hotel_id', $hotelId)
            ->where('is_active', self::STATUS_ENABLED)
            ->find();
        
        if (!$mapping) {
            // 其次匹配全局+行号
            $mapping = self::where('row_num', $rowNum)
                ->where('hotel_id', null)
                ->where('is_active', self::STATUS_ENABLED)
                ->find();
        }
        
        return $mapping ? $mapping->toArray() : null;
    }
    
    /**
     * 根据Excel项目名查找映射
     */
    public static function findByExcelItem(string $excelItem): ?array
    {
        $mapping = self::where('excel_item_name', $excelItem)
            ->where('is_active', self::STATUS_ENABLED)
            ->find();
        return $mapping ? $mapping->toArray() : null;
    }
    
    /**
     * 根据系统字段查找映射
     */
    public static function findBySystemField(string $systemField): ?array
    {
        $mapping = self::where('system_field', $systemField)
            ->where('is_active', self::STATUS_ENABLED)
            ->find();
        return $mapping ? $mapping->toArray() : null;
    }
    
    /**
     * 获取所有系统字段列表（用于下拉选择）
     */
    public static function getSystemFieldOptions(): array
    {
        // 从report_configs获取所有字段
        $configs = ReportConfig::where('report_type', 'daily')
            ->where('status', 1)
            ->field('field_name, display_name, category')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
        
        $options = [];
        foreach ($configs as $config) {
            $options[] = [
                'value' => $config['field_name'],
                'label' => $config['display_name'],
                'category' => $config['category'],
            ];
        }
        
        return $options;
    }
    
    /**
     * 按类别分组获取映射
     */
    public static function getGroupedByCategory(): array
    {
        $mappings = self::where('is_active', self::STATUS_ENABLED)
            ->order('priority', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        
        $grouped = [];
        foreach ($mappings as $mapping) {
            $category = $mapping['category'] ?: '未分类';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $mapping;
        }
        
        return $grouped;
    }
}
