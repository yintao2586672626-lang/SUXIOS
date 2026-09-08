<?php
declare(strict_types=1);

namespace app\service;

/** Allowlisted diagnostics. Never persist exception messages, SQL, URLs or upstream bodies. */
final class FailureEvidenceService
{
    private const REASONS = [
        'missing_resource_id', 'invalid_manual_fetch_request', 'ota_manual_execution_failed',
        'meituan_request_failed', 'meituan_api_error', 'ctrip_request_failed', 'ctrip_api_error', 'meituan_config_metadata_unavailable',
        'meituan_config_locator_mismatch', 'meituan_platform_identity_invalid',
        'ctrip_traffic_request_failed', 'ctrip_traffic_api_rejected', 'ctrip_hotel_identity_blocked',
        'hotel_identity_mismatch', 'hotel_identity_missing', 'identity_mismatch', 'identity_missing',
        'returned_current_hotel_id_missing', 'configured_platform_hotel_id_mismatch',
        'response_business_date_unverified', 'response_business_date_missing', 'response_business_date_mismatch', 'response_business_date_ambiguous',
        'platform_hotel_id_incomplete', 'captured_platform_hotel_id_ambiguous', 'platform_hotel_conflict',
        'platform_hotel_unbound', 'platform_hotel_ambiguous', 'data_source_scope_missing',
        'ota_profile_binding_blocked', 'resource_busy_login', 'hotel_identity_unverified',
        'missing_patrol_snapshot', 'module_not_entitled', 'hotel_permission_denied', 'role_permission_denied',
        'tenant_context_missing', 'tenant_context_mismatch', 'rate_limit_exceeded',
        'login_expired', 'login_required', 'cookie_expired', 'credential_missing',
        'upstream_redirect', 'upstream_unauthorized', 'upstream_forbidden', 'upstream_html',
        'upstream_rate_limited', 'upstream_http_error', 'upstream_transport_failed', 'upstream_invalid_json',
        'config_legacy_migration_required', 'config_metadata_invalid', 'config_scope_conflict',
        'database_schema_unavailable', 'database_write_conflict', 'database_unavailable',
        'config_save_failed', 'notification_write_failed',
        'not_persisted', 'readback_failed', 'target_date_unverified',
    ];
    private const STAGES = ['request_validation', 'read_metadata', 'validate_existing_metadata',
        'metadata_lock', 'credential_store', 'metadata_write', 'config_save', 'after_save',
        'upstream_request', 'upstream_snapshot', 'identity_validation', 'date_validation', 'persistence',
        'notification_create', 'notification_user_state', 'platform_execution', 'result_inspection', 'authorization', 'credential', 'unknown', 'response'];

    public static function fromResponse(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $saveStatus = $data['save_status'] ?? $data['persistence_status'] ?? '';
        $reason = $data['reason'] ?? $data['reason_code'] ?? $data['redacted_reason'] ?? $data['status_code']
            ?? (in_array($saveStatus, ['not_persisted', 'readback_failed', 'target_date_unverified'], true) ? $saveStatus : '');
        $stage = $data['stage'] ?? ($saveStatus === 'target_date_unverified' ? 'date_validation'
            : (in_array($saveStatus, ['not_persisted', 'readback_failed'], true) ? 'persistence' : 'response'));
        $result = [
            'reason_code' => is_string($reason) && in_array($reason, self::REASONS, true) ? $reason : 'unclassified_response_failure',
            'failure_stage' => in_array($stage, self::STAGES, true) ? $stage : 'response',
        ];
        $http = $data['http_code'] ?? null;
        if (is_int($http) && $http >= 100 && $http <= 599) $result['upstream_http_status'] = $http;
        $businessCode = $data['business_code'] ?? null;
        if (in_array($businessCode, [-1, 303, 400, 401, 403, 429, 500, '-1', '303', '400', '401', '403', '429', '500'], true)) $result['upstream_business_code'] = (int)$businessCode;
        if (in_array($saveStatus, ['blocked', 'failed', 'not_saved', 'partial', 'target_date_unverified', 'not_persisted', 'readback_failed'], true)) $result['save_status'] = $saveStatus;
        return $result;
    }

    /** Safe API payload for an upstream failure; the original result never escapes. */
    public static function upstreamResponseData(array $result, string $fallbackReason): array
    {
        $evidence = self::fromResponse(['data' => array_replace([
            'reason' => $fallbackReason, 'stage' => 'upstream_request',
        ], $result)]);
        $data = ['reason' => $evidence['reason_code'], 'stage' => $evidence['failure_stage']];
        foreach (['upstream_http_status' => 'http_code', 'upstream_business_code' => 'business_code'] as $source => $target) {
            if (isset($evidence[$source])) $data[$target] = $evidence[$source];
        }
        if (in_array($result['credential_status'] ?? '', ['login_required', 'api_error'], true)) $data['credential_status'] = $result['credential_status'];
        return $data;
    }

    public static function fromException(\Throwable $error, string $stage = 'config_save'): array
    {
        $reason = 'config_save_failed';
        $status = 500;
        $message = '保存未完成，请保留问题编号供管理员排查';
        $known = [
            'Legacy Meituan plaintext credential requires Task6 migration; normal save cannot read or migrate it.' => 'config_legacy_migration_required',
            'Stored Meituan protected review scope metadata blocks normal save.' => 'config_legacy_migration_required',
            'Stored Meituan config list is invalid.' => 'config_metadata_invalid',
            'Stored Meituan sibling config is invalid.' => 'config_metadata_invalid',
            'Stored Meituan sibling config id is invalid.' => 'config_metadata_invalid',
            'Meituan config scope changed during save.' => 'config_scope_conflict',
            'Meituan config does not exist.' => 'config_scope_conflict',
            'Meituan config already exists.' => 'config_scope_conflict',
        ];
        $reason = $known[$error->getMessage()] ?? $reason;
        if ($reason === 'config_legacy_migration_required') {
            $status = 409;
            $message = '历史配置需要管理员完成迁移后才能保存；本次未修改配置，请勿反复提交';
        } elseif ($reason === 'config_metadata_invalid' || $reason === 'config_scope_conflict') {
            $status = 409;
            $message = '配置记录存在冲突或格式异常；请刷新核对，仍失败时联系管理员修复';
        }
        $code = (string)$error->getCode();
        if ($error instanceof \PDOException || $error instanceof \think\db\exception\PDOException) {
            $code = $error instanceof \PDOException ? (string)($error->errorInfo[0] ?? $code)
                : (string)($error->getData()['PDO Error Info']['SQLSTATE'] ?? $code);
            $reason = in_array($code, ['42S02', '42S22'], true) ? 'database_schema_unavailable'
                : (in_array($code, ['23000', '40001'], true) ? 'database_write_conflict' : 'database_unavailable');
        }
        return ['reason' => $reason, 'stage' => in_array($stage, self::STAGES, true) ? $stage : 'config_save',
            'status' => $status, 'message' => $message];
    }
}
