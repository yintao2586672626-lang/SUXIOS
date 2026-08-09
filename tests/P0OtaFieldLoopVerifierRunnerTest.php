<?php
declare(strict_types=1);

namespace Tests;

use app\service\P0OtaFieldLoopVerifierRunner;
use PHPUnit\Framework\TestCase;

final class P0OtaFieldLoopVerifierRunnerTest extends TestCase
{
    public function testMockedVerifierAndPersistedTrustMustBothMatchExactScope(): void
    {
        $argumentsSeen = [];
        $runner = new P0OtaFieldLoopVerifierRunner(
            function (array $arguments, string $cwd, int $timeout) use (&$argumentsSeen): array {
                $argumentsSeen = $arguments;
                self::assertDirectoryExists($cwd);
                self::assertSame(60, $timeout);
                return [
                    'exit_code' => 0,
                    'stdout' => json_encode($this->verifierReport(), JSON_THROW_ON_ERROR),
                    'stderr' => '',
                ];
            },
            fn(int $hotelId, string $startDate, string $endDate): array =>
                $this->continuousTrust($hotelId, $startDate, $endDate)
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['meituan', 'ctrip'],
            str_repeat('c', 64)
        );

        self::assertTrue($receipt['authority_ready']);
        self::assertSame('passed', $receipt['status']);
        self::assertSame('external_p0_verifier', $receipt['verification_source']);
        self::assertSame(['ctrip', 'meituan'], $receipt['verified_platforms']);
        self::assertSame([], $receipt['issue_codes']);
        self::assertSame(str_repeat('c', 64), $receipt['collection_anchor_hash']);
        self::assertSame(2, $receipt['schema_version']);
        self::assertSame(25, $receipt['platform_storage_scopes']['ctrip']['data_source_id']);
        self::assertSame(3001, $receipt['platform_storage_scopes']['ctrip']['sync_task_id']);
        self::assertSame([501], $receipt['platform_storage_scopes']['ctrip']['sample_row_ids']);
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertContains('--date=2026-07-23', $argumentsSeen);
        self::assertContains('--platform=ctrip,meituan', $argumentsSeen);
        self::assertContains('--system-hotel-id=58', $argumentsSeen);
    }

    public function testMockedPassedOutputCannotOpenGateForWrongHotelOrMissingRawTrust(): void
    {
        $report = $this->verifierReport();
        $report['scope']['system_hotel_id'] = 59;
        $continuous = $this->continuousTrust(58, '2026-07-23', '2026-07-23');
        $continuous['days'][0]['platforms'][1]['status'] = 'partial';
        $continuous['days'][0]['platforms'][1]['missing_steps'] = ['raw_save'];

        $runner = new P0OtaFieldLoopVerifierRunner(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ],
            static fn(): array => $continuous
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['ctrip', 'meituan'],
            str_repeat('c', 64)
        );

