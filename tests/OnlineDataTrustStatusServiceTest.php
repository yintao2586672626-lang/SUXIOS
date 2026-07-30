<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataTrustStatusService;
use PHPUnit\Framework\TestCase;

final class OnlineDataTrustStatusServiceTest extends TestCase
{
    public function testVerifiedEnvelopeRequiresIdentityCollectionTimeReadbackAndFieldEvidence(): void
    {
        $truth = OnlineDataTrustStatusService::truthEnvelope(
            $this->readyRow(),
            $this->readyFieldFacts()
        );

        self::assertSame('verified', $truth['status']);
        self::assertSame('已验证', $truth['status_label']);
        self::assertSame('ota_channel', $truth['metric_scope']);
        self::assertSame(7, $truth['hotel']['system_hotel_id']);
        self::assertSame('ctrip', $truth['platform']);
        self::assertSame('2026-07-18', $truth['data_date']);
        self::assertSame('browser_profile', $truth['source']['method']);
        self::assertSame('2026-07-19 08:30:00', $truth['collected_at']);
        self::assertTrue($truth['persistence']['stored']);
        self::assertTrue($truth['persistence']['readback_verified']);
        self::assertSame('', $truth['failure_reason']);
    }

    public function testStoredRowWithMissingCollectionTimeIsPartialInsteadOfVerified(): void
    {
        $row = $this->readyRow();
        unset($row['snapshot_time']);

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('partial', $truth['status']);
        self::assertSame('部分数据', $truth['status_label']);
        self::assertContains('collected_at_missing', $truth['evidence_gap_codes']);
        self::assertStringContainsString('采集时间未记录', $truth['failure_reason']);
        self::assertSame('2026-07-19 08:31:00', $truth['persistence']['stored_at']);
    }

    public function testManualImportRemainsUnverifiedEvenAfterDatabaseReadback(): void
    {
        $row = $this->readyRow();
        $row['ingestion_method'] = 'manual_import';

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('unverified', $truth['status']);
        self::assertSame('未验证', $truth['status_label']);
        self::assertContains('source_method_unverified', $truth['evidence_gap_codes']);
        self::assertStringContainsString('人工或导入来源尚未核验', $truth['failure_reason']);
    }

    public function testExplicitPartialValidationCanNeverBePromotedToVerified(): void
    {
        $row = $this->readyRow();
        $row['validation_status'] = 'partial';

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('partial', $truth['status']);
        self::assertContains('record_explicitly_partial', $truth['evidence_gap_codes']);
        self::assertStringContainsString('明确标记为部分数据', $truth['failure_reason']);
    }

    public function testExplicitStaleValidationCanNeverBePromotedToVerified(): void
    {
        $row = $this->readyRow();
        $row['validation_status'] = 'stale';

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('unverified', $truth['status']);
        self::assertContains('record_explicitly_unverified', $truth['evidence_gap_codes']);
        self::assertStringContainsString('明确标记为未验证', $truth['failure_reason']);
    }

    public function testValidationFailureExposesSafeFailureReasonWithoutRawPayload(): void
    {
        $row = $this->readyRow();
        $row['validation_status'] = 'abnormal';
        $row['validation_flags'] = json_encode([
            ['level' => 'error', 'field' => 'amount', 'message' => 'token=must-not-leak'],
        ], JSON_UNESCAPED_UNICODE);

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('collection_failed', $truth['status']);
        self::assertSame('采集失败', $truth['status_label']);
        self::assertSame('字段 amount 校验失败', $truth['failure_reason']);
        self::assertStringNotContainsString('must-not-leak', json_encode($truth, JSON_UNESCAPED_UNICODE));
    }

    public function testEmptyRawErrorFieldsDoNotTurnVerifiedDataIntoCollectionFailure(): void
    {
        $row = $this->readyRow();
        $row['raw_data'] = json_encode(['error' => null, 'errors' => []], JSON_UNESCAPED_UNICODE);

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('verified', $truth['status']);
        self::assertSame('', $truth['failure_reason']);
    }

