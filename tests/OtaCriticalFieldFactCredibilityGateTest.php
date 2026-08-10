<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaCriticalFieldFactCredibilityGateTest extends TestCase
{
    public function testFormalEtlDefaultZeroWithoutFieldFactsOrCollectionTimeIsBlocked(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row(false, false),
        ]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $gate = $metrics['credibility_gate'];

        self::assertSame('ready', $dataset['status']);
        self::assertSame(0.0, $metrics['totals']['revenue']);
        self::assertSame('blocked', $gate['status']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertContains('ota_critical_field_facts_unverified', $gate['reason_codes']);
        self::assertContains(
            'ota_critical_collection_time_missing_or_imprecise',
            $gate['reason_codes']
        );
        self::assertSame(
            'blocked',
            $gate['evidence']['critical_field_fact_contract']['status']
        );
        self::assertFalse($metrics['p1_revenue_closure']['calculation_allowed']);
    }

    public function testExplicitZeroWithAlignedFieldFactsAndCollectionTimeRemainsValid(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row(true, true),
        ]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $gate = $metrics['credibility_gate'];

        self::assertSame('ready', $dataset['status']);
        self::assertSame(0.0, $metrics['totals']['revenue']);
        self::assertSame(0.0, $metrics['totals']['room_revenue']);
        self::assertSame(0.0, $metrics['totals']['adr']);
        self::assertSame('warning', $gate['status']);
        self::assertTrue($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame(
            'verified',
            $gate['evidence']['critical_field_fact_contract']['status']
        );
        self::assertSame(1, $gate['evidence']['critical_field_fact_contract']['verified_rows']);
    }

    public function testLegacyNonZeroFormalFactKeepsExistingSourceTraceCompatibility(): void
    {
        $row = $this->row(false, true);
        $row['amount'] = 100;
        $row['room_revenue'] = 100;
        $row['book_order_num'] = 1;
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$row]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertTrue($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('verified', $metrics['metric_trust']['totals.revenue']['truth']['status']);
        self::assertSame(
            0,
            $metrics['credibility_gate']['evidence']['critical_field_fact_contract']['checked_rows']
        );
    }

    public function testVerifiedNonZeroAggregateCannotHideAnUnverifiedZeroRow(): void
    {
        $verified = $this->row(false, false);
        $verified['id'] = 902;
        $verified['data_date'] = '2026-07-28';
        $verified['amount'] = 100;
        $verified['room_revenue'] = 100;
        $verified['book_order_num'] = 1;
        $unverifiedZero = $this->row(false, false);
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $verified,
            $unverifiedZero,
        ]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(100.0, $metrics['totals']['revenue']);
        self::assertFalse($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
        self::assertContains(
            'ota_critical_field_facts_unverified',
            $metrics['credibility_gate']['reason_codes']
        );
    }

    /** @return array<string, mixed> */
    private function row(bool $withFieldFacts, bool $withCollectionTime): array
    {
        $traceId = 'ctrip:' . str_repeat('a', 64);
        $sourceUrlHash = str_repeat('b', 64);
        $raw = [];
        if ($withFieldFacts) {
            $captureEvidence = [
                'source_trace_id' => $traceId,
                'source_url_hash' => $sourceUrlHash,
            ];
            $raw = [
                '_source_path' => 'data.business',
                'capture_evidence' => $captureEvidence,
                'field_facts' => [
                    [
                        'metric_key' => 'sales_amount',
                        'source_path' => 'data.business.salesAmount',
                        'storage_field' => 'online_daily_data.amount',
                        'stored_value_present' => true,
                        'status' => 'captured',
                        'capture_evidence' => $captureEvidence,
                    ],
                    [
                        'metric_key' => 'room_revenue',
                        'source_path' => 'data.business.roomRevenue',
                        'storage_field' => 'online_daily_data.room_revenue',
                        'stored_value_present' => true,
                        'status' => 'captured',
                        'capture_evidence' => $captureEvidence,
                    ],
                    [
                        'metric_key' => 'sales_room_nights',
                        'source_path' => 'data.business.roomNights',
                        'storage_field' => 'online_daily_data.quantity',
                        'stored_value_present' => true,
                        'status' => 'captured',
                        'capture_evidence' => $captureEvidence,
                    ],
                ],
            ];
        }

        return [
            'id' => 901,
            'system_hotel_id' => 7,
            'hotel_id' => 'ctrip-hotel-7',
            'hotel_name' => 'Hotel 7',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'amount' => 0,
            'room_revenue' => 0,
            'quantity' => 10,
            'book_order_num' => 0,
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'source_trace_id' => $traceId,
            'collected_at' => $withCollectionTime ? '2026-07-30 08:00:00' : null,
            'updated_at' => '2026-07-30 08:05:00',
            'raw_data' => json_encode(
                $raw,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }
}
