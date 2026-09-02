<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Stores immutable AI-suggestion evidence and produces descriptive,
 * non-causal calibration summaries. This service never invokes a model,
 * activates a candidate strategy, or writes an OTA/PMS/business table.
 */
final class AiSuggestionCalibrationService
{
    private const MAX_DAILY_RANKING_SNAPSHOT_SCAN = 5000;
    private const SNAPSHOT_TABLE = 'ai_suggestion_calibration_snapshots';
    private const FEEDBACK_TABLE = 'ai_suggestion_calibration_feedback_events';
    private const OBSERVATION_TABLE = 'ai_suggestion_calibration_observation_events';
    private const COMPARISON_TABLE = 'ai_suggestion_strategy_comparisons';
    private const MINIMUM_RANKING_SAMPLES = 20;

    private const FEEDBACK_STATUSES = [
        'accepted',
        'modified',
        'rejected',
        'deferred',
        'needs_more_evidence',
    ];
    private const EXECUTION_STATUSES = ['executed', 'not_executed'];
    private const REVIEW_RESULTS = ['supported', 'contradicted', 'indeterminate'];
    private const COMPARISON_MODES = ['offline', 'shadow'];

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone(date_default_timezone_get() ?: 'Asia/Shanghai')
            );
    }

    /**
     * Freezes the exact tenant/user/hotel/scenario/source/version/evidence
     * identity before feedback can be appended.
     *
     * @return array<string,mixed>
     */
    public function freezeSuggestion(array $input): array
    {
        $scope = $this->scope($input);
        $suggestionKey = $this->requiredText($input['suggestion_key'] ?? null, 120, 'suggestion_key');
        $scenario = $this->requiredText($input['scenario'] ?? null, 120, 'scenario');
        $sourceKey = $this->requiredText($input['source_key'] ?? $input['source'] ?? null, 120, 'source_key');
        $sourceVersion = $this->requiredText(
            $input['source_version'] ?? $input['version'] ?? null,
            120,
            'source_version'
        );
        $evidenceDigest = $this->sha256($input['evidence_digest'] ?? null, 'evidence_digest');
        $payload = $this->arrayInput(
            $input['suggestion_payload'] ?? $input['payload'] ?? null,
            'suggestion_payload',
            true
        );
        $confidence = $this->confidence($input['confidence'] ?? null);
        $idempotencyHash = $this->idempotencyHash($input['idempotency_key'] ?? null);
        $this->assertNoSensitiveMaterial($payload, 'suggestion_payload');

        $identityDigest = $this->digest([
            'tenant_id' => $scope['tenant_id'],
            'user_id' => $scope['user_id'],
            'hotel_id' => $scope['hotel_id'],
            'suggestion_key' => $suggestionKey,
            'scenario' => $scenario,
            'source_key' => $sourceKey,
            'source_version' => $sourceVersion,
            'evidence_digest' => $evidenceDigest,
        ]);
        $contentDigest = $this->digest([
            'identity_digest' => $identityDigest,
            'suggestion_payload' => $payload,
            'confidence' => $confidence,
        ]);

        $existing = $this->findExistingSuggestion(
            $scope,
            $suggestionKey,
            $identityDigest,
            $idempotencyHash
        );
        if (is_array($existing)) {
            return $this->verifiedSuggestionReplay($existing, $identityDigest, $contentDigest);
        }

        try {
            return Db::transaction(function () use (
                $scope,
                $suggestionKey,
                $scenario,
                $sourceKey,
                $sourceVersion,
                $evidenceDigest,
                $identityDigest,
                $payload,
                $confidence,
                $contentDigest,
                $idempotencyHash
            ): array {
                $createdId = (int)Db::name(self::SNAPSHOT_TABLE)->insertGetId([
                    ...$scope,
                    'suggestion_key' => $suggestionKey,
                    'scenario' => $scenario,
                    'source_key' => $sourceKey,
                    'source_version' => $sourceVersion,
                    'evidence_digest' => $evidenceDigest,
                    'identity_digest' => $identityDigest,
                    'suggestion_payload_json' => $this->canonicalJson($payload),
                    'confidence' => $confidence,
                    'content_digest' => $contentDigest,
                    'idempotency_hash' => $idempotencyHash,
                    'created_at' => $this->now(),
                ]);

                $row = $this->suggestionById($scope, $createdId);
                if (!is_array($row)) {
                    throw new RuntimeException('AI suggestion snapshot exact readback failed');
                }

                $result = $this->normalizeSuggestion($row);
                if (($result['readback_verified'] ?? false) !== true) {
                    throw new RuntimeException('AI suggestion snapshot integrity verification failed');
                }
                $result['created'] = true;
                $result['idempotent_replay'] = false;
                return $result;
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $row = $this->findExistingSuggestion($scope, $suggestionKey, $identityDigest, $idempotencyHash);
        if (!is_array($row)) {
            throw new RuntimeException('AI suggestion snapshot exact readback failed');
        }
        return $this->verifiedSuggestionReplay($row, $identityDigest, $contentDigest);
    }

    /** @return array<string,mixed> */
    public function appendFeedback(array $input): array
    {
        $scope = $this->scope($input);
        $suggestion = $this->requiredSuggestion(
            $scope,
            $this->requiredText($input['suggestion_key'] ?? null, 120, 'suggestion_key')
        );
        $status = $this->enum(
            $input['feedback_status'] ?? null,
            self::FEEDBACK_STATUSES,
            'feedback_status'
        );
        $idempotencyHash = $this->idempotencyHash($input['idempotency_key'] ?? null);
        $reasonCode = $this->optionalText($input['reason_code'] ?? null, 100);
        $reasonNote = $this->optionalText($input['reason_note'] ?? null, 1000);
        $payload = $this->arrayInput($input['feedback_payload'] ?? [], 'feedback_payload');
        $this->assertNoSensitiveMaterial([$reasonCode, $reasonNote, $payload], 'feedback');
        $contentDigest = $this->digest([
            'suggestion_identity_digest' => $suggestion['identity_digest'],
            'feedback_status' => $status,
            'reason_code' => $reasonCode,
            'reason_note' => $reasonNote,
            'feedback_payload' => $payload,
        ]);

        $existing = $this->feedbackByIdempotency($scope, (int)$suggestion['id'], $idempotencyHash);
        if (is_array($existing)) {
            return $this->verifiedFeedbackReplay($existing, $contentDigest);
        }

        try {
            return Db::transaction(function () use (
                $scope,
                $suggestion,
                $idempotencyHash,
                $status,
                $reasonCode,
                $reasonNote,
                $payload,
                $contentDigest
            ): array {
                $createdId = (int)Db::name(self::FEEDBACK_TABLE)->insertGetId([
                    'suggestion_id' => (int)$suggestion['id'],
                    ...$scope,
                    'suggestion_identity_digest' => (string)$suggestion['identity_digest'],
                    'idempotency_hash' => $idempotencyHash,
                    'feedback_status' => $status,
                    'reason_code' => $reasonCode,
                    'reason_note' => $reasonNote,
                    'feedback_payload_json' => $this->canonicalJson($payload),
                    'content_digest' => $contentDigest,
                    'created_at' => $this->now(),
                ]);

                $row = $this->feedbackById($scope, (int)$suggestion['id'], $createdId);
                if (!is_array($row)) {
                    throw new RuntimeException('AI suggestion feedback exact readback failed');
                }

                $result = $this->normalizeFeedback($row);
                if (($result['readback_verified'] ?? false) !== true) {
                    throw new RuntimeException('AI suggestion feedback integrity verification failed');
                }
                $result['created'] = true;
                $result['idempotent_replay'] = false;
                return $result;
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $row = $this->feedbackByIdempotency($scope, (int)$suggestion['id'], $idempotencyHash);
        if (!is_array($row)) {
            throw new RuntimeException('AI suggestion feedback exact readback failed');
        }
        return $this->verifiedFeedbackReplay($row, $contentDigest);
    }

    /**
     * Appends an optional execution observation and/or a bounded review. Review
     * values are observations only: supported does not mean the AI caused the
     * later result.
     *
     * @return array<string,mixed>
     */
    public function appendExecutionReview(array $input): array
    {
        $scope = $this->scope($input);
        $suggestion = $this->requiredSuggestion(
            $scope,
            $this->requiredText($input['suggestion_key'] ?? null, 120, 'suggestion_key')
        );
        $idempotencyHash = $this->idempotencyHash($input['idempotency_key'] ?? null);
        $existing = $this->observationByIdempotency(
            $scope,
            (int)$suggestion['id'],
            $idempotencyHash
        );
        $executionStatus = $this->nullableEnum(
            $input['execution_status'] ?? null,
            self::EXECUTION_STATUSES,
            'execution_status'
        );
        $reviewResult = $this->nullableEnum(
            $input['review_result'] ?? null,
            self::REVIEW_RESULTS,
            'review_result'
        );
        if ($executionStatus === null && $reviewResult === null) {
            throw new InvalidArgumentException('execution_status or review_result is required');
        }
        if ($executionStatus === 'not_executed' && $reviewResult !== null) {
            throw new InvalidArgumentException('not_executed observation cannot include a review result');
        }

        $evidenceDigest = $this->optionalSha256($input['evidence_digest'] ?? null, 'evidence_digest');
        if ($reviewResult !== null && $evidenceDigest === null) {
            throw new InvalidArgumentException('review_result requires an evidence_digest');
        }
        $payload = $this->arrayInput($input['evidence_payload'] ?? [], 'evidence_payload');
        $this->assertNoSensitiveMaterial($payload, 'evidence_payload');
        $observedAt = array_key_exists('observed_at', $input)
            ? $this->dateTime($input['observed_at'], 'observed_at')
            : (is_array($existing)
                ? $this->dateTime($existing['observed_at'] ?? null, 'observed_at')
                : $this->now());
        $contentDigest = $this->digest([
            'suggestion_identity_digest' => $suggestion['identity_digest'],
            'execution_status' => $executionStatus,
            'review_result' => $reviewResult,
            'observed_at' => $observedAt,
            'evidence_digest' => $evidenceDigest,
            'evidence_payload' => $payload,
            'causal_claim' => 'none',
        ]);

        if (is_array($existing)) {
            return $this->verifiedObservationReplay($existing, $contentDigest);
        }

        try {
            return Db::transaction(function () use (
                $scope,
                $suggestion,
                $idempotencyHash,
                $executionStatus,
                $reviewResult,
                $observedAt,
                $evidenceDigest,
                $payload,
                $contentDigest
            ): array {
                $createdId = (int)Db::name(self::OBSERVATION_TABLE)->insertGetId([
                    'suggestion_id' => (int)$suggestion['id'],
                    ...$scope,
                    'suggestion_identity_digest' => (string)$suggestion['identity_digest'],
                    'idempotency_hash' => $idempotencyHash,
                    'execution_status' => $executionStatus,
                    'review_result' => $reviewResult,
                    'observed_at' => $observedAt,
                    'evidence_digest' => $evidenceDigest,
                    'evidence_payload_json' => $this->canonicalJson($payload),
                    'content_digest' => $contentDigest,
                    'causal_claim' => 'none',
                    'created_at' => $this->now(),
                ]);

                $row = $this->observationById($scope, (int)$suggestion['id'], $createdId);
                if (!is_array($row)) {
                    throw new RuntimeException('AI suggestion observation exact readback failed');
                }

                $result = $this->normalizeObservation($row);
                if (($result['readback_verified'] ?? false) !== true) {
                    throw new RuntimeException('AI suggestion observation integrity verification failed');
                }
                $result['created'] = true;
                $result['idempotent_replay'] = false;
                return $result;
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $row = $this->observationByIdempotency($scope, (int)$suggestion['id'], $idempotencyHash);
        if (!is_array($row)) {
            throw new RuntimeException('AI suggestion observation exact readback failed');
        }
        return $this->verifiedObservationReplay($row, $contentDigest);
    }

    /**
     * Reads one exact user/hotel-scoped frozen suggestion and its append-only
     * event history. A scope mismatch returns null instead of falling back.
     *
     * @return array<string,mixed>|null
     */
    public function readExact(
        int $tenantId,
        int $userId,
        int $hotelId,
        string $suggestionKey
    ): ?array {
        $scope = $this->scope([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
        ]);
        $row = $this->suggestionByKey(
            $scope,
            $this->requiredText($suggestionKey, 120, 'suggestion_key')
        );
        if (!is_array($row)) {
            return null;
        }

        $suggestion = $this->normalizeSuggestion($row);
        if (($suggestion['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI suggestion snapshot integrity verification failed');
        }
        $suggestionId = (int)$suggestion['id'];
        $feedback = Db::name(self::FEEDBACK_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $observations = Db::name(self::OBSERVATION_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $suggestion['feedback_events'] = array_map(
            fn(array $event): array => $this->normalizeFeedback($event),
            $feedback
        );
        $suggestion['observation_events'] = array_map(
            fn(array $event): array => $this->normalizeObservation($event),
            $observations
        );
        return $suggestion;
    }

    /**
     * Descriptive metrics only. Rates expose their denominators and use null
     * when a denominator is absent, so missing evidence is never zero-filled.
     *
     * @return array<string,mixed>
     */
    public function summarize(array $scopeInput, array $options = []): array
    {
        $scope = $this->scope($scopeInput);
        $minimumSamples = $this->boundedInt($options['minimum_samples'] ?? 20, 1, 10000, 'minimum_samples');
        $tolerance = $this->boundedFloat(
            $options['calibration_tolerance'] ?? 0.10,
            0.0,
            1.0,
            'calibration_tolerance'
        );
        $scenario = $this->optionalText($options['scenario'] ?? null, 120);
        $sourceKey = $this->optionalText($options['source_key'] ?? $options['source'] ?? null, 120);
        $sourceVersion = $this->optionalText(
            $options['source_version'] ?? $options['version'] ?? null,
            120
        );

        $query = Db::name(self::SNAPSHOT_TABLE)->where($scope);
        if ($scenario !== '') {
            $query->where('scenario', $scenario);
        }
        if ($sourceKey !== '') {
            $query->where('source_key', $sourceKey);
        }
        if ($sourceVersion !== '') {
            $query->where('source_version', $sourceVersion);
        }
        $suggestions = $query->order('id', 'asc')->select()->toArray();
        $suggestionIds = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            $suggestions
        ));

        $feedbackRows = [];
        $observationRows = [];
        if ($suggestionIds !== []) {
            $feedbackRows = Db::name(self::FEEDBACK_TABLE)
                ->where($scope)
                ->whereIn('suggestion_id', $suggestionIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            $observationRows = Db::name(self::OBSERVATION_TABLE)
                ->where($scope)
                ->whereIn('suggestion_id', $suggestionIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        }

        $latestFeedback = [];
        foreach ($feedbackRows as $row) {
            $latestFeedback[(int)$row['suggestion_id']] = $row;
        }
        $executed = [];
        $latestReview = [];
        foreach ($observationRows as $row) {
            $suggestionId = (int)$row['suggestion_id'];
            if (($row['execution_status'] ?? null) === 'executed') {
                $executed[$suggestionId] = true;
            }
            if (trim((string)($row['review_result'] ?? '')) !== '') {
                $latestReview[$suggestionId] = $row;
            }
        }

        $feedbackCounts = array_fill_keys(self::FEEDBACK_STATUSES, 0);
        foreach ($latestFeedback as $row) {
            $status = (string)($row['feedback_status'] ?? '');
            if (array_key_exists($status, $feedbackCounts)) {
                $feedbackCounts[$status]++;
            }
        }

        $totalSuggestions = count($suggestions);
        $feedbackSampleCount = count($latestFeedback);
        $executedCount = count($executed);
        $observableReviewCount = count(array_intersect_key($latestReview, $executed));
        $calibration = $this->confidenceCalibration(
            $suggestions,
            $latestReview,
            $minimumSamples,
            $tolerance
        );
        $feedbackRanking = $this->feedbackRanking(
            $suggestions,
            $latestFeedback,
            max(
                self::MINIMUM_RANKING_SAMPLES,
                $this->boundedInt(
                    $options['ranking_minimum_samples'] ?? self::MINIMUM_RANKING_SAMPLES,
                    1,
                    1000,
                    'ranking_minimum_samples'
                )
            )
        );

        return [
            'scope' => $scope,
            'filters' => [
                'scenario' => $scenario !== '' ? $scenario : null,
                'source_key' => $sourceKey !== '' ? $sourceKey : null,
                'source_version' => $sourceVersion !== '' ? $sourceVersion : null,
            ],
            'status' => $feedbackSampleCount < $minimumSamples
                ? 'insufficient_samples'
                : 'descriptive_only',
            'minimum_samples' => $minimumSamples,
            'counts' => [
                'total_suggestions' => $totalSuggestions,
                'feedback_sample_count' => $feedbackSampleCount,
                'accepted' => $feedbackCounts['accepted'],
                'modified' => $feedbackCounts['modified'],
                'rejected' => $feedbackCounts['rejected'],
                'deferred' => $feedbackCounts['deferred'],
                'needs_more_evidence' => $feedbackCounts['needs_more_evidence'],
                'executed_suggestions' => $executedCount,
                'observable_reviews' => $observableReviewCount,
                'all_review_results' => count($latestReview),
            ],
            'rates' => [
                'direct_acceptance_rate' => $this->rate(
                    $feedbackCounts['accepted'],
                    $feedbackSampleCount
                ),
                'modified_acceptance_rate' => $this->rate(
                    $feedbackCounts['modified'],
                    $feedbackSampleCount
                ),
                'rejection_rate' => $this->rate(
                    $feedbackCounts['rejected'],
                    $feedbackSampleCount
                ),
                'insufficient_evidence_rate' => $this->rate(
                    $feedbackCounts['needs_more_evidence'],
                    $feedbackSampleCount
                ),
                'execution_rate' => $this->rate($executedCount, $totalSuggestions),
                'observable_review_rate' => $this->rate($observableReviewCount, $executedCount),
            ],
            'rate_denominators' => [
                'feedback_rates' => $feedbackSampleCount,
                'execution_rate' => $totalSuggestions,
                'observable_review_rate' => $executedCount,
            ],
            'confidence_calibration' => $calibration,
            'feedback_ranking' => $feedbackRanking,
            'policy' => $this->nonCausalPolicy(),
        ];
    }

    /**
     * Build user-and-hotel-scoped Daily One Thing feedback adjustments from
     * immutable snapshots. The result is descriptive and can only be consumed
     * as a tie-break after the business rank has already tied exactly.
     *
     * @return array<string,mixed>
     */
    public function buildDailyRankingAdjustments(
        array $scopeInput,
        array $options = []
    ): array {
        $scope = $this->scope($scopeInput);
        $scenario = $this->optionalText(
            $options['scenario'] ?? 'daily_one_thing_selection',
            120
        );
        $sourceKey = $this->optionalText(
            $options['source_key'] ?? 'daily_one_thing_input',
            120
        );
        $sourceVersion = $this->optionalText(
            $options['source_version'] ?? DailyOneThingInputService::CONTRACT_VERSION,
            120
        );
        if ($scenario === '' || $sourceKey === '' || $sourceVersion === '') {
            throw new InvalidArgumentException('daily ranking source identity is required');
        }

        $snapshotRows = Db::name(self::SNAPSHOT_TABLE)
            ->where($scope)
            ->where('scenario', $scenario)
            ->where('source_key', $sourceKey)
            ->where('source_version', $sourceVersion)
            ->order('id', 'desc')
            ->limit(self::MAX_DAILY_RANKING_SNAPSHOT_SCAN + 1)
            ->select()
            ->toArray();
        if (count($snapshotRows) > self::MAX_DAILY_RANKING_SNAPSHOT_SCAN) {
            return [
                'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
                'status' => 'unavailable',
                'reason_code' => 'history_scan_limit_exceeded',
                'scope' => $scope + [
                    'scenario' => $scenario,
                    'source_key' => $sourceKey,
                    'source_version' => $sourceVersion,
                ],
                'minimum_samples' => self::MINIMUM_RANKING_SAMPLES,
                'maximum_snapshot_scan' => self::MAX_DAILY_RANKING_SNAPSHOT_SCAN,
                'scanned_snapshot_count' => self::MAX_DAILY_RANKING_SNAPSHOT_SCAN,
                'history_truncated' => true,
                'items' => [],
                'facts_changed' => false,
                'eligibility_changed' => false,
                'permissions_changed' => false,
                'approval_changed' => false,
                'external_write_authorized' => false,
            ];
        }
        $snapshots = [];
        foreach ($snapshotRows as $row) {
            $snapshot = $this->normalizeSuggestion($row);
            if (($snapshot['readback_verified'] ?? false) !== true) {
                throw new RuntimeException('daily ranking suggestion integrity verification failed');
            }
            $payload = is_array($snapshot['suggestion_payload'] ?? null)
                ? $snapshot['suggestion_payload']
                : [];
            $featureIdentity = strtolower(trim((string)($payload['feature_identity'] ?? '')));
            if ((string)($payload['feature_key'] ?? '') !== 'daily_one_thing'
                || preg_match('/^[a-f0-9]{64}$/D', $featureIdentity) !== 1
            ) {
                continue;
            }
            $snapshots[(int)$snapshot['id']] = $snapshot;
        }

        $latestFeedback = [];
        if ($snapshots !== []) {
            $feedbackRows = Db::name(self::FEEDBACK_TABLE)
                ->where($scope)
                ->whereIn('suggestion_id', array_keys($snapshots))
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($feedbackRows as $row) {
                $feedback = $this->normalizeFeedback($row);
                if (($feedback['readback_verified'] ?? false) !== true) {
                    throw new RuntimeException('daily ranking feedback integrity verification failed');
                }
                $latestFeedback[(int)$feedback['suggestion_id']] = $feedback;
            }
        }

        $latestDailyFeedback = [];
        $duplicateSamplesByFeature = [];
        $invalidDateSamplesByFeature = [];
        foreach ($latestFeedback as $suggestionId => $feedback) {
            $snapshot = $snapshots[$suggestionId] ?? null;
            if (!is_array($snapshot)) {
                continue;
            }
            $payload = (array)$snapshot['suggestion_payload'];
            $featureIdentity = (string)$payload['feature_identity'];
            $businessDate = substr(trim((string)($payload['business_date'] ?? '')), 0, 10);
            $parsedBusinessDate = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
                || !$parsedBusinessDate
                || $parsedBusinessDate->format('Y-m-d') !== $businessDate
            ) {
                $invalidDateSamplesByFeature[$featureIdentity] =
                    ($invalidDateSamplesByFeature[$featureIdentity] ?? 0) + 1;
                continue;
            }
            $sampleIdentity = $featureIdentity . '|' . $businessDate;
            if (isset($latestDailyFeedback[$sampleIdentity])) {
                $duplicateSamplesByFeature[$featureIdentity] =
                    ($duplicateSamplesByFeature[$featureIdentity] ?? 0) + 1;
            }
            if (!isset($latestDailyFeedback[$sampleIdentity])
                || $suggestionId > (int)$latestDailyFeedback[$sampleIdentity]['suggestion_id']
            ) {
                $latestDailyFeedback[$sampleIdentity] = [
                    'suggestion_id' => $suggestionId,
                    'business_date' => $businessDate,
                    'snapshot' => $snapshot,
                    'feedback' => $feedback,
                ];
            }
        }

        $groups = [];
        foreach ($latestDailyFeedback as $sample) {
            $suggestionId = (int)$sample['suggestion_id'];
            $businessDate = (string)$sample['business_date'];
            $snapshot = (array)$sample['snapshot'];
            $feedback = (array)$sample['feedback'];
            $payload = (array)$snapshot['suggestion_payload'];
            $featureIdentity = (string)$payload['feature_identity'];
            if (!isset($groups[$featureIdentity])) {
                $groups[$featureIdentity] = [
                    'feature_identity' => $featureIdentity,
                    'feature_dimensions' => is_array($payload['feature_dimensions'] ?? null)
                        ? $payload['feature_dimensions']
                        : [],
                    'sample_rows' => [],
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'excluded_count' => (int)($invalidDateSamplesByFeature[$featureIdentity] ?? 0),
                    'duplicate_sample_count' => (int)($duplicateSamplesByFeature[$featureIdentity] ?? 0),
                    'feedback_refs' => [],
                ];
            }
            $status = strtolower(trim((string)($feedback['feedback_status'] ?? '')));
            $reason = strtolower(trim((string)($feedback['reason_code'] ?? '')));
            $sampleType = $status === 'accepted' && $reason === 'useful'
                ? 'positive'
                : ($status === 'rejected' && $reason === 'wrong_focus'
                    ? 'negative'
                    : 'excluded');
            if ($sampleType === 'excluded') {
                $groups[$featureIdentity]['excluded_count']++;
                continue;
            }
            $groups[$featureIdentity][$sampleType . '_count']++;
            $groups[$featureIdentity]['sample_rows'][] = [
                'suggestion_id' => $suggestionId,
                'feedback_id' => (int)$feedback['id'],
                'business_date' => $businessDate,
                'status' => $status,
                'reason_code' => $reason,
            ];
            $groups[$featureIdentity]['feedback_refs'][] =
                'ai_suggestion_calibration_feedback_events#' . (int)$feedback['id'];
        }

        $items = [];
        foreach ($groups as $group) {
            $sampleCount = $group['positive_count'] + $group['negative_count'];
            $eligible = $sampleCount >= self::MINIMUM_RANKING_SAMPLES;
            $positiveRate = $eligible ? $group['positive_count'] / $sampleCount : null;
            $negativeRate = $eligible ? $group['negative_count'] / $sampleCount : null;
            $adjustment = !$eligible
                ? 0
                : (($positiveRate ?? 0.0) >= (2 / 3)
                    ? 1
                    : (($negativeRate ?? 0.0) >= (2 / 3) ? -1 : 0));
            $items[] = [
                'feature_identity' => $group['feature_identity'],
                'feature_dimensions' => $group['feature_dimensions'],
                'status' => !$eligible
                    ? 'insufficient_samples'
                    : ($adjustment === 0 ? 'no_dominant_direction' : 'ready'),
                'eligible' => $eligible,
                'adjustment' => $adjustment,
                'sample_count' => $sampleCount,
                'minimum_samples' => self::MINIMUM_RANKING_SAMPLES,
                'positive_count' => $group['positive_count'],
                'negative_count' => $group['negative_count'],
                'excluded_count' => $group['excluded_count'],
                'unique_business_date_count' => $sampleCount,
                'duplicate_sample_count' => $group['duplicate_sample_count'],
                'positive_rate' => $positiveRate === null ? null : round($positiveRate, 6),
                'negative_rate' => $negativeRate === null ? null : round($negativeRate, 6),
                'sample_digest' => $this->digest($group['sample_rows']),
                'sample_identity_digest' => $this->digest([
                    'feature_identity' => $group['feature_identity'],
                    'business_dates' => array_values(array_map(
                        static fn(array $row): string => (string)$row['business_date'],
                        $group['sample_rows']
                    )),
                ]),
                'source_refs' => array_values(array_unique($group['feedback_refs'])),
                'application_mode' => 'base_rank_exact_tie_break_only',
            ];
        }
        usort($items, static fn(array $left, array $right): int => (
            strcmp((string)$left['feature_identity'], (string)$right['feature_identity'])
        ));

        return [
            'contract_version' => 'daily_one_thing_feedback_adjustments.v1',
            'status' => $items === []
                ? 'empty'
                : (count(array_filter(
                    $items,
                    static fn(array $item): bool => $item['eligible'] === true
                )) > 0 ? 'ready' : 'insufficient_samples'),
            'scope' => $scope + [
                'scenario' => $scenario,
                'source_key' => $sourceKey,
                'source_version' => $sourceVersion,
            ],
            'minimum_samples' => self::MINIMUM_RANKING_SAMPLES,
            'unique_sample_policy' => 'one_latest_feedback_per_feature_and_business_date',
            'duplicate_sample_count' => array_sum($duplicateSamplesByFeature),
            'invalid_business_date_sample_count' => array_sum($invalidDateSamplesByFeature),
            'maximum_snapshot_scan' => self::MAX_DAILY_RANKING_SNAPSHOT_SCAN,
            'scanned_snapshot_count' => count($snapshotRows),
            'history_truncated' => false,
            'items' => $items,
            'facts_changed' => false,
            'eligibility_changed' => false,
            'permissions_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];
    }

    /**
     * Deterministic, user-and-hotel-scoped ordering hints for existing quick
     * suggestions. It never creates a new business candidate or changes facts.
     *
     * @param list<array<string,mixed>> $suggestions
     * @param array<int,array<string,mixed>> $latestFeedback
     * @return array<string,mixed>
     */
    private function feedbackRanking(
        array $suggestions,
        array $latestFeedback,
        int $minimumSamples
    ): array {
        $bySuggestionId = [];
        foreach ($suggestions as $row) {
            $bySuggestionId[(int)$row['id']] = $row;
        }
        $groups = [];
        $excludedCount = 0;
        foreach ($latestFeedback as $suggestionId => $feedback) {
            $suggestion = $bySuggestionId[(int)$suggestionId] ?? null;
            if (!is_array($suggestion)) {
                continue;
            }
            $payload = $this->decodeJson($suggestion['suggestion_payload_json'] ?? '');
            $topicKey = strtolower(trim((string)($payload['topic_key'] ?? '')));
            $scenario = strtolower(trim((string)($suggestion['scenario'] ?? '')));
            $sourceKey = strtolower(trim((string)($suggestion['source_key'] ?? '')));
            $sourceVersion = trim((string)($suggestion['source_version'] ?? ''));
            if ($topicKey === ''
                || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $topicKey) !== 1
                || !str_starts_with($scenario, 'system_guidance_')
                || $sourceKey !== 'precise_query'
                || $sourceVersion === ''
            ) {
                continue;
            }
            $status = strtolower(trim((string)($feedback['feedback_status'] ?? '')));
            $reasonCode = strtolower(trim((string)($feedback['reason_code'] ?? '')));
            $sampleType = $status === 'accepted' && $reasonCode === 'useful'
                ? 'positive'
                : ($status === 'rejected' && $reasonCode === 'wrong_focus'
                    ? 'negative'
                    : 'excluded');
            $groupKey = implode('|', [$scenario, $sourceKey, $sourceVersion, $topicKey]);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'topic_key' => $topicKey,
                    'scenario' => $scenario,
                    'source_key' => $sourceKey,
                    'source_version' => $sourceVersion,
                    'sample_count' => 0,
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'excluded_count' => 0,
                    'latest_suggestion_id' => 0,
                ];
            }
            $groups[$groupKey]['latest_suggestion_id'] = max(
                $groups[$groupKey]['latest_suggestion_id'],
                (int)$suggestionId
            );
            if ($sampleType === 'excluded') {
                $groups[$groupKey]['excluded_count']++;
                $excludedCount++;
                continue;
            }
            $groups[$groupKey]['sample_count']++;
            $groups[$groupKey][$sampleType . '_count']++;
        }

        $items = [];
        foreach ($groups as $group) {
            $eligible = $group['sample_count'] >= $minimumSamples;
            $positiveRate = $eligible
                ? $group['positive_count'] / $group['sample_count']
                : null;
            $negativeRate = $eligible
                ? $group['negative_count'] / $group['sample_count']
                : null;
            $adjustment = !$eligible
                ? 0
                : (($positiveRate ?? 0.0) >= (2 / 3)
                    ? 1
                    : (($negativeRate ?? 0.0) >= (2 / 3) ? -1 : 0));
            $items[] = [
                'topic_key' => $group['topic_key'],
                'scenario' => $group['scenario'],
                'source_key' => $group['source_key'],
                'source_version' => $group['source_version'],
                'sample_count' => $group['sample_count'],
                'positive_count' => $group['positive_count'],
                'negative_count' => $group['negative_count'],
                'excluded_count' => $group['excluded_count'],
                'eligible' => $eligible,
                'adjustment' => $adjustment,
                'application_mode' => 'base_order_tie_break_only',
                'positive_rate' => $positiveRate === null ? null : round($positiveRate, 6),
                'negative_rate' => $negativeRate === null ? null : round($negativeRate, 6),
                'latest_suggestion_id' => $group['latest_suggestion_id'],
                'resolution_status' => $eligible ? 'applicable' : 'insufficient_samples',
            ];
        }
        $itemsByTopic = [];
        foreach ($items as $item) {
            $itemsByTopic[(string)$item['topic_key']][] = $item;
        }
        $resolvedItems = [];
        foreach ($itemsByTopic as $topicItems) {
            if (count($topicItems) === 1) {
                $resolvedItems[] = $topicItems[0];
                continue;
            }
            usort($topicItems, static fn(array $left, array $right): int => (
                (int)$right['latest_suggestion_id'] <=> (int)$left['latest_suggestion_id']
            ));
            $latest = $topicItems[0];
            $latest['eligible'] = false;
            $latest['adjustment'] = 0;
            $latest['application_mode'] = 'none';
            $latest['resolution_status'] = 'conflicting_source_groups';
            $latest['conflicting_source_groups'] = array_values(array_map(
                static fn(array $item): array => [
                    'scenario' => (string)$item['scenario'],
                    'source_key' => (string)$item['source_key'],
                    'source_version' => (string)$item['source_version'],
                    'sample_count' => (int)$item['sample_count'],
                    'adjustment' => (int)$item['adjustment'],
                ],
                $topicItems
            ));
            $resolvedItems[] = $latest;
        }
        $items = $resolvedItems;
        usort($items, static function (array $left, array $right): int {
            $eligibleOrder = (int)$right['eligible'] <=> (int)$left['eligible'];
            if ($eligibleOrder !== 0) {
                return $eligibleOrder;
            }
            $adjustmentOrder = (int)$right['adjustment'] <=> (int)$left['adjustment'];
            if ($adjustmentOrder !== 0) {
                return $adjustmentOrder;
            }
            $sampleOrder = (int)$right['sample_count'] <=> (int)$left['sample_count'];
            return $sampleOrder !== 0
                ? $sampleOrder
                : strcmp((string)$left['topic_key'], (string)$right['topic_key']);
        });
        foreach ($items as &$item) {
            unset($item['latest_suggestion_id']);
        }
        unset($item);
        $rankedTopicKeys = array_values(array_map(
            static fn(array $item): string => (string)$item['topic_key'],
            array_filter($items, static fn(array $item): bool => $item['eligible'] === true)
        ));

        return [
            'contract_version' => 'user_feedback_ranking.v1',
            'status' => $items === []
                ? 'empty'
                : ($rankedTopicKeys === [] ? 'insufficient_samples' : 'ready'),
            'minimum_samples_per_topic' => $minimumSamples,
            'eligible_sample_statuses' => ['accepted:useful', 'rejected:wrong_focus'],
            'excluded_feedback_count' => $excludedCount,
            'items' => $items,
            'ranked_topic_keys' => $rankedTopicKeys,
            'effect_scope' => $rankedTopicKeys === []
                ? 'none'
                : 'existing_quick_suggestion_order_only',
            'facts_changed' => false,
            'permissions_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
            'new_business_candidate_created' => false,
        ];
    }

    /**
     * Persists supplied frozen replay metrics as an offline/shadow comparison.
     * It deliberately has no activation path and no model/business callback.
     *
     * @return array<string,mixed>
     */
    public function recordStrategyComparison(array $input): array
    {
        $scope = $this->scope($input);
        $this->rejectForbiddenComparisonRequests($input);
        $comparisonKey = $this->requiredText($input['comparison_key'] ?? null, 120, 'comparison_key');
        $idempotencyHash = $this->idempotencyHash($input['idempotency_key'] ?? null);
        $mode = $this->enum($input['mode'] ?? null, self::COMPARISON_MODES, 'mode');
        $scenario = $this->requiredText($input['scenario'] ?? null, 120, 'scenario');
        $evaluationSet = $this->requiredText($input['evaluation_set'] ?? null, 120, 'evaluation_set');
        $baselineVersion = $this->requiredText(
            $input['baseline_version'] ?? null,
            120,
            'baseline_version'
        );
        $candidateVersion = $this->requiredText(
            $input['candidate_version'] ?? null,
            120,
            'candidate_version'
        );
        if ($candidateVersion === $baselineVersion) {
            throw new InvalidArgumentException('candidate_version must differ from baseline_version');
        }
        $snapshotDigest = $this->sha256(
            $input['evaluation_snapshot_digest'] ?? null,
            'evaluation_snapshot_digest'
        );
        $baselineMetrics = $this->arrayInput(
            $input['baseline_metrics'] ?? null,
            'baseline_metrics',
            true
        );
        $candidateMetrics = $this->arrayInput(
            $input['candidate_metrics'] ?? null,
            'candidate_metrics',
            true
        );
        $rollback = $this->arrayInput(
            $input['rollback_metadata'] ?? null,
            'rollback_metadata',
            true
        );
        $this->assertNoSensitiveMaterial([$baselineMetrics, $candidateMetrics, $rollback], 'strategy_comparison');
        $rollbackTarget = $this->requiredText(
            $rollback['target_version'] ?? null,
            120,
            'rollback_metadata.target_version'
        );
        if ($rollbackTarget !== $baselineVersion) {
            throw new InvalidArgumentException('rollback target must equal baseline_version');
        }
        $this->requiredText($rollback['trigger'] ?? null, 500, 'rollback_metadata.trigger');
        $this->requiredText($rollback['procedure'] ?? null, 1000, 'rollback_metadata.procedure');

        $comparison = [
            'baseline_metrics' => $baselineMetrics,
            'candidate_metrics' => $candidateMetrics,
            'metric_deltas' => $this->metricDeltas($baselineMetrics, $candidateMetrics),
            'interpretation' => 'descriptive_non_causal_comparison',
        ];
        $contentDigest = $this->digest([
            ...$scope,
            'comparison_key' => $comparisonKey,
            'mode' => $mode,
            'scenario' => $scenario,
            'evaluation_set' => $evaluationSet,
            'baseline_version' => $baselineVersion,
            'candidate_version' => $candidateVersion,
            'evaluation_snapshot_digest' => $snapshotDigest,
            'comparison' => $comparison,
            'rollback_metadata' => $rollback,
            'activation_status' => 'not_activated',
            'decision_effect' => 'none',
            'external_call_status' => 'not_called',
            'business_write_status' => 'none',
            'causal_claim' => 'none',
        ]);

        $existing = $this->findExistingComparison($scope, $comparisonKey, $idempotencyHash);
        if (is_array($existing)) {
            return $this->verifiedComparisonReplay($existing, $contentDigest);
        }

        try {
            return Db::transaction(function () use (
                $scope,
                $comparisonKey,
                $idempotencyHash,
                $mode,
                $scenario,
                $evaluationSet,
                $baselineVersion,
                $candidateVersion,
                $snapshotDigest,
                $comparison,
                $rollback,
                $contentDigest
            ): array {
                $createdId = (int)Db::name(self::COMPARISON_TABLE)->insertGetId([
                    ...$scope,
                    'comparison_key' => $comparisonKey,
                    'idempotency_hash' => $idempotencyHash,
                    'mode' => $mode,
                    'scenario' => $scenario,
                    'evaluation_set' => $evaluationSet,
                    'baseline_version' => $baselineVersion,
                    'candidate_version' => $candidateVersion,
                    'evaluation_snapshot_digest' => $snapshotDigest,
                    'comparison_json' => $this->canonicalJson($comparison),
                    'rollback_metadata_json' => $this->canonicalJson($rollback),
                    'activation_status' => 'not_activated',
                    'decision_effect' => 'none',
                    'external_call_status' => 'not_called',
                    'business_write_status' => 'none',
                    'causal_claim' => 'none',
                    'content_digest' => $contentDigest,
                    'created_at' => $this->now(),
                ]);

                $row = $this->comparisonById($scope, $createdId);
                if (!is_array($row)) {
                    throw new RuntimeException('AI strategy comparison exact readback failed');
                }

                $result = $this->normalizeComparison($row);
                if (($result['readback_verified'] ?? false) !== true) {
                    throw new RuntimeException('AI strategy comparison integrity verification failed');
                }
                $result['created'] = true;
                $result['idempotent_replay'] = false;
                return $result;
            });
        } catch (Throwable $error) {
            if (!$this->isDuplicateKeyConflict($error)) {
                throw $error;
            }
        }

        $row = $this->findExistingComparison($scope, $comparisonKey, $idempotencyHash);
        if (!is_array($row)) {
            throw new RuntimeException('AI strategy comparison exact readback failed');
        }
        return $this->verifiedComparisonReplay($row, $contentDigest);
    }

    /** @return array<string,mixed>|null */
    public function readStrategyComparison(
        int $tenantId,
        int $userId,
        int $hotelId,
        string $comparisonKey
    ): ?array {
        $scope = $this->scope([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
        ]);
        $row = $this->comparisonByKey(
            $scope,
            $this->requiredText($comparisonKey, 120, 'comparison_key')
        );
        if (!is_array($row)) {
            return null;
        }
        $result = $this->normalizeComparison($row);
        if (($result['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI strategy comparison integrity verification failed');
        }
        return $result;
    }

    /** @param array<string,int> $scope */
    private function requiredSuggestion(array $scope, string $suggestionKey): array
    {
        $row = $this->suggestionByKey($scope, $suggestionKey);
        if (!is_array($row)) {
            throw new RuntimeException('AI suggestion snapshot not found in exact scope', 404);
        }
        $suggestion = $this->normalizeSuggestion($row);
        if (($suggestion['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI suggestion snapshot integrity verification failed');
        }
        return $suggestion;
    }

    /** @param array<string,int> $scope */
    private function findExistingSuggestion(
        array $scope,
        string $suggestionKey,
        string $identityDigest,
        string $idempotencyHash
    ): ?array {
        $row = Db::name(self::SNAPSHOT_TABLE)
            ->where($scope)
            ->where('idempotency_hash', $idempotencyHash)
            ->find();
        if (is_array($row)) {
            return $row;
        }
        $row = $this->suggestionByKey($scope, $suggestionKey);
        if (is_array($row)) {
            return $row;
        }
        $row = Db::name(self::SNAPSHOT_TABLE)
            ->where($scope)
            ->where('identity_digest', $identityDigest)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function suggestionByKey(array $scope, string $suggestionKey): ?array
    {
        $row = Db::name(self::SNAPSHOT_TABLE)
            ->where($scope)
            ->where('suggestion_key', $suggestionKey)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function suggestionById(array $scope, int $id): ?array
    {
        $row = Db::name(self::SNAPSHOT_TABLE)->where($scope)->where('id', $id)->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function feedbackByIdempotency(array $scope, int $suggestionId, string $hash): ?array
    {
        $row = Db::name(self::FEEDBACK_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->where('idempotency_hash', $hash)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function feedbackById(array $scope, int $suggestionId, int $id): ?array
    {
        $row = Db::name(self::FEEDBACK_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->where('id', $id)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function observationByIdempotency(array $scope, int $suggestionId, string $hash): ?array
    {
        $row = Db::name(self::OBSERVATION_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->where('idempotency_hash', $hash)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function observationById(array $scope, int $suggestionId, int $id): ?array
    {
        $row = Db::name(self::OBSERVATION_TABLE)
            ->where($scope)
            ->where('suggestion_id', $suggestionId)
            ->where('id', $id)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function findExistingComparison(array $scope, string $comparisonKey, string $idempotencyHash): ?array
    {
        $row = Db::name(self::COMPARISON_TABLE)
            ->where($scope)
            ->where('idempotency_hash', $idempotencyHash)
            ->find();
        if (is_array($row)) {
            return $row;
        }
        return $this->comparisonByKey($scope, $comparisonKey);
    }

    /** @param array<string,int> $scope */
    private function comparisonByKey(array $scope, string $comparisonKey): ?array
    {
        $row = Db::name(self::COMPARISON_TABLE)
            ->where($scope)
            ->where('comparison_key', $comparisonKey)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,int> $scope */
    private function comparisonById(array $scope, int $id): ?array
    {
        $row = Db::name(self::COMPARISON_TABLE)->where($scope)->where('id', $id)->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function verifiedSuggestionReplay(array $row, string $identityDigest, string $contentDigest): array
    {
        if (!hash_equals((string)($row['identity_digest'] ?? ''), $identityDigest)
            || !hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)
        ) {
            throw new RuntimeException('AI suggestion idempotency conflict', 409);
        }
        $result = $this->normalizeSuggestion($row);
        if (($result['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI suggestion snapshot integrity verification failed');
        }
        $result['created'] = false;
        $result['idempotent_replay'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function verifiedFeedbackReplay(array $row, string $contentDigest): array
    {
        if (!hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)) {
            throw new RuntimeException('AI suggestion feedback idempotency conflict', 409);
        }
        $result = $this->normalizeFeedback($row);
        if (($result['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI suggestion feedback integrity verification failed');
        }
        $result['created'] = false;
        $result['idempotent_replay'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function verifiedObservationReplay(array $row, string $contentDigest): array
    {
        if (!hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)) {
            throw new RuntimeException('AI suggestion observation idempotency conflict', 409);
        }
        $result = $this->normalizeObservation($row);
        if (($result['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI suggestion observation integrity verification failed');
        }
        $result['created'] = false;
        $result['idempotent_replay'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function verifiedComparisonReplay(array $row, string $contentDigest): array
    {
        if (!hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)) {
            throw new RuntimeException('AI strategy comparison idempotency conflict', 409);
        }
        $result = $this->normalizeComparison($row);
        if (($result['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('AI strategy comparison integrity verification failed');
        }
        $result['created'] = false;
        $result['idempotent_replay'] = true;
        return $result;
    }

    /** @return array<string,mixed> */
    private function normalizeSuggestion(array $row): array
    {
        $payload = $this->decodeJson($row['suggestion_payload_json'] ?? null);
        $confidence = $this->confidence($row['confidence'] ?? null);
        $identityDigest = $this->digest([
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'suggestion_key' => (string)($row['suggestion_key'] ?? ''),
            'scenario' => (string)($row['scenario'] ?? ''),
            'source_key' => (string)($row['source_key'] ?? ''),
            'source_version' => (string)($row['source_version'] ?? ''),
            'evidence_digest' => (string)($row['evidence_digest'] ?? ''),
        ]);
        $contentDigest = $this->digest([
            'identity_digest' => $identityDigest,
            'suggestion_payload' => $payload,
            'confidence' => $confidence,
        ]);

        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'suggestion_key' => (string)($row['suggestion_key'] ?? ''),
            'scenario' => (string)($row['scenario'] ?? ''),
            'source_key' => (string)($row['source_key'] ?? ''),
            'source_version' => (string)($row['source_version'] ?? ''),
            'evidence_digest' => (string)($row['evidence_digest'] ?? ''),
            'identity_digest' => (string)($row['identity_digest'] ?? ''),
            'suggestion_payload' => $payload,
            'confidence' => $confidence === null ? null : (float)$confidence,
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'idempotency_hash' => (string)($row['idempotency_hash'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'frozen' => true,
            'readback_verified' => hash_equals((string)($row['identity_digest'] ?? ''), $identityDigest)
                && hash_equals((string)($row['content_digest'] ?? ''), $contentDigest),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeFeedback(array $row): array
    {
        $payload = $this->decodeJson($row['feedback_payload_json'] ?? null);
        $contentDigest = $this->digest([
            'suggestion_identity_digest' => (string)($row['suggestion_identity_digest'] ?? ''),
            'feedback_status' => (string)($row['feedback_status'] ?? ''),
            'reason_code' => (string)($row['reason_code'] ?? ''),
            'reason_note' => (string)($row['reason_note'] ?? ''),
            'feedback_payload' => $payload,
        ]);
        return [
            'id' => (int)($row['id'] ?? 0),
            'suggestion_id' => (int)($row['suggestion_id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'feedback_status' => (string)($row['feedback_status'] ?? ''),
            'reason_code' => (string)($row['reason_code'] ?? ''),
            'reason_note' => (string)($row['reason_note'] ?? ''),
            'feedback_payload' => $payload,
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'idempotency_hash' => (string)($row['idempotency_hash'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'readback_verified' => hash_equals((string)($row['content_digest'] ?? ''), $contentDigest),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeObservation(array $row): array
    {
        $payload = $this->decodeJson($row['evidence_payload_json'] ?? null);
        $executionStatus = trim((string)($row['execution_status'] ?? '')) ?: null;
        $reviewResult = trim((string)($row['review_result'] ?? '')) ?: null;
        $evidenceDigest = trim((string)($row['evidence_digest'] ?? '')) ?: null;
        $contentDigest = $this->digest([
            'suggestion_identity_digest' => (string)($row['suggestion_identity_digest'] ?? ''),
            'execution_status' => $executionStatus,
            'review_result' => $reviewResult,
            'observed_at' => (string)($row['observed_at'] ?? ''),
            'evidence_digest' => $evidenceDigest,
            'evidence_payload' => $payload,
            'causal_claim' => (string)($row['causal_claim'] ?? ''),
        ]);
        return [
            'id' => (int)($row['id'] ?? 0),
            'suggestion_id' => (int)($row['suggestion_id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'execution_status' => $executionStatus,
            'review_result' => $reviewResult,
            'observed_at' => (string)($row['observed_at'] ?? ''),
            'evidence_digest' => $evidenceDigest,
            'evidence_payload' => $payload,
            'causal_claim' => (string)($row['causal_claim'] ?? ''),
            'idempotency_hash' => (string)($row['idempotency_hash'] ?? ''),
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'readback_verified' => hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)
                && (string)($row['causal_claim'] ?? '') === 'none',
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeComparison(array $row): array
    {
        $comparison = $this->decodeJson($row['comparison_json'] ?? null);
        $rollback = $this->decodeJson($row['rollback_metadata_json'] ?? null);
        $contentDigest = $this->digest([
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'comparison_key' => (string)($row['comparison_key'] ?? ''),
            'mode' => (string)($row['mode'] ?? ''),
            'scenario' => (string)($row['scenario'] ?? ''),
            'evaluation_set' => (string)($row['evaluation_set'] ?? ''),
            'baseline_version' => (string)($row['baseline_version'] ?? ''),
            'candidate_version' => (string)($row['candidate_version'] ?? ''),
            'evaluation_snapshot_digest' => (string)($row['evaluation_snapshot_digest'] ?? ''),
            'comparison' => $comparison,
            'rollback_metadata' => $rollback,
            'activation_status' => (string)($row['activation_status'] ?? ''),
            'decision_effect' => (string)($row['decision_effect'] ?? ''),
            'external_call_status' => (string)($row['external_call_status'] ?? ''),
            'business_write_status' => (string)($row['business_write_status'] ?? ''),
            'causal_claim' => (string)($row['causal_claim'] ?? ''),
        ]);
        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'comparison_key' => (string)($row['comparison_key'] ?? ''),
            'mode' => (string)($row['mode'] ?? ''),
            'scenario' => (string)($row['scenario'] ?? ''),
            'evaluation_set' => (string)($row['evaluation_set'] ?? ''),
            'baseline_version' => (string)($row['baseline_version'] ?? ''),
            'candidate_version' => (string)($row['candidate_version'] ?? ''),
            'evaluation_snapshot_digest' => (string)($row['evaluation_snapshot_digest'] ?? ''),
            'comparison' => $comparison,
            'rollback_metadata' => $rollback,
            'activation_status' => (string)($row['activation_status'] ?? ''),
            'decision_effect' => (string)($row['decision_effect'] ?? ''),
            'external_call_status' => (string)($row['external_call_status'] ?? ''),
            'business_write_status' => (string)($row['business_write_status'] ?? ''),
            'causal_claim' => (string)($row['causal_claim'] ?? ''),
            'idempotency_hash' => (string)($row['idempotency_hash'] ?? ''),
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'readback_verified' => hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)
                && in_array((string)($row['mode'] ?? ''), self::COMPARISON_MODES, true)
                && (string)($row['activation_status'] ?? '') === 'not_activated'
                && (string)($row['decision_effect'] ?? '') === 'none'
                && (string)($row['external_call_status'] ?? '') === 'not_called'
                && (string)($row['business_write_status'] ?? '') === 'none'
                && (string)($row['causal_claim'] ?? '') === 'none',
        ];
    }

    /** @return array<string,mixed> */
    private function confidenceCalibration(
        array $suggestions,
        array $latestReview,
        int $minimumSamples,
        float $tolerance
    ): array {
        $confidences = [];
        $observed = [];
        foreach ($suggestions as $row) {
            $suggestionId = (int)($row['id'] ?? 0);
            $reviewResult = (string)($latestReview[$suggestionId]['review_result'] ?? '');
            if (!in_array($reviewResult, ['supported', 'contradicted'], true)) {
                continue;
            }
            $confidence = $this->confidence($row['confidence'] ?? null);
            if ($confidence === null) {
                continue;
            }
            $confidences[] = (float)$confidence;
            $observed[] = $reviewResult === 'supported' ? 1.0 : 0.0;
        }

        $sampleCount = count($confidences);
        $averageConfidence = $sampleCount > 0 ? array_sum($confidences) / $sampleCount : null;
        $observedSupportRate = $sampleCount > 0 ? array_sum($observed) / $sampleCount : null;
        $gap = $sampleCount > 0 ? $averageConfidence - $observedSupportRate : null;
        $status = 'insufficient_samples';
        if ($sampleCount >= $minimumSamples && $gap !== null) {
            if (abs($gap) <= $tolerance) {
                $status = 'calibrated';
            } elseif ($gap > 0) {
                $status = 'over_confident';
            } else {
                $status = 'under_confident';
            }
        }

        return [
            'status' => $status,
            'basis' => 'observed_review_support_non_causal',
            'sample_count' => $sampleCount,
            'minimum_samples' => $minimumSamples,
            'tolerance' => $tolerance,
            'average_confidence' => $averageConfidence === null ? null : round($averageConfidence, 6),
            'observed_support_rate' => $observedSupportRate === null
                ? null
                : round($observedSupportRate, 6),
            'confidence_gap' => $gap === null ? null : round($gap, 6),
            'causal_claim' => 'none',
        ];
    }

    /** @return array<string,mixed> */
    private function nonCausalPolicy(): array
    {
        return [
            'automatic_activation' => false,
            'external_model_calls' => false,
            'business_table_writes' => false,
            'causal_claim' => 'none',
        ];
    }

    private function rejectForbiddenComparisonRequests(array $input): void
    {
        foreach (['activate', 'allow_external_model_call', 'write_business_tables'] as $field) {
            if ($this->boolValue($input[$field] ?? false)) {
                throw new InvalidArgumentException($field . ' is not permitted for strategy comparison');
            }
        }
        if (isset($input['activation_status'])
            && trim((string)$input['activation_status']) !== 'not_activated'
        ) {
            throw new InvalidArgumentException('strategy comparison cannot activate a candidate');
        }
    }

    /** @return array<string,float|int> */
    private function metricDeltas(array $baseline, array $candidate): array
    {
        $deltas = [];
        foreach (array_intersect(array_keys($baseline), array_keys($candidate)) as $key) {
            if (!is_numeric($baseline[$key]) || !is_numeric($candidate[$key])) {
                continue;
            }
            $delta = (float)$candidate[$key] - (float)$baseline[$key];
            $deltas[(string)$key] = round($delta, 8);
        }
        ksort($deltas, SORT_STRING);
        return $deltas;
    }

    /** @return array{tenant_id:int,user_id:int,hotel_id:int} */
    private function scope(array $input): array
    {
        return [
            'tenant_id' => $this->positiveInt($input['tenant_id'] ?? null, 'tenant_id'),
            'user_id' => $this->positiveInt($input['user_id'] ?? null, 'user_id'),
            'hotel_id' => $this->positiveInt(
                $input['hotel_id'] ?? $input['system_hotel_id'] ?? null,
                'hotel_id'
            ),
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer');
        }
        return (int)$value;
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($field . ' must be an integer');
        }
        $value = (int)$value;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($field . ' is outside the allowed range');
        }
        return $value;
    }

    private function boundedFloat(mixed $value, float $minimum, float $maximum, string $field): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be numeric');
        }
        $value = (float)$value;
        if (!is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($field . ' is outside the allowed range');
        }
        return $value;
    }

    private function idempotencyHash(mixed $value): string
    {
        return hash('sha256', $this->requiredText($value, 96, 'idempotency_key'));
    }

    private function assertNoSensitiveMaterial(mixed $value, string $field): void
    {
        $serialized = is_string($value) ? $value : $this->canonicalJson($value);
        foreach ([
            '/\b(?:password|passwd|pwd|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|token|authorization|cookie)\s*[:=]\s*\S+/iu',
            '/(?:密码|凭证|密钥|令牌|验证码|短信码|恢复码)\s*[:：=]\s*\S+/u',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}\b/',
            '/\b(?:bearer\s+|sk-(?:proj-)?)[A-Za-z0-9._-]{12,}\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $serialized) === 1) {
                throw new InvalidArgumentException($field . ' contains sensitive credential material');
            }
        }
    }

    private function requiredText(mixed $value, int $maximumLength, string $field): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            throw new InvalidArgumentException($field . ' is required');
        }
        if (mb_strlen($text) > $maximumLength) {
            throw new InvalidArgumentException($field . ' exceeds the maximum length');
        }
        return $text;
    }

    private function optionalText(mixed $value, int $maximumLength): string
    {
        $text = trim((string)$value);
        return mb_substr($text, 0, $maximumLength);
    }

    private function enum(mixed $value, array $allowed, string $field): string
    {
        $value = strtolower($this->requiredText($value, 40, $field));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field . ' is invalid');
        }
        return $value;
    }

    private function nullableEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->enum($value, $allowed, $field);
    }

    private function confidence(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('confidence must be numeric');
        }
        $confidence = (float)$value;
        if (!is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be between 0 and 1');
        }
        return number_format($confidence, 5, '.', '');
    }

    private function sha256(mixed $value, string $field): string
    {
        $digest = strtolower(trim((string)$value));
        if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new InvalidArgumentException($field . ' must be a SHA-256 digest');
        }
        return $digest;
    }

    private function optionalSha256(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->sha256($value, $field);
    }

    private function dateTime(mixed $value, string $field): string
    {
        $text = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $text) {
            throw new InvalidArgumentException($field . ' must use Y-m-d H:i:s');
        }
        return $text;
    }

    private function arrayInput(mixed $value, string $field, bool $required = false): array
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $error) {
                throw new InvalidArgumentException($field . ' must be valid JSON', 0, $error);
            }
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException($field . ' must be an array');
        }
        if ($required && $value === []) {
            throw new InvalidArgumentException($field . ' cannot be empty');
        }
        return $value;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('AI suggestion stored JSON is invalid', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('AI suggestion stored JSON must decode to an array');
        }
        return $decoded;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, 6) : null;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['true', 'yes', 'on'], true);
    }

    private function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s');
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->canonical($value),
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $error) {
            throw new RuntimeException('AI suggestion JSON encoding failed', 0, $error);
        }
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }

    private function isDuplicateKeyConflict(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'integrity constraint violation: 1062')
                || str_contains($message, 'unique constraint failed')
            ) {
                return true;
            }
        }
        return false;
    }
}