    public function testRawFailureAndArbitraryStatusNeverExposeExternalErrorText(): void
    {
        $row = $this->readyRow();
        $row['status'] = 'failed';
        $row['validation_status'] = 'token=must-not-leak';
        $row['raw_data'] = json_encode(['error' => 'authorization=must-not-leak'], JSON_UNESCAPED_UNICODE);

        $truth = OnlineDataTrustStatusService::truthEnvelope($row, $this->readyFieldFacts());

        self::assertSame('collection_failed', $truth['status']);
        self::assertSame('平台返回包含错误状态，未形成可信字段事实', $truth['failure_reason']);
        self::assertStringNotContainsString('must-not-leak', json_encode($truth, JSON_UNESCAPED_UNICODE));
    }

    public function testSummaryKeepsFourStateCountsAndOtaScope(): void
    {
        $verified = OnlineDataTrustStatusService::truthEnvelope($this->readyRow(), $this->readyFieldFacts());
        $partialRow = $this->readyRow();
        $partialRow['id'] = 2;
        $partialRow['source'] = 'meituan';
        $partialRow['data_date'] = '2026-07-17';
        unset($partialRow['snapshot_time']);
        $partial = OnlineDataTrustStatusService::truthEnvelope($partialRow, $this->readyFieldFacts());

        $summary = OnlineDataTrustStatusService::summarizeTruthEnvelopes([$verified, $partial], [
            'excluded_untrusted_count' => 3,
        ]);

        self::assertSame('partial', $summary['status']);
        self::assertSame('部分数据', $summary['status_label']);
        self::assertSame('ota_channel', $summary['metric_scope']);
        self::assertSame(1, $summary['status_counts']['verified']);
        self::assertSame(1, $summary['status_counts']['partial']);
        self::assertSame(['ctrip', 'meituan'], $summary['platforms']);
        self::assertSame('2026-07-17', $summary['date_range']['start']);
        self::assertSame('2026-07-18', $summary['date_range']['end']);
        self::assertSame(2, $summary['persistence']['readback_verified_count']);
        self::assertSame(3, $summary['persistence']['excluded_untrusted_count']);
        self::assertStringContainsString('采集时间未记录', $summary['failure_reason']);
    }

    public function testEmptySummaryIsUnverifiedAndKeepsExplicitReason(): void
    {
        $summary = OnlineDataTrustStatusService::summarizeTruthEnvelopes([], [
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-19',
            'fallback_failure_reason' => '当前筛选范围没有可核验的 OTA 入库数字',
        ]);

        self::assertSame('unverified', $summary['status']);
        self::assertSame('未验证', $summary['status_label']);
        self::assertSame('2026-07-01', $summary['date_range']['start']);
        self::assertSame('2026-07-19', $summary['date_range']['end']);
        self::assertSame('当前筛选范围没有可核验的 OTA 入库数字', $summary['failure_reason']);
    }

    public function testExcludedUntrustedRowsDowngradeOtherwiseVerifiedSummaryToPartial(): void
    {
        $verified = OnlineDataTrustStatusService::truthEnvelope($this->readyRow(), $this->readyFieldFacts());
        $summary = OnlineDataTrustStatusService::summarizeTruthEnvelopes([$verified], [
            'excluded_untrusted_count' => 2,
            'fallback_failure_reason' => '有未可信记录已从汇总数字中排除',
        ]);

        self::assertSame('partial', $summary['status']);
        self::assertSame('部分数据', $summary['status_label']);
        self::assertStringContainsString('有未可信记录已从汇总数字中排除', $summary['failure_reason']);
    }

