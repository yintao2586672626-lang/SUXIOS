<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaStructuredCaptureEvidenceService;
use PHPUnit\Framework\TestCase;

final class OtaStructuredCaptureEvidenceServiceTest extends TestCase
{
    public function testCurrentStructuredCtripAndMeituanRowsUseOnePolicy(): void
    {
        $service = new OtaStructuredCaptureEvidenceService();

        foreach (['ctrip', 'meituan'] as $platform) {
            $row = $this->structuredRow($platform);
            $raw = json_decode((string)$row['raw_data'], true);
            $raw['row']['capture_evidence']['capture_strategy']
                = 'browser_response';
            $raw['row']['capture_evidence']['response_evidence_type']
                = 'structured_json';
            $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);

            $result = $service->classifyRow($row);

            self::assertTrue($result['allowed'], $platform);
            self::assertSame(
                OtaStructuredCaptureEvidenceService::STATUS_STRUCTURED,
                $result['status']
            );
            self::assertSame('structured_json', $result['response_evidence_type']);
        }
    }

    public function testPersistedLegacyXhrRowIsRecognizedWithoutRecollection(): void
    {
        $result = (new OtaStructuredCaptureEvidenceService())
            ->classifyRow($this->structuredRow('ctrip'));

        self::assertTrue($result['allowed']);
        self::assertSame(
            OtaStructuredCaptureEvidenceService::STATUS_LEGACY_STRUCTURED,
            $result['status']
        );
        self::assertSame('browser_response', $result['capture_strategy']);
    }

    public function testDomAndTraceOnlyRowsRemainBlocked(): void
    {
        $service = new OtaStructuredCaptureEvidenceService();
        $dom = $this->structuredRow('meituan');
        $raw = json_decode((string)$dom['raw_data'], true);
        $raw['row']['_capture_source'] = 'dom:traffic:home_summary';
        $raw['row']['_source_path'] = 'dom.traffic.home_summary';
        $raw['row']['capture_evidence']['capture_source']
            = 'dom:traffic:home_summary';
        $raw['row']['capture_evidence']['source_path']
            = 'dom.traffic.home_summary';
        $dom['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);

        $domResult = $service->classifyRow($dom);
        self::assertFalse($domResult['allowed']);
        self::assertSame(
            OtaStructuredCaptureEvidenceService::STATUS_DOM,
            $domResult['status']
        );

        $traceOnly = $this->structuredRow('ctrip');
        $raw = json_decode((string)$traceOnly['raw_data'], true);
        unset($raw['row']);
        $traceOnly['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
        $traceOnlyResult = $service->classifyRow($traceOnly);

        self::assertFalse($traceOnlyResult['allowed']);
        self::assertContains(
            'structured_capture_source_missing',
            $traceOnlyResult['reason_codes']
        );
    }

    public function testFieldFactTraceOrUrlHashMismatchBlocksTheRow(): void
    {
        $row = $this->structuredRow('ctrip');
        $raw = json_decode((string)$row['raw_data'], true);
        $raw['field_facts'][0]['capture_evidence']['source_url_hash']
            = str_repeat('f', 64);
        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);

        $result = (new OtaStructuredCaptureEvidenceService())
            ->classifyRow($row);

        self::assertFalse($result['allowed']);
        self::assertContains(
            'field_fact_url_hash_mismatch',
            $result['reason_codes']
        );
    }

    public function testNormalizedEnvelopeMayKeepADistinctConsistentUpstreamTrace(): void
    {
        $row = $this->structuredRow('meituan');
        $raw = json_decode((string)$row['raw_data'], true);
        $raw['row']['source_trace_id'] = 'upstream:response:1';
        $raw['row']['capture_evidence']['source_trace_id']
            = 'upstream:response:1';
        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);

        $verified = (new OtaStructuredCaptureEvidenceService())
            ->classifyRow($row);
        self::assertTrue($verified['allowed']);

        $raw['row']['capture_evidence']['source_trace_id']
            = 'upstream:response:2';
        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
        $mismatched = (new OtaStructuredCaptureEvidenceService())
            ->classifyRow($row);
        self::assertFalse($mismatched['allowed']);
        self::assertContains(
            'upstream_source_trace_mismatch',
            $mismatched['reason_codes']
        );
    }

    /** @return array<string,mixed> */
    private function structuredRow(string $platform): array
    {
        $traceId = $platform . ':' . str_repeat('a', 64);
        $urlHash = str_repeat('b', 64);
        $captureSource = $platform === 'ctrip'
            ? 'xhr:traffic'
            : 'xhr:traffic:business_data';
        $sourcePath = $platform === 'ctrip' ? '$.data' : 'data';
        return [
            'system_hotel_id' => 80,
            'hotel_id' => $platform === 'ctrip'
                ? '130079194'
                : '1029642156589279',
            'platform' => $platform,
            'source' => $platform,
            'data_date' => '2026-07-29',
            'data_source_id' => 25,
            'sync_task_id' => 2125,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $traceId,
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
                'row' => [
                    'endpoint_id' => $platform === 'ctrip'
                        ? 'traffic_hotel_seq'
                        : null,
                    '_capture_source' => $captureSource,
                    '_source_path' => $sourcePath,
                    'capture_evidence' => [
                        'capture_source' => $captureSource,
                        'source_path' => $sourcePath,
                        'source_trace_id' => $traceId,
                        'source_url_hash' => $urlHash,
                    ],
                ],
                'field_facts' => [[
                    'metric_key' => 'order_amount',
                    'normalized_field' => 'amount',
                    'storage_field' => 'online_daily_data.amount',
                    'source_path' => $sourcePath . '.amount',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'capture_source' => $captureSource,
                        'source_path' => $sourcePath,
                        'source_trace_id' => $traceId,
                        'source_url_hash' => $urlHash,
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
