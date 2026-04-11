<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class HotelFieldTemplateItem extends Model
{
    protected $name = 'hotel_field_template_items';
    protected $autoWriteTimestamp = false;
    
    public static function getItemsByTemplate(int $templateId): array
    {
        return self::where('template_id', $templateId)
            ->where('is_active', 1)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
    }
}
