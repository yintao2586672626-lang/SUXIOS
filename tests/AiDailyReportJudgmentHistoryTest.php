<?php
declare(strict_types=1);

use app\service\AiDailyReportService;
use app\service\AiDecisionQualityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiDailyReportJudgmentHistoryTest extends TestCase
{
    public static function storageBoundaries(): array
    {
        $cases = [];
        foreach ([false, true] as $appendOnly) foreach ([100, 101, 106] as $count) {
            $cases[($appendOnly ? 'append' : 'snapshot') . '-' . $count] = [$appendOnly, $count];
        }
        return $cases;
    }

    #[DataProvider('storageBoundaries')]
    public function testLatestJudgmentsAndUsefulnessAgreeAcrossStorageBoundary(bool $appendOnly, int $count): void
    {
        $snapshot = $persisted = [];
        for ($id = 1; $id <= $count; $id++) {
            $judgment = $this->judgment($id, $appendOnly);
            if ($id === 1 || $id === $count) {
                $judgment['target_type'] = 'report_usefulness';
                $judgment['decision'] = $id === 1 ? 'accepted' : 'rejected';
            }
            $snapshot[] = $judgment;
            if ($appendOnly) $persisted[] = array_replace($judgment, ['id' => 'review-' . $id, 'user_label' => 'persisted reviewer']);
        }
        $snapshot = array_slice($snapshot, -100);
        $before = [$snapshot, $persisted];
        $report = $this->normalize($snapshot, $persisted);
        $expectedIds = range($appendOnly ? 1 : max(1, $count - 99), $count);
        self::assertSame(array_map(static fn(int $id): string => 'opinion-' . $id, $expectedIds), array_column($report['human_judgments'], 'comment'));
        self::assertSame(array_map(static fn(int $id): string => 'opinion-' . $id, range($count, $count - 4)), array_column(array_slice(array_reverse($report['human_judgments']), 0, 5), 'comment'));
        self::assertFalse($report['trial_validation']['user_confirmed_useful']['passed']);
        self::assertSame('confirmed_not_useful', $report['trial_validation']['user_confirmed_useful']['status']);
        self::assertSame($report['human_judgments'], $report['snapshot']['human_judgments']);
        self::assertSame($report['human_judgments'], $report['result_layers']['human_judgments']);
        self::assertSame($before, [$snapshot, $persisted], 'normalization does not mutate storage inputs');
        self::assertSame([301, 7, '2026-09-14'], [$report['id'], $report['hotel_id'], $report['report_date']]);
        if ($appendOnly) {
            self::assertSame('review-' . $count, $report['human_judgments'][$count - 1]['id']);
            self::assertSame('persisted reviewer', $report['human_judgments'][$count - 1]['user_label']);
        }
    }

    public function testSameSecondMigrationRetainsLegacyOrderAndPersistedValuesAtSharedAnchor(): void
    {
        $legacy = $this->judgment(90, false);
        $legacy['target_type'] = 'report_usefulness';
        $legacy['decision'] = 'accepted';
        $shared = $this->judgment(2, true);
        $shared['comment'] = 'stale snapshot value';
        $shared['decision'] = 'accepted';
        $persisted = array_map(fn(int $id): array => $this->judgment($id, true), [1, 2, 3]);
        $persisted[1]['target_type'] = 'report_usefulness';
        $persisted[1]['decision'] = 'rejected';
        $persisted[1]['id'] = 'review-2';
        $report = $this->normalize([$legacy, $shared], $persisted);
        self::assertSame(['opinion-90', 'opinion-1', 'opinion-2', 'opinion-3'], array_column($report['human_judgments'], 'comment'));
        self::assertSame('review-2', $report['human_judgments'][2]['id']);
        self::assertSame('rejected', $report['human_judgments'][2]['decision']);
        self::assertFalse($report['trial_validation']['user_confirmed_useful']['passed'], 'later overall acceptance does not replace the latest usefulness rejection');
        self::assertCount(4, $report['human_judgments']);
    }

    public function testDifferentRecordedTimesInterleaveLegacyAndPersistedWithoutSortingRandomIds(): void
    {
        $legacy = $this->judgment(90, false);
        $legacy['recorded_at'] = '2026-09-14 12:00:01';
        $persisted = array_map(fn(int $id): array => $this->judgment($id, true), [1, 2, 3]);
        $persisted[1]['recorded_at'] = '2026-09-14 12:00:02';
        $persisted[2]['recorded_at'] = '2026-09-14 12:00:03';
        $report = $this->normalize([$legacy, $persisted[1]], $persisted);
        self::assertSame(['opinion-1', 'opinion-90', 'opinion-2', 'opinion-3'], array_column($report['human_judgments'], 'comment'));
        self::assertSame($legacy['id'], $report['human_judgments'][1]['id']);
    }

    public function testMissingLegacyTimesStayMissingAndKeepTheirExistingRelativeOrder(): void
    {
        $first = $this->judgment(90, false);
        $second = $this->judgment(91, false);
        unset($first['recorded_at']);
        $second['recorded_at'] = '';
        $first['id'] = 'z-old'; $second['id'] = 'a-old';
        $shared = $this->judgment(2, true);
        $report = $this->normalize([$first, $second, $shared], [$this->judgment(1, true), $shared, $this->judgment(3, true)]);
        self::assertSame(['opinion-90', 'opinion-91', 'opinion-1', 'opinion-2', 'opinion-3'], array_column($report['human_judgments'], 'comment'));
        self::assertArrayNotHasKey('recorded_at', $report['human_judgments'][0]);
        self::assertSame('', $report['human_judgments'][1]['recorded_at']);
        self::assertSame(['z-old', 'a-old'], array_column(array_slice($report['human_judgments'], 0, 2), 'id'));
    }

    public function testRepeatedSharedKeysAreEmittedOnceAndLaterSnapshotOnlyRecordStaysAfterItsAnchor(): void
    {
        $shared = $this->judgment(2, true);
        $after = $this->judgment(90, false);
        $persisted = [$this->judgment(1, true), array_replace($shared, ['comment' => 'authoritative shared']), $this->judgment(3, true)];
        $report = $this->normalize([$shared, $shared, $after], $persisted);
        self::assertSame(['opinion-1', 'authoritative shared', 'opinion-90', 'opinion-3'], array_column($report['human_judgments'], 'comment'));
        self::assertCount(4, $report['human_judgments']);
    }

    private function judgment(int $id, bool $persisted): array
    {
        return ['id' => sprintf('%016x', 2000 - $id), 'review_record_id' => $persisted ? $id : null,
            'target_type' => 'overall', 'target_key' => 'synthetic-' . $id, 'decision' => 'accepted',
            'comment' => 'opinion-' . $id, 'correction' => '', 'recorded_at' => '2026-09-14 12:00:00',
            'user_id' => 99, 'user_label' => 'snapshot reviewer', 'scope' => 'single_report_single_hotel', 'propagate_to_other_hotels' => false];
    }

    private function normalize(array $snapshot, array $persisted): array
    {
        $service = new class extends AiDailyReportService {
            public function __construct() {}
            public function buildReportReadiness(array $report, array $executionItems = [], ?array $kernelSummary = null): array
            {
                // Inject only the unrelated kernel read result; normalization and judgment projection stay real.
                return parent::buildReportReadiness($report, $executionItems, []);
            }
        };
        $reflection = new ReflectionClass(AiDailyReportService::class);
        $reflection->getProperty('decisionQualityService')->setValue($service, new AiDecisionQualityService());
        return $reflection->getMethod('normalizeReportRow')->invoke($service, [
            'id' => 301, 'hotel_id' => 7, 'tenant_id' => 42, 'report_date' => '2026-09-14', 'model_status' => 'not_requested',
            'snapshot_json' => json_encode(['human_judgments' => $snapshot], JSON_THROW_ON_ERROR),
            'source_refs_json' => '[]', 'recommended_actions_json' => '[]',
        ], [], $persisted);
    }
}
