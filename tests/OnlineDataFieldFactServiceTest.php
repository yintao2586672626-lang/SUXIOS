<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataFieldFactService;
use PHPUnit\Framework\TestCase;

final class OnlineDataFieldFactServiceTest extends TestCase
{
    public function testOrderIdRoomCountAndNightsDoNotPretendPlatformReturnedCounts(): void
    {
        $row = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'data_type' => 'order',
            'amount' => 500.0,
            'quantity' => null,
            'book_order_num' => null,
            'data_value' => null,
            'raw_data' => '{}',
        ], [
            'order_id' => 'ORDER-1',
            'total_amount' => 500,
            'room_count' => 2,
            'nights' => 3,
        ]);

        $raw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($raw);
        $metricKeys = array_column($raw['field_facts'] ?? [], 'metric_key');

        self::assertContains('order_amount', $metricKeys);
        self::assertNotContains('room_nights', $metricKeys);
        self::assertNotContains('order_count', $metricKeys);
    }

    public function testExplicitZeroOrderMetricsRemainCapturedFacts(): void
    {
        $row = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'data_type' => 'order',
            'quantity' => 0,
            'book_order_num' => 0,
            'raw_data' => '{}',
        ], [
            'room_nights' => 0,
            'order_count' => 0,
        ]);

        $raw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($raw);
        $metricKeys = array_column($raw['field_facts'] ?? [], 'metric_key');

        self::assertContains('room_nights', $metricKeys);
        self::assertContains('order_count', $metricKeys);
    }

    public function testExplicitZeroExposureToBrowseRateRemainsAStoredPlatformFact(): void
    {
        $row = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'data_type' => 'traffic',
            'raw_data' => json_encode([
                '_source_path' => 'data.myHotel',
                'exposure_to_browse_rate' => 0,
            ], JSON_UNESCAPED_UNICODE),
        ], [
            '_source_path' => 'data.myHotel',
            'intentionPerExposure' => '0%',
            'exposure_to_browse_rate' => 0,
        ]);

        $raw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($raw);
        $facts = array_column($raw['field_facts'] ?? [], null, 'metric_key');

        self::assertSame(
            'data.myHotel.intentionPerExposure',
            $facts['exposure_to_browse_rate']['source_path'] ?? null
        );
        self::assertTrue(
            $facts['exposure_to_browse_rate']['stored_value_present'] ?? false
        );
    }

    public function testFieldFactStatusRequiresDesensitizedCaptureEvidence(): void
    {
        $row = [
            'data_type' => 'traffic',
            'list_exposure' => 100,
            'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
            'source_url_hash' => str_repeat('b', 64),
        ];
        $fact = [
            'metric_key' => 'list_exposure',
            'source_path' => 'data.list_exposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'stored_value_present' => true,
            'status' => 'captured',
            'capture_evidence' => [
                'source_trace_id' => $row['source_trace_id'],
            ],
        ];

        $weak = OnlineDataFieldFactService::buildStatus($row, ['field_facts' => [$fact]]);
        self::assertSame('partial', $weak['status']);
        self::assertSame(0, $weak['desensitized_capture_evidence_count']);

        $fact['capture_evidence']['source_url_hash'] = str_repeat('b', 64);
        $complete = OnlineDataFieldFactService::buildStatus($row, ['field_facts' => [$fact]]);
        self::assertSame('ready', $complete['status']);
        self::assertSame(1, $complete['desensitized_capture_evidence_count']);

        $fact['capture_evidence']['source_trace_id'] = 'ctrip:' . str_repeat('c', 64);
        $mismatched = OnlineDataFieldFactService::buildStatus($row, ['field_facts' => [$fact]]);
        self::assertSame('partial', $mismatched['status']);
        self::assertSame(0, $mismatched['matching_desensitized_capture_evidence_count']);
    }

    public function testFieldFactStatusRejectsMalformedAndPerFactMismatchedEvidence(): void
    {
        $traceId = 'ctrip:' . str_repeat('a', 64);
        $sourceUrlHash = str_repeat('b', 64);
        $row = [
            'data_type' => 'traffic',
            'list_exposure' => 100,
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
        ];
        $fact = [
            'metric_key' => 'list_exposure',
            'source_path' => 'data.list_exposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'stored_value_present' => true,
            'status' => 'captured',
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $sourceUrlHash,
            ],
        ];

        $malformedFact = $fact;
        $malformedFact['capture_evidence']['source_url_hash'] = 'x';
        $malformed = OnlineDataFieldFactService::buildStatus($row, ['field_facts' => [$malformedFact]]);
        self::assertSame('partial', $malformed['status']);
        self::assertSame(0, $malformed['desensitized_capture_evidence_count']);

        $mismatchedFact = $fact;
        $mismatchedFact['capture_evidence']['source_trace_id'] = 'ctrip:' . str_repeat('c', 64);
        $duplicateMetric = OnlineDataFieldFactService::buildStatus($row, [
            'field_facts' => [$fact, $mismatchedFact],
        ]);
        self::assertSame('partial', $duplicateMetric['status']);
        self::assertSame(1, $duplicateMetric['captured_count']);
        self::assertSame(1, $duplicateMetric['missing_count']);
        self::assertSame(1, $duplicateMetric['matching_desensitized_capture_evidence_count']);
    }

    public function testMetricScopedStatusKeepsUnrelatedFieldGapsOutOfRequestedMetricTrust(): void
    {
        $traceId = 'ctrip:' . str_repeat('a', 64);
        $sourceUrlHash = str_repeat('b', 64);
        $row = [
            'data_type' => 'business',
            'amount' => 2168.0,
            'quantity' => 3,
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
        ];
        $capturedAmount = [
            'metric_key' => 'order_amount',
            'source_path' => 'data.amount',
            'storage_field' => 'online_daily_data.amount',
            'stored_value_present' => true,
            'status' => 'captured',
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $sourceUrlHash,
            ],
        ];
        $unrelatedMissing = [
            'metric_key' => 'comment_score',
            'source_path' => 'data.commentScore',
            'storage_field' => 'online_daily_data.comment_score',
            'status' => 'missing',
        ];
        $raw = ['field_facts' => [$capturedAmount, $unrelatedMissing]];

        $wholeRow = OnlineDataFieldFactService::buildStatus($row, $raw);
        $amountOnly = OnlineDataFieldFactService::buildMetricStatus($row, $raw, ['order_amount']);
        $amountAndNights = OnlineDataFieldFactService::buildMetricStatus(
            $row,
            $raw,
            ['order_amount', 'room_nights']
        );

        self::assertSame('partial', $wholeRow['status']);
        self::assertSame('ready', $amountOnly['status']);
        self::assertSame([], $amountOnly['missing_requested_metric_keys']);
        self::assertSame('partial', $amountAndNights['status']);
        self::assertSame(['room_nights'], $amountAndNights['missing_requested_metric_keys']);
    }

    public function testChannelSpecificMetricAliasesStayInsideTheirOwnPlatform(): void
    {
        $source = [
            '_source_path' => 'data',
            'listExposure' => 58,
            'intentionUV' => 4,
            'payOrderCnt' => 1,
        ];
        $ctrip = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'list_exposure' => 58,
            'detail_exposure' => 4,
            'order_submit_num' => 1,
            'raw_data' => '{}',
        ], $source);
        $meituan = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'list_exposure' => 58,
            'detail_exposure' => 4,
            'order_submit_num' => 1,
            'raw_data' => '{}',
        ], $source);

        $ctripKeys = array_column(
            json_decode((string)$ctrip['raw_data'], true)['field_facts'] ?? [],
            'metric_key'
        );
        $meituanKeys = array_column(
            json_decode((string)$meituan['raw_data'], true)['field_facts'] ?? [],
            'metric_key'
        );
        self::assertContains('list_exposure', $ctripKeys);
        self::assertNotContains('mt_exposure', $ctripKeys);
        self::assertNotContains('mt_intention_uv', $ctripKeys);
        self::assertNotContains('mt_pay_orders', $ctripKeys);
        self::assertContains('mt_exposure', $meituanKeys);
        self::assertContains('mt_intention_uv', $meituanKeys);
        self::assertContains('mt_pay_orders', $meituanKeys);
    }

    public function testBusinessCommissionRateNeverUsesTrafficFlowRateStorage(): void
    {
        $traceId = 'ctrip:' . str_repeat('a', 64);
        $sourceUrlHash = str_repeat('b', 64);
        $row = [
            'platform' => 'ctrip',
            'data_type' => 'business',
            'flow_rate' => 18.5,
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
        ];
        $raw = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
            'field_facts' => [[
                'metric_key' => 'business_commission_rate',
                'source_path' => 'data.bpi.businessCommissionRate',
                'value' => 12.0,
                'status' => 'captured',
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                ],
            ]],
        ];

        $status = OnlineDataFieldFactService::buildStatus($row, $raw);
        $sample = $status['sample_facts'][0] ?? [];

        self::assertSame('ready', $status['status']);
        self::assertSame(
            'online_daily_data.raw_data.facts.metric_key=business_commission_rate',
            $sample['storage_field'] ?? null
        );
        self::assertNotSame('online_daily_data.flow_rate', $sample['storage_field'] ?? null);
        self::assertSame('raw_data_facts', $sample['storage_field_source'] ?? null);
    }
}
