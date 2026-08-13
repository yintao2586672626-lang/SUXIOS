<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Promotes one source-verified Q&A action draft into the existing manual
 * approval workflow. It never approves, executes, collects, or writes OTA.
 */
final class OperatingQuestionExecutionBridgeService
{
    public const SOURCE_MODULE = 'operating_question';
    public const CONTRACT_VERSION = 'operating_question_execution_bridge.v1';

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
        $idempotencyKey = $this->idempotencyKey($question, $action, $actionIndex);
        $existing = $this->operationService->readExecutionIntentByIdempotencyKey(
            $idempotencyKey,
            $hotelIds
        );
        $evidenceRefs = array_values(array_unique([
            'hotel_operating_questions#' . $questionId,
            ...(array)$action['evidence_refs'],
        ]));
        $decisionQuality = is_array($action['decision_quality'] ?? null)
            ? $action['decision_quality']
            : [];

        $intent = $this->operationService->createExecutionIntent(
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
                    'question_scope' => $this->questionScope($question),
                    'metric_baseline' => $this->metricBaseline($question, $action),
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
                    'execution_mode' => 'manual',
                    'auto_write_ota' => false,
                ],
                'evidence' => [
                    'bridge_contract_version' => self::CONTRACT_VERSION,
                    'source_policy' => 'source_verified_question_action_then_human_approval',
                    'question_id' => $questionId,
                    'question_content_digest' => (string)$question['content_digest'],
                    'question_answer_status' => (string)$question['answer_status'],
                    'question_scope' => $this->questionScope($question),
                    'action_index' => $actionIndex,
                    'action_draft_digest' => (string)$action['action_digest'],
                    'evidence_refs' => $evidenceRefs,
                    'source_refs' => array_values((array)$action['evidence_refs']),
                    'decision_recommendation' => $action,
                    'decision_quality' => $decisionQuality,
                    'ai_runtime' => (array)($question['answer']['ai_runtime'] ?? []),
                    'boundaries' => (array)$action['boundaries'],
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
        $this->assertIntentReadback($intent, $question, $action, $actionIndex);

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
        if ((string)($question['answer_status'] ?? '') !== 'answered_by_grounded_ai'
            || (string)($answer['status'] ?? '') !== 'answered_by_grounded_ai'
            || (string)($runtime['status'] ?? '') !== 'ready'
            || strtolower(trim((string)($runtime['provider'] ?? ''))) !== 'deepseek'
            || (string)($runtime['prompt_version'] ?? '') !== OperatingQuestionAiAnswerService::PROMPT_VERSION
            || strtolower(trim((string)($runtime['finish_reason'] ?? ''))) !== 'stop'
            || !in_array((string)($answer['confidence'] ?? ''), ['medium', 'high'], true)
            || ($runtime['external_llm_called'] ?? false) !== true
            || (string)($runtime['external_llm_call_status'] ?? '') !== 'confirmed_success'
            || ($runtime['fallback_used'] ?? false) === true
            || ($runtime['cache_hit'] ?? false) === true
            || ($runtime['degraded'] ?? false) === true
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
            || !$this->actionScopeMatches($question, $action)
            || !$this->actionEvidenceCoverageReady($question, $action)
            || !$this->currentActionEvidenceMatches($question, $action)
        ) {
            throw new InvalidArgumentException('行动草案尚未通过当前来源、证据、范围、风险和人工确认门');
        }
        return $action;
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
        int $actionIndex
    ): void {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $embedded = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $status = (string)($intent['status'] ?? '');
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
            || !in_array($status, ['pending_approval', 'approved', 'rejected'], true)
            || trim((string)($intent['blocked_reason'] ?? '')) !== ''
            || (string)($intent['expected_metric'] ?? '') !== (string)$action['expected_metric']
            || (string)($evidence['bridge_contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (int)($evidence['question_id'] ?? 0) !== (int)$question['id']
            || !hash_equals(
                strtolower((string)$question['content_digest']),
                strtolower(trim((string)($evidence['question_content_digest'] ?? '')))
            )
            || (int)($evidence['action_index'] ?? -1) !== $actionIndex
            || !hash_equals(
                (string)$action['action_digest'],
                strtolower(trim((string)($evidence['action_draft_digest'] ?? '')))
            )
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

    /** @param array<string,mixed> $question @param array<string,mixed> $action */
    private function actionEvidenceCoverageReady(array $question, array $action): bool
    {
        $metric = trim((string)($action['expected_metric'] ?? ''));
        $refs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        $factRefs = array_values(array_map('strval', (array)($question['fact_refs'] ?? [])));
        if ($metric === ''
            || $refs === []
            || array_diff($refs, $factRefs) !== []
            || array_filter($refs, static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) !== 1) !== []
        ) {
            return false;
        }
        $answer = is_array($question['answer'] ?? null) ? $question['answer'] : [];
        $coverage = [];
        foreach ((array)($answer['fact_samples'] ?? []) as $fact) {
            if (!is_array($fact) || !in_array((string)($fact['ref'] ?? ''), $refs, true)) {
                continue;
            }
            $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $units = is_array($fact['metric_units'] ?? null) ? $fact['metric_units'] : [];
            if (!array_key_exists($metric, $values)
                || !is_numeric($values[$metric])
                || trim((string)($units[$metric] ?? '')) === ''
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($fact['platform'] ?? '')));
            $date = trim((string)($fact['data_date'] ?? ''));
            $coverage[$platform][$date] = true;
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
     */
    private function currentActionEvidenceMatches(array $question, array $action): bool
    {
        $metric = trim((string)($action['expected_metric'] ?? ''));
        $refs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        if ($metric === '' || $refs === []) {
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
        $savedByRef = [];
        foreach ((array)($question['answer']['fact_samples'] ?? []) as $fact) {
            if (is_array($fact)) {
                $savedByRef[(string)($fact['ref'] ?? '')] = $fact;
            }
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
        foreach ($refs as $ref) {
            $saved = $savedByRef[$ref] ?? null;
            $current = $currentByRef[$ref] ?? null;
            if (!is_array($saved) || !is_array($current)) {
                return false;
            }
            $savedValues = is_array($saved['metric_values'] ?? null) ? $saved['metric_values'] : [];
            $currentValues = is_array($current['metric_values'] ?? null) ? $current['metric_values'] : [];
            $savedUnits = is_array($saved['metric_units'] ?? null) ? $saved['metric_units'] : [];
            $currentUnits = is_array($current['metric_units'] ?? null) ? $current['metric_units'] : [];
            if ((string)($current['ref'] ?? '') !== $ref
                || (string)($current['data_date'] ?? '') !== (string)($saved['data_date'] ?? '')
                || strtolower(trim((string)($current['platform'] ?? '')))
                    !== strtolower(trim((string)($saved['platform'] ?? '')))
                || (string)($current['data_type'] ?? '') !== (string)($saved['data_type'] ?? '')
                || (string)($current['history_status'] ?? '') !== 'success'
                || (string)($current['quality_status'] ?? '') !== 'verified'
                || (string)($current['readback_status'] ?? '') !== 'readback_verified'
                || (string)($current['ingestion_method'] ?? '') !== (string)($saved['ingestion_method'] ?? '')
                || (string)($current['source_trace_id'] ?? '') !== (string)($saved['source_trace_id'] ?? '')
                || !array_key_exists($metric, $savedValues)
                || !array_key_exists($metric, $currentValues)
                || !is_numeric($savedValues[$metric])
                || !is_numeric($currentValues[$metric])
                || !$this->sameMetricValue($savedValues[$metric], $currentValues[$metric])
                || trim((string)($savedUnits[$metric] ?? '')) === ''
                || (string)($currentUnits[$metric] ?? '') !== (string)$savedUnits[$metric]
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
        $metric = (string)$action['expected_metric'];
        $refs = array_values((array)$action['evidence_refs']);
        $rows = [];
        foreach ((array)($question['answer']['fact_samples'] ?? []) as $fact) {
            if (!is_array($fact) || !in_array((string)($fact['ref'] ?? ''), $refs, true)) {
                continue;
            }
            $rows[] = [
                'ref' => (string)$fact['ref'],
                'platform' => (string)($fact['platform'] ?? ''),
                'business_date' => (string)($fact['data_date'] ?? ''),
                'metric' => $metric,
                'value' => $fact['metric_values'][$metric] ?? null,
                'unit' => (string)($fact['metric_units'][$metric] ?? ''),
            ];
        }
        return ['metric' => $metric, 'rows' => $rows];
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