    public function testDerivedMetricTruthRequiresFullSourceAndReadbackEvidence(): void
    {
        $truth = OnlineDataTrustStatusService::metricTruthEnvelope([
            'source' => [
                'table' => 'online_daily_data',
                'row_ids' => [11],
                'trace_ids' => ['trace-11'],
                'hotels' => [['system_hotel_id' => 7, 'name' => '测试酒店']],
                'platforms' => ['ctrip'],
                'data_types' => ['business'],
                'source_methods' => ['browser_profile'],
                'date_range' => ['start' => '2026-07-18', 'end' => '2026-07-18'],
                'collected_at_range' => ['start' => '2026-07-19 08:30:00', 'end' => '2026-07-19 08:30:00'],
                'row_count' => 1,
                'stored_count' => 1,
                'readback_verified_count' => 1,
            ],
            'caliber' => 'sum(fact_ota_daily.room_revenue)',
            'saved_success' => true,
            'failure_reasons' => [],
        ]);

        self::assertSame('verified', $truth['status']);
        self::assertSame('已验证', $truth['status_label']);
        self::assertSame('OTA渠道指标，不代表全酒店经营', $truth['scope_label']);
        self::assertSame(7, $truth['hotels'][0]['system_hotel_id']);
        self::assertSame(['ctrip'], $truth['platforms']);
        self::assertSame('2026-07-18', $truth['date_range']['start']);
        self::assertSame('browser_profile', $truth['source']['methods'][0]);
        self::assertSame('2026-07-19 08:30:00', $truth['collected_at_range']['start']);
        self::assertTrue($truth['persistence']['stored']);
        self::assertTrue($truth['persistence']['readback_verified']);
        self::assertSame('', $truth['failure_reason']);
    }

    public function testDerivedMetricWithReadbackButMissingCollectionTimeIsPartial(): void
    {
        $truth = OnlineDataTrustStatusService::metricTruthEnvelope([
            'source' => [
                'table' => 'online_daily_data',
                'row_ids' => [12],
                'trace_ids' => ['trace-12'],
                'hotels' => [['system_hotel_id' => 7, 'name' => '测试酒店']],
                'platforms' => ['meituan'],
                'source_methods' => ['browser_profile'],
                'date_range' => ['start' => '2026-07-18', 'end' => '2026-07-18'],
                'row_count' => 1,
                'stored_count' => 1,
                'readback_verified_count' => 1,
            ],
            'caliber' => 'sum(fact_ota_daily.room_nights)',
            'saved_success' => true,
            'failure_reasons' => [],
        ]);

        self::assertSame('partial', $truth['status']);
        self::assertSame('部分数据', $truth['status_label']);
        self::assertContains('collected_at_missing', $truth['evidence_gap_codes']);
        self::assertStringContainsString('collected_at_missing', $truth['failure_reason']);
    }

    public function testDerivedMetricPreservesExplicitCollectionFailure(): void
    {
        $truth = OnlineDataTrustStatusService::metricTruthEnvelope([
            'source' => ['table' => 'online_daily_data'],
            'saved_success' => false,
            'failure_reasons' => ['capture_failed'],
        ]);

        self::assertSame('collection_failed', $truth['status']);
        self::assertSame('采集失败', $truth['status_label']);
        self::assertStringContainsString('capture_failed', $truth['failure_reason']);
    }

    /** @return array<string, mixed> */
    private function readyRow(): array
    {
        return [
            'id' => 1,
            'system_hotel_id' => 7,
            'hotel_id' => 'ctrip-7001',
            'hotel_name' => '测试酒店',
            'source' => 'ctrip',
            'data_date' => '2026-07-18',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-safe-1',
            'snapshot_time' => '2026-07-19 08:30:00',
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'create_time' => '2026-07-19 08:30:30',
            'update_time' => '2026-07-19 08:31:00',
        ];
    }

    /** @return array<string, mixed> */
    private function readyFieldFacts(): array
    {
        return [
            'status' => 'ready',
            'captured_count' => 2,
            'missing_count' => 0,
            'desensitized_capture_evidence_count' => 2,
        ];
    }
}
