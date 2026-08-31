<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Builds a read-only recovery view from already-collected operating evidence.
 *
 * This service deliberately has no database, browser, credential, messaging,
 * approval or OTA/PMS write dependency. Callers remain responsible for reading
 * and redacting source evidence before passing it here.
 */
final class OperatingBlockerRecoveryService
{
    public const CONTRACT_VERSION = 'operating_blocker_recovery.v1';
    public const RESUME_CONTRACT_VERSION = 'operating_blocker_resume.v1';

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'database' => '数据库',
        'worker' => '运行 worker',
        'pms' => '主 PMS',
        'ctrip' => '携程',
        'meituan' => '美团',
        'wecom' => '企业微信',
        'unknown' => '未知来源',
    ];

    /** @var array<string, string> */
    private const SOURCE_ALIASES = [
        'database' => 'database',
        'db' => 'database',
        'mysql' => 'database',
        'mariadb' => 'database',
        'worker' => 'worker',
        'workers' => 'worker',
        'php_worker' => 'worker',
        'runtime_worker' => 'worker',
        'pms' => 'pms',
        'dingdandao' => 'pms',
        'dingdandao_pms' => 'pms',
        'meituan_cloud_pms' => 'pms',
        'ctrip' => 'ctrip',
        '携程' => 'ctrip',
        'meituan' => 'meituan',
        '美团' => 'meituan',
        'wecom' => 'wecom',
        'wechat_work' => 'wecom',
        'wechat_robot' => 'wecom',
        '企业微信' => 'wecom',
    ];

    /** @var array<string, string> */
    private const CATEGORY_LABELS = [
        'human_login_verification' => '需要人工登录或验证',
        'config_binding' => '配置或绑定缺失',
        'retryable_runtime' => '可重试运行时故障',
        'data_missing' => '目标范围数据缺失',
        'permission' => '权限或范围不匹配',
        'external_authorization' => '等待外部动作授权',
        'unknown' => '阻塞证据不完整',
    ];

    /** @var array<string, int> */
    private const IMPACT_RANK = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'unknown' => 1,
    ];

    /** @var array<string, int> */
    private const BLOCKING_SCOPE_RANK = [
        'system' => 4,
        'hotel' => 3,
        'source' => 2,
        'unknown' => 1,
    ];

    /** @var array<string, int> */
    private const EVIDENCE_QUALITY_RANK = [
        'verified' => 4,
        'partial' => 3,
        'unknown' => 2,
        'invalid_scope' => 1,
    ];

    /** @var array<string, int> */
    private const CATEGORY_RANK = [
        'permission' => 7,
        'human_login_verification' => 6,
        'config_binding' => 5,
        'retryable_runtime' => 4,
        'data_missing' => 3,
        'external_authorization' => 2,
        'unknown' => 1,
    ];

    /** @var array<string, int> */
    private const SOURCE_RANK = [
        'database' => 7,
        'worker' => 6,
        'pms' => 5,
        'ctrip' => 4,
        'meituan' => 3,
        'wecom' => 2,
        'unknown' => 1,
    ];

    /** @var array<int, string> */
    private const READY_CODES = [
        'active',
        'available',
        'configured',
        'healthy',
        'ok',
        'readback_verified',
        'ready',
        'sent',
        'success',
        'succeeded',
        'verified',
    ];

    /** @var array<int, string> */
    private const PROHIBITED_ACTIONS = [
        'read_or_store_credentials',
        'automatic_login_or_verification_bypass',
        'automatic_approval',
        'automatic_external_send',
        'ota_or_pms_write',
    ];

    /**
     * @param array<string, mixed> $scope Required: tenant_id, hotel_id, business_date.
     * @param array<int|string, mixed> $evidence List rows or rows keyed by source.
     * @return array<string, mixed>
     */
    public function build(array $scope, array $evidence): array
    {
        $requestedScope = $this->requestedScope($scope);
        $rows = $this->evidenceRows($evidence);
        $itemsById = [];
        $nonBlockingEvidenceCount = 0;

        foreach ($rows as $row) {
            $item = $this->normalizeBlocker($requestedScope, $row);
            if ($item === null) {
                $nonBlockingEvidenceCount++;
                continue;
            }

            $id = (string)$item['blocker_id'];
            if (!isset($itemsById[$id]) || $this->compareItems($item, $itemsById[$id]) < 0) {
                $itemsById[$id] = $item;
            }
        }

        $items = array_values($itemsById);
        usort($items, fn(array $left, array $right): int => $this->compareItems($left, $right));

        $selectedId = $items === [] ? null : (string)$items[0]['blocker_id'];
        foreach ($items as &$item) {
            $item['selected'] = $selectedId !== null
                && hash_equals($selectedId, (string)$item['blocker_id']);
        }
        unset($item);

        $selected = $items === [] ? null : $items[0];

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'scope' => $requestedScope,
            'status' => $items === []
                ? ($rows === [] ? 'evidence_missing' : 'no_blocker_observed')
                : 'blocked',
            'recovery_status' => 'not_attempted',
            'evidence_count' => count($rows),
            'non_blocking_evidence_count' => $nonBlockingEvidenceCount,
            'blocker_count' => count($items),
            'selected_count' => $selected === null ? 0 : 1,
            'selected' => $selected,
            'items' => $items,
            'execution_mode' => 'instruction_only',
            'resume_executor_status' => 'not_wired',
            'resume_endpoint' => null,
            'selection_policy' => [
                'purpose' => 'attention_priority_only',
                'order' => [
                    'business_impact',
                    'blocking_scope',
                    'prerequisite',
                    'evidence_quality',
                    'source',
                    'reason_code',
                    'blocker_id',
                ],
                'monetary_value_claimed' => false,
                'causality_claimed' => false,
            ],
            'safety' => [
                'read_only' => true,
                'automatic_recovery' => false,
                'credentials_accessed' => false,
                'external_actions_executed' => false,
                'writes_executed' => false,
                'prohibited_actions' => self::PROHIBITED_ACTIONS,
            ],
        ];
    }

    /** @param array<string, mixed> $scope @return array<string, int|string> */
    private function requestedScope(array $scope): array
    {
        $tenantId = (int)($scope['tenant_id'] ?? 0);
        $hotelId = (int)($scope['hotel_id'] ?? 0);
        $businessDate = trim((string)($scope['business_date'] ?? ''));

        if ($tenantId <= 0) {
            throw new InvalidArgumentException('operating_blocker_tenant_invalid');
        }
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('operating_blocker_hotel_invalid');
        }
        if (!$this->isDate($businessDate)) {
            throw new InvalidArgumentException('operating_blocker_business_date_invalid');
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
        ];
    }

    /**
     * @param array<int|string, mixed> $evidence
     * @return array<int, array<string, mixed>>
     */
    private function evidenceRows(array $evidence): array
    {
        $rows = [];
        foreach ($evidence as $sourceHint => $value) {
            if (!is_array($value)) {
                continue;
            }

            $hint = is_string($sourceHint) ? trim($sourceHint) : '';
            if (!array_is_list($value) || $this->looksLikeEvidenceRow($value)) {
                $row = $value;
                if ($hint !== '' && !isset($row['source']) && !isset($row['platform']) && !isset($row['provider'])) {
                    $row['_source_hint'] = $hint;
                }
                $rows[] = $row;
                continue;
            }

            foreach ($value as $nested) {
                if (!is_array($nested)) {
                    continue;
                }
                if ($hint !== '' && !isset($nested['source']) && !isset($nested['platform']) && !isset($nested['provider'])) {
                    $nested['_source_hint'] = $hint;
                }
                $rows[] = $nested;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function looksLikeEvidenceRow(array $row): bool
    {
        foreach (['source', 'platform', 'provider', 'status', 'reason_code', 'code', 'error_code', 'blocking'] as $field) {
            if (array_key_exists($field, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|string> $requestedScope
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeBlocker(array $requestedScope, array $row): ?array
    {
        $source = $this->source($row);
        $statusCode = $this->machineCode($row['status'] ?? '');
        $reasonCode = $this->reasonCode($row, $statusCode);
        if (!$this->isBlockingEvidence($row, $statusCode, $reasonCode)) {
            return null;
        }

        $scopeEvidence = $this->scopeEvidence($requestedScope, $row, $source);
        $scopeStatus = (string)$scopeEvidence['status'];
        $category = $scopeStatus === 'mismatch'
            ? 'permission'
            : $this->category($reasonCode, $source);
        if ($scopeStatus === 'mismatch') {
            $reasonCode = 'evidence_scope_mismatch';
        }

        $evidenceQuality = $this->evidenceQuality($row, $scopeStatus, $statusCode);
        $businessImpact = $this->businessImpact($row, $source);
        $blockingScope = $this->blockingScope($source);
        $action = $this->actionContract($category, $requestedScope, $source);
        $blockerId = $this->blockerId(
            $requestedScope,
            $source,
            $category,
            $reasonCode
        );

        return [
            'blocker_id' => $blockerId,
            'scope' => [
                'tenant_id' => $requestedScope['tenant_id'],
                'hotel_id' => $requestedScope['hotel_id'],
                'business_date' => $requestedScope['business_date'],
                'source' => $source,
            ],
            'reported_scope' => $scopeEvidence['reported_scope'],
            'scope_status' => $scopeStatus,
            'source' => $source,
            'source_label' => self::SOURCE_LABELS[$source],
            'status' => 'blocked',
            'evidence_status' => $statusCode !== '' ? $statusCode : 'unknown',
            'evidence_quality' => $evidenceQuality,
            'category' => $category,
            'category_label' => self::CATEGORY_LABELS[$category],
            'reason_code' => $reasonCode,
            'reason' => $this->safeReason($category, $source, $scopeStatus),
            'business_impact' => $businessImpact,
            'blocking_scope' => $blockingScope,
            'actionable' => true,
            'next_action_code' => $action['next_action_code'],
            'next_action_actor' => $action['next_action_actor'],
            'next_action' => $action['next_action'],
            'resumable' => $action['resumable'],
            'resume_contract' => $action['resume_contract'],
            'evidence_ref' => $this->evidenceRef($row),
            'observed_at' => $this->dateTimeOrNull($row['observed_at'] ?? $row['checked_at'] ?? null),
            'selection_rank' => [
                'business_impact' => self::IMPACT_RANK[$businessImpact],
                'blocking_scope' => self::BLOCKING_SCOPE_RANK[$blockingScope],
                'prerequisite' => self::CATEGORY_RANK[$category],
                'evidence_quality' => self::EVIDENCE_QUALITY_RANK[$evidenceQuality],
                'category' => self::CATEGORY_RANK[$category],
                'source' => self::SOURCE_RANK[$source],
            ],
            'selected' => false,
        ];
    }

    /** @param array<string, mixed> $row */
    private function source(array $row): string
    {
        foreach (['source', 'platform', 'provider', '_source_hint'] as $field) {
            $raw = trim((string)($row[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            $key = strtolower(str_replace(['-', ' '], '_', $raw));
            return self::SOURCE_ALIASES[$key]
                ?? self::SOURCE_ALIASES[$raw]
                ?? 'unknown';
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $row */
    private function reasonCode(array $row, string $statusCode): string
    {
        foreach (['reason_code', 'code', 'error_code'] as $field) {
            $code = $this->machineCode($row[$field] ?? '');
            if ($code !== '') {
                return $code;
            }
        }

        return $statusCode !== '' ? $statusCode : 'unknown_reason';
    }

    /** @param array<string, mixed> $row */
    private function isBlockingEvidence(array $row, string $statusCode, string $reasonCode): bool
    {
        if (array_key_exists('blocking', $row)) {
            $explicit = filter_var($row['blocking'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        return !in_array($statusCode, self::READY_CODES, true)
            && !in_array($reasonCode, self::READY_CODES, true);
    }

    /**
     * @param array<string, int|string> $requestedScope
     * @param array<string, mixed> $row
     * @return array{status:string,reported_scope:array<string, int|string|null>}
     */
    private function scopeEvidence(array $requestedScope, array $row, string $source): array
    {
        $reportedTenantId = $this->positiveIntOrNull($row['tenant_id'] ?? null);
        $reportedHotelId = $this->positiveIntOrNull($row['hotel_id'] ?? $row['system_hotel_id'] ?? null);
        $reportedDateRaw = trim((string)($row['business_date'] ?? $row['data_date'] ?? ''));
        $reportedDate = $this->isDate($reportedDateRaw) ? $reportedDateRaw : null;

        $mismatch = ($reportedTenantId !== null && $reportedTenantId !== $requestedScope['tenant_id'])
            || ($reportedHotelId !== null && $reportedHotelId !== $requestedScope['hotel_id'])
            || ($reportedDate !== null && $reportedDate !== $requestedScope['business_date']);
        $complete = $reportedTenantId !== null
            && $reportedHotelId !== null
            && $reportedDate !== null
            && $source !== 'unknown';

        return [
            'status' => $mismatch ? 'mismatch' : ($complete ? 'matched' : 'inherited'),
            'reported_scope' => [
                'tenant_id' => $reportedTenantId,
                'hotel_id' => $reportedHotelId,
                'business_date' => $reportedDate,
                'source' => $source === 'unknown' ? null : $source,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function category(string $reasonCode, string $source): string
    {
        if ($this->containsAny($reasonCode, [
            'pending_approval',
            'approval_required',
            'authorization_required',
            'external_authorization',
            'send_authorization',
            'write_authorization',
            'user_authorization_required',
        ])) {
            return 'external_authorization';
        }
        if ($this->containsAny($reasonCode, [
            'permission',
            'forbidden',
            'access_denied',
            'scope_mismatch',
            'identity_mismatch',
            'tenant_mismatch',
            'hotel_mismatch',
            'cross_tenant',
            'unauthorized',
        ])) {
            return 'permission';
        }
        if ($this->containsAny($reasonCode, [
            'login',
            'authentication_required',
            'captcha',
            'credential_required',
            'human_verification',
            'verification_required',
            'verification_needed',
            'mfa_required',
            'passkey_required',
            'sms_required',
            'account_selection_required',
            'session_expired',
            'session_invalid',
            'profile_not_ready',
            'profile_expired',
        ])) {
            return 'human_login_verification';
        }
        if ($this->containsAny($reasonCode, [
            'binding',
            'configuration',
            'config_missing',
            'not_configured',
            'hotel_disabled',
            'plan_disabled',
            'mapping_missing',
            'robot_missing',
        ])) {
            return 'config_binding';
        }
        if (in_array($source, ['database', 'worker'], true) || $this->containsAny($reasonCode, [
            'timeout',
            'unavailable',
            'connection_failed',
            'network_error',
            'rate_limit',
            'read_failed',
            'service_failed',
            'runtime_failed',
            'worker_failed',
            'database_failed',
            'db_failed',
            'collection_failed',
            'dispatch_failed',
        ])) {
            return 'retryable_runtime';
        }
        if ($this->containsAny($reasonCode, [
            'data_missing',
            'rows_missing',
            'field_missing',
            'waiting_data',
            'stale',
            'partial',
            'unverified',
            'readback',
            'date_mismatch',
            'empty',
            'not_found',
            'missing',
        ])) {
            return 'data_missing';
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $row */
    private function evidenceQuality(array $row, string $scopeStatus, string $statusCode): string
    {
        if ($scopeStatus === 'mismatch') {
            return 'invalid_scope';
        }

        foreach (['evidence_quality', 'quality_status', 'validation_status', 'readback_status'] as $field) {
            $quality = $this->machineCode($row[$field] ?? '');
            if (in_array($quality, ['verified', 'readback_verified', 'matched'], true)) {
                return $scopeStatus === 'matched' ? 'verified' : 'partial';
            }
            if (in_array($quality, ['partial', 'stale', 'unverified', 'failed', 'error'], true)) {
                return 'partial';
            }
        }

        if (in_array($statusCode, ['verified', 'readback_verified'], true)) {
            return $scopeStatus === 'matched' ? 'verified' : 'partial';
        }

        return $scopeStatus === 'inherited' ? 'partial' : 'unknown';
    }

    /** @param array<string, mixed> $row */
    private function businessImpact(array $row, string $source): string
    {
        foreach (['business_impact', 'impact', 'severity'] as $field) {
            $value = $this->machineCode($row[$field] ?? '');
            if (array_key_exists($value, self::IMPACT_RANK)) {
                return $value;
            }
        }

        return match ($source) {
            'database', 'worker' => 'critical',
            'pms', 'ctrip', 'meituan' => 'high',
            'wecom' => 'medium',
            default => 'unknown',
        };
    }

    private function blockingScope(string $source): string
    {
        return match ($source) {
            'database', 'worker' => 'system',
            'pms' => 'hotel',
            'ctrip', 'meituan', 'wecom' => 'source',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, int|string> $scope
     * @return array<string, mixed>
     */
    private function actionContract(string $category, array $scope, string $source): array
    {
        $sourceLabel = self::SOURCE_LABELS[$source];
        $definition = 'true 表示前置证据满足后仅可续跑原同范围只读流程；false 表示必须由外部人工重新触发，系统不得自动继续。';

        $details = match ($category) {
            'human_login_verification' => [
                'next_action_code' => 'complete_human_login_or_verification',
                'next_action_actor' => 'authorized_human',
                'next_action' => "由用户在原设备完成{$sourceLabel}登录、账号选择或验证；不要提供密码、Cookie、验证码或恢复码。",
                'resumable' => true,
                'resume_mode' => 'after_human_session_verification',
                'required_evidence' => [
                    'human_step_completed_on_original_device',
                    'same_scope_session_ready',
                    'fresh_read_only_status_evidence',
                ],
                'resume_action' => 'rerun_original_read_only_check',
            ],
            'config_binding' => [
                'next_action_code' => 'verify_or_complete_scope_binding',
                'next_action_actor' => 'authorized_admin',
                'next_action' => "由有权限管理员核验并完成当前酒店的{$sourceLabel}配置或绑定；仅在同范围回读匹配后继续。",
                'resumable' => true,
                'resume_mode' => 'after_verified_binding_readback',
                'required_evidence' => [
                    'same_tenant_hotel_source_binding',
                    'binding_readback_matched',
                    'fresh_read_only_status_evidence',
                ],
                'resume_action' => 'rerun_original_read_only_check',
            ],
            'retryable_runtime' => [
                'next_action_code' => 'verify_runtime_then_retry_read_only',
                'next_action_actor' => 'system_operator',
                'next_action' => "先核验{$sourceLabel}健康与连接状态；恢复后只重试原同范围只读检查，不触发业务写入。",
                'resumable' => true,
                'resume_mode' => 'after_runtime_health_verified',
                'required_evidence' => [
                    'runtime_health_verified',
                    'same_scope_read_available',
                ],
                'resume_action' => 'retry_original_read_only_operation_once',
            ],
            'data_missing' => [
                'next_action_code' => 'obtain_same_scope_data_evidence',
                'next_action_actor' => 'data_operator',
                'next_action' => "补充并核验当前酒店、日期和{$sourceLabel}的只读数据证据；如需采集或保存，由外层流程另行确认授权。",
                'resumable' => true,
                'resume_mode' => 'after_same_scope_data_verified',
                'required_evidence' => [
                    'same_scope_source_evidence',
                    'target_date_matched',
                    'readback_or_source_verification_passed',
                ],
                'resume_action' => 'rerun_original_read_only_check',
            ],
            'permission' => [
                'next_action_code' => 'request_minimum_scope_permission',
                'next_action_actor' => 'authorized_admin',
                'next_action' => '由管理员核验并授予当前租户、酒店、日期和来源所需的最小权限；不得切换门店或复用其他范围证据。',
                'resumable' => false,
                'resume_mode' => 'new_human_controlled_continuation_required',
                'required_evidence' => [
                    'minimum_scope_permission_receipt',
                    'same_scope_access_readback',
                ],
                'resume_action' => 'reinvoke_after_permission_review',
            ],
            'external_authorization' => [
                'next_action_code' => 'wait_for_explicit_external_authorization',
                'next_action_actor' => 'user',
                'next_action' => '等待用户对具体外部动作主动授权；不得自动审批、发送消息或写入 OTA/PMS。',
                'resumable' => false,
                'resume_mode' => 'new_explicit_authorization_required',
                'required_evidence' => [
                    'explicit_action_specific_authorization',
                    'authorized_target_and_scope',
                ],
                'resume_action' => 'start_separately_authorized_external_workflow',
            ],
            default => [
                'next_action_code' => 'collect_redacted_read_only_evidence',
                'next_action_actor' => 'system_operator',
                'next_action' => "补充{$sourceLabel}的脱敏状态码、同范围身份和只读时间证据；在分类明确前不得执行恢复动作。",
                'resumable' => false,
                'resume_mode' => 'reclassification_required',
                'required_evidence' => [
                    'recognized_reason_code',
                    'same_scope_identity',
                    'fresh_read_only_status_evidence',
                ],
                'resume_action' => 'rebuild_recovery_contract_from_new_evidence',
            ],
        };

        return [
            'next_action_code' => $details['next_action_code'],
            'next_action_actor' => $details['next_action_actor'],
            'next_action' => $details['next_action'],
            'resumable' => $details['resumable'],
            'resume_contract' => [
                'contract_version' => self::RESUME_CONTRACT_VERSION,
                'resumable_definition' => $definition,
                'resume_mode' => $details['resume_mode'],
                'resume_scope' => [
                    'tenant_id' => $scope['tenant_id'],
                    'hotel_id' => $scope['hotel_id'],
                    'business_date' => $scope['business_date'],
                    'source' => $source,
                ],
                'resume_from' => 'original_read_only_workflow',
                'required_evidence' => $details['required_evidence'],
                'resume_action' => $details['resume_action'],
                'prohibited_actions' => self::PROHIBITED_ACTIONS,
            ],
        ];
    }

    private function safeReason(string $category, string $source, string $scopeStatus): string
    {
        if ($scopeStatus === 'mismatch') {
            return '证据范围与请求的租户、酒店或业务日期不一致；该证据不能证明当前范围状态。';
        }

        $sourceLabel = self::SOURCE_LABELS[$source];
        return match ($category) {
            'human_login_verification' => "{$sourceLabel}需要人工登录或验证，系统未尝试登录或绕过验证。",
            'config_binding' => "{$sourceLabel}配置或绑定尚未取得同范围回读证明。",
            'retryable_runtime' => "{$sourceLabel}存在运行时读取故障，尚未证明已经恢复。",
            'data_missing' => "{$sourceLabel}当前酒店与业务日期的数据证据不完整。",
            'permission' => "{$sourceLabel}权限或访问范围未通过核验。",
            'external_authorization' => "{$sourceLabel}相关外部动作尚未获得动作级明确授权。",
            default => "{$sourceLabel}只有未知或不完整的阻塞证据，不能判断已恢复。",
        };
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareItems(array $left, array $right): int
    {
        foreach (['business_impact', 'blocking_scope', 'prerequisite', 'evidence_quality', 'source'] as $key) {
            $leftRank = (int)($left['selection_rank'][$key] ?? 0);
            $rightRank = (int)($right['selection_rank'][$key] ?? 0);
            if ($leftRank !== $rightRank) {
                return $rightRank <=> $leftRank;
            }
        }

        return strcmp((string)($left['reason_code'] ?? ''), (string)($right['reason_code'] ?? ''))
            ?: strcmp((string)($left['blocker_id'] ?? ''), (string)($right['blocker_id'] ?? ''));
    }

    /** @param array<string, int|string> $scope */
    private function blockerId(
        array $scope,
        string $source,
        string $category,
        string $reasonCode
    ): string {
        $identity = implode('|', [
            (string)$scope['tenant_id'],
            (string)$scope['hotel_id'],
            (string)$scope['business_date'],
            $source,
            $category,
            $reasonCode,
        ]);

        return 'operating_blocker_' . substr(hash('sha256', $identity), 0, 24);
    }

    /** @param array<string, mixed> $row */
    private function evidenceRef(array $row): ?string
    {
        foreach (['evidence_ref', 'source_ref', 'receipt_ref'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && preg_match('/^[a-zA-Z0-9_.:#\/-]{1,160}$/D', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    private function machineCode(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 96), '_');
    }

    /** @param array<int, string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return null;
        }
        $number = (int)$value;
        return $number > 0 ? $number : null;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:sP'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $outputFormat = ltrim($format, '!');
            if ($date instanceof DateTimeImmutable && $date->format($outputFormat) === $value) {
                return $value;
            }
        }

        return null;
    }
}
