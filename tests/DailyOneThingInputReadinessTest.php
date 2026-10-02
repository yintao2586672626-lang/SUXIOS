<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingInputService;
use app\service\DailyOneThingService;
use app\service\DualOtaFieldClosureService;
use app\service\OtaReputationDailySignalService;
use PHPUnit\Framework\TestCase;

final class DailyOneThingInputReadinessTest extends TestCase
{
    public function testVerifiedReadOfAnEmptyDateAllowsAnExplicitGapCandidate(): void
    {
        $input = $this->service(static fn(): array => self::closure())->build(80, 80, '2026-09-27', 7);
        self::assertSame('readback_ready', $input['strict_fact_status'] ?? null);
        self::assertSame([], $input['source_errors']);
        $selection = (new DailyOneThingService())->select($input['candidates'], '2026-09-27');
        self::assertSame('explicit_data_gap', $selection['selected']['source_type']);
        self::assertSame('gap:ctrip:target_date_source_rows', $selection['selected']['candidate_key']);
    }

    public function testFailedOrWrongScopeReadsNeverBecomeAnActionableMissingDataClaim(): void
    {
        foreach (['failure', 'tenant_id', 'hotel_id', 'business_date'] as $kind) {
            $input = $this->service(static function () use ($kind): array {
                if ($kind === 'failure') throw new \RuntimeException('Synthetic source failure');
                $closure = self::closure();
                $closure[$kind] = $kind === 'business_date' ? '2026-09-26' : 81;
                return $closure;
            })->build(80, 80, '2026-09-27', 7);
            self::assertSame('source_unavailable', $input['strict_fact_status'] ?? null);
            self::assertSame([], $input['candidates']);
            self::assertContains(['code' => 'strict_fact_layer_unavailable'], $input['source_errors']);
        }
    }

    public function testIndependentReputationFailureDoesNotDiscardVerifiedFieldGap(): void
    {
        $input = $this->service(static fn(): array => self::closure(), true)->build(80, 80, '2026-09-27', 7);
        self::assertSame('readback_ready', $input['strict_fact_status'] ?? null);
        self::assertSame([['code' => 'ota_reputation_signal_unavailable']], $input['source_errors']);
        self::assertSame('explicit_data_gap', $input['candidates'][0]['source_type']);
    }

    private function service(callable $closureReader, bool $reputationFailure = false): DailyOneThingInputService
    {
        return new DailyOneThingInputService($closureReader,
            static fn(): array => ['data_status' => 'ok', 'list' => []], static fn(): ?array => null,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-09-27 09:00:00', new \DateTimeZone('Asia/Shanghai')),
            static function () use ($reputationFailure): array {
                if ($reputationFailure) throw new \RuntimeException('Synthetic reputation failure');
                return ['contract_version' => OtaReputationDailySignalService::CONTRACT_VERSION,
                    'tenant_id' => 80, 'hotel_id' => 80, 'business_date' => '2026-09-27',
                    'signals' => [], 'boundary' => ['external_write_count' => 0]];
            });
    }

    private static function closure(): array
    {
        $closure = DualOtaFieldClosureService::evaluate(['id' => 80, 'tenant_id' => 80], '2026-09-27', []);
        self::assertSame('partial', $closure['status']);
        return $closure;
    }
}
