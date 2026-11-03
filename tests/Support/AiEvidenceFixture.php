<?php
declare(strict_types=1);
namespace Tests\Support;

final class AiEvidenceFixture
{
    public static function closure(string $date = '2026-09-08', bool $complete = true): array
    {
        $closure = ['contract_version' => 'ai_daily_report_broadcast_strict_facts.v2',
            'tenant_id' => 9004, 'hotel_id' => 904, 'business_date' => $date, 'dataset_kind' => 'synthetic', 'platforms' => []];
        foreach (['ctrip', 'meituan'] as $platform) {
            $ref = 'online_daily_data#' . ($platform === 'ctrip' ? '90401' : '90402');
            $fields = [];
            $values = ['revenue' => [200, 'CNY'], 'order_count' => [2, 'orders'], 'room_nights' => [2, 'room_nights'],
                'adr' => [100, 'CNY'], 'exposure' => [100, 'people'], 'visits' => [20, 'people'], 'conversion' => [20, 'percent'],
                'cancellation' => [0, 'percent'], 'sellable' => [10, 'rooms'], 'bookable' => [8, 'rooms']];
            foreach ($values as $key => [$value, $unit]) {
                if (!$complete && $key !== 'exposure') continue;
                $fields[$key] = ['metric_key' => $key, 'status' => 'available', 'value' => $value, 'unit' => $unit,
                    'source_record_refs' => [$ref], 'revenue_analysis_consumable' => true, 'source_method' => 'synthetic'];
            }
            $closure['platforms'][$platform] = ['platform' => $platform, 'accepted_record_refs' => [$ref], 'fields' => $fields];
        }
        return $closure;
    }
}
