<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;

final class PlatformSyncTaskStructuredStrategyTest extends TestCase
{
    public function testCompleteStructuredP0SetWinsOverDiagnosticDomDuplicate(): void
    {
        $result = $this->classify([
            $this->trafficRow('xhr:traffic:traffic', 1277, 176, 4.55),
            $this->trafficRow('dom:traffic:home_summary', 1277, 176, 4.55),
        ]);

        self::assertSame('browser_response', $result['capture_strategy']);
        self::assertSame('structured_json', $result['response_evidence_type']);
    }

    public function testDomCannotCompleteAMissingStructuredP0Metric(): void
    {
        $result = $this->classify([
            $this->trafficRow('xhr:traffic:traffic', 1277, 176, null),
            $this->trafficRow('dom:traffic:home_summary', 1277, 176, 4.55),
        ]);

        self::assertSame('dom_fallback', $result['capture_strategy']);
        self::assertSame('dom_fields', $result['response_evidence_type']);
    }

    public function testConflictingStructuredP0ValuesRemainUnverified(): void
    {
        $result = $this->classify([
            $this->trafficRow('xhr:traffic:traffic', 126, 23, 0.0, 'a'),
            $this->trafficRow('xhr:traffic:traffic', 1277, 176, 4.55, 'c'),
            $this->trafficRow('dom:traffic:home_summary', 1277, 176, 4.55, 'd'),
        ]);

        self::assertSame('dom_fallback', $result['capture_strategy']);
        self::assertSame('dom_fields', $result['response_evidence_type']);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function classify(array $rows): array
    {
        $method = new \ReflectionMethod(
            new PlatformDataSyncService(),
            'collectionStrategyEvidenceFromRunRows'
        );
        $method->setAccessible(true);

        return $method->invoke(new PlatformDataSyncService(), $rows, [
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ]);
    }

    /** @return array<string,mixed> */
    private function trafficRow(
        string $captureSource,
        int $listExposure,
        int $detailExposure,
        ?float $flowRate,
        string $traceSeed = 'a'
    ): array {
        $traceId = 'meituan:' . str_repeat($traceSeed, 64);
        $urlHash = str_repeat('b', 64);
        $sourcePath = str_starts_with($captureSource, 'dom:')
            ? 'dom.traffic.home_summary'
            : 'data.myHotel';
        $strategy = str_starts_with($captureSource, 'dom:')
            ? 'dom_fallback'
            : 'browser_response';
        $responseType = str_starts_with($captureSource, 'dom:')
            ? 'dom_fields'
            : 'structured_json';
        $raw = [
            'date_source' => 'page.traffic_period_selection.readback',
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'row' => [
                '_capture_source' => $captureSource,
                '_source_path' => $sourcePath,
                'capture_evidence' => [
                    'capture_source' => $captureSource,
                    'source_path' => $sourcePath,
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                    'capture_strategy' => $strategy,
                    'response_evidence_type' => $responseType,
                ],
            ],
            'field_facts' => [[
                'metric_key' => 'list_exposure',
                'source_path' => $sourcePath . '.exposureUV',
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => $captureSource,
                    'source_path' => $sourcePath,
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ]],
        ];

        return [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'dimension' => 'flow_conversion',
            'compare_type' => 'self',
            'data_date' => '2026-08-08',
            'data_source_id' => 68,
            'sync_task_id' => 3045,
            'readback_verified' => 1,
            'source_trace_id' => $traceId,
            'list_exposure' => $listExposure,
            'detail_exposure' => $detailExposure,
            'flow_rate' => $flowRate,
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ];
    }
}
