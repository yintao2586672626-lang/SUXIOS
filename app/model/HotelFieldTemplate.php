<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class HotelFieldTemplate extends Model
{
    protected $name = 'hotel_field_templates';
    protected $autoWriteTimestamp = true;
    
    public function items()
    {
        return $this->hasMany(HotelFieldTemplateItem::class, 'template_id');
    }
    
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id');
    }
    
    public static function getHotelTemplate(int $hotelId): ?array
    {
        $template = self::where('hotel_id', $hotelId)
            ->where('is_default', 1)
            ->with(['items'])
            ->find();
        return $template ? $template->toArray() : null;
    }
    
    public static function getHotelTemplateId(int $hotelId): ?int
    {
        $template = self::where('hotel_id', $hotelId)
            ->where('is_default', 1)
            ->find();
        return $template ? $template->id : null;
    }
}
