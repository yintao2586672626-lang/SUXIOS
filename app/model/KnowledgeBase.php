<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 知识库模型
 * 用于智能员工Agent的问答知识库
 */
class KnowledgeBase extends Model
{
    protected $name = 'knowledge_base';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'hotel_id' => 'integer',
        'category_id' => 'integer',
        'is_enabled' => 'integer',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'tags' => 'json',
    ];
    
    protected $json = ['tags'];
    protected $jsonAssoc = true;

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
     * 关联分类
     */
    public function category()
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id', 'id');
    }

    /**
     * 搜索器 - 酒店
     */
    public function searchHotelIdAttr($query, $value)
    {
        $query->where('hotel_id', $value);
    }

    /**
     * 搜索器 - 分类
     */
    public function searchCategoryIdAttr($query, $value)
    {
        $query->where('category_id', $value);
    }

    /**
     * 搜索器 - 关键词搜索（标题和内容）
     */
    public function searchKeywordAttr($query, $value)
    {
        $query->where(function($q) use ($value) {
            $q->whereLike('title', '%' . $value . '%')
              ->whereOrLike('content', '%' . $value . '%')
              ->whereOrLike('keywords', '%' . $value . '%');
        });
    }

    /**
     * 搜索器 - 状态
     */
    public function searchIsEnabledAttr($query, $value)
    {
        $query->where('is_enabled', $value);
    }

    /**
     * 增加浏览次数
     */
    public function incrementViewCount(): void
    {
        $this->view_count++;
        $this->save();
    }

    /**
     * 增加点赞次数
     */
    public function incrementLikeCount(): void
    {
        $this->like_count++;
        $this->save();
    }

    /**
     * 获取热门知识（按浏览量排序）
     */
    public static function getHotKnowledge(int $hotelId, int $limit = 10)
    {
        return self::where('hotel_id', $hotelId)
            ->where('is_enabled', self::STATUS_ENABLED)
            ->order('view_count', 'desc')
            ->limit($limit)
            ->select();
    }

    /**
     * 搜索知识
     */
    public static function searchKnowledge(int $hotelId, string $keyword, int $categoryId = 0, int $limit = 10)
    {
        $query = self::where('hotel_id', $hotelId)
            ->where('is_enabled', self::STATUS_ENABLED)
            ->where(function($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')
                  ->whereOrLike('content', '%' . $keyword . '%')
                  ->whereOrLike('keywords', '%' . $keyword . '%');
            });
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        return $query->order('view_count', 'desc')
            ->limit($limit)
            ->select();
    }
}
