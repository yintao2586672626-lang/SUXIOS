<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManagerCapabilityScoringService;
use PHPUnit\Framework\TestCase;

final class ManagerCapabilityScoringServiceTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260822_z_create_manager_capability_scoring.sql';
    private const FOLLOWUP_MIGRATION = __DIR__ . '/../database/migrations/20260822_zz_create_manager_capability_followups.sql';
    private const OPTIMIZATION_MIGRATION = __DIR__ . '/../database/migrations/20260822_zzz_optimize_manager_capability.sql';
    private const EVENT_TIMESTAMP_MIGRATION = __DIR__ . '/../database/migrations/20260822_zzzz_refine_manager_capability_event_timestamps.sql';
    private const FOLLOWUP_EVENT_TIMESTAMP_MIGRATION = __DIR__ . '/../database/migrations/20260822_zzzzz_refine_manager_capability_followup_event_timestamp.sql';

    public function testGoldenClosedCaseProducesExplainableSixDimensionScore(): void
    {
        $result = (new ManagerCapabilityScoringService())->scoreCase([
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是交接流程未明确主管复核责任。',
            'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'observed_result',
            'verification_text' => '次日抽查3笔记录，全部签字完整且完成时间符合要求，员工可以独立完成。',
        ]);

        self::assertSame(ManagerCapabilityScoringService::FORMULA_VERSION, $result['formula_version']);
        self::assertSame('scored', $result['status']);
        self::assertSame(6, $result['scored_dimension_count']);
        self::assertSame(90.0, $result['case_score']);
        self::assertCount(6, $result['dimensions']);

        foreach ($result['dimensions'] as $dimension) {
            self::assertSame(90, $dimension['score'], $dimension['key']);
            self::assertSame('scored', $dimension['status']);
            self::assertNotEmpty($dimension['evidence_refs']);
            self::assertNotEmpty($dimension['reasons']);
            self::assertCount(3, $dimension['rubric']);
            self::assertSame('manual_declared', $dimension['source_quality_status']);
        }
    }

    public function testPlannedVerificationAndMissingCoachingStayUnknownInsteadOfZero(): void
    {
        $result = (new ManagerCapabilityScoringService())->scoreCase([
            'problem_facts' => '今天前台发现一笔交接记录没有主管签字。',
            'action_taken' => '已安排负责人当天按清单补齐，并在每班结束时检查。',
            'verification_status' => 'planned_verification',
            'verification_text' => '计划次日抽查三笔记录并核对完成时间。',
        ]);
        $dimensions = array_column($result['dimensions'], null, 'key');

        self::assertSame('pending_verification', $result['status']);
        self::assertNull($result['case_score']);
        self::assertNull($dimensions['coaching']['score']);
        self::assertSame('not_observed', $dimensions['coaching']['status']);
        self::assertNull($dimensions['closure']['score']);
        self::assertSame('not_observed', $dimensions['closure']['status']);
        self::assertStringContainsString('不计0分', $dimensions['coaching']['reasons'][0]);
    }

    public function testProfileRequiresThreeSamplesPerDimensionAndExcludesUnknowns(): void
    {
        $service = new ManagerCapabilityScoringService();
        $golden = $service->scoreCase([
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排主管现场演示标准，按清单补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'observed_result',
            'verification_text' => '次日抽查3笔记录，全部签字完整，员工可以独立完成。',
        ]);

        $insufficient = $service->aggregateDimensionScores([
            ['case_id' => 1, 'dimensions' => $golden['dimensions']],
            ['case_id' => 2, 'dimensions' => $golden['dimensions']],
        ]);
        self::assertSame('data_insufficient', $insufficient['status']);
        self::assertNull($insufficient['overall_score']);
        self::assertCount(6, $insufficient['data_gaps']);
        self::assertSame(2, $insufficient['dimensions'][0]['sample_count']);

        $ready = $service->aggregateDimensionScores([
            ['case_id' => 1, 'dimensions' => $golden['dimensions']],
            ['case_id' => 2, 'dimensions' => $golden['dimensions']],
            ['case_id' => 3, 'dimensions' => $golden['dimensions']],
        ]);
        self::assertSame('scored', $ready['status']);
        self::assertSame(90.0, $ready['overall_score']);
        self::assertSame('较强', $ready['label']);
        self::assertSame([], $ready['data_gaps']);
        foreach ($ready['dimensions'] as $dimension) {
            self::assertSame(3, $dimension['sample_count']);
            self::assertSame(90.0, $dimension['score']);
        }
    }

    public function testFollowupOutcomeControlsClosureWithoutOverwritingUnknowns(): void
    {
        $service = new ManagerCapabilityScoringService();
        $base = [
            'problem_facts' => '今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排主管现场演示标准，按清单补齐2笔记录，并指定主管每班抽查。',
            'verification_text' => '本次抽查2笔记录，核对后已有明确结果。',
            'followup_sample_count' => 2,
        ];

        $stillOpen = $service->scoreCase($base + [
            'verification_status' => 'planned_verification',
            'followup_outcome' => 'still_open',
        ]);
        $stillOpenDimensions = array_column($stillOpen['dimensions'], null, 'key');
        self::assertSame('pending_verification', $stillOpen['status']);
        self::assertNull($stillOpenDimensions['closure']['score']);
        self::assertStringContainsString('继续留空', $stillOpenDimensions['closure']['reasons'][0]);

        $recurred = $service->scoreCase($base + [
            'verification_status' => 'observed_result',
            'followup_outcome' => 'recurred',
        ]);
        $recurredDimensions = array_column($recurred['dimensions'], null, 'key');
        self::assertSame(50, $recurredDimensions['closure']['score']);
        self::assertStringContainsString('再次发生', $recurredDimensions['closure']['reasons'][0]);
    }

    public function testDailySubmissionCountsActiveSameDayCasesWithoutInferringClosure(): void
    {
        $summary = (new ManagerCapabilityScoringService())->summarizeDailySubmission([
            ['id' => 11, 'business_date' => '2026-08-20', 'is_voided' => false],
            ['id' => 12, 'business_date' => '2026-08-20', 'is_voided' => false],
            ['id' => 13, 'business_date' => '2026-08-20', 'is_voided' => true],
            ['id' => 14, 'business_date' => '2026-08-21', 'is_voided' => false],
        ], '2026-08-20');

        self::assertSame('submitted', $summary['status']);
        self::assertSame('当日已提交', $summary['label']);
        self::assertSame(2, $summary['case_count']);
        self::assertSame([11, 12], $summary['case_ids']);
        self::assertSame('2026-08-20', $summary['last_submission_date']);
        self::assertSame(0, $summary['consecutive_missing_days']);
        self::assertSame('none', $summary['attention_status']);
        self::assertFalse($summary['independent_verification']);
        self::assertFalse($summary['closure_inferred']);
        self::assertStringContainsString('已提交不等于已闭环', $summary['closure_note']);
    }

    public function testDailySubmissionFlagsThreeDayGapAndKeepsNoHistoryDurationUnknown(): void
    {
        $service = new ManagerCapabilityScoringService();
        $missing = $service->summarizeDailySubmission([
            ['id' => 21, 'business_date' => '2026-08-21', 'is_voided' => false],
            ['id' => 22, 'business_date' => 'not-a-date', 'is_voided' => false],
        ], '2026-08-25');

        self::assertSame('not_submitted', $missing['status']);
        self::assertSame('2026-08-21', $missing['last_submission_date']);
        self::assertSame(4, $missing['consecutive_missing_days']);
        self::assertSame('three_day_missing', $missing['attention_status']);
        self::assertSame(1, $missing['invalid_business_date_count']);

        $noHistory = $service->summarizeDailySubmission([], '2026-08-25');
        self::assertSame('not_submitted', $noHistory['status']);
        self::assertNull($noHistory['last_submission_date']);
        self::assertNull($noHistory['consecutive_missing_days']);
        self::assertSame('no_history', $noHistory['attention_status']);
        self::assertSame('empty', $noHistory['history_status']);
    }

    public function testMigrationPersistsScopedCasesAndSnapshotsWithoutPersonnelActions(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            'manager_capability_cases',
            'manager_capability_score_snapshots',
            '`tenant_id`',
            '`hotel_id`',
            '`manager_user_id`',
            '`input_digest`',
            '`evidence_digest`',
            'uniq_manager_capability_case_idempotency',
            'manual_management_three_questions',
            'manual_declared',
            ManagerCapabilityScoringService::SOURCE_FINGERPRINT,
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));
    }

    public function testFollowupMigrationIsAppendOnlyAndKeepsRecurrenceScoped(): void
    {
        $sql = (string)file_get_contents(self::FOLLOWUP_MIGRATION);

        foreach ([
            'manager_capability_case_followups',
            '`case_id`',
            '`tenant_id`',
            '`hotel_id`',
            '`manager_user_id`',
            '`followup_outcome`',
            '`next_followup_date`',
            '`linked_recurrence_case_id`',
            '`parent_case_id`',
            '`origin_followup_id`',
            '`input_digest`',
            '`evidence_digest`',
            'uniq_manager_capability_followup_idempotency',
            'manual_declared',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));
    }

    public function testOptimizationMigrationAddsStructuredEvidenceAndAppendOnlyHumanEvents(): void
    {
        $sql = (string)file_get_contents(self::OPTIMIZATION_MIGRATION);
        foreach ([
            'manager_capability_case_adjustments',
            'manager_capability_score_reviews',
            '`evidence_type`',
            '`evidence_reference`',
            '`evidence_date`',
            '`evidence_confidence`',
            '`effective_payload_json`',
            '`is_voided`',
            '`source_score_digest`',
            'uniq_manager_capability_adjustment_idempotency',
            'uniq_manager_capability_score_review_idempotency',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }
        self::assertStringNotContainsString('operation_execution_intents', $sql);
        self::assertStringNotContainsString('operation_execution_tasks', $sql);
        self::assertStringNotContainsString('ranking', strtolower($sql));
        self::assertStringNotContainsString('penalty', strtolower($sql));

        $timestampSql = (string)file_get_contents(self::EVENT_TIMESTAMP_MIGRATION);
        self::assertStringContainsString('manager_capability_case_adjustments', $timestampSql);
        self::assertStringContainsString('manager_capability_score_reviews', $timestampSql);
        self::assertStringContainsString('DATETIME(6)', $timestampSql);
        self::assertStringContainsString('manager_capability_case_followups', $timestampSql);
        self::assertSame(
            '79b7f4540fbfb60ae0d417b57a59d2d3a5408503bd9ab2e318e1c9511f4be7a3',
            hash_file('sha256', self::EVENT_TIMESTAMP_MIGRATION)
        );

        $followupTimestampSql = (string)file_get_contents(self::FOLLOWUP_EVENT_TIMESTAMP_MIGRATION);
        self::assertStringContainsString('manager_capability_case_followups', $followupTimestampSql);
        self::assertStringContainsString('DATETIME(6)', $followupTimestampSql);
        self::assertStringNotContainsString('manager_capability_case_adjustments', $followupTimestampSql);
        self::assertStringNotContainsString('manager_capability_score_reviews', $followupTimestampSql);
        self::assertSame(
            'd53dbb0f2192b13ae27d0cdc56389e552075664875ff419eac1aef18df4397b4',
            hash_file('sha256', self::FOLLOWUP_EVENT_TIMESTAMP_MIGRATION)
        );
    }

    public function testIdempotentWriteRetriesOnlyBoundedDatabaseConflicts(): void
    {
        $attempts = 0;
        $lookups = 0;
        $result = $this->invokeIdempotentWrite(
            static fn(): array => ['case_id' => 41, 'replayed' => false],
            static function () use (&$lookups): ?array {
                $lookups++;
                return null;
            },
            static fn(array $existing): array => $existing,
            static function (callable $callback) use (&$attempts): array {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RuntimeException('Deadlock found when trying to get lock', 1213);
                }
                return $callback();
            }
        );

        self::assertSame(['case_id' => 41, 'replayed' => false], $result);
        self::assertSame(3, $attempts);
        self::assertSame(0, $lookups, 'Deadlocks retry the transaction; they do not masquerade as duplicate replays.');

        $attempts = 0;
        try {
            $this->invokeIdempotentWrite(
                static fn(): array => ['unreachable' => true],
                static fn(): ?array => null,
                static fn(array $existing): array => $existing,
                static function (callable $callback) use (&$attempts): array {
                    $attempts++;
                    throw new \RuntimeException('Lock wait timeout exceeded', 1205);
                }
            );
            self::fail('The third retryable lock failure must be rethrown.');
        } catch (\RuntimeException $error) {
            self::assertSame(1205, $error->getCode());
            self::assertSame(3, $attempts);
        }
    }

    public function testDuplicateConflictReplaysOnlyAfterExactDigestReadback(): void
    {
        $inputDigest = str_repeat('a', 64);
        $attempts = 0;
        $lookups = 0;
        $replay = static function (array $existing) use ($inputDigest): array {
            if (!hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)) {
                throw new \InvalidArgumentException('幂等键已用于不同内容');
            }
            return ['case_id' => (int)$existing['id'], 'replayed' => true];
        };
        $duplicateRunner = static function (callable $callback) use (&$attempts): array {
            $attempts++;
            throw new \RuntimeException(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'same-key'",
                23000
            );
        };

        $result = $this->invokeIdempotentWrite(
            static fn(): array => ['unreachable' => true],
            static function () use (&$lookups, $inputDigest): ?array {
                $lookups++;
                return ['id' => 73, 'input_digest' => $inputDigest];
            },
            $replay,
            $duplicateRunner
        );
        self::assertSame(['case_id' => 73, 'replayed' => true], $result);
        self::assertSame(1, $attempts);
        self::assertSame(1, $lookups);

        try {
            $this->invokeIdempotentWrite(
                static fn(): array => ['unreachable' => true],
                static fn(): ?array => ['id' => 74, 'input_digest' => str_repeat('b', 64)],
                $replay,
                static function (callable $callback): array {
                    throw new \RuntimeException('1062 Duplicate entry for key idempotency', 1062);
                }
            );
            self::fail('A reused idempotency key with a different digest must fail closed.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('幂等键已用于不同内容', $error->getMessage());
        }
    }

    public function testNonRetryableWriteFailureIsNotSwallowed(): void
    {
        $attempts = 0;
        $original = new \RuntimeException(
            'SQLSTATE[23000]: Integrity constraint violation: foreign key constraint fails',
            23000
        );

        try {
            $this->invokeIdempotentWrite(
                static fn(): array => ['unreachable' => true],
                static fn(): ?array => null,
                static fn(array $existing): array => $existing,
                static function (callable $callback) use (&$attempts, $original): array {
                    $attempts++;
                    throw $original;
                }
            );
            self::fail('An unrelated database constraint failure must be rethrown.');
        } catch (\RuntimeException $error) {
            self::assertSame($original, $error);
            self::assertSame(1, $attempts);
        }
    }

    public function testPagedProjectionFindsNewestOverdueBeyondLegacyThousandRowCutoff(): void
    {
        $fixtures = [];
        for ($id = 1; $id <= 1000; $id++) {
            $fixtures[] = [
                'id' => $id,
                'business_date' => '2025-01-01',
                'case_status' => 'closed',
                'is_voided' => false,
                'current_followup_due_date' => null,
            ];
        }
        $fixtures[] = [
            'id' => 1001,
            'business_date' => '2026-08-20',
            'case_status' => 'pending_verification',
            'is_voided' => false,
            'current_followup_due_date' => '2026-08-21',
        ];
        $loadPage = static function (int $afterId, int $limit, int $boundaryId) use ($fixtures): array {
            $eligible = array_values(array_filter(
                $fixtures,
                static fn(array $row): bool => (int)$row['id'] > $afterId
                    && (int)$row['id'] <= $boundaryId
            ));
            return array_slice($eligible, 0, $limit);
        };
        $projectPage = static fn(array $rows): array => $rows;

        $scan = $this->invokePrivateMethod(
            'scanProjectedCasePages',
            [1001, $loadPage, $projectPage, 250, 20000]
        );
        self::assertTrue($scan['metadata']['complete']);
        self::assertFalse($scan['metadata']['truncated']);
        self::assertSame('boundary_reached', $scan['metadata']['stop_reason']);
        self::assertSame(5, $scan['metadata']['page_count']);
        self::assertSame(1001, $scan['metadata']['scanned_row_count']);
        self::assertCount(1001, $scan['cases']);

        $today = new \DateTimeImmutable('2026-08-22', new \DateTimeZone('Asia/Shanghai'));
        $queue = $this->invokePrivateMethod('casesForFollowupQueue', [$scan['cases'], $today]);
        self::assertCount(1, $queue);
        self::assertSame(1001, $queue[0]['id']);
        self::assertSame('overdue', $queue[0]['due_bucket']);
        self::assertSame(-1, $queue[0]['days_offset']);

        $partial = $this->invokePrivateMethod(
            'scanProjectedCasePages',
            [1001, $loadPage, $projectPage, 250, 1000]
        );
        self::assertFalse($partial['metadata']['complete']);
        self::assertTrue($partial['metadata']['truncated']);
        self::assertSame('partial', $partial['metadata']['status']);
        self::assertSame('row_limit_reached', $partial['metadata']['stop_reason']);
        self::assertSame(1000, $partial['metadata']['scanned_row_count']);
        self::assertCount(1000, $partial['cases']);
    }

    public function testProfileWindowUsesAdjustedProjectionDateInsteadOfRawDate(): void
    {
        $rawRows = [[
            'id' => 91,
            'business_date' => '2025-01-10',
        ]];
        $loadPage = static function (int $afterId, int $limit, int $boundaryId) use ($rawRows): array {
            if ($afterId >= 91 || $boundaryId < 91) {
                return [];
            }
            return array_slice($rawRows, 0, $limit);
        };
        $projectPage = static function (array $rows): array {
            return array_map(static function (array $row): array {
                return [
                    ...$row,
                    'business_date' => '2026-08-12',
                    'original_case' => ['business_date' => (string)$row['business_date']],
                ];
            }, $rows);
        };

        $scan = $this->invokePrivateMethod(
            'scanProjectedCasePages',
            [91, $loadPage, $projectPage, 250, 20000]
        );
        $windowCases = $this->invokePrivateMethod(
            'casesInProfileWindow',
            [$scan['cases'], '2026-08-01', '2026-08-22']
        );

        self::assertTrue($scan['metadata']['complete']);
        self::assertCount(1, $windowCases);
        self::assertSame(91, $windowCases[0]['id']);
        self::assertSame('2026-08-12', $windowCases[0]['business_date']);
        self::assertSame('2025-01-10', $windowCases[0]['original_case']['business_date']);
    }

    /**
     * @param callable(): array<string, mixed> $transactionCallback
     * @param callable(): ?array<string, mixed> $findExisting
     * @param callable(array<string, mixed>): array<string, mixed> $replayExisting
     * @param callable(callable(): array<string, mixed>): array<string, mixed> $transactionRunner
     * @return array<string, mixed>
     */
    private function invokeIdempotentWrite(
        callable $transactionCallback,
        callable $findExisting,
        callable $replayExisting,
        callable $transactionRunner
    ): array {
        $method = (new \ReflectionClass(ManagerCapabilityScoringService::class))
            ->getMethod('runIdempotentWrite');
        $result = $method->invoke(
            new ManagerCapabilityScoringService(),
            $transactionCallback,
            $findExisting,
            $replayExisting,
            $transactionRunner
        );
        self::assertIsArray($result);
        return $result;
    }

    /** @param array<int, mixed> $arguments */
    private function invokePrivateMethod(string $methodName, array $arguments): mixed
    {
        $method = (new \ReflectionClass(ManagerCapabilityScoringService::class))
            ->getMethod($methodName);
        return $method->invokeArgs(new ManagerCapabilityScoringService(), $arguments);
    }
}
