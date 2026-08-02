<?php
declare(strict_types=1);

use app\service\OperatingMemoryService;
use app\service\OperatingSopService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(dirname(__DIR__));
$app->initialize();
$options = getopt('', [
    'tenant-id:',
    'source-hotel-id:',
    'target-hotel-id:',
    'cross-tenant-hotel-id:',
    'execution-task-id:',
    'platform:',
]);
$tenantId = (int)($options['tenant-id'] ?? 0);
$sourceHotelId = (int)($options['source-hotel-id'] ?? 0);
$targetHotelId = (int)($options['target-hotel-id'] ?? 0);
$crossTenantHotelId = (int)($options['cross-tenant-hotel-id'] ?? 0);
$executionTaskId = (int)($options['execution-task-id'] ?? 0);
$platform = strtolower(trim((string)($options['platform'] ?? '')));
if ($tenantId <= 0 || $sourceHotelId <= 0 || $targetHotelId <= 0 || $crossTenantHotelId <= 0 || $executionTaskId <= 0 || $platform === '') {
    fwrite(STDERR, "tenant-id, source-hotel-id, target-hotel-id, cross-tenant-hotel-id, execution-task-id and platform are required.\n");
    exit(2);
}

$memoryService = new OperatingMemoryService();
$sopService = new OperatingSopService();
$transactionOpen = false;
try {
    Db::startTrans();
    $transactionOpen = true;

    $realMemoryResult = $memoryService->createFromExecutionTask(
        $executionTaskId,
        $tenantId,
        [$sourceHotelId],
        0
    );
    $realMemory = $realMemoryResult['memory'];
    $realCandidateStatus = 'not_attempted';
    $realCandidateReason = '';
    try {
        $realCandidate = $sopService->createCandidate(
            $tenantId,
            $sourceHotelId,
            [(int)$realMemory['id']],
            [
                'title' => '本店执行复盘候选SOP',
                'objective' => '只在真实复盘证据达到门槛后进入候选。',
                'steps' => ['读取同范围事实', '人工执行并留证', '读取同范围后续事实', '保存复盘'],
                'stop_conditions' => ['事实缺失、口径冲突或来源未回读时停止'],
                'applicable_data_types' => ['competitor'],
            ],
            0
        );
        $realCandidateStatus = (string)$realCandidate['version']['validation_status'];
        try {
            $sopService->validateVersion(
                (int)$realCandidate['version']['id'],
                $tenantId,
                [$sourceHotelId],
                [
                    'decision' => 'verify',
                    'validation_note' => '验证单条真实复盘不能直接升级。',
                    'evidence_memory_ids' => [(int)$realMemory['id']],
                ],
                0
            );
            throw new RuntimeException('single real review unexpectedly verified an SOP');
        } catch (InvalidArgumentException $e) {
            $realCandidateReason = $e->getMessage();
        }
    } catch (InvalidArgumentException $e) {
        $realCandidateStatus = 'blocked_before_candidate';
        $realCandidateReason = $e->getMessage();
    }

    $syntheticMemoryIds = [];
    $syntheticSuffix = bin2hex(random_bytes(5));
    foreach ([
        [900001, '2026-07-28'],
        [900002, '2026-07-29'],
        [900003, '2026-07-30'],
    ] as [$taskId, $businessDate]) {
        $context = [
            'outcome_verified' => true,
            'positive_outcome_verified' => true,
            'sop_candidate_ready' => true,
            'verification_fixture' => 'transaction_rollback_only',
        ];
        $digest = hash('sha256', json_encode([$tenantId, $sourceHotelId, $taskId, $context], JSON_THROW_ON_ERROR));
        $syntheticMemoryIds[] = (int)Db::name(OperatingMemoryService::TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => $sourceHotelId,
            'memory_key' => 'verification-fixture:' . $syntheticSuffix . ':' . $taskId,
            'memory_layer' => 'execution_review',
            'title' => 'Transaction rollback verification memory',
            'summary' => 'Synthetic positive review used only to verify version and replication gates.',
            'business_date' => $businessDate,
            'platform' => $platform,
            'source_scope' => 'ota_channel',
            'source_module' => 'verification_fixture',
            'source_record_type' => 'operation_execution_task',
            'source_record_id' => $taskId,
            'evidence_refs_json' => json_encode(['operation_execution_task#' . $taskId], JSON_THROW_ON_ERROR),
            'context_json' => json_encode($context, JSON_THROW_ON_ERROR),
            'quality_status' => 'verified',
            'usage_level' => 'decision_support',
            'lifecycle_status' => 'active',
            'content_digest' => $digest,
            'previous_memory_id' => null,
            'recorded_by' => 0,
            'occurred_at' => $businessDate . ' 12:00:00',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ]);
    }
    $candidate = $sopService->createCandidate(
        $tenantId,
        $sourceHotelId,
        [$syntheticMemoryIds[0]],
        [
            'title' => '跨店复制门槛验证SOP',
            'objective' => '验证SOP版本、人工升级和目标门店再验证边界。',
            'steps' => ['读取目标店自身事实', '人工确认适用范围', '仅创建本店执行草稿'],
            'stop_conditions' => ['目标店事实缺失或口径不一致时停止'],
            'applicable_data_types' => ['competitor'],
            'metric_definitions' => ['目标店 competitor 类型事实，日期必须落在来源复盘证据范围内'],
        ],
        0
    );
    $verified = $sopService->validateVersion(
        (int)$candidate['version']['id'],
        $tenantId,
        [$sourceHotelId],
        [
            'decision' => 'verify',
            'validation_note' => 'Transaction-only fixture verified the three-review gate.',
            'evidence_memory_ids' => $syntheticMemoryIds,
        ],
        0
    );
    $replicated = $sopService->replicate(
        (int)$verified['version']['id'],
        $tenantId,
        [$sourceHotelId, $targetHotelId],
        $targetHotelId,
        0
    );

    $crossTenantRejected = false;
    $crossTenantReason = '';
    try {
        $sopService->replicate(
            (int)$verified['version']['id'],
            $tenantId,
            [$sourceHotelId, $crossTenantHotelId],
            $crossTenantHotelId,
            0
        );
    } catch (RuntimeException|InvalidArgumentException $e) {
        $crossTenantRejected = true;
        $crossTenantReason = $e->getMessage();
    }

    $result = [
        'database_scope' => 'local_transaction_rollback',
        'real_execution_review' => [
            'task_id' => $executionTaskId,
            'memory_persistence_status' => $realMemoryResult['persistence_status'],
            'quality_status' => $realMemory['quality_status'],
            'usage_level' => $realMemory['usage_level'],
            'candidate_status' => $realCandidateStatus,
            'candidate_or_verification_blocker' => $realCandidateReason,
        ],
        'replication_gate_simulation' => [
            'fixture_type' => 'synthetic_source_reviews_transaction_rollback',
            'source_hotel_id' => $sourceHotelId,
            'target_hotel_id' => $targetHotelId,
            'candidate_version' => (int)$candidate['version']['version_no'],
            'verified_version' => (int)$verified['version']['version_no'],
            'replication_status' => $replicated['replication']['status'],
            'target_validation_status' => $replicated['replication']['target_validation_status'],
            'target_fact_reference_count' => count($replicated['replication']['target_fact_refs']),
            'target_verified' => $replicated['replication']['draft']['boundaries']['target_verified'],
            'automatic_execution' => $replicated['replication']['draft']['boundaries']['automatic_execution'],
            'ota_write' => $replicated['replication']['draft']['boundaries']['ota_write'],
            'external_message' => $replicated['replication']['draft']['boundaries']['external_message'],
            'cross_tenant_rejected' => $crossTenantRejected,
            'cross_tenant_reason' => $crossTenantReason,
        ],
        'rolled_back' => true,
    ];
    Db::rollback();
    $transactionOpen = false;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($transactionOpen) {
        Db::rollback();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
