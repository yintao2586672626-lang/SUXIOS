<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Versioned, append-only action-management overlay for execution intents.
 *
 * The legacy execution tables remain the compatible write projection. Only
 * intents carrying operation_action_card.v1 are governed by this service, so
 * historical rows are never reinterpreted or silently rewritten.
 */
final class OperationActionLifecycleService
{
    public const CARD_CONTRACT_VERSION = 'operation_action_card.v1';
    public const DAILY_CARD_CONTRACT_VERSION = 'operation_action_card.v2';
    public const REVIEW_CONTRACT_VERSION = 'operation_action_review.v1';
    public const APPROVAL_CONFIRMATION_VERSION = 'operation_action_approval_confirmation.v1';
    public const EVENT_TABLE = 'operation_action_lifecycle_events';
    public const REVIEW_TABLE = 'operation_action_reviews';

    /** @var list<string> */
    public const STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'in_progress',
        'completed',
        'reviewed',
        'cancelled',
    ];

    /** @var list<string> */
    public const DAILY_STATUSES = [
        'draft',
        'pending_approval',
        'approved',
        'executing',
        'evidence_recorded',
        'review_pending',
        'reviewed',
        'blocked',
    ];

    /**
     * @param array<string,mixed> $question
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function buildPendingCard(
        array $question,
        array $action,
        int $ownerId,
        ?DateTimeImmutable $now = null
    ): array {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = $now?->setTimezone($timezone) ?? new DateTimeImmutable('now', $timezone);
        $tenantId = (int)($question['tenant_id'] ?? 0);
        $hotelId = (int)($question['hotel_id'] ?? 0);
        $questionId = (int)($question['id'] ?? 0);
        $platform = strtolower(trim((string)($question['platform'] ?? '')));
        $dateStart = substr(trim((string)($question['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($question['date_end'] ?? '')), 0, 10);
        $metric = strtolower(trim((string)($action['expected_metric'] ?? '')));
        $baseline = $this->metricBaseline($question, $action, $metric);
        if ($tenantId <= 0 || $hotelId <= 0 || $questionId <= 0 || $ownerId <= 0) {
            throw new InvalidArgumentException('行动卡缺少租户、酒店、问题或负责人身份');
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('行动卡来源平台无效');
        }
        $this->requiredDateRange($dateStart, $dateEnd);

        $baselineEnd = new DateTimeImmutable($dateEnd . ' 00:00:00', $timezone);
        $minimumReviewDate = $baselineEnd->modify('+1 day');
        $operationalReviewDate = $now->modify('+2 days')->setTime(10, 0);
        $reviewAt = $minimumReviewDate > $operationalReviewDate
            ? $minimumReviewDate->setTime(10, 0)
            : $operationalReviewDate;
        $dueAt = $reviewAt->modify('-16 hours');
        if ($dueAt <= $now) {
            $dueAt = $now->modify('+4 hours');
        }
        if ($reviewAt <= $dueAt) {
            $reviewAt = $dueAt->modify('+16 hours');
        }

        $answer = is_array($question['answer'] ?? null) ? $question['answer'] : [];
        $risk = is_array($action['risk'] ?? null) ? $action['risk'] : [];
        $factRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        $card = [
            'contract_version' => self::CARD_CONTRACT_VERSION,
            'status' => 'pending_approval',
            'hotel' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
            ],
            'source' => [
                'module' => OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                'record_id' => $questionId,
                'platform' => $platform,
                'source_scope' => 'ota_channel',
            ],
            'business_window' => [
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
            ],
            'fact_refs' => $factRefs,
            'action' => [
                'type' => 'human_reviewed_operating_check',
                'title' => trim((string)($action['title'] ?? '')),
                'description' => trim((string)($action['action'] ?? '')),
                'object' => trim((string)($action['action_object'] ?? '')),
                'steps' => array_values(array_filter(array_map(
                    static fn(mixed $item): string => trim((string)$item),
                    (array)($action['execution_steps'] ?? [])
                ))),
            ],
            'reason' => trim((string)($answer['answer_summary'] ?? $question['answer_summary'] ?? '')),
            'risk' => [
                'level' => strtolower(trim((string)($risk['level'] ?? $action['risk_level'] ?? 'medium'))),
                'summary' => trim((string)($risk['summary'] ?? $action['risk_summary'] ?? '')),
                'controls' => array_values((array)($risk['controls'] ?? $action['risk_controls'] ?? [])),
                'stop_conditions' => array_values((array)($action['stop_conditions'] ?? [])),
            ],
            'responsibility' => [
                'owner_id' => $ownerId,
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
            ],
            'execution_window' => [
                'start_at' => $now->format('Y-m-d H:i:s'),
                'end_at' => $dueAt->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Shanghai',
            ],
            'metric_contract' => [
                'metric_key' => $metric,
                'unit' => $baseline['unit'],
                'aggregation' => $baseline['aggregation'],
                'baseline_window' => [
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'value' => $baseline['value'],
                    'fact_rows' => $baseline['rows'],
                ],
                'followup_window' => [
                    'review_at' => $reviewAt->format('Y-m-d H:i:s'),
                    'business_date' => $reviewAt->format('Y-m-d'),
                    'status' => 'pending_source_readback',
                ],
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'target_value' => null,
                'expected_delta' => null,
                'expected_delta_status' => 'observation_only',
            ],
            'trace' => [
                'question_ref' => 'hotel_operating_questions#' . $questionId,
                'question_content_digest' => strtolower(trim((string)($question['content_digest'] ?? ''))),
                'answer_status' => trim((string)($question['answer_status'] ?? '')),
                'action_index' => null,
                'action_draft_digest' => strtolower(trim((string)($action['action_digest'] ?? ''))),
            ],
            'approval' => [
                'required' => true,
                'mode' => 'human_confirmation',
                'trigger_policy' => 'explicit_user_second_confirmation_after_fact_reread',
                'fact_reread_required' => true,
                'approval_expires_at' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
                'confirmation_version' => self::APPROVAL_CONFIRMATION_VERSION,
            ],
            'boundaries' => [
                'automatic_collection' => false,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'external_message' => false,
                'causality_claimed' => false,
                'human_confirmation_required' => true,
                'independent_ai_review_required' => false,
            ],
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
        $this->assertCardShape($card);
        $card['identity_digest'] = $this->identityDigest($card);
        $card['content_digest'] = $this->cardDigest($card);
        return $card;
    }

    /**
     * Build the v2 daily-one-thing card. Unlike the historical v1 overlay,
     * this contract owns the fixed daily lifecycle and freezes the selected
     * source snapshot before any approval or task exists.
     *
     * @param array<string,mixed> $run
     * @param array<string,mixed> $selected
     * @return array<string,mixed>
     */
    public function buildDailyOneThingPendingCard(
        array $run,
        array $selected,
        int $ownerId,
        ?DateTimeImmutable $now = null
    ): array {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = $now?->setTimezone($timezone) ?? new DateTimeImmutable('now', $timezone);
        $runId = (int)($run['id'] ?? 0);
        $tenantId = (int)($run['tenant_id'] ?? 0);
        $hotelId = (int)($run['system_hotel_id'] ?? 0);
        $businessDate = substr(trim((string)($run['business_date'] ?? '')), 0, 10);
        $selectionDigest = strtolower(trim((string)($selected['content_digest'] ?? '')));
        $resultDigest = strtolower(trim((string)($run['result_digest'] ?? '')));
        $inputDigest = strtolower(trim((string)($run['input_digest'] ?? '')));
        if ($runId <= 0 || $tenantId <= 0 || $hotelId <= 0 || $ownerId <= 0
            || (string)($run['feature_key'] ?? '') !== 'daily_one_thing'
            || preg_match('/^[a-f0-9]{64}$/D', $selectionDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $resultDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $inputDigest) !== 1
        ) {
            throw new InvalidArgumentException('每日一件事行动卡来源身份无效');
        }
        $this->requiredDateRange($businessDate, $businessDate);

        $scope = is_array($selected['scope'] ?? null) ? $selected['scope'] : [];
        $action = is_array($selected['recommended_action'] ?? null) ? $selected['recommended_action'] : [];
        $metric = is_array($selected['expected_observation_metric'] ?? null)
            ? $selected['expected_observation_metric'] : [];
        $risk = is_array($selected['risk'] ?? null) ? $selected['risk'] : [];
        $responsibility = is_array($selected['responsibility'] ?? null)
            ? $selected['responsibility'] : [];
        $boundaries = is_array($selected['external_write_boundary'] ?? null)
            ? $selected['external_write_boundary'] : [];
        if ((int)($scope['tenant_id'] ?? 0) !== $tenantId
            || (int)($scope['hotel_id'] ?? 0) !== $hotelId
            || (string)($scope['business_date'] ?? '') !== $businessDate
            || (int)($responsibility['owner_id'] ?? 0) !== $ownerId
            || !hash_equals($selectionDigest, DailyOneThingService::digest($selected))
        ) {
            throw new InvalidArgumentException('每日一件事行动卡范围或选择摘要已漂移');
        }
        $dueAt = $this->requiredDateTime($responsibility['due_at'] ?? null, '每日事项截止时间');
        $reviewAt = $this->requiredDateTime($responsibility['review_at'] ?? null, '每日事项复盘时间');
        if ($reviewAt <= $dueAt) {
            throw new InvalidArgumentException('每日事项复盘时间必须晚于截止时间');
        }
        $metricKey = strtolower(trim((string)($metric['key'] ?? '')));
        $metricUnit = trim((string)($metric['unit'] ?? ''));
        $baselineValue = $metric['baseline_value'] ?? null;
        if ($metricKey === '' || $metricUnit === '' || !is_numeric($baselineValue)) {
            throw new InvalidArgumentException('每日事项观察指标合同无效');
        }

        $runRef = OperatingOpportunityLabService::RUN_TABLE . '#' . $runId;
        $factRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                [$runRef, (string)($selected['source']['record_ref'] ?? '')],
                (array)($selected['source']['fact_refs'] ?? [])
            )
        ))));
        $card = [
            'contract_version' => self::DAILY_CARD_CONTRACT_VERSION,
            'status' => 'pending_approval',
            'problem' => trim((string)($selected['problem'] ?? '')),
            'fact_basis' => array_values((array)($selected['fact_basis'] ?? [])),
            'hotel' => ['tenant_id' => $tenantId, 'hotel_id' => $hotelId],
            'source' => [
                'module' => OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                'record_id' => $runId,
                'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
                'source_scope' => strtolower(trim((string)($scope['metric_scope'] ?? 'ota_channel'))),
            ],
            'business_window' => ['date_start' => $businessDate, 'date_end' => $businessDate],
            'fact_refs' => $factRefs,
            'action' => [
                'type' => trim((string)($action['type'] ?? '')),
                'title' => trim((string)($action['title'] ?? '')),
                'description' => trim((string)($action['description'] ?? '')),
                'object' => trim((string)($action['object'] ?? '')),
                'steps' => array_values((array)($action['steps'] ?? [])),
            ],
            'reason' => trim((string)($selected['problem'] ?? '')),
            'risk' => [
                'level' => strtolower(trim((string)($risk['level'] ?? 'medium'))),
                'summary' => trim((string)($risk['summary'] ?? '')),
                'controls' => array_values((array)($risk['controls'] ?? [])),
                'stop_conditions' => array_values((array)($risk['stop_conditions'] ?? [])),
            ],
            'responsibility' => [
                'owner_id' => $ownerId,
                'owner_label' => trim((string)($responsibility['owner_label'] ?? '当前确认人')),
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
            ],
            'execution_window' => [
                'start_at' => $now->format('Y-m-d H:i:s'),
                'end_at' => $dueAt->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Shanghai',
            ],
            'metric_contract' => [
                'metric_key' => $metricKey,
                'label' => trim((string)($metric['label'] ?? $metricKey)),
                'unit' => $metricUnit,
                'aggregation' => trim((string)($metric['aggregation'] ?? 'latest')),
                'baseline_window' => [
                    'date_start' => $businessDate,
                    'date_end' => $businessDate,
                    'value' => round((float)$baselineValue, 6),
                    'fact_rows' => [[
                        'ref' => $runRef,
                        'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
                        'business_date' => $businessDate,
                        'metric_key' => $metricKey,
                        'value' => round((float)$baselineValue, 6),
                        'unit' => $metricUnit,
                    ]],
                ],
                'followup_window' => [
                    'review_at' => $reviewAt->format('Y-m-d H:i:s'),
                    'business_date' => $reviewAt->format('Y-m-d'),
                    'status' => 'pending_source_readback',
                ],
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'target_value' => null,
                'expected_delta' => null,
                'expected_delta_status' => 'observation_only',
            ],
            'trace' => [
                'daily_one_thing_run_ref' => $runRef,
                'daily_selection_digest' => $selectionDigest,
                'daily_run_input_digest' => $inputDigest,
                'daily_run_result_digest' => $resultDigest,
                'source_snapshot_digest' => strtolower(trim((string)($selected['source']['snapshot_digest'] ?? ''))),
                'candidate_key' => trim((string)($selected['candidate_key'] ?? '')),
                'source_type' => trim((string)($selected['source_type'] ?? '')),
                'underlying_source_record_id' => max(0, (int)($selected['source']['record_id'] ?? 0)),
            ],
            'approval' => [
                'required' => true,
                'mode' => 'human_confirmation',
                'trigger_policy' => 'explicit_user_second_confirmation_after_fact_reread',
                'fact_reread_required' => true,
                'approval_expires_at' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
                'confirmation_version' => self::APPROVAL_CONFIRMATION_VERSION,
            ],
            'boundaries' => [
                'automatic_collection' => false,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'external_message' => false,
                'automatic_wecom_message' => false,
                'causality_claimed' => false,
                'human_confirmation_required' => true,
                'external_write_count_before_approval' => 0,
            ] + $boundaries,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
        $this->assertCardShape($card);
        $card['identity_digest'] = $this->identityDigest($card);
        $card['content_digest'] = $this->cardDigest($card);
        return $card;
    }

    /**
     * Build the smallest truthful action card for a revenue-cockpit handoff.
     * It is an observation contract: no numeric improvement target is invented,
     * and every external operation remains a later human action.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function buildRevenueCockpitObservationCard(
        array $context,
        int $ownerId,
        ?DateTimeImmutable $now = null
    ): array {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = $now?->setTimezone($timezone) ?? new DateTimeImmutable('now', $timezone);
        $tenantId = (int)($context['tenant_id'] ?? 0);
        $hotelId = (int)($context['hotel_id'] ?? 0);
        $sourceRecordId = (int)($context['source_record_id'] ?? 0);
        $platform = strtolower(trim((string)($context['platform'] ?? '')));
        $businessDate = substr(trim((string)($context['business_date'] ?? '')), 0, 10);
        $metricKey = strtolower(trim((string)($context['metric_key'] ?? '')));
        $metricUnit = trim((string)($context['metric_unit'] ?? ''));
        $metricValue = $context['metric_value'] ?? null;
        $metricRows = array_values(array_filter(
            (array)($context['metric_rows'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
        $factRefs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($context['fact_refs'] ?? [])
        ))));
        if ($tenantId <= 0 || $hotelId <= 0 || $sourceRecordId <= 0 || $ownerId <= 0) {
            throw new InvalidArgumentException('收益驾驶舱行动卡缺少租户、酒店、来源或负责人身份');
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('收益驾驶舱行动卡平台范围无效');
        }
        $this->requiredDateRange($businessDate, $businessDate);
        if ($metricKey === '' || $metricUnit === '' || !is_numeric($metricValue)
            || $metricRows === [] || $factRefs === []
        ) {
            throw new InvalidArgumentException('收益驾驶舱行动卡缺少完整的原始指标事实');
        }

        $baselineEnd = new DateTimeImmutable($businessDate . ' 00:00:00', $timezone);
        $minimumReviewAt = $baselineEnd->modify('+1 day')->setTime(10, 0);
        $reviewAt = $now->modify('+2 days')->setTime(10, 0);
        if ($minimumReviewAt > $reviewAt) {
            $reviewAt = $minimumReviewAt;
        }
        $dueAt = $reviewAt->modify('-16 hours');
        if ($dueAt <= $now) {
            $dueAt = $now->modify('+4 hours');
        }
        if ($reviewAt <= $dueAt) {
            $reviewAt = $dueAt->modify('+16 hours');
        }

        $card = [
            'contract_version' => self::CARD_CONTRACT_VERSION,
            'status' => 'pending_approval',
            'hotel' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
            ],
            'source' => [
                'module' => trim((string)($context['source_module'] ?? RevenueCockpitActionContract::SOURCE_MODULE)),
                'record_id' => $sourceRecordId,
                'platform' => $platform,
                'source_scope' => 'ota_channel',
            ],
            'business_window' => [
                'date_start' => $businessDate,
                'date_end' => $businessDate,
            ],
            'fact_refs' => $factRefs,
            'action' => [
                'type' => 'human_reviewed_metric_observation',
                'title' => trim((string)($context['action_title'] ?? '观察收益事实并记录人工处理')),
                'description' => trim((string)($context['action_description'] ?? '由负责人核对当前收益事实，记录实际运营处理，并在计划日期读取同口径事实复盘。')),
                'object' => trim((string)($context['action_object'] ?? ($platform . ':' . $metricKey))),
                'steps' => array_values(array_filter(array_map(
                    static fn(mixed $item): string => trim((string)$item),
                    (array)($context['action_steps'] ?? [
                        '复核酒店、平台、营业日、指标和原始事实引用',
                        '由负责人在授权范围内完成人工运营处理并保存真实执行证据',
                        '到期重新读取同酒店、同平台、同指标事实并保存效果复盘',
                    ])
                ))),
            ],
            'reason' => trim((string)($context['reason'] ?? '当前严格回读收益事实需要进入人工运营跟进与同口径复盘。')),
            'risk' => [
                'level' => strtolower(trim((string)($context['risk_level'] ?? 'medium'))),
                'summary' => trim((string)($context['risk_summary'] ?? '原始事实、酒店、平台、日期或指标发生漂移时必须停止并重新生成行动。')),
                'controls' => array_values((array)($context['risk_controls'] ?? [
                    '审批前重新读取原始事实并校验作用域',
                    '系统不自动操作 OTA 或 PMS',
                    '执行证据与效果证据分开保存',
                ])),
                'stop_conditions' => array_values((array)($context['stop_conditions'] ?? [
                    '酒店、租户、平台、营业日或指标身份不一致',
                    '原始事实引用缺失、失效或数值漂移',
                    '人工执行超出已审批的对象、窗口或权限范围',
                ])),
            ],
            'responsibility' => [
                'owner_id' => $ownerId,
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
            ],
            'execution_window' => [
                'start_at' => $now->format('Y-m-d H:i:s'),
                'end_at' => $dueAt->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Shanghai',
            ],
            'metric_contract' => [
                'metric_key' => $metricKey,
                'unit' => $metricUnit,
                'aggregation' => trim((string)($context['aggregation'] ?? 'sum')),
                'baseline_window' => [
                    'date_start' => $businessDate,
                    'date_end' => $businessDate,
                    'value' => round((float)$metricValue, 6),
                    'fact_rows' => $metricRows,
                ],
                'followup_window' => [
                    'review_at' => $reviewAt->format('Y-m-d H:i:s'),
                    'business_date' => $reviewAt->format('Y-m-d'),
                    'status' => 'pending_source_readback',
                ],
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'target_value' => null,
                'expected_delta' => null,
                'expected_delta_status' => 'observation_only',
            ],
            'trace' => [
                'cockpit_identity_digest' => strtolower(trim((string)($context['fact_snapshot_digest'] ?? ''))),
                'opportunity_key' => trim((string)($context['opportunity_key'] ?? '')),
                'opportunity_digest' => strtolower(trim((string)($context['opportunity_digest'] ?? ''))),
                'decision_snapshot_id' => max(0, (int)($context['decision_snapshot_id'] ?? 0)),
                'decision_snapshot_digest' => strtolower(trim((string)($context['decision_snapshot_digest'] ?? ''))),
                'action_index' => null,
            ],
            'approval' => [
                'required' => true,
                'mode' => 'human_confirmation',
                'trigger_policy' => 'explicit_user_second_confirmation_after_fact_reread',
                'fact_reread_required' => true,
                'approval_expires_at' => $now->modify('+24 hours')->format('Y-m-d H:i:s'),
                'confirmation_version' => self::APPROVAL_CONFIRMATION_VERSION,
            ],
            'boundaries' => [
                'automatic_collection' => false,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'external_message' => false,
                'causality_claimed' => false,
                'human_confirmation_required' => true,
                'independent_ai_review_required' => false,
            ],
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];
        $this->assertCardShape($card);
        $card['identity_digest'] = $this->identityDigest($card);
        $card['content_digest'] = $this->cardDigest($card);
        return $card;
    }

    /** @param array<string,mixed> $card */
    public function withActionIndex(array $card, int $actionIndex): array
    {
        if ($actionIndex < 0) {
            throw new InvalidArgumentException('行动卡 action_index 无效');
        }
        $card['trace']['action_index'] = $actionIndex;
        unset($card['identity_digest'], $card['content_digest']);
        $this->assertCardShape($card);
        $card['identity_digest'] = $this->identityDigest($card);
        $card['content_digest'] = $this->cardDigest($card);
        return $card;
    }

    /** @param array<string,mixed> $intent */
    public function isManagedIntent(array $intent): bool
    {
        $card = $this->cardFromIntent($intent);
        return self::isManagedCard($card);
    }

    /** @param array<string,mixed> $card */
    public static function isManagedCard(array $card): bool
    {
        return in_array((string)($card['contract_version'] ?? ''), [
            self::CARD_CONTRACT_VERSION,
            self::DAILY_CARD_CONTRACT_VERSION,
        ], true);
    }

    /** @param array<string,mixed> $intent */
    public function isDailyOneThingIntent(array $intent): bool
    {
        $card = $this->cardFromIntent($intent);
        return (string)($card['contract_version'] ?? '') === self::DAILY_CARD_CONTRACT_VERSION
            && strtolower(trim((string)($intent['source_module'] ?? $card['source']['module'] ?? '')))
                === OperatingOpportunityLabService::DAILY_SOURCE_MODULE;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function assertPendingCardCurrent(array $intent, ?DateTimeImmutable $now = null): array
    {
        $card = $this->cardFromIntent($intent);
        $this->assertCardShape($card);
        $storedDigest = strtolower(trim((string)($card['content_digest'] ?? '')));
        if (!$this->isDigest($storedDigest) || !hash_equals($storedDigest, $this->cardDigest($card))) {
            throw new InvalidArgumentException('行动卡内容摘要不一致，请基于当前事实重新生成');
        }
        foreach ([
            [(int)($card['hotel']['tenant_id'] ?? 0), (int)($intent['tenant_id'] ?? 0), 'tenant'],
            [(int)($card['hotel']['hotel_id'] ?? 0), (int)($intent['hotel_id'] ?? 0), 'hotel'],
            [(int)($card['source']['record_id'] ?? 0), (int)($intent['source_record_id'] ?? 0), 'source record'],
        ] as [$cardValue, $intentValue, $label]) {
            if ($cardValue <= 0 || $cardValue !== $intentValue) {
                throw new InvalidArgumentException('行动卡 ' . $label . ' 范围已漂移');
            }
        }
        if ((string)($card['source']['module'] ?? '') !== (string)($intent['source_module'] ?? '')
            || (string)($card['source']['platform'] ?? '') !== (string)($intent['platform'] ?? '')
            || (string)($card['business_window']['date_start'] ?? '') !== (string)($intent['date_start'] ?? '')
            || (string)($card['business_window']['date_end'] ?? '') !== (string)($intent['date_end'] ?? '')
            || (string)($card['metric_contract']['metric_key'] ?? '') !== (string)($intent['expected_metric'] ?? '')
        ) {
            throw new InvalidArgumentException('行动卡来源、业务日期或指标范围已漂移');
        }
        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = $now?->setTimezone($timezone) ?? new DateTimeImmutable('now', $timezone);
        $expiresAt = $this->requiredDateTime(
            $card['approval']['approval_expires_at'] ?? null,
            '行动卡审批有效期'
        );
        if (strtolower(trim((string)($intent['status'] ?? ''))) === 'pending_approval'
            && $now > $expiresAt
        ) {
            throw new InvalidArgumentException('行动卡已过期，请基于最新事实重新生成');
        }
        return $card;
    }

    /**
     * Block a materially equivalent active action before task creation.
     * Exact retries remain handled by the existing idempotency key.
     *
     * @param array<string,mixed> $intent
     */
    public function assertNoActiveDuplicate(array $intent): void
    {
        $card = $this->assertPendingCardCurrent($intent);
        $identityDigest = $this->actionIdentityDigest($card);
        $rows = Db::name('operation_execution_intents')
            ->where('tenant_id', (int)$intent['tenant_id'])
            ->where('hotel_id', (int)$intent['hotel_id'])
            ->where('id', '<>', (int)$intent['id'])
            ->whereIn('status', ['pending_approval', 'approved'])
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $candidate = $this->cardFromIntent($row);
            if (!self::isManagedCard($candidate)
                || (string)($candidate['contract_version'] ?? '') !== (string)($card['contract_version'] ?? '')
                || !hash_equals($identityDigest, $this->actionIdentityDigest($candidate))
            ) {
                continue;
            }
            $candidateTask = Db::name('operation_execution_tasks')
                ->where('intent_id', (int)$row['id'])
                ->where('tenant_id', (int)$row['tenant_id'])
                ->where('hotel_id', (int)$row['hotel_id'])
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->find();
            $taskStatus = strtolower(trim((string)($candidateTask['status'] ?? '')));
            if (!in_array($taskStatus, ['executed', 'failed', 'cancelled'], true)) {
                throw new InvalidArgumentException('存在重复且尚未结束的运营行动：execution_intent#' . (int)$row['id']);
            }
        }
    }

    /**
     * Find the one persisted lifecycle represented by the same hotel, channel,
     * business window, metric contract, decision snapshot and recommendation.
     * Generated prose and source record IDs are excluded, while changed
     * decision evidence deliberately receives a new lifecycle identity.
     *
     * @param array<string,mixed> $card
     */
    public function findEquivalentIntentId(array $card): ?int
    {
        $this->assertCardShape($card);
        $tenantId = (int)($card['hotel']['tenant_id'] ?? 0);
        $hotelId = (int)($card['hotel']['hotel_id'] ?? 0);
        $identityDigest = $this->actionIdentityDigest($card);
        $rows = Db::name('operation_execution_intents')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $candidate = $this->cardFromIntent($row);
            if (self::isManagedCard($candidate)
                && (string)($candidate['contract_version'] ?? '') === (string)($card['contract_version'] ?? '')
                && hash_equals($identityDigest, $this->actionIdentityDigest($candidate))
            ) {
                return (int)($row['id'] ?? 0) ?: null;
            }
        }
        return null;
    }

    /**
     * Return every managed lifecycle in one exact hotel/platform/business-date
     * scope. This is used for refresh recovery only; it never creates, approves
     * or mutates an action.
     *
     * @return list<int>
     */
    public function findManagedIntentIdsForScope(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $dateStart,
        string $dateEnd
    ): array {
        $platform = strtolower(trim($platform));
        $dateStart = substr(trim($dateStart), 0, 10);
        $dateEnd = substr(trim($dateEnd), 0, 10);
        if ($tenantId <= 0 || $hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)
        ) {
            throw new InvalidArgumentException('运营行动恢复范围无效');
        }
        $this->requiredDateRange($dateStart, $dateEnd);
        $rows = Db::name('operation_execution_intents')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('date_start', $dateStart)
            ->where('date_end', $dateEnd)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $ids = [];
        foreach ($rows as $row) {
            $card = $this->cardFromIntent($row);
            if (!self::isManagedCard($card)) {
                continue;
            }
            try {
                $this->assertCardShape($card);
            } catch (\Throwable) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    public function assertEquivalentActionIdentity(array $left, array $right): void
    {
        $this->assertCardShape($left);
        $this->assertCardShape($right);
        if (!hash_equals($this->actionIdentityDigest($left), $this->actionIdentityDigest($right))) {
            throw new InvalidArgumentException('运营行动酒店、平台、营业日或指标身份不一致');
        }
    }

    /**
     * New managed actions can only cross the approval boundary after an
     * authenticated user confirms the exact current intent and card digest.
     * Historical AI-reviewed cards remain readable but cannot satisfy this
     * human confirmation contract accidentally.
     *
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $input
     */
    public function assertHumanApprovalConfirmation(array $intent, array $input, int $userId): void
    {
        $card = $this->assertPendingCardCurrent($intent);
        if (strtolower(trim((string)($card['approval']['mode'] ?? ''))) !== 'human_confirmation') {
            throw new InvalidArgumentException('旧版非人工确认行动不能直接审批，请重新生成当前人工确认行动卡');
        }
        $cardDigest = strtolower(trim((string)($card['content_digest'] ?? '')));
        $confirmedDigest = strtolower(trim((string)($input['confirmed_action_digest'] ?? '')));
        if ($userId <= 0
            || ($input['confirmed'] ?? false) !== true
            || (string)($input['confirmation_version'] ?? '') !== self::APPROVAL_CONFIRMATION_VERSION
            || (int)($input['confirmed_intent_id'] ?? 0) !== (int)($intent['id'] ?? 0)
            || !$this->isDigest($cardDigest)
            || !$this->isDigest($confirmedDigest)
            || !hash_equals($cardDigest, $confirmedDigest)
        ) {
            throw new InvalidArgumentException('请由当前用户二次确认同一待审批行动后再创建任务');
        }
    }

    /**
     * @param array<string,mixed> $card
     * @param array<string,mixed> $schedule
     * @param array<string,mixed> $approvalTarget
     * @return array<string,mixed>
     */
    public function freezeApprovedCard(
        array $card,
        array $schedule,
        array $approvalTarget,
        int $approvedBy,
        string $approvedAt,
        array $approvalAuthority = []
    ): array {
        $this->assertCardShape($card);
        $previousDigest = strtolower(trim((string)($card['content_digest'] ?? '')));
        if (!$this->isDigest($previousDigest) || !hash_equals($previousDigest, $this->cardDigest($card))) {
            throw new InvalidArgumentException('待审批行动卡摘要无效');
        }
        $card['status'] = 'approved';
        $card['responsibility'] = array_merge((array)($card['responsibility'] ?? []), [
            'owner_id' => (int)($schedule['assignee_id'] ?? 0),
            'due_at' => trim((string)($schedule['due_at'] ?? '')),
        ]);
        $card['execution_window'] = [
            'start_at' => trim((string)($schedule['execution_start_at']
                ?? $card['execution_window']['start_at']
                ?? $approvedAt)),
            'end_at' => trim((string)($schedule['execution_end_at']
                ?? $schedule['due_at']
                ?? $card['execution_window']['end_at']
                ?? '')),
            'timezone' => 'Asia/Shanghai',
        ];
        $card['metric_contract']['followup_window'] = [
            'review_at' => trim((string)($schedule['review_at'] ?? '')),
            'business_date' => trim((string)($approvalTarget['review_business_date'] ?? '')),
            'status' => 'pending_source_readback',
        ];
        foreach (['expected_direction', 'target_type', 'target_value', 'expected_delta'] as $field) {
            $card['metric_contract'][$field] = $approvalTarget[$field] ?? null;
        }
        $card['approval'] = array_merge((array)$card['approval'], [
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'fact_reread_status' => 'verified_no_drift',
            'approval_target_digest' => strtolower(trim((string)($approvalTarget['content_digest'] ?? ''))),
        ], $approvalAuthority);
        if (($approvalAuthority['mode'] ?? '') === 'ai_independent_review') {
            $card['action']['type'] = 'ai_reviewed_operating_check';
            $card['boundaries']['human_confirmation_required'] = false;
            $card['boundaries']['independent_ai_review_required'] = true;
            $card = $this->applyIndependentAiReviewAuthority($card);
        }
        $card['previous_card_digest'] = $previousDigest;
        $card['identity_digest'] = $this->identityDigest($card);
        unset($card['content_digest']);
        $card['content_digest'] = $this->cardDigest($card);
        return $card;
    }

    /**
     * Reissue an already approved AI-reviewed card as a new current projection.
     * The previous digest remains linked and callers must append a lifecycle
     * event instead of rewriting any historical event payload.
     *
     * @param array<string,mixed> $card
     * @return array<string,mixed>
     */
    public function reviseIndependentAiReviewCard(array $card): array
    {
        $this->assertCardShape($card);
        if (strtolower(trim((string)($card['approval']['mode'] ?? ''))) !== 'ai_independent_review') {
            throw new InvalidArgumentException('行动卡不是独立 AI 评审合同');
        }
        $previousDigest = strtolower(trim((string)($card['content_digest'] ?? '')));
        if (!$this->isDigest($previousDigest) || !hash_equals($previousDigest, $this->cardDigest($card))) {
            throw new InvalidArgumentException('独立 AI 评审行动卡摘要无效');
        }

        $revised = $this->applyIndependentAiReviewAuthority($card);
        $before = $card;
        $after = $revised;
        unset($before['content_digest'], $after['content_digest']);
        if ($this->canonicalJson($before) === $this->canonicalJson($after)) {
            return $card;
        }

        $revised['previous_card_digest'] = $previousDigest;
        $revised['identity_digest'] = $this->identityDigest($revised);
        unset($revised['content_digest']);
        $revised['content_digest'] = $this->cardDigest($revised);
        $this->assertCardShape($revised);
        return $revised;
    }

    /**
     * Keep the mutable task projection aligned with the approved action card.
     * Source answer/draft evidence is intentionally left untouched.
     *
     * @param array<string,mixed> $target
     * @param array<string,mixed> $card
     * @return array<string,mixed>
     */
    public function alignIndependentAiTaskProjection(array $target, array $card): array
    {
        $this->assertCardShape($card);
        if (strtolower(trim((string)($card['approval']['mode'] ?? ''))) !== 'ai_independent_review') {
            throw new InvalidArgumentException('行动任务不是独立 AI 评审合同');
        }

        $target['action_card'] = $card;
        $target['steps'] = array_values((array)($card['action']['steps'] ?? []));
        $target['stop_conditions'] = array_values((array)($card['risk']['stop_conditions'] ?? []));
        $metricKey = trim((string)($card['metric_contract']['metric_key'] ?? ''));
        $criteria = ['按同酒店、同渠道、同日期口径复核 ' . $metricKey];
        $reviewWindow = trim((string)($target['review_window'] ?? ''));
        if ($reviewWindow !== '') {
            $criteria[] = '到期复核窗口：' . $reviewWindow;
        }
        foreach ($target['stop_conditions'] as $condition) {
            $condition = trim((string)$condition);
            if ($condition !== '') {
                $criteria[] = '停止条件：' . $condition;
            }
        }
        $target['acceptance_criteria'] = array_values(array_unique($criteria));
        $schedule = is_array($target['workflow_schedule'] ?? null)
            ? $target['workflow_schedule']
            : [];
        if ($schedule !== []) {
            $schedule['source_policy'] = 'independent_ai_reviewed_schedule_manual_execution_and_source_readback';
            $target['workflow_schedule'] = $schedule;
        }
        $target['approval_mode'] = 'ai_independent_review';
        $target['execution_mode'] = 'manual';
        $target['auto_write_ota'] = false;
        return $target;
    }

    /**
     * Keep the manual task projection complete and aligned with the immutable
     * action card used for approval.
     *
     * @param array<string,mixed> $target
     * @param array<string,mixed> $card
     * @return array<string,mixed>
     */
    public function alignManualTaskProjection(array $target, array $card): array
    {
        $this->assertCardShape($card);
        if (strtolower(trim((string)($card['approval']['mode'] ?? ''))) !== 'human_confirmation') {
            throw new InvalidArgumentException('行动任务不是人工确认合同');
        }
        $target['action_card'] = $card;
        $target['title'] = (string)($card['action']['title'] ?? '');
        $target['action_text'] = (string)($card['action']['description'] ?? '');
        $target['action_object'] = (string)($card['action']['object'] ?? '');
        $target['steps'] = array_values((array)($card['action']['steps'] ?? []));
        $target['stop_conditions'] = array_values((array)($card['risk']['stop_conditions'] ?? []));
        $metricKey = trim((string)($card['metric_contract']['metric_key'] ?? ''));
        $criteria = ['按同酒店、同渠道、同营业日口径复核 ' . $metricKey];
        foreach ($target['stop_conditions'] as $condition) {
            $condition = trim((string)$condition);
            if ($condition !== '') {
                $criteria[] = '停止条件：' . $condition;
            }
        }
        $target['acceptance_criteria'] = array_values(array_unique($criteria));
        $target['assignee_id'] = (int)($card['responsibility']['owner_id'] ?? 0);
        $target['execution_window'] = [
            'start_at' => (string)($card['execution_window']['start_at'] ?? $card['created_at'] ?? ''),
            'end_at' => (string)($card['execution_window']['end_at'] ?? $card['responsibility']['due_at'] ?? ''),
            'timezone' => 'Asia/Shanghai',
        ];
        $target['workflow_schedule'] = [
            'assignee_id' => (int)($card['responsibility']['owner_id'] ?? 0),
            'due_at' => (string)($card['responsibility']['due_at'] ?? ''),
            'review_at' => (string)($card['metric_contract']['followup_window']['review_at'] ?? ''),
            'execution_start_at' => (string)$target['execution_window']['start_at'],
            'execution_end_at' => (string)$target['execution_window']['end_at'],
            'source_policy' => 'human_confirmed_manual_execution_then_same_criterion_source_readback',
        ];
        $target['approval_mode'] = 'human_confirmation';
        $target['execution_mode'] = 'manual';
        $target['auto_write_ota'] = false;
        return $target;
    }

    /** @param array<string,mixed> $card @return array<string,mixed> */
    private function applyIndependentAiReviewAuthority(array $card): array
    {
        $approval = is_array($card['approval'] ?? null) ? $card['approval'] : [];
        $approval['mode'] = 'ai_independent_review';
        $approval['trigger_policy'] = 'automatic_independent_ai_review_after_fact_reread';
        $approval['human_confirmation_required'] = false;
        $card['approval'] = $approval;
        $card['action']['type'] = 'ai_reviewed_operating_check';
        $card['action']['steps'] = $this->normalizeIndependentAiReviewTextList(
            (array)($card['action']['steps'] ?? [])
        );
        $card['reason'] = $this->normalizeIndependentAiReviewText((string)($card['reason'] ?? ''));
        $card['risk']['controls'] = $this->normalizeIndependentAiReviewTextList(
            (array)($card['risk']['controls'] ?? [])
        );
        $card['risk']['stop_conditions'] = $this->normalizeIndependentAiReviewTextList(
            (array)($card['risk']['stop_conditions'] ?? [])
        );
        $card['boundaries']['human_confirmation_required'] = false;
        $card['boundaries']['independent_ai_review_required'] = true;
        return $card;
    }

    /** @param array<int,mixed> $items @return list<string> */
    private function normalizeIndependentAiReviewTextList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $value = $this->normalizeIndependentAiReviewText((string)$item);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function normalizeIndependentAiReviewText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = str_replace([
            '负责人未获得用户书面审批前不得执行',
            '所有步骤需用户审批后执行',
            '由用户审批本草案，确认负责人和复核窗口。',
            '需用户审批后由负责人执行',
            '用户审批后',
            '用户书面审批',
            '人工审批',
            '用户审批',
        ], [
            '独立 AI 评审未通过或事实已漂移时不得执行',
            '仅在独立 AI 评审通过且事实未漂移时创建本地人工任务',
            '由独立 AI 基于最新事实评审行动卡，评审通过后按已冻结负责人和复核窗口创建本地人工任务。',
            '经独立 AI 评审通过后由负责人执行',
            '独立 AI 评审通过后',
            '独立 AI 评审',
            '独立 AI 评审',
            '独立 AI 评审',
        ], $value);
        $value = preg_replace(
            '/\b(?:human|user|written)\s+approval\b/iu',
            'independent AI review',
            $value
        ) ?? $value;
        return trim($value);
    }

    /** @param array<string,mixed> $intent */
    public function appendInitialEvents(array $intent, int $actorId): void
    {
        if (!$this->isManagedIntent($intent)) {
            return;
        }
        $this->assertSchemaReady();
        $card = $this->cardFromIntent($intent);
        $this->appendEvent($intent, 0, '', 'draft', 'drafted', $actorId, [
            'action_card' => array_merge($card, ['status' => 'draft']),
            'external_action_performed' => false,
        ]);
        $this->appendEvent($intent, 0, 'draft', 'pending_approval', 'submitted', $actorId, [
            'action_card' => $card,
            'fact_reread_required_before_review' => true,
            'review_mode' => (string)($card['approval']['mode'] ?? 'legacy_human_approval'),
            'external_action_performed' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function appendEvent(
        array $intent,
        int $taskId,
        string $fromStatus,
        string $toStatus,
        string $eventType,
        int $actorId,
        array $payload = []
    ): array {
        if (!$this->isManagedIntent($intent)) {
            return [];
        }
        $this->assertSchemaReady();
        $allowedStatuses = $this->isDailyOneThingIntent($intent)
            ? self::DAILY_STATUSES
            : self::STATUSES;
        if (($fromStatus !== '' && !in_array($fromStatus, $allowedStatuses, true))
            || !in_array($toStatus, $allowedStatuses, true)
        ) {
            throw new InvalidArgumentException('运营行动生命周期状态无效');
        }
        if ($this->isDailyOneThingIntent($intent)) {
            $this->assertDailyTransition($fromStatus, $toStatus);
        }
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0 || $intentId <= 0) {
            throw new InvalidArgumentException('运营行动生命周期身份无效');
        }
        $eventType = trim($eventType);
        $taskId = max(0, $taskId);
        $actorId = max(0, $actorId);
        $payloadJson = $this->canonicalJson($payload);

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $intentId,
            $taskId,
            $fromStatus,
            $toStatus,
            $eventType,
            $actorId,
            $payload,
            $payloadJson
        ): array {
            $lockedIntent = Db::name('operation_execution_intents')
                ->where('id', $intentId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->lock(true)
                ->find();
            if (!is_array($lockedIntent)) {
                throw new RuntimeException('运营行动执行意图不存在或范围已变化');
            }

            $existingEvents = array_map(
                [$this, 'normalizeEvent'],
                Db::name(self::EVENT_TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('intent_id', $intentId)
                    ->order('sequence_no', 'asc')
                    ->lock(true)
                    ->select()
                    ->toArray()
            );
            $integrity = $this->verifyEventChain($existingEvents, $tenantId, $hotelId, $intentId);
            if ($integrity['status'] === 'invalid') {
                throw new RuntimeException('运营行动生命周期事件链损坏：' . $integrity['failure_reason']);
            }
            $latest = $existingEvents === [] ? null : $existingEvents[count($existingEvents) - 1];

            $replayed = Db::name(self::EVENT_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', $intentId)
                ->where('task_id', $taskId)
                ->where('event_type', $eventType)
                ->where('from_status', $fromStatus)
                ->where('to_status', $toStatus)
                ->where('actor_id', $actorId)
                ->where('event_payload_json', $payloadJson)
                ->order('sequence_no', 'desc')
                ->find();
            if (is_array($replayed)) {
                return $this->normalizeEvent($replayed);
            }

            if (is_array($latest)) {
                if ($fromStatus === '' || (string)($latest['to_status'] ?? '') !== $fromStatus) {
                    throw new InvalidArgumentException('运营行动生命周期已变化，请刷新后重试');
                }
            } elseif ($fromStatus !== '') {
                throw new InvalidArgumentException('运营行动生命周期尚未初始化');
            }

            $sequence = (int)($latest['sequence_no'] ?? 0) + 1;
            $previousDigest = strtolower(trim((string)($latest['content_digest'] ?? '')));
            $createdAt = date('Y-m-d H:i:s');
            $digestPayload = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'sequence_no' => $sequence,
                'event_type' => $eventType,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'event_payload' => $payload,
                'previous_digest' => $previousDigest,
                'created_at' => $createdAt,
            ];
            $digest = hash('sha256', $this->canonicalJson($digestPayload));
            $id = (int)Db::name(self::EVENT_TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'sequence_no' => $sequence,
                'event_type' => $eventType,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'event_payload_json' => $payloadJson,
                'previous_digest' => $previousDigest,
                'content_digest' => $digest,
                'created_at' => $createdAt,
            ]);
            $row = Db::name(self::EVENT_TABLE)
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', $intentId)
                ->find();
            $normalized = is_array($row) ? $this->normalizeEvent($row) : [];
            $postAppendIntegrity = $this->verifyEventChain(
                [...$existingEvents, $normalized],
                $tenantId,
                $hotelId,
                $intentId
            );
            if ((int)($normalized['id'] ?? 0) !== $id
                || !hash_equals($digest, strtolower(trim((string)($normalized['content_digest'] ?? ''))))
                || $postAppendIntegrity['status'] !== 'verified'
            ) {
                throw new RuntimeException('运营行动生命周期事件保存后回读失败');
            }
            return $normalized;
        });
    }

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $task
     * @param list<array<string,mixed>> $evidenceRows
     * @return array<string,mixed>
     */
    public function appendReview(
        array $intent,
        array $task,
        array $evidenceRows,
        string $resultStatus,
        string $resultSummary,
        int $reviewedBy,
        string $reviewedAt
    ): array {
        if (!$this->isManagedIntent($intent)) {
            return [];
        }
        $this->assertSchemaReady();
        if ($reviewedBy <= 0) {
            throw new InvalidArgumentException('运营行动复盘必须由已登录用户主动确认');
        }
        return Db::transaction(function () use (
            $intent,
            $task,
            $evidenceRows,
            $resultStatus,
            $resultSummary,
            $reviewedBy,
            $reviewedAt
        ): array {
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        $taskId = (int)($task['id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0 || $intentId <= 0 || $taskId <= 0
            || (int)($task['tenant_id'] ?? 0) !== $tenantId
            || (int)($task['hotel_id'] ?? 0) !== $hotelId
            || (int)($task['intent_id'] ?? 0) !== $intentId
        ) {
            throw new InvalidArgumentException('运营行动统一复盘身份或任务范围无效');
        }
        $lockedIntent = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->lock(true)
            ->find();
        if (!is_array($lockedIntent)) {
            throw new RuntimeException('运营行动执行意图不存在或范围已变化');
        }
        $existingReviews = array_map(
            [$this, 'normalizeReview'],
            Db::name(self::REVIEW_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', $intentId)
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray()
        );
        $reviewIntegrity = $this->verifyReviewChain($existingReviews, $tenantId, $hotelId, $intentId);
        if ($reviewIntegrity['status'] === 'invalid') {
            throw new RuntimeException('运营行动统一复盘链损坏：' . $reviewIntegrity['failure_reason']);
        }
        $card = $this->cardFromIntent($intent);
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $metricUnit = trim((string)($card['metric_contract']['unit'] ?? ''));
        $sourceEvidence = null;
        $executionRefs = [];
        foreach ($evidenceRows as $row) {
            $row = $this->normalizeEvidence($row);
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $executionRefs[] = 'operation_execution_evidence#' . $id;
            }
            if ($sourceEvidence === null
                && strtolower(trim((string)($row['evidence_type'] ?? ''))) === 'source_verified_metric_readback'
            ) {
                $sourceEvidence = $row;
            }
        }
        $beforeValue = null;
        $afterValue = null;
        $sourceContext = [];
        $sufficiency = 'insufficient';
        $reasons = [];
        if (is_array($sourceEvidence)) {
            $sourceContext = is_array($sourceEvidence['platform_response'] ?? null)
                ? $sourceEvidence['platform_response']
                : [];
            $before = is_array($sourceEvidence['before'] ?? null) ? $sourceEvidence['before'] : [];
            $after = is_array($sourceEvidence['after'] ?? null) ? $sourceEvidence['after'] : [];
            $sourceMetric = strtolower(trim((string)($sourceContext['metric_key'] ?? '')));
            $sourceUnit = trim((string)($sourceContext['metric_unit'] ?? ''));
            if (($sourceContext['readback_verified'] ?? false) === true
                && ($sourceContext['database_written'] ?? false) === true
                && $sourceMetric === $metricKey
                && $sourceUnit === $metricUnit
                && is_numeric($before[$metricKey] ?? null)
                && is_numeric($after[$metricKey] ?? null)
                && $metricUnit !== ''
            ) {
                $beforeValue = round((float)$before[$metricKey], 6);
                $afterValue = round((float)$after[$metricKey], 6);
                $sufficiency = 'sufficient';
            } elseif (($sourceMetric !== '' && $sourceMetric !== $metricKey)
                || ($sourceUnit !== '' && $sourceUnit !== $metricUnit)
            ) {
                $sufficiency = 'mismatched';
                $reasons[] = $sourceMetric !== $metricKey
                    ? 'metric_key_mismatch'
                    : 'metric_unit_mismatch';
            }
        }
        if ($sourceEvidence === null) {
            $reasons[] = 'same_scope_source_readback_missing';
        }
        if ($metricUnit === '') {
            $sufficiency = 'mismatched';
            $reasons[] = 'metric_unit_missing';
        }
        if ($beforeValue === null || $afterValue === null) {
            $reasons[] = 'before_after_metric_value_missing';
        }
        $reasons[] = 'observational_before_after_no_control_group';
        $reasons[] = 'external_market_and_inventory_factors_not_isolated';
        $reasons = array_values(array_unique($reasons));
        if ($this->isDailyOneThingIntent($intent) && $sufficiency !== 'sufficient') {
            throw new InvalidArgumentException(
                '每日一件事缺少同酒店、同平台、同指标的后续自然事实，保持 review_pending'
            );
        }
        $delta = $beforeValue !== null && $afterValue !== null
            ? round($afterValue - $beforeValue, 6)
            : null;
        $changeStatus = $delta === null
            ? 'unknown'
            : (abs($delta) <= 0.000001 ? 'unchanged' : ($delta > 0 ? 'increased' : 'decreased'));
        $recommendation = match (strtolower(trim($resultStatus))) {
            'success' => $sufficiency === 'sufficient' ? 'continue' : 'adjust',
            'failed' => $sufficiency === 'sufficient' ? 'stop' : 'adjust',
            default => 'adjust',
        };
        $effectReview = $this->tableExists('operation_effect_reviews')
            ? Db::name('operation_effect_reviews')
                ->where('tenant_id', (int)$intent['tenant_id'])
                ->where('hotel_id', (int)$intent['hotel_id'])
                ->where('intent_id', (int)$intent['id'])
                ->where('task_id', (int)$task['id'])
                ->order('id', 'desc')
                ->find()
            : null;
        $effectReviewId = (int)($effectReview['id'] ?? 0);
        if ($effectReviewId > 0) {
            $executionRefs[] = 'operation_effect_reviews#' . $effectReviewId;
        }
        foreach (['source_ref', 'baseline_source_ref', 'followup_source_ref'] as $field) {
            $ref = trim((string)($sourceContext[$field] ?? ''));
            if ($ref !== '') {
                $executionRefs[] = $ref;
            }
        }
        $executionRefs = array_values(array_unique($executionRefs));
        $previous = $existingReviews === [] ? null : $existingReviews[count($existingReviews) - 1];
        $baselineWindow = [
            'date_start' => (string)($card['business_window']['date_start'] ?? $intent['date_start'] ?? ''),
            'date_end' => (string)($sourceContext['baseline_date'] ?? $card['business_window']['date_end'] ?? $intent['date_end'] ?? ''),
            'value' => $beforeValue,
            'fact_refs' => array_values((array)($card['fact_refs'] ?? [])),
        ];
        $followupWindow = [
            'date_start' => (string)($sourceContext['review_date'] ?? $card['metric_contract']['followup_window']['business_date'] ?? ''),
            'date_end' => (string)($sourceContext['review_date'] ?? $card['metric_contract']['followup_window']['business_date'] ?? ''),
            'value' => $afterValue,
            'readback_at' => (string)($sourceContext['readback_at'] ?? ''),
        ];
        $payload = [
            'tenant_id' => (int)$intent['tenant_id'],
            'hotel_id' => (int)$intent['hotel_id'],
            'intent_id' => (int)$intent['id'],
            'task_id' => (int)$task['id'],
            'effect_review_id' => $effectReviewId > 0 ? $effectReviewId : null,
            'contract_version' => self::REVIEW_CONTRACT_VERSION,
            'metric_key' => $metricKey,
            'metric_unit' => $metricUnit,
            'baseline_window' => $baselineWindow,
            'followup_window' => $followupWindow,
            'before_value' => $beforeValue,
            'after_value' => $afterValue,
            'delta_value' => $delta,
            'metric_change_status' => $changeStatus,
            'evidence_sufficiency' => $sufficiency,
            'evidence_refs' => $executionRefs,
            'non_attribution_reasons' => $reasons,
            'recommendation' => $recommendation,
            'result_status' => strtolower(trim($resultStatus)) ?: 'observing',
            'result_summary' => trim($resultSummary),
            'causality_claimed' => false,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt,
            'previous_review_id' => is_array($previous) ? (int)$previous['id'] : null,
            'previous_digest' => strtolower(trim((string)($previous['content_digest'] ?? ''))),
        ];
        $digest = hash('sha256', $this->canonicalJson($payload));
        $id = (int)Db::name(self::REVIEW_TABLE)->insertGetId([
            'tenant_id' => $payload['tenant_id'],
            'hotel_id' => $payload['hotel_id'],
            'intent_id' => $payload['intent_id'],
            'task_id' => $payload['task_id'],
            'effect_review_id' => $payload['effect_review_id'],
            'contract_version' => self::REVIEW_CONTRACT_VERSION,
            'metric_key' => $metricKey,
            'metric_unit' => $metricUnit,
            'baseline_window_json' => $this->canonicalJson($baselineWindow),
            'followup_window_json' => $this->canonicalJson($followupWindow),
            'before_value' => $beforeValue,
            'after_value' => $afterValue,
            'delta_value' => $delta,
            'metric_change_status' => $changeStatus,
            'evidence_sufficiency' => $sufficiency,
            'evidence_refs_json' => $this->canonicalJson($executionRefs),
            'non_attribution_reasons_json' => $this->canonicalJson($reasons),
            'recommendation' => $recommendation,
            'result_status' => $payload['result_status'],
            'result_summary' => $payload['result_summary'],
            'causality_claimed' => 0,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt,
            'previous_review_id' => $payload['previous_review_id'],
            'previous_digest' => $payload['previous_digest'],
            'content_digest' => $digest,
            'created_at' => $reviewedAt,
        ]);
        $row = Db::name(self::REVIEW_TABLE)->where('id', $id)->find();
        $normalized = is_array($row) ? $this->normalizeReview($row) : [];
        if ((int)($normalized['id'] ?? 0) !== $id
            || !hash_equals($digest, strtolower(trim((string)($normalized['content_digest'] ?? ''))))
            || $this->reviewDigest($normalized) !== $digest
            || $this->verifyReviewChain(
                [...$existingReviews, $normalized],
                $tenantId,
                $hotelId,
                $intentId
            )['status'] !== 'verified'
        ) {
            throw new RuntimeException('运营行动效果复盘保存后精确回读失败');
        }
        return $normalized;
        });
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function decorateIntent(array $intent): array
    {
        return $this->decorateIntentWithTaskScope($intent, false);
    }

    /**
     * Use only for an intent whose complete task collection was loaded from
     * the database in the same aggregate read. Ordinary callers must use
     * decorateIntent(), which re-verifies every supplied task against storage.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    public function decorateCurrentDatabaseAggregate(array $intent): array
    {
        return $this->decorateIntentWithTaskScope($intent, true);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function decorateIntentWithTaskScope(array $intent, bool $trustedCurrentDatabaseAggregate): array
    {
        if (!$this->isManagedIntent($intent)) {
            return $intent;
        }
        $tenantId = (int)$intent['tenant_id'];
        $hotelId = (int)$intent['hotel_id'];
        $intentId = (int)$intent['id'];
        $candidateTaskScope = $this->knownTaskScopeFromIntent($intent);
        $knownTaskScope = $trustedCurrentDatabaseAggregate
            ? $candidateTaskScope
            : $this->verifiedTaskScopeFromDatabase(
                $tenantId,
                $hotelId,
                $intentId,
                $candidateTaskScope
            );
        $eventChain = $this->readEventChain($tenantId, $hotelId, $intentId, $knownTaskScope);
        $reviewChain = $this->readReviewChain($tenantId, $hotelId, $intentId, $knownTaskScope);
        $events = $eventChain['events'];
        $reviews = array_reverse($reviewChain['reviews']);
        $taskIds = $knownTaskScope === null
            ? array_values(array_filter(array_map(
                static fn(array $task): int => (int)($task['id'] ?? 0),
                (array)($intent['tasks'] ?? [])
            )))
            : array_map('intval', array_keys($knownTaskScope));
        $evidenceIds = [];
        if ($taskIds !== []) {
            $evidenceIds = array_map(
                'intval',
                Db::name('operation_execution_evidence')
                    ->whereIn('task_id', $taskIds)
                    ->where('tenant_id', (int)$intent['tenant_id'])
                    ->whereNull('deleted_at')
                    ->column('id')
            );
        }
        $intent['action_management'] = $this->managementProjection(
            $intent,
            $events,
            $reviews,
            $taskIds,
            $evidenceIds,
            $eventChain['integrity'],
            $reviewChain['integrity']
        );
        return $intent;
    }

    /**
     * @param array<int,true>|null $candidateTaskScope
     * @return array<int,true>|null
     */
    private function verifiedTaskScopeFromDatabase(
        int $tenantId,
        int $hotelId,
        int $intentId,
        ?array $candidateTaskScope
    ): ?array {
        if ($candidateTaskScope === null) {
            return null;
        }
        $verified = [];
        foreach (array_keys($candidateTaskScope) as $taskId) {
            if ($this->taskScopeExists($tenantId, $hotelId, $intentId, (int)$taskId)) {
                $verified[(int)$taskId] = true;
            }
        }
        return $verified;
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    public function decorateTask(array $task, array $intent): array
    {
        if (!$this->isManagedIntent($intent)) {
            return $task;
        }
        $eventChain = $this->readEventChain(
            (int)$intent['tenant_id'],
            (int)$intent['hotel_id'],
            (int)$intent['id']
        );
        $reviewChain = $this->readReviewChain(
            (int)$intent['tenant_id'],
            (int)$intent['hotel_id'],
            (int)$intent['id']
        );
        $events = $eventChain['events'];
        $reviews = array_reverse($reviewChain['reviews']);
        $evidenceIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            (array)($task['evidence'] ?? [])
        )));
        $task['action_management'] = $this->managementProjection(
            array_merge($intent, ['tasks' => [$task]]),
            $events,
            $reviews,
            [(int)$task['id']],
            $evidenceIds,
            $eventChain['integrity'],
            $reviewChain['integrity']
        );
        return $task;
    }

    /** @return list<array<string,mixed>> */
    public function eventsForIntent(int $tenantId, int $hotelId, int $intentId): array
    {
        return $this->readEventChain($tenantId, $hotelId, $intentId)['events'];
    }

    /** @return list<array<string,mixed>> */
    public function reviewsForIntent(int $tenantId, int $hotelId, int $intentId): array
    {
        return array_reverse($this->readReviewChain($tenantId, $hotelId, $intentId)['reviews']);
    }

    /** @param array<string,mixed> $intent */
    public function currentStatus(array $intent, array $events = []): string
    {
        if ($events !== []) {
            $integrity = $this->verifyEventChain(
                $events,
                (int)($intent['tenant_id'] ?? 0),
                (int)($intent['hotel_id'] ?? 0),
                (int)($intent['id'] ?? 0)
            );
            if ($integrity['status'] !== 'verified') {
                throw new RuntimeException('运营行动生命周期状态不可用：' . $integrity['failure_reason']);
            }
        }
        return $this->statusFromVerifiedEvents($intent, $events);
    }

    /** @param array<string,mixed> $intent */
    private function statusFromVerifiedEvents(array $intent, array $events): string
    {
        $isDaily = $this->isDailyOneThingIntent($intent);
        $allowedStatuses = $isDaily ? self::DAILY_STATUSES : self::STATUSES;
        if ($events !== []) {
            $latest = $events[count($events) - 1];
            $status = strtolower(trim((string)($latest['to_status'] ?? '')));
            if (in_array($status, $allowedStatuses, true)) {
                return $status;
            }
        }
        $intentStatus = strtolower(trim((string)($intent['status'] ?? '')));
        if ($isDaily) {
            if (in_array($intentStatus, ['blocked', 'rejected', 'cancelled'], true)) {
                return 'blocked';
            }
            if ($intentStatus === 'draft') {
                return 'draft';
            }
            if ($intentStatus !== 'approved') {
                return 'pending_approval';
            }
            $tasks = array_values((array)($intent['tasks'] ?? []));
            $task = $tasks === [] ? [] : $tasks[count($tasks) - 1];
            $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
            if ($taskStatus === 'executing') {
                return 'executing';
            }
            if (in_array($taskStatus, ['blocked', 'failed', 'cancelled'], true)) {
                return 'blocked';
            }
            if ($taskStatus === 'executed') {
                return in_array(strtolower(trim((string)($task['result_status'] ?? ''))), ['success', 'near_success', 'failed'], true)
                    ? 'reviewed'
                    : 'review_pending';
            }
            return 'approved';
        }
        if (in_array($intentStatus, ['cancelled', 'rejected'], true)) {
            return 'cancelled';
        }
        if ($intentStatus === 'draft') {
            return 'draft';
        }
        if ($intentStatus !== 'approved') {
            return 'pending_approval';
        }
        $tasks = array_values((array)($intent['tasks'] ?? []));
        $task = $tasks === [] ? [] : $tasks[count($tasks) - 1];
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        if ($taskStatus === 'executing') {
            return 'in_progress';
        }
        if ($taskStatus === 'executed') {
            return in_array(strtolower(trim((string)($task['result_status'] ?? ''))), ['success', 'near_success', 'failed'], true)
                ? 'reviewed'
                : 'completed';
        }
        if ($taskStatus === 'cancelled') {
            return 'cancelled';
        }
        return 'approved';
    }

    private function assertDailyTransition(string $fromStatus, string $toStatus): void
    {
        $allowed = [
            '' => ['draft'],
            'draft' => ['pending_approval', 'blocked'],
            'pending_approval' => ['pending_approval', 'approved', 'blocked'],
            'approved' => ['approved', 'executing', 'blocked'],
            'executing' => ['evidence_recorded', 'blocked'],
            'evidence_recorded' => ['review_pending', 'blocked'],
            'review_pending' => ['review_pending', 'reviewed', 'blocked'],
            'reviewed' => ['reviewed'],
            'blocked' => ['blocked'],
        ];
        if (!in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
            throw new InvalidArgumentException(
                '每日一件事生命周期不允许从 ' . ($fromStatus !== '' ? $fromStatus : '初始') . ' 跳到 ' . $toStatus
            );
        }
    }

    private function managementProjection(
        array $intent,
        array $events,
        array $reviews,
        array $taskIds,
        array $evidenceIds,
        array $integrity,
        array $reviewIntegrity
    ): array {
        $card = $this->cardFromIntent($intent);
        $questionRef = trim((string)($card['trace']['question_ref'] ?? ''));
        $isOperatingQuestion = strtolower(trim((string)($intent['source_module'] ?? '')))
            === OperatingQuestionExecutionBridgeService::SOURCE_MODULE;
        $questionId = $isOperatingQuestion
            ? (preg_match('/#([1-9][0-9]*)$/D', $questionRef, $matches) === 1
                ? (int)$matches[1]
                : (int)($intent['source_record_id'] ?? 0))
            : 0;
        $isDaily = $this->isDailyOneThingIntent($intent);
        $taskCount = count($taskIds);
        return [
            'contract_version' => (string)($card['contract_version'] ?? self::CARD_CONTRACT_VERSION),
            'action_card' => $card,
            'lifecycle' => [
                'status' => $this->statusFromVerifiedEvents($intent, $events),
                'allowed_statuses' => $isDaily ? self::DAILY_STATUSES : self::STATUSES,
                'event_count' => count($events),
                'events' => $events,
                'integrity_status' => $integrity['status'],
                'integrity_failure_reason' => $integrity['failure_reason'],
            ],
            'integrity' => [
                'status' => $integrity['status'] === 'verified'
                    && in_array($reviewIntegrity['status'], ['verified', 'missing'], true)
                    ? 'verified'
                    : 'invalid',
                'event_chain_status' => $integrity['status'],
                'event_chain_failure_reason' => $integrity['failure_reason'],
                'review_chain_status' => $reviewIntegrity['status'],
                'review_chain_failure_reason' => $reviewIntegrity['failure_reason'],
            ],
            'traceability' => [
                'question_ref' => $questionId > 0 ? 'hotel_operating_questions#' . $questionId : null,
                'answer_ref' => $questionId > 0 ? 'hotel_operating_questions#' . $questionId . ':answer' : null,
                'source_ref' => trim((string)($card['trace']['daily_one_thing_run_ref'] ?? ''))
                    ?: ((int)($intent['source_record_id'] ?? 0) > 0
                        ? (string)($intent['source_module'] ?? 'source') . '#' . (int)$intent['source_record_id']
                        : null),
                'action_ref' => 'operation_execution_intents#' . (int)$intent['id'],
                'task_refs' => array_map(static fn(int $id): string => 'operation_execution_tasks#' . $id, $taskIds),
                'evidence_refs' => array_map(static fn(int $id): string => 'operation_execution_evidence#' . $id, $evidenceIds),
                'review_refs' => array_map(static fn(array $row): string => 'operation_action_reviews#' . (int)$row['id'], $reviews),
            ],
            'task_count' => $taskCount,
            'task_cardinality_status' => $taskCount === 0
                ? 'none'
                : ($taskCount === 1 ? 'exactly_one' : 'invalid_multiple'),
            'reviews' => $reviews,
            'review_integrity_status' => $reviewIntegrity['status'],
            'review_integrity_failure_reason' => $reviewIntegrity['failure_reason'],
            'latest_review' => $reviewIntegrity['status'] === 'invalid' ? null : ($reviews[0] ?? null),
            'historical_records_mutated' => false,
            'external_action_performed_by_system' => false,
            'human_execution_evidence_recorded' => $evidenceIds !== [],
            'external_action_performed' => false,
        ];
    }

    /** @return array{status:string,failure_reason:?string} */
    private function verifyEventChain(
        array $events,
        int $tenantId = 0,
        int $hotelId = 0,
        int $intentId = 0,
        ?array $knownTaskScope = null
    ): array {
        $previous = '';
        $previousStatus = '';
        $expectedSequence = 1;
        $referencedTaskIds = [];
        foreach ($events as $event) {
            if ((int)($event['sequence_no'] ?? 0) !== $expectedSequence
                || ($tenantId > 0 && (int)($event['tenant_id'] ?? 0) !== $tenantId)
                || ($hotelId > 0 && (int)($event['hotel_id'] ?? 0) !== $hotelId)
                || ($intentId > 0 && (int)($event['intent_id'] ?? 0) !== $intentId)
                || (string)($event['from_status'] ?? '') !== $previousStatus
                || !in_array((string)($event['to_status'] ?? ''), self::STATUSES, true)
                || strtolower(trim((string)($event['previous_digest'] ?? ''))) !== $previous
                || !$this->isDigest((string)($event['content_digest'] ?? ''))
                || !hash_equals(strtolower((string)$event['content_digest']), $this->eventDigest($event))
            ) {
                return ['status' => 'invalid', 'failure_reason' => 'event_chain_digest_or_sequence_mismatch'];
            }
            $previous = strtolower((string)$event['content_digest']);
            $previousStatus = (string)$event['to_status'];
            $eventTaskId = (int)($event['task_id'] ?? 0);
            if ($eventTaskId > 0) {
                $referencedTaskIds[$eventTaskId] = true;
            }
            $expectedSequence++;
        }
        foreach (array_keys($referencedTaskIds) as $referencedTaskId) {
            if (!$this->taskScopeVerified(
                $tenantId,
                $hotelId,
                $intentId,
                (int)$referencedTaskId,
                $knownTaskScope
            )) {
                return ['status' => 'invalid', 'failure_reason' => 'event_chain_task_scope_mismatch'];
            }
        }
        return $events === []
            ? ['status' => 'missing', 'failure_reason' => 'lifecycle_events_missing']
            : ['status' => 'verified', 'failure_reason' => null];
    }

    /** @return array{status:string,failure_reason:?string} */
    private function verifyReviewChain(
        array $reviews,
        int $tenantId = 0,
        int $hotelId = 0,
        int $intentId = 0,
        ?array $knownTaskScope = null
    ): array {
        $previousId = null;
        $previousDigest = '';
        $taskId = null;
        foreach ($reviews as $review) {
            $currentTaskId = (int)($review['task_id'] ?? 0);
            $storedPreviousId = ($review['previous_review_id'] ?? null) === null
                ? null
                : (int)$review['previous_review_id'];
            $digest = strtolower(trim((string)($review['content_digest'] ?? '')));
            if (($tenantId > 0 && (int)($review['tenant_id'] ?? 0) !== $tenantId)
                || ($hotelId > 0 && (int)($review['hotel_id'] ?? 0) !== $hotelId)
                || ($intentId > 0 && (int)($review['intent_id'] ?? 0) !== $intentId)
                || $currentTaskId <= 0
                || ($taskId !== null && $currentTaskId !== $taskId)
                || $storedPreviousId !== $previousId
                || strtolower(trim((string)($review['previous_digest'] ?? ''))) !== $previousDigest
                || !$this->isDigest($digest)
                || !hash_equals($digest, $this->reviewDigest($review))
            ) {
                return ['status' => 'invalid', 'failure_reason' => 'review_chain_digest_identity_or_link_mismatch'];
            }
            $taskId ??= $currentTaskId;
            $previousId = (int)($review['id'] ?? 0);
            $previousDigest = $digest;
        }
        if ($taskId !== null && !$this->taskScopeVerified(
            $tenantId,
            $hotelId,
            $intentId,
            $taskId,
            $knownTaskScope
        )) {
            return ['status' => 'invalid', 'failure_reason' => 'review_chain_task_scope_mismatch'];
        }
        return $reviews === []
            ? ['status' => 'missing', 'failure_reason' => 'lifecycle_reviews_missing']
            : ['status' => 'verified', 'failure_reason' => null];
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<int,true>|null
     */
    private function knownTaskScopeFromIntent(array $intent): ?array
    {
        if (!array_key_exists('tasks', $intent) || !is_array($intent['tasks'])) {
            return null;
        }
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        $known = [];
        foreach ($intent['tasks'] as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskId = (int)($task['id'] ?? 0);
            if ($taskId > 0
                && (int)($task['tenant_id'] ?? 0) === $tenantId
                && (int)($task['hotel_id'] ?? 0) === $hotelId
                && (int)($task['intent_id'] ?? 0) === $intentId
            ) {
                $known[$taskId] = true;
            }
        }
        return $known;
    }

    /** @param array<int,true>|null $knownTaskScope */
    private function taskScopeVerified(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId,
        ?array $knownTaskScope
    ): bool {
        if ($knownTaskScope !== null && ($knownTaskScope[$taskId] ?? false) === true) {
            return true;
        }
        return $this->taskScopeExists($tenantId, $hotelId, $intentId, $taskId);
    }

    /**
     * @param array<int,true>|null $knownTaskScope
     * @return array{events:list<array<string,mixed>>,integrity:array{status:string,failure_reason:?string}}
     */
    private function readEventChain(
        int $tenantId,
        int $hotelId,
        int $intentId,
        ?array $knownTaskScope = null
    ): array {
        if (!$this->tableExists(self::EVENT_TABLE)) {
            return [
                'events' => [],
                'integrity' => ['status' => 'missing', 'failure_reason' => 'lifecycle_events_missing'],
            ];
        }
        $events = array_map(
            [$this, 'normalizeEvent'],
            Db::name(self::EVENT_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', $intentId)
                ->order('sequence_no', 'asc')
                ->select()
                ->toArray()
        );
        $integrity = $this->verifyEventChain(
            $events,
            $tenantId,
            $hotelId,
            $intentId,
            $knownTaskScope
        );
        if ($integrity['status'] === 'invalid') {
            throw new RuntimeException('运营行动生命周期事件链损坏：' . $integrity['failure_reason']);
        }
        return ['events' => $events, 'integrity' => $integrity];
    }

    /**
     * @param array<int,true>|null $knownTaskScope
     * @return array{reviews:list<array<string,mixed>>,integrity:array{status:string,failure_reason:?string}}
     */
    private function readReviewChain(
        int $tenantId,
        int $hotelId,
        int $intentId,
        ?array $knownTaskScope = null
    ): array {
        if (!$this->tableExists(self::REVIEW_TABLE)) {
            return [
                'reviews' => [],
                'integrity' => ['status' => 'missing', 'failure_reason' => 'lifecycle_reviews_missing'],
            ];
        }
        $reviews = array_map(
            [$this, 'normalizeReview'],
            Db::name(self::REVIEW_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', $intentId)
                ->order('id', 'asc')
                ->select()
                ->toArray()
        );
        $integrity = $this->verifyReviewChain(
            $reviews,
            $tenantId,
            $hotelId,
            $intentId,
            $knownTaskScope
        );
        if ($integrity['status'] === 'invalid') {
            throw new RuntimeException('运营行动统一复盘链损坏：' . $integrity['failure_reason']);
        }
        return ['reviews' => $reviews, 'integrity' => $integrity];
    }

    private function taskScopeExists(int $tenantId, int $hotelId, int $intentId, int $taskId): bool
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $intentId <= 0 || $taskId <= 0
            || !$this->tableExists('operation_execution_tasks')
        ) {
            return false;
        }
        return is_array(Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->find());
    }

    /** @param array<string,mixed> $question @param array<string,mixed> $action */
    private function metricBaseline(array $question, array $action, string $metric): array
    {
        if ($metric === '') {
            throw new InvalidArgumentException('行动卡缺少预期指标');
        }
        $refs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['evidence_refs'] ?? [])
        ))));
        $rows = [];
        $units = [];
        foreach ((array)($question['answer']['fact_samples'] ?? []) as $fact) {
            if (!is_array($fact) || !in_array((string)($fact['ref'] ?? ''), $refs, true)) {
                continue;
            }
            $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $metricUnits = is_array($fact['metric_units'] ?? null) ? $fact['metric_units'] : [];
            $unit = trim((string)($metricUnits[$metric] ?? ''));
            if (!is_numeric($values[$metric] ?? null) || $unit === '') {
                continue;
            }
            $units[$unit] = true;
            $rows[] = [
                'ref' => (string)$fact['ref'],
                'platform' => strtolower(trim((string)($fact['platform'] ?? ''))),
                'business_date' => substr(trim((string)($fact['data_date'] ?? '')), 0, 10),
                'metric_key' => $metric,
                'value' => round((float)$values[$metric], 6),
                'unit' => $unit,
            ];
        }
        if ($rows === [] || count($rows) !== count($refs)) {
            throw new InvalidArgumentException('行动卡事实不足，无法形成完整指标基线');
        }
        if (count($units) !== 1) {
            throw new InvalidArgumentException('行动卡指标单位不匹配，不能进入审批');
        }
        $unit = (string)array_key_first($units);
        $aggregation = preg_match('/(?:rate|ratio|percent|pct|score)/i', $unit) === 1
            ? 'average'
            : 'sum';
        $values = array_map(static fn(array $row): float => (float)$row['value'], $rows);
        $value = $aggregation === 'average'
            ? array_sum($values) / count($values)
            : array_sum($values);
        return [
            'unit' => $unit,
            'aggregation' => $aggregation,
            'value' => round($value, 6),
            'rows' => $rows,
        ];
    }

    /** @param array<string,mixed> $card */
    private function assertCardShape(array $card): void
    {
        if (!self::isManagedCard($card)
            || !in_array((string)($card['status'] ?? ''), ['draft', 'pending_approval', 'approved'], true)
            || (int)($card['hotel']['tenant_id'] ?? 0) <= 0
            || (int)($card['hotel']['hotel_id'] ?? 0) <= 0
            || trim((string)($card['source']['module'] ?? '')) === ''
            || (int)($card['source']['record_id'] ?? 0) <= 0
            || trim((string)($card['source']['platform'] ?? '')) === ''
            || trim((string)($card['action']['type'] ?? '')) === ''
            || trim((string)($card['action']['title'] ?? '')) === ''
            || trim((string)($card['action']['description'] ?? '')) === ''
            || trim((string)($card['action']['object'] ?? '')) === ''
            || trim((string)($card['reason'] ?? '')) === ''
            || trim((string)($card['risk']['level'] ?? '')) === ''
            || trim((string)($card['risk']['summary'] ?? '')) === ''
            || !is_array($card['risk']['controls'] ?? null)
            || $card['risk']['controls'] === []
            || !is_array($card['risk']['stop_conditions'] ?? null)
            || $card['risk']['stop_conditions'] === []
            || (int)($card['responsibility']['owner_id'] ?? 0) <= 0
            || trim((string)($card['responsibility']['due_at'] ?? '')) === ''
            || trim((string)($card['metric_contract']['metric_key'] ?? '')) === ''
            || trim((string)($card['metric_contract']['unit'] ?? '')) === ''
            || trim((string)($card['metric_contract']['expected_direction'] ?? '')) === ''
            || trim((string)($card['metric_contract']['target_type'] ?? '')) === ''
            || !is_numeric($card['metric_contract']['baseline_window']['value'] ?? null)
            || !is_array($card['fact_refs'] ?? null)
            || $card['fact_refs'] === []
            || ($card['approval']['required'] ?? false) !== true
            || ($card['approval']['fact_reread_required'] ?? false) !== true
            || ($card['boundaries']['automatic_execution'] ?? true) !== false
            || ($card['boundaries']['automatic_ota_write'] ?? true) !== false
            || ($card['boundaries']['external_message'] ?? true) !== false
        ) {
            throw new InvalidArgumentException('行动卡字段不完整或越过外部操作授权边界');
        }
        if ((string)($card['metric_contract']['target_type'] ?? '') === 'observation'
            && ((string)($card['metric_contract']['expected_direction'] ?? '') !== 'observe'
                || ($card['metric_contract']['target_value'] ?? null) !== null
                || ($card['metric_contract']['expected_delta'] ?? null) !== null)
        ) {
            throw new InvalidArgumentException('观察型行动只能保存观察目标，不能编造数值目标');
        }
        $this->requiredDateRange(
            (string)($card['business_window']['date_start'] ?? ''),
            (string)($card['business_window']['date_end'] ?? '')
        );
        $this->requiredDateTime($card['responsibility']['due_at'] ?? null, '行动卡截止时间');
        $this->requiredDateTime($card['metric_contract']['followup_window']['review_at'] ?? null, '行动卡复盘时间');
        if (is_array($card['execution_window'] ?? null)) {
            $executionStart = $this->requiredDateTime(
                $card['execution_window']['start_at'] ?? null,
                '行动卡执行窗口开始时间'
            );
            $executionEnd = $this->requiredDateTime(
                $card['execution_window']['end_at'] ?? null,
                '行动卡执行窗口结束时间'
            );
            if ($executionEnd <= $executionStart
                || trim((string)($card['execution_window']['timezone'] ?? '')) !== 'Asia/Shanghai'
            ) {
                throw new InvalidArgumentException('行动卡执行窗口无效');
            }
        }
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function cardFromIntent(array $intent): array
    {
        $target = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson($intent['target_value_json'] ?? null);
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson($intent['evidence_json'] ?? null);
        $card = is_array($target['action_card'] ?? null)
            ? $target['action_card']
            : (is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : []);
        return $card;
    }

    /** @param array<string,mixed> $card */
    public function actionIdentityDigest(array $card): string
    {
        return hash('sha256', $this->canonicalJson([
            'contract_version' => (string)($card['contract_version'] ?? self::CARD_CONTRACT_VERSION),
            'hotel' => [
                'tenant_id' => (int)($card['hotel']['tenant_id'] ?? 0),
                'hotel_id' => (int)($card['hotel']['hotel_id'] ?? 0),
            ],
            'source_scope' => [
                'platform' => strtolower(trim((string)($card['source']['platform'] ?? ''))),
                'source_scope' => strtolower(trim((string)($card['source']['source_scope'] ?? ''))),
            ],
            'business_window' => [
                'date_start' => substr(trim((string)($card['business_window']['date_start'] ?? '')), 0, 10),
                'date_end' => substr(trim((string)($card['business_window']['date_end'] ?? '')), 0, 10),
            ],
            'metric_contract' => [
                'metric_key' => strtolower(trim((string)($card['metric_contract']['metric_key'] ?? ''))),
                'metric_unit' => strtolower(trim((string)($card['metric_contract']['unit'] ?? ''))),
                'target_type' => strtolower(trim((string)($card['metric_contract']['target_type'] ?? 'observation'))),
            ],
            // The opportunity is the durable business identity. Snapshot and
            // evidence digests attest a particular observation, but refreshing
            // them must not create a second execution intent for the same work.
            'opportunity_key' => trim((string)($card['trace']['opportunity_key'] ?? '')),
        ]));
    }

    /** @param array<string,mixed> $card */
    private function identityDigest(array $card): string
    {
        return $this->actionIdentityDigest($card);
    }

    /** @param array<string,mixed> $card */
    private function cardDigest(array $card): string
    {
        unset($card['content_digest']);
        return hash('sha256', $this->canonicalJson($card));
    }

    /** @param array<string,mixed> $event */
    private function eventDigest(array $event): string
    {
        return hash('sha256', $this->canonicalJson([
            'tenant_id' => (int)($event['tenant_id'] ?? 0),
            'hotel_id' => (int)($event['hotel_id'] ?? 0),
            'intent_id' => (int)($event['intent_id'] ?? 0),
            'task_id' => (int)($event['task_id'] ?? 0),
            'sequence_no' => (int)($event['sequence_no'] ?? 0),
            'event_type' => (string)($event['event_type'] ?? ''),
            'from_status' => (string)($event['from_status'] ?? ''),
            'to_status' => (string)($event['to_status'] ?? ''),
            'actor_id' => (int)($event['actor_id'] ?? 0),
            'event_payload' => is_array($event['event_payload'] ?? null) ? $event['event_payload'] : [],
            'previous_digest' => (string)($event['previous_digest'] ?? ''),
            'created_at' => (string)($event['created_at'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $review */
    private function reviewDigest(array $review): string
    {
        return hash('sha256', $this->canonicalJson([
            'tenant_id' => (int)($review['tenant_id'] ?? 0),
            'hotel_id' => (int)($review['hotel_id'] ?? 0),
            'intent_id' => (int)($review['intent_id'] ?? 0),
            'task_id' => (int)($review['task_id'] ?? 0),
            'effect_review_id' => ($review['effect_review_id'] ?? null) === null ? null : (int)$review['effect_review_id'],
            'contract_version' => (string)($review['contract_version'] ?? ''),
            'metric_key' => (string)($review['metric_key'] ?? ''),
            'metric_unit' => (string)($review['metric_unit'] ?? ''),
            'baseline_window' => $review['baseline_window'] ?? [],
            'followup_window' => $review['followup_window'] ?? [],
            'before_value' => $review['before_value'] ?? null,
            'after_value' => $review['after_value'] ?? null,
            'delta_value' => $review['delta_value'] ?? null,
            'metric_change_status' => (string)($review['metric_change_status'] ?? ''),
            'evidence_sufficiency' => (string)($review['evidence_sufficiency'] ?? ''),
            'evidence_refs' => $review['evidence_refs'] ?? [],
            'non_attribution_reasons' => $review['non_attribution_reasons'] ?? [],
            'recommendation' => (string)($review['recommendation'] ?? ''),
            'result_status' => (string)($review['result_status'] ?? ''),
            'result_summary' => (string)($review['result_summary'] ?? ''),
            'causality_claimed' => false,
            'reviewed_by' => (int)($review['reviewed_by'] ?? 0),
            'reviewed_at' => (string)($review['reviewed_at'] ?? ''),
            'previous_review_id' => ($review['previous_review_id'] ?? null) === null ? null : (int)$review['previous_review_id'],
            'previous_digest' => (string)($review['previous_digest'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeEvent(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'intent_id', 'task_id', 'sequence_no', 'actor_id'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['event_payload'] = $this->decodeJson($row['event_payload_json'] ?? null);
        unset($row['event_payload_json']);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeReview(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'intent_id', 'task_id', 'reviewed_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['effect_review_id'] = ($row['effect_review_id'] ?? null) === null ? null : (int)$row['effect_review_id'];
        $row['previous_review_id'] = ($row['previous_review_id'] ?? null) === null ? null : (int)$row['previous_review_id'];
        foreach (['before_value', 'after_value', 'delta_value'] as $field) {
            $row[$field] = ($row[$field] ?? null) === null ? null : (float)$row[$field];
        }
        $row['causality_claimed'] = false;
        $row['baseline_window'] = $this->decodeJson($row['baseline_window_json'] ?? null);
        $row['followup_window'] = $this->decodeJson($row['followup_window_json'] ?? null);
        $row['evidence_refs'] = array_values($this->decodeJson($row['evidence_refs_json'] ?? null));
        $row['non_attribution_reasons'] = array_values($this->decodeJson($row['non_attribution_reasons_json'] ?? null));
        unset(
            $row['baseline_window_json'],
            $row['followup_window_json'],
            $row['evidence_refs_json'],
            $row['non_attribution_reasons_json']
        );
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeEvidence(array $row): array
    {
        if (!is_array($row['before'] ?? null)) {
            $row['before'] = $this->decodeJson($row['before_json'] ?? null);
        }
        if (!is_array($row['after'] ?? null)) {
            $row['after'] = $this->decodeJson($row['after_json'] ?? null);
        }
        if (!is_array($row['platform_response'] ?? null)) {
            $row['platform_response'] = $this->decodeJson($row['platform_response_json'] ?? null);
        }
        return $row;
    }

    private function assertSchemaReady(): void
    {
        foreach ([self::EVENT_TABLE, self::REVIEW_TABLE] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException($table . ' table does not exist, run database migration first');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function requiredDateRange(string $start, string $end): void
    {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        if ($startDate === false || $endDate === false
            || $startDate->format('Y-m-d') !== $start
            || $endDate->format('Y-m-d') !== $end
            || $endDate < $startDate
        ) {
            throw new InvalidArgumentException('行动卡业务日期范围无效');
        }
    }

    private function requiredDateTime(mixed $value, string $label): DateTimeImmutable
    {
        $text = trim(str_replace('T', ' ', (string)$value));
        $text = strlen($text) === 16 ? $text . ':00' : $text;
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $text,
            new DateTimeZone('Asia/Shanghai')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $text
        ) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        return $date;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }
}
