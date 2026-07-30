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
