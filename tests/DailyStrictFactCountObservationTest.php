<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingOpportunityLabService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyStrictFactCountObservationTest extends TestCase
{
    #[DataProvider('observations')]
    public function testNaturalReadbackRetainsImprovedUnchangedAndReducedCounts(int $before, int $after): void
    {
        $evidence = $this->readback($before, $after);
        self::assertIsArray($evidence, 'A later same-scope readback must not depend on improvement.');
        self::assertSame((float)$before, $evidence['before']['ctrip_strict_core_fact_count']);
        self::assertSame((float)$after, $evidence['after']['ctrip_strict_core_fact_count']);
        self::assertFalse($evidence['platform_response']['causality_claimed']);
        self::assertSame('observed_not_attributed', $evidence['platform_response']['effect_evidence_status']);
    }

    public static function observations(): array
    {
        return ['improved' => [4, 5], 'unchanged' => [4, 4], 'reduced' => [4, 3]];
    }

    public function testStaleMissingOrWrongScopeReceiptRemainsUnavailable(): void
    {
        self::assertNull($this->readback(4, 4, ['tenant_id' => 81]));
        self::assertNull($this->readback(4, 4, ['hotel_id' => 81]));
        self::assertNull($this->readback(4, 4, ['business_date' => '2026-08-27']));
        self::assertNull($this->readback(4, 4, [], '2026-08-27 09:00:00'));
        self::assertNull($this->readback(4, 4, [], '2026-08-27 11:00:00', []));
    }

    private function readback(int $before, int $after, array $override = [], string $collectedAt = '2026-08-27 11:00:00', array $ids = [701]): ?array
    {
        $fields = [['key' => 'collected_at', 'value' => $collectedAt]];
        foreach (array_slice(['revenue', 'order_count', 'room_nights', 'exposure', 'visits', 'conversion'], 0, $after) as $key) {
            $fields[] = ['key' => $key, 'status' => 'strict_readback',
                'identity_binding_verified' => true, 'strict_final_gate' => true];
        }
        $closure = array_merge([
            'tenant_id' => 80, 'hotel_id' => 80, 'business_date' => '2026-08-26',
            'platforms' => ['ctrip' => ['fields' => $fields, 'current_receipt_record_ids' => $ids]],
            'closure_digest' => str_repeat('a', 64),
        ], $override);
        return (new \ReflectionMethod(OperatingOpportunityLabService::class, 'strictFactCountReadbackFromClosure'))
            ->invoke(new OperatingOpportunityLabService(),
                ['id' => 601, 'executed_at' => '2026-08-27 10:00:00'],
                ['id' => 501, 'tenant_id' => 80, 'hotel_id' => 80, 'date_start' => '2026-08-26',
                    'source_record_id' => 901, 'expected_metric' => 'ctrip_strict_core_fact_count'],
                ['expected_observation_metric' => ['baseline_value' => $before]],
                $closure);
    }
}
