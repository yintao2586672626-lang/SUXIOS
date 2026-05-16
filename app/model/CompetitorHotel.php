<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class CompetitorHotel extends Model
{
    public const PLATFORM_MEITUAN = 'mt';
    public const PLATFORM_CTRIP = 'xc';
    public const PLATFORM_BOOKING = 'booking';
    public const PLATFORM_AGODA = 'agoda';
    public const PLATFORM_EXPEDIA = 'expedia';

    protected $name = 'competitor_hotel';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'store_id' => 'integer',
        'status' => 'integer',
    ];

    public static function platformOptions(): array
    {
        return [
            ['value' => self::PLATFORM_MEITUAN, 'label' => '美团', 'is_recommended' => true],
            ['value' => self::PLATFORM_CTRIP, 'label' => '携程', 'is_recommended' => true],
            ['value' => self::PLATFORM_BOOKING, 'label' => 'Booking.com', 'is_recommended' => true],
            ['value' => self::PLATFORM_AGODA, 'label' => 'Agoda', 'is_recommended' => true],
            ['value' => self::PLATFORM_EXPEDIA, 'label' => 'Expedia', 'is_recommended' => true],
        ];
    }

    public static function platformCodes(): array
    {
        return array_column(self::platformOptions(), 'value');
    }

    public static function platformLabel(string $platform): string
    {
        foreach (self::platformOptions() as $option) {
            if ($option['value'] === $platform) {
                return $option['label'];
            }
        }

        return $platform;
    }
}
