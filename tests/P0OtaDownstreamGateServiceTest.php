<?php
declare(strict_types=1);

namespace Tests;

use app\service\P0OtaDownstreamGateService;
use PHPUnit\Framework\TestCase;

final class P0OtaDownstreamGateServiceTest extends TestCase
{
    public function testBlockedGateKeepsMissingTargetDateEvidenceExplicit(): void
    {
        $gate = (new P0OtaDownstreamGateService())->blockedForDataset('2026-06-27', 7, [
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ]);

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertSame('not_verified', $gate['current_upstream_status']);
        self::assertSame('ready', $gate['required_upstream_status']);
        self::assertSame('npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27 --system-hotel-id=7', $gate['required_gate_command']);
        self::assertContains('p0_field_loop_verifier_ready', $gate['blocking_missing_inputs']);
        self::assertContains('target_date_ota_rows', $gate['blocking_missing_inputs']);
        self::assertContains('target_date_traffic_rows', $gate['blocking_missing_inputs']);
        self::assertContains('revenue_analysis', $gate['blocked_stage_keys']);
        self::assertContains('investment_judgment', $gate['blocked_stage_keys']);
        self::assertContains('no_whole_hotel_or_downstream_closure_claim', $gate['allowed_claims']);
    }

    public function testReadyGateNormalizesToNoBlockingInputs(): void
    {
        $gate = (new P0OtaDownstreamGateService())->normalize([
            'status' => 'ready',
        ], '2026-06-27', 7);

        self::assertSame('ready', $gate['status']);
        self::assertSame([], $gate['blocking_missing_inputs']);
        self::assertSame([], $gate['blocked_stage_keys']);
        self::assertSame('npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27 --system-hotel-id=7', $gate['required_gate_command']);
        self::assertContains('p0_ota_field_loop_ready_for_downstream_claims', $gate['allowed_claims']);
    }

    public function testGateCommandCanStayScopedToCtripOnly(): void
    {
        $gate = (new P0OtaDownstreamGateService())->blockedForDataset('2026-06-28', null, [
            'fact_ota_daily' => [
                ['source' => 'ctrip'],
            ],
            'fact_ota_traffic' => [
                ['source' => 'ctrip'],
            ],
        ], ['ctrip']);

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertSame(
            'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-28 --platform=ctrip',
            $gate['required_gate_command']
        );
        self::assertStringNotContainsString('meituan', $gate['required_gate_command']);
    }
}
