<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Builds a per-user preview after the shared Daily One Thing fact gate and
 * four-dimensional business rank have already completed.
 *
 * The preview is never a hotel-shared run, approval, task, or external write.
 */
final class DailyOneThingPersonalizationService
{
    public const CONTRACT_VERSION = 'daily_one_thing_personalization.v1';
    public const EXPERIENCE_VERSION = 'daily_one_thing.personalized_preview.v3';
    public const SCENARIO = 'daily_one_thing_selection';
    public const SOURCE_KEY = 'daily_one_thing_input';
    private const FEEDBACK_SLOT_IDEMPOTENCY_KEY = 'daily_preview_feedback_slot_v1';

    private DailyOneThingService $base;
    private AiSuggestionCalibrationService $calibration;

    /** @var Closure(int,int,int):array<string,mixed> */
    private Closure $preferenceLoader;

    /** @var Closure(int,int,int):array<string,mixed> */
    private Closure $feedbackLoader;

    public function __construct(
        ?DailyOneThingService $base = null,
        ?AiSuggestionCalibrationService $calibration = null,
        ?callable $preferenceLoader = null,
        ?callable $feedbackLoader = null
    ) {
        $this->base = $base ?? new DailyOneThingService();
        $this->calibration = $calibration ?? new AiSuggestionCalibrationService();
        $this->preferenceLoader = $preferenceLoader !== null
            ? Closure::fromCallable($preferenceLoader)
            : static fn(int $tenantId, int $userId, int $hotelId): array =>
                (new UserPreferenceContextService())->build(
                    $tenantId,
                    $userId,
                    $hotelId
                );
        $this->feedbackLoader = $feedbackLoader !== null
            ? Closure::fromCallable($feedbackLoader)
            : fn(int $tenantId, int $userId, int $hotelId): array =>
                $this->calibration->buildDailyRankingAdjustments([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'hotel_id' => $hotelId,
                ]);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function select(
        array $candidates,
        string $businessDate,
        int $tenantId,
        int $userId,
        int $hotelId,
        array $reviewedObservations = []
    ): array {
        $this->assertScope($tenantId, $userId, $hotelId);
        $baseResult = $this->base->select($candidates, $businessDate, $reviewedObservations);
        $prepared = $this->base->prepare($candidates, $businessDate);
        $baseSelected = is_array($baseResult['selected'] ?? null)
            ? $baseResult['selected']
            : null;
        if ($baseSelected === null) {
            return $this->withReceipt(
                $baseResult,
                $this->emptyReceipt(
                    $tenantId,
                    $userId,
                    $hotelId,
                    'not_applied',
                    ['no_eligible_item']
                )
            );
        }
        if (($baseResult['selection_policy']['outcome_learning_applied'] ?? false) === true) {
            return $this->withReceipt(
                $baseResult,
                $this->emptyReceipt(
                    $tenantId,
                    $userId,
                    $hotelId,
                    'not_applied',
                    ['outcome_learning_precedes_personalization'],
                    (string)$baseSelected['candidate_key'],
                    (string)$baseSelected['candidate_key'],
                    DailyOneThingService::baseRankKey($baseSelected),
                    max(1, (int)($baseResult['selection_policy']['base_tie_group_size'] ?? 1))
                )
            );
        }

        $eligible = array_values(array_filter(
            is_array($prepared['eligible'] ?? null) ? $prepared['eligible'] : [],
            'is_array'
        ));
        $topTie = array_values(array_filter(
            $eligible,
            static fn(array $candidate): bool => DailyOneThingService::sameBaseRank(
                $candidate,
                $baseSelected
            )
        ));
        usort($topTie, static fn(array $left, array $right): int => strcmp(
            (string)$left['candidate_key'],
            (string)$right['candidate_key']
        ));
        if (count($topTie) <= 1) {
            $currentFeedback = $this->loadCurrentFeedback(
                $tenantId,
                $userId,
                $hotelId,
                $businessDate,
                $baseSelected
            );
            return $this->withReceipt(
                $baseResult,
                $this->emptyReceipt(
                    $tenantId,
                    $userId,
                    $hotelId,
                    'not_applied',
                    ['no_base_rank_tie'],
                    (string)$baseSelected['candidate_key'],
                    (string)$baseSelected['candidate_key'],
                    DailyOneThingService::baseRankKey($baseSelected),
                    count($topTie),
                    $currentFeedback
                )
            );
        }

        $preferences = $this->loadPreferences($tenantId, $userId, $hotelId);
        $feedback = $this->loadFeedback($tenantId, $userId, $hotelId);
        if (($preferences['status'] ?? '') === 'ready'
            && ((int)($preferences['tenant_id'] ?? 0) !== $tenantId
                || (int)($preferences['user_id'] ?? 0) !== $userId
                || (int)($preferences['hotel_id'] ?? 0) !== $hotelId)
        ) {
            $preferences = [
                'status' => 'unavailable',
                'reason_code' => 'preference_context_scope_mismatch',
                'items' => [],
            ];
        }
        if (($feedback['status'] ?? '') !== 'unavailable') {
            $feedbackScope = is_array($feedback['scope'] ?? null) ? $feedback['scope'] : [];
            if ((int)($feedbackScope['tenant_id'] ?? 0) !== $tenantId
                || (int)($feedbackScope['user_id'] ?? 0) !== $userId
                || (int)($feedbackScope['hotel_id'] ?? 0) !== $hotelId
            ) {
                $feedback = [
                    'status' => 'unavailable',
                    'reason_code' => 'feedback_context_scope_mismatch',
                    'items' => [],
                ];
            }
        }
        $preferredPlatform = '';
        $preferenceRefs = [];
        $preferenceItem = null;
        foreach ((array)($preferences['items'] ?? []) as $item) {
            if (!is_array($item)
                || ($item['consumable'] ?? false) !== true
                || (string)($item['preference_key'] ?? '') !== 'preferred_platform'
                || (string)($item['learning_status'] ?? '') !== 'explicit_confirmed'
                || (string)($item['lifecycle_status'] ?? '') !== 'active'
                || (int)($item['tenant_id'] ?? 0) !== $tenantId
                || (int)($item['user_id'] ?? 0) !== $userId
                || ((string)($item['scope'] ?? '') === 'hotel'
                    && (int)($item['hotel_id'] ?? 0) !== $hotelId)
            ) {
                continue;
            }
            $value = strtolower(trim((string)($item['value'] ?? '')));
            if (!in_array($value, ['ctrip', 'meituan', 'all_ota'], true)) {
                continue;
            }
            $preferredPlatform = $value;
            $preferenceItem = $item;
            $id = max(0, (int)($item['id'] ?? 0));
            if ($id > 0) {
                $preferenceRefs[] = 'user_learning_preference#' . $id;
            }
            break;
        }

        $feedbackByFeature = [];
        $feedbackProgressByFeature = [];
        foreach ((array)($feedback['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $identity = strtolower(trim((string)($item['feature_identity'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1) {
                continue;
            }
            $feedbackProgressByFeature[$identity] = [
                'feature_identity' => $identity,
                'status' => (string)($item['status'] ?? ''),
                'sample_count' => max(0, (int)($item['sample_count'] ?? 0)),
                'minimum_samples' => max(20, (int)($item['minimum_samples'] ?? 20)),
                'unique_business_date_count' => max(
                    0,
                    (int)($item['unique_business_date_count'] ?? $item['sample_count'] ?? 0)
                ),
                'duplicate_sample_count' => max(0, (int)($item['duplicate_sample_count'] ?? 0)),
                'sample_digest' => (string)($item['sample_digest'] ?? ''),
            ];
            if (($item['eligible'] ?? false) !== true
                || (string)($item['status'] ?? '') !== 'ready'
                || (int)($item['sample_count'] ?? 0) < 20
                || (int)($item['minimum_samples'] ?? 0) < 20
                || !in_array((int)($item['adjustment'] ?? 0), [-1, 0, 1], true)
                || preg_match('/^[a-f0-9]{64}$/D', (string)($item['sample_digest'] ?? '')) !== 1
            ) {
                continue;
            }
            $feedbackByFeature[$identity] = $item;
        }

        $ranked = [];
        foreach ($topTie as $candidate) {
            $featureIdentity = self::featureIdentity($candidate);
            $feedbackItem = $feedbackByFeature[$featureIdentity] ?? [];
            $feedbackAdjustment = ($feedbackItem['eligible'] ?? false) === true
                ? max(-1, min(1, (int)($feedbackItem['adjustment'] ?? 0)))
                : 0;
            $ranked[] = [
                'candidate' => $candidate,
                'feature_identity' => $featureIdentity,
                'preference_adjustment' => $preferredPlatform !== ''
                    && (string)($candidate['scope']['platform'] ?? '') === $preferredPlatform
                        ? 1
                        : 0,
                'feedback_adjustment' => $feedbackAdjustment,
                'feedback' => $feedbackItem,
            ];
        }
        usort($ranked, static fn(array $left, array $right): int => (
            (int)$right['preference_adjustment'] <=> (int)$left['preference_adjustment']
        ) ?: (
            (int)$right['feedback_adjustment'] <=> (int)$left['feedback_adjustment']
        ) ?: strcmp(
            (string)$left['candidate']['candidate_key'],
            (string)$right['candidate']['candidate_key']
        ));

        $winner = $ranked[0];
        $preferenceDistinguishes = count(array_unique(array_column(
            $ranked,
            'preference_adjustment'
        ))) > 1;
        $feedbackDistinguishes = count(array_unique(array_column(
            $ranked,
            'feedback_adjustment'
        ))) > 1;
        $selected = $this->base->explainPreparedCandidate(
            (array)$winner['candidate'],
            count($topTie)
        );
        $currentFeedback = $this->loadCurrentFeedback(
            $tenantId,
            $userId,
            $hotelId,
            $businessDate,
            $selected
        );
        $baseKey = (string)$baseSelected['candidate_key'];
        $selectedKey = (string)$selected['candidate_key'];
        $appliedAdjustments = [];
        $notAppliedReasons = [];
        if (in_array($preferredPlatform, ['ctrip', 'meituan'], true)
            && $preferenceDistinguishes
        ) {
            $appliedAdjustments[] = [
                'kind' => 'explicit_confirmed_preference',
                'key' => 'preferred_platform',
                'value' => $preferredPlatform,
                'adjustment' => (int)$winner['preference_adjustment'],
                'source_refs' => $preferenceRefs,
            ];
        } elseif ($preferredPlatform === '') {
            $notAppliedReasons[] = ($preferences['status'] ?? '') === 'migration_required'
                ? 'preference_migration_required'
                : 'no_explicit_confirmed_platform_preference';
        } elseif ($preferredPlatform === 'all_ota') {
            $notAppliedReasons[] = 'preferred_platform_all_ota_is_neutral';
        } else {
            $notAppliedReasons[] = 'preference_does_not_distinguish_base_tie';
        }
        $winnerFeedback = is_array($winner['feedback'] ?? null)
            ? $winner['feedback']
            : [];
        $feedbackRanked = $ranked;
        usort($feedbackRanked, static fn(array $left, array $right): int => (
            (int)$right['feedback_adjustment'] <=> (int)$left['feedback_adjustment']
        ) ?: strcmp(
            (string)$left['candidate']['candidate_key'],
            (string)$right['candidate']['candidate_key']
        ));
        $feedbackConflictsWithPreference = $preferredPlatform !== ''
            && $preferenceDistinguishes
            && $feedbackDistinguishes
            && (string)($feedbackRanked[0]['candidate']['candidate_key'] ?? '') !== $selectedKey;
        if ($feedbackDistinguishes && !$feedbackConflictsWithPreference) {
            $feedbackSourceRefs = [];
            $feedbackComparison = [];
            foreach ($ranked as $rankedItem) {
                $itemFeedback = is_array($rankedItem['feedback'] ?? null)
                    ? $rankedItem['feedback']
                    : [];
                if (($itemFeedback['eligible'] ?? false) !== true) {
                    continue;
                }
                $feedbackSourceRefs = array_merge(
                    $feedbackSourceRefs,
                    (array)($itemFeedback['source_refs'] ?? [])
                );
                $feedbackComparison[] = [
                    'candidate_key' => (string)$rankedItem['candidate']['candidate_key'],
                    'feature_identity' => (string)$rankedItem['feature_identity'],
                    'adjustment' => (int)$rankedItem['feedback_adjustment'],
                    'sample_count' => (int)($itemFeedback['sample_count'] ?? 0),
                    'sample_digest' => (string)($itemFeedback['sample_digest'] ?? ''),
                ];
            }
            $appliedAdjustments[] = [
                'kind' => 'feedback',
                'feature_identity' => (string)$winner['feature_identity'],
                'adjustment' => (int)$winner['feedback_adjustment'],
                'sample_count' => max(array_map(
                    static fn(array $item): int => (int)($item['sample_count'] ?? 0),
                    $feedbackComparison
                )),
                'minimum_samples' => 20,
                'sample_digest' => preg_match(
                    '/^[a-f0-9]{64}$/D',
                    (string)($winnerFeedback['sample_digest'] ?? '')
                ) === 1
                    ? (string)$winnerFeedback['sample_digest']
                    : self::digest($feedbackComparison),
                'source_refs' => array_values(array_unique($feedbackSourceRefs)),
                'compared_candidates' => $feedbackComparison,
            ];
        } elseif ($feedbackConflictsWithPreference) {
            $notAppliedReasons[] = 'feedback_conflicts_with_explicit_preference';
        } elseif (($feedback['status'] ?? '') === 'insufficient_samples') {
            $notAppliedReasons[] = 'feedback_insufficient_samples';
        } elseif (($feedback['status'] ?? '') === 'unavailable') {
            $notAppliedReasons[] = 'feedback_context_unavailable';
        } elseif (!$feedbackDistinguishes) {
            $notAppliedReasons[] = 'feedback_does_not_distinguish_base_tie';
        }

        $contextPayload = [
            'scope' => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
            ],
            'preference_status' => (string)($preferences['status'] ?? 'unavailable'),
            'preference_refs' => $preferenceRefs,
            'preference_value_hash' => (string)($preferenceItem['value_hash'] ?? ''),
            'feedback_status' => (string)($feedback['status'] ?? 'unavailable'),
            'feedback_contract' => (string)($feedback['contract_version'] ?? ''),
            'feedback_items' => array_map(
                static fn(array $item): array => [
                    'feature_identity' => (string)($item['feature_identity'] ?? ''),
                    'status' => (string)($item['status'] ?? ''),
                    'adjustment' => (int)($item['adjustment'] ?? 0),
                    'sample_count' => max(0, (int)($item['sample_count'] ?? 0)),
                    'minimum_samples' => max(20, (int)($item['minimum_samples'] ?? 20)),
                    'unique_business_date_count' => max(
                        0,
                        (int)($item['unique_business_date_count'] ?? $item['sample_count'] ?? 0)
                    ),
                    'duplicate_sample_count' => max(0, (int)($item['duplicate_sample_count'] ?? 0)),
                    'sample_digest' => (string)($item['sample_digest'] ?? ''),
                ],
                array_values(array_filter((array)($feedback['items'] ?? []), 'is_array'))
            ),
        ];
        $contextDigest = self::digest($contextPayload);
        $selectionChanged = $selectedKey !== $baseKey;
        $status = $appliedAdjustments === [] ? 'not_applied' : 'applied';
        $appliedKinds = array_values(array_unique(array_map(
            static fn(array $adjustment): string => (string)($adjustment['kind'] ?? ''),
            $appliedAdjustments
        )));
        $feedbackProgress = array_values(array_filter(array_map(
            static fn(array $item): ?array => $feedbackProgressByFeature[(string)$item['feature_identity']] ?? null,
            $ranked
        ), 'is_array'));
        $maximumFeedbackSamples = $feedbackProgress === []
            ? 0
            : max(array_map(
                static fn(array $item): int => (int)$item['unique_business_date_count'],
                $feedbackProgress
            ));
        $feedbackMinimumSamples = $feedbackProgress === []
            ? 20
            : max(array_map(
                static fn(array $item): int => (int)$item['minimum_samples'],
                $feedbackProgress
            ));
        $whyYou = $status !== 'applied'
            ? (in_array('feedback_insufficient_samples', $notAppliedReasons, true)
                && $maximumFeedbackSamples > 0
                    ? '当前并列候选的历史反馈最多为 '
                        . $maximumFeedbackSamples . '/' . $feedbackMinimumSamples
                        . ' 个独立营业日样本，尚未参与排序；公共基础顺序保持不变。'
                    : '当前个人偏好或反馈没有改变四维基础并列组的默认顺序。')
            : (in_array('explicit_confirmed_preference', $appliedKinds, true)
                && in_array('feedback', $appliedKinds, true)
                    ? '只在四维基础完全并列的合格事项中，按你已确认的平台偏好和达到门槛的历史反馈共同调整了个人预览顺序。'
                    : (in_array('explicit_confirmed_preference', $appliedKinds, true)
                        ? '只在四维基础完全并列的合格事项中，按你已确认的平台偏好调整了个人预览顺序。'
                        : '只在四维基础完全并列的合格事项中，按达到门槛的历史反馈调整了个人预览顺序。'));
        $decisionPayload = [
            'base_selected_candidate_key' => $baseKey,
            'selected_candidate_key' => $selectedKey,
            'base_rank' => DailyOneThingService::baseRankKey($selected),
            'base_tie_group_size' => count($topTie),
            'applied_adjustments' => $appliedAdjustments,
            'context_digest' => $contextDigest,
        ];
        $receipt = [
            'contract_version' => self::CONTRACT_VERSION,
            'experience_version' => self::EXPERIENCE_VERSION,
            'status' => $status,
            'application_mode' => 'base_rank_exact_tie_break_only',
            'scope' => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
            ],
            'base_selected_candidate_key' => $baseKey,
            'selected_candidate_key' => $selectedKey,
            'selection_changed' => $selectionChanged,
            'base_rank' => DailyOneThingService::baseRankKey($selected),
            'base_tie_group_size' => count($topTie),
            'why_you' => [
                'summary' => $whyYou,
                'reason_code' => $status === 'applied'
                    ? 'confirmed_preference_or_feedback_tie_break'
                    : 'personalization_not_applied',
            ],
            'applied_adjustments' => $appliedAdjustments,
            'not_applied_reasons' => array_values(array_unique($notAppliedReasons)),
            'preference_refs' => $preferenceRefs,
            'feedback_refs' => array_values(array_unique(array_merge(...array_map(
                static fn(array $adjustment): array => $adjustment['kind'] === 'feedback'
                    ? (array)($adjustment['source_refs'] ?? [])
                    : [],
                $appliedAdjustments
            )))),
            'feedback_progress' => $feedbackProgress,
            'current_feedback' => $currentFeedback,
            'context_digest' => $contextDigest,
            'decision_digest' => self::digest($decisionPayload),
            'candidate_preferences_consumed' => false,
            'facts_changed' => false,
            'eligibility_changed' => false,
            'business_rank_changed' => false,
            'permissions_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];

        $personalized = $baseResult;
        $personalized['selected'] = $selected;
        $personalized['headline'] = (string)$selected['problem'];
        return $this->withReceipt($personalized, $receipt);
    }

    /**
     * Persist one explicit user judgement about the exact preview shown.
     *
     * @return array<string,mixed>
     */
    public function recordFeedback(
        int $tenantId,
        int $userId,
        int $hotelId,
        string $businessDate,
        array $selected,
        array $receipt,
        string $sourceDigest,
        string $feedbackStatus,
        string $reasonCode,
        string $idempotencyKey
    ): array {
        $this->assertScope($tenantId, $userId, $hotelId);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($selected['content_digest'] ?? '')) !== 1
            || !hash_equals((string)$selected['content_digest'], DailyOneThingService::digest($selected))
            || (int)($selected['scope']['tenant_id'] ?? 0) !== $tenantId
            || (int)($selected['scope']['hotel_id'] ?? 0) !== $hotelId
            || (string)($selected['scope']['business_date'] ?? '') !== $businessDate
            || preg_match('/^[a-f0-9]{64}$/D', (string)($selected['material_identity_digest'] ?? '')) !== 1
            || !hash_equals(
                (string)$selected['material_identity_digest'],
                DailyOneThingService::materialIdentityDigest($selected)
            )
            || (string)($receipt['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (int)($receipt['scope']['tenant_id'] ?? 0) !== $tenantId
            || (int)($receipt['scope']['user_id'] ?? 0) !== $userId
            || (int)($receipt['scope']['hotel_id'] ?? 0) !== $hotelId
            || preg_match('/^[a-f0-9]{64}$/D', (string)($receipt['context_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string)($receipt['decision_digest'] ?? '')) !== 1
        ) {
            throw new InvalidArgumentException('每日事项个性化反馈范围或摘要无效');
        }
        $allowed = [
            'accepted' => ['useful'],
            'rejected' => ['wrong_focus'],
        ];
        $feedbackStatus = strtolower(trim($feedbackStatus));
        $reasonCode = strtolower(trim($reasonCode));
        if (!isset($allowed[$feedbackStatus])
            || !in_array($reasonCode, $allowed[$feedbackStatus], true)
        ) {
            throw new InvalidArgumentException('每日事项反馈状态与原因不匹配');
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 96) {
            throw new InvalidArgumentException('每日事项反馈重试标识无效');
        }
        $featureDimensions = self::featureDimensions($selected);
        $featureIdentity = self::featureIdentity($selected);
        $suggestionKey = self::feedbackSuggestionKey($businessDate, $selected);
        try {
            $snapshot = $this->calibration->readExact(
                $tenantId,
                $userId,
                $hotelId,
                $suggestionKey
            );
            if (is_array($snapshot)) {
                $this->assertStableFeedbackSnapshot($snapshot, $businessDate, $selected);
            } else {
                $snapshot = $this->calibration->freezeSuggestion([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'hotel_id' => $hotelId,
                    'suggestion_key' => $suggestionKey,
                    'scenario' => self::SCENARIO,
                    'source_key' => self::SOURCE_KEY,
                    'source_version' => DailyOneThingInputService::CONTRACT_VERSION,
                    'evidence_digest' => $sourceDigest,
                    'suggestion_payload' => [
                        'feature_key' => 'daily_one_thing',
                        'feature_identity' => $featureIdentity,
                        'feature_dimensions' => $featureDimensions,
                        'candidate_key' => (string)$selected['candidate_key'],
                        'candidate_material_digest' => (string)$selected['material_identity_digest'],
                        'business_date' => $businessDate,
                        'sample_identity_contract' => 'one_user_hotel_business_date_feature_material.v1',
                    ],
                    'idempotency_key' => 'freeze_' . $suggestionKey,
                ]);
            }

            $feedbackEvents = array_values(array_filter(
                (array)($snapshot['feedback_events'] ?? []),
                'is_array'
            ));
            $feedback = $feedbackEvents === [] ? null : $feedbackEvents[array_key_last($feedbackEvents)];
            if (is_array($feedback)) {
                if ((string)($feedback['feedback_status'] ?? '') !== $feedbackStatus
                    || (string)($feedback['reason_code'] ?? '') !== $reasonCode
                ) {
                    throw new RuntimeException(
                        '该营业日这一类个人重点已记录反馈；当前版本不允许静默覆盖原反馈',
                        409
                    );
                }
                $feedback['created'] = false;
                $feedback['idempotent_replay'] = true;
            } else {
                $feedback = $this->calibration->appendFeedback([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'hotel_id' => $hotelId,
                    'suggestion_key' => $suggestionKey,
                    'feedback_status' => $feedbackStatus,
                    'reason_code' => $reasonCode,
                    'feedback_payload' => [
                        'surface' => 'operating_opportunity_daily_preview',
                        'business_date' => $businessDate,
                        'feature_identity' => $featureIdentity,
                        'selection_digest' => (string)$selected['content_digest'],
                        'context_digest' => (string)$receipt['context_digest'],
                        'decision_digest' => (string)$receipt['decision_digest'],
                        'personalization_status' => (string)($receipt['status'] ?? 'not_applied'),
                    ],
                    'idempotency_key' => self::FEEDBACK_SLOT_IDEMPOTENCY_KEY,
                ]);
            }
            $adjustments = $this->calibration->buildDailyRankingAdjustments([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
            ]);
        } catch (Throwable $error) {
            $message = strtolower($error->getMessage());
            if (str_contains($message, 'no such table')
                || str_contains($message, "doesn't exist")
                || str_contains($message, 'base table or view not found')
            ) {
                throw new RuntimeException('个人学习数据表未就绪，请先执行数据库迁移', 503, $error);
            }
            if ((int)$error->getCode() === 409
                && !preg_match('/[\x{4e00}-\x{9fff}]/u', $error->getMessage())
            ) {
                throw new RuntimeException('该营业日这一类个人重点已记录反馈，请刷新查看', 409, $error);
            }
            throw $error;
        }
        return [
            'contract_version' => 'daily_one_thing_personalization_feedback.v1',
            'snapshot' => $snapshot,
            'feedback' => $feedback,
            'adjustments' => $adjustments,
            'readback_verified' => ($snapshot['readback_verified'] ?? false) === true
                && ($feedback['readback_verified'] ?? false) === true,
            'feedback_slot' => [
                'contract_version' => 'daily_one_thing_feedback_slot.v1',
                'identity_mode' => 'user_hotel_business_date_feature_material',
                'maximum_feedback_events' => 1,
                'server_owned_slot_idempotency' => true,
                'client_retry_marker_validated' => true,
            ],
            'facts_changed' => false,
            'permissions_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @return array<string,string> */
    public static function featureDimensions(array $candidate): array
    {
        return [
            'source_type' => strtolower(trim((string)($candidate['source_type'] ?? ''))),
            'platform' => strtolower(trim((string)($candidate['scope']['platform'] ?? ''))),
            'action_type' => strtolower(trim((string)($candidate['recommended_action']['type'] ?? ''))),
            'metric_key' => strtolower(trim((string)($candidate['expected_observation_metric']['key'] ?? ''))),
        ];
    }

    public static function featureIdentity(array $candidate): string
    {
        return self::digest([
            'contract_version' => 'daily_one_thing_feature_identity.v1',
            'dimensions' => self::featureDimensions($candidate),
        ]);
    }

    private static function feedbackSuggestionKey(string $businessDate, array $candidate): string
    {
        return 'daily_preview_' . str_replace('-', '', $businessDate)
            . '_' . substr(self::featureIdentity($candidate), 0, 24)
            . '_' . substr(DailyOneThingService::materialIdentityDigest($candidate), 0, 24);
    }

    /** @return array<string,mixed> */
    private function loadCurrentFeedback(
        int $tenantId,
        int $userId,
        int $hotelId,
        string $businessDate,
        array $selected
    ): array {
        try {
            $snapshot = $this->calibration->readExact(
                $tenantId,
                $userId,
                $hotelId,
                self::feedbackSuggestionKey($businessDate, $selected)
            );
            if (!is_array($snapshot)) {
                return [
                    'status' => 'not_recorded',
                    'readback_verified' => true,
                    'feedback_status' => null,
                    'reason_code' => null,
                    'feedback_ref' => null,
                ];
            }
            $this->assertStableFeedbackSnapshot($snapshot, $businessDate, $selected);
            $events = array_values(array_filter(
                (array)($snapshot['feedback_events'] ?? []),
                'is_array'
            ));
            if ($events === []) {
                return [
                    'status' => 'not_recorded',
                    'readback_verified' => true,
                    'feedback_status' => null,
                    'reason_code' => null,
                    'feedback_ref' => null,
                ];
            }
            $latest = $events[array_key_last($events)];
            return [
                'status' => 'recorded',
                'readback_verified' => ($latest['readback_verified'] ?? false) === true,
                'feedback_status' => (string)($latest['feedback_status'] ?? ''),
                'reason_code' => (string)($latest['reason_code'] ?? ''),
                'feedback_ref' => 'ai_suggestion_calibration_feedback_events#'
                    . max(0, (int)($latest['id'] ?? 0)),
                'recorded_at' => (string)($latest['created_at'] ?? ''),
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'readback_verified' => false,
                'reason_code' => 'daily_preview_feedback_readback_unavailable',
                'feedback_status' => null,
                'feedback_ref' => null,
            ];
        }
    }

    private function assertStableFeedbackSnapshot(
        array $snapshot,
        string $businessDate,
        array $selected
    ): void {
        $payload = is_array($snapshot['suggestion_payload'] ?? null)
            ? $snapshot['suggestion_payload']
            : [];
        if ((string)($payload['feature_key'] ?? '') !== 'daily_one_thing'
            || (string)($payload['feature_identity'] ?? '') !== self::featureIdentity($selected)
            || (string)($payload['candidate_material_digest'] ?? '')
                !== DailyOneThingService::materialIdentityDigest($selected)
            || (string)($payload['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('每日事项反馈稳定槽位身份冲突', 409);
        }
    }

    /** @return array<string,mixed> */
    private function loadPreferences(int $tenantId, int $userId, int $hotelId): array
    {
        try {
            $context = ($this->preferenceLoader)($tenantId, $userId, $hotelId);
            return is_array($context) ? $context : ['status' => 'unavailable', 'items' => []];
        } catch (Throwable $error) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'preference_context_unavailable',
                'items' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function loadFeedback(int $tenantId, int $userId, int $hotelId): array
    {
        try {
            $context = ($this->feedbackLoader)($tenantId, $userId, $hotelId);
            return is_array($context) ? $context : ['status' => 'unavailable', 'items' => []];
        } catch (Throwable $error) {
            return [
                'status' => 'unavailable',
                'reason_code' => 'feedback_context_unavailable',
                'items' => [],
            ];
        }
    }

    /** @return array<string,mixed> */
    private function emptyReceipt(
        int $tenantId,
        int $userId,
        int $hotelId,
        string $status,
        array $reasons,
        string $baseKey = '',
        string $selectedKey = '',
        array $baseRank = [],
        int $tieSize = 0,
        array $currentFeedback = []
    ): array {
        $contextDigest = self::digest([
            'scope' => [$tenantId, $userId, $hotelId],
            'status' => $status,
            'reasons' => array_values($reasons),
        ]);
        $decision = [
            'base_selected_candidate_key' => $baseKey,
            'selected_candidate_key' => $selectedKey,
            'base_rank' => $baseRank,
            'base_tie_group_size' => $tieSize,
            'context_digest' => $contextDigest,
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'experience_version' => self::EXPERIENCE_VERSION,
            'status' => $status,
            'application_mode' => 'base_rank_exact_tie_break_only',
            'scope' => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'hotel_id' => $hotelId,
            ],
            'base_selected_candidate_key' => $baseKey,
            'selected_candidate_key' => $selectedKey,
            'selection_changed' => false,
            'base_rank' => $baseRank,
            'base_tie_group_size' => $tieSize,
            'why_you' => [
                'summary' => '当前没有使用个人偏好或反馈改变公共基础排序。',
                'reason_code' => 'personalization_not_applied',
            ],
            'applied_adjustments' => [],
            'not_applied_reasons' => array_values($reasons),
            'preference_refs' => [],
            'feedback_refs' => [],
            'feedback_progress' => [],
            'current_feedback' => $currentFeedback !== [] ? $currentFeedback : [
                'status' => 'not_available',
                'readback_verified' => false,
                'reason_code' => 'no_selected_candidate',
                'feedback_status' => null,
                'feedback_ref' => null,
            ],
            'context_digest' => $contextDigest,
            'decision_digest' => self::digest($decision),
            'candidate_preferences_consumed' => false,
            'facts_changed' => false,
            'eligibility_changed' => false,
            'business_rank_changed' => false,
            'permissions_changed' => false,
            'approval_changed' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function withReceipt(array $result, array $receipt): array
    {
        $result['experience_version'] = self::EXPERIENCE_VERSION;
        $result['personalization_receipt'] = $receipt;
        if (($receipt['status'] ?? '') === 'applied'
            && is_array($result['selected']['recommendation_explanation'] ?? null)
        ) {
            $explanation = $result['selected']['recommendation_explanation'];
            unset($explanation['personalization']);
            $explanation['personalization_receipt_authoritative'] = true;
            $explanation['why_recommended']['summary'] =
                '该事项位于最高四维业务并列组；个人预览按同一响应中的个性化回执完成并列选择。';
            $explanation['why_recommended']['reason_code'] =
                'highest_base_rank_personalized_receipt_tie_break';
            $result['selected']['recommendation_explanation'] = $explanation;
            $result['selected']['content_digest'] = DailyOneThingService::digest($result['selected']);
        }
        $result['selection_policy']['personalization_applied'] =
            ($receipt['status'] ?? '') === 'applied';
        $result['selection_policy']['personalization_mode'] =
            'base_rank_exact_tie_break_only';
        $result['selection_policy']['base_order'] = (string)(
            $result['selection_policy']['order'] ?? ''
        );
        $result['selection_policy']['effective_order'] =
            ($receipt['status'] ?? '') === 'applied'
                ? 'impact_desc_then_urgency_desc_then_evidence_strength_desc_then_execution_cost_asc_then_explicit_confirmed_preference_desc_then_feedback_adjustment_desc_then_candidate_key'
                : (string)($result['selection_policy']['order'] ?? '');
        $result['can_execute'] = false;
        $result['requires_human_approval'] = true;
        $result['external_write_performed'] = false;
        return $result;
    }

    private function assertScope(int $tenantId, int $userId, int $hotelId): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('每日事项个性化范围无效');
        }
    }

    /** @param array<string,mixed> $value */
    private static function digest(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function canonicalJson(mixed $value): string
    {
        return (string)json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
