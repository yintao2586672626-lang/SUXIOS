<?php
declare(strict_types=1);

namespace Tests\fixtures;

final class RevenueForecastReplayFixture
{
    public static function scope(): array
    {
        return ['tenant_id' => 9001, 'hotel_id' => 90001, 'platform' => 'ctrip', 'platform_store_id' => 'synthetic-store', 'room_scope' => 'synthetic-room'];
    }

    public static function input(): array
    {
        $observations = [];
        for ($i = 0; $i < 243; $i++) {
            $date = (new \DateTimeImmutable('2026-01-01'))->modify("+{$i} days");
            $observations[] = self::scope() + ['business_date' => $date->format('Y-m-d'),
                'available_at' => $date->modify('+1 day')->format('Y-m-d') . 'T06:00:00+08:00',
                'quality_status' => 'ready', 'value' => 20 + ($i % 7) * 2, 'source_ref' => 'synthetic-day-' . $i];
        }
        return ['evidence' => ['schema_version' => 'temporal_replay.v1', 'source_kind' => 'synthetic',
            'metric_definition' => 'net_stay_room_nights_excluding_cancelled', 'date_basis' => 'stay_date', 'unit' => 'room_nights',
            'as_of_at' => '2026-09-01T08:00:00+08:00', 'evaluation_at' => '2026-09-01T08:00:00+08:00',
            'backtest_start' => '2026-03-01', 'backtest_end' => '2026-08-31', 'observations' => $observations],
            'scenario' => ['horizon_days' => 7, 'current_price' => 200, 'proposed_price' => 220,
                'elasticity' => -1, 'inventory_room_nights' => 210, 'inventory_scope' => 'synthetic-room', 'price_unit' => 'CNY_per_room_night']];
    }
}
