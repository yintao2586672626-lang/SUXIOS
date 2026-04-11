<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 设备分类模型
 */
class DeviceCategory extends Model
{
    protected $name = 'device_categories';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'is_enabled' => 'integer',
        'default_maintenance_cycle' => 'integer',
    ];

    // 状态常量
    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    /**
     * 关联父分类
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 关联子分类
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    /**
     * 关联设备
     */
    public function devices()
    {
        return $this->hasMany(Device::class, 'category_id', 'id');
    }

    /**
     * 搜索器 - 父分类
     */
    public function searchParentIdAttr($query, $value)
    {
        $query->where('parent_id', $value);
    }

    /**
     * 搜索器 - 状态
     */
    public function searchIsEnabledAttr($query, $value)
    {
        $query->where('is_enabled', $value);
    }

    /**
     * 获取树形结构
     */
    public static function getTree(int $parentId = 0)
    {
        $categories = self::where('is_enabled', self::STATUS_ENABLED)
            ->order('sort_order', 'asc')
            ->select();
        
        return self::buildTree($categories, $parentId);
    }

    /**
     * 构建树形结构
     */
    private static function buildTree($categories, $parentId = 0)
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = self::buildTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['children'] = $children;
                }
                $tree[] = $category;
            }
        }
        return $tree;
    }
}
