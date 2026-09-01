<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Promotes one source-verified Q&A action draft into the existing manual
 * approval workflow. It never approves, executes, collects, or writes OTA.
 */
final class OperatingQuestionExecutionBridgeService
{
    public const SOURCE_MODULE = 'operating_question';
    public const CONTRACT_VERSION = 'operating_question_execution_bridge.v3';
    /** @var list<string> */
    private const SUPPORTED_CURRENCY_CODES = [
        'CNY', 'USD', 'HKD', 'MOP', 'TWD', 'JPY', 'KRW', 'EUR', 'GBP', 'SGD', 'THB', 'MYR', 'AUD', 'CAD',
    ];

    /** @var list<string> */
    private const SUPPORTED_NON_CURRENCY_UNITS = [
        'percent', 'ratio_0_1', 'score_5_point', 'exposure_count', 'order_count',
        'count', 'room_night_count', 'visitor_count',
    ];

    public function __construct(
        private readonly OperatingQuestionService $questionService = new OperatingQuestionService(),
        private readonly OperationManagementService $operationService = new OperationManagementService()
    ) {
    }

    /**
     * @param list<int> $hotelIds
     * @return array<string,mixed>
     */
    public function createIntent(
        int $questionId,
        int $actionIndex,
        int $tenantId,
        array $hotelIds,
        int $userId
    ): array {
        $hotelIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $hotelIds
        ), static fn(int $id): bool => $id > 0)));
        if ($questionId <= 0 || $actionIndex < 0 || $hotelIds === []) {
            throw new InvalidArgumentException('经营问答行动草案身份无效');
        }

        $question = $this->questionService->read($questionId, $tenantId, $hotelIds);
        $action = $this->eligibleAction($question, $actionIndex);
        $hotelId = (int)$question['hotel_id'];
        $evidenceRefs = array_values(array_unique([
            'hotel_operating_questions#' . $questionId,
            ...(array)$action['evidence_refs'],
        ]));
        $decisionQuality = is_array($action['decision_quality'] ?? null)
            ? $action['decision_quality']
            : [];
        $lifecycle = new OperationActionLifecycleService();
        $actionCard = $lifecycle->withActionIndex(
            $lifecycle->buildPendingCard($question, $action, max(1, $userId)),
            $actionIndex
        );
        [$existing, $idempotencyKey] = $this->findExistingIntent(
            $question,
            $action,
            $actionIndex,
            $hotelIds,
            $actionCard
        );
        if (is_array($existing) && (string)($existing['status'] ?? '') !== 'pending_approval') {
            throw new InvalidArgumentException('该经营问答行动草案已经结束审批；请重新提问并生成新的待审批草案');
        }
        $metricBaseline = $this->metricBaseline($question, $action);
        $baselineValue = $actionCard['metric_contract']['baseline_window']['value'];
        $workflowSchedule = [
            'assignee_id' => (int)$actionCard['responsibility']['owner_id'],
            'due_at' => (string)$actionCard['responsibility']['due_at'],
            'review_at' => (string)$actionCard['metric_contract']['followup_window']['review_at'],
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];

        $intent = $existing ?? $this->operationService->createExecutionIntent(
            $hotelIds,
            $hotelId,
            [
                'source_module' => self::SOURCE_MODULE,
                'source_record_id' => $questionId,
                'hotel_id' => $hotelId,
                'platform' => (string)$question['platform'],
                'object_type' => 'operation_checklist',
                'action_type' => 'human_reviewed_operating_check',
                'date_start' => (string)$question['date_start'],
                'date_end' => (string)$question['date_end'],
                'current_value' => [
                    'question_id' => $questionId,
                    'question_content_digest' => (string)$question['content_digest'],
                    'claims_digest' => (string)$action['claims_digest'],
                    'question_scope' => $this->questionScope($question),
                    'metric_baseline' => $metricBaseline,
                    (string)$action['expected_metric'] => $baselineValue,
                ],
                'target_value' => [
                    'title' => (string)$action['title'],
                    'action_text' => (string)$action['action'],
                    'action_object' => (string)($action['action_object'] ?? ''),
                    'steps' => array_values((array)$action['execution_steps']),
                    'acceptance_criteria' => array_values(array_unique([
                        '按同酒店、同渠道、同日期口径复核 ' . (string)$action['expected_metric'],
                        '到期复核窗口：' . (string)$action['review_window'],
                        ...array_map(
                            static fn(mixed $item): string => '停止条件：' . trim((string)$item),
                            (array)$action['stop_conditions']
                        ),
                    ])),
                    'review_window' => (string)$action['review_window'],
                    'stop_conditions' => array_values((array)$action['stop_conditions']),
                    'assignee_id' => (int)$workflowSchedule['assignee_id'],
                    'workflow_schedule' => $workflowSchedule,
                    'action_card' => $actionCard,
                    'execution_mode' => 'manual',
                    'auto_write_ota' => false,
                ],
                'evidence' => [
                    'bridge_contract_version' => self::CONTRACT_VERSION,
                    'source_policy' => 'source_verified_question_action_then_human_approval',
                    'question_id' => $questionId,
                    'question_content_digest' => (string)$question['content_digest'],
                    'question_answer_status' => (string)$question['answer_status'],
                    'question_contract_version' => (string)($question['answer']['contract_version'] ?? ''),
                    'provider_response_id' => (string)($question['answer']['ai_runtime']['provider_response_id'] ?? ''),
                    'question_scope' => $this->questionScope($question),
                    'question_metric_contract' => (array)($question['answer']['question_metric_contract'] ?? []),
                    'action_index' => $actionIndex,
                    'action_draft_digest' => (string)$action['action_digest'],
                    'claims_digest' => (string)$action['claims_digest'],
                    'basis_claim_ids' => array_values((array)$action['basis_claim_ids']),
                    'basis_claims_digest' => (string)$action['basis_claims_digest'],
                    'evidence_refs' => $evidenceRefs,
                    'source_refs' => array_values((array)$action['evidence_refs']),
                    'decision_recommendation' => $action,
                    'decision_quality' => $decisionQuality,
                    'ai_runtime' => (array)($question['answer']['ai_runtime'] ?? []),
                    'boundaries' => (array)$action['boundaries'],
                    'workflow_schedule' => $workflowSchedule,
                    'action_card' => $actionCard,
                    'automatic_collection' => false,
                    'automatic_execution' => false,
                    'automatic_ota_write' => false,
                    'external_message' => false,
                ],
                'expected_metric' => (string)$action['expected_metric'],
                'expected_delta' => null,
                'risk_level' => (string)($action['risk']['level'] ?? $action['risk_level'] ?? 'medium'),
                'status' => 'pending_approval',
            ],
            max(0, $userId),
            false,
            $idempotencyKey,
            true
        );
        $this->assertIntentReadback($intent, $question, $action, $actionIndex, $actionCard);
        if ((string)($intent['status'] ?? '') !== 'pending_approval') {
            throw new InvalidArgumentException('经营问答桥接只能返回待人工审批的行动草案');
        }

        return [
            'execution_intent' => $intent,
            'source_question_id' => $questionId,
            'action_index' => $actionIndex,
            'reused_existing_intent' => is_array($existing),
            'idempotency_key' => $idempotencyKey,
            'next_page' => 'ops-track',
            'source_policy' => 'human_approval_required_no_automatic_ota_write',
        ];
    }

    /**
     * Read back already-created approval intents without creating, approving,
     * collecting, executing, or writing OTA data.
     *
     * @param list<int> $hotelIds
     * @return array<string,mixed>
     */
    public function readExistingIntents(
        int $questionId,
        int $tenantId,
        array $hotelIds
    ): array {
        $hotelIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $hotelIds
        ), static fn(int $id): bool => $id > 0)));
        if ($questionId <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('经营问答待审批任务回读范围无效');
        }
        $question = $this->questionService->read($questionId, $tenantId, $hotelIds);
        if (!$this->questionDigestMatches($question)) {
            return [
                'data_status' => 'invalid',
                'list' => [],
                'data_gaps' => [['code' => 'operating_question_content_digest_invalid']],
            ];
        }
        $actions = is_array($question['answer']['action_drafts'] ?? null)
            ? array_values($question['answer']['action_drafts'])
            : [];
        $list = [];
        $dataGaps = [];
        foreach (array_slice($actions, 0, 1, true) as $actionIndex => $action) {
            if (!is_array($action)) {
                continue;
            }
            $actionDigest = strtolower(trim((string)($action['action_digest'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $actionDigest) !== 1
                || !hash_equals($actionDigest, self::actionDigest($action))
            ) {
                $dataGaps[] = [
                    'code' => 'operating_question_action_digest_invalid',
                    'action_index' => $actionIndex,
                ];
                continue;
            }
            try {
                $lifecycle = new OperationActionLifecycleService();
                $expectedCard = $lifecycle->withActionIndex(
                    $lifecycle->buildPendingCard(
                        $question,
                        $action,
                        max(1, (int)($question['created_by'] ?? 1))
                    ),
                    $actionIndex
                );
                [$intent] = $this->findExistingIntent(
                    $question,
                    $action,
                    $actionIndex,
                    $hotelIds,
                    $expectedCard
                );
            } catch (\Throwable) {
                return [
                    'data_status' => 'unavailable',
                    'list' => [],
                    'data_gaps' => [['code' => 'operation_execution_intent_readback_unavailable']],
                ];
            }
            if (!is_array($intent)) {
                continue;
            }
            try {
                $this->assertIntentReadback($intent, $question, $action, $actionIndex, $expectedCard);
            } catch (\Throwable) {
                $dataGaps[] = [
                    'code' => 'operation_execution_intent_identity_mismatch',
                    'action_index' => $actionIndex,
                ];
                continue;
            }
            $list[] = [
                'action_index' => $actionIndex,
                'execution_intent' => $intent,
            ];
        }
        return [
            'data_status' => $dataGaps === [] ? 'ok' : 'partial',
            'list' => $list,
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * Read-only eligibility adapter for the daily-one-thing selector.
     * It reuses the exact same source, scope, model, digest and evidence gates
     * as intent creation, but never creates, approves or executes anything.
     *
     * @param array<string,mixed> $question
     * @return array<string,mixed>
     */
    public function eligibleActionForDailyOneThing(array $question, int $actionIndex = 0): array
    {
        return $this->eligibleAction($question, $actionIndex);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function assertIntentCurrent(array $intent): array
    {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $questionId = (int)($intent['source_record_id'] ?? 0);
        if ((string)($intent['source_module'] ?? '') !== self::SOURCE_MODULE
            || $hotelId <= 0
            || $tenantId <= 0
            || $questionId <= 0
        ) {
            throw new InvalidArgumentException('经营问答执行意图来源身份无效');
        }
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $actionIndex = (int)($evidence['action_index'] ?? -1);
        $question = $this->questionService->read($questionId, $tenantId, [$hotelId]);
        $action = $this->eligibleAction($question, $actionIndex);
        $this->assertIntentReadback($intent, $question, $action, $actionIndex);
        (new OperationActionLifecycleService())->assertPendingCardCurrent($intent);
        return $action;
    }

    /** @param array<string,mixed> $intent */
    public function isIntentCurrent(array $intent): bool
    {
        try {
            $this->assertIntentCurrent($intent);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $action */
    public static function actionDigest(array $action): string
    {
        unset($action['action_digest']);
        return hash('sha256', json_encode(
            self::canonicalize($action),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @param array<string,mixed> $question
     * @return array<string,mixed>
     */
    private function eligibleAction(array $question, int $actionIndex): array
    {
        if ($actionIndex < 0 || !$this->questionDigestMatches($question)) {
            throw new InvalidArgumentException('经营问答保存内容校验失败，请重新提问');
        }
        $answer = is_array($question['answer'] ?? null) ? $question['answer'] : [];
        $runtime = is_array($answer['ai_runtime'] ?? null) ? $answer['ai_runtime'] : [];
        $claims = $this->verifiedClaims($answer);
        if ((string)($question['answer_status'] ?? '') !== 'answered_by_grounded_ai'
            || preg_match('/^operating-question:v(?:4|6):[a-f0-9]{48}$/D', (string)($question['request_key'] ?? '')) !== 1
            || (string)($answer['contract_version'] ?? '') !== OperatingQuestionService::CONTRACT_VERSION
            || (string)($answer['status'] ?? '') !== 'answered_by_grounded_ai'
            || (string)($runtime['status'] ?? '') !== 'ready'
            || !OperatingQuestionAiAnswerService::directCallProofReady($runtime)
            || (string)($runtime['prompt_version'] ?? '') !== OperatingQuestionAiAnswerService::PROMPT_VERSION
            || !in_array((string)($answer['confidence'] ?? ''), ['medium', 'high'], true)
            || ($runtime['external_llm_called'] ?? false) !== true
            || (string)($runtime['external_llm_call_status'] ?? '')
                !== OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS
            || $claims === []
            || (string)($answer['question_metric_contract']['contract_version'] ?? '')
                !== OperatingQuestionService::METRIC_INTENT_CONTRACT_VERSION
            || (string)($answer['question_metric_contract']['mode'] ?? '') !== 'metric_lookup'
            || ($answer['question_metric_contract']['action_draft_allowed'] ?? false) !== true
            || !$this->modelResponseRegistryMatches($question, $runtime)
        ) {
            throw new InvalidArgumentException('只有当前 DeepSeek 直接生成且严格回读的证据回答才能转为行动草案');
        }
        if (!$this->answerScopeMatches($question, $answer)) {
            throw new InvalidArgumentException('经营问答回答范围已漂移，请重新提问');
        }
        $actions = is_array($answer['action_drafts'] ?? null) ? array_values($answer['action_drafts']) : [];
        $action = $actions[$actionIndex] ?? null;
        if (!is_array($action)) {
            throw new InvalidArgumentException('经营问答没有可用的行动草案');
        }
        $basisClaims = $this->basisClaims($answer, $action, $claims);
        $quality = is_array($action['decision_quality'] ?? null) ? $action['decision_quality'] : [];
        $boundaries = is_array($action['boundaries'] ?? null) ? $action['boundaries'] : [];
        $digest = strtolower(trim((string)($action['action_digest'] ?? '')));
        if ((string)($action['contract_version'] ?? '') !== OperatingQuestionAiAnswerService::ACTION_DRAFT_CONTRACT_VERSION
            || (string)($action['status'] ?? '') !== 'ready_for_human_review'
            || ($action['can_create_execution_intent'] ?? false) !== true
            || (string)($quality['contract_version'] ?? '') !== AiDecisionQualityService::CONTRACT_VERSION
            || ($quality['complete'] ?? false) !== true
            || ($quality['execution_ready'] ?? false) !== true
            || ($boundaries['human_confirmation_required'] ?? false) !== true
            || ($boundaries['automatic_collection'] ?? true) !== false
            || ($boundaries['automatic_execution'] ?? true) !== false
            || ($boundaries['ota_write'] ?? true) !== false
            || ($boundaries['external_message'] ?? true) !== false
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, self::actionDigest($action))
            || $basisClaims === []
            || !$this->actionScopeMatches($question, $action)
            || !$this->actionMetricSemanticsCurrent($action)
            || !$this->actionEvidenceCoverageReady($question, $action, $basisClaims)
            || !$this->currentActionEvidenceMatches($question, $action, $basisClaims)
        ) {
            throw new InvalidArgumentException('行动草案尚未通过当前来源、证据、范围、风险和人工确认门');
        }
        return $action;
    }

    /** @param array<string,mixed> $action */
    private function actionMetricSemanticsCurrent(array $action): bool
    {
        $expectedMetric = trim((string)($action['expected_metric'] ?? ''));
        if ($expectedMetric !== 'list_exposure') {
            return true;
        }
        $effect = is_array($action['expected_effect'] ?? null) ? $action['expected_effect'] : [];
        return (string)($effect['metric'] ?? '') === $expectedMetric
            && (string)($effect['metric_label'] ?? '') === AiDecisionQualityService::LIST_EXPOSURE_METRIC_LABEL;
    }

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $question
     * @param array<string,mixed> $action
     */
    private function assertIntentReadback(
        array $intent,
        array $question,
        array $action,
        int $actionIndex,
        array $expectedCard = []
    ): void {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $embedded = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $targetCard = is_array($target['action_card'] ?? null) ? $target['action_card'] : [];
        $evidenceCard = is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : [];
        $status = (string)($intent['status'] ?? '');
        if ((string)($intent['source_module'] ?? '') !== self::SOURCE_MODULE) {
            $storedCard = $targetCard !== [] ? $targetCard : $evidenceCard;
            if ($expectedCard === []
                || $storedCard === []
                || (int)($intent['id'] ?? 0) <= 0
                || (int)($intent['hotel_id'] ?? 0) !== (int)$question['hotel_id']
                || (int)($intent['tenant_id'] ?? 0) !== (int)$question['tenant_id']
                || (string)($intent['platform'] ?? '') !== (string)$question['platform']
                || (string)($intent['date_start'] ?? '') !== (string)$question['date_start']
                || (string)($intent['date_end'] ?? '') !== (string)$question['date_end']
                || !in_array($status, ['pending_approval', 'approved', 'rejected', 'cancelled'], true)
                || trim((string)($intent['blocked_reason'] ?? '')) !== ''
                || (string)($intent['expected_metric'] ?? '') !== (string)$action['expected_metric']
            ) {
                throw new RuntimeException('跨入口运营行动生命周期回读无效');
            }
            (new OperationActionLifecycleService())->assertEquivalentActionIdentity(
                $expectedCard,
                $storedCard
            );
            return;
        }
        if ((int)($intent['id'] ?? 0) <= 0
            || (string)($intent['source_module'] ?? '') !== self::SOURCE_MODULE
            || (int)($intent['source_record_id'] ?? 0) !== (int)$question['id']
            || (int)($intent['hotel_id'] ?? 0) !== (int)$question['hotel_id']
            || (int)($intent['tenant_id'] ?? 0) !== (int)$question['tenant_id']
            || (string)($intent['platform'] ?? '') !== (string)$question['platform']
            || (string)($intent['date_start'] ?? '') !== (string)$question['date_start']
            || (string)($intent['date_end'] ?? '') !== (string)$question['date_end']
            || (string)($intent['object_type'] ?? '') !== 'operation_checklist'
            || (string)($intent['action_type'] ?? '') !== 'human_reviewed_operating_check'
            || !in_array($status, ['pending_approval', 'approved', 'rejected', 'cancelled'], true)
            || trim((string)($intent['blocked_reason'] ?? '')) !== ''
            || (string)($intent['expected_metric'] ?? '') !== (string)$action['expected_metric']
            || (string)($evidence['bridge_contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (string)($targetCard['contract_version'] ?? '') !== OperationActionLifecycleService::CARD_CONTRACT_VERSION
            || (string)($evidenceCard['contract_version'] ?? '') !== OperationActionLifecycleService::CARD_CONTRACT_VERSION
            || (int)($targetCard['trace']['action_index'] ?? -1) !== $actionIndex
            || self::canonicalize($targetCard) !== self::canonicalize($evidenceCard)
            || self::canonicalize((array)($target['workflow_schedule'] ?? []))
                !== self::canonicalize((array)($evidence['workflow_schedule'] ?? []))
            || (int)($evidence['question_id'] ?? 0) !== (int)$question['id']
            || (string)($evidence['question_contract_version'] ?? '')
                !== OperatingQuestionService::CONTRACT_VERSION
            || (string)($evidence['provider_response_id'] ?? '')
                !== (string)($question['answer']['ai_runtime']['provider_response_id'] ?? '')
            || !hash_equals(
                strtolower((string)$question['content_digest']),
                strtolower(trim((string)($evidence['question_content_digest'] ?? '')))
            )
            || (int)($evidence['action_index'] ?? -1) !== $actionIndex
            || self::canonicalize((array)($evidence['question_metric_contract'] ?? []))
                !== self::canonicalize((array)($question['answer']['question_metric_contract'] ?? []))
            || !hash_equals(
                (string)$action['action_digest'],
                strtolower(trim((string)($evidence['action_draft_digest'] ?? '')))
            )
            || !hash_equals(
                (string)$action['claims_digest'],
                strtolower(trim((string)($evidence['claims_digest'] ?? '')))
            )
            || !hash_equals(
                (string)$action['basis_claims_digest'],
                strtolower(trim((string)($evidence['basis_claims_digest'] ?? '')))
            )
            || array_values((array)($evidence['basis_claim_ids'] ?? []))
                !== array_values((array)$action['basis_claim_ids'])
            || $embedded === []
            || !hash_equals((string)$action['action_digest'], self::actionDigest($embedded))
        ) {
            throw new RuntimeException('经营问答待审批任务保存后精确回读失败');
        }
    }

    /** @param array<string,mixed> $question */
    private function questionDigestMatches(array $question): bool
    {
        $stored = strtolower(trim((string)($question['content_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $stored) !== 1) {
            return false;
        }
        $calculated = hash('sha256', json_encode(
            self::canonicalize([
                'question' => (string)($question['question_text'] ?? ''),
                'answer' => is_array($question['answer'] ?? null) ? $question['answer'] : [],
                'fact_refs' => array_values((array)($question['fact_refs'] ?? [])),
                'memory_refs' => array_values((array)($question['memory_refs'] ?? [])),
                'knowledge_refs' => array_values((array)($question['knowledge_refs'] ?? [])),
                'execution_refs' => array_values((array)($question['execution_refs'] ?? [])),
            ]),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ));
        return hash_equals($stored, $calculated);
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $answer */
    private function answerScopeMatches(array $question, array $answer): bool
    {
        $scope = is_array($answer['scope'] ?? null) ? $answer['scope'] : [];
        return (int)($scope['tenant_id'] ?? 0) === (int)$question['tenant_id']
            && (int)($scope['hotel_id'] ?? 0) === (int)$question['hotel_id']
            && (string)($scope['platform'] ?? '') === (string)$question['platform']
            && (string)($scope['date_start'] ?? '') === (string)$question['date_start']
            && (string)($scope['date_end'] ?? '') === (string)$question['date_end']
            && (string)($scope['source_scope'] ?? '') === 'ota_channel';
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $action */
    private function actionScopeMatches(array $question, array $action): bool
    {
        $scope = is_array($action['scope'] ?? null) ? $action['scope'] : [];
        return (int)($scope['tenant_id'] ?? 0) === (int)$question['tenant_id']
            && (int)($scope['hotel_id'] ?? 0) === (int)$question['hotel_id']
            && (string)($scope['platform'] ?? '') === (string)$question['platform']
            && (string)($scope['date_start'] ?? '') === (string)$question['date_start']
            && (string)($scope['date_end'] ?? '') === (string)$question['date_end']
            && (string)($scope['source_scope'] ?? '') === 'ota_channel';
    }

    /** @param array<string,mixed> $answer @return list<array<string,mixed>> */
    private function verifiedClaims(array $answer): array
    {
        $claims = $answer['fact_claims'] ?? null;
        $digest = strtolower((string)($answer['claims_digest'] ?? ''));
        if (!is_array($claims)
            || !array_is_list($claims)
            || $claims === []
            || count($claims) > 8
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, OperatingQuestionAiAnswerService::claimsDigest($claims))
        ) {
            return [];
        }

        $seen = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                return [];
            }
            $claimId = (string)($claim['claim_id'] ?? '');
            $binding = is_array($claim['binding'] ?? null) ? $claim['binding'] : [];
            if (preg_match('/^claim-[a-f0-9]{16}$/D', $claimId) !== 1
                || isset($seen[$claimId])
                || preg_match('/^online_daily_data#[1-9][0-9]*$/D', (string)($claim['evidence_ref'] ?? '')) !== 1
                || preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', (string)($claim['metric_key'] ?? '')) !== 1
                || preg_match('/^[a-z0-9_.-]+\.v[1-9][0-9]*$/D', (string)($claim['metric_definition_id'] ?? '')) !== 1
                || preg_match('/^[a-z0-9_.:-]{1,100}$/D', (string)($claim['source_metric_key'] ?? '')) !== 1
                || (!is_int($claim['value'] ?? null) && !is_float($claim['value'] ?? null))
                || !$this->realMetricUnit((string)($claim['unit'] ?? ''))
                || !in_array((string)($claim['platform'] ?? ''), ['ctrip', 'meituan', 'qunar'], true)
                || $this->dates((string)($claim['data_date'] ?? ''), (string)($claim['data_date'] ?? '')) === []
                || (string)($binding['storage_field'] ?? '') !== 'online_daily_data.' . (string)$claim['metric_key']
                || preg_match('/^[a-f0-9]{64}$/D', (string)($binding['source_path_digest'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', (string)($binding['field_fact_digest'] ?? '')) !== 1
                || preg_match('/^[a-z0-9_.:-]{1,50}$/D', (string)($binding['source_data_type'] ?? '')) !== 1
                || preg_match('/^[a-z0-9_.:-]{1,100}$/D', (string)($binding['source_key'] ?? '')) !== 1
                || trim((string)($binding['readback_verified_at'] ?? '')) === ''
                || trim((string)($binding['ingestion_method'] ?? '')) === ''
                || preg_match('/^[a-f0-9]{64}$/D', (string)($binding['source_trace_id_digest'] ?? '')) !== 1
                || trim((string)($claim['statement'] ?? '')) === ''
            ) {
                return [];
            }
            $identity = [
                'evidence_ref' => (string)$claim['evidence_ref'],
                'metric_key' => (string)$claim['metric_key'],
                'metric_definition_id' => (string)$claim['metric_definition_id'],
                'source_metric_key' => (string)$claim['source_metric_key'],
                'metric_label' => (string)($claim['metric_label'] ?? ''),
                'value' => $claim['value'],
                'unit' => (string)$claim['unit'],
                'platform' => (string)$claim['platform'],
                'data_date' => (string)$claim['data_date'],
                'binding' => $binding,
            ];
            $expectedId = 'claim-' . substr(hash('sha256', json_encode(
                $identity,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )), 0, 16);
            if (!hash_equals($expectedId, $claimId)) {
                return [];
            }
            $seen[$claimId] = true;
        }
        return $claims;
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $runtime */
    private function modelResponseRegistryMatches(array $question, array $runtime): bool
    {
        $providerResponseId = $this->providerResponseId($runtime['provider_response_id'] ?? null);
        $provider = strtolower(trim((string)($runtime['provider'] ?? '')));
        $digest = strtolower(trim((string)($question['content_digest'] ?? '')));
        if ($providerResponseId === ''
            || preg_match('/^[a-z0-9_.:-]{2,50}$/D', $provider) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || (int)($question['id'] ?? 0) <= 0
            || (int)($question['tenant_id'] ?? 0) <= 0
            || (int)($question['hotel_id'] ?? 0) <= 0
        ) {
            return false;
        }
        try {
            $registry = Db::name(OperatingQuestionService::MODEL_RESPONSE_REGISTRY_TABLE)
                ->where('provider_response_id', $providerResponseId)
                ->find();
        } catch (\Throwable) {
            return false;
        }
        return is_array($registry)
            && (string)($registry['provider_response_id'] ?? '') === $providerResponseId
            && (string)($registry['provider'] ?? '') === $provider
            && (int)($registry['question_id'] ?? 0) === (int)$question['id']
            && (int)($registry['tenant_id'] ?? 0) === (int)$question['tenant_id']
            && (int)($registry['hotel_id'] ?? 0) === (int)$question['hotel_id']
            && hash_equals($digest, strtolower(trim((string)($registry['question_content_digest'] ?? ''))));
    }

    /**
     * @param array<string,mixed> $answer
     * @param array<string,mixed> $action
     * @param list<array<string,mixed>> $claims
     * @return list<array<string,mixed>>
     */
    private function basisClaims(array $answer, array $action, array $claims): array
    {
        $answerDigest = strtolower((string)($answer['claims_digest'] ?? ''));
        $actionDigest = strtolower((string)($action['claims_digest'] ?? ''));
        $basisDigest = strtolower((string)($action['basis_claims_digest'] ?? ''));
        $basisIds = $action['basis_claim_ids'] ?? null;
        if ($claims === []
            || preg_match('/^[a-f0-9]{64}$/D', $answerDigest) !== 1
            || !hash_equals($answerDigest, $actionDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $basisDigest) !== 1
            || !is_array($basisIds)
            || !array_is_list($basisIds)
            || $basisIds === []
        ) {
            return [];
        }
        $byId = [];
        foreach ($claims as $claim) {
            $byId[(string)$claim['claim_id']] = $claim;
        }
        $basis = [];
        $seen = [];
        foreach ($basisIds as $claimId) {
            if (!is_string($claimId) || isset($seen[$claimId]) || !isset($byId[$claimId])) {
                return [];
            }
            $seen[$claimId] = true;
            $basis[] = $byId[$claimId];
        }
        $metric = (string)($action['expected_metric'] ?? '');
        $definitionId = (string)($action['expected_metric_definition_id'] ?? '');
        $unit = (string)($action['expected_unit'] ?? '');
        $allMatchingIds = [];
        foreach ($claims as $claim) {
            if ((string)$claim['metric_key'] === $metric
                && (string)$claim['metric_definition_id'] === $definitionId
            ) {
                $allMatchingIds[] = (string)$claim['claim_id'];
            }
        }
        $declaredIds = array_values($basisIds);
        sort($declaredIds, SORT_STRING);
        sort($allMatchingIds, SORT_STRING);
        if ($allMatchingIds === [] || $declaredIds !== $allMatchingIds) {
            return [];
        }
        foreach ($basis as $claim) {
            if ((string)$claim['metric_key'] !== $metric
                || (string)$claim['metric_definition_id'] !== $definitionId
                || (string)$claim['unit'] !== $unit
            ) {
                return [];
            }
        }
        if (!hash_equals($basisDigest, OperatingQuestionAiAnswerService::claimsDigest($basis))) {
            return [];
        }
        $claimRefs = array_values(array_unique(array_map(
            static fn(array $claim): string => (string)$claim['evidence_ref'],
            $basis
        )));
        $actionRefs = array_values(array_unique(array_map('strval', (array)($action['evidence_refs'] ?? []))));
        sort($claimRefs, SORT_STRING);
        sort($actionRefs, SORT_STRING);
        return $claimRefs === $actionRefs ? $basis : [];
    }

    private function providerResponseId(mixed $value): string
    {
        return is_string($value)
            && strlen($value) >= 8
            && strlen($value) <= 191
            && preg_match('/^[A-Za-z0-9._:-]+$/D', $value) === 1
                ? $value
                : '';
    }

    private function realMetricUnit(string $unit): bool
    {
        $unit = trim($unit);
        if (preg_match('/^[A-Z]{3}$/D', $unit) === 1) {
            return in_array($unit, self::SUPPORTED_CURRENCY_CODES, true);
        }
        return in_array(strtolower($unit), self::SUPPORTED_NON_CURRENCY_UNITS, true);
    }

    /**
     * @param array<string,mixed> $question
     * @param array<string,mixed> $action
     * @param list<array<string,mixed>> $basisClaims
     */
    private function actionEvidenceCoverageReady(array $question, array $action, array $basisClaims): bool
    {
        $metric = trim((string)($action['expected_metric'] ?? ''));
        $definitionId = trim((string)($action['expected_metric_definition_id'] ?? ''));
        $unit = trim((string)($action['expected_unit'] ?? ''));
        $refs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        $factRefs = array_values(array_map('strval', (array)($question['fact_refs'] ?? [])));
        if ($metric === ''
            || $definitionId === ''
            || !$this->realMetricUnit($unit)
            || $refs === []
            || $basisClaims === []
            || array_diff($refs, $factRefs) !== []
            || array_filter($refs, static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) !== 1) !== []
        ) {
            return false;
        }
        $answer = is_array($question['answer'] ?? null) ? $question['answer'] : [];
        $metricContract = is_array($answer['question_metric_contract'] ?? null)
            ? $answer['question_metric_contract']
            : [];
        $requestedMetricReady = false;
        foreach ((array)($metricContract['requested_metrics'] ?? []) as $requested) {
            if (is_array($requested)
                && (string)($requested['metric_key'] ?? '') === $metric
                && in_array($definitionId, (array)($requested['definition_ids'] ?? []), true)
            ) {
                $requestedMetricReady = true;
                break;
            }
        }
        if (!$requestedMetricReady) {
            return false;
        }
        $coverage = [];
        $claimRefs = [];
        foreach ($basisClaims as $claim) {
            if ((string)($claim['metric_key'] ?? '') !== $metric
                || (string)($claim['metric_definition_id'] ?? '') !== $definitionId
                || (string)($claim['unit'] ?? '') !== $unit
                || (!is_int($claim['value'] ?? null) && !is_float($claim['value'] ?? null))
            ) {
                return false;
            }
            $ref = (string)($claim['evidence_ref'] ?? '');
            if (!in_array($ref, $refs, true)) {
                return false;
            }
            $claimRefs[$ref] = true;
            $platform = strtolower(trim((string)($claim['platform'] ?? '')));
            $date = trim((string)($claim['data_date'] ?? ''));
            $coverage[$platform][$date] = true;
        }
        $declaredRefs = $refs;
        $boundRefs = array_keys($claimRefs);
        sort($declaredRefs, SORT_STRING);
        sort($boundRefs, SORT_STRING);
        if ($declaredRefs !== $boundRefs) {
            return false;
        }
        $platforms = (string)$question['platform'] === 'all_ota'
            ? ['ctrip', 'meituan']
            : [(string)$question['platform']];
        $dates = $this->dates((string)$question['date_start'], (string)$question['date_end']);
        if ($dates === []) {
            return false;
        }
        foreach ($platforms as $platform) {
            foreach ($dates as $date) {
                if (($coverage[$platform][$date] ?? false) !== true) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * A saved Q&A snapshot explains why the draft was created, but approval
     * must also prove that every underlying fact row is still the same
     * verified/read-back source. This prevents a deleted, revoked or edited
     * online_daily_data row from surviving only through cached answer JSON.
     *
     * @param array<string,mixed> $question
     * @param array<string,mixed> $action
     * @param list<array<string,mixed>> $basisClaims
     */
    private function currentActionEvidenceMatches(array $question, array $action, array $basisClaims): bool
    {
        $refs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        if ($refs === [] || $basisClaims === []) {
            return false;
        }
        try {
            $currentFacts = $this->questionService->readCurrentVerifiedFactsForRefs(
                (int)($question['tenant_id'] ?? 0),
                (int)($question['hotel_id'] ?? 0),
                (string)($question['platform'] ?? ''),
                (string)($question['date_start'] ?? ''),
                (string)($question['date_end'] ?? ''),
                $refs
            );
        } catch (\Throwable) {
            return false;
        }
        $currentByRef = [];
        foreach ($currentFacts as $fact) {
            if (is_array($fact)) {
                $currentByRef[(string)($fact['ref'] ?? '')] = $fact;
            }
        }
        if (count($currentByRef) !== count($refs)) {
            return false;
        }
        foreach ($basisClaims as $claim) {
            $ref = (string)($claim['evidence_ref'] ?? '');
            $metric = (string)($claim['metric_key'] ?? '');
            $current = $currentByRef[$ref] ?? null;
            $binding = is_array($claim['binding'] ?? null) ? $claim['binding'] : [];
            if ($ref === '' || $metric === '' || !is_array($current)) {
                return false;
            }
            $currentValues = is_array($current['metric_values'] ?? null) ? $current['metric_values'] : [];
            $currentUnits = is_array($current['metric_units'] ?? null) ? $current['metric_units'] : [];
            $currentDefinitions = is_array($current['metric_definitions'] ?? null)
                ? $current['metric_definitions']
                : [];
            $definition = is_array($currentDefinitions[$metric] ?? null)
                ? $currentDefinitions[$metric]
                : [];
            if ((string)($current['ref'] ?? '') !== $ref
                || (string)($current['data_date'] ?? '') !== (string)($claim['data_date'] ?? '')
                || strtolower(trim((string)($current['platform'] ?? '')))
                    !== strtolower(trim((string)($claim['platform'] ?? '')))
                || (string)($current['history_status'] ?? '') !== 'success'
                || (string)($current['quality_status'] ?? '') !== 'verified'
                || (string)($current['readback_status'] ?? '') !== 'readback_verified'
                || trim((string)($current['readback_verified_at'] ?? ''))
                    !== (string)($binding['readback_verified_at'] ?? '')
                || trim((string)($current['ingestion_method'] ?? ''))
                    !== (string)($binding['ingestion_method'] ?? '')
                || !hash_equals(
                    (string)($binding['source_trace_id_digest'] ?? ''),
                    hash('sha256', trim((string)($current['source_trace_id'] ?? '')))
                )
                || !array_key_exists($metric, $currentValues)
                || !is_numeric($currentValues[$metric])
                || !$this->sameMetricValue($claim['value'] ?? null, $currentValues[$metric])
                || (string)($currentUnits[$metric] ?? '') !== (string)($claim['unit'] ?? '')
                || (string)($definition['definition_id'] ?? '')
                    !== (string)($claim['metric_definition_id'] ?? '')
                || (string)($definition['source_metric_key'] ?? '')
                    !== (string)($claim['source_metric_key'] ?? '')
                || (string)($definition['source_data_type'] ?? '')
                    !== (string)($binding['source_data_type'] ?? '')
                || (string)($definition['source_key'] ?? '')
                    !== (string)($binding['source_key'] ?? '')
                || (string)($definition['storage_field'] ?? '')
                    !== (string)($binding['storage_field'] ?? '')
                || (string)($definition['source_path_digest'] ?? '')
                    !== (string)($binding['source_path_digest'] ?? '')
                || (string)($definition['field_fact_digest'] ?? '')
                    !== (string)($binding['field_fact_digest'] ?? '')
                || (string)($definition['unit_status'] ?? '') !== 'verified'
            ) {
                return false;
            }
        }
        return true;
    }

    private function sameMetricValue(mixed $saved, mixed $current): bool
    {
        return sprintf('%.12F', (float)$saved) === sprintf('%.12F', (float)$current);
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $action @return array<string,mixed> */
    private function metricBaseline(array $question, array $action): array
    {
        $answer = is_array($question['answer'] ?? null) ? $question['answer'] : [];
        $claims = $this->verifiedClaims($answer);
        $basisClaims = $this->basisClaims($answer, $action, $claims);
        $rows = [];
        foreach ($basisClaims as $claim) {
            $rows[] = [
                'claim_id' => (string)$claim['claim_id'],
                'ref' => (string)$claim['evidence_ref'],
                'platform' => (string)$claim['platform'],
                'business_date' => (string)$claim['data_date'],
                'metric' => (string)$claim['metric_key'],
                'metric_definition_id' => (string)$claim['metric_definition_id'],
                'value' => $claim['value'],
                'unit' => (string)$claim['unit'],
                'binding' => (array)$claim['binding'],
            ];
        }
        return [
            'metric' => (string)$action['expected_metric'],
            'metric_definition_id' => (string)$action['expected_metric_definition_id'],
            'unit' => (string)$action['expected_unit'],
            'claims_digest' => (string)$action['claims_digest'],
            'basis_claims_digest' => (string)$action['basis_claims_digest'],
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $question @return array<string,mixed> */
    private function questionScope(array $question): array
    {
        return [
            'tenant_id' => (int)$question['tenant_id'],
            'hotel_id' => (int)$question['hotel_id'],
            'platform' => (string)$question['platform'],
            'date_start' => (string)$question['date_start'],
            'date_end' => (string)$question['date_end'],
            'source_scope' => 'ota_channel',
        ];
    }

    /**
     * Resolve the unified lifecycle identity first, while retaining the legacy
     * operating-question key for existing readbacks.
     *
     * @param array<string,mixed> $question
     * @param array<string,mixed> $action
     * @param list<int> $hotelIds
     * @param array<string,mixed> $actionCard
     * @return array{0:?array<string,mixed>,1:string}
     */
    private function findExistingIntent(
        array $question,
        array $action,
        int $actionIndex,
        array $hotelIds,
        array $actionCard
    ): array {
        $lifecycle = new OperationActionLifecycleService();
        $currentKey = 'operation_action_' . substr($lifecycle->actionIdentityDigest($actionCard), 0, 32);
        $equivalentId = $lifecycle->findEquivalentIntentId($actionCard);
        if ($equivalentId !== null) {
            return [$this->operationService->readExecutionIntent($equivalentId, $hotelIds), $currentKey];
        }

        $intent = $this->operationService->readExecutionIntentByIdempotencyKey($currentKey, $hotelIds);
        if (is_array($intent)) {
            return [$intent, $currentKey];
        }

        $legacyKey = $this->idempotencyKey($question, $action, $actionIndex);
        $intent = $this->operationService->readExecutionIntentByIdempotencyKey($legacyKey, $hotelIds);
        return [is_array($intent) ? $intent : null, is_array($intent) ? $legacyKey : $currentKey];
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $action */
    private function idempotencyKey(array $question, array $action, int $actionIndex): string
    {
        return 'operating_question_action_' . md5(json_encode(
            self::canonicalize([
                'contract_version' => self::CONTRACT_VERSION,
                'question_id' => (int)$question['id'],
                'question_content_digest' => (string)$question['content_digest'],
                'action_index' => $actionIndex,
                'action_digest' => (string)$action['action_digest'],
            ]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @return list<string> */
    private function dates(string $start, string $end): array
    {
        $startDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if ($startDate === false
            || $endDate === false
            || $startDate->format('Y-m-d') !== $start
            || $endDate->format('Y-m-d') !== $end
            || $endDate < $startDate
        ) {
            return [];
        }
        $dates = [];
        for ($cursor = $startDate; $cursor <= $endDate && count($dates) <= 40; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor->format('Y-m-d');
        }
        return count($dates) > 40 ? [] : $dates;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