        self::assertFalse($receipt['authority_ready']);
        self::assertSame('incomplete', $receipt['status']);
        self::assertContains('p0_verifier_scope_mismatch', $receipt['issue_codes']);
        self::assertContains('meituan_raw_save_not_ready', $receipt['issue_codes']);
    }

    public function testExactPersistedFactAuthorityIsIndependentFromContinuousCollectionHealth(): void
    {
        $report = $this->verifierReport();
        $continuous = $this->continuousTrust(58, '2026-07-23', '2026-07-23');
        $continuous['status'] = 'partial';
        $continuous['days'][0]['status'] = 'partial';
        $continuous['days'][0]['platforms'][0]['status'] = 'partial';
        $continuous['days'][0]['platforms'][0]['missing_steps'] = ['organized_save'];

        $runner = new P0OtaFieldLoopVerifierRunner(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ],
            static fn(): array => $continuous
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['ctrip', 'meituan'],
            str_repeat('c', 64)
        );

        self::assertTrue($receipt['authority_ready']);
        self::assertSame('passed', $receipt['status']);
        self::assertFalse($receipt['continuous_trust_ready']);
        self::assertSame('partial', $receipt['continuous_trust_status']);
        self::assertContains('ctrip_organized_save_not_ready', $receipt['continuous_trust_missing_steps']);
    }

    public function testInvalidMockedVerifierOutputFailsClosedWithoutSensitiveOutput(): void
    {
        $runner = new P0OtaFieldLoopVerifierRunner(
            static fn(): array => ['exit_code' => 2, 'stdout' => 'not-json', 'stderr' => 'secret'],
            static fn(): array => []
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['ctrip', 'meituan'],
            str_repeat('c', 64)
        );

        self::assertFalse($receipt['authority_ready']);
        self::assertSame('failed', $receipt['status']);
        self::assertSame(['p0_verifier_output_invalid'], $receipt['issue_codes']);
        self::assertArrayNotHasKey('stderr', $receipt);
        self::assertArrayNotHasKey('stdout', $receipt);
    }

    public function testPassedVerifierCannotOpenGateWithoutExactStorageScope(): void
    {
        $report = $this->verifierReport();
        $report['traffic_evidence_availability'][0]['traffic_field_fact_closure']['storage_scope']['sync_task_id'] = 0;
        $runner = new P0OtaFieldLoopVerifierRunner(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ],
            fn(int $hotelId, string $startDate, string $endDate): array =>
                $this->continuousTrust($hotelId, $startDate, $endDate)
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['ctrip', 'meituan'],
            str_repeat('c', 64)
        );

        self::assertFalse($receipt['authority_ready']);
        self::assertSame('incomplete', $receipt['status']);
        self::assertContains('ctrip_p0_storage_scope_missing', $receipt['issue_codes']);
        self::assertArrayNotHasKey('ctrip', $receipt['platform_storage_scopes']);
    }

    public function testPassedVerifierCannotOpenGateWithoutObservedMetricProvenance(): void
    {
        $report = $this->verifierReport();
        unset($report['traffic_evidence_availability'][0]['traffic_field_fact_closure']['observed_traffic_metric_provenance_status']);
        $report['traffic_evidence_availability'][0]['traffic_field_fact_closure']['synthetic_normalization_provenance_missing_rows'] = 1;
        $runner = new P0OtaFieldLoopVerifierRunner(
            static fn(): array => [
                'exit_code' => 0,
                'stdout' => json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ],
            fn(int $hotelId, string $startDate, string $endDate): array =>
                $this->continuousTrust($hotelId, $startDate, $endDate)
        );

        $receipt = $runner->verify(
            58,
            '2026-07-23',
            ['ctrip', 'meituan'],
            str_repeat('c', 64)
        );

        self::assertFalse($receipt['authority_ready']);
        self::assertSame('incomplete', $receipt['status']);
        self::assertContains('ctrip_synthetic_normalization_provenance_missing', $receipt['issue_codes']);
        self::assertArrayNotHasKey('ctrip', $receipt['platform_storage_scopes']);
    }

    /** @return array<string, mixed> */
    private function verifierReport(): array
    {
        return [
            'status' => 'passed',
            'scope' => [
                'date' => '2026-07-23',
                'system_hotel_id' => 58,
                'platforms' => ['ctrip', 'meituan'],
            ],
            'platforms' => [
                ['platform' => 'ctrip', 'p0_traffic_gate' => ['status' => 'ready']],
                ['platform' => 'meituan', 'p0_traffic_gate' => ['status' => 'ready']],
            ],
            'traffic_evidence_availability' => [
                $this->storageEvidence('ctrip', 25, 3001, 501),
                $this->storageEvidence('meituan', 68, 3002, 502),
            ],
            'issues' => [],
            'summary' => [
                'p0_platforms_ready' => 2,
                'p0_platforms_incomplete' => 0,
                'traffic_gates_ready' => 2,
                'traffic_gates_incomplete' => 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function storageEvidence(
        string $platform,
        int $sourceId,
        int $taskId,
        int $rowId
    ): array {
        $metrics = ['list_exposure', 'detail_exposure', 'flow_rate'];
        return [
            'platform' => $platform,
            'system_hotel_id' => 58,
            'status' => 'ready',
            'traffic_field_fact_closure' => [
                'status' => 'ready',
                'target_date' => '2026-07-23',
                'system_hotel_id' => 58,
                'storage_scope' => [
                    'status' => 'ready',
                    'tenant_id' => 7,
                    'data_source_id' => $sourceId,
                    'sync_task_id' => $taskId,
                    'system_hotel_id' => 58,
                    'platform' => $platform,
                    'selection_basis' => 'target_date_readback_traffic_rows',
                ],
                'authoritative_traffic_row_count' => 1,
                'readback_status' => 'ready',
                'required_metric_keys' => $metrics,
                'complete_metric_keys' => $metrics,
                'missing_metric_keys' => [],
                'nonzero_required_metric_rows' => 1,
                'explicit_zero_confirmed_rows' => 0,
                'observed_traffic_metric_provenance_status' => 'ready',
                'synthetic_normalization_provenance_missing_rows' => 0,
                'sample_metric_rows' => [['row_id' => $rowId]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function continuousTrust(int $hotelId, string $startDate, string $endDate): array
    {
        return [
            'status' => 'verified',
            'metric_scope' => 'ota_channel',
            'tenant_scope_status' => 'verified',
            'hotel_id' => $hotelId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => [[
                'date' => $startDate,
                'status' => 'verified',
                'platforms' => [
                    ['platform' => 'ctrip', 'status' => 'verified', 'missing_steps' => []],
                    ['platform' => 'meituan', 'status' => 'verified', 'missing_steps' => []],
                ],
            ]],
        ];
    }
}
