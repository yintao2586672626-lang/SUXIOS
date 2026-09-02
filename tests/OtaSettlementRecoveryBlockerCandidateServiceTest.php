<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaSettlementRecoveryBlockerCandidateService;
use PHPUnit\Framework\TestCase;

final class OtaSettlementRecoveryBlockerCandidateServiceTest extends TestCase
{
    public function testSyntheticSettlementBatchRemainsExplicitlyBlocked(): void
    {
        $result = (new OtaSettlementRecoveryBlockerCandidateService())->build(
            $this->readback('synthetic_test_only')
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame(1, $result['candidate_count']);
        self::assertSame('settlement_synthetic_test_only', $result['selected']['reason_code']);
        self::assertSame(
            'import_verified_same_scope_settlement_export',
            $result['selected']['next_action_code']
        );
        self::assertSame(
            ['source_quality_status:synthetic_test_only'],
            $result['selected']['gap_codes']
        );
        self::assertFalse($result['selected']['execution']['execution_intent_created']);
        self::assertSame(0, $result['selected']['boundaries']['external_write_count']);
    }

    public function testVerifiedSettlementBatchWithoutFactGapsCanBeReady(): void
    {
        $result = (new OtaSettlementRecoveryBlockerCandidateService())->build(
            $this->readback('verified_export')
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(0, $result['candidate_count']);
        self::assertNull($result['selected']);
    }

    /** @return array<string,mixed> */
    private function readback(string $sourceQualityStatus): array
    {
        return [
            'read_status' => 'available',
            'readback_verified' => true,
            'batch_id' => 12,
            'batch_fingerprint' => str_repeat('a', 64),
            'scope' => [
                'tenant_id' => 8,
                'hotel_id' => 80,
                'source_hotel_id' => 80,
                'platform' => 'ctrip',
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
            ],
            'source' => [
                'source_quality_status' => $sourceQualityStatus,
            ],
            'lines' => [],
            'ranked_discrepancies' => [],
        ];
    }
}
