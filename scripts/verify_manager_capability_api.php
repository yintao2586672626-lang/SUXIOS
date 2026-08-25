#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\model\User;
use app\service\ManagerCapabilityScoringService;
use app\service\ProtectedCapabilityService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();
date_default_timezone_set('Asia/Shanghai');

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function managerCapabilityApiRequest(string $url, string $token, string $method = 'GET', ?array $payload = null): array
{
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $content = '';
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match) === 1) {
            $status = (int)$match[1];
            break;
        }
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

$baseUrl = rtrim(trim((string)(getenv('SUXIOS_LOCAL_BASE_URL') ?: 'http://127.0.0.1:8080')), '/');
$service = new ManagerCapabilityScoringService();
$protectedCapabilityService = new ProtectedCapabilityService();
$readCapability = $protectedCapabilityService->classifyPath('GET', 'api/operation/manager-capability/profile');
$errors = [];
$summary = [];
$token = 'verify_manager_capability_' . bin2hex(random_bytes(24));
$tokenStored = false;
$verifyPage = in_array('--page', $argv ?? [], true);

try {
    $scope = null;
    $users = User::where('status', User::STATUS_ENABLED)->order('id', 'asc')->select();
    foreach ($users as $user) {
        try {
            $candidateHotelIds = $user->getPermittedHotelIds();
        } catch (Throwable) {
            continue;
        }
        foreach ($candidateHotelIds as $candidateHotelId) {
            $hotelId = (int)$candidateHotelId;
            if ($hotelId <= 0
                || !$user->hasHotelPermission($hotelId, 'operation.view')
                || !$user->hasHotelPermission($hotelId, 'operation.execute')
            ) {
                continue;
            }
            if (!is_array($readCapability)
                || ($protectedCapabilityService->authorizeContext(
                    $user,
                    $readCapability,
                    ['hotel_id' => $hotelId]
                )['allowed'] ?? false) !== true
            ) {
                continue;
            }
            try {
                $tenantId = $service->hotelTenantId($hotelId);
                $managers = $service->listManagers($tenantId, $hotelId, (int)$user->id);
            } catch (Throwable) {
                continue;
            }
            if ($managers === []) {
                continue;
            }
            $scope = [
                'actor_user_id' => (int)$user->id,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => (int)$managers[0]['id'],
                'auth_version' => $user->authSessionVersion(),
            ];
            break 2;
        }
    }
    if (!is_array($scope)) {
        throw new RuntimeException('no_local_operation_execute_scope_available');
    }

    cache('token_' . $token, [
        'user_id' => (int)$scope['actor_user_id'],
        'created_at' => time(),
        'auth_version' => (string)$scope['auth_version'],
    ], 600);
    $tokenStored = is_array(cache('token_' . $token));
    if (!$tokenStored) {
        throw new RuntimeException('temporary_local_auth_token_not_stored');
    }

    $beforeCaseCount = (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)->count();
    $beforeFollowupCount = (int)Db::name(ManagerCapabilityScoringService::FOLLOWUP_TABLE)->count();
    $beforeAdjustmentCount = (int)Db::name(ManagerCapabilityScoringService::ADJUSTMENT_TABLE)->count();
    $beforeScoreReviewCount = (int)Db::name(ManagerCapabilityScoringService::REVIEW_TABLE)->count();
    $hotelId = (int)$scope['hotel_id'];
    $managerUserId = (int)$scope['manager_user_id'];

    $managerResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/managers?hotel_id=' . $hotelId,
        $token
    );
    if ($managerResponse['status'] !== 200
        || (int)($managerResponse['body']['code'] ?? 0) !== 200
        || (int)($managerResponse['body']['data']['hotel_id'] ?? 0) !== $hotelId
        || !is_array($managerResponse['body']['data']['list'] ?? null)
    ) {
        $errors[] = 'manager_list_http_contract_failed:' . $managerResponse['status'];
    }

    $profileResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/profile?hotel_id=' . $hotelId . '&manager_user_id=' . $managerUserId,
        $token
    );
    $dailySubmission = is_array($profileResponse['body']['data']['daily_submission'] ?? null)
        ? $profileResponse['body']['data']['daily_submission']
        : [];
    if ($profileResponse['status'] !== 200
        || (int)($profileResponse['body']['code'] ?? 0) !== 200
        || (int)($profileResponse['body']['data']['hotel_id'] ?? 0) !== $hotelId
        || (int)($profileResponse['body']['data']['manager_user_id'] ?? 0) !== $managerUserId
        || count((array)($profileResponse['body']['data']['dimensions'] ?? [])) !== 6
        || (string)($profileResponse['body']['data']['scoring_contract']['version'] ?? '') !== ManagerCapabilityScoringService::FORMULA_VERSION
        || (string)($dailySubmission['business_date'] ?? '') !== date('Y-m-d')
        || !in_array((string)($dailySubmission['status'] ?? ''), ['submitted', 'not_submitted'], true)
        || ($dailySubmission['closure_inferred'] ?? null) !== false
        || ($dailySubmission['independent_verification'] ?? null) !== false
    ) {
        $errors[] = 'profile_http_contract_failed:' . $profileResponse['status'];
    }

    $queueResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/followup-queue?hotel_id=' . $hotelId . '&manager_user_id=' . $managerUserId,
        $token
    );
    if ($queueResponse['status'] !== 200
        || (int)($queueResponse['body']['code'] ?? 0) !== 200
        || (int)($queueResponse['body']['data']['hotel_id'] ?? 0) !== $hotelId
        || (int)($queueResponse['body']['data']['manager_user_id'] ?? 0) !== $managerUserId
        || !is_array($queueResponse['body']['data']['rows'] ?? null)
        || !in_array((string)($queueResponse['body']['data']['data_status'] ?? ''), ['ready', 'empty'], true)
    ) {
        $errors[] = 'followup_queue_http_contract_failed:' . $queueResponse['status'];
    }

    $crossScopeResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/profile?hotel_id=' . $hotelId . '&manager_user_id=2147483647',
        $token
    );
    if ($crossScopeResponse['status'] !== 403
        || (int)($crossScopeResponse['body']['code'] ?? 0) !== 403
    ) {
        $errors[] = 'out_of_scope_manager_http_not_rejected:' . $crossScopeResponse['status'];
    }

    $invalidWriteResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/cases',
        $token,
        'POST',
        [
            'hotel_id' => $hotelId,
            'manager_user_id' => $managerUserId,
            'business_date' => date('Y-m-d'),
            'problem_facts' => '',
            'action_taken' => '',
            'verification_status' => 'observed_result',
            'verification_text' => '',
            'idempotency_key' => 'verify-invalid-' . bin2hex(random_bytes(8)),
        ]
    );
    $afterCaseCount = (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)->count();
    if ($invalidWriteResponse['status'] !== 422
        || (int)($invalidWriteResponse['body']['code'] ?? 0) !== 422
        || $afterCaseCount !== $beforeCaseCount
    ) {
        $errors[] = 'invalid_write_http_contract_failed:' . $invalidWriteResponse['status'];
    }

    $invalidFollowupResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/cases/2147483647/followups',
        $token,
        'POST',
        [
            'hotel_id' => $hotelId,
            'followup_date' => date('Y-m-d'),
            'followup_outcome' => 'resolved',
            'verification_text' => '只验证不存在案例会被拒绝，不保存任何真实复查。',
            'sample_count' => 1,
            'idempotency_key' => 'verify-invalid-followup-' . bin2hex(random_bytes(8)),
        ]
    );
    $afterFollowupCount = (int)Db::name(ManagerCapabilityScoringService::FOLLOWUP_TABLE)->count();
    if ($invalidFollowupResponse['status'] !== 404
        || (int)($invalidFollowupResponse['body']['code'] ?? 0) !== 404
        || $afterFollowupCount !== $beforeFollowupCount
    ) {
        $errors[] = 'invalid_followup_http_contract_failed:' . $invalidFollowupResponse['status'];
    }

    $invalidAdjustmentResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/cases/2147483647/adjustments',
        $token,
        'POST',
        [
            'hotel_id' => $hotelId,
            'adjustment_type' => 'voided',
            'reason' => '只验证不存在案例会被拒绝，不保存任何真实修正。',
            'idempotency_key' => 'verify-invalid-adjustment-' . bin2hex(random_bytes(8)),
        ]
    );
    $afterAdjustmentCount = (int)Db::name(ManagerCapabilityScoringService::ADJUSTMENT_TABLE)->count();
    if ($invalidAdjustmentResponse['status'] !== 404
        || (int)($invalidAdjustmentResponse['body']['code'] ?? 0) !== 404
        || $afterAdjustmentCount !== $beforeAdjustmentCount
    ) {
        $errors[] = 'invalid_adjustment_http_contract_failed:' . $invalidAdjustmentResponse['status'];
    }

    $invalidScoreReviewResponse = managerCapabilityApiRequest(
        $baseUrl . '/api/operation/manager-capability/cases/2147483647/score-reviews',
        $token,
        'POST',
        [
            'hotel_id' => $hotelId,
            'review_outcome' => 'confirmed',
            'reason' => '只验证不存在案例会被拒绝，不保存任何真实复核。',
            'source_score_digest' => str_repeat('a', 64),
            'dimension_overrides' => [],
            'idempotency_key' => 'verify-invalid-review-' . bin2hex(random_bytes(8)),
        ]
    );
    $afterScoreReviewCount = (int)Db::name(ManagerCapabilityScoringService::REVIEW_TABLE)->count();
    if ($invalidScoreReviewResponse['status'] !== 404
        || (int)($invalidScoreReviewResponse['body']['code'] ?? 0) !== 404
        || $afterScoreReviewCount !== $beforeScoreReviewCount
    ) {
        $errors[] = 'invalid_score_review_http_contract_failed:' . $invalidScoreReviewResponse['status'];
    }

    $pageVerification = null;
    if ($verifyPage) {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['SUXIOS_LOCAL_BASE_URL'] = $baseUrl;
        $environment['SUXIOS_MANAGER_CAPABILITY_TOKEN'] = $token;
        $environment['SUXIOS_MANAGER_CAPABILITY_HOTEL_ID'] = (string)$hotelId;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['node', __DIR__ . '/verify_manager_capability_page.mjs'],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            $errors[] = 'page_verifier_process_not_started';
        } else {
            fclose($pipes[0]);
            $pageStdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $pageStderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $pageExitCode = proc_close($process);
            $decodedPage = json_decode(trim((string)$pageStdout), true);
            if (!is_array($decodedPage) || $pageExitCode !== 0 || ($decodedPage['status'] ?? '') !== 'pass') {
                $safePageError = trim((string)$pageStderr);
                $pageErrors = is_array($decodedPage['errors'] ?? null)
                    ? implode('|', array_slice(array_map('strval', $decodedPage['errors']), 0, 3))
                    : '';
                $errors[] = 'page_verification_failed:' . $pageExitCode
                    . ($pageErrors !== '' ? ':' . mb_substr($pageErrors, 0, 360) : '')
                    . ($safePageError !== '' ? ':' . mb_substr($safePageError, 0, 240) : '');
            }
            $pageVerification = is_array($decodedPage) ? ($decodedPage['summary'] ?? null) : null;
        }
    }

    $summary = [
        'scope' => [
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => $hotelId,
            'actor_user_id' => (int)$scope['actor_user_id'],
            'manager_user_id' => $managerUserId,
        ],
        'manager_list_http_status' => $managerResponse['status'],
        'manager_list_message' => $managerResponse['body']['message'] ?? null,
        'profile_http_status' => $profileResponse['status'],
        'profile_message' => $profileResponse['body']['message'] ?? null,
        'profile_status' => $profileResponse['body']['data']['profile_status'] ?? null,
        'privacy_scope' => $profileResponse['body']['data']['privacy_scope'] ?? null,
        'daily_submission_status' => $dailySubmission['status'] ?? null,
        'daily_submission_case_count' => $dailySubmission['case_count'] ?? null,
        'daily_submission_truth_boundary_verified' => ($dailySubmission['closure_inferred'] ?? null) === false
            && ($dailySubmission['independent_verification'] ?? null) === false,
        'followup_queue_http_status' => $queueResponse['status'],
        'followup_queue_data_status' => $queueResponse['body']['data']['data_status'] ?? null,
        'out_of_scope_manager_http_status' => $crossScopeResponse['status'],
        'out_of_scope_manager_message' => $crossScopeResponse['body']['message'] ?? null,
        'invalid_write_http_status' => $invalidWriteResponse['status'],
        'invalid_write_message' => $invalidWriteResponse['body']['message'] ?? null,
        'invalid_write_persisted_rows' => $afterCaseCount - $beforeCaseCount,
        'invalid_followup_http_status' => $invalidFollowupResponse['status'],
        'invalid_followup_message' => $invalidFollowupResponse['body']['message'] ?? null,
        'invalid_followup_persisted_rows' => $afterFollowupCount - $beforeFollowupCount,
        'invalid_adjustment_http_status' => $invalidAdjustmentResponse['status'],
        'invalid_adjustment_persisted_rows' => $afterAdjustmentCount - $beforeAdjustmentCount,
        'invalid_score_review_http_status' => $invalidScoreReviewResponse['status'],
        'invalid_score_review_persisted_rows' => $afterScoreReviewCount - $beforeScoreReviewCount,
        'page_verification' => $pageVerification,
        'temporary_token_persisted' => false,
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . $exception->getMessage();
} finally {
    if ($tokenStored) {
        cache('token_' . $token, null);
    }
}

if (cache('token_' . $token) !== null) {
    $errors[] = 'temporary_local_auth_token_cleanup_failed';
}
$summary['temporary_token_cleanup_verified'] = cache('token_' . $token) === null;

$result = [
    'status' => $errors === [] ? 'pass' : 'fail',
    'summary' => $summary,
    'errors' => $errors,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
