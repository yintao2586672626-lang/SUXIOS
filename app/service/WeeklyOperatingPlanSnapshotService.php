<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class WeeklyOperatingPlanSnapshotService
{
    public const CONTRACT_VERSION = 'weekly_operating_plan.v2';
    public const TABLE = 'weekly_operating_plan_snapshots';

    /** @var callable|null */
    private $sourceReader;

    /** @var callable|null */
    private $snapshotReader;

    /** @var callable|null */
    private $snapshotWriter;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $scopeVerifier;

    /** @var callable|null */
    private $snapshotTransaction;

    public function __construct(
        ?callable $sourceReader = null,
        ?callable $snapshotReader = null,
        ?callable $snapshotWriter = null,
        ?callable $clock = null,
        ?callable $scopeVerifier = null,
        ?callable $snapshotTransaction = null
    ) {
        $this->sourceReader = $sourceReader;
        $this->snapshotReader = $snapshotReader;
        $this->snapshotWriter = $snapshotWriter;
        $this->clock = $clock;
        $this->scopeVerifier = $scopeVerifier;
        $this->snapshotTransaction = $snapshotTransaction;
    }

    /** @return array<string,mixed> */
    public function generateAndReadback(
        int $tenantId,
        int $hotelId,
        string $weekEnd,
        int $createdBy = 0,
        string $generationTrigger = 'background'
    ): array {
        [$weekStart, $weekEnd] = $this->week($weekEnd);
        $this->assertScope($tenantId, $hotelId);
        $generationTrigger = strtolower(trim($generationTrigger));
        if (!in_array($generationTrigger, ['background', 'manual'], true)) {
            throw new \InvalidArgumentException('weekly_plan_generation_trigger_invalid');
        }
        $sources = $this->readSources($tenantId, $hotelId, $weekStart, $weekEnd);
        $draft = $this->buildDraft(
            $tenantId,
            $hotelId,
            $weekStart,
            $weekEnd,
            $sources,
            $generationTrigger,
            $createdBy
        );
        $existing = $this->readSnapshot('by_source', [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'generation_trigger' => $generationTrigger,
            'source_digest' => $draft['source_digest'],
        ]);
        if (is_array($existing)) {
            return $this->normalizeStored($existing, false, true);
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $version = max(1, (int)$this->readSnapshot('next_version', [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
            ]));
            $fingerprintInput = $draft + ['version_no' => $version];
            $fingerprintInput['version_no'] = $version;
            $row = [
                'contract_version' => self::CONTRACT_VERSION,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'version_no' => $version,
                'status' => (string)$draft['status'],
                'source_digest' => (string)$draft['source_digest'],
                'snapshot_fingerprint' => $this->snapshotFingerprint($fingerprintInput),
                'final_text_sha256' => (string)$draft['final_text_sha256'],
                'daily_run_refs_json' => $this->encode($draft['daily_run_refs']),
                'broadcast_snapshot_refs_json' => $this->encode($draft['broadcast_snapshot_refs']),
                'lifecycle_summary_json' => $this->encode($draft['lifecycle_summary']),
                'repeated_gap_summary_json' => $this->encode($draft['repeated_gap_summary']),
                'selected_focus_json' => $this->encode($draft['selected_focus']),
                'missing_days_json' => $this->encode($draft['missing_days']),
                'final_text' => (string)$draft['final_text'],
                'generation_trigger' => $generationTrigger,
                'generated_at' => (string)$draft['generated_at'],
                'created_by' => max(0, $createdBy),
                'created_at' => (string)$draft['generated_at'],
            ];
            try {
                return $this->persistAndVerifySnapshot($row);
            } catch (\Throwable $error) {
                $winner = $this->readSnapshot('by_source', [
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'generation_trigger' => $generationTrigger,
                    'source_digest' => $draft['source_digest'],
                ]);
                if (is_array($winner)) {
                    return $this->normalizeStored($winner, false, true);
                }
                if ($attempt === 3) {
                    if (str_starts_with($error->getMessage(), 'weekly_plan_snapshot_readback_')) {
                        throw $error;
                    }
                    throw new \RuntimeException('weekly_plan_snapshot_save_failed', 0, $error);
                }
            }
        }
        throw new \RuntimeException('weekly_plan_snapshot_save_failed');
    }

    /** @return array<string,mixed> */
    public function readLatest(int $tenantId, int $hotelId, string $weekEnd): array
    {
        [$weekStart, $weekEnd] = $this->week($weekEnd);
        $this->assertScope($tenantId, $hotelId);
        $row = $this->readSnapshot('latest', [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ]);
        if (!is_array($row)) {
            throw new \RuntimeException('weekly_plan_snapshot_not_found', 404);
        }
        return $this->normalizeStored($row, false, false);
    }

    /** @return array<string,mixed> */
    public function readExact(int $tenantId, int $hotelId, int $snapshotId): array
    {
        $this->assertScope($tenantId, $hotelId);
        if ($snapshotId <= 0) {
            throw new \InvalidArgumentException('weekly_plan_snapshot_id_invalid');
        }
        $row = $this->readSnapshot('exact', ['id' => $snapshotId]);
        if (!is_array($row)
            || (int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new \RuntimeException('weekly_plan_snapshot_not_found', 404);
        }
        return $this->normalizeStored($row, false, false);
    }

    /** @param array<string,mixed> $sources @return array<string,mixed> */
    public function buildDraft(
        int $tenantId,
        int $hotelId,
        string $weekStart,
        string $weekEnd,
        array $sources,
        string $generationTrigger = 'background',
        int $createdBy = 0
    ): array {
        $dates = $this->dateRange($weekStart, $weekEnd);
        $dailyRuns = array_values(array_filter((array)($sources['daily_runs'] ?? []), 'is_array'));
        $broadcasts = array_values(array_filter((array)($sources['broadcasts'] ?? []), 'is_array'));
        $intents = array_values(array_filter((array)($sources['intents'] ?? []), 'is_array'));
        $tasks = array_values(array_filter((array)($sources['tasks'] ?? []), 'is_array'));
        $reviewedObservations = array_values(array_filter(
            (array)($sources['reviewed_observations'] ?? []),
            static fn(mixed $review): bool => is_array($review)
                && (int)($review['baseline']['system_hotel_id'] ?? 0) === $hotelId
                && (int)($review['followup']['system_hotel_id'] ?? 0) === $hotelId
        ));
        $learningSummary = (new LongitudinalEvidenceLearningService())
            ->summarizeReviews($reviewedObservations, 3);
        $learningProjection = $this->compactOutcomeLearningSummary($learningSummary);
        $outcomeLearningRuntime = is_array($sources['outcome_learning_runtime'] ?? null)
            ? [
                'contract_version' => (string)($sources['outcome_learning_runtime']['contract_version'] ?? OperatingOutcomeLearningRuntimeService::CONTRACT_VERSION),
                'status' => (string)($sources['outcome_learning_runtime']['status'] ?? 'missing'),
                'reviewed_observation_count' => max(0, (int)($sources['outcome_learning_runtime']['reviewed_observation_count'] ?? count($reviewedObservations))),
                'data_gaps' => array_values((array)($sources['outcome_learning_runtime']['data_gaps'] ?? [])),
                'external_write_count' => 0,
            ]
            : [
                'contract_version' => OperatingOutcomeLearningRuntimeService::CONTRACT_VERSION,
                'status' => $reviewedObservations === [] ? 'missing' : 'ready',
                'reviewed_observation_count' => count($reviewedObservations),
                'data_gaps' => [],
                'external_write_count' => 0,
            ];
        $sourceErrors = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($sources['source_errors'] ?? [])
        ))));
        usort($intents, static fn(array $left, array $right): int => (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        usort($tasks, static fn(array $left, array $right): int => (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        sort($sourceErrors, SORT_STRING);
        $dailyByDate = $this->latestByDate($dailyRuns, 'business_date');
        $broadcastByDate = $this->latestByDate($broadcasts, 'business_date');
        $dailyRunRefs = array_values(array_map(
            static fn(array $row): string => 'operating_opportunity_runs#' . (int)($row['id'] ?? 0),
            $dailyByDate
        ));
        $broadcastRefs = array_values(array_map(
            static fn(array $row): string => 'ai_daily_report_broadcast_snapshots#' . (int)($row['id'] ?? 0),
            $broadcastByDate
        ));
        $missingDays = [
            'daily_priority' => array_values(array_diff($dates, array_keys($dailyByDate))),
            'trusted_broadcast' => array_values(array_diff($dates, array_keys($broadcastByDate))),
        ];
        $lifecycle = $this->lifecycleSummary($intents, $tasks);
        $gaps = $this->repeatedGaps($dailyByDate);
        $focus = $this->selectFocus(
            $gaps,
            $lifecycle,
            $missingDays,
            $dailyByDate,
            $intents,
            $tasks,
            $sourceErrors,
            $learningSummary,
            $hotelId
        );
        $focus['outcome_learning_summary'] = $learningProjection;
        $focus['outcome_learning_runtime'] = $outcomeLearningRuntime;
        $focus['learning_selection_reason'] = (string)($focus['type'] ?? '') === 'outcome_learning_review'
            ? 'eligible_pattern_selected_after_higher_priority_operating_blockers_cleared'
            : ((int)$learningProjection['pattern_candidate_count'] > 0
                ? 'eligible_pattern_retained_but_higher_priority_operating_focus_selected'
                : ((int)$learningProjection['contradictory_pattern_count'] > 0
                    ? 'contradictory_or_indeterminate_learning_cannot_affect_weekly_focus'
                    : 'insufficient_independent_reviewed_observations'));
        $status = $sourceErrors !== []
            ? 'blocked_by_source_errors'
            : (count($dailyByDate) === 7 && count($broadcastByDate) === 7
                ? 'ready'
                : ((count($dailyByDate) + count($broadcastByDate)) > 0 ? 'partial' : 'missing'));
        $generatedAt = $this->now()->format('Y-m-d H:i:s');
        $sourceIdentity = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'hotel_name' => (string)($sources['hotel_name'] ?? ('酒店 #' . $hotelId)),
            'daily_runs' => array_map(fn(array $row): array => $this->sourceRunIdentity($row), $dailyByDate),
            'broadcasts' => array_map(fn(array $row): array => $this->sourceBroadcastIdentity($row), $broadcastByDate),
            'lifecycle' => $lifecycle,
            'intents' => array_map(fn(array $row): array => $this->sourceIntentIdentity($row), $intents),
            'tasks' => array_map(fn(array $row): array => $this->sourceTaskIdentity($row), $tasks),
            'outcome_learning' => $this->outcomeLearningIdentity($learningSummary),
            'outcome_learning_runtime' => $outcomeLearningRuntime,
            'source_errors' => $sourceErrors,
        ];
        $sourceDigest = hash('sha256', $this->canonicalJson($sourceIdentity));
        $finalText = $this->renderText(
            (string)($sources['hotel_name'] ?? ('酒店 #' . $hotelId)),
            $weekStart,
            $weekEnd,
            count($dailyByDate),
            count($broadcastByDate),
            $lifecycle,
            $focus,
            $missingDays
        );
        $finalTextSha = hash('sha256', $finalText);
        $snapshotFingerprint = $this->snapshotFingerprint([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'version_no' => 0,
            'status' => $status,
            'source_digest' => $sourceDigest,
            'daily_run_refs' => $dailyRunRefs,
            'broadcast_snapshot_refs' => $broadcastRefs,
            'lifecycle_summary' => $lifecycle,
            'repeated_gap_summary' => $gaps,
            'outcome_learning_summary' => $learningProjection,
            'outcome_learning_runtime' => $outcomeLearningRuntime,
            'selected_focus' => $focus,
            'missing_days' => $missingDays,
            'final_text_sha256' => $finalTextSha,
            'generation_trigger' => $generationTrigger,
        ]);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'status' => $status,
            'daily_run_refs' => $dailyRunRefs,
            'broadcast_snapshot_refs' => $broadcastRefs,
            'daily_priority_coverage_count' => count($dailyByDate),
            'trusted_broadcast_coverage_count' => count($broadcastByDate),
            'lifecycle_summary' => $lifecycle,
            'repeated_gap_summary' => $gaps,
            'outcome_learning_summary' => $learningProjection,
            'outcome_learning_runtime' => $outcomeLearningRuntime,
            'selected_focus' => $focus,
            'missing_days' => $missingDays,
            'source_digest' => $sourceDigest,
            'snapshot_fingerprint' => $snapshotFingerprint,
            'final_text_sha256' => $finalTextSha,
            'final_text' => $finalText,
            'generation_trigger' => $generationTrigger,
            'generated_at' => $generatedAt,
            'created_by' => max(0, $createdBy),
            'external_write_count' => 0,
            'external_message_count' => 0,
            'automatic_execution' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function readSources(int $tenantId, int $hotelId, string $weekStart, string $weekEnd): array
    {
        if ($this->sourceReader !== null) {
            $loaded = call_user_func($this->sourceReader, $tenantId, $hotelId, $weekStart, $weekEnd);
            return is_array($loaded) ? $loaded : [];
        }
        $errors = [];
        $hotelName = '酒店 #' . $hotelId;
        try {
            $hotelName = trim((string)Db::name('hotels')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->value('name')) ?: $hotelName;
        } catch (\Throwable) {
            $errors[] = 'hotel_identity_unavailable';
        }
        $dailyRuns = $this->safeRows(static fn(): array => Db::name('operating_opportunity_runs')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('feature_key', 'daily_one_thing')
            ->whereBetween('business_date', [$weekStart, $weekEnd])
            ->order('business_date', 'asc')
            ->order('id', 'desc')
            ->select()->toArray(), 'daily_runs_unavailable', $errors);
        $verifiedDailyRuns = [];
        $dailyRunReader = new OperatingOpportunityLabService();
        foreach ($dailyRuns as $row) {
            $runId = (int)($row['id'] ?? 0);
            try {
                $verified = $dailyRunReader->readRun($tenantId, $hotelId, $runId);
                if (($verified['record_readback_status'] ?? '') !== 'readback_verified') {
                    throw new \RuntimeException('daily_run_readback_unverified');
                }
                $verifiedDailyRuns[] = $verified;
            } catch (\Throwable) {
                $errors[] = 'daily_run_integrity_failed_' . max(0, $runId);
            }
        }
        $dailyRuns = $verifiedDailyRuns;
        $broadcasts = $this->safeRows(static fn(): array => Db::name('ai_daily_report_broadcast_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereBetween('business_date', [$weekStart, $weekEnd])
            ->order('business_date', 'asc')
            ->order('id', 'desc')
            ->select()->toArray(), 'broadcasts_unavailable', $errors);
        $verifiedBroadcasts = [];
        $broadcastReader = new AiDailyReportBroadcastSnapshotService();
        foreach ($broadcasts as $row) {
            $snapshotId = (int)($row['id'] ?? 0);
            try {
                $verified = $broadcastReader->readExact($snapshotId, [$hotelId]);
                if (!is_array($verified)
                    || ($verified['readback_verified'] ?? false) !== true
                    || (int)($verified['tenant_id'] ?? 0) !== $tenantId
                    || (int)($verified['hotel_id'] ?? 0) !== $hotelId
                    || !$this->validDate((string)($verified['business_date'] ?? ''))
                    || (string)$verified['business_date'] < $weekStart
                    || (string)$verified['business_date'] > $weekEnd
                ) {
                    throw new \RuntimeException('broadcast_snapshot_readback_unverified');
                }
                $verifiedBroadcasts[] = $verified + ['id' => $snapshotId];
            } catch (\Throwable) {
                $errors[] = 'broadcast_snapshot_integrity_failed_' . max(0, $snapshotId);
            }
        }
        $broadcasts = $verifiedBroadcasts;
        $runIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $dailyRuns
        )));
        $intents = $runIds === [] ? [] : $this->safeRows(static fn(): array => Db::name('operation_execution_intents')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('source_module', OperatingOpportunityLabService::DAILY_SOURCE_MODULE)
            ->whereIn('source_record_id', $runIds)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()->toArray(), 'intents_unavailable', $errors);
        $intentIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $intents
        )));
        $tasks = $intentIds === [] ? [] : $this->safeRows(static fn(): array => Db::name('operation_execution_tasks')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereIn('intent_id', $intentIds)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()->toArray(), 'tasks_unavailable', $errors);
        $outcomeLearning = (new OperatingOutcomeLearningRuntimeService())->load($tenantId, $hotelId);
        $reviewedObservations = ($outcomeLearning['usable_for_tie_break'] ?? false) === true
            ? array_values((array)($outcomeLearning['reviewed_observations'] ?? []))
            : [];
        return compact('hotelName', 'dailyRuns', 'broadcasts', 'intents', 'tasks') + [
            'hotel_name' => $hotelName,
            'daily_runs' => $dailyRuns,
            'reviewed_observations' => $reviewedObservations,
            'outcome_learning_runtime' => [
                'contract_version' => OperatingOutcomeLearningRuntimeService::CONTRACT_VERSION,
                'status' => (string)($outcomeLearning['status'] ?? 'missing'),
                'reviewed_observation_count' => (int)($outcomeLearning['reviewed_observation_count'] ?? 0),
                'data_gaps' => array_values((array)($outcomeLearning['data_gaps'] ?? [])),
                'external_write_count' => 0,
            ],
            'source_errors' => $errors,
        ];
    }

    /** @return array<string,int> */
    private function lifecycleSummary(array $intents, array $tasks): array
    {
        $summary = [
            'pending_approval' => 0,
            'approved_or_executing' => 0,
            'review_pending' => 0,
            'reviewed' => 0,
            'blocked' => 0,
        ];
        foreach ($intents as $intent) {
            $status = strtolower(trim((string)($intent['status'] ?? '')));
            if ($status === 'pending_approval') $summary['pending_approval']++;
            elseif (in_array($status, ['approved', 'executing'], true)) $summary['approved_or_executing']++;
            elseif (in_array($status, ['blocked', 'rejected', 'cancelled'], true)) $summary['blocked']++;
        }
        foreach ($tasks as $task) {
            $result = strtolower(trim((string)($task['result_status'] ?? '')));
            if (in_array($result, ['success', 'near_success', 'failed'], true)) $summary['reviewed']++;
            elseif ($this->taskNeedsReview($task)) $summary['review_pending']++;
        }
        return $summary;
    }

    /** @return list<array<string,mixed>> */
    private function repeatedGaps(array $dailyByDate): array
    {
        $counts = [];
        foreach ($dailyByDate as $date => $run) {
            $selected = (array)($run['result']['selected'] ?? []);
            foreach (array_values((array)($selected['source']['gap_codes'] ?? [])) as $gap) {
                $gap = strtolower(trim((string)$gap));
                if ($gap === '') continue;
                $counts[$gap] ??= ['gap_code' => $gap, 'count' => 0, 'dates' => []];
                $counts[$gap]['count']++;
                $counts[$gap]['dates'][] = $date;
            }
        }
        $rows = array_values($counts);
        usort($rows, static fn(array $left, array $right): int => [
            -(int)$left['count'], (string)$left['gap_code'],
        ] <=> [-(int)$right['count'], (string)$right['gap_code']]);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function selectFocus(
        array $gaps,
        array $lifecycle,
        array $missingDays,
        array $dailyByDate,
        array $intents,
        array $tasks,
        array $sourceErrors,
        array $learningSummary,
        int $hotelId
    ): array {
        if ($sourceErrors !== []) {
            return [
                'type' => 'lifecycle_source_unavailable',
                'key' => 'weekly_source_readback_blocked',
                'title' => '修复周计划来源读取后再确认下周重点',
                'reason' => '待审批与复盘状态读取不完整，当前不能声称没有积压事项。',
                'evidence_refs' => array_map(
                    static fn(string $error): string => 'source_error#' . $error,
                    $sourceErrors
                ),
            ];
        }
        $repeated = array_values(array_filter($gaps, static fn(array $row): bool => (int)$row['count'] >= 2));
        if ($repeated !== []) {
            $gap = $repeated[0];
            return [
                'type' => 'repeated_data_gap',
                'key' => (string)$gap['gap_code'],
                'title' => $this->gapLabel((string)$gap['gap_code']),
                'reason' => '该缺口在本周重复出现 ' . (int)$gap['count'] . ' 天，应先关闭事实链。',
                'evidence_refs' => array_values(array_map(
                    static fn(string $date): string => 'business_date#' . $date,
                    (array)$gap['dates']
                )),
            ];
        }
        if ((int)$lifecycle['pending_approval'] > 0) {
            $intent = array_values(array_filter($intents, static fn(array $row): bool => (string)($row['status'] ?? '') === 'pending_approval'))[0] ?? [];
            return [
                'type' => 'oldest_pending_approval',
                'key' => 'pending_approval',
                'title' => '处理本周最早的待审批事项',
                'reason' => '仍有 ' . (int)$lifecycle['pending_approval'] . ' 项等待人工决定。',
                'evidence_refs' => [(int)($intent['id'] ?? 0) > 0 ? 'operation_execution_intents#' . (int)$intent['id'] : ''],
            ];
        }
        if ((int)$lifecycle['review_pending'] > 0) {
            $task = array_values(array_filter($tasks, fn(array $row): bool => $this->taskNeedsReview($row)))[0] ?? [];
            return [
                'type' => 'review_pending',
                'key' => 'review_pending',
                'title' => '补齐已执行事项的同口径复盘',
                'reason' => '仍有 ' . (int)$lifecycle['review_pending'] . ' 项已执行但尚未完成人工终审。',
                'evidence_refs' => [(int)($task['id'] ?? 0) > 0 ? 'operation_execution_tasks#' . (int)$task['id'] : ''],
            ];
        }
        $missing = array_values(array_unique(array_merge(
            (array)$missingDays['daily_priority'],
            (array)$missingDays['trusted_broadcast']
        )));
        sort($missing);
        if ($missing !== []) {
            return [
                'type' => 'coverage_gap',
                'key' => 'weekly_operating_coverage_missing',
                'title' => '补齐周度自动计划与可信播报覆盖',
                'reason' => '本周有 ' . count($missing) . ' 个日期缺少自动产物。',
                'evidence_refs' => array_map(static fn(string $date): string => 'business_date#' . $date, $missing),
            ];
        }
        $patterns = array_values(array_filter(
            (array)($learningSummary['items'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && (int)($item['scope']['system_hotel_id'] ?? 0) === $hotelId
                && (string)($item['status'] ?? '') === 'pattern_candidate'
                && (string)($item['learning_stage'] ?? '') === 'pattern_candidate'
                && ($item['outcome_tie_break_eligible'] ?? false) === true
                && ($item['causality_claimed'] ?? true) === false
                && ($item['candidate_sop_eligible'] ?? true) === false
                && (int)($item['sample_count'] ?? 0) >= max(3, (int)($item['minimum_samples'] ?? 3))
                && (int)($item['contradicted_count'] ?? -1) === 0
                && (int)($item['not_declared_count'] ?? -1) === 0
        ));
        usort($patterns, static fn(array $left, array $right): int => [
            -(int)($left['sample_count'] ?? 0),
            (string)($left['pattern_key'] ?? ''),
        ] <=> [
            -(int)($right['sample_count'] ?? 0),
            (string)($right['pattern_key'] ?? ''),
        ]);
        if ($patterns !== []) {
            $pattern = $patterns[0];
            return [
                'type' => 'outcome_learning_review',
                'key' => (string)($pattern['pattern_key'] ?? ''),
                'title' => '人工复核已重复验证的经营动作模式',
                'reason' => '已有 ' . (int)$pattern['sample_count']
                    . ' 个独立、同范围且无反例的复盘样本；仅把该模式列为下周人工复核重点，不自动晋级SOP。',
                'evidence_refs' => array_values((array)($pattern['evidence_refs'] ?? [])),
                'pattern' => [
                    'pattern_key' => (string)($pattern['pattern_key'] ?? ''),
                    'comparison_key' => (string)($pattern['comparison_key'] ?? ''),
                    'action_type' => (string)($pattern['action_type'] ?? ''),
                    'expected_direction' => (string)($pattern['expected_direction'] ?? ''),
                    'sample_count' => (int)($pattern['sample_count'] ?? 0),
                    'causality_claimed' => false,
                    'automatic_sop_promotion' => false,
                    'human_review_required' => true,
                ],
            ];
        }
        return [
            'type' => 'workflow_improvement',
            'key' => 'weekly_loop_closed',
            'title' => '保持闭环并选择一个低成本流程优化',
            'reason' => '本周每日计划和可信播报均已覆盖，且没有积压事项。',
            'evidence_refs' => array_values(array_map(
                static fn(array $row): string => 'operating_opportunity_runs#' . (int)($row['id'] ?? 0),
                $dailyByDate
            )),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeStored(array $row, bool $created, bool $replayed): array
    {
        $selectedFocus = $this->decode($row['selected_focus_json'] ?? '{}');
        $normalized = [
            'contract_version' => (string)($row['contract_version'] ?? 'weekly_operating_plan.v1'),
            'snapshot_id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'week_start' => (string)($row['week_start'] ?? ''),
            'week_end' => (string)($row['week_end'] ?? ''),
            'version_no' => (int)($row['version_no'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'source_digest' => strtolower((string)($row['source_digest'] ?? '')),
            'snapshot_fingerprint' => strtolower((string)($row['snapshot_fingerprint'] ?? '')),
            'final_text_sha256' => strtolower((string)($row['final_text_sha256'] ?? '')),
            'daily_run_refs' => $this->decode($row['daily_run_refs_json'] ?? '[]'),
            'broadcast_snapshot_refs' => $this->decode($row['broadcast_snapshot_refs_json'] ?? '[]'),
            'lifecycle_summary' => $this->decode($row['lifecycle_summary_json'] ?? '{}'),
            'repeated_gap_summary' => $this->decode($row['repeated_gap_summary_json'] ?? '[]'),
            'selected_focus' => $selectedFocus,
            'outcome_learning_summary' => is_array($selectedFocus['outcome_learning_summary'] ?? null)
                ? $selectedFocus['outcome_learning_summary']
                : $this->compactOutcomeLearningSummary([]),
            'outcome_learning_runtime' => is_array($selectedFocus['outcome_learning_runtime'] ?? null)
                ? $selectedFocus['outcome_learning_runtime']
                : [
                    'contract_version' => OperatingOutcomeLearningRuntimeService::CONTRACT_VERSION,
                    'status' => 'missing',
                    'reviewed_observation_count' => 0,
                    'data_gaps' => [],
                    'external_write_count' => 0,
                ],
            'missing_days' => $this->decode($row['missing_days_json'] ?? '{}'),
            'final_text' => (string)($row['final_text'] ?? ''),
            'generation_trigger' => (string)($row['generation_trigger'] ?? ''),
            'generated_at' => (string)($row['generated_at'] ?? ''),
            'created' => $created,
            'idempotent_replay' => $replayed,
            'readback_verified' => true,
            'external_write_count' => 0,
            'external_message_count' => 0,
        ];
        $expectedSnapshotFingerprint = $normalized['contract_version'] === 'weekly_operating_plan.v1'
            ? hash('sha256', $this->canonicalJson([
                'source_digest' => $normalized['source_digest'],
                'status' => $normalized['status'],
                'selected_focus' => $normalized['selected_focus'],
                'missing_days' => $normalized['missing_days'],
                'final_text_sha256' => $normalized['final_text_sha256'],
            ]))
            : $this->snapshotFingerprint($normalized, $normalized['contract_version']);
        if ($normalized['snapshot_id'] <= 0
            || !in_array($normalized['contract_version'], ['weekly_operating_plan.v1', self::CONTRACT_VERSION], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $normalized['source_digest'])
            || !preg_match('/^[a-f0-9]{64}$/D', $normalized['snapshot_fingerprint'])
            || !hash_equals($normalized['snapshot_fingerprint'], $expectedSnapshotFingerprint)
            || !hash_equals($normalized['final_text_sha256'], hash('sha256', $normalized['final_text']))
            || !is_array($normalized['selected_focus'])
        ) {
            throw new \RuntimeException('weekly_plan_snapshot_readback_failed');
        }
        return $normalized;
    }

    private function readSnapshot(string $action, array $scope): mixed
    {
        if ($this->snapshotReader !== null) {
            return call_user_func($this->snapshotReader, $action, $scope);
        }
        if ($action === 'next_version') {
            return (int)Db::name(self::TABLE)
                ->where('tenant_id', $scope['tenant_id'])
                ->where('hotel_id', $scope['hotel_id'])
                ->where('week_start', $scope['week_start'])
                ->where('week_end', $scope['week_end'])
                ->max('version_no') + 1;
        }
        $query = Db::name(self::TABLE);
        if ($action === 'exact') return $query->where('id', $scope['id'])->find();
        foreach (['tenant_id', 'hotel_id', 'week_start', 'week_end'] as $field) {
            $query->where($field, $scope[$field]);
        }
        if ($action === 'by_source') {
            $query->where('generation_trigger', $scope['generation_trigger'])
                ->where('source_digest', $scope['source_digest']);
        }
        return $query->order('id', 'desc')->find();
    }

    private function writeSnapshot(array $row): int
    {
        if ($this->snapshotWriter !== null) {
            return (int)call_user_func($this->snapshotWriter, $row);
        }
        return (int)Db::name(self::TABLE)->insertGetId($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function persistAndVerifySnapshot(array $row): array
    {
        $operation = function () use ($row): array {
            $id = $this->writeSnapshot($row);
            if ($id <= 0) {
                throw new \RuntimeException('weekly_plan_snapshot_save_failed');
            }
            $stored = $this->readSnapshot('exact', ['id' => $id]);
            if (!is_array($stored)) {
                throw new \RuntimeException('weekly_plan_snapshot_readback_failed');
            }
            return $this->normalizeStored($stored, true, false);
        };
        if ($this->snapshotTransaction !== null) {
            return (array)call_user_func($this->snapshotTransaction, $operation);
        }
        if ($this->snapshotReader !== null || $this->snapshotWriter !== null) {
            return $operation();
        }
        return Db::transaction($operation);
    }

    private function renderText(
        string $hotelName,
        string $weekStart,
        string $weekEnd,
        int $dailyCoverage,
        int $broadcastCoverage,
        array $lifecycle,
        array $focus,
        array $missingDays
    ): string {
        $missing = count(array_unique(array_merge(
            (array)$missingDays['daily_priority'],
            (array)$missingDays['trusted_broadcast']
        )));
        return implode("\n", [
            '宿析OS周度经营计划',
            '门店：' . $hotelName,
            '周期：' . $weekStart . ' 至 ' . $weekEnd,
            '自动事项覆盖：' . $dailyCoverage . '/7；可信播报覆盖：' . $broadcastCoverage . '/7。',
            '待审批：' . (int)$lifecycle['pending_approval']
                . '；待复盘：' . (int)$lifecycle['review_pending']
                . '；已复盘：' . (int)$lifecycle['reviewed'] . '。',
            '下周唯一重点：' . (string)($focus['title'] ?? '等待可确认事项'),
            '选择依据：' . (string)($focus['reason'] ?? '当前证据不足。'),
            '效果学习：' . $this->renderOutcomeLearning((array)($focus['outcome_learning_summary'] ?? []))
                . '；选择关系：' . (string)($focus['learning_selection_reason'] ?? 'insufficient_independent_reviewed_observations') . '。',
            '缺失日期数：' . $missing . '。缺失保持缺失，不以0或旧报告补齐。',
            '边界：本计划只整理已保存事实和流程状态，不自动审批、执行、发送消息或写入OTA/PMS。',
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function latestByDate(array $rows, string $dateField): array
    {
        $result = [];
        foreach ($rows as $row) {
            $date = substr(trim((string)($row[$dateField] ?? '')), 0, 10);
            if (!$this->validDate($date)) continue;
            if (!isset($result[$date]) || (int)($row['id'] ?? 0) > (int)($result[$date]['id'] ?? 0)) {
                $result[$date] = $row;
            }
        }
        ksort($result);
        return $result;
    }

    private function sourceRunIdentity(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'business_date' => (string)($row['business_date'] ?? ''),
            'input_digest' => (string)($row['input_digest'] ?? ''),
            'result_digest' => (string)($row['result_digest'] ?? ''),
        ];
    }

    private function sourceBroadcastIdentity(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'business_date' => (string)($row['business_date'] ?? ''),
            'facts_fingerprint' => (string)($row['facts_fingerprint'] ?? ''),
            'snapshot_fingerprint' => (string)($row['snapshot_fingerprint'] ?? ''),
        ];
    }

    private function sourceIntentIdentity(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'source_record_id' => (int)($row['source_record_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'approval_status' => (string)($row['approval_status'] ?? ''),
            'target_digest' => (string)($row['target_digest'] ?? ''),
            'evidence_digest' => (string)($row['evidence_digest'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['update_time'] ?? ''),
        ];
    }

    private function sourceTaskIdentity(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'intent_id' => (int)($row['intent_id'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'result_status' => (string)($row['result_status'] ?? ''),
            'executed_at' => (string)($row['executed_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['update_time'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function compactOutcomeLearningSummary(array $summary): array
    {
        return [
            'contract_version' => 'weekly_operating_outcome_learning_summary.v1',
            'status' => (string)($summary['status'] ?? 'missing'),
            'reviewed_observation_count' => max(0, (int)($summary['reviewed_observation_count'] ?? 0)),
            'rejected_review_count' => max(0, (int)($summary['rejected_review_count'] ?? 0)),
            'duplicate_review_count' => max(0, (int)($summary['duplicate_review_count'] ?? 0)),
            'indeterminate_review_count' => max(0, (int)($summary['indeterminate_review_count'] ?? 0)),
            'pattern_candidate_count' => max(0, (int)($summary['pattern_candidate_count'] ?? 0)),
            'outcome_tie_break_candidate_count' => max(
                0,
                (int)($summary['outcome_tie_break_candidate_count'] ?? 0)
            ),
            'contradictory_pattern_count' => max(0, (int)($summary['contradictory_pattern_count'] ?? 0)),
            'causality_claimed' => false,
            'automatic_sop_promotion' => false,
            'human_approval_required' => true,
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function outcomeLearningIdentity(array $summary): array
    {
        $items = [];
        foreach ((array)($summary['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $refs = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($item['evidence_refs'] ?? [])
            ))));
            sort($refs, SORT_STRING);
            $items[] = [
                'pattern_key' => (string)($item['pattern_key'] ?? ''),
                'comparison_key' => (string)($item['comparison_key'] ?? ''),
                'action_type' => (string)($item['action_type'] ?? ''),
                'expected_direction' => (string)($item['expected_direction'] ?? ''),
                'status' => (string)($item['status'] ?? ''),
                'sample_count' => max(0, (int)($item['sample_count'] ?? 0)),
                'aligned_count' => max(0, (int)($item['aligned_count'] ?? 0)),
                'contradicted_count' => max(0, (int)($item['contradicted_count'] ?? 0)),
                'not_declared_count' => max(0, (int)($item['not_declared_count'] ?? 0)),
                'last_reviewed_at' => (string)($item['last_reviewed_at'] ?? ''),
                'evidence_refs' => $refs,
            ];
        }
        usort($items, static fn(array $left, array $right): int => strcmp(
            (string)$left['pattern_key'],
            (string)$right['pattern_key']
        ));
        return $this->compactOutcomeLearningSummary($summary) + ['items' => $items];
    }

    /** @param array<string,mixed> $summary */
    private function renderOutcomeLearning(array $summary): string
    {
        $patterns = max(0, (int)($summary['pattern_candidate_count'] ?? 0));
        $contradictions = max(0, (int)($summary['contradictory_pattern_count'] ?? 0));
        $reviews = max(0, (int)($summary['reviewed_observation_count'] ?? 0));
        if ($patterns > 0) {
            return '已形成 ' . $patterns . ' 个待人工复核模式候选（' . $reviews . ' 个独立复盘观察）';
        }
        if ($contradictions > 0) {
            return '存在反例或冲突模式，未用于排序或SOP晋级';
        }
        return '独立同范围复盘样本不足，未用于排序';
    }

    private function taskNeedsReview(array $task): bool
    {
        return strtolower(trim((string)($task['status'] ?? ''))) === 'executed'
            && !in_array(
                strtolower(trim((string)($task['result_status'] ?? ''))),
                ['success', 'near_success', 'failed'],
                true
            );
    }

    private function gapLabel(string $gap): string
    {
        return match ($gap) {
            'ctrip_target_date_source_rows_missing' => '补齐携程目标日期可信事实',
            'meituan_target_date_source_rows_missing' => '补齐美团目标日期可信事实',
            default => '关闭重复数据缺口：' . $gap,
        };
    }

    /** @return array{0:string,1:string} */
    private function week(string $weekEnd): array
    {
        if (!$this->validDate($weekEnd)) throw new \InvalidArgumentException('weekly_plan_week_end_invalid');
        $end = new DateTimeImmutable($weekEnd);
        return [$end->modify('-6 days')->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** @return list<string> */
    private function dateRange(string $start, string $end): array
    {
        $rows = [];
        $cursor = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
        while ($cursor <= $last) {
            $rows[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        return $rows;
    }

    private function assertScope(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new \InvalidArgumentException('weekly_plan_scope_invalid');
        }
        if ($this->scopeVerifier !== null) {
            if (call_user_func($this->scopeVerifier, $tenantId, $hotelId) !== true) {
                throw new \RuntimeException('weekly_plan_hotel_scope_unavailable');
            }
            return;
        }
        try {
            $verified = Db::name('hotels')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->where('status', 1)
                ->count() === 1;
        } catch (\Throwable) {
            $verified = false;
        }
        if (!$verified) {
            throw new \RuntimeException('weekly_plan_hotel_scope_unavailable');
        }
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === trim($value);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null
            ? call_user_func($this->clock)
            : new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalJson(mixed $value): string
    {
        return $this->encode($this->canonical($value));
    }

    /** @param array<string,mixed> $snapshot */
    private function snapshotFingerprint(array $snapshot, string $contractVersion = self::CONTRACT_VERSION): string
    {
        return hash('sha256', $this->canonicalJson([
            'contract_version' => $contractVersion,
            'tenant_id' => (int)($snapshot['tenant_id'] ?? 0),
            'hotel_id' => (int)($snapshot['hotel_id'] ?? 0),
            'week_start' => (string)($snapshot['week_start'] ?? ''),
            'week_end' => (string)($snapshot['week_end'] ?? ''),
            'version_no' => (int)($snapshot['version_no'] ?? 0),
            'status' => (string)($snapshot['status'] ?? ''),
            'source_digest' => (string)($snapshot['source_digest'] ?? ''),
            'daily_run_refs' => (array)($snapshot['daily_run_refs'] ?? []),
            'broadcast_snapshot_refs' => (array)($snapshot['broadcast_snapshot_refs'] ?? []),
            'lifecycle_summary' => (array)($snapshot['lifecycle_summary'] ?? []),
            'repeated_gap_summary' => (array)($snapshot['repeated_gap_summary'] ?? []),
            'selected_focus' => (array)($snapshot['selected_focus'] ?? []),
            'missing_days' => (array)($snapshot['missing_days'] ?? []),
            'final_text_sha256' => (string)($snapshot['final_text_sha256'] ?? ''),
            'generation_trigger' => (string)($snapshot['generation_trigger'] ?? ''),
        ]));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->canonical($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as &$item) $item = $this->canonical($item);
        unset($item);
        return $value;
    }

    /** @param callable():array $reader @param list<string> $errors @return list<array<string,mixed>> */
    private function safeRows(callable $reader, string $errorCode, array &$errors): array
    {
        try {
            return array_values(array_filter($reader(), 'is_array'));
        } catch (\Throwable) {
            $errors[] = $errorCode;
            return [];
        }
    }
}
