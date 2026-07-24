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
}
