<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeChunkGateSummaryService;
use PHPUnit\Framework\TestCase;

final class KnowledgeChunkGateSummaryTest extends TestCase
{
    public function testContradictorySiblingClaimsAreWithheldAndReportedAsUnresolved(): void
    {
        $summary = $this->summarize([
            $this->chunk(101, '5.69%'),
            $this->chunk(102, '6.10%'),
        ]);

        self::assertSame(2, $summary['total_count']);
        self::assertSame(0, $summary['retrieval_safe_count']);
        self::assertSame(0, $summary['decision_safe_count']);
        self::assertSame(1, $summary['unresolved_conflict_count']);
        self::assertSame(2, $summary['withheld_conflict_chunk_count']);
    }

    public function testExplicitCurrentWinnerAllowsOnlyThatClaimThroughTheGate(): void
    {
        $current = $this->chunk(101, '6.10%');
        $current['content']['resolution_status'] = 'current';

        $summary = $this->summarize([
            $this->chunk(100, '5.69%'),
            $current,
        ]);

        self::assertSame(2, $summary['total_count']);
        self::assertSame(1, $summary['retrieval_safe_count']);
        self::assertSame(1, $summary['decision_safe_count']);
        self::assertSame(1, $summary['resolved_conflict_count']);
        self::assertSame(0, $summary['unresolved_conflict_count']);
        self::assertSame(1, $summary['withheld_conflict_chunk_count']);
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<string, int>
     */
    private function summarize(array $chunks): array
    {
        $result = (new KnowledgeChunkGateSummaryService())->summarize([[
            'unit_id' => 9,
            'status' => 'done',
            'lifecycle_status' => 'active',
        ]], $chunks);

        return $result[9];
    }

    /** @return array<string, mixed> */
    private function chunk(int $chunkId, string $claimValue): array
    {
        return [
            'chunk_id' => $chunkId,
            'unit_id' => 9,
            'content' => [
                'scope' => 'ota_revenue',
                'evidence_level' => 'official_current',
                'source_refs' => ['official-help://ctrip/revenue-share'],
                'reviewed_at' => '2026-07-30 00:00:00',
                'review_due_at' => '2026-12-31 23:59:59',
                'conflict_key' => 'ctrip.revenue_share',
                'claim_value' => $claimValue,
            ],
        ];
    }
}
