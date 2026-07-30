<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeSopExecutionProvenanceService;
use PHPUnit\Framework\TestCase;

final class KnowledgeSopExecutionProvenanceServiceTest extends TestCase
{
    public function testCurrentSystemSopBindsExactHotelPlatformAndSnapshot(): void
    {
        $service = new KnowledgeSopExecutionProvenanceService();
        [$unit, $chunk] = $this->snapshot();
        $provenance = $service->validateSnapshot(
            $unit,
            $chunk,
            7,
            'ctrip',
            '2026-07-30 10:00:00'
        );
        $intent = $this->intent($provenance, 7, 'ctrip');

        $current = $service->assertSnapshotMatches(
            $intent,
            $unit,
            $chunk,
            '2026-07-30 10:05:00'
        );

        self::assertSame(
            KnowledgeSopExecutionProvenanceService::CONTRACT_VERSION,
            $current['contract_version']
        );
        self::assertSame(7, $current['target_hotel_id']);
        self::assertSame('ctrip', $current['resolved_platform']);
        self::assertSame('C', $current['evidence_grade']);
        self::assertTrue($current['knowledge_gate']['task_draft_safe']);
    }

    public function testHotelAndPlatformScopeCannotBeRebound(): void
    {
        $service = new KnowledgeSopExecutionProvenanceService();
        [$unit, $chunk] = $this->snapshot([
            'hotel_id' => 7,
            'created_by' => 9,
        ], [
            'platforms' => ['ctrip'],
        ]);

        try {
            $service->validateSnapshot($unit, $chunk, 8, 'ctrip', '2026-07-30 10:00:00');
            self::fail('cross-hotel SOP binding must fail');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('hotel', strtolower($exception->getMessage()));
        }

        $this->expectException(\InvalidArgumentException::class);
        $service->validateSnapshot($unit, $chunk, 7, 'meituan', '2026-07-30 10:00:00');
    }

    public function testAmbiguousMultiPlatformTargetMustBeSelectedExplicitly(): void
    {
        [$unit, $chunk] = $this->snapshot();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('select one platform');
        (new KnowledgeSopExecutionProvenanceService())->validateSnapshot(
            $unit,
            $chunk,
            7,
            'ota',
            '2026-07-30 10:00:00'
        );
    }

    public function testChangedOrStaleSourceCannotMatchStoredDraft(): void
    {
        $service = new KnowledgeSopExecutionProvenanceService();
        [$unit, $chunk] = $this->snapshot();
        $provenance = $service->validateSnapshot(
            $unit,
            $chunk,
            7,
            'ctrip',
            '2026-07-30 10:00:00'
        );
        $intent = $this->intent($provenance, 7, 'ctrip');

        $changed = $chunk;
        $changed['content']['task_template']['steps'][0] = 'changed after draft';
        try {
            $service->assertSnapshotMatches(
                $intent,
                $unit,
                $changed,
                '2026-07-30 10:05:00'
            );
            self::fail('changed SOP content must invalidate the stored draft');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('source changed', $exception->getMessage());
        }

        $stale = $chunk;
        $stale['content']['valid_until'] = '2026-07-29 23:59:59';
        $this->expectException(\InvalidArgumentException::class);
        $service->assertSnapshotMatches(
            $intent,
            $unit,
            $stale,
            '2026-07-30 10:05:00'
        );
    }

    /**
     * @param array<string, mixed> $unitOverrides
     * @param array<string, mixed> $contentOverrides
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function snapshot(array $unitOverrides = [], array $contentOverrides = []): array
    {
        $unit = array_replace([
            'unit_id' => 40,
            'hotel_id' => 0,
            'created_by' => 0,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-29 09:00:00',
            'review_due_at' => '2026-12-31 23:59:59',
            'truth_profile_version' => '2026-07-30.1',
        ], $unitOverrides);
        $content = array_replace([
            'content_type' => 'sop_card',
            'scope' => 'generic_methodology',
            'evidence_level' => 'external_public_reference_reviewed',
            'evidence_grade' => 'C',
            'source_refs' => ['domestic-public-reference'],
            'platforms' => ['ctrip', 'meituan'],
            'lifecycle_status' => 'active',
            'task_template' => [
                'title' => 'verify inventory',
                'steps' => ['check current inventory', 'record the result'],
                'acceptance_criteria' => ['same hotel and platform verified'],
            ],
        ], $contentOverrides);

        return [$unit, [
            'chunk_id' => 291,
            'unit_id' => 40,
            'content' => $content,
        ]];
    }

    /** @return array<string, mixed> */
    private function intent(array $provenance, int $hotelId, string $platform): array
    {
        return [
            'source_module' => 'knowledge_sop',
            'source_record_id' => (int)$provenance['knowledge_chunk_id'],
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'evidence' => [
                'knowledge_provenance' => $provenance,
            ],
        ];
    }
}
