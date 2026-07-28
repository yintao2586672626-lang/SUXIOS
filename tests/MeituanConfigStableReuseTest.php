<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MeituanConfigStableReuseTest extends TestCase
{
    private function harness(): object
    {
        return new class {
            use \app\controller\concern\OtaConfigConcern;
            use \app\controller\concern\MeituanConfigConcern;

            public function stableValue(array $request, array $original, array $aliases): mixed
            {
                return $this->resolveMeituanStableConfigInput($request, $original, $aliases);
            }

            public function existingForHotel(array $list, int $hotelId): array
            {
                return $this->selectExistingMeituanConfigForHotel($list, $hotelId);
            }
        };
    }

    public function testBlankRefreshFieldsReuseStoredValuesWhileExplicitValuesWin(): void
    {
        $harness = $this->harness();
        self::assertSame(
            'stored-partner',
            $harness->stableValue(
                ['partner_id' => '  '],
                ['partner_id' => 'stored-partner'],
                ['partner_id', 'partnerId']
            )
        );
        self::assertSame(
            37,
            $harness->stableValue(
                ['hotel_room_count' => ''],
                ['hotel_room_count' => 37],
                ['hotel_room_count', 'hotelRoomCount']
            )
        );
        self::assertSame(
            'new-poi',
            $harness->stableValue(
                ['poi_id' => 'new-poi'],
                ['poi_id' => 'stored-poi'],
                ['poi_id', 'poiId']
            )
        );
    }

    public function testExistingHotelConfigIsSelectedWithoutCrossStoreFallback(): void
    {
        $selected = $this->harness()->existingForHotel([
            'ready-58' => [
                'id' => 'ready-58',
                'config_id' => 'ready-58',
                'hotel_id' => 58,
                'config_status' => 'active',
                'credential_status' => 'ready',
                'has_cookies' => true,
                'update_time' => '2026-07-27 09:00:00',
            ],
            'newer-unready-58' => [
                'id' => 'newer-unready-58',
                'config_id' => 'newer-unready-58',
                'hotel_id' => 58,
                'config_status' => 'active',
                'credential_status' => 'missing',
                'has_cookies' => false,
                'update_time' => '2026-07-28 09:00:00',
            ],
            'ready-59' => [
                'id' => 'ready-59',
                'config_id' => 'ready-59',
                'hotel_id' => 59,
                'config_status' => 'active',
                'credential_status' => 'ready',
                'has_cookies' => true,
                'update_time' => '2026-07-28 10:00:00',
            ],
        ], 58);

        self::assertSame('ready-58', $selected['config_id'] ?? null);
    }

    public function testSavePathReusesExistingHotelConfigBeforeGeneratingAnId(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/MeituanConfigConcern.php'
        );
        self::assertStringContainsString(
            'selectExistingMeituanConfigForHotel($list, $systemHotelId)',
            $source
        );
        self::assertStringContainsString('$id = $primaryConfigId;', $source);
        self::assertStringContainsString(
            'resolveMeituanStableConfigInput(',
            $source
        );
    }
}
