<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(dirname(__DIR__));
$app->initialize();

$options = getopt('', [
    'tenant-id:',
    'hotel-id:',
    'platform:',
    'date-start:',
    'date-end:',
    'question::',
]);
$tenantId = (int)($options['tenant-id'] ?? 0);
$hotelId = (int)($options['hotel-id'] ?? 0);
$platform = strtolower(trim((string)($options['platform'] ?? '')));
$dateStart = trim((string)($options['date-start'] ?? ''));
$dateEnd = trim((string)($options['date-end'] ?? ''));
$question = trim((string)($options['question'] ?? ''));

if ($question === '') {
    $dateRangeLabel = $dateEnd === $dateStart ? $dateStart : $dateStart . '至' . $dateEnd;
    $question = sprintf(
        '%s%s经营表现中有哪些已验证问题，下一步应人工复核什么？',
        $dateRangeLabel,
        platformLabel($platform)
    );
}

if ($tenantId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
    fwrite(STDERR, "tenant-id, hotel-id and platform (ctrip/meituan/qunar) are required.\n");
    exit(2);
}
if (!validDate($dateStart) || !validDate($dateEnd) || $dateEnd < $dateStart) {
    fwrite(STDERR, "date-start and date-end must be a valid ascending YYYY-MM-DD range.\n");
    exit(2);
}

