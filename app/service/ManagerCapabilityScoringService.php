<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class ManagerCapabilityScoringService
{
    public const CASE_TABLE = 'manager_capability_cases';
    public const SCORE_TABLE = 'manager_capability_score_snapshots';
    public const FOLLOWUP_TABLE = 'manager_capability_case_followups';
    public const ADJUSTMENT_TABLE = 'manager_capability_case_adjustments';
    public const REVIEW_TABLE = 'manager_capability_score_reviews';
    public const FORMULA_VERSION = 'manager_capability_evidence_v1';
    public const SOURCE_REFERENCE_KEY = 'management-three-questions-share-20260822';
    public const SOURCE_FINGERPRINT = '2CF5141F480243EBEA75D0520FD299BC2EE4ACB0E8F752113D8B93DB489CEF66';
    public const MINIMUM_PROFILE_SAMPLES = 3;
    private const IDEMPOTENT_WRITE_MAX_ATTEMPTS = 3;
    private const IDEMPOTENT_WRITE_RETRY_DELAY_MICROSECONDS = 20000;
    private const CASE_SCAN_PAGE_SIZE = 250;
    private const CASE_SCAN_MAX_ROWS = 20000;

    /** @var array<string, string> */
    private const DIMENSION_LABELS = [
        'problem_discovery' => '问题发现',
        'cause_analysis' => '原因分析',
        'solution_management' => '管理解决',
        'coaching' => '带教能力',
        'execution_prevention' => '执行与预防',
        'closure' => '闭环能力',
    ];

    /** @var array<string, string> */
    private const EVIDENCE_TYPE_LABELS = [
        'onsite_observation' => '现场观察',
        'signed_checklist' => '签字清单/台账',
        'system_record' => '系统记录/报表',
        'guest_feedback' => '客诉或宾客反馈',
        'photo_record' => '照片/附件记录',
        'other' => '其他人工证据',
    ];

    /** @var array<string, array<int, string>> */
    private const DIMENSION_RUBRICS = [
        'problem_discovery' => ['90：对象、时间/岗位或数量证据充分', '75：问题具体但事实要素不完整', '50：描述笼统，需补何时何地何人何事'],
        'cause_analysis' => ['90：原因判断与流程/标准/资源对象同时成立', '75：有原因或管理对象线索', '50：只有现象或动作，未形成原因链'],
        'solution_management' => ['90：动作、责任对象和具体标准齐全', '75：已有动作但责任人或标准不完整', '50：原则性表述，缺少可执行边界'],
        'coaching' => ['90：有带教且验证员工独立完成', '75：按标准或流程完成带教', '50：只有带教动作，缺标准和掌握验证'],
        'execution_prevention' => ['90：已执行、预防机制、责任/时间边界齐全', '75：已执行且有预防安排', '50：缺持续执行或防复发机制'],
        'closure' => ['90：结果可观察且有核对依据', '75：已有结果但量化/核对依据不足', '50：结果不能证明关闭或复查确认复发'],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listManagers(int $tenantId, int $hotelId, int $currentUserId = 0): array
    {
        $this->assertPositiveScope($tenantId, $hotelId);

        $directUserIds = Db::name('users')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('status', 1)
            ->column('id');
        $now = $this->now();
        $grantedUserIds = Db::name('user_hotel_permissions')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['active', '1', 1])
            ->where('can_view', 1)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')->whereOr('expires_at', '>', $now);
            })
            ->column('user_id');
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', array_merge($directUserIds, $grantedUserIds)),
            static fn(int $id): bool => $id > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $rows = Db::name('users')->alias('u')
            ->leftJoin('roles r', 'r.id = u.role_id')
            ->where('u.tenant_id', $tenantId)
            ->where('u.status', 1)
            ->whereIn('u.id', $userIds)
            ->field('u.id,u.username,u.realname,u.role_id,r.name AS role_name,r.display_name AS role_display_name')
            ->order('u.role_id', 'asc')
            ->order('u.realname', 'asc')
            ->order('u.id', 'asc')
            ->select()
            ->toArray();

        return array_map(static function (array $row) use ($currentUserId): array {
            $realname = trim((string)($row['realname'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));
            $displayName = $realname !== '' ? $realname : ($username !== '' ? $username : '用户 ' . (int)$row['id']);

            return [
                'id' => (int)$row['id'],
                'display_name' => $displayName,
                'username' => $username,
                'role_name' => (string)($row['role_name'] ?? ''),
                'role_display_name' => (string)($row['role_display_name'] ?? ''),
                'is_current_user' => $currentUserId > 0 && (int)$row['id'] === $currentUserId,
            ];
        }, $rows);
    }

    public function hotelTenantId(int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择单个酒店');
        }

        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('status', 1)
            ->field('id,tenant_id')
            ->find();
        if (!is_array($hotel)) {
            throw new RuntimeException('酒店不存在或已停用');
        }

        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('酒店租户边界未就绪');
        }

        return $tenantId;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCase(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        array $input
    ): array {
        $this->assertSchemaReady();
        $this->assertPositiveScope($tenantId, $hotelId);
        if ($actorUserId <= 0) {
            throw new RuntimeException('未登录');
        }

        $normalized = $this->normalizeCaseInput($input);
        $manager = $this->managerForScope($tenantId, $hotelId, (int)$normalized['manager_user_id']);
        $inputDigest = $this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => (int)$normalized['manager_user_id'],
            'business_date' => $normalized['business_date'],
            'problem_facts' => $normalized['problem_facts'],
            'action_taken' => $normalized['action_taken'],
            'verification_status' => $normalized['verification_status'],
            'verification_text' => $normalized['verification_text'],
            'followup_due_date' => $normalized['followup_due_date'],
            'evidence_type' => $normalized['evidence_type'],
            'evidence_reference' => $normalized['evidence_reference'],
            'evidence_date' => $normalized['evidence_date'],
            'evidence_confidence' => $normalized['evidence_confidence'],
            'source_kind' => 'manual_management_three_questions',
        ]);

        $transactionResult = $this->runIdempotentWrite(function () use (
            $tenantId,
            $hotelId,
            $actorUserId,
            $normalized,
            $manager,
            $inputDigest
        ): array {
            $existing = Db::name(self::CASE_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('created_by', $actorUserId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['input_digest'], $inputDigest)) {
                    throw new InvalidArgumentException('幂等键已用于不同的店长评分案例');
                }

                return ['case_id' => (int)$existing['id'], 'replayed' => true];
            }

            $caseId = $this->insertCaseWithSnapshot(
                $tenantId,
                $hotelId,
                $actorUserId,
                $normalized,
                $manager,
                $inputDigest
            );

            return ['case_id' => $caseId, 'replayed' => false];
        }, function () use ($tenantId, $actorUserId, $normalized): ?array {
            return $this->findIdempotentWrite(
                self::CASE_TABLE,
                $tenantId,
                $actorUserId,
                (string)$normalized['idempotency_key']
            );
        }, static function (array $existing) use ($inputDigest): array {
            if (!hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)) {
                throw new InvalidArgumentException('幂等键已用于不同的店长评分案例');
            }

            return ['case_id' => (int)($existing['id'] ?? 0), 'replayed' => true];
        });

        $readback = $this->readCase(
            $tenantId,
            $hotelId,
            (int)$normalized['manager_user_id'],
            (int)$transactionResult['case_id']
        );
        if (!hash_equals($inputDigest, (string)($readback['input_digest'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/', (string)($readback['score_snapshot']['evidence_digest'] ?? ''))
        ) {
            throw new RuntimeException('店长评分案例保存后精确回读失败');
        }

        return [
            'case' => $readback,
            'profile' => $this->profile(
                $tenantId,
                $hotelId,
                (int)$normalized['manager_user_id']
            ),
            'replayed' => (bool)$transactionResult['replayed'],
            'readback_verified' => true,
        ];
    }

    /**
     * Append one immutable follow-up event. Original three-question answers are never overwritten.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createFollowup(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        int $caseId,
        array $input
    ): array {
        $this->assertSchemaReady();
        $this->assertPositiveScope($tenantId, $hotelId);
        if ($actorUserId <= 0) {
            throw new RuntimeException('未登录');
        }
        if ($caseId <= 0) {
            throw new InvalidArgumentException('店长评分案例ID无效');
        }

        $baseCase = Db::name(self::CASE_TABLE)
            ->where('id', $caseId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($baseCase)) {
            throw new RuntimeException('店长评分案例不存在');
        }
        $managerUserId = (int)($baseCase['manager_user_id'] ?? 0);
        $manager = $this->managerForScope($tenantId, $hotelId, $managerUserId);
        $currentCase = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
        if (($currentCase['is_voided'] ?? false) === true) {
            throw new InvalidArgumentException('已作废案例不能追加复查');
        }
        $sourceCaseDigest = $this->mutableCaseDigest($currentCase);
        $normalized = $this->normalizeFollowupInput(
            $input,
            (string)($currentCase['business_date'] ?? '')
        );
        $inputDigest = $this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId,
            'case_id' => $caseId,
            'followup_date' => $normalized['followup_date'],
            'followup_outcome' => $normalized['followup_outcome'],
            'verification_text' => $normalized['verification_text'],
            'sample_count' => $normalized['sample_count'],
            'evidence_type' => $normalized['evidence_type'],
            'evidence_reference' => $normalized['evidence_reference'],
            'evidence_date' => $normalized['evidence_date'],
            'evidence_confidence' => $normalized['evidence_confidence'],
            'next_followup_date' => $normalized['next_followup_date'],
            'recurrence_problem_facts' => $normalized['recurrence_problem_facts'],
            'recurrence_action_taken' => $normalized['recurrence_action_taken'],
            'recurrence_verification_plan' => $normalized['recurrence_verification_plan'],
            'source_kind' => 'manual_manager_capability_followup',
        ]);

        $transactionResult = $this->runIdempotentWrite(function () use (
            $tenantId,
            $hotelId,
            $actorUserId,
            $caseId,
            $managerUserId,
            $manager,
            $sourceCaseDigest,
            $normalized,
            $inputDigest
        ): array {
            $existing = Db::name(self::FOLLOWUP_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('created_by', $actorUserId)
                ->where('idempotency_key', $normalized['idempotency_key'])
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['input_digest'], $inputDigest)) {
                    throw new InvalidArgumentException('幂等键已用于不同的店长能力复查');
                }

                return [
                    'followup_id' => (int)$existing['id'],
                    'linked_recurrence_case_id' => (int)($existing['linked_recurrence_case_id'] ?? 0),
                    'replayed' => true,
                ];
            }

            $lockedCase = Db::name(self::CASE_TABLE)
                ->where('id', $caseId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('manager_user_id', $managerUserId)
                ->lock(true)
                ->find();
            if (!is_array($lockedCase)) {
                throw new RuntimeException('店长评分案例不存在');
            }
            $lockedCurrentCase = $this->readCase(
                $tenantId,
                $hotelId,
                $managerUserId,
                $caseId
            );
            if (($lockedCurrentCase['is_voided'] ?? false) === true) {
                throw new InvalidArgumentException('已作废案例不能追加复查');
            }
            if (!hash_equals($sourceCaseDigest, $this->mutableCaseDigest($lockedCurrentCase))) {
                throw new InvalidArgumentException('案例状态已变化，请刷新后重新复查');
            }

            $latestFollowup = Db::name(self::FOLLOWUP_TABLE)
                ->where('case_id', $caseId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->order('followup_date', 'desc')
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            if (is_array($latestFollowup)
                && (string)$normalized['followup_date'] < (string)$latestFollowup['followup_date']
            ) {
                throw new InvalidArgumentException('复查日期不能早于最近一次复查日期');
            }

            $score = $this->scoreCase([
                'problem_facts' => (string)$lockedCurrentCase['problem_facts'],
                'action_taken' => (string)$lockedCurrentCase['action_taken'],
                'verification_status' => $normalized['followup_outcome'] === 'still_open'
                    ? 'planned_verification'
                    : 'observed_result',
                'verification_text' => $normalized['verification_text'],
                'followup_outcome' => $normalized['followup_outcome'],
                'followup_sample_count' => $normalized['sample_count'],
                'evidence_reference' => $normalized['evidence_reference'],
            ]);
            $evidenceDigest = $this->digest([
                'case_id' => $caseId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => $managerUserId,
                'followup_date' => $normalized['followup_date'],
                'followup_outcome' => $normalized['followup_outcome'],
                'formula_version' => self::FORMULA_VERSION,
                'source_reference_key' => self::SOURCE_REFERENCE_KEY,
                'source_fingerprint' => self::SOURCE_FINGERPRINT,
                'dimensions' => $score['dimensions'],
                'case_score' => $score['case_score'],
                'score_status' => $score['status'],
                'input_digest' => $inputDigest,
            ]);

            $followupId = (int)Db::name(self::FOLLOWUP_TABLE)->insertGetId([
                'case_id' => $caseId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => $managerUserId,
                'followup_date' => $normalized['followup_date'],
                'followup_outcome' => $normalized['followup_outcome'],
                'verification_text' => $normalized['verification_text'],
                'sample_count' => $normalized['sample_count'],
                'evidence_type' => $normalized['evidence_type'],
                'evidence_reference' => $normalized['evidence_reference'],
                'evidence_date' => $normalized['evidence_date'],
                'evidence_confidence' => $normalized['evidence_confidence'],
                'next_followup_date' => $normalized['next_followup_date'],
                'recurrence_problem_facts' => $normalized['recurrence_problem_facts'],
                'recurrence_action_taken' => $normalized['recurrence_action_taken'],
                'recurrence_verification_plan' => $normalized['recurrence_verification_plan'],
                'linked_recurrence_case_id' => null,
                'scoring_version' => self::FORMULA_VERSION,
                'source_reference_key' => self::SOURCE_REFERENCE_KEY,
                'source_fingerprint' => self::SOURCE_FINGERPRINT,
                'dimensions_json' => $this->encodeJson($score['dimensions']),
                'case_score' => $score['case_score'],
                'scored_dimension_count' => (int)$score['scored_dimension_count'],
                'score_status' => $score['status'],
                'source_kind' => 'manual_manager_capability_followup',
                'source_quality_status' => 'manual_declared',
                'idempotency_key' => $normalized['idempotency_key'],
                'input_digest' => $inputDigest,
                'evidence_digest' => $evidenceDigest,
                'created_by' => $actorUserId,
                'created_at' => $this->nowPrecise(),
            ]);
            if ($followupId <= 0) {
                throw new RuntimeException('店长能力复查保存失败');
            }

            $linkedRecurrenceCaseId = 0;
            if ($normalized['followup_outcome'] === 'recurred') {
                $recurrence = $this->normalizeCaseInput([
                    'manager_user_id' => $managerUserId,
                    'business_date' => $normalized['followup_date'],
                    'problem_facts' => $normalized['recurrence_problem_facts'],
                    'action_taken' => $normalized['recurrence_action_taken'],
                    'verification_status' => 'planned_verification',
                    'verification_text' => $normalized['recurrence_verification_plan'],
                    'followup_due_date' => $normalized['next_followup_date'],
                    'evidence_type' => $normalized['evidence_type'],
                    'evidence_reference' => $normalized['evidence_reference'],
                    'evidence_date' => $normalized['evidence_date'],
                    'idempotency_key' => 'manager-recurrence-' . $followupId,
                ]);
                $recurrenceInputDigest = $this->digest([
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'manager_user_id' => $managerUserId,
                    'business_date' => $recurrence['business_date'],
                    'problem_facts' => $recurrence['problem_facts'],
                    'action_taken' => $recurrence['action_taken'],
                    'verification_status' => $recurrence['verification_status'],
                    'verification_text' => $recurrence['verification_text'],
                    'followup_due_date' => $recurrence['followup_due_date'],
                    'evidence_type' => $recurrence['evidence_type'],
                    'evidence_reference' => $recurrence['evidence_reference'],
                    'evidence_date' => $recurrence['evidence_date'],
                    'evidence_confidence' => $recurrence['evidence_confidence'],
                    'source_kind' => 'manual_management_three_questions_recurrence',
                    'parent_case_id' => $caseId,
                    'origin_followup_id' => $followupId,
                ]);
                $linkedRecurrenceCaseId = $this->insertCaseWithSnapshot(
                    $tenantId,
                    $hotelId,
                    $actorUserId,
                    $recurrence,
                    $manager,
                    $recurrenceInputDigest,
                    $caseId,
                    $followupId,
                    'manual_management_three_questions_recurrence'
                );
                Db::name(self::FOLLOWUP_TABLE)
                    ->where('id', $followupId)
                    ->where('tenant_id', $tenantId)
                    ->update(['linked_recurrence_case_id' => $linkedRecurrenceCaseId]);
            }

            $caseStatus = match ($normalized['followup_outcome']) {
                'resolved' => 'closed',
                'recurred' => 'recurred',
                default => 'pending_verification',
            };
            Db::name(self::CASE_TABLE)
                ->where('id', $caseId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('manager_user_id', $managerUserId)
                ->update(['case_status' => $caseStatus]);

            return [
                'followup_id' => $followupId,
                'linked_recurrence_case_id' => $linkedRecurrenceCaseId,
                'replayed' => false,
            ];
        }, function () use ($tenantId, $actorUserId, $normalized): ?array {
            return $this->findIdempotentWrite(
                self::FOLLOWUP_TABLE,
                $tenantId,
                $actorUserId,
                (string)$normalized['idempotency_key']
            );
        }, static function (array $existing) use ($inputDigest): array {
            if (!hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)) {
                throw new InvalidArgumentException('幂等键已用于不同的店长能力复查');
            }

            return [
                'followup_id' => (int)($existing['id'] ?? 0),
                'linked_recurrence_case_id' => (int)($existing['linked_recurrence_case_id'] ?? 0),
                'replayed' => true,
            ];
        });

        $readback = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
        $followup = null;
        foreach ((array)($readback['followups'] ?? []) as $candidate) {
            if (is_array($candidate)
                && (int)($candidate['id'] ?? 0) === (int)$transactionResult['followup_id']
            ) {
                $followup = $candidate;
                break;
            }
        }
        if (!is_array($followup)
            || !hash_equals($inputDigest, (string)($followup['input_digest'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/', (string)($followup['score_snapshot']['evidence_digest'] ?? ''))
        ) {
            throw new RuntimeException('店长能力复查保存后精确回读失败');
        }

        $linkedRecurrenceCase = null;
        $linkedRecurrenceCaseId = (int)($transactionResult['linked_recurrence_case_id'] ?? 0);
        if ($linkedRecurrenceCaseId > 0) {
            $linkedRecurrenceCase = $this->readCase(
                $tenantId,
                $hotelId,
                $managerUserId,
                $linkedRecurrenceCaseId
            );
            if ((int)($linkedRecurrenceCase['parent_case_id'] ?? 0) !== $caseId
                || (int)($linkedRecurrenceCase['origin_followup_id'] ?? 0) !== (int)$followup['id']
            ) {
                throw new RuntimeException('复发关联案例保存后精确回读失败');
            }
        }

        return [
            'case' => $readback,
            'followup' => $followup,
            'linked_recurrence_case' => $linkedRecurrenceCase,
            'profile' => $this->profile($tenantId, $hotelId, $managerUserId),
            'replayed' => (bool)$transactionResult['replayed'],
            'readback_verified' => true,
        ];
    }

    /**
     * Append a correction, void, or restore event without overwriting the original case.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createAdjustment(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        int $caseId,
        array $input
    ): array {
        $this->assertSchemaReady();
        $this->assertPositiveScope($tenantId, $hotelId);
        if ($actorUserId <= 0) {
            throw new RuntimeException('未登录');
        }
        if ($caseId <= 0) {
            throw new InvalidArgumentException('店长评分案例ID无效');
        }

        $baseCase = Db::name(self::CASE_TABLE)
            ->where('id', $caseId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($baseCase)) {
            throw new RuntimeException('店长评分案例不存在');
        }
        $managerUserId = (int)$baseCase['manager_user_id'];
        $manager = $this->managerForScope($tenantId, $hotelId, $managerUserId);
        $adjustmentType = trim((string)($input['adjustment_type'] ?? ''));
        if (!in_array($adjustmentType, ['corrected', 'voided', 'restored'], true)) {
            throw new InvalidArgumentException('请选择纠错、作废或恢复计分');
        }
        $reason = $this->requiredText($input['reason'] ?? '', '修正原因', 4, 500);
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('案例修正幂等键无效');
        }

        $corrected = null;
        if ($adjustmentType === 'corrected') {
            $corrected = $this->normalizeCaseInput([
                ...$input,
                'manager_user_id' => $managerUserId,
                'idempotency_key' => $idempotencyKey,
            ]);
        }
        $inputDigest = $this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId,
            'case_id' => $caseId,
            'adjustment_type' => $adjustmentType,
            'reason' => $reason,
            'corrected_case' => $corrected,
            'source_kind' => 'manual_manager_capability_adjustment',
        ]);

        $existing = Db::name(self::ADJUSTMENT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('created_by', $actorUserId)
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['input_digest'], $inputDigest)) {
                throw new InvalidArgumentException('幂等键已用于不同的店长能力修正');
            }
            $transactionResult = ['adjustment_id' => (int)$existing['id'], 'replayed' => true];
        } else {
            $currentCase = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
            // Capture the complete mutable projection before calculating the
            // append-only event. The case row lock below serializes writers;
            // this digest then fails closed if a follow-up, adjustment, or
            // score review landed while this request was preparing its event.
            $sourceCaseDigest = $this->mutableCaseDigest($currentCase);
            $currentlyVoided = (bool)($currentCase['is_voided'] ?? false);
            if ($adjustmentType === 'voided' && $currentlyVoided) {
                throw new InvalidArgumentException('该案例已经作废');
            }
            if ($adjustmentType === 'restored' && !$currentlyVoided) {
                throw new InvalidArgumentException('只有已作废案例可以恢复计分');
            }
            if ($adjustmentType === 'corrected' && $currentlyVoided) {
                throw new InvalidArgumentException('已作废案例需先恢复后再纠错');
            }

            if (is_array($corrected)) {
                $score = $this->scoreCase($corrected);
                $projection = [
                    'business_date' => $corrected['business_date'],
                    'problem_facts' => $corrected['problem_facts'],
                    'action_taken' => $corrected['action_taken'],
                    'verification_status' => $corrected['verification_status'],
                    'verification_text' => $corrected['verification_text'],
                    'followup_due_date' => $corrected['followup_due_date'],
                    'case_status' => $corrected['verification_status'] === 'observed_result' ? 'closed' : 'pending_verification',
                    'evidence_type' => $corrected['evidence_type'],
                    'evidence_reference' => $corrected['evidence_reference'],
                    'evidence_date' => $corrected['evidence_date'],
                    'evidence_confidence' => $corrected['evidence_confidence'],
                ];
                $isVoided = false;
            } else {
                $sourceAdjustment = is_array($currentCase['latest_adjustment'] ?? null)
                    ? $currentCase['latest_adjustment']
                    : null;
                $sourceScore = $currentlyVoided && is_array($sourceAdjustment['score_snapshot'] ?? null)
                    ? $sourceAdjustment['score_snapshot']
                    : (array)($currentCase['score_snapshot'] ?? []);
                $score = [
                    'dimensions' => is_array($sourceScore['dimensions'] ?? null) ? $sourceScore['dimensions'] : [],
                    'case_score' => $sourceScore['case_score'] ?? null,
                    'scored_dimension_count' => (int)($sourceScore['scored_dimension_count'] ?? 0),
                    'status' => (string)($sourceScore['score_status'] ?? 'data_insufficient'),
                ];
                $sourceProjection = is_array($sourceAdjustment['effective_case'] ?? null)
                    ? $sourceAdjustment['effective_case']
                    : [];
                $evidence = (array)($currentCase['evidence'] ?? []);
                $projection = [
                    'business_date' => (string)$currentCase['business_date'],
                    'problem_facts' => (string)$currentCase['problem_facts'],
                    'action_taken' => (string)$currentCase['action_taken'],
                    'verification_status' => (string)$currentCase['verification_status'],
                    'verification_text' => (string)$currentCase['verification_text'],
                    'followup_due_date' => $currentCase['current_followup_due_date'] ?? $currentCase['followup_due_date'] ?? null,
                    'case_status' => (string)($sourceProjection['case_status'] ?? ($currentCase['case_status'] === 'voided' ? 'closed' : $currentCase['case_status'])),
                    'evidence_type' => $evidence['type'] ?? null,
                    'evidence_reference' => $evidence['reference'] ?? null,
                    'evidence_date' => $evidence['date'] ?? null,
                    'evidence_confidence' => $evidence['confidence'] ?? 'unverified',
                ];
                $isVoided = $adjustmentType === 'voided';
            }

            $evidenceDigest = $this->digest([
                'case_id' => $caseId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => $managerUserId,
                'adjustment_type' => $adjustmentType,
                'effective_case' => $projection,
                'is_voided' => $isVoided,
                'formula_version' => self::FORMULA_VERSION,
                'dimensions' => $score['dimensions'],
                'case_score' => $score['case_score'],
                'score_status' => $score['status'],
                'input_digest' => $inputDigest,
            ]);

            $transactionResult = $this->runIdempotentWrite(function () use (
                $tenantId,
                $hotelId,
                $actorUserId,
                $caseId,
                $managerUserId,
                $adjustmentType,
                $reason,
                $idempotencyKey,
                $inputDigest,
                $sourceCaseDigest,
                $evidenceDigest,
                $projection,
                $isVoided,
                $score
            ): array {
                $existingLocked = Db::name(self::ADJUSTMENT_TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('created_by', $actorUserId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if (is_array($existingLocked)) {
                    if (!hash_equals((string)$existingLocked['input_digest'], $inputDigest)) {
                        throw new InvalidArgumentException('幂等键已用于不同的店长能力修正');
                    }
                    return ['adjustment_id' => (int)$existingLocked['id'], 'replayed' => true];
                }
                $lockedCase = Db::name(self::CASE_TABLE)
                    ->where('id', $caseId)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('manager_user_id', $managerUserId)
                    ->lock(true)
                    ->find();
                if (!is_array($lockedCase)) {
                    throw new RuntimeException('店长评分案例不存在');
                }
                $lockedCurrentCase = $this->readCase(
                    $tenantId,
                    $hotelId,
                    $managerUserId,
                    $caseId
                );
                if (!hash_equals($sourceCaseDigest, $this->mutableCaseDigest($lockedCurrentCase))) {
                    throw new InvalidArgumentException('案例状态已变化，请刷新后重新修正');
                }

                $adjustmentId = (int)Db::name(self::ADJUSTMENT_TABLE)->insertGetId([
                    'case_id' => $caseId,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'manager_user_id' => $managerUserId,
                    'adjustment_type' => $adjustmentType,
                    'reason' => $reason,
                    'effective_payload_json' => $this->encodeJson($projection),
                    'is_voided' => $isVoided ? 1 : 0,
                    'scoring_version' => self::FORMULA_VERSION,
                    'source_reference_key' => self::SOURCE_REFERENCE_KEY,
                    'source_fingerprint' => self::SOURCE_FINGERPRINT,
                    'dimensions_json' => $this->encodeJson($score['dimensions']),
                    'case_score' => $score['case_score'],
                    'scored_dimension_count' => (int)$score['scored_dimension_count'],
                    'score_status' => (string)$score['status'],
                    'source_kind' => 'manual_manager_capability_adjustment',
                    'source_quality_status' => 'manual_declared',
                    'idempotency_key' => $idempotencyKey,
                    'input_digest' => $inputDigest,
                    'evidence_digest' => $evidenceDigest,
                    'created_by' => $actorUserId,
                    'created_at' => $this->nowPrecise(),
                ]);
                if ($adjustmentId <= 0) {
                    throw new RuntimeException('店长能力修正保存失败');
                }
                return ['adjustment_id' => $adjustmentId, 'replayed' => false];
            }, function () use ($tenantId, $actorUserId, $idempotencyKey): ?array {
                return $this->findIdempotentWrite(
                    self::ADJUSTMENT_TABLE,
                    $tenantId,
                    $actorUserId,
                    $idempotencyKey
                );
            }, static function (array $existing) use ($inputDigest): array {
                if (!hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)) {
                    throw new InvalidArgumentException('幂等键已用于不同的店长能力修正');
                }

                return ['adjustment_id' => (int)($existing['id'] ?? 0), 'replayed' => true];
            });
        }

        $readback = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
        $adjustment = null;
        foreach ((array)($readback['adjustments'] ?? []) as $candidate) {
            if (is_array($candidate) && (int)($candidate['id'] ?? 0) === (int)$transactionResult['adjustment_id']) {
                $adjustment = $candidate;
                break;
            }
        }
        if (!is_array($adjustment)
            || !hash_equals($inputDigest, (string)($adjustment['input_digest'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/', (string)($adjustment['score_snapshot']['evidence_digest'] ?? ''))
        ) {
            throw new RuntimeException('店长能力修正保存后精确回读失败');
        }

        return [
            'case' => $readback,
            'adjustment' => $adjustment,
            'profile' => $this->profile($tenantId, $hotelId, $managerUserId),
            'replayed' => (bool)$transactionResult['replayed'],
            'readback_verified' => true,
        ];
    }

    /**
     * Append a human score review. The source score digest is mandatory so stale forms fail closed.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createScoreReview(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        int $caseId,
        array $input
    ): array {
        $this->assertSchemaReady();
        $this->assertPositiveScope($tenantId, $hotelId);
        if ($actorUserId <= 0) {
            throw new RuntimeException('未登录');
        }
        if ($caseId <= 0) {
            throw new InvalidArgumentException('店长评分案例ID无效');
        }

        $baseCase = Db::name(self::CASE_TABLE)
            ->where('id', $caseId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($baseCase)) {
            throw new RuntimeException('店长评分案例不存在');
        }
        $managerUserId = (int)$baseCase['manager_user_id'];
        $this->managerForScope($tenantId, $hotelId, $managerUserId);
        $reviewOutcome = trim((string)($input['review_outcome'] ?? ''));
        if (!in_array($reviewOutcome, ['confirmed', 'adjusted'], true)) {
            throw new InvalidArgumentException('请选择确认原评分或人工调整');
        }
        $reason = $this->requiredText($input['reason'] ?? '', '复核原因', 4, 500);
        $sourceScoreDigest = strtolower(trim((string)($input['source_score_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceScoreDigest) !== 1) {
            throw new InvalidArgumentException('复核来源评分摘要无效，请刷新后重试');
        }
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('人工复核幂等键无效');
        }

        $rawOverrides = is_array($input['dimension_overrides'] ?? null)
            ? $input['dimension_overrides']
            : [];
        $overrides = [];
        foreach ($rawOverrides as $key => $value) {
            $dimensionKey = (string)$key;
            if (!array_key_exists($dimensionKey, self::DIMENSION_LABELS)) {
                throw new InvalidArgumentException('人工复核包含未知评分维度');
            }
            if ($value === null || $value === '') {
                $overrides[$dimensionKey] = null;
                continue;
            }
            $scoreValue = filter_var($value, FILTER_VALIDATE_INT);
            if ($scoreValue === false || !in_array((int)$scoreValue, [50, 75, 90], true)) {
                throw new InvalidArgumentException('人工复核分值只能是90、75、50或未观察');
            }
            $overrides[$dimensionKey] = (int)$scoreValue;
        }
        ksort($overrides);
        if ($reviewOutcome === 'confirmed' && $overrides !== []) {
            throw new InvalidArgumentException('确认原评分时不能提交人工调整分');
        }
        if ($reviewOutcome === 'adjusted' && $overrides === []) {
            throw new InvalidArgumentException('人工调整至少需要修改一个维度');
        }

        $inputDigest = $this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId,
            'case_id' => $caseId,
            'review_outcome' => $reviewOutcome,
            'reason' => $reason,
            'dimension_overrides' => $overrides,
            'source_score_digest' => $sourceScoreDigest,
            'source_kind' => 'manual_manager_capability_score_review',
        ]);

        $existing = Db::name(self::REVIEW_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('created_by', $actorUserId)
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['input_digest'], $inputDigest)) {
                throw new InvalidArgumentException('幂等键已用于不同的店长评分复核');
            }
            $transactionResult = ['review_id' => (int)$existing['id'], 'replayed' => true];
        } else {
            $currentCase = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
            $sourceCaseDigest = $this->mutableCaseDigest($currentCase);
            if (($currentCase['is_voided'] ?? false) === true) {
                throw new InvalidArgumentException('已作废案例不能进行评分复核');
            }
            $currentScore = (array)($currentCase['score_snapshot'] ?? []);
            if (!hash_equals($sourceScoreDigest, strtolower((string)($currentScore['evidence_digest'] ?? '')))) {
                throw new InvalidArgumentException('评分已变化，请刷新后重新复核');
            }

            $reviewedDimensions = [];
            foreach ((array)($currentScore['dimensions'] ?? []) as $dimension) {
                if (!is_array($dimension)) {
                    continue;
                }
                $key = (string)($dimension['key'] ?? '');
                if (!array_key_exists($key, self::DIMENSION_LABELS)) {
                    continue;
                }
                if (array_key_exists($key, $overrides)) {
                    $scoreValue = $overrides[$key];
                    $dimension['score'] = $scoreValue;
                    $dimension['status'] = $scoreValue === null ? 'not_observed' : 'scored';
                    $dimension['level'] = $scoreValue === null ? 'unknown' : ($scoreValue >= 90 ? 'positive' : ($scoreValue >= 75 ? 'normal' : 'improve'));
                    $dimension['level_label'] = $scoreValue === null ? '未观察' : $this->scoreLevelLabel($scoreValue);
                    $dimension['reasons'] = [
                        ...array_values(array_filter((array)($dimension['reasons'] ?? []), 'is_string')),
                        '人工复核调整：' . $reason,
                    ];
                    $dimension['manual_reviewed'] = true;
                }
                $dimension['rubric'] = self::DIMENSION_RUBRICS[$key] ?? [];
                $reviewedDimensions[] = $dimension;
            }
            if (count($reviewedDimensions) !== count(self::DIMENSION_LABELS)) {
                throw new RuntimeException('当前评分维度不完整，无法人工复核');
            }
            $numericScores = array_values(array_filter(array_map(
                static fn(array $dimension): mixed => $dimension['score'] ?? null,
                $reviewedDimensions
            ), 'is_numeric'));
            $scoredCount = count($numericScores);
            $caseStatus = (string)($currentCase['case_status'] ?? 'data_insufficient');
            $reviewedCaseScore = $scoredCount === count(self::DIMENSION_LABELS)
                && !in_array($caseStatus, ['pending_verification', 'voided'], true)
                ? round(array_sum(array_map('floatval', $numericScores)) / $scoredCount, 2)
                : null;
            $scoreStatus = $caseStatus === 'pending_verification'
                ? 'pending_verification'
                : ($reviewedCaseScore !== null ? 'scored' : 'data_insufficient');
            $evidenceDigest = $this->digest([
                'case_id' => $caseId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => $managerUserId,
                'review_outcome' => $reviewOutcome,
                'dimension_overrides' => $overrides,
                'dimensions' => $reviewedDimensions,
                'case_score' => $reviewedCaseScore,
                'score_status' => $scoreStatus,
                'source_score_digest' => $sourceScoreDigest,
                'input_digest' => $inputDigest,
            ]);

            $transactionResult = $this->runIdempotentWrite(function () use (
                $tenantId,
                $hotelId,
                $actorUserId,
                $caseId,
                $managerUserId,
                $reviewOutcome,
                $reason,
                $overrides,
                $reviewedDimensions,
                $reviewedCaseScore,
                $scoredCount,
                $scoreStatus,
                $sourceScoreDigest,
                $idempotencyKey,
                $inputDigest,
                $sourceCaseDigest,
                $evidenceDigest
            ): array {
                $existingLocked = Db::name(self::REVIEW_TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('created_by', $actorUserId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if (is_array($existingLocked)) {
                    if (!hash_equals((string)$existingLocked['input_digest'], $inputDigest)) {
                        throw new InvalidArgumentException('幂等键已用于不同的店长评分复核');
                    }
                    return ['review_id' => (int)$existingLocked['id'], 'replayed' => true];
                }
                $lockedCase = Db::name(self::CASE_TABLE)
                    ->where('id', $caseId)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('manager_user_id', $managerUserId)
                    ->lock(true)
                    ->find();
                if (!is_array($lockedCase)) {
                    throw new RuntimeException('店长评分案例不存在');
                }
                $lockedCurrentCase = $this->readCase(
                    $tenantId,
                    $hotelId,
                    $managerUserId,
                    $caseId
                );
                if (($lockedCurrentCase['is_voided'] ?? false) === true) {
                    throw new InvalidArgumentException('已作废案例不能进行评分复核');
                }
                $lockedScoreDigest = strtolower((string)(
                    $lockedCurrentCase['score_snapshot']['evidence_digest'] ?? ''
                ));
                if (!hash_equals($sourceScoreDigest, $lockedScoreDigest)
                    || !hash_equals($sourceCaseDigest, $this->mutableCaseDigest($lockedCurrentCase))
                ) {
                    throw new InvalidArgumentException('评分已变化，请刷新后重新复核');
                }
                $reviewId = (int)Db::name(self::REVIEW_TABLE)->insertGetId([
                    'case_id' => $caseId,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'manager_user_id' => $managerUserId,
                    'review_outcome' => $reviewOutcome,
                    'reason' => $reason,
                    'dimension_overrides_json' => $this->encodeJson((object)$overrides),
                    'reviewed_dimensions_json' => $this->encodeJson($reviewedDimensions),
                    'reviewed_case_score' => $reviewedCaseScore,
                    'scored_dimension_count' => $scoredCount,
                    'score_status' => $scoreStatus,
                    'source_score_digest' => $sourceScoreDigest,
                    'source_kind' => 'manual_manager_capability_score_review',
                    'source_quality_status' => 'manual_declared',
                    'idempotency_key' => $idempotencyKey,
                    'input_digest' => $inputDigest,
                    'evidence_digest' => $evidenceDigest,
                    'created_by' => $actorUserId,
                    'created_at' => $this->nowPrecise(),
                ]);
                if ($reviewId <= 0) {
                    throw new RuntimeException('店长评分人工复核保存失败');
                }
                return ['review_id' => $reviewId, 'replayed' => false];
            }, function () use ($tenantId, $actorUserId, $idempotencyKey): ?array {
                return $this->findIdempotentWrite(
                    self::REVIEW_TABLE,
                    $tenantId,
                    $actorUserId,
                    $idempotencyKey
                );
            }, static function (array $existing) use ($inputDigest): array {
                if (!hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)) {
                    throw new InvalidArgumentException('幂等键已用于不同的店长评分复核');
                }

                return ['review_id' => (int)($existing['id'] ?? 0), 'replayed' => true];
            });
        }

        $readback = $this->readCase($tenantId, $hotelId, $managerUserId, $caseId);
        $review = null;
        foreach ((array)($readback['score_reviews'] ?? []) as $candidate) {
            if (is_array($candidate) && (int)($candidate['id'] ?? 0) === (int)$transactionResult['review_id']) {
                $review = $candidate;
                break;
            }
        }
        if (!is_array($review)
            || !hash_equals($inputDigest, (string)($review['input_digest'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/', (string)($review['score_snapshot']['evidence_digest'] ?? ''))
        ) {
            throw new RuntimeException('店长评分人工复核保存后精确回读失败');
        }

        return [
            'case' => $readback,
            'score_review' => $review,
            'profile' => $this->profile($tenantId, $hotelId, $managerUserId),
            'replayed' => (bool)$transactionResult['replayed'],
            'readback_verified' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readCase(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        int $caseId
    ): array {
        $this->assertSchemaReady();
        $this->managerForScope($tenantId, $hotelId, $managerUserId);
        if ($caseId <= 0) {
            throw new InvalidArgumentException('店长评分案例ID无效');
        }

        $row = Db::name(self::CASE_TABLE)->alias('c')
            ->leftJoin(self::SCORE_TABLE . ' s', 's.case_id = c.id AND s.formula_version = \'' . self::FORMULA_VERSION . '\'')
            ->where('c.id', $caseId)
            ->where('c.tenant_id', $tenantId)
            ->where('c.hotel_id', $hotelId)
            ->where('c.manager_user_id', $managerUserId)
            ->field('c.*,s.formula_version,s.source_reference_key,s.source_fingerprint,s.dimensions_json,s.case_score,s.scored_dimension_count,s.score_status,s.evidence_digest,s.created_at AS scored_at')
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('店长评分案例不存在');
        }

        $followups = $this->followupsForCases(
            $tenantId,
            $hotelId,
            $managerUserId,
            [$caseId]
        );
        $adjustments = $this->adjustmentsForCases($tenantId, $hotelId, $managerUserId, [$caseId]);
        $reviews = $this->reviewsForCases($tenantId, $hotelId, $managerUserId, [$caseId]);

        return $this->publicCase(
            $row,
            $followups[$caseId] ?? [],
            $adjustments[$caseId] ?? [],
            $reviews[$caseId] ?? []
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $includePrivateDetails = true
    ): array {
        $this->assertSchemaReady();
        $manager = $this->managerForScope($tenantId, $hotelId, $managerUserId);
        [$from, $to] = $this->normalizeProfileWindow($dateFrom, $dateTo);

        $projectionBoundaries = $this->scopedProjectionBoundaries($tenantId, $hotelId, $managerUserId);
        $boundaryCaseId = $projectionBoundaries['case_id'];
        $scan = $this->scanProjectedCasePages(
            $boundaryCaseId,
            fn(int $afterCaseId, int $limit, int $boundary): array => $this->loadScopedCasePage(
                $tenantId,
                $hotelId,
                $managerUserId,
                $afterCaseId,
                $boundary,
                $limit
            ),
            fn(array $pageRows): array => $this->projectCaseRows(
                $tenantId,
                $hotelId,
                $pageRows,
                $projectionBoundaries
            )
        );
        $scan['metadata']['projection_boundaries'] = $projectionBoundaries;
        $publicCases = $this->casesInProfileWindow($scan['cases'], $from, $to);
        $activeCases = array_values(array_filter(
            $publicCases,
            static fn(array $case): bool => ($case['is_voided'] ?? false) !== true
        ));
        $snapshots = array_map(function (array $case): array {
            return [
                'case_id' => (int)$case['id'],
                'dimensions' => is_array($case['score_snapshot']['dimensions'] ?? null)
                    ? $case['score_snapshot']['dimensions']
                    : [],
            ];
        }, $activeCases);
        $aggregate = $this->aggregateDimensionScores($snapshots);
        $trend = $this->buildTrend($activeCases);
        $coachingSuggestions = $this->buildCoachingSuggestions($aggregate);
        $confidenceSummary = $this->buildEvidenceConfidenceSummary($activeCases);
        $pilotReadiness = $this->buildPilotReadiness($activeCases);
        $dailySubmission = $this->summarizeDailySubmission($publicCases, $to);
        $scanComplete = ($scan['metadata']['complete'] ?? false) === true;
        $profileDataGaps = array_values((array)$aggregate['data_gaps']);
        if (!$scanComplete) {
            $profileDataGaps[] = 'case_scan_incomplete';
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId,
            'manager' => $manager,
            'window' => [
                'date_from' => $from,
                'date_to' => $to,
                'timezone' => 'Asia/Shanghai',
            ],
            'data_status' => !$scanComplete ? 'partial' : ($publicCases === [] ? 'empty' : 'ready'),
            'scan' => $scan['metadata'],
            'profile_status' => $scanComplete ? $aggregate['status'] : 'data_incomplete',
            'profile_label' => $scanComplete ? $aggregate['label'] : '扫描不完整',
            'overall_score' => $scanComplete ? $aggregate['overall_score'] : null,
            'dimensions' => $aggregate['dimensions'],
            'sample_case_count' => count($activeCases),
            'voided_case_count' => count($publicCases) - count($activeCases),
            'minimum_samples_per_dimension' => self::MINIMUM_PROFILE_SAMPLES,
            'data_gaps' => $profileDataGaps,
            'trend' => $trend,
            'coaching_suggestions' => $coachingSuggestions,
            'evidence_confidence_summary' => $confidenceSummary,
            'pilot_readiness' => $pilotReadiness,
            'daily_submission' => $dailySubmission,
            'privacy_scope' => $includePrivateDetails ? 'evidence_detail' : 'aggregate_only',
            'recent_cases' => $includePrivateDetails ? array_slice($publicCases, 0, 10) : [],
            'scoring_contract' => [
                'version' => self::FORMULA_VERSION,
                'score_levels' => [
                    ['score' => 90, 'label' => '证据充分'],
                    ['score' => 75, 'label' => '基本成立'],
                    ['score' => 50, 'label' => '需补强'],
                ],
                'unknown_policy' => '无案例证据时留空，不按0分计算',
                'aggregation_policy' => '每个维度至少3个近90天案例后生成稳定档案分，六维等权',
                'followup_policy' => '原始三问不覆盖；最近一次追加复查作为当前有效评分快照，复发另建关联案例',
                'adjustment_policy' => '纠错、作废和恢复均追加事件；原始案例和评分永不覆盖',
                'manual_review_policy' => '人工复核必须绑定当前评分摘要并填写原因；后续复查或纠错可形成更新快照',
                'evidence_confidence_policy' => '证据置信度只反映结构化元数据完整度，与能力分分开，不参与加权',
                'dimension_rubrics' => self::DIMENSION_RUBRICS,
            ],
            'source' => [
                'reference_key' => self::SOURCE_REFERENCE_KEY,
                'fingerprint' => self::SOURCE_FINGERPRINT,
                'adaptation' => 'SUXIOS evidence scoring adaptation',
            ],
            'data_quality_status' => 'manual_declared',
            'usage_limits' => [
                '同一租户和酒店内的管理复盘',
                '不作为跨店排名或处罚依据',
                '不自动审批、建任务、操作OTA或PMS',
                '人工录入事实尚未被系统独立核验',
            ],
        ];
    }

    /**
     * Projects a single business day's three-question submission state without
     * treating submission as proof that the underlying case is closed.
     *
     * @param array<int, array<string, mixed>> $cases
     * @return array<string, mixed>
     */
    public function summarizeDailySubmission(array $cases, string $asOfDate): array
    {
        $asOfDate = $this->validDate($asOfDate, '三问提交状态日期');
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $asOf = new \DateTimeImmutable($asOfDate, $timezone);
        $today = new \DateTimeImmutable('today', $timezone);
        $sameDayCases = [];
        $lastSubmissionDate = null;
        $activeScannedCount = 0;
        $invalidDateCount = 0;

        foreach ($cases as $case) {
            if (!is_array($case) || ($case['is_voided'] ?? false) === true) {
                continue;
            }
            $businessDate = trim((string)($case['business_date'] ?? ''));
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($parsed === false
                || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
                || $parsed->format('Y-m-d') !== $businessDate
            ) {
                $invalidDateCount++;
                continue;
            }
            if ($businessDate > $asOfDate) {
                continue;
            }

            $activeScannedCount++;
            if ($lastSubmissionDate === null || $businessDate > $lastSubmissionDate) {
                $lastSubmissionDate = $businessDate;
            }
            if ($businessDate === $asOfDate) {
                $sameDayCases[] = $case;
            }
        }

        $caseIds = array_values(array_unique(array_filter(array_map(
            static fn(array $case): int => (int)($case['id'] ?? 0),
            $sameDayCases
        ), static fn(int $caseId): bool => $caseId > 0)));
        $caseCount = count($sameDayCases);
        $isCurrentDay = $asOfDate === $today->format('Y-m-d');
        $status = $caseCount > 0 ? 'submitted' : 'not_submitted';
        $missingDays = null;
        $attentionStatus = 'none';

        if ($status === 'submitted') {
            $missingDays = 0;
        } elseif ($lastSubmissionDate === null) {
            $attentionStatus = 'no_history';
        } else {
            $lastSubmission = new \DateTimeImmutable($lastSubmissionDate, $timezone);
            $missingDays = (int)$lastSubmission->diff($asOf)->days;
            $attentionStatus = $missingDays >= 3 ? 'three_day_missing' : 'due';
        }

        return [
            'business_date' => $asOfDate,
            'timezone' => 'Asia/Shanghai',
            'status' => $status,
            'label' => $status === 'submitted'
                ? ($isCurrentDay ? '今日已提交' : '当日已提交')
                : ($isCurrentDay ? '今日尚未提交' : '当日未提交'),
            'case_count' => $caseCount,
            'case_ids' => $caseIds,
            'last_submission_date' => $lastSubmissionDate,
            'consecutive_missing_days' => $missingDays,
            'attention_status' => $attentionStatus,
            'history_status' => $lastSubmissionDate === null ? 'empty' : 'available',
            'active_case_scan_count' => $activeScannedCount,
            'invalid_business_date_count' => $invalidDateCount,
            'source_quality_status' => 'manual_declared',
            'independent_verification' => false,
            'closure_inferred' => false,
            'closure_note' => '已提交不等于已闭环；仍以复查事件和可核对的验证结果为准',
            'automation_policy' => '状态只供人工查看，不自动提醒、建任务、处罚或外发',
        ];
    }

    /**
     * Due follow-up queue is independent from the ten recent profile cards.
     *
     * @return array<string, mixed>
     */
    public function followupQueue(
        int $tenantId,
        int $hotelId,
        int $managerUserId = 0,
        ?string $asOfDate = null
    ): array {
        $this->assertSchemaReady();
        $this->assertPositiveScope($tenantId, $hotelId);
        if ($managerUserId > 0) {
            $this->managerForScope($tenantId, $hotelId, $managerUserId);
        }
        $todayText = $asOfDate === null || trim($asOfDate) === ''
            ? (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d')
            : $this->validDate($asOfDate, '复查队列基准日期');
        $today = new \DateTimeImmutable($todayText, new \DateTimeZone('Asia/Shanghai'));
        $upcomingEnd = $today->modify('+7 days');

        $projectionBoundaries = $this->scopedProjectionBoundaries($tenantId, $hotelId, $managerUserId);
        $boundaryCaseId = $projectionBoundaries['case_id'];
        $scan = $this->scanProjectedCasePages(
            $boundaryCaseId,
            fn(int $afterCaseId, int $limit, int $boundary): array => $this->loadScopedCasePage(
                $tenantId,
                $hotelId,
                $managerUserId,
                $afterCaseId,
                $boundary,
                $limit
            ),
            fn(array $pageRows): array => $this->projectCaseRows(
                $tenantId,
                $hotelId,
                $pageRows,
                $projectionBoundaries
            )
        );
        $scan['metadata']['projection_boundaries'] = $projectionBoundaries;
        $queueRows = $this->casesForFollowupQueue($scan['cases'], $today);
        $counts = ['overdue' => 0, 'today' => 0, 'upcoming' => 0, 'all' => count($queueRows)];
        foreach ($queueRows as $case) {
            $bucket = (string)$case['due_bucket'];
            if (array_key_exists($bucket, $counts)) {
                $counts[$bucket]++;
            }
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId > 0 ? $managerUserId : null,
            'business_date' => $todayText,
            'timezone' => 'Asia/Shanghai',
            'horizon_end' => $upcomingEnd->format('Y-m-d'),
            'data_status' => ($scan['metadata']['complete'] ?? false) !== true
                ? 'partial'
                : ($queueRows === [] ? 'empty' : 'ready'),
            'scan' => $scan['metadata'],
            'data_gaps' => ($scan['metadata']['complete'] ?? false) === true
                ? []
                : ['case_scan_incomplete'],
            'counts' => $counts,
            'rows' => $queueRows,
            'source' => [
                'kind' => 'manual_declared',
                'rule' => '当前有效案例投影与最近追加复查；已关闭、复发原案例和已作废案例排除',
            ],
            'automation_boundary' => '只提供人工复查工作台，不自动建任务、发消息或操作OTA/PMS',
        ];
    }

    /**
     * Deterministic, explainable score for one three-question case.
     * Missing evidence stays null and is never converted to zero.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function scoreCase(array $input): array
    {
        $problem = trim((string)($input['problem_facts'] ?? ''));
        $action = trim((string)($input['action_taken'] ?? ''));
        $verificationStatus = trim((string)($input['verification_status'] ?? ''));
        $verification = trim((string)($input['verification_text'] ?? ''));
        $followupOutcome = trim((string)($input['followup_outcome'] ?? ''));
        $followupSampleCount = max(0, (int)($input['followup_sample_count'] ?? 0));
        $evidenceReference = trim((string)($input['evidence_reference'] ?? ''));
        $combined = $problem . '\n' . $action . '\n' . $verification . '\n' . $evidenceReference;

        $observationCueCount = $this->cueGroupCount($problem, [
            ['发现', '检查', '核对', '抽查', '投诉', '异常', '缺少', '未完成', '下降', '增加', '超时'],
            ['今天', '昨日', '上午', '下午', '晚上', '点', '日', '周', '班次'],
            ['前台', '客房', '餐厅', '主管', '员工', '客人', '房间', '订单', '记录', '报表'],
        ]) + ($this->hasNumber($problem) ? 1 : 0);
        $problemScore = $this->textLength($problem) >= 16 && $observationCueCount >= 2
            ? 90
            : ($this->textLength($problem) >= 10 && $observationCueCount >= 1 ? 75 : 50);
        $problemReasons = $problemScore === 90
            ? ['问题描述包含可观察对象、时间/岗位或数量证据']
            : ($problemScore === 75
                ? ['已说明具体问题，但事实要素仍可补充']
                : ['问题描述偏笼统，需要补充何时、何地、何人、何事']);

        $hasCause = $this->containsAny($combined, ['因为', '由于', '原因', '根因', '导致', '经核查', '经排查', '分析后', '发现是']);
        $hasCauseObject = $this->containsAny($combined, ['流程', '标准', '培训', '交接', '排班', '系统', '检查', '责任', '人手', '操作', '权限', '物料']);
        $causeScore = $hasCause && $hasCauseObject ? 90 : (($hasCause || $hasCauseObject) ? 75 : 50);
        $causeReasons = $causeScore === 90
            ? ['案例同时给出原因判断和具体流程/标准/资源对象']
            : ($causeScore === 75
                ? ['出现原因或管理对象线索，但因果链仍可补强']
                : ['未见明确原因分析证据，不能用处理动作代替原因']);

        $hasActionVerb = $this->containsAny($action, ['安排', '调整', '修复', '补齐', '更新', '制定', '执行', '分配', '跟进', '关闭', '联系', '处理', '完成', '纠正']);
        $hasOwner = $this->containsAny($action, ['店长', '主管', '负责人', '前台', '客房', '员工', '我', '安排由', '指定']);
        $hasActionSpecificity = $this->hasNumber($action)
            || $this->containsAny($action, ['截止', '当天', '现场', '逐项', '清单', 'SOP', '标准', '班次']);
        $solutionScore = $hasActionVerb && $hasOwner && $hasActionSpecificity
            ? 90
            : ($hasActionVerb && ($hasOwner || $hasActionSpecificity) ? 75 : 50);
        $solutionReasons = $solutionScore === 90
            ? ['动作包含执行行为、责任对象和具体做法']
            : ($solutionScore === 75
                ? ['已描述管理动作，但责任人或具体标准仍可补充']
                : ['动作偏原则性，需补充谁在何时完成什么']);

        $hasCoaching = $this->containsAny($action, ['培训', '带教', '演示', '讲解', '辅导', '示范', '复述', '实操', '回演']);
        $hasCoachingStandard = $this->containsAny($action, ['标准', '流程', '清单', '逐项', '演示']);
        $hasIndependentCheck = $this->containsAny($combined, ['独立完成', '复述', '实操', '考核', '通过', '掌握', '回演', '抽查']);
        $coachingScore = null;
        $coachingReasons = ['本案例未出现带教证据；保持未知，不计0分'];
        if ($hasCoaching) {
            $coachingScore = $hasIndependentCheck ? 90 : ($hasCoachingStandard ? 75 : 50);
            $coachingReasons = $coachingScore === 90
                ? ['包含带教动作及员工独立完成/考核证据']
                : ($coachingScore === 75
                    ? ['包含按标准或流程的带教动作']
                    : ['出现带教动作，但尚缺标准和掌握验证']);
        }

        $hasExecuted = $this->containsAny($action, ['已', '完成', '现场', '当天', '立即', '安排', '执行', '处理']);
        $hasPrevention = $this->containsAny($combined, ['防止', '避免', '后续', '每日', '每班', '持续', '机制', '清单', 'SOP', '复查', '抽查', '检查', '责任人', '截止']);
        $hasExecutionBoundary = $hasOwner || $this->hasNumber($combined)
            || $this->containsAny($combined, ['今天', '明天', '次日', '本周', '截止']);
        $executionScore = $hasExecuted && $hasPrevention && $hasExecutionBoundary
            ? 90
            : ($hasExecuted && $hasPrevention ? 75 : 50);
        $executionReasons = $executionScore === 90
            ? ['包含已执行动作、预防机制和责任/时间边界']
            : ($executionScore === 75
                ? ['已执行且有复查/预防安排，但边界仍可补充']
                : ['尚缺持续执行或防止复发的机制证据']);

        $closureScore = null;
        $closureReasons = ['当前只保存了复查计划；闭环分留空，待观察结果'];
        if ($followupOutcome === 'recurred') {
            $closureScore = 50;
            $closureReasons = ['复查明确记录问题再次发生；本次闭环证据需补强，并已转入关联新案例'];
        } elseif ($followupOutcome === 'still_open') {
            $closureReasons = ['复查确认问题仍待观察或处理；闭环分继续留空，不计0分'];
        } elseif ($verificationStatus === 'observed_result') {
            $hasObservedResult = $this->containsAny($verification, ['已', '全部', '完成', '恢复', '减少', '提升', '无', '符合', '达标', '结果']);
            $hasResultMeasurement = $followupSampleCount > 0
                || $this->hasNumber($verification)
                || $this->containsAny($verification, ['抽查', '核对', '记录', '数据', '签字', '订单', '投诉']);
            $closureScore = $hasObservedResult && $hasResultMeasurement
                ? 90
                : ($hasObservedResult ? 75 : 50);
            $closureReasons = $closureScore === 90
                ? ['已保存可观察且可核对的验证结果']
                : ($closureScore === 75
                    ? ['已保存结果，但量化或核对依据仍可补充']
                    : ['验证描述尚不能证明问题已关闭']);
        }

        $dimensions = [
            $this->dimension('problem_discovery', $problemScore, ['problem_facts'], $problemReasons),
            $this->dimension('cause_analysis', $causeScore, ['problem_facts', 'action_taken'], $causeReasons),
            $this->dimension('solution_management', $solutionScore, ['action_taken'], $solutionReasons),
            $this->dimension('coaching', $coachingScore, $hasCoaching ? ['action_taken', 'verification_text'] : [], $coachingReasons),
            $this->dimension('execution_prevention', $executionScore, ['action_taken', 'verification_text'], $executionReasons),
            $this->dimension(
                'closure',
                $closureScore,
                $closureScore === null
                    ? ['verification_status', ...($followupOutcome !== '' ? ['followup_outcome'] : [])]
                    : ['verification_text', ...($followupOutcome !== '' ? ['followup_outcome', 'sample_count'] : [])],
                $closureReasons
            ),
        ];
        $scores = array_values(array_filter(
            array_map(static fn(array $dimension): ?int => is_int($dimension['score']) ? $dimension['score'] : null, $dimensions),
            static fn(?int $score): bool => $score !== null
        ));
        $scoredCount = count($scores);
        $caseScore = $scoredCount === count(self::DIMENSION_LABELS)
            ? round(array_sum($scores) / $scoredCount, 2)
            : null;
        $status = $verificationStatus === 'planned_verification' || $followupOutcome === 'still_open'
            ? 'pending_verification'
            : ($caseScore !== null ? 'scored' : 'data_insufficient');

        return [
            'formula_version' => self::FORMULA_VERSION,
            'status' => $status,
            'case_score' => $caseScore,
            'scored_dimension_count' => $scoredCount,
            'dimensions' => $dimensions,
            'truth_boundary' => '人工声明案例的证据分，不代表系统已独立核验事实',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $snapshots
     * @return array<string, mixed>
     */
    public function aggregateDimensionScores(array $snapshots): array
    {
        $buckets = array_fill_keys(array_keys(self::DIMENSION_LABELS), []);
        foreach ($snapshots as $snapshot) {
            $caseId = (int)($snapshot['case_id'] ?? 0);
            $dimensions = is_array($snapshot['dimensions'] ?? null) ? $snapshot['dimensions'] : [];
            foreach ($dimensions as $dimension) {
                if (!is_array($dimension)) {
                    continue;
                }
                $key = (string)($dimension['key'] ?? '');
                $score = $dimension['score'] ?? null;
                if (!array_key_exists($key, $buckets) || !is_numeric($score)) {
                    continue;
                }
                $buckets[$key][] = [
                    'case_id' => $caseId,
                    'score' => (float)$score,
                ];
            }
        }

        $dimensions = [];
        $readyScores = [];
        $dataGaps = [];
        foreach (self::DIMENSION_LABELS as $key => $label) {
            $samples = $buckets[$key];
            $sampleCount = count($samples);
            $score = $sampleCount >= self::MINIMUM_PROFILE_SAMPLES
                ? round(array_sum(array_column($samples, 'score')) / $sampleCount, 1)
                : null;
            if ($score !== null) {
                $readyScores[] = $score;
            } else {
                $dataGaps[] = $key . '_requires_' . self::MINIMUM_PROFILE_SAMPLES . '_cases';
            }
            $dimensions[] = [
                'key' => $key,
                'label' => $label,
                'score' => $score,
                'status' => $score === null ? 'data_insufficient' : 'scored',
                'level_label' => $score === null ? '数据不足' : $this->scoreLevelLabel($score),
                'sample_count' => $sampleCount,
                'required_sample_count' => self::MINIMUM_PROFILE_SAMPLES,
                'evidence_case_ids' => array_values(array_filter(array_map(
                    static fn(array $sample): int => (int)$sample['case_id'],
                    $samples
                ))),
            ];
        }

        $overallScore = count($readyScores) === count(self::DIMENSION_LABELS)
            ? round(array_sum($readyScores) / count($readyScores), 1)
            : null;

        return [
            'status' => $overallScore === null ? 'data_insufficient' : 'scored',
            'label' => $overallScore === null ? '数据不足' : $this->profileLevelLabel($overallScore),
            'overall_score' => $overallScore,
            'dimensions' => $dimensions,
            'data_gaps' => $dataGaps,
        ];
    }

    /** @param array<int, array<string, mixed>> $cases @return array<string, mixed> */
    private function buildTrend(array $cases): array
    {
        $months = [];
        foreach ($cases as $case) {
            $date = (string)($case['business_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                continue;
            }
            $month = substr($date, 0, 7);
            if (!isset($months[$month])) {
                $months[$month] = [
                    'case_ids' => [],
                    'dimension_scores' => array_fill_keys(array_keys(self::DIMENSION_LABELS), []),
                ];
            }
            $months[$month]['case_ids'][] = (int)$case['id'];
            foreach ((array)($case['score_snapshot']['dimensions'] ?? []) as $dimension) {
                if (!is_array($dimension)) {
                    continue;
                }
                $key = (string)($dimension['key'] ?? '');
                $score = $dimension['score'] ?? null;
                if (array_key_exists($key, self::DIMENSION_LABELS) && is_numeric($score)) {
                    $months[$month]['dimension_scores'][$key][] = (float)$score;
                }
            }
        }
        ksort($months);
        $points = [];
        foreach (array_slice($months, -6, 6, true) as $month => $monthData) {
            $dimensionAverages = [];
            $allObservedScores = [];
            foreach (self::DIMENSION_LABELS as $key => $label) {
                $values = $monthData['dimension_scores'][$key];
                $average = $values === [] ? null : round(array_sum($values) / count($values), 1);
                $dimensionAverages[] = [
                    'key' => $key,
                    'label' => $label,
                    'score' => $average,
                    'sample_count' => count($values),
                ];
                $allObservedScores = [...$allObservedScores, ...$values];
            }
            $points[] = [
                'period' => $month,
                'case_count' => count(array_unique($monthData['case_ids'])),
                'observed_dimension_count' => count($allObservedScores),
                'average_score' => $allObservedScores === []
                    ? null
                    : round(array_sum($allObservedScores) / count($allObservedScores), 1),
                'dimensions' => $dimensionAverages,
                'status' => $allObservedScores === [] ? 'data_insufficient' : 'observed_average',
            ];
        }
        $scoredPoints = array_values(array_filter(
            $points,
            static fn(array $point): bool => is_numeric($point['average_score'] ?? null)
        ));
        $direction = 'data_insufficient';
        $delta = null;
        if (count($scoredPoints) >= 2) {
            $previous = (float)$scoredPoints[count($scoredPoints) - 2]['average_score'];
            $latest = (float)$scoredPoints[count($scoredPoints) - 1]['average_score'];
            $delta = round($latest - $previous, 1);
            $direction = $delta >= 3 ? 'improving' : ($delta <= -3 ? 'needs_attention' : 'stable');
        }

        return [
            'status' => $points === [] ? 'empty' : ($direction === 'data_insufficient' ? 'partial' : 'ready'),
            'direction' => $direction,
            'latest_delta' => $delta,
            'points' => $points,
            'scope' => '当前店长个人月度观察均值；不用于跨店排名',
        ];
    }

    /** @param array<string, mixed> $aggregate @return array<int, array<string, mixed>> */
    private function buildCoachingSuggestions(array $aggregate): array
    {
        $actions = [
            'problem_discovery' => '连续一周使用“时间、岗位、对象、数量”四要素记录一个问题事实。',
            'cause_analysis' => '每个案例区分现象与原因，至少补一条流程、标准或资源层面的核查依据。',
            'solution_management' => '把动作写成“谁、何时、按什么标准、完成什么”，复盘时逐项核对。',
            'coaching' => '安排一次示范、员工复述或实操，并记录能否独立完成。',
            'execution_prevention' => '为动作补责任人、截止时间和防复发检查频率。',
            'closure' => '复查时记录样本数、证据引用和可观察结果，未关闭就继续设下次日期。',
        ];
        $dimensions = array_values((array)($aggregate['dimensions'] ?? []));
        usort($dimensions, static function (array $left, array $right): int {
            $leftScore = is_numeric($left['score'] ?? null) ? (float)$left['score'] : 1000.0 + (int)($left['sample_count'] ?? 0);
            $rightScore = is_numeric($right['score'] ?? null) ? (float)$right['score'] : 1000.0 + (int)($right['sample_count'] ?? 0);
            return $leftScore <=> $rightScore;
        });
        $result = [];
        foreach (array_slice($dimensions, 0, 2) as $dimension) {
            $key = (string)($dimension['key'] ?? '');
            if (!isset($actions[$key])) {
                continue;
            }
            $score = $dimension['score'] ?? null;
            $result[] = [
                'dimension_key' => $key,
                'dimension_label' => (string)($dimension['label'] ?? self::DIMENSION_LABELS[$key]),
                'basis' => $score === null
                    ? sprintf('当前仅有%d/%d个有效案例，先补证据再判断能力。', (int)($dimension['sample_count'] ?? 0), self::MINIMUM_PROFILE_SAMPLES)
                    : sprintf('当前个人档案分%s，优先从最低观察维度开始带教。', (string)$score),
                'suggestion' => $actions[$key],
                'review_question' => '下次复查能否提供同门店、同店长、明确日期和证据引用？',
                'source_kind' => 'deterministic_coaching_rule',
            ];
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $cases @return array<string, mixed> */
    private function buildEvidenceConfidenceSummary(array $cases): array
    {
        $counts = ['high' => 0, 'medium' => 0, 'unverified' => 0];
        foreach ($cases as $case) {
            $confidence = (string)($case['evidence']['confidence'] ?? 'unverified');
            $counts[array_key_exists($confidence, $counts) ? $confidence : 'unverified']++;
        }
        return [
            'counts' => $counts,
            'structured_count' => $counts['high'] + $counts['medium'],
            'total_count' => count($cases),
            'policy' => '置信度仅反映证据类型、日期、引用和样本完整度，不参与能力分加权',
        ];
    }

    /** @param array<int, array<string, mixed>> $cases @return array<string, mixed> */
    private function buildPilotReadiness(array $cases): array
    {
        $dates = array_values(array_filter(array_map(
            static fn(array $case): string => (string)($case['business_date'] ?? ''),
            $cases
        ), static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1));
        sort($dates);
        $activeDays = 0;
        if ($dates !== []) {
            $from = new \DateTimeImmutable($dates[0], new \DateTimeZone('Asia/Shanghai'));
            $to = new \DateTimeImmutable($dates[array_key_last($dates)], new \DateTimeZone('Asia/Shanghai'));
            $activeDays = (int)$from->diff($to)->days + 1;
        }
        $caseCount = count($cases);
        $status = $caseCount === 0
            ? 'not_started'
            : (($activeDays >= 14 && $caseCount >= self::MINIMUM_PROFILE_SAMPLES) ? 'ready_for_review' : 'collecting');
        return [
            'status' => $status,
            'case_count' => $caseCount,
            'active_days' => $activeDays,
            'first_case_date' => $dates[0] ?? null,
            'latest_case_date' => $dates === [] ? null : $dates[array_key_last($dates)],
            'minimum_review_days' => 14,
            'recommended_review_days' => 28,
            'field_validation_status' => 'not_validated',
            'note' => '达到条件只表示可开始人工复盘，不代表现场效果已经验证。',
        ];
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $manager
     */
    private function insertCaseWithSnapshot(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        array $normalized,
        array $manager,
        string $inputDigest,
        ?int $parentCaseId = null,
        ?int $originFollowupId = null,
        string $sourceKind = 'manual_management_three_questions'
    ): int {
        $score = $this->scoreCase($normalized);
        $caseStatus = $normalized['verification_status'] === 'observed_result'
            ? 'closed'
            : 'pending_verification';
        $createdAt = $this->now();
        $caseId = (int)Db::name(self::CASE_TABLE)->insertGetId([
            'parent_case_id' => $parentCaseId,
            'origin_followup_id' => $originFollowupId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => (int)$normalized['manager_user_id'],
            'manager_name_snapshot' => (string)$manager['display_name'],
            'business_date' => $normalized['business_date'],
            'problem_facts' => $normalized['problem_facts'],
            'action_taken' => $normalized['action_taken'],
            'verification_status' => $normalized['verification_status'],
            'verification_text' => $normalized['verification_text'],
            'followup_due_date' => $normalized['followup_due_date'],
            'evidence_type' => $normalized['evidence_type'],
            'evidence_reference' => $normalized['evidence_reference'],
            'evidence_date' => $normalized['evidence_date'],
            'evidence_confidence' => $normalized['evidence_confidence'],
            'case_status' => $caseStatus,
            'source_kind' => $sourceKind,
            'source_quality_status' => 'manual_declared',
            'idempotency_key' => $normalized['idempotency_key'],
            'input_digest' => $inputDigest,
            'created_by' => $actorUserId,
            'created_at' => $createdAt,
        ]);
        if ($caseId <= 0) {
            throw new RuntimeException('店长评分案例保存失败');
        }

        $evidencePayload = [
            'case_id' => $caseId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => (int)$normalized['manager_user_id'],
            'formula_version' => self::FORMULA_VERSION,
            'source_reference_key' => self::SOURCE_REFERENCE_KEY,
            'source_fingerprint' => self::SOURCE_FINGERPRINT,
            'dimensions' => $score['dimensions'],
            'case_score' => $score['case_score'],
            'score_status' => $score['status'],
            'input_digest' => $inputDigest,
        ];
        $evidenceDigest = $this->digest($evidencePayload);
        Db::name(self::SCORE_TABLE)->insert([
            'case_id' => $caseId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'manager_user_id' => (int)$normalized['manager_user_id'],
            'formula_version' => self::FORMULA_VERSION,
            'source_reference_key' => self::SOURCE_REFERENCE_KEY,
            'source_fingerprint' => self::SOURCE_FINGERPRINT,
            'dimensions_json' => $this->encodeJson($score['dimensions']),
            'case_score' => $score['case_score'],
            'scored_dimension_count' => (int)$score['scored_dimension_count'],
            'score_status' => $score['status'],
            'evidence_digest' => $evidenceDigest,
            'created_at' => $createdAt,
        ]);

        return $caseId;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeFollowupInput(array $input, string $caseBusinessDate): array
    {
        $followupDate = $this->validDate((string)($input['followup_date'] ?? ''), '复查日期');
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = new \DateTimeImmutable('today', $timezone);
        $caseClock = new \DateTimeImmutable(
            $this->validDate($caseBusinessDate, '原案例日期'),
            $timezone
        );
        $followupClock = new \DateTimeImmutable($followupDate, $timezone);
        if ($followupClock < $caseClock) {
            throw new InvalidArgumentException('复查日期不能早于原案例日期');
        }
        if ($followupClock > $today) {
            throw new InvalidArgumentException('复查日期不能晚于今天');
        }

        $outcome = trim((string)($input['followup_outcome'] ?? ''));
        if (!in_array($outcome, ['resolved', 'still_open', 'recurred'], true)) {
            throw new InvalidArgumentException('请选择已解决、待继续或再发生');
        }
        $verification = $this->requiredText(
            $input['verification_text'] ?? '',
            $outcome === 'still_open' ? '继续复查说明' : '复查结果',
            8,
            2000
        );

        $sampleValue = filter_var(
            $input['sample_count'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 100000]]
        );
        if ($sampleValue === false) {
            throw new InvalidArgumentException('核对样本数必须是0到100000之间的整数');
        }
        $sampleCount = (int)$sampleValue;
        if (in_array($outcome, ['resolved', 'recurred'], true) && $sampleCount < 1) {
            throw new InvalidArgumentException('已解决或再发生至少需要1个核对样本');
        }

        $evidence = $this->normalizeEvidence($input, $followupDate, $sampleCount);

        $nextFollowupDate = null;
        if ($outcome !== 'resolved') {
            $nextFollowupDate = $this->validDate(
                (string)($input['next_followup_date'] ?? ''),
                '下次复查日期'
            );
            $nextClock = new \DateTimeImmutable($nextFollowupDate, $timezone);
            if ($nextClock < $followupClock) {
                throw new InvalidArgumentException('下次复查日期不能早于本次复查日期');
            }
            if ($nextClock > $followupClock->modify('+90 days')) {
                throw new InvalidArgumentException('下次复查日期不能晚于本次复查日期90天');
            }
        }

        $recurrenceProblem = null;
        $recurrenceAction = null;
        $recurrencePlan = null;
        if ($outcome === 'recurred') {
            $recurrenceProblem = $this->requiredText(
                $input['recurrence_problem_facts'] ?? '',
                '复发问题事实',
                10,
                2000
            );
            $recurrenceAction = $this->requiredText(
                $input['recurrence_action_taken'] ?? '',
                '复发后采取动作',
                8,
                2000
            );
            $recurrencePlan = $this->requiredText(
                $input['recurrence_verification_plan'] ?? '',
                '复发案例验证计划',
                8,
                2000
            );
        }

        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('能力复查幂等键无效');
        }

        return [
            'followup_date' => $followupDate,
            'followup_outcome' => $outcome,
            'verification_text' => $verification,
            'sample_count' => $sampleCount,
            'evidence_type' => $evidence['type'],
            'evidence_reference' => $evidence['reference'],
            'evidence_date' => $evidence['date'],
            'evidence_confidence' => $evidence['confidence'],
            'next_followup_date' => $nextFollowupDate,
            'recurrence_problem_facts' => $recurrenceProblem,
            'recurrence_action_taken' => $recurrenceAction,
            'recurrence_verification_plan' => $recurrencePlan,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeCaseInput(array $input): array
    {
        $managerUserId = (int)($input['manager_user_id'] ?? 0);
        if ($managerUserId <= 0) {
            throw new InvalidArgumentException('请选择店长或负责人');
        }

        $businessDate = $this->validDate((string)($input['business_date'] ?? ''), '案例日期');
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        $businessClock = new \DateTimeImmutable($businessDate, new \DateTimeZone('Asia/Shanghai'));
        if ($businessClock > $today) {
            throw new InvalidArgumentException('案例日期不能晚于今天');
        }
        if ($businessClock < $today->modify('-365 days')) {
            throw new InvalidArgumentException('案例日期只支持最近365天');
        }

        $problem = $this->requiredText($input['problem_facts'] ?? '', '问题事实', 10, 2000);
        $action = $this->requiredText($input['action_taken'] ?? '', '采取动作', 8, 2000);
        $verificationStatus = trim((string)($input['verification_status'] ?? ''));
        if (!in_array($verificationStatus, ['observed_result', 'planned_verification'], true)) {
            throw new InvalidArgumentException('请选择已观察结果或待复查计划');
        }
        $verification = $this->requiredText(
            $input['verification_text'] ?? '',
            $verificationStatus === 'observed_result' ? '验证结果' : '复查计划',
            8,
            2000
        );

        $followupDueDate = null;
        if ($verificationStatus === 'planned_verification') {
            $followupDueDate = $this->validDate((string)($input['followup_due_date'] ?? ''), '复查日期');
            $followupClock = new \DateTimeImmutable($followupDueDate, new \DateTimeZone('Asia/Shanghai'));
            if ($followupClock < $businessClock) {
                throw new InvalidArgumentException('复查日期不能早于案例日期');
            }
            if ($followupClock > $businessClock->modify('+90 days')) {
                throw new InvalidArgumentException('复查日期不能晚于案例日期90天');
            }
        }

        $evidence = $this->normalizeEvidence($input, $businessDate);

        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:_-]{8,120}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('评分案例幂等键无效');
        }

        return [
            'manager_user_id' => $managerUserId,
            'business_date' => $businessDate,
            'problem_facts' => $problem,
            'action_taken' => $action,
            'verification_status' => $verificationStatus,
            'verification_text' => $verification,
            'followup_due_date' => $followupDueDate,
            'evidence_type' => $evidence['type'],
            'evidence_reference' => $evidence['reference'],
            'evidence_date' => $evidence['date'],
            'evidence_confidence' => $evidence['confidence'],
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * Structured evidence is deliberately separate from the capability score.
     * Legacy callers may omit all evidence fields; partial structured evidence is rejected.
     *
     * @param array<string, mixed> $input
     * @return array{type: ?string, reference: ?string, date: ?string, confidence: string, label: ?string}
     */
    private function normalizeEvidence(array $input, string $fallbackDate, int $sampleCount = 0): array
    {
        $type = trim((string)($input['evidence_type'] ?? ''));
        $reference = $this->optionalText($input['evidence_reference'] ?? '', '证据引用', 500);
        $dateText = trim((string)($input['evidence_date'] ?? ''));
        $hasAny = $type !== '' || $reference !== null || $dateText !== '';
        if (!$hasAny) {
            return [
                'type' => null,
                'reference' => null,
                'date' => null,
                'confidence' => 'unverified',
                'label' => null,
            ];
        }
        if (!array_key_exists($type, self::EVIDENCE_TYPE_LABELS)) {
            throw new InvalidArgumentException('请选择有效的证据类型');
        }
        if ($reference === null) {
            throw new InvalidArgumentException('结构化证据必须填写记录或附件引用');
        }
        if ($dateText === '') {
            throw new InvalidArgumentException('结构化证据必须填写证据日期');
        }
        $date = $this->validDate($dateText, '证据日期');
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if ($date > $today) {
            throw new InvalidArgumentException('证据日期不能晚于今天');
        }

        $confidence = in_array($type, ['signed_checklist', 'system_record'], true) || $sampleCount >= 3
            ? 'high'
            : 'medium';

        return [
            'type' => $type,
            'reference' => $reference,
            'date' => $date,
            'confidence' => $confidence,
            'label' => self::EVIDENCE_TYPE_LABELS[$type],
        ];
    }

    /** @return array{case_id: int, followup_id: int, adjustment_id: int, review_id: int} */
    private function scopedProjectionBoundaries(int $tenantId, int $hotelId, int $managerUserId): array
    {
        $boundaries = [];
        foreach ([
            'case_id' => self::CASE_TABLE,
            'followup_id' => self::FOLLOWUP_TABLE,
            'adjustment_id' => self::ADJUSTMENT_TABLE,
            'review_id' => self::REVIEW_TABLE,
        ] as $key => $table) {
            $query = Db::name($table)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId);
            if ($managerUserId > 0) {
                $query->where('manager_user_id', $managerUserId);
            }
            $boundaries[$key] = max(0, (int)$query->max('id'));
        }
        return $boundaries;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadScopedCasePage(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        int $afterCaseId,
        int $boundaryCaseId,
        int $limit
    ): array {
        if ($afterCaseId < 0 || $boundaryCaseId < 0 || $limit < 1 || $limit > self::CASE_SCAN_PAGE_SIZE) {
            throw new InvalidArgumentException('店长能力案例分页边界无效');
        }

        $query = Db::name(self::CASE_TABLE)->alias('c')
            ->leftJoin(self::SCORE_TABLE . ' s', 's.case_id = c.id AND s.formula_version = \'' . self::FORMULA_VERSION . '\'')
            ->where('c.tenant_id', $tenantId)
            ->where('c.hotel_id', $hotelId)
            ->where('c.id', '>', $afterCaseId)
            ->where('c.id', '<=', $boundaryCaseId)
            ->field('c.*,s.formula_version,s.source_reference_key,s.source_fingerprint,s.dimensions_json,s.case_score,s.scored_dimension_count,s.score_status,s.evidence_digest,s.created_at AS scored_at')
            ->order('c.id', 'asc')
            ->limit($limit);
        if ($managerUserId > 0) {
            $query->where('c.manager_user_id', $managerUserId);
        }
        return $query->select()->toArray();
    }

    /**
     * Hydrate every raw page before filtering effective dates or states because
     * append-only adjustments and follow-ups may change those projections.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function projectCaseRows(
        int $tenantId,
        int $hotelId,
        array $rows,
        array $projectionBoundaries
    ): array
    {
        $byManager = [];
        foreach ($rows as $row) {
            $caseId = (int)($row['id'] ?? 0);
            $managerUserId = (int)($row['manager_user_id'] ?? 0);
            if ($caseId <= 0 || $managerUserId <= 0) {
                throw new RuntimeException('店长能力案例分页数据无效');
            }
            $byManager[$managerUserId][] = $row;
        }

        $projectedById = [];
        foreach ($byManager as $managerUserId => $managerRows) {
            $caseIds = array_map(static fn(array $row): int => (int)$row['id'], $managerRows);
            $followups = $this->followupsForCases(
                $tenantId,
                $hotelId,
                $managerUserId,
                $caseIds,
                (int)($projectionBoundaries['followup_id'] ?? 0)
            );
            $adjustments = $this->adjustmentsForCases(
                $tenantId,
                $hotelId,
                $managerUserId,
                $caseIds,
                (int)($projectionBoundaries['adjustment_id'] ?? 0)
            );
            $reviews = $this->reviewsForCases(
                $tenantId,
                $hotelId,
                $managerUserId,
                $caseIds,
                (int)($projectionBoundaries['review_id'] ?? 0)
            );
            foreach ($managerRows as $row) {
                $caseId = (int)$row['id'];
                $projectedById[$caseId] = $this->publicCase(
                    $row,
                    $followups[$caseId] ?? [],
                    $adjustments[$caseId] ?? [],
                    $reviews[$caseId] ?? []
                );
            }
        }

        $projected = [];
        foreach ($rows as $row) {
            $caseId = (int)$row['id'];
            if (!isset($projectedById[$caseId])) {
                throw new RuntimeException('店长能力案例投影缺失');
            }
            $projected[] = $projectedById[$caseId];
        }
        return $projected;
    }

    /**
     * Keyset-scan a fixed case-id boundary. Concurrent new cases belong to the
     * next read instead of moving the current window. A reached safety cap is
     * explicitly partial and never presented as a complete aggregate.
     *
     * @param callable(int, int, int): array<int, array<string, mixed>> $loadPage
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $projectPage
     * @return array{cases: array<int, array<string, mixed>>, metadata: array<string, mixed>}
     */
    private function scanProjectedCasePages(
        int $boundaryCaseId,
        callable $loadPage,
        callable $projectPage,
        ?int $pageSize = null,
        ?int $maxRows = null
    ): array {
        $resolvedPageSize = $pageSize ?? self::CASE_SCAN_PAGE_SIZE;
        $resolvedMaxRows = $maxRows ?? self::CASE_SCAN_MAX_ROWS;
        if ($boundaryCaseId < 0
            || $resolvedPageSize < 1
            || $resolvedPageSize > self::CASE_SCAN_PAGE_SIZE
            || $resolvedMaxRows < 1
        ) {
            throw new InvalidArgumentException('店长能力案例扫描参数无效');
        }

        $cases = [];
        $cursor = 0;
        $pageCount = 0;
        $scannedRowCount = 0;
        $complete = $boundaryCaseId === 0;
        $stopReason = $complete ? 'empty_scope' : 'not_started';
        while (!$complete && $scannedRowCount < $resolvedMaxRows) {
            $limit = min($resolvedPageSize, $resolvedMaxRows - $scannedRowCount);
            $rows = $loadPage($cursor, $limit, $boundaryCaseId);
            if (!is_array($rows)) {
                throw new RuntimeException('店长能力案例分页返回格式无效');
            }
            if ($rows === []) {
                $stopReason = 'page_empty_before_boundary';
                break;
            }

            $previousId = $cursor;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('店长能力案例分页行格式无效');
                }
                $caseId = (int)($row['id'] ?? 0);
                if ($caseId <= $previousId || $caseId > $boundaryCaseId) {
                    throw new RuntimeException('店长能力案例分页游标未单调前进');
                }
                $previousId = $caseId;
            }

            $projected = $projectPage($rows);
            if (!is_array($projected)) {
                throw new RuntimeException('店长能力案例投影返回格式无效');
            }
            if (count($projected) !== count($rows)) {
                throw new RuntimeException('店长能力案例投影数量与分页数量不一致');
            }
            foreach ($projected as $case) {
                if (!is_array($case)) {
                    throw new RuntimeException('店长能力案例投影行格式无效');
                }
                $cases[] = $case;
            }

            $pageCount++;
            $scannedRowCount += count($rows);
            $cursor = $previousId;
            if ($cursor >= $boundaryCaseId) {
                $complete = true;
                $stopReason = 'boundary_reached';
            } elseif (count($rows) < $limit) {
                $stopReason = 'short_page_before_boundary';
                break;
            }
        }
        if (!$complete && $scannedRowCount >= $resolvedMaxRows) {
            $stopReason = 'row_limit_reached';
        }

        return [
            'cases' => $cases,
            'metadata' => [
                'strategy' => 'case_id_keyset_fixed_boundary',
                'status' => $complete ? 'complete' : 'partial',
                'complete' => $complete,
                'truncated' => !$complete,
                'stop_reason' => $stopReason,
                'boundary_case_id' => $boundaryCaseId,
                'last_case_id' => $cursor > 0 ? $cursor : null,
                'page_size' => $resolvedPageSize,
                'page_count' => $pageCount,
                'scanned_row_count' => $scannedRowCount,
                'projected_case_count' => count($cases),
                'max_rows' => $resolvedMaxRows,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $cases @return array<int, array<string, mixed>> */
    private function casesInProfileWindow(array $cases, string $from, string $to): array
    {
        $filtered = array_values(array_filter(
            $cases,
            static fn(array $case): bool => (string)($case['business_date'] ?? '') >= $from
                && (string)($case['business_date'] ?? '') <= $to
        ));
        usort($filtered, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)$right['business_date'], (string)$left['business_date']);
            return $dateCompare !== 0 ? $dateCompare : ((int)$right['id'] <=> (int)$left['id']);
        });
        return $filtered;
    }

    /** @param array<int, array<string, mixed>> $cases @return array<int, array<string, mixed>> */
    private function casesForFollowupQueue(array $cases, \DateTimeImmutable $today): array
    {
        $queueRows = [];
        foreach ($cases as $case) {
            if (($case['is_voided'] ?? false) === true
                || (string)($case['case_status'] ?? '') !== 'pending_verification'
                || trim((string)($case['current_followup_due_date'] ?? '')) === ''
            ) {
                continue;
            }
            $dueText = (string)$case['current_followup_due_date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueText) !== 1) {
                throw new RuntimeException('店长能力案例当前复查日期无效');
            }
            $due = new \DateTimeImmutable($dueText, new \DateTimeZone('Asia/Shanghai'));
            $daysOffset = (int)$today->diff($due)->format('%r%a');
            if ($daysOffset > 7) {
                continue;
            }
            $case['due_bucket'] = $daysOffset < 0 ? 'overdue' : ($daysOffset === 0 ? 'today' : 'upcoming');
            $case['days_offset'] = $daysOffset;
            $queueRows[] = $case;
        }
        usort($queueRows, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)$left['current_followup_due_date'], (string)$right['current_followup_due_date']);
            return $dateCompare !== 0 ? $dateCompare : ((int)$left['id'] <=> (int)$right['id']);
        });
        return $queueRows;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeProfileWindow(?string $dateFrom, ?string $dateTo): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = new \DateTimeImmutable('today', $timezone);
        $to = $dateTo === null || trim($dateTo) === ''
            ? $today->format('Y-m-d')
            : $this->validDate($dateTo, '截止日期');
        $from = $dateFrom === null || trim($dateFrom) === ''
            ? (new \DateTimeImmutable($to, $timezone))->modify('-89 days')->format('Y-m-d')
            : $this->validDate($dateFrom, '开始日期');
        if ($from > $to) {
            throw new InvalidArgumentException('开始日期不能晚于截止日期');
        }

        $days = (new \DateTimeImmutable($from, $timezone))->diff(new \DateTimeImmutable($to, $timezone))->days;
        if ($days === false || $days > 365) {
            throw new InvalidArgumentException('评分档案查询范围不能超过366天');
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function managerForScope(int $tenantId, int $hotelId, int $managerUserId): array
    {
        foreach ($this->listManagers($tenantId, $hotelId) as $manager) {
            if ((int)$manager['id'] === $managerUserId) {
                return $manager;
            }
        }

        throw new RuntimeException('所选店长不属于当前租户和酒店');
    }

    /**
     * @param array<int, int> $caseIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function followupsForCases(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        array $caseIds,
        ?int $maxEventId = null
    ): array {
        $caseIds = array_values(array_unique(array_filter(
            array_map('intval', $caseIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($caseIds === []) {
            return [];
        }

        $query = Db::name(self::FOLLOWUP_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('manager_user_id', $managerUserId)
            ->whereIn('case_id', $caseIds)
            ->order('followup_date', 'asc')
            ->order('id', 'asc');
        if ($maxEventId !== null) {
            $query->where('id', '<=', max(0, $maxEventId));
        }
        $rows = $query->select()->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $caseId = (int)($row['case_id'] ?? 0);
            if ($caseId <= 0) {
                continue;
            }
            $grouped[$caseId][] = $this->publicFollowup($row);
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $caseIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function adjustmentsForCases(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        array $caseIds,
        ?int $maxEventId = null
    ): array {
        $caseIds = array_values(array_unique(array_filter(
            array_map('intval', $caseIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($caseIds === []) {
            return [];
        }

        $query = Db::name(self::ADJUSTMENT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('manager_user_id', $managerUserId)
            ->whereIn('case_id', $caseIds)
            ->order('id', 'asc');
        if ($maxEventId !== null) {
            $query->where('id', '<=', max(0, $maxEventId));
        }
        $rows = $query->select()->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $caseId = (int)($row['case_id'] ?? 0);
            if ($caseId > 0) {
                $grouped[$caseId][] = $this->publicAdjustment($row);
            }
        }
        return $grouped;
    }

    /**
     * @param array<int, int> $caseIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function reviewsForCases(
        int $tenantId,
        int $hotelId,
        int $managerUserId,
        array $caseIds,
        ?int $maxEventId = null
    ): array {
        $caseIds = array_values(array_unique(array_filter(
            array_map('intval', $caseIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($caseIds === []) {
            return [];
        }

        $query = Db::name(self::REVIEW_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('manager_user_id', $managerUserId)
            ->whereIn('case_id', $caseIds)
            ->order('id', 'asc');
        if ($maxEventId !== null) {
            $query->where('id', '<=', max(0, $maxEventId));
        }
        $rows = $query->select()->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $caseId = (int)($row['case_id'] ?? 0);
            if ($caseId > 0) {
                $grouped[$caseId][] = $this->publicReview($row);
            }
        }
        return $grouped;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicAdjustment(array $row): array
    {
        $caseScore = $row['case_score'] === null || $row['case_score'] === ''
            ? null
            : round((float)$row['case_score'], 2);
        return [
            'id' => (int)$row['id'],
            'case_id' => (int)$row['case_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'manager_user_id' => (int)$row['manager_user_id'],
            'adjustment_type' => (string)$row['adjustment_type'],
            'reason' => (string)$row['reason'],
            'effective_case' => $this->decodeJson((string)$row['effective_payload_json']),
            'is_voided' => (int)$row['is_voided'] === 1,
            'source_kind' => (string)$row['source_kind'],
            'source_quality_status' => (string)$row['source_quality_status'],
            'input_digest' => (string)$row['input_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'score_snapshot' => [
                'scoring_version' => (string)$row['scoring_version'],
                'source_reference_key' => (string)$row['source_reference_key'],
                'source_fingerprint' => (string)$row['source_fingerprint'],
                'dimensions' => $this->decodeJson((string)$row['dimensions_json']),
                'case_score' => $caseScore,
                'scored_dimension_count' => (int)$row['scored_dimension_count'],
                'score_status' => (string)$row['score_status'],
                'evidence_digest' => (string)$row['evidence_digest'],
                'scored_at' => (string)$row['created_at'],
                'review_kind' => 'case_adjustment',
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function publicReview(array $row): array
    {
        $caseScore = $row['reviewed_case_score'] === null || $row['reviewed_case_score'] === ''
            ? null
            : round((float)$row['reviewed_case_score'], 2);
        return [
            'id' => (int)$row['id'],
            'case_id' => (int)$row['case_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'manager_user_id' => (int)$row['manager_user_id'],
            'review_outcome' => (string)$row['review_outcome'],
            'reason' => (string)$row['reason'],
            'dimension_overrides' => $this->decodeJson((string)$row['dimension_overrides_json']),
            'source_score_digest' => (string)$row['source_score_digest'],
            'source_kind' => (string)$row['source_kind'],
            'source_quality_status' => (string)$row['source_quality_status'],
            'input_digest' => (string)$row['input_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'score_snapshot' => [
                'scoring_version' => self::FORMULA_VERSION,
                'source_reference_key' => self::SOURCE_REFERENCE_KEY,
                'source_fingerprint' => self::SOURCE_FINGERPRINT,
                'dimensions' => $this->decodeJson((string)$row['reviewed_dimensions_json']),
                'case_score' => $caseScore,
                'scored_dimension_count' => (int)$row['scored_dimension_count'],
                'score_status' => (string)$row['score_status'],
                'evidence_digest' => (string)$row['evidence_digest'],
                'scored_at' => (string)$row['created_at'],
                'review_kind' => 'human_score_review',
                'review_outcome' => (string)$row['review_outcome'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicFollowup(array $row): array
    {
        $caseScore = $row['case_score'] === null || $row['case_score'] === ''
            ? null
            : round((float)$row['case_score'], 2);

        return [
            'id' => (int)$row['id'],
            'case_id' => (int)$row['case_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'manager_user_id' => (int)$row['manager_user_id'],
            'followup_date' => (string)$row['followup_date'],
            'followup_outcome' => (string)$row['followup_outcome'],
            'verification_text' => (string)$row['verification_text'],
            'sample_count' => (int)$row['sample_count'],
            'evidence_reference' => $row['evidence_reference'] ?: null,
            'evidence' => $this->publicEvidence(
                $row['evidence_type'] ?? null,
                $row['evidence_reference'] ?? null,
                $row['evidence_date'] ?? null,
                $row['evidence_confidence'] ?? 'unverified'
            ),
            'next_followup_date' => $row['next_followup_date'] ?: null,
            'recurrence_problem_facts' => $row['recurrence_problem_facts'] ?: null,
            'recurrence_action_taken' => $row['recurrence_action_taken'] ?: null,
            'recurrence_verification_plan' => $row['recurrence_verification_plan'] ?: null,
            'linked_recurrence_case_id' => (int)($row['linked_recurrence_case_id'] ?? 0) ?: null,
            'source_kind' => (string)$row['source_kind'],
            'source_quality_status' => (string)$row['source_quality_status'],
            'input_digest' => (string)$row['input_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'score_snapshot' => [
                'scoring_version' => (string)($row['scoring_version'] ?? ''),
                'source_reference_key' => (string)($row['source_reference_key'] ?? ''),
                'source_fingerprint' => (string)($row['source_fingerprint'] ?? ''),
                'dimensions' => $this->decodeJson((string)($row['dimensions_json'] ?? '[]')),
                'case_score' => $caseScore,
                'scored_dimension_count' => (int)($row['scored_dimension_count'] ?? 0),
                'score_status' => (string)($row['score_status'] ?? 'data_insufficient'),
                'evidence_digest' => (string)($row['evidence_digest'] ?? ''),
                'scored_at' => (string)($row['created_at'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function initialScoreSnapshot(array $row): array
    {
        $caseScore = $row['case_score'] === null || $row['case_score'] === ''
            ? null
            : round((float)$row['case_score'], 2);

        return [
            'scoring_version' => (string)($row['formula_version'] ?? ''),
            'source_reference_key' => (string)($row['source_reference_key'] ?? ''),
            'source_fingerprint' => (string)($row['source_fingerprint'] ?? ''),
            'dimensions' => $this->decodeJson((string)($row['dimensions_json'] ?? '[]')),
            'case_score' => $caseScore,
            'scored_dimension_count' => (int)($row['scored_dimension_count'] ?? 0),
            'score_status' => (string)($row['score_status'] ?? 'data_insufficient'),
            'evidence_digest' => (string)($row['evidence_digest'] ?? ''),
            'scored_at' => (string)($row['scored_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $followups
     * @param array<int, array<string, mixed>> $adjustments
     * @param array<int, array<string, mixed>> $reviews
     * @return array<string, mixed>
     */
    private function publicCase(
        array $row,
        array $followups = [],
        array $adjustments = [],
        array $reviews = []
    ): array {
        $initialScoreSnapshot = $this->initialScoreSnapshot($row);
        $latestFollowup = $followups === [] ? null : $followups[array_key_last($followups)];
        $latestAdjustment = $adjustments === [] ? null : $adjustments[array_key_last($adjustments)];
        $latestReview = $reviews === [] ? null : $reviews[array_key_last($reviews)];
        $projection = is_array($latestAdjustment['effective_case'] ?? null)
            ? $latestAdjustment['effective_case']
            : [];
        $isVoided = (bool)($latestAdjustment['is_voided'] ?? false);

        $scoreCandidates = [[
            'at' => (string)($initialScoreSnapshot['scored_at'] ?? $row['created_at'] ?? ''),
            'priority' => 0,
            'kind' => 'initial',
            'snapshot' => $initialScoreSnapshot,
        ]];
        foreach ([
            ['event' => $latestFollowup, 'priority' => 1, 'kind' => 'followup'],
            ['event' => $latestAdjustment, 'priority' => 2, 'kind' => 'adjustment'],
            ['event' => $latestReview, 'priority' => 3, 'kind' => 'human_review'],
        ] as $candidate) {
            if (is_array($candidate['event']) && is_array($candidate['event']['score_snapshot'] ?? null)) {
                $scoreCandidates[] = [
                    'at' => (string)($candidate['event']['created_at'] ?? ''),
                    'priority' => (int)$candidate['priority'],
                    'kind' => (string)$candidate['kind'],
                    'snapshot' => $candidate['event']['score_snapshot'],
                ];
            }
        }
        usort($scoreCandidates, static function (array $left, array $right): int {
            $timeCompare = strcmp((string)$left['at'], (string)$right['at']);
            return $timeCompare !== 0 ? $timeCompare : ((int)$left['priority'] <=> (int)$right['priority']);
        });
        $selectedScore = $scoreCandidates[array_key_last($scoreCandidates)];
        $effectiveScoreSnapshot = $selectedScore['snapshot'];
        if ($isVoided) {
            $effectiveScoreSnapshot['case_score'] = null;
            $effectiveScoreSnapshot['score_status'] = 'voided';
        }

        $businessDate = (string)($projection['business_date'] ?? $row['business_date']);
        $problemFacts = (string)($projection['problem_facts'] ?? $row['problem_facts']);
        $actionTaken = (string)($projection['action_taken'] ?? $row['action_taken']);
        $verificationStatus = (string)($projection['verification_status'] ?? $row['verification_status']);
        $verificationText = (string)($projection['verification_text'] ?? $row['verification_text']);
        $followupDueDate = $projection['followup_due_date'] ?? ($row['followup_due_date'] ?: null);
        $initialCaseStatus = (string)($row['verification_status'] ?? '') === 'observed_result'
            ? 'closed'
            : 'pending_verification';
        $projectedCaseStatus = (string)($projection['case_status'] ?? $initialCaseStatus);
        $latestAdjustmentAt = (string)($latestAdjustment['created_at'] ?? '');
        $latestFollowupAt = (string)($latestFollowup['created_at'] ?? '');
        $followupApplies = is_array($latestFollowup) && ($latestAdjustmentAt === '' || $latestFollowupAt > $latestAdjustmentAt);
        $caseStatus = $projectedCaseStatus;
        $currentFollowupDueDate = $followupDueDate;
        if ($followupApplies) {
            $outcome = (string)($latestFollowup['followup_outcome'] ?? '');
            $caseStatus = match ($outcome) {
                'resolved' => 'closed',
                'recurred' => 'recurred',
                default => 'pending_verification',
            };
            $currentFollowupDueDate = $outcome === 'still_open'
                ? ($latestFollowup['next_followup_date'] ?? null)
                : null;
        }
        if ($isVoided) {
            $caseStatus = 'voided';
            $currentFollowupDueDate = null;
        }

        $evidence = $this->publicEvidence(
            $projection['evidence_type'] ?? ($row['evidence_type'] ?? null),
            $projection['evidence_reference'] ?? ($row['evidence_reference'] ?? null),
            $projection['evidence_date'] ?? ($row['evidence_date'] ?? null),
            $projection['evidence_confidence'] ?? ($row['evidence_confidence'] ?? 'unverified')
        );

        return [
            'id' => (int)$row['id'],
            'parent_case_id' => (int)($row['parent_case_id'] ?? 0) ?: null,
            'origin_followup_id' => (int)($row['origin_followup_id'] ?? 0) ?: null,
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'manager_user_id' => (int)$row['manager_user_id'],
            'manager_name' => (string)$row['manager_name_snapshot'],
            'business_date' => $businessDate,
            'problem_facts' => $problemFacts,
            'action_taken' => $actionTaken,
            'verification_status' => $verificationStatus,
            'verification_text' => $verificationText,
            'followup_due_date' => $followupDueDate,
            'current_followup_due_date' => $currentFollowupDueDate,
            'case_status' => $caseStatus,
            'is_voided' => $isVoided,
            'evidence' => $evidence,
            'source_kind' => (string)$row['source_kind'],
            'source_quality_status' => (string)$row['source_quality_status'],
            'input_digest' => (string)$row['input_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'original_case' => [
                'business_date' => (string)$row['business_date'],
                'problem_facts' => (string)$row['problem_facts'],
                'action_taken' => (string)$row['action_taken'],
                'verification_status' => (string)$row['verification_status'],
                'verification_text' => (string)$row['verification_text'],
                'followup_due_date' => $row['followup_due_date'] ?: null,
                'evidence' => $this->publicEvidence(
                    $row['evidence_type'] ?? null,
                    $row['evidence_reference'] ?? null,
                    $row['evidence_date'] ?? null,
                    $row['evidence_confidence'] ?? 'unverified'
                ),
            ],
            'initial_score_snapshot' => $initialScoreSnapshot,
            'score_snapshot' => $effectiveScoreSnapshot,
            'score_source' => (string)$selectedScore['kind'],
            'followups' => array_values($followups),
            'latest_followup' => $latestFollowup,
            'adjustments' => array_values($adjustments),
            'latest_adjustment' => $latestAdjustment,
            'score_reviews' => array_values($reviews),
            'latest_score_review' => $latestReview,
        ];
    }

    /**
     * @param array<int, string> $evidenceRefs
     * @param array<int, string> $reasons
     * @return array<string, mixed>
     */
    private function dimension(string $key, ?int $score, array $evidenceRefs, array $reasons): array
    {
        return [
            'key' => $key,
            'label' => self::DIMENSION_LABELS[$key],
            'score' => $score,
            'status' => $score === null ? 'not_observed' : 'scored',
            'level' => $score === null ? 'unknown' : ($score >= 90 ? 'positive' : ($score >= 75 ? 'normal' : 'improve')),
            'level_label' => $score === null ? '未观察' : $this->scoreLevelLabel($score),
            'evidence_refs' => $evidenceRefs,
            'reasons' => $reasons,
            'rubric' => self::DIMENSION_RUBRICS[$key] ?? [],
            'source_quality_status' => 'manual_declared',
        ];
    }

    /** @return array<string, mixed> */
    private function publicEvidence(mixed $type, mixed $reference, mixed $date, mixed $confidence): array
    {
        $typeValue = trim((string)$type);
        $confidenceValue = trim((string)$confidence);
        if (!in_array($confidenceValue, ['high', 'medium', 'unverified'], true)) {
            $confidenceValue = 'unverified';
        }

        return [
            'type' => $typeValue !== '' ? $typeValue : null,
            'type_label' => self::EVIDENCE_TYPE_LABELS[$typeValue] ?? null,
            'reference' => trim((string)$reference) !== '' ? (string)$reference : null,
            'date' => trim((string)$date) !== '' ? (string)$date : null,
            'confidence' => $confidenceValue,
            'confidence_label' => match ($confidenceValue) {
                'high' => '较高',
                'medium' => '一般',
                default => '未核验',
            },
            'truth_boundary' => '证据元数据完整度，不改变能力分，也不代表系统已核验事实',
        ];
    }

    private function scoreLevelLabel(float $score): string
    {
        if ($score >= 85) {
            return '证据充分';
        }
        if ($score >= 65) {
            return '基本成立';
        }
        return '需补强';
    }

    private function profileLevelLabel(float $score): string
    {
        if ($score >= 85) {
            return '较强';
        }
        if ($score >= 70) {
            return '稳定';
        }
        return '待提升';
    }

    /** @param array<int, array<int, string>> $groups */
    private function cueGroupCount(string $text, array $groups): int
    {
        $count = 0;
        foreach ($groups as $keywords) {
            if ($this->containsAny($text, $keywords)) {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<int, string> $keywords */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }
            $found = function_exists('mb_stripos')
                ? mb_stripos($text, $keyword, 0, 'UTF-8')
                : stripos($text, $keyword);
            if ($found !== false) {
                return true;
            }
        }
        return false;
    }

    private function hasNumber(string $text): bool
    {
        return preg_match('/\d+(?:\.\d+)?/u', $text) === 1;
    }

    private function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function requiredText(mixed $value, string $label, int $minLength, int $maxLength): string
    {
        $text = trim((string)$value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $length = $this->textLength($text);
        if ($length < $minLength) {
            throw new InvalidArgumentException($label . '至少需要' . $minLength . '个字符');
        }
        if ($length > $maxLength) {
            throw new InvalidArgumentException($label . '不能超过' . $maxLength . '个字符');
        }
        return $text;
    }

    private function optionalText(mixed $value, string $label, int $maxLength): ?string
    {
        $text = trim((string)$value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        if ($text === '') {
            return null;
        }
        if ($this->textLength($text) > $maxLength) {
            throw new InvalidArgumentException($label . '不能超过' . $maxLength . '个字符');
        }
        return $text;
    }

    private function validDate(string $date, string $label): string
    {
        $date = trim($date);
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException($label . '必须是有效的YYYY-MM-DD日期');
        }
        return $date;
    }

    /**
     * Execute one append-only idempotent write with a bounded retry budget.
     *
     * A concurrent winner may commit between the initial lookup and insert.
     * In that case the unique-key violation is converted to an exact replay
     * only after the committed row is read and its full input digest matches.
     * Deadlocks and lock-wait timeouts rerun the whole transaction at most
     * three times; validation, domain, and unrelated database failures retain
     * their original exception and fail closed.
     *
     * @param callable(): array<string, mixed> $transactionCallback
     * @param callable(): ?array<string, mixed> $findExisting
     * @param callable(array<string, mixed>): array<string, mixed> $replayExisting
     * @param null|callable(callable(): array<string, mixed>): array<string, mixed> $transactionRunner
     * @return array<string, mixed>
     */
    private function runIdempotentWrite(
        callable $transactionCallback,
        callable $findExisting,
        callable $replayExisting,
        ?callable $transactionRunner = null
    ): array {
        $runTransaction = $transactionRunner
            ?? static fn(callable $callback): array => Db::transaction($callback);
        $lastError = null;
        for ($attempt = 1; $attempt <= self::IDEMPOTENT_WRITE_MAX_ATTEMPTS; $attempt++) {
            try {
                return $runTransaction($transactionCallback);
            } catch (\Throwable $error) {
                $lastError = $error;
                if ($this->isDuplicateKeyConflict($error)) {
                    try {
                        $existing = $findExisting();
                    } catch (\Throwable $lookupError) {
                        if ($attempt >= self::IDEMPOTENT_WRITE_MAX_ATTEMPTS
                            || !$this->isRetryableWriteConflict($lookupError)
                        ) {
                            throw $lookupError;
                        }
                        $lastError = $lookupError;
                        $existing = null;
                    }
                    if (is_array($existing)) {
                        return $replayExisting($existing);
                    }
                }
                if ($attempt >= self::IDEMPOTENT_WRITE_MAX_ATTEMPTS
                    || !$this->isRetryableWriteConflict($lastError)
                ) {
                    throw $lastError;
                }
                usleep(self::IDEMPOTENT_WRITE_RETRY_DELAY_MICROSECONDS * $attempt);
            }
        }

        throw $lastError ?? new RuntimeException('店长能力幂等写入失败');
    }

    /** @return ?array<string, mixed> */
    private function findIdempotentWrite(
        string $table,
        int $tenantId,
        int $actorUserId,
        string $idempotencyKey
    ): ?array {
        if (!in_array($table, [
            self::CASE_TABLE,
            self::FOLLOWUP_TABLE,
            self::ADJUSTMENT_TABLE,
            self::REVIEW_TABLE,
        ], true)) {
            throw new InvalidArgumentException('店长能力幂等回读表无效');
        }

        $row = Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('created_by', $actorUserId)
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        return is_array($row) ? $row : null;
    }

    private function isDuplicateKeyConflict(\Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $code = (string)$current->getCode();
            $message = strtolower($current->getMessage());
            if ($code === '1062'
                || str_contains($message, 'duplicate entry')
                || (str_contains($message, '1062') && str_contains($message, 'duplicate'))
            ) {
                return true;
            }
        }
        return false;
    }

    private function isRetryableWriteConflict(\Throwable $error): bool
    {
        if ($this->isDuplicateKeyConflict($error)) {
            return true;
        }
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $code = (string)$current->getCode();
            $message = strtolower($current->getMessage());
            if ($code === '40001'
                || $code === '1213'
                || $code === '1205'
                || str_contains($message, 'deadlock found')
                || str_contains($message, 'lock wait timeout')
                || str_contains($message, 'serialization failure')
            ) {
                return true;
            }
        }
        return false;
    }

    private function assertSchemaReady(): void
    {
        foreach ([self::CASE_TABLE, self::SCORE_TABLE, self::FOLLOWUP_TABLE, self::ADJUSTMENT_TABLE, self::REVIEW_TABLE] as $table) {
            try {
                Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
            } catch (\Throwable) {
                throw new RuntimeException('店长能力评分数据表未就绪，请先执行数据库迁移');
            }
        }
    }

    private function assertPositiveScope(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('租户和酒店范围无效');
        }
    }

    /** @param array<string, mixed>|array<int, mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->encodeJson($value));
    }

    /**
     * Digest every append-only event that can change the effective case or
     * score. This is deliberately separate from the immutable source-case
     * digest: it is a compare-and-swap token for concurrent human writes.
     *
     * @param array<string, mixed> $case
     */
    private function mutableCaseDigest(array $case): string
    {
        $latestFollowup = is_array($case['latest_followup'] ?? null)
            ? $case['latest_followup']
            : [];
        $latestAdjustment = is_array($case['latest_adjustment'] ?? null)
            ? $case['latest_adjustment']
            : [];
        $latestReview = is_array($case['latest_score_review'] ?? null)
            ? $case['latest_score_review']
            : [];

        return $this->digest([
            'case_id' => (int)($case['id'] ?? 0),
            'case_status' => (string)($case['case_status'] ?? ''),
            'is_voided' => ($case['is_voided'] ?? false) === true,
            'current_followup_due_date' => $case['current_followup_due_date'] ?? null,
            'score_evidence_digest' => strtolower((string)(
                $case['score_snapshot']['evidence_digest'] ?? ''
            )),
            'latest_followup_id' => (int)($latestFollowup['id'] ?? 0),
            'latest_followup_evidence_digest' => strtolower((string)(
                $latestFollowup['score_snapshot']['evidence_digest']
                    ?? $latestFollowup['evidence_digest']
                    ?? ''
            )),
            'latest_adjustment_id' => (int)($latestAdjustment['id'] ?? 0),
            'latest_adjustment_evidence_digest' => strtolower((string)(
                $latestAdjustment['score_snapshot']['evidence_digest']
                    ?? $latestAdjustment['evidence_digest']
                    ?? ''
            )),
            'latest_review_id' => (int)($latestReview['id'] ?? 0),
            'latest_review_evidence_digest' => strtolower((string)(
                $latestReview['score_snapshot']['evidence_digest']
                    ?? $latestReview['evidence_digest']
                    ?? ''
            )),
        ]);
    }

    private function encodeJson(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<int|string, mixed> */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    private function nowPrecise(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s.u');
    }
}