try {
    foreach ([
        'online_daily_data',
        'agent_logs',
        'hotel_operating_questions',
        'hotel_operating_memories',
        'hotel_operating_sop_versions',
        'hotel_operating_sop_replications',
    ] as $table) {
        if (!tableExists($table)) {
            throw new RuntimeException('required table missing: ' . $table);
        }
    }

    $factQuery = Db::name('online_daily_data')
        ->where('tenant_id', $tenantId)
        ->where('system_hotel_id', $hotelId)
        ->whereBetween('data_date', [$dateStart, $dateEnd])
        ->where('readback_verified', 1)
        ->where('validation_status', 'normal')
        ->whereRaw(
            "LOWER(COALESCE(NULLIF(`platform`, ''), `source`, '')) = :audit_platform",
            ['audit_platform' => $platform]
        );
    $strictFactCount = (int)$factQuery->count();
    $factTypes = $factQuery
        ->field('data_type')
        ->group('data_type')
        ->order('data_type', 'asc')
        ->column('data_type');
    $factTypes = array_values(array_filter(array_map(
        static fn(mixed $value): string => trim((string)$value),
        $factTypes
    )));

    $diagnoses = matchingDiagnoses($tenantId, $hotelId, $platform, $dateStart, $dateEnd);
    $latestDiagnosis = $diagnoses[0] ?? null;

    $questionQuery = Db::name('hotel_operating_questions')
        ->where('tenant_id', $tenantId)
        ->where('hotel_id', $hotelId)
        ->where('platform', $platform)
        ->where('date_start', $dateStart)
        ->where('date_end', $dateEnd)
        ->whereNull('deleted_at');
    $latestQuestion = $questionQuery
        ->field('id,question_text,answer_status,content_digest,created_at,updated_at')
        ->order('id', 'desc')
        ->find();
    $latestQuestion = is_array($latestQuestion) ? $latestQuestion : null;

    $memoryQuery = Db::name('hotel_operating_memories')
        ->where('tenant_id', $tenantId)
        ->where('hotel_id', $hotelId)
        ->where('platform', $platform)
        ->whereBetween('business_date', [$dateStart, $dateEnd])
        ->where('lifecycle_status', 'active')
        ->whereNull('deleted_at');
    $memoryCount = (int)$memoryQuery->count();
    $verifiedExecutionReviewCount = (int)(clone $memoryQuery)
        ->where('memory_layer', 'execution_review')
        ->where('quality_status', 'verified')
        ->count();

    $sopQuery = Db::name('hotel_operating_sop_versions')
        ->where('tenant_id', $tenantId)
        ->where('hotel_id', $hotelId)
        ->where('lifecycle_status', 'active')
        ->whereNull('deleted_at');
    $sopVersionCount = (int)$sopQuery->count();
    $verifiedSopCount = (int)(clone $sopQuery)->where('validation_status', 'verified')->count();

    $replicationQuery = Db::name('hotel_operating_sop_replications')
        ->where('tenant_id', $tenantId)
        ->where('source_hotel_id', $hotelId)
        ->whereNull('deleted_at');
    $replicationCount = (int)$replicationQuery->count();
    $draftReplicationCount = (int)(clone $replicationQuery)
        ->where('status', 'draft_pending_target_validation')
        ->count();

    $checks = [
        'strict_facts_available' => $strictFactCount > 0,
        'diagnosis_saved_and_readback_verified' => $latestDiagnosis !== null,
        'question_answered_from_saved_diagnosis' => ($latestQuestion['answer_status'] ?? '') === 'answered_from_saved_diagnosis',
        'verified_execution_review_memory_present' => $verifiedExecutionReviewCount > 0,
        'human_verified_sop_version_present' => $verifiedSopCount > 0,
        'same_tenant_replication_draft_present' => $draftReplicationCount > 0,
    ];
    [$closureStatus, $nextAction] = closureState($checks);

    echo json_encode([
        'contract_version' => 'operating_intelligence_closure_audit.v1',
        'read_only' => true,
        'database_scope' => 'local_only',
        'scope' => [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'source_scope' => 'ota_channel',
        ],
        'records' => [
            'strict_readback_facts' => $strictFactCount,
            'fact_types' => $factTypes,
            'matching_saved_diagnoses' => count($diagnoses),
            'latest_saved_diagnosis' => $latestDiagnosis,
            'matching_operating_questions' => (int)$questionQuery->count(),
            'latest_operating_question' => $latestQuestion,
            'operating_memories' => $memoryCount,
            'verified_execution_review_memories' => $verifiedExecutionReviewCount,
            'sop_versions' => $sopVersionCount,
            'verified_sop_versions' => $verifiedSopCount,
            'replications_from_source_hotel' => $replicationCount,
            'draft_replications_pending_target_validation' => $draftReplicationCount,
        ],
        'acceptance_checks' => $checks,
        'closure_status' => $closureStatus,
        'next_action' => $nextAction,
        'prepared_requests' => [
            'diagnosis_generation' => [
                'method' => 'POST',
                'path' => '/api/agent/ota-diagnosis',
                'body' => [
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'start_date' => $dateStart,
                    'end_date' => $dateEnd,
                    'analysis_type' => 'all',
                    'analysis_mode' => 'rules_only',
                ],
                'replay_safe' => false,
                'replay_note' => '每次生成会保存新诊断并替代同范围旧记录；只在精确回读确认缺失时执行一次。',
            ],
            'diagnosis_readback' => [
                'method' => 'GET',
                'path' => sprintf(
                    '/api/agent/ota-diagnosis?hotel_id=%d&platform=%s&start_date=%s&end_date=%s',
                    $hotelId,
                    rawurlencode($platform),
                    rawurlencode($dateStart),
                    rawurlencode($dateEnd)
                ),
                'replay_safe' => true,
            ],
            'operating_question' => [
                'method' => 'POST',
                'path' => '/api/agent/operating-questions',
                'body' => [
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'date_start' => $dateStart,
                    'date_end' => $dateEnd,
                    'question' => $question,
                ],
                'replay_safe_for_same_saved_evidence' => true,
                'expected_status_after_diagnosis' => 'answered_from_saved_diagnosis',
            ],
        ],
        'boundaries' => [
            'external_llm_called' => false,
            'ota_write' => false,
            'external_message' => false,
            'automatic_execution' => false,
            'page_verification_included' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function platformLabel(string $platform): string
{
    return match ($platform) {
        'ctrip' => '携程',
        'meituan' => '美团',
        'qunar' => '去哪儿',
        default => strtoupper($platform),
    };
}

function tableExists(string $table): bool
{
    $rows = Db::query(
        'SELECT COUNT(*) AS `aggregate` FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
    return (int)($rows[0]['aggregate'] ?? 0) > 0;
}

function columnExists(string $table, string $column): bool
{
    foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
        if (strtolower((string)($row['Field'] ?? $row['field'] ?? '')) === strtolower($column)) {
            return true;
        }
    }
    return false;
}

/** @return list<array<string,mixed>> */
function matchingDiagnoses(
    int $tenantId,
    int $hotelId,
    string $platform,
    string $dateStart,
    string $dateEnd
): array {
    $query = Db::name('agent_logs')
        ->where('hotel_id', $hotelId)
        ->where('action', 'ota_diagnosis')
        ->order('id', 'desc')
        ->limit(100);
    if (columnExists('agent_logs', 'tenant_id')) {
        $query->where('tenant_id', $tenantId);
    }

    $matches = [];
    foreach ($query->select()->toArray() as $row) {
        $context = decodeJson($row['context_data'] ?? null);
        $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
        $saved = is_array($snapshot['saved_record'] ?? null) ? $snapshot['saved_record'] : [];
        $range = is_array($snapshot['requested_date_range'] ?? null)
            ? $snapshot['requested_date_range']
            : (is_array($snapshot['date_range'] ?? null) ? $snapshot['date_range'] : []);
        $recordPlatform = strtolower(trim((string)($snapshot['platform'] ?? $context['platform'] ?? '')));
        $recordStatus = (string)($snapshot['record_status'] ?? $context['record_status'] ?? 'active');
        $summary = trim((string)($snapshot['core_conclusion'] ?? $snapshot['diagnosis']['summary'] ?? ''));
        if ($recordPlatform !== $platform
            || (string)($range['start_date'] ?? '') !== $dateStart
            || (string)($range['end_date'] ?? '') !== $dateEnd
            || $recordStatus !== 'active'
            || ($saved['saved'] ?? false) !== true
            || ($saved['readback_verified'] ?? false) !== true
        ) {
            continue;
        }
        $matches[] = [
            'id' => (int)$row['id'],
            'decision_status' => (string)($snapshot['decision_status'] ?? ''),
            'record_status' => $recordStatus,
            'readback_verified' => true,
            'has_conclusion' => $summary !== '',
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    return $matches;
}

/** @return array<string,mixed> */
function decodeJson(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,bool> $checks @return array{0:string,1:array<string,string>} */
function closureState(array $checks): array
{
    $steps = [
        'strict_facts_available' => [
            'blocked_by_missing_strict_facts',
            ['code' => 'collect_and_strictly_readback_facts', 'message' => '先取得同租户、同酒店、同平台、同日期范围的严格回读事实。'],
        ],
        'diagnosis_saved_and_readback_verified' => [
            'ready_for_real_diagnosis_generation',
            ['code' => 'generate_saved_diagnosis_once', 'message' => '通过已登录 Agent 页面生成一次规则诊断，并立即按同范围严格回读。'],
        ],
        'question_answered_from_saved_diagnosis' => [
            'ready_for_operating_question_refresh',
            ['code' => 'refresh_operating_question', 'message' => '使用相同范围重新提交经营问题，严格回读目标状态 answered_from_saved_diagnosis。'],
        ],
        'verified_execution_review_memory_present' => [
            'awaiting_real_execution_review_memory',
            ['code' => 'record_real_execution_review', 'message' => '完成一条真实人工动作、执行证据和同口径复盘后，再沉淀 verified execution_review 经营记忆。'],
        ],
        'human_verified_sop_version_present' => [
            'awaiting_human_verified_sop',
            ['code' => 'accumulate_and_validate_sop', 'message' => '累计满足门槛的独立真实复盘记忆后，由人工验证不可变 SOP 版本。'],
        ],
        'same_tenant_replication_draft_present' => [
            'ready_for_replication_draft',
            ['code' => 'create_same_tenant_replication_draft', 'message' => '选择同租户且目标事实匹配的门店，只创建待目标店人工验证的复制草稿。'],
        ],
    ];
    foreach ($steps as $key => [$status, $nextAction]) {
        if (($checks[$key] ?? false) !== true) {
            return [$status, $nextAction];
        }
    }
    return [
        'records_ready_for_authenticated_page_verification',
        ['code' => 'verify_authenticated_page', 'message' => '逐项核对登录后页面回显；本审计本身不替代页面验收。'],
    ];
}
