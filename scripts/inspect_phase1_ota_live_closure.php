<?php
declare(strict_types=1);

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use think\App;
use think\facade\Db;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    echo json_encode([
        'status' => 'failed',
        'mode' => 'inspect',
        'scope' => [],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'issues' => [[
            'severity' => 'error',
            'code' => 'vendor_autoload_missing',
            'message' => 'vendor/autoload.php is missing. Run composer install before live closure inspection.',
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

require $autoload;

$root = dirname(__DIR__);

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function parse_args(array $argv): array
{
    $options = [
        'date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
        'platform' => '',
        'hotel_id' => '',
        'system_hotel_id' => '',
        'evidence' => '',
        'limit' => 5000,
        'format' => 'json',
        'strict' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--strict') {
            $options['strict'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = $key === 'limit' ? max(1, min(5000, (int)$value)) : trim($value);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }
    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return $options;
}

/**
 * @param array<int, array<string, mixed>> $target
 * @param array<string, mixed> $details
 */
function add_check(array &$target, string $code, string $status, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $status,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $target[] = $row;
}

/**
 * @param array<string, mixed> $details
 */
function missing_requirement_platform_label(string $code, array $details = []): string
{
    $platform = strtolower(trim((string)($details['platform'] ?? '')));
    if ($platform === '') {
        if (str_starts_with($code, 'ctrip_')) {
            $platform = 'ctrip';
        } elseif (str_starts_with($code, 'meituan_')) {
            $platform = 'meituan';
        }
    }

    return match ($platform) {
        'ctrip' => '携程',
        'meituan' => '美团',
        default => $platform !== '' ? strtoupper($platform) : 'OTA',
    };
}

/**
 * @param array<string, mixed> $details
 * @return array<string, mixed>
 */
function missing_requirement_employee_explanation(string $code, array $details = []): array
{
    $platformLabel = missing_requirement_platform_label($code, $details);

    if (str_ends_with($code, '_source_rows_missing')) {
        return [
            'employee_explanation' => $platformLabel . '目标日没有同日 OTA 源数据行，不能证明今天' . $platformLabel . '数据已采到。',
            'limited_conclusions' => [
                $platformLabel . '收入',
                $platformLabel . '流量',
                $platformLabel . '转化',
                $platformLabel . '字段可信度',
                $platformLabel . 'AI 诊断',
            ],
            'still_usable_metrics' => [
                $platformLabel . '最近可用历史数据只能作参考，不能替代目标日数据。',
                '其它已采到平台的同日 OTA 指标可按平台单独复核。',
            ],
            'explanation_next_action' => '使用现有' . $platformLabel . '手动或自动获取入口补齐目标日源数据。',
        ];
    }

    if (str_ends_with($code, '_etl_not_ready')) {
        return [
            'employee_explanation' => $platformLabel . '源数据没有形成可读的标准事实层，不能进入统一收益诊断。',
            'limited_conclusions' => [
                $platformLabel . '标准事实',
                $platformLabel . '收益指标',
                $platformLabel . '字段可信判断',
            ],
            'still_usable_metrics' => [
                '已保存的原始/历史参考状态。',
                '现有采集日志和数据质量标记。',
            ],
            'explanation_next_action' => '复核现有' . $platformLabel . ' ETL 输入、data_type、raw_data 标准化证据。',
        ];
    }

    if (str_ends_with($code, '_revenue_metrics_not_ready')) {
        return [
            'employee_explanation' => $platformLabel . '收益指标未就绪，不能计算收入、间夜、客单等经营结论。',
            'limited_conclusions' => [
                $platformLabel . '收益',
                $platformLabel . 'ADR',
                $platformLabel . '订单',
                $platformLabel . '间夜',
                $platformLabel . '相关 AI 建议',
            ],
            'still_usable_metrics' => [
                '其它已 ready 平台的收益指标可单独复核。',
                '缺口本身可作为补证据清单。',
            ],
            'explanation_next_action' => '补齐' . $platformLabel . '目标日源数据和标准事实后复跑收益指标。',
        ];
    }

    if (str_ends_with($code, '_metric_trust_missing')) {
        return [
            'employee_explanation' => $platformLabel . '指标可信度为空，不能判断哪些字段可用于经营结论。',
            'limited_conclusions' => [
                $platformLabel . '字段可信度',
                $platformLabel . 'AI 建议依据',
                $platformLabel . '可执行动作优先级',
            ],
            'still_usable_metrics' => [
                '已存在的源数据行和标准事实状态。',
                '明确列出的缺口和采集状态。',
            ],
            'explanation_next_action' => '复核' . $platformLabel . '收益指标计算输入，确保 metric_trust 随指标一起输出。',
        ];
    }

    if (str_ends_with($code, '_data_gaps_missing')) {
        return [
            'employee_explanation' => $platformLabel . '缺口清单为空，系统不能解释哪些字段或证据限制了结论。',
            'limited_conclusions' => [
                $platformLabel . '缺字段解释',
                $platformLabel . '受限指标',
                $platformLabel . 'AI 诊断限制条件',
            ],
            'still_usable_metrics' => [
                '已采到且 metric_trust 明确可信的指标。',
                '当前缺口状态本身仍可用于补证据排查。',
            ],
            'explanation_next_action' => '按现有指标计算链路补齐 data_gaps，不用 0、空值或成功状态掩盖缺口。',
        ];
    }

    if (str_ends_with($code, '_traffic_facts_missing')) {
        return [
            'employee_explanation' => $platformLabel . '目标日缺少流量/转化事实，不能判断曝光、访问、下单链路是否异常。',
            'limited_conclusions' => [
                $platformLabel . '流量',
                $platformLabel . '转化率',
                $platformLabel . '漏斗诊断',
                'AI 对流量问题的确定结论',
            ],
            'still_usable_metrics' => [
                $platformLabel . '已采到且 metric_trust 明确可信的收益事实（如存在）。',
                '其它平台已就绪的同日指标。',
            ],
            'explanation_next_action' => '使用现有' . $platformLabel . '流量获取入口补齐目标日流量事实，复跑巡检。',
        ];
    }

    if ($code === 'ai_diagnosis_evidence_sample_missing') {
        return [
            'employee_explanation' => '缺少可追溯的 AI 诊断证据，不能说明 AI 建议依据来自哪些 OTA 数据和缺口。',
            'limited_conclusions' => [
                'AI 经营建议',
                'AI 建议证据来源',
                '运营执行动作生成',
            ],
            'still_usable_metrics' => [
                '已验证的 OTA 源数据、收益指标和 data_gaps。',
                '缺口清单可作为生成诊断前的补证据清单。',
            ],
            'explanation_next_action' => '调用现有 OTA 诊断接口，并附上包含 evidence_sources、data_gaps、action_items 的脱敏证据 JSON。',
        ];
    }

    if ($code === 'ai_diagnosis_action_items_blocked') {
        return [
            'employee_explanation' => 'AI 诊断已有阻断依据，但 action_items 不能作为可执行经营建议。',
            'limited_conclusions' => [
                'AI 自动建议',
                '执行意图创建',
                '运营闭环完成判断',
            ],
            'still_usable_metrics' => [
                '阻断原因。',
                '证据来源。',
                'data_gaps 补证据清单。',
            ],
            'explanation_next_action' => '先解除上游 OTA 缺口，再重新生成包含非 blocked action_items 的诊断。',
        ];
    }

    if ($code === 'operation_execution_sample_missing') {
        return [
            'employee_explanation' => '尚无能追溯到 OTA 诊断的执行意图、审批、执行证据或复盘样例。',
            'limited_conclusions' => [
                '运营执行闭环',
                '动作完成',
                '复盘和 ROI 判断',
            ],
            'still_usable_metrics' => [
                '下一步动作和阻断链可见。',
                '已验证的 OTA 诊断缺口可继续作为待处理清单。',
            ],
            'explanation_next_action' => '取得可执行 AI action_items 后，创建或附上执行意图和证据。',
        ];
    }

    if ($code === 'operation_execution_ai_action_link_missing') {
        return [
            'employee_explanation' => '已有执行相关数据，但未能追溯到 OTA 诊断 action_items，不能证明这一步是 AI 建议的运营承接。',
            'limited_conclusions' => [
                'AI 建议执行承接',
                '运营执行闭环',
                '动作完成归因',
            ],
            'still_usable_metrics' => [
                '普通执行流可作为运营参考。',
                'OTA 诊断缺口和动作队列仍可作为待处理清单。',
            ],
            'explanation_next_action' => '将执行意图或执行流程的 source/evidence 关联到 OTA 诊断 action_items，再补齐审批、执行证据或复盘。',
        ];
    }

    if ($code === 'operation_execution_evidence_incomplete') {
        return [
            'employee_explanation' => '已有执行相关样例，但审批、执行证据、复盘或 ROI 信号不足，不能证明动作已闭环。',
            'limited_conclusions' => [
                '动作完成',
                '执行效果',
                '复盘结论',
                'ROI 判断',
            ],
            'still_usable_metrics' => [
                '已有执行意图或执行流可作为待补证据入口。',
                '当前审批/执行状态可用于定位阻断环节。',
            ],
            'explanation_next_action' => '补齐审批通过、执行证据、复盘状态或 ROI 信号后复跑巡检。',
        ];
    }

    if ($code === 'evidence_scope_date_mismatch') {
        return [
            'employee_explanation' => '外部证据日期与本次巡检目标日不一致，不能证明目标日 OTA 闭环。',
            'limited_conclusions' => [
                '目标日 AI 诊断',
                '目标日运营动作',
                '目标日闭环完成判断',
            ],
            'still_usable_metrics' => [
                '该证据只能作为历史或非目标日参考。',
                '本次巡检的同日 OTA 数据和缺口仍可单独查看。',
            ],
            'explanation_next_action' => '重新生成或选择与本次巡检同一业务日期的证据 JSON。',
        ];
    }

    if ($code === 'unsupported_platform') {
        return [
            'employee_explanation' => '第一阶段真实闭环只支持携程和美团，当前平台不在本阶段范围内。',
            'limited_conclusions' => [
                '非携程/美团平台采集状态',
                '非携程/美团平台经营诊断',
            ],
            'still_usable_metrics' => [
                '携程和美团 OTA 渠道范围内的已验证数据。',
            ],
            'explanation_next_action' => '将巡检平台限制为 ctrip、meituan，或另行定义新平台的数据合同。',
        ];
    }

    return [];
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $details
 */
function add_missing(array &$result, string $code, string $message, array $details = []): void
{
    $nextAction = next_action_for_missing_requirement($code, $details);
    if ($nextAction !== []) {
        $nextAction = with_inspection_next_action_entry($nextAction);
        $nextAction = with_inspection_next_action_success_criteria($nextAction);
        $nextAction = with_inspection_next_action_resolution($nextAction);
    }
    $row = [
        'code' => $code,
        'status' => 'missing',
        'message' => $message,
    ];
    $platform = strtolower(trim((string)($details['platform'] ?? '')));
    if ($platform === '' && preg_match('/^(ctrip|meituan)_/', $code, $matches)) {
        $platform = $matches[1];
    }
    if ($platform === '' && (str_starts_with($code, 'ai_') || str_starts_with($code, 'operation_'))) {
        $platform = 'ctrip,meituan';
    }
    if ($platform !== '') {
        $row['platform'] = $platform;
    }
    $explanation = missing_requirement_employee_explanation($code, $details);
    if ($explanation !== []) {
        $row['employee_explanation'] = (string)($explanation['employee_explanation'] ?? '');
        $row['limited_conclusions'] = array_values(array_filter(
            array_map('strval', (array)($explanation['limited_conclusions'] ?? [])),
            static fn(string $item): bool => $item !== ''
        ));
        $row['still_usable_metrics'] = array_values(array_filter(
            array_map('strval', (array)($explanation['still_usable_metrics'] ?? [])),
            static fn(string $item): bool => $item !== ''
        ));
        $row['explanation_next_action'] = (string)($explanation['explanation_next_action'] ?? '');
    }
    if ($details !== []) {
        $row['details'] = $details;
    }
    if ($nextAction !== []) {
        $actionCode = (string)($nextAction['action_code'] ?? '');
        if ($actionCode !== '') {
            $row['action_code'] = $actionCode;
            $row['action_family'] = inspection_next_action_family($actionCode);
            $row['question_key'] = inspection_next_action_question_key($actionCode);
            $row['related_question_keys'] = inspection_next_action_related_question_keys($actionCode);
            $row['resolves_missing_codes'] = inspection_next_action_resolves_missing_codes($actionCode);
            $row['live_closure_gap_codes'] = inspection_next_action_live_closure_gap_codes(['action_code' => $actionCode]);
            $nextAction['action_family'] = $row['action_family'];
            $nextAction['question_key'] = $row['question_key'];
            $nextAction['related_question_keys'] = $row['related_question_keys'];
            $nextAction['resolves_missing_codes'] = $row['resolves_missing_codes'];
            $nextAction['live_closure_gap_codes'] = $row['live_closure_gap_codes'];
        }
        $row['next_action'] = $nextAction;
    }
    $result['missing_requirements'][] = $row;
    if ($nextAction !== []) {
        $result['next_actions'] ??= [];
        $actionKey = (string)($nextAction['action_code'] ?? $code);
        foreach ($result['next_actions'] as $existingAction) {
            if (($existingAction['action_code'] ?? '') === $actionKey) {
                return;
            }
        }
        $result['next_actions'][] = $nextAction;
    }
}

/**
 * @param array<string, mixed> $details
 * @return array<string, mixed>
 */
function next_action_for_missing_requirement(string $code, array $details = []): array
{
    $platform = (string)($details['platform'] ?? '');
    $date = (string)($details['date'] ?? '');
    $platformLabel = $platform !== '' ? strtoupper($platform) : 'OTA';

    if (str_ends_with($code, '_source_rows_missing')) {
        return [
            'action_code' => $code . '_collect_existing_path',
            'owner' => '酒店运营人员',
            'action' => sprintf(
                '使用现有%s手动或自动获取入口补齐 %s 的 OTA 数据，然后重新运行真实闭环巡检。',
                $platformLabel,
                $date !== '' ? $date : '目标日期'
            ),
            'evidence_needed' => [
                'online_daily_data 同日期源数据行',
                'data_source_id 或 sync_task_id',
                'source_trace_id 或 raw_data 追踪证据',
            ],
            'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
        ];
    }

    if (str_ends_with($code, '_etl_not_ready')) {
        return [
            'action_code' => $code . '_check_standard_facts',
            'owner' => '产品/技术',
            'action' => sprintf('%s 源数据行存在后，检查同范围 OTA 标准事实层为什么仍然为空。', $platformLabel),
            'evidence_needed' => [
                'accepted_rows',
                'rejected_rows',
                'validation_flags',
                'data_type 分布',
            ],
            'protected_boundary' => '保持源采集不变，只检查下游标准化证据。',
        ];
    }

    if (str_ends_with($code, '_revenue_metrics_not_ready')) {
        return [
            'action_code' => $code . '_check_metric_inputs',
            'owner' => '收益运营人员',
            'action' => sprintf('在输出经营结论前，确认 %s 同日标准事实是否包含最小收益指标输入。', $platformLabel),
            'evidence_needed' => [
                'amount',
                'quantity 或 room_nights',
                'book_order_num 或 order_count',
                'metric_trust',
                'data_gaps',
            ],
            'protected_boundary' => '不使用 0 或伪成功值填补缺失指标。',
        ];
    }

    if (str_ends_with($code, '_traffic_facts_missing')) {
        return [
            'action_code' => $code . '_confirm_traffic_collection',
            'owner' => 'OTA 运营人员',
            'action' => sprintf('确认 %s 同日流量数据是否已采到；未采到时，流量/转化诊断必须标记为不可用。', $platformLabel),
            'evidence_needed' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num 或 order_submit_num',
            ],
            'protected_boundary' => '不从只有收益的数据行推断流量或转化问题。',
        ];
    }

    if ($code === 'ai_diagnosis_evidence_sample_missing') {
        return [
            'action_code' => 'collect_ai_diagnosis_evidence',
            'owner' => 'AI 运营人员',
            'action' => '调用现有 OTA 诊断接口，并为本次巡检范围附上脱敏证据 JSON。',
            'evidence_needed' => [
                'evidence_sources',
                'data_gaps',
                'action_items',
            ],
            'protected_boundary' => 'AI 建议必须引用 OTA 证据，不能把缺失数据写成确定结论。',
        ];
    }

    if ($code === 'ai_diagnosis_action_items_blocked') {
        return [
            'action_code' => 'resolve_ai_diagnosis_blocked_action_items',
            'owner' => 'AI 运营人员',
            'action' => 'AI 诊断已有阻断依据，但 action_items 仍不可执行；先处理上游 OTA 缺口后重新生成诊断。',
            'evidence_needed' => [
                '非 blocked action_items',
                'evidence_sources',
                'data_gaps',
            ],
            'protected_boundary' => 'AI 诊断可以暴露阻断依据，但不能把 blocked action_items 当成可执行经营建议。',
        ];
    }

    if ($code === 'operation_execution_sample_missing') {
        return [
            'action_code' => 'collect_operation_execution_evidence',
            'owner' => '运营负责人',
            'action' => '创建或附上一个真实执行意图/执行流程样例，并关联到 OTA 诊断动作项。',
            'evidence_needed' => [
                'execution_intents 或 execution_flow',
                'approval.status=approved',
                'execution.status=executed 或 evidence.count>0',
                'review.status 或 ROI 复盘状态',
            ],
            'protected_boundary' => '动作可以处于待审批状态；不能只凭 AI 建议卡片标记闭环完成。',
        ];
    }

    if ($code === 'operation_execution_ai_action_link_missing') {
        return [
            'action_code' => 'collect_operation_execution_evidence',
            'owner' => '运营负责人',
            'action' => '已有执行意图/执行流程数据，但还不能追溯到 OTA 诊断 action_items；补齐 source、evidence_refs 或 action_item_id 关联。',
            'evidence_needed' => [
                'source_module=ota_diagnosis 或 source=ota_diagnosis#action_item',
                'evidence_refs 或 action_item_id',
                'approval.status=approved、execution.status=executed、evidence.count>0 或 review.status',
            ],
            'protected_boundary' => '只补齐执行证据关联，不改携程/美团采集字段和采集逻辑。',
        ];
    }

    if ($code === 'operation_execution_evidence_incomplete') {
        return [
            'action_code' => 'collect_operation_execution_evidence',
            'owner' => '运营负责人',
            'action' => '已有执行意图/执行流程样例，但还不能证明动作可进入运营闭环；补齐审批通过、执行证据或复盘状态。',
            'evidence_needed' => [
                'approval.status=approved',
                'execution.status=executed 或 evidence.count>0',
                'review.status 或 ROI 复盘状态',
            ],
            'protected_boundary' => '动作可以处于待审批状态；不能只凭 AI 建议卡片标记闭环完成。',
        ];
    }

    if ($code === 'evidence_scope_date_mismatch') {
        return [
            'action_code' => 'align_evidence_scope_date',
            'owner' => '产品/技术',
            'action' => '重新生成或选择与真实闭环巡检同一业务日期的证据 JSON。',
            'evidence_needed' => [
                'scope.date',
                '巡检日期',
            ],
            'protected_boundary' => '不复用过期证据证明当天 OTA 闭环。',
        ];
    }

    return [];
}

/**
 * @param array<string, mixed> $result
 * @return array<int, array<string, mixed>>
 */
function finalize_inspection_next_actions(array $result): array
{
    $missingCodes = array_values(array_filter(array_map(
        static fn($item): string => is_array($item) ? (string)($item['code'] ?? '') : '',
        (array)($result['missing_requirements'] ?? [])
    ), static fn(string $code): bool => $code !== ''));
    $aiBlockers = inspection_ai_blocking_missing_codes($missingCodes);
    $actions = [];
    foreach ((array)($result['next_actions'] ?? []) as $action) {
        if (is_array($action)) {
            $actions[] = normalize_inspection_next_action($action, $missingCodes, $aiBlockers);
        }
    }

    return sort_inspection_next_actions($actions);
}

/**
 * @param array<int, string> $missingCodes
 * @return array<int, string>
 */
function inspection_ai_blocking_missing_codes(array $missingCodes): array
{
    return array_values(array_filter(array_unique($missingCodes), static function (string $code): bool {
        return str_contains($code, 'source_rows_missing')
            || str_contains($code, 'etl_not_ready')
            || str_contains($code, 'revenue_metrics_not_ready')
            || str_contains($code, 'traffic_facts_missing')
            || str_contains($code, 'data_gaps_missing')
            || $code === 'evidence_scope_date_mismatch'
            || $code === 'ai_diagnosis_evidence_sample_missing'
            || $code === 'ai_diagnosis_action_items_blocked';
    }));
}

/**
 * @param array<string, mixed> $operation
 * @return array<string, int>
 */
function inspection_operation_signal_counts(array $operation): array
{
    $flow = is_array($operation['execution_flow'] ?? null) ? $operation['execution_flow'] : [];
    $items = array_values(array_filter(
        is_array($flow['list'] ?? null) ? $flow['list'] : [],
        static fn($item): bool => is_array($item)
    ));
    $linkedItems = array_values(array_filter($items, 'inspection_operation_item_has_ota_diagnosis_link'));
    $intents = array_values(array_filter(
        (array)($operation['execution_intents'] ?? []),
        static fn($item): bool => is_array($item)
    ));
    $linkedIntents = array_values(array_filter($intents, 'inspection_operation_intent_has_ota_diagnosis_link'));
    $summary = is_array($flow['summary'] ?? null) ? $flow['summary'] : [];
    $stageCounts = is_array($summary['stage_counts'] ?? null) ? $summary['stage_counts'] : [];
    $stageCountTotal = array_sum(array_map('intval', $stageCounts));
    $countItems = static fn(callable $predicate): int => count(array_filter($linkedItems, $predicate));
    $executionEvidenceCount = array_sum(array_map(
        static fn(array $item): int => max(0, (int)($item['evidence']['count'] ?? 0)),
        $linkedItems
    ));
    $approvedCount = max(
        0,
        $countItems(static fn(array $item): bool => (string)($item['approval']['status'] ?? '') === 'approved')
    );
    $executedCount = max(
        0,
        $countItems(static fn(array $item): bool => (string)($item['execution']['status'] ?? '') === 'executed')
    );
    $evidenceReadyCount = max(
        0,
        $countItems(static fn(array $item): bool => (int)($item['evidence']['count'] ?? 0) > 0)
    );
    $reviewedCount = max(
        0,
        $countItems(static fn(array $item): bool => (string)($item['stage'] ?? '') === 'reviewed'
            || in_array((string)($item['review']['status'] ?? ''), ['success', 'near_success', 'failed'], true))
    );
    $roiReadyCount = $countItems(static fn(array $item): bool => (string)($item['roi']['status'] ?? '') === 'ready');
    $blockedExecutionCount = max(
        0,
        $countItems(static fn(array $item): bool => in_array((string)($item['stage'] ?? ''), ['blocked', 'rejected', 'failed'], true)
            || in_array((string)($item['approval']['status'] ?? ''), ['blocked', 'rejected'], true)
            || in_array((string)($item['execution']['status'] ?? ''), ['blocked', 'failed'], true))
    );

    return [
        'execution_intent_count' => count($intents),
        'execution_flow_item_count' => count($items),
        'execution_flow_stage_count' => count(is_array($flow['stages'] ?? null) ? $flow['stages'] : []),
        'execution_flow_summary_total' => max((int)($summary['total'] ?? 0), $stageCountTotal),
        'ota_diagnosis_linked_intent_count' => count($linkedIntents),
        'ota_diagnosis_linked_flow_item_count' => count($linkedItems),
        'approved_count' => $approvedCount,
        'executed_count' => $executedCount,
        'evidence_ready_count' => $evidenceReadyCount,
        'execution_evidence_count' => $executionEvidenceCount,
        'reviewed_count' => $reviewedCount,
        'roi_ready_count' => $roiReadyCount,
        'blocked_execution_count' => $blockedExecutionCount,
        'completion_signal_count' => $approvedCount + $executedCount + $evidenceReadyCount + $reviewedCount + $roiReadyCount,
    ];
}

function inspection_operation_item_has_ota_diagnosis_link(array $item): bool
{
    $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
    $evidence = is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [];
    $source = strtolower((string)($recommendation['source'] ?? ''));
    $sourceModule = strtolower((string)($recommendation['source_module'] ?? ''));

    return $sourceModule === 'ota_diagnosis'
        || str_contains($source, 'ota_diagnosis')
        || !empty($evidence['evidence_refs'])
        || !empty($evidence['data_gaps'])
        || array_key_exists('action_item_id', $evidence)
        || array_key_exists('action_item_status', $evidence)
        || array_key_exists('diagnosis_summary', $evidence);
}

function inspection_operation_intent_has_ota_diagnosis_link(array $intent): bool
{
    $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
    $source = strtolower((string)($intent['source'] ?? ''));
    $sourceModule = strtolower((string)($intent['source_module'] ?? ''));

    return $sourceModule === 'ota_diagnosis'
        || str_contains($source, 'ota_diagnosis')
        || !empty($evidence['evidence_refs'])
        || !empty($evidence['data_gaps'])
        || array_key_exists('action_item_id', $evidence)
        || array_key_exists('action_item_status', $evidence)
        || array_key_exists('diagnosis_summary', $evidence);
}

function inspection_operation_payload_signal_count(array $operation): int
{
    $counts = inspection_operation_signal_counts($operation);
    return (int)$counts['execution_intent_count']
        + (int)$counts['execution_flow_item_count']
        + (int)$counts['execution_flow_summary_total'];
}

function inspection_operation_linked_payload_signal_count(array $operation): int
{
    $counts = inspection_operation_signal_counts($operation);
    return (int)$counts['ota_diagnosis_linked_intent_count']
        + (int)$counts['ota_diagnosis_linked_flow_item_count'];
}

/**
 * @param array<string, mixed> $operation
 */
function inspection_operation_evidence_status(array $operation): string
{
    $counts = inspection_operation_signal_counts($operation);
    $completionSignalCount = (int)$counts['approved_count']
        + (int)$counts['executed_count']
        + (int)$counts['evidence_ready_count']
        + (int)$counts['reviewed_count']
        + (int)$counts['roi_ready_count'];
    if ($completionSignalCount > 0) {
        return 'proved';
    }
    return inspection_operation_payload_signal_count($operation) > 0 ? 'warning' : 'missing';
}

function inspection_operation_blocking_code(array $operation): string
{
    if (inspection_operation_payload_signal_count($operation) === 0) {
        return 'operation_execution_sample_missing';
    }
    if (inspection_operation_linked_payload_signal_count($operation) === 0) {
        return 'operation_execution_ai_action_link_missing';
    }
    if (inspection_operation_evidence_status($operation) === 'warning') {
        return 'operation_execution_evidence_incomplete';
    }
    return '';
}

/**
 * @param array<string, mixed> $action
 * @param array<int, string> $missingCodes
 * @param array<int, string> $aiBlockers
 * @return array<string, mixed>
 */
function normalize_inspection_next_action(array $action, array $missingCodes, array $aiBlockers): array
{
    $code = (string)($action['action_code'] ?? '');
    $blockedBy = array_values(array_filter(array_map('strval', (array)($action['blocked_by'] ?? []))));
    if ($code === 'collect_ai_diagnosis_evidence') {
        $blockedBy = array_values(array_unique(array_filter(
            array_merge($blockedBy, $aiBlockers),
            static fn(string $code): bool => $code !== 'ai_diagnosis_evidence_sample_missing'
        )));
    }
    if ($code === 'resolve_ai_diagnosis_blocked_action_items') {
        $blockedBy = array_values(array_unique(array_merge($blockedBy, $aiBlockers)));
    }
    if ($code === 'collect_operation_execution_evidence') {
        if (in_array('ai_diagnosis_evidence_sample_missing', $missingCodes, true)) {
            $blockedBy[] = 'ai_diagnosis_evidence_sample_missing';
        }
        $blockedBy = array_values(array_unique(array_merge($blockedBy, $aiBlockers)));
    }

    $platform = strtolower(trim((string)($action['platform'] ?? '')));
    $action['platform'] = $platform !== '' ? $platform : inspection_next_action_platform($code);
    $action['type'] = inspection_next_action_type($code);
    $action['action_family'] = inspection_next_action_family($code);
    $action['question_key'] = inspection_next_action_question_key($code);
    $action['related_question_keys'] = inspection_next_action_related_question_keys($code);
    $action['priority'] = inspection_next_action_priority($code);
    $action['status'] = $blockedBy === [] ? 'missing' : 'blocked';
    $action['blocked_by'] = $blockedBy;
    $action['evidence_needed'] = array_values(array_filter(array_map('strval', (array)($action['evidence_needed'] ?? []))));
    $action['protected_boundary'] = (string)($action['protected_boundary'] ?? '不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射。');
    $action = with_inspection_next_action_entry($action);
    $action = with_inspection_next_action_success_criteria($action);
    $action = with_inspection_next_action_resolution($action);
    $action = with_inspection_next_action_employee_explanation($action);
    $action = with_inspection_next_action_employee_copy($action);

    return $action;
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function with_inspection_next_action_employee_copy(array $action): array
{
    $action['employee_action'] = inspection_employee_readable_copy((string)($action['employee_action'] ?? $action['next_action'] ?? $action['action'] ?? ''));
    $action['employee_evidence_needed'] = array_values(array_filter(array_map(
        static fn($value): string => inspection_employee_readable_copy((string)$value),
        (array)($action['employee_evidence_needed'] ?? $action['evidence_needed'] ?? [])
    ), static fn(string $value): bool => $value !== ''));
    $action['employee_success_criteria'] = inspection_employee_readable_copy((string)($action['employee_success_criteria'] ?? $action['success_criteria'] ?? ''));
    $action['employee_explanation_next_action'] = inspection_employee_readable_copy((string)($action['employee_explanation_next_action'] ?? $action['explanation_next_action'] ?? ''));
    $action['employee_verification_steps'] = array_values(array_filter(array_map(
        static fn($value): string => inspection_employee_readable_copy((string)$value),
        (array)($action['employee_verification_steps'] ?? inspection_next_action_employee_verification_steps($action))
    ), static fn(string $value): bool => $value !== ''));
    if ((string)($action['action_code'] ?? '') === 'resolve_ai_diagnosis_blocked_action_items') {
        $action['employee_action'] = 'AI 动作项被上游缺口阻断；先处理上游 OTA 缺口后重新生成非阻断动作项。';
    }

    return $action;
}

/**
 * @param array<string, mixed> $action
 * @return array<int, string>
 */
function inspection_next_action_employee_verification_steps(array $action): array
{
    $family = (string)($action['action_family'] ?? inspection_next_action_family((string)($action['action_code'] ?? '')));
    $platformLabel = inspection_next_action_platform_label($action);

    return match ($family) {
        'target_date_source_rows' => [
            '刷新数据健康页的员工六问闭环。',
            '确认' . $platformLabel . '目标日入库行数大于 0。',
            '确认相关未完成问题和巡检缺口减少。',
        ],
        'standard_facts' => [
            '刷新员工六问里的收入、流量和转化问题。',
            '确认' . $platformLabel . '标准事实层变为可复核。',
            '确认字段可信或收益指标不再被该项阻断。',
        ],
        'revenue_metric_inputs' => [
            '刷新收入、流量和转化问题。',
            '确认' . $platformLabel . '收益输入变为可复核。',
            '确认 AI 依据不再因为该收益缺口被阻断。',
        ],
        'traffic_conversion_facts' => [
            '刷新收入、流量和转化问题。',
            '确认' . $platformLabel . '流量/转化事实变为可复核。',
            '确认漏斗判断不再显示该平台流量/转化缺口。',
        ],
        'ai_diagnosis_evidence' => [
            '重新运行现有 OTA 诊断。',
            '确认 AI 动作项不再被上游缺口阻断。',
            '确认 AI 依据仍保留证据来源和数据缺口说明。',
        ],
        'operation_execution_evidence' => [
            '刷新运营执行闭环摘要。',
            '确认执行意图能追溯到 OTA 诊断动作。',
            '确认出现审批、执行证据、复盘或 ROI 信号。',
        ],
        'evidence_scope' => [
            '刷新员工六问闭环。',
            '确认目标日期和证据日期一致。',
            '确认最近可用数据没有替代目标日证明。',
        ],
        default => [
            '刷新员工六问闭环。',
            '确认该动作对应缺口从未证明变为可复核。',
        ],
    };
}

function inspection_next_action_family(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'target_date_source_rows';
    }
    if (str_contains($code, 'etl_not_ready')) {
        return 'standard_facts';
    }
    if (str_contains($code, 'revenue_metrics_not_ready')) {
        return 'revenue_metric_inputs';
    }
    if (str_contains($code, 'traffic_facts_missing')) {
        return 'traffic_conversion_facts';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'ai_diagnosis_evidence';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return 'operation_execution_evidence';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'evidence_scope';
    }
    return 'evidence_gap';
}

function inspection_next_action_question_key(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'today_ota_collected';
    }
    if (str_contains($code, 'etl_not_ready')
        || str_contains($code, 'revenue_metrics_not_ready')
        || str_contains($code, 'traffic_facts_missing')
    ) {
        return 'revenue_traffic_conversion';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'ai_evidence';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return 'next_operation_action';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'ai_evidence';
    }
    return '';
}

function inspection_next_action_related_question_keys(string $code): array
{
    $family = inspection_next_action_family($code);
    $keys = match ($family) {
        'target_date_source_rows' => ['today_ota_collected', 'trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'standard_facts' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'revenue_metric_inputs' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'traffic_conversion_facts' => ['revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
        'ai_diagnosis_evidence' => ['ai_evidence', 'next_operation_action'],
        'operation_execution_evidence' => ['next_operation_action'],
        'evidence_scope' => ['ai_evidence', 'next_operation_action'],
        default => [],
    };
    $questionKey = inspection_next_action_question_key($code);
    if ($questionKey !== '') {
        array_unshift($keys, $questionKey);
    }
    return array_values(array_unique($keys));
}

function inspection_next_action_platform(string $code): string
{
    if (preg_match('/^(ctrip|meituan)_/', $code, $matches)) {
        return $matches[1];
    }
    if (in_array($code, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
        return 'ctrip,meituan';
    }
    return 'ota';
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function with_inspection_next_action_entry(array $action): array
{
    $code = (string)($action['action_code'] ?? '');
    $entry = trim((string)($action['entry'] ?? ''));
    if ($entry === '') {
        $entry = inspection_next_action_entry($code);
    }
    $action['entry'] = $entry;
    if (empty($action['entry_options']) || !is_array($action['entry_options'])) {
        $entryOptions = inspection_next_action_entry_options($code);
        if ($entryOptions !== []) {
            $action['entry_options'] = $entryOptions;
        }
    }
    return $action;
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function with_inspection_next_action_success_criteria(array $action): array
{
    $criteria = trim((string)($action['success_criteria'] ?? ''));
    if ($criteria === '') {
        $criteria = inspection_next_action_success_criteria((string)($action['action_code'] ?? ''));
    }
    $action['success_criteria'] = $criteria;
    return $action;
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function with_inspection_next_action_resolution(array $action): array
{
    $resolves = array_values(array_filter(array_map('strval', (array)($action['resolves_missing_codes'] ?? []))));
    if ($resolves === []) {
        $resolves = inspection_next_action_resolves_missing_codes((string)($action['action_code'] ?? ''));
    }
    $action['resolves_missing_codes'] = $resolves;
    $action['blocked_by_action_codes'] = inspection_next_action_blocked_by_action_codes(
        (array)($action['blocked_by'] ?? []),
        (string)($action['action_code'] ?? '')
    );
    return $action;
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function with_inspection_next_action_employee_explanation(array $action): array
{
    $explanation = inspection_next_action_employee_explanation($action);
    if ($explanation === []) {
        return $action;
    }
    $action['employee_explanation'] = (string)($explanation['employee_explanation'] ?? '');
    $action['limited_conclusions'] = array_values(array_filter(
        array_map('strval', (array)($explanation['limited_conclusions'] ?? [])),
        static fn(string $value): bool => $value !== ''
    ));
    $action['still_usable_metrics'] = array_values(array_filter(
        array_map('strval', (array)($explanation['still_usable_metrics'] ?? [])),
        static fn(string $value): bool => $value !== ''
    ));
    $action['explanation_next_action'] = (string)($explanation['explanation_next_action'] ?? '');
    $action['live_closure_gap_codes'] = inspection_next_action_live_closure_gap_codes($action);
    return $action;
}

/**
 * @param array<string, mixed> $action
 */
function inspection_next_action_platform_label(array $action): string
{
    $code = (string)($action['action_code'] ?? '');
    $platform = strtolower(trim((string)($action['platform'] ?? '')));
    if (str_contains($platform, ',')) {
        return '携程/美团';
    }
    if ($platform === '') {
        if (preg_match('/^(ctrip|meituan)_/', $code, $matches)) {
            $platform = $matches[1];
        } elseif (str_contains((string)($action['platform'] ?? ''), ',')) {
            return '携程/美团';
        }
    }

    return match ($platform) {
        'ctrip' => '携程',
        'meituan' => '美团',
        default => $platform !== '' ? strtoupper($platform) : 'OTA',
    };
}

/**
 * @param array<string, mixed> $action
 * @return array<int, string>
 */
function inspection_next_action_live_closure_gap_codes(array $action): array
{
    return inspection_next_action_resolves_missing_codes((string)($action['action_code'] ?? ''));
}

/**
 * @param array<string, mixed> $action
 * @return array<string, mixed>
 */
function inspection_next_action_employee_explanation(array $action): array
{
    $family = (string)($action['action_family'] ?? inspection_next_action_family((string)($action['action_code'] ?? '')));
    $platformLabel = inspection_next_action_platform_label($action);

    return match ($family) {
        'target_date_source_rows' => [
            'employee_explanation' => $platformLabel . '目标日没有同日 OTA 源数据行，不能证明今天' . $platformLabel . '数据已采到。',
            'limited_conclusions' => [$platformLabel . '收入', $platformLabel . '流量', $platformLabel . '转化', $platformLabel . '字段可信度', $platformLabel . 'AI 诊断'],
            'still_usable_metrics' => [$platformLabel . '最近可用历史数据只能作参考，不能替代目标日数据。', '其它已采到平台的同日 OTA 指标可按平台单独复核。'],
            'explanation_next_action' => '使用现有' . $platformLabel . '手动或自动获取入口补齐目标日源数据。',
        ],
        'standard_facts' => [
            'employee_explanation' => $platformLabel . '源数据没有形成可读的标准事实层，不能进入统一收益诊断。',
            'limited_conclusions' => [$platformLabel . '标准事实', $platformLabel . '收益指标', $platformLabel . '字段可信判断'],
            'still_usable_metrics' => ['已保存的原始/历史参考状态。', '现有采集日志和数据质量标记。'],
            'explanation_next_action' => '复核现有' . $platformLabel . ' ETL 输入、data_type、raw_data 标准化证据。',
        ],
        'revenue_metric_inputs' => [
            'employee_explanation' => $platformLabel . '收益指标未就绪，不能计算收入、间夜、客单等经营结论。',
            'limited_conclusions' => [$platformLabel . '收益', $platformLabel . 'ADR', $platformLabel . '订单', $platformLabel . '间夜', $platformLabel . '相关 AI 建议'],
            'still_usable_metrics' => ['其它已 ready 平台的收益指标可单独复核。', '缺口本身可作为补证据清单。'],
            'explanation_next_action' => '补齐' . $platformLabel . '目标日源数据和标准事实后复跑收益指标。',
        ],
        'traffic_conversion_facts' => [
            'employee_explanation' => $platformLabel . '目标日缺少流量/转化事实，不能判断曝光、访问、下单链路是否异常。',
            'limited_conclusions' => [$platformLabel . '流量', $platformLabel . '转化率', $platformLabel . '漏斗诊断', 'AI 对流量问题的确定结论'],
            'still_usable_metrics' => [$platformLabel . '已采到且 metric_trust 明确可信的收益事实（如存在）。', '其它平台已就绪的同日指标。'],
            'explanation_next_action' => '使用现有' . $platformLabel . '流量获取入口补齐目标日流量事实，复跑巡检。',
        ],
        'ai_diagnosis_evidence' => [
            'employee_explanation' => 'AI 诊断证据未闭合，不能把当前 action_items 当作可执行经营建议。',
            'limited_conclusions' => ['AI 自动建议', '执行意图创建', '运营闭环完成判断'],
            'still_usable_metrics' => ['阻断原因。', '证据来源。', 'data_gaps 补证据清单。'],
            'explanation_next_action' => '先解除上游 OTA 缺口，再调用现有 OTA 诊断并保留脱敏 evidence_sources、data_gaps、action_items。',
        ],
        'operation_execution_evidence' => [
            'employee_explanation' => '尚无能追溯到 OTA 诊断的执行意图、审批、执行证据或复盘样例。',
            'limited_conclusions' => ['运营执行闭环', '动作完成', '复盘和 ROI 判断'],
            'still_usable_metrics' => ['下一步动作和阻断链可见。', '已验证的 OTA 诊断缺口可继续作为待处理清单。'],
            'explanation_next_action' => '取得可执行 AI action_items 后，创建或附上执行意图和证据。',
        ],
        'evidence_scope' => [
            'employee_explanation' => '外部证据日期与本次巡检目标日不一致，不能证明目标日 OTA 闭环。',
            'limited_conclusions' => ['目标日 AI 诊断', '目标日运营动作', '目标日闭环完成判断'],
            'still_usable_metrics' => ['该证据只能作为历史或非目标日参考。', '本次巡检的同日 OTA 数据和缺口仍可单独查看。'],
            'explanation_next_action' => '重新生成或选择与本次巡检同一业务日期的证据 JSON。',
        ],
        default => [],
    };
}

function inspection_next_action_entry(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        if (str_starts_with($code, 'ctrip_')) {
            return '/api/online-data/fetch-ctrip-overview';
        }
        if (str_starts_with($code, 'meituan_')) {
            return '/api/online-data/fetch-meituan';
        }
        return '/api/online-data/collection-reliability';
    }
    if ($code === 'ctrip_traffic_facts_missing_confirm_traffic_collection') {
        return '/api/online-data/fetch-ctrip-traffic';
    }
    if ($code === 'meituan_traffic_facts_missing_confirm_traffic_collection') {
        return '/api/online-data/fetch-meituan-traffic';
    }
    if (str_contains($code, 'etl_not_ready')
        || str_contains($code, 'revenue_metrics_not_ready')
        || str_contains($code, 'traffic_facts_missing')
    ) {
        return '/api/ota-standard/revenue-metrics';
    }
    if ($code === 'collect_ai_diagnosis_evidence' || $code === 'resolve_ai_diagnosis_blocked_action_items') {
        return '/api/agent/ota-diagnosis';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return '/api/operation/execution-intents';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'scripts/inspect_phase1_ota_live_closure.php --evidence=<same-date-json>';
    }
    return '/api/online-data/collection-reliability';
}

function inspection_profile_directory_count(string $platform): int
{
    $platform = strtolower(trim($platform));
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        return 0;
    }
    $dirs = glob(__DIR__ . '/../storage/' . $platform . '_profile_*', GLOB_ONLYDIR);
    return is_array($dirs) ? count($dirs) : 0;
}

function inspection_entry_option_readiness(string $platform, string $mode): array
{
    $platform = strtolower(trim($platform));
    $mode = strtolower(trim($mode));
    if ($mode === 'status_check') {
        return [
            'status' => 'ready',
            'label' => '可直接只读核对',
            'can_run_now' => true,
            'reason' => '只读取 collection-reliability 和 online_daily_data 状态，不写 OTA 数据。',
            'evidence' => 'read_existing_collection_reliability_only',
        ];
    }
    if ($mode === 'browser_profile') {
        $profileCount = inspection_profile_directory_count($platform);
        return [
            'status' => $profileCount > 0 ? 'profile_found_login_unverified' : 'profile_missing',
            'label' => $profileCount > 0 ? '发现 Profile，登录态需复核' : '未发现本机 Profile',
            'can_run_now' => false,
            'reason' => $profileCount > 0
                ? '本机存在 Profile 目录，但仍需人工确认平台账号登录态有效。'
                : '未发现对应平台 Profile 目录，需先按现有自动采集流程完成授权登录。',
            'evidence' => 'storage_profile_directory_count',
            'profile_count' => $profileCount,
            'source_policy' => 'read_local_profile_directory_names_only',
        ];
    }
    return [
        'status' => 'requires_user_context',
        'label' => '需提供授权上下文',
        'can_run_now' => false,
        'reason' => '需要用户提供 Cookie/Payload/门店标识等授权上下文后才能调用现有手动入口。',
        'evidence' => 'user_supplied_cookie_or_payload_required',
    ];
}

function inspection_entry_options_with_readiness(string $platform, array $options): array
{
    return array_values(array_map(static function (array $option) use ($platform): array {
        $option['readiness'] = inspection_entry_option_readiness($platform, (string)($option['mode'] ?? ''));
        return $option;
    }, $options));
}

function inspection_next_action_entry_options(string $code): array
{
    if (str_contains($code, 'traffic_facts_missing')) {
        if (str_starts_with($code, 'ctrip_')) {
            return inspection_entry_options_with_readiness('ctrip', [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动流量 Cookie/API',
                    'entry' => '/api/online-data/fetch-ctrip-traffic',
                    'use_when' => '已取得携程流量接口 Cookie、URL、spiderkey 或必要参数，需要临时补齐目标日流量事实。',
                    'requires' => '用户提供授权上下文、流量接口参数和目标日期。',
                    'boundary' => '不自动登录携程后台，不改变流量采集字段或字段映射。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-ctrip-browser',
                    'use_when' => '门店携程浏览器 Profile 已登录授权，需要走现有自动采集路径补齐流量事实。',
                    'requires' => '本地 Profile 存在且携程账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日流量事实是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ]);
        }
        if (str_starts_with($code, 'meituan_')) {
            return inspection_entry_options_with_readiness('meituan', [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动流量 Cookie/API',
                    'entry' => '/api/online-data/fetch-meituan-traffic',
                    'use_when' => '已取得美团流量接口 Cookie、Partner ID、POI ID 或必要参数，需要临时补齐目标日流量事实。',
                    'requires' => '用户提供授权上下文、门店/POI 标识、流量接口参数和目标日期。',
                    'boundary' => '不代登录美团后台，不改变流量采集字段或字段映射。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-meituan-browser',
                    'use_when' => '门店美团浏览器 Profile 已登录授权，需要走现有自动采集路径补齐流量事实。',
                    'requires' => '本地 Profile 存在且美团账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日流量事实是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ]);
        }
    }
    if (in_array($code, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
        $primaryEntry = $code === 'collect_operation_execution_evidence'
            ? '/api/operation/execution-intents'
            : '/api/agent/ota-diagnosis';
        return inspection_entry_options_with_readiness('ctrip', [
            [
                'mode' => 'manual_cookie_api',
                'label' => 'Evidence/API',
                'entry' => $primaryEntry,
                'use_when' => 'Use existing API evidence after target-date OTA facts are present.',
                'requires' => 'Target-date OTA facts, diagnosis/action evidence, and authorized user context.',
                'boundary' => 'Does not change Ctrip/Meituan acquisition logic, fields, or mappings.',
            ],
            [
                'mode' => 'browser_profile',
                'label' => 'Profile evidence refresh',
                'entry' => '/api/online-data/capture-ctrip-browser',
                'use_when' => 'Use only when an authorized browser Profile must refresh target-date OTA evidence.',
                'requires' => 'Local Profile exists and platform login state is manually verified.',
                'boundary' => 'Does not bypass captcha, SMS, human verification, or platform permissions.',
            ],
            [
                'mode' => 'status_check',
                'label' => 'Read-only status check',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => 'Verify target-date rows, latest available date, and explicit missing reasons.',
                'requires' => 'Read existing collection reliability and online_daily_data state.',
                'boundary' => 'Read-only; does not write OTA data or alter field mappings.',
            ],
        ]);
    }
    if (!str_contains($code, 'source_rows_missing')) {
        return [];
    }
    if (str_starts_with($code, 'ctrip_')) {
        return inspection_entry_options_with_readiness('ctrip', [
            [
                'mode' => 'manual_cookie_api',
                'label' => '手动 Cookie/API',
                'entry' => '/api/online-data/fetch-ctrip-overview',
                'use_when' => '已取得携程 Cookie、Payload 或必要参数，需要临时补齐目标日经营概况。',
                'requires' => '用户提供授权上下文、平台酒店标识和目标日期。',
                'boundary' => '不自动登录携程后台，不启动浏览器 Profile，不改变采集字段。',
            ],
            [
                'mode' => 'browser_profile',
                'label' => '浏览器 Profile',
                'entry' => '/api/online-data/capture-ctrip-browser',
                'use_when' => '门店携程浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                'requires' => '本地 Profile 存在且携程账号登录态有效。',
                'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
            ],
            [
                'mode' => 'status_check',
                'label' => '状态核对',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
            ],
        ]);
    }
    if (str_starts_with($code, 'meituan_')) {
        return inspection_entry_options_with_readiness('meituan', [
            [
                'mode' => 'manual_cookie_api',
                'label' => '手动 Cookie/API',
                'entry' => '/api/online-data/fetch-meituan',
                'use_when' => '已取得美团 Cookie、Session、POI 或必要 Payload，需要临时补齐目标日数据。',
                'requires' => '用户提供授权上下文、门店/POI 标识和目标日期。',
                'boundary' => '不代登录美团后台，不启动浏览器 Profile，不改变采集字段。',
            ],
            [
                'mode' => 'browser_profile',
                'label' => '浏览器 Profile',
                'entry' => '/api/online-data/capture-meituan-browser',
                'use_when' => '门店美团浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                'requires' => '本地 Profile 存在且美团账号登录态有效。',
                'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
            ],
            [
                'mode' => 'status_check',
                'label' => '状态核对',
                'entry' => '/api/online-data/collection-reliability',
                'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
            ],
        ]);
    }
    return [];
}

function inspection_next_action_resolves_missing_codes(string $code): array
{
    if (preg_match('/^(ctrip|meituan)_source_rows_missing_collect_existing_path$/', $code, $matches)) {
        return [$matches[1] . '_source_rows_missing'];
    }
    if (preg_match('/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/', $code, $matches)) {
        return [$matches[1] . '_etl_not_ready'];
    }
    if (preg_match('/^(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs$/', $code, $matches)) {
        return [$matches[1] . '_revenue_metrics_not_ready'];
    }
    if (preg_match('/^(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection$/', $code, $matches)) {
        return [$matches[1] . '_traffic_facts_missing'];
    }
    if ($code === 'collect_ai_diagnosis_evidence') {
        return ['ai_diagnosis_evidence_sample_missing'];
    }
    if ($code === 'resolve_ai_diagnosis_blocked_action_items') {
        return ['ai_diagnosis_action_items_blocked'];
    }
    if ($code === 'collect_operation_execution_evidence') {
        return ['operation_execution_sample_missing', 'operation_execution_ai_action_link_missing', 'operation_execution_evidence_incomplete'];
    }
    if ($code === 'align_evidence_scope_date') {
        return ['evidence_scope_date_mismatch'];
    }
    return [];
}

function inspection_next_action_for_blocker_code(string $code): string
{
    if (preg_match('/^(ctrip|meituan)_source_rows_missing$/', $code, $matches)) {
        return $matches[1] . '_source_rows_missing_collect_existing_path';
    }
    if (preg_match('/^(ctrip|meituan)_etl_not_ready$/', $code, $matches)) {
        return $matches[1] . '_etl_not_ready_check_standard_facts';
    }
    if (preg_match('/^(ctrip|meituan)_revenue_metrics_not_ready$/', $code, $matches)) {
        return $matches[1] . '_revenue_metrics_not_ready_check_metric_inputs';
    }
    if (preg_match('/^(ctrip|meituan)_traffic_facts_missing$/', $code, $matches)) {
        return $matches[1] . '_traffic_facts_missing_confirm_traffic_collection';
    }
    if ($code === 'ai_diagnosis_evidence_sample_missing'
        || $code === 'ai_evidence_sources_missing'
        || $code === 'ai_data_gaps_missing'
        || $code === 'ai_action_items_missing'
    ) {
        return 'collect_ai_diagnosis_evidence';
    }
    if ($code === 'ai_diagnosis_action_items_blocked' || $code === 'ai_action_items_blocked') {
        return 'resolve_ai_diagnosis_blocked_action_items';
    }
    if ($code === 'operation_execution_sample_missing'
        || $code === 'operation_execution_ai_action_link_missing'
        || $code === 'operation_execution_evidence_incomplete'
    ) {
        return 'collect_operation_execution_evidence';
    }
    if ($code === 'evidence_scope_date_mismatch') {
        return 'align_evidence_scope_date';
    }
    return '';
}

function inspection_next_action_blocked_by_action_codes(array $blockedBy, string $currentActionCode = ''): array
{
    $actions = [];
    foreach ($blockedBy as $blocker) {
        $actionCode = inspection_next_action_for_blocker_code((string)$blocker);
        if ($actionCode !== '' && $actionCode !== $currentActionCode) {
            $actions[] = $actionCode;
        }
    }
    return array_values(array_unique($actions));
}

function inspection_next_action_success_criteria(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'source_date_evidence.platforms 中对应平台 target_date_rows > 0；latest_available 仅作最近可用参考，不能替代或否定目标日行数。';
    }
    if (str_contains($code, 'etl_not_ready')) {
        return '同范围 OTA 标准事实层出现 accepted_rows 或 ETL status=ready，并保留 validation_flags 与 data_type 分布。';
    }
    if (str_contains($code, 'revenue_metrics_not_ready')) {
        return '对应平台 revenue_status=ready，且 revenue_metrics 输出 metric_trust 与 data_gaps。';
    }
    if (str_contains($code, 'traffic_facts_missing')) {
        return '对应平台 traffic_status=ready；未采到时必须保留 data_gaps，不用收益行推断流量或转化。';
    }
    if ($code === 'collect_ai_diagnosis_evidence') {
        return 'OTA 诊断响应包含 evidence_sources、data_gaps 和至少一个非 blocked action_items。';
    }
    if ($code === 'resolve_ai_diagnosis_blocked_action_items') {
        return 'action_items 不再全部为 blocked，且 blocked_by 上游缺口已清空或显式转为待补证。';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return '执行意图或执行流程可追溯到 OTA diagnosis action_items，并出现审批通过、执行证据、复盘或 ROI 任一完成信号。';
    }
    if ($code === 'align_evidence_scope_date') {
        return '证据 JSON 的 scope.date 与本次巡检目标日期一致。';
    }
    return '补齐所需证据后重新运行第一阶段真实闭环巡检，相关员工六问不再处于缺失状态。';
}

function inspection_next_action_type(string $code): string
{
    if (str_contains($code, 'source_rows_missing')) {
        return 'collection_evidence';
    }
    if (str_contains($code, 'etl_not_ready')) {
        return 'standard_fact_evidence';
    }
    if (str_contains($code, 'revenue_metrics_not_ready')) {
        return 'revenue_metric_evidence';
    }
    if (str_contains($code, 'traffic_facts_missing')) {
        return 'traffic_conversion_evidence';
    }
    if ($code === 'collect_ai_diagnosis_evidence') {
        return 'ai_diagnosis_evidence';
    }
    if ($code === 'collect_operation_execution_evidence') {
        return 'operation_execution_evidence';
    }
    if ($code === 'align_evidence_scope_date') {
        return 'evidence_scope';
    }
    return 'evidence_gap';
}

function inspection_next_action_priority(string $code): string
{
    if (str_contains($code, 'source_rows_missing')
        || str_contains($code, 'traffic_facts_missing')
        || $code === 'collect_ai_diagnosis_evidence'
        || $code === 'resolve_ai_diagnosis_blocked_action_items'
        || $code === 'align_evidence_scope_date'
    ) {
        return 'high';
    }
    if (str_contains($code, 'etl_not_ready')
        || str_contains($code, 'revenue_metrics_not_ready')
        || $code === 'collect_operation_execution_evidence'
    ) {
        return 'medium';
    }
    return 'low';
}

/**
 * @param array<string, mixed> $action
 */
function inspection_next_action_family_rank(array $action): int
{
    $family = (string)($action['action_family'] ?? '');
    if ($family === '') {
        $family = inspection_next_action_family((string)($action['action_code'] ?? ''));
    }

    return match ($family) {
        'evidence_scope' => 0,
        'target_date_source_rows' => 1,
        'standard_facts' => 2,
        'revenue_metric_inputs' => 3,
        'traffic_conversion_facts' => 4,
        'ai_diagnosis_evidence' => 5,
        'operation_execution_evidence' => 6,
        default => 9,
    };
}

/**
 * @param array<int, array<string, mixed>> $actions
 * @return array<int, array<string, mixed>>
 */
function sort_inspection_next_actions(array $actions): array
{
    $statusRank = ['missing' => 0, 'blocked' => 1];
    $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($actions, static function (array $a, array $b) use ($statusRank, $priorityRank): int {
        $aStatus = $statusRank[(string)($a['status'] ?? '')] ?? 9;
        $bStatus = $statusRank[(string)($b['status'] ?? '')] ?? 9;
        if ($aStatus !== $bStatus) {
            return $aStatus <=> $bStatus;
        }
        $aFamily = inspection_next_action_family_rank($a);
        $bFamily = inspection_next_action_family_rank($b);
        if ($aFamily !== $bFamily) {
            return $aFamily <=> $bFamily;
        }
        $aPriority = $priorityRank[(string)($a['priority'] ?? '')] ?? 9;
        $bPriority = $priorityRank[(string)($b['priority'] ?? '')] ?? 9;
        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }
        return strcmp((string)($a['action_code'] ?? ''), (string)($b['action_code'] ?? ''));
    });

    return array_values($actions);
}

/**
 * @return array<int, string>
 */
function source_aliases(string $platform): array
{
    return match ($platform) {
        'ctrip' => ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'],
        'meituan' => ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile'],
        default => [$platform],
    };
}

function table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
}

/**
 * @return array<string, bool>
 */
function table_columns(string $table): array
{
    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 * @return array<int, array<string, mixed>>
 */
function query_source_rows(array $columns, string $platform, array $options): array
{
    $fields = array_values(array_intersect([
        'id',
        'system_hotel_id',
        'hotel_id',
        'hotel_name',
        'source',
        'data_date',
        'data_type',
        'amount',
        'quantity',
        'book_order_num',
        'validation_status',
        'validation_flags',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
        'status',
        'save_status',
        'error_info',
        'failure_reason',
        'failed_reason',
        'update_time',
        'updated_at',
        'create_time',
        'created_at',
    ], array_keys($columns)));

    $query = scoped_source_query($columns, $platform, $options, (string)$options['date'])->field($fields ?: '*');

    return $query
        ->order('id', 'desc')
        ->limit((int)$options['limit'])
        ->select()
        ->toArray();
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 */
function scoped_source_query(array $columns, string $platform, array $options, ?string $date = null): object
{
    $query = Db::name('online_daily_data');
    if (isset($columns['source'])) {
        $query->whereIn('source', source_aliases($platform));
    }
    if ($date !== null && isset($columns['data_date'])) {
        $query->where('data_date', $date);
    }
    if ((string)$options['hotel_id'] !== '' && isset($columns['hotel_id'])) {
        $query->where('hotel_id', (string)$options['hotel_id']);
    }
    if ((string)$options['system_hotel_id'] !== '' && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', (int)$options['system_hotel_id']);
    }

    return $query;
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function query_latest_available_source_rows(array $columns, string $platform, array $options): array
{
    if (!isset($columns['data_date'])) {
        return [
            'date' => null,
            'date_relation' => 'unknown',
            'count' => 0,
            'data_types' => [],
            'latest_trace_time' => null,
            'sample_traces' => [],
        ];
    }

    $latestRow = scoped_source_query($columns, $platform, $options)
        ->field('MAX(data_date) AS latest_data_date')
        ->find();
    $latestDate = (string)($latestRow['latest_data_date'] ?? '');
    if ($latestDate === '') {
        return [
            'date' => null,
            'date_relation' => 'none',
            'count' => 0,
            'data_types' => [],
            'latest_trace_time' => null,
            'sample_traces' => [],
        ];
    }

    $latestOptions = $options;
    $latestOptions['date'] = $latestDate;
    $rows = query_source_rows($columns, $platform, $latestOptions);

    return [
        'date' => $latestDate,
        'date_relation' => source_date_relation((string)$options['date'], $latestDate),
        'count' => (int)scoped_source_query($columns, $platform, $latestOptions, $latestDate)->count(),
        'data_types' => data_types($rows),
        'latest_trace_time' => latest_time($rows),
        'sample_traces' => sample_traces($rows),
    ];
}

function source_date_relation(string $targetDate, string $latestDate): string
{
    if ($latestDate === '') {
        return 'none';
    }
    if ($latestDate === $targetDate) {
        return 'target_date';
    }
    return strcmp($latestDate, $targetDate) > 0 ? 'future_dated_for_target' : 'stale_before_target';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function data_types(array $rows): array
{
    $types = [];
    foreach ($rows as $row) {
        $type = trim((string)($row['data_type'] ?? ''));
        if ($type !== '') {
            $types[$type] = true;
        }
    }
    return array_keys($types);
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function latest_time(array $rows): ?string
{
    foreach ($rows as $row) {
        foreach (['updated_at', 'update_time', 'created_at', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function sample_traces(array $rows): array
{
    $samples = [];
    foreach (array_slice($rows, 0, 5) as $row) {
        $samples[] = array_filter([
            'row_id' => $row['id'] ?? null,
            'source' => $row['source'] ?? null,
            'data_type' => $row['data_type'] ?? null,
            'hotel_id' => $row['hotel_id'] ?? null,
            'system_hotel_id' => $row['system_hotel_id'] ?? null,
            'data_source_id' => $row['data_source_id'] ?? null,
            'sync_task_id' => $row['sync_task_id'] ?? null,
            'ingestion_method' => $row['ingestion_method'] ?? null,
            'source_trace_id' => $row['source_trace_id'] ?? null,
            'validation_status' => $row['validation_status'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }
    return $samples;
}

/**
 * @return array<int, mixed>
 */
function rows_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<string, bool> $columns
 * @param array<string, mixed> $options
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function inspect_platform(string $platform, array $columns, array $options, array &$result): array
{
    $checks = [];
    $rows = query_source_rows($columns, $platform, $options);
    $latestAvailable = query_latest_available_source_rows($columns, $platform, $options);
    $filters = array_filter([
        'source' => $platform,
        'start_date' => $options['date'],
        'end_date' => $options['date'],
        'hotel_id' => $options['hotel_id'],
        'system_hotel_id' => $options['system_hotel_id'],
        'limit' => $options['limit'],
    ], static fn($value): bool => $value !== '' && $value !== null);

    if ($rows === []) {
        add_check($checks, 'source_rows_present', 'missing', 'No same-day OTA source rows found for this scope.', [
            'filters' => $filters,
            'latest_available' => $latestAvailable,
        ]);
        add_missing($result, $platform . '_source_rows_missing', 'No same-day OTA source rows found.', [
            'platform' => $platform,
            'date' => $options['date'],
            'latest_available' => $latestAvailable,
        ]);
    } else {
        add_check($checks, 'source_rows_present', 'proved', 'Same-day OTA source rows exist.', [
            'rows' => count($rows),
            'data_types' => data_types($rows),
        ]);
    }

    $dataset = (new OtaStandardEtlService())->buildDataset($filters);
    $daily = rows_list($dataset['fact_ota_daily'] ?? []);
    $traffic = rows_list($dataset['fact_ota_traffic'] ?? []);
    $advertising = rows_list($dataset['fact_ota_advertising'] ?? []);
    $quality = rows_list($dataset['fact_ota_quality'] ?? []);
    $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

    if (($dataset['status'] ?? '') === 'ready') {
        add_check($checks, 'etl_ready', 'proved', 'OTA standard ETL produced readable facts.');
    } else {
        add_check($checks, 'etl_ready', 'missing', 'OTA standard ETL is not ready for this scope.', [
            'etl_status' => $dataset['status'] ?? null,
        ]);
        add_missing($result, $platform . '_etl_not_ready', 'OTA standard ETL did not produce readable facts.', [
            'platform' => $platform,
        ]);
    }

    if (($metrics['status'] ?? '') === 'ready') {
        add_check($checks, 'revenue_metrics_ready', 'proved', 'Revenue metrics are available for this scope.');
    } else {
        add_check($checks, 'revenue_metrics_ready', 'missing', 'Revenue metrics are not ready for this scope.', [
            'metric_status' => $metrics['status'] ?? null,
        ]);
        add_missing($result, $platform . '_revenue_metrics_not_ready', 'Revenue metrics are not ready.', [
            'platform' => $platform,
        ]);
    }

    $metricTrust = is_array($metrics['metric_trust'] ?? null) ? $metrics['metric_trust'] : [];
    $reportedMetricTrustKeys = array_keys($metricTrust);
    $trustedMetricTrustKeys = inspection_trusted_metric_trust_keys($metricTrust);
    $metricTrustContext = [
        'source_rows' => count($rows),
        'metric_status' => $metrics['status'] ?? null,
        'metric_trust_key_count' => count($trustedMetricTrustKeys),
        'reported_metric_trust_key_count' => count($reportedMetricTrustKeys),
    ];
    if ($rows === []) {
        add_check($checks, 'trusted_fields_visible', 'missing', 'Target-date source rows are missing; metric_trust cannot prove field trust for this platform.', $metricTrustContext);
    } elseif (($metrics['status'] ?? '') !== 'ready') {
        add_check($checks, 'trusted_fields_visible', 'missing', 'Revenue metrics are not ready; metric_trust cannot prove field trust for this platform.', $metricTrustContext);
    } elseif ($trustedMetricTrustKeys !== []) {
        add_check($checks, 'trusted_fields_visible', 'proved', 'metric_trust is present with target-date source rows and ready revenue metrics.');
    } else {
        add_check($checks, 'trusted_fields_visible', 'missing', 'metric_trust has no saved-success keys.', $metricTrustContext);
        add_missing($result, $platform . '_metric_trust_missing', 'metric_trust is missing or empty.', [
            'platform' => $platform,
        ]);
    }

    if (array_key_exists('data_gaps', $metrics) && is_array($metrics['data_gaps'])) {
        add_check($checks, 'missing_fields_visible', 'proved', 'data_gaps is present for missing-field display.', [
            'gap_codes' => array_values(array_filter(array_column($metrics['data_gaps'], 'code'), 'is_string')),
        ]);
    } else {
        add_check($checks, 'missing_fields_visible', 'missing', 'data_gaps key is missing from metrics.');
        add_missing($result, $platform . '_data_gaps_missing', 'data_gaps key is missing from metrics.', [
            'platform' => $platform,
        ]);
    }

    if ($traffic === []) {
        add_check($checks, 'traffic_conversion_visible', 'missing', 'No traffic facts found; traffic/conversion diagnosis is not proved for this scope.');
        add_missing($result, $platform . '_traffic_facts_missing', 'No traffic facts found for same-day conversion diagnosis.', [
            'platform' => $platform,
        ]);
    } else {
        add_check($checks, 'traffic_conversion_visible', 'proved', 'Traffic and conversion facts are available.', [
            'traffic_rows' => count($traffic),
        ]);
    }

    return [
        'platform' => $platform,
        'filters' => $filters,
        'checks' => $checks,
        'source_rows' => [
            'count' => count($rows),
            'data_types' => data_types($rows),
            'latest_trace_time' => latest_time($rows),
            'sample_traces' => sample_traces($rows),
            'latest_available' => $latestAvailable,
        ],
        'etl' => [
            'status' => $dataset['status'] ?? null,
            'daily_facts' => count($daily),
            'traffic_facts' => count($traffic),
            'advertising_facts' => count($advertising),
            'quality_facts' => count($quality),
            'accepted_rows' => $dataset['data_quality']['accepted_rows'] ?? null,
            'rejected_rows' => count($dataset['data_quality']['rejected_rows'] ?? []),
        ],
        'metrics' => [
            'status' => $metrics['status'] ?? null,
            'totals' => $metrics['totals'] ?? [],
            'traffic' => $metrics['traffic'] ?? [],
            'advertising' => $metrics['advertising'] ?? [],
            'quality' => $metrics['quality'] ?? [],
            'data_gap_codes' => array_values(array_filter(array_column($metrics['data_gaps'] ?? [], 'code'), 'is_string')),
            'metric_trust_keys' => $trustedMetricTrustKeys,
            'reported_metric_trust_key_count' => count($reportedMetricTrustKeys),
        ],
    ];
}

/**
 * @param array<string, mixed> $metricTrust
 * @return array<int, string>
 */
function inspection_trusted_metric_trust_keys(array $metricTrust): array
{
    $keys = [];
    foreach ($metricTrust as $key => $trust) {
        $keyText = trim((string)$key);
        if ($keyText === '') {
            continue;
        }
        if (is_array($trust)) {
            if (($trust['saved_success'] ?? false) === true) {
                $keys[] = $keyText;
            }
            continue;
        }
        if ($trust === true) {
            $keys[] = $keyText;
        }
    }
    return array_values(array_unique($keys));
}

/**
 * @param array<int, array<string, mixed>> $platforms
 * @return array<int, array<string, mixed>>
 */
function build_collection_source_summary(array $platforms, string $targetDate = ''): array
{
    return array_values(array_map(static function (array $platform) use ($targetDate): array {
        $sourceRows = is_array($platform['source_rows'] ?? null) ? $platform['source_rows'] : [];
        $latestAvailable = is_array($sourceRows['latest_available'] ?? null) ? $sourceRows['latest_available'] : [];
        $etl = is_array($platform['etl'] ?? null) ? $platform['etl'] : [];
        $metrics = is_array($platform['metrics'] ?? null) ? $platform['metrics'] : [];
        $traffic = is_array($metrics['traffic'] ?? null) ? $metrics['traffic'] : [];
        $latestRelation = trim((string)($latestAvailable['date_relation'] ?? 'none'));
        if ($latestRelation === '') {
            $latestRelation = 'none';
        }

        return [
            'platform' => strtolower((string)($platform['platform'] ?? '')),
            'target_date' => $targetDate,
            'storage_table' => 'online_daily_data',
            'source_policy' => 'read_existing_online_daily_data_only',
            'metric_scope' => 'ota_channel',
            'target_date_rows' => (int)($sourceRows['count'] ?? 0),
            'target_date_data_types' => array_values(array_map('strval', (array)($sourceRows['data_types'] ?? []))),
            'target_date_latest_trace_time' => $sourceRows['latest_trace_time'] ?? null,
            'latest_available' => $latestAvailable === [] ? null : [
                'date' => $latestAvailable['date'] ?? null,
                'date_relation' => $latestRelation,
                'rows' => (int)($latestAvailable['count'] ?? 0),
                'data_types' => array_values(array_map('strval', (array)($latestAvailable['data_types'] ?? []))),
                'latest_trace_time' => $latestAvailable['latest_trace_time'] ?? null,
            ],
            'latest_available_reference_only' => $latestRelation !== 'target_date',
            'etl_status' => (string)($etl['status'] ?? 'unknown'),
            'daily_facts' => (int)($etl['daily_facts'] ?? 0),
            'traffic_rows' => (int)($traffic['rows'] ?? $etl['traffic_facts'] ?? 0),
            'metric_status' => (string)($metrics['status'] ?? 'unknown'),
            'collection_logic_changed' => false,
        ];
    }, $platforms));
}

/**
 * @return array<string, mixed>
 */
function read_json_file(string $path): array
{
    global $root;
    $fullPath = $path;
    if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $path) && !str_starts_with($path, DIRECTORY_SEPARATOR)) {
        $fullPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
    if (!is_file($fullPath)) {
        throw new RuntimeException('Evidence file does not exist: ' . $path);
    }
    $decoded = json_decode((string)file_get_contents($fullPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Evidence file is not valid JSON: ' . $path);
    }
    return $decoded;
}

/**
 * @param array<string, mixed> $section
 * @return array{matched: bool, date: ?string, status: string}
 */
function external_section_scope_date(array $section, string $expectedDate, ?string $fallbackDate, bool $fallbackMatched): array
{
    $explicitStatus = trim((string)($section['scope_date_status'] ?? ''));
    $sectionScope = is_array($section['scope'] ?? null) ? $section['scope'] : [];
    $sectionDate = trim((string)($section['scope_date'] ?? $sectionScope['date'] ?? ''));
    if ($explicitStatus !== '') {
        return [
            'matched' => $explicitStatus === 'matched',
            'date' => $sectionDate !== '' ? $sectionDate : null,
            'status' => $explicitStatus,
        ];
    }
    if ($sectionDate !== '') {
        return [
            'matched' => $sectionDate === $expectedDate,
            'date' => $sectionDate,
            'status' => $sectionDate === $expectedDate ? 'matched' : 'mismatch',
        ];
    }
    return [
        'matched' => $fallbackMatched,
        'date' => $fallbackDate,
        'status' => $fallbackMatched ? 'matched' : 'mismatch',
    ];
}

function inspection_diagnosis_action_item_statuses(array $diagnosis): array
{
    return array_values(array_filter(array_map(
        static fn($item): string => is_array($item) ? trim((string)($item['status'] ?? '')) : '',
        (array)($diagnosis['action_items'] ?? [])
    ), static fn(string $status): bool => $status !== ''));
}

function inspection_is_blocked_diagnosis_action_status(string $status): bool
{
    return $status === 'blocked' || str_starts_with($status, 'blocked_');
}

function inspection_actionable_diagnosis_action_count(array $diagnosis): int
{
    $count = 0;
    foreach ((array)($diagnosis['action_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $status = trim((string)($item['status'] ?? ''));
        $action = trim((string)($item['action'] ?? $item['title'] ?? ''));
        if ($action !== '' && !inspection_is_blocked_diagnosis_action_status($status)) {
            $count++;
        }
    }
    return $count;
}

function build_inspection_blocked_diagnosis_evidence(array $result, array $options): ?array
{
    $missingCodes = array_values(array_filter(array_map(
        static fn($item): string => is_array($item) ? (string)($item['code'] ?? '') : '',
        (array)($result['missing_requirements'] ?? [])
    ), static function (string $code): bool {
        return str_contains($code, 'source_rows_missing')
            || str_contains($code, 'etl_not_ready')
            || str_contains($code, 'revenue_metrics_not_ready')
            || str_contains($code, 'traffic_facts_missing')
            || str_contains($code, 'data_gaps_missing');
    }));
    $missingCodes = array_values(array_unique($missingCodes));
    if ($missingCodes === []) {
        return null;
    }

    $platformSources = [];
    foreach ((array)($result['platforms'] ?? []) as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $platformName = (string)($platform['platform'] ?? '');
        if ($platformName === '') {
            continue;
        }
        $metrics = is_array($platform['metrics'] ?? null) ? $platform['metrics'] : [];
        $traffic = is_array($metrics['traffic'] ?? null) ? $metrics['traffic'] : [];
        $platformSources[] = [
            'ref' => 'phase1_' . $platformName . '_target_date_evidence',
            'source_policy' => 'read_only_online_daily_data_and_metric_summary',
            'metric_scope' => 'ota_channel',
            'date' => (string)$options['date'],
            'platform' => $platformName,
            'target_date_rows' => (int)($platform['source_rows']['count'] ?? 0),
            'etl_status' => (string)($platform['etl']['status'] ?? 'unknown'),
            'revenue_status' => (string)($metrics['status'] ?? 'unknown'),
            'traffic_rows' => (int)($traffic['rows'] ?? 0),
            'latest_available' => $platform['source_rows']['latest_available'] ?? null,
        ];
    }

    $evidenceSources = array_merge([[
        'ref' => 'phase1_verified_ota_gap_scope',
        'source_policy' => 'generated_blocked_from_verified_missing_requirements',
        'metric_scope' => 'ota_channel',
        'date' => (string)$options['date'],
    ]], $platformSources);

    return [
        'scope' => [
            'date' => (string)$options['date'],
            'metric_scope' => 'ota_channel',
        ],
        'ota_diagnosis' => [
            'source' => '/api/agent/ota-diagnosis',
            'status' => 'blocked_by_verified_ota_gaps',
            'source_policy' => 'generated_blocked_from_verified_missing_requirements',
            'scope' => [
                'date' => (string)$options['date'],
                'metric_scope' => 'ota_channel',
            ],
            'summary' => '上游 OTA 数据和指标证据未闭合，当前不能生成确定 AI 经营建议。',
            'evidence_sources' => $evidenceSources,
            'data_gaps' => array_values(array_map(static fn(string $code): array => [
                'code' => $code,
                'message' => 'Phase-one OTA closure blocker: ' . $code,
                'scope' => 'ota_channel',
            ], $missingCodes)),
            'action_items' => [[
                'id' => 'phase1_ai_diagnosis_blocked_by_ota_gaps',
                'action' => '先处理目标日 OTA 数据、收益指标或流量/转化事实缺口，再重新生成 OTA AI 诊断。',
                'status' => 'blocked_by_verified_ota_gaps',
                'evidence_refs' => array_values(array_map(static fn(array $source): string => (string)$source['ref'], $evidenceSources)),
                'blocking_missing_codes' => $missingCodes,
                'source_policy' => 'not_execution_ready_until_ota_evidence_closed',
            ]],
        ],
        'operation_execution' => [
            'source' => '/api/operation/execution-intents',
            'status' => 'missing_real_api_response',
            'execution_intents' => [],
            'execution_flow' => [],
        ],
    ];
}

/**
 * @param array<string, mixed> $evidence
 * @param array<string, mixed> $options
 * @param array<string, mixed> $result
 * @return array<int, array<string, mixed>>
 */
function validate_external_evidence(array $evidence, array $options, array &$result): array
{
    $checks = [];
    $scope = is_array($evidence['scope'] ?? null) ? $evidence['scope'] : [];
    $scopeDate = trim((string)($scope['date'] ?? ''));
    $scopeDateMatched = $scopeDate === (string)$options['date'];
    if ($scopeDateMatched) {
        add_check($checks, 'evidence_scope_date', 'proved', 'Evidence date matches requested scope.');
    } else {
        add_check($checks, 'evidence_scope_date', 'missing', 'Evidence date does not match requested scope.', [
            'expected' => $options['date'],
            'actual' => $scopeDate !== '' ? $scopeDate : null,
        ]);
        add_missing($result, 'evidence_scope_date_mismatch', 'Evidence date does not match requested scope.');
    }

    $diagnosis = is_array($evidence['ota_diagnosis'] ?? null) ? $evidence['ota_diagnosis'] : [];
    $diagnosisScopeDate = external_section_scope_date($diagnosis, (string)$options['date'], $scopeDate !== '' ? $scopeDate : null, $scopeDateMatched);
    $diagnosisEvidence = $diagnosis['evidence_sources'] ?? [];
    $diagnosisActions = $diagnosis['action_items'] ?? [];
    $diagnosisContentPresent = is_array($diagnosisEvidence) && $diagnosisEvidence !== [] && is_array($diagnosisActions) && $diagnosisActions !== [] && array_key_exists('data_gaps', $diagnosis);
    $diagnosisActionableCount = inspection_actionable_diagnosis_action_count($diagnosis);
    $diagnosisBlockedCount = count(array_filter(
        inspection_diagnosis_action_item_statuses($diagnosis),
        static fn(string $status): bool => inspection_is_blocked_diagnosis_action_status($status)
    ));
    if ($diagnosisContentPresent && $diagnosisScopeDate['matched']) {
        $diagnosisDetails = [
            'evidence_source_count' => count((array)$diagnosisEvidence),
            'data_gap_count' => count((array)($diagnosis['data_gaps'] ?? [])),
            'action_item_count' => count((array)$diagnosisActions),
            'actionable_action_item_count' => $diagnosisActionableCount,
            'blocked_action_item_count' => $diagnosisBlockedCount,
            'action_item_statuses' => inspection_diagnosis_action_item_statuses($diagnosis),
            'scope_date_status' => 'matched',
            'scope_date' => $diagnosisScopeDate['date'],
            'expected_scope_date' => $options['date'],
        ];
        if ($diagnosisActionableCount > 0) {
            add_check($checks, 'ai_diagnosis_evidence', 'proved', 'OTA diagnosis carries evidence_sources, data_gaps, and actionable action_items.', $diagnosisDetails);
        } else {
            add_check($checks, 'ai_diagnosis_evidence', 'warning', 'OTA diagnosis evidence exists, but action_items are blocked.', $diagnosisDetails);
            add_missing($result, 'ai_diagnosis_action_items_blocked', 'OTA diagnosis action_items are blocked and cannot be used as executable advice.', $diagnosisDetails);
        }
    } elseif ($diagnosisContentPresent) {
        add_check($checks, 'ai_diagnosis_evidence', 'warning', 'OTA diagnosis evidence exists, but its scope.date does not match requested scope.', [
            'expected_scope_date' => $options['date'],
            'scope_date' => $diagnosisScopeDate['date'],
            'scope_date_status' => $diagnosisScopeDate['status'],
            'evidence_source_count' => count((array)$diagnosisEvidence),
            'data_gap_count' => count((array)($diagnosis['data_gaps'] ?? [])),
            'action_item_count' => count((array)$diagnosisActions),
            'actionable_action_item_count' => $diagnosisActionableCount,
            'blocked_action_item_count' => $diagnosisBlockedCount,
            'action_item_statuses' => inspection_diagnosis_action_item_statuses($diagnosis),
        ]);
        if ($scopeDateMatched) {
            add_missing($result, 'evidence_scope_date_mismatch', 'OTA diagnosis evidence date does not match requested scope.');
        }
    } else {
        add_check($checks, 'ai_diagnosis_evidence', 'missing', 'OTA diagnosis evidence sample is incomplete.');
        add_missing($result, 'ai_diagnosis_evidence_sample_missing', 'OTA diagnosis needs evidence_sources, data_gaps, and action_items.');
    }

    $operation = is_array($evidence['operation_execution'] ?? null) ? $evidence['operation_execution'] : [];
    $operationScopeDate = external_section_scope_date($operation, (string)$options['date'], $scopeDate !== '' ? $scopeDate : null, $scopeDateMatched);
    $operationCounts = inspection_operation_signal_counts($operation);
    $operationStatus = inspection_operation_evidence_status($operation);
    if ($operationStatus !== 'missing' && !$operationScopeDate['matched']) {
        add_check($checks, 'operation_execution_sample', 'warning', 'Operation execution evidence exists, but its scope.date does not match requested scope.', array_merge($operationCounts, [
            'expected_scope_date' => $options['date'],
            'scope_date' => $operationScopeDate['date'],
            'scope_date_status' => $operationScopeDate['status'],
        ]));
        if ($scopeDateMatched) {
            add_missing($result, 'evidence_scope_date_mismatch', 'Operation execution evidence date does not match requested scope.');
        }
    } elseif ($operationStatus === 'proved') {
        add_check($checks, 'operation_execution_sample', 'proved', 'Operation execution evidence carries approval, execution evidence, review, or ROI signal.', $operationCounts);
    } elseif ($operationStatus === 'warning') {
        $operationBlockingCode = inspection_operation_blocking_code($operation);
        if ($operationBlockingCode === 'operation_execution_ai_action_link_missing') {
            add_check($checks, 'operation_execution_sample', 'warning', 'Operation execution sample exists but is not linked to OTA diagnosis action_items.', $operationCounts);
            add_missing($result, 'operation_execution_ai_action_link_missing', 'Operation execution sample must link to OTA diagnosis action_items before it can prove the operating loop.', $operationCounts);
        } else {
            add_check($checks, 'operation_execution_sample', 'warning', 'Operation execution sample exists but lacks approval, execution evidence, review, or ROI signal.', $operationCounts);
            add_missing($result, 'operation_execution_evidence_incomplete', 'Operation execution sample needs approval, execution evidence, review, or ROI signal.', $operationCounts);
        }
    } else {
        add_check($checks, 'operation_execution_sample', 'missing', 'Operation execution sample is missing.');
        add_missing($result, 'operation_execution_sample_missing', 'Operation loop needs an execution intent or execution-flow sample.');
    }

    return $checks;
}

/**
 * @param array<int, array<string, mixed>> $questions
 * @param array<int, array<string, mixed>> $actions
 * @return array<int, array<string, mixed>>
 */
function with_inspection_employee_question_action_codes(array $questions, array $actions): array
{
    $actionsByQuestion = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $actionCode = (string)($action['action_code'] ?? '');
        if ($actionCode === '') {
            continue;
        }
        $directQuestionKey = (string)($action['question_key'] ?? '');
        $questionKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge([$directQuestionKey], (array)($action['related_question_keys'] ?? []))
        ))));
        foreach ($questionKeys as $questionKey) {
            if (!isset($actionsByQuestion[$questionKey])) {
                $actionsByQuestion[$questionKey] = [
                    'codes' => [],
                    'primary_action' => null,
                    'direct_action' => null,
                    'blocked_action_codes' => [],
                ];
            }
            $actionsByQuestion[$questionKey]['codes'][] = $actionCode;
            if (!is_array($actionsByQuestion[$questionKey]['primary_action'])) {
                $actionsByQuestion[$questionKey]['primary_action'] = $action;
            }
            if ($directQuestionKey === $questionKey && !is_array($actionsByQuestion[$questionKey]['direct_action'])) {
                $actionsByQuestion[$questionKey]['direct_action'] = $action;
            }
            if ((string)($action['status'] ?? '') === 'blocked') {
                $actionsByQuestion[$questionKey]['blocked_action_codes'][] = $actionCode;
            }
        }
    }

    return array_values(array_map(static function (array $question) use ($actionsByQuestion): array {
        $key = (string)($question['key'] ?? '');
        $summary = is_array($actionsByQuestion[$key] ?? null) ? $actionsByQuestion[$key] : [];
        $actionCodes = array_values(array_unique((array)($summary['codes'] ?? [])));
        $question['next_action_codes'] = $actionCodes;
        $evidence = is_array($question['evidence'] ?? null) ? $question['evidence'] : [];
        $evidence['linked_action_count'] = count($actionCodes);
        $directAction = is_array($summary['direct_action'] ?? null)
            ? $summary['direct_action']
            : ($summary['primary_action'] ?? null);
        foreach ([
            'primary_next_action' => $summary['primary_action'] ?? null,
            'direct_next_action' => $directAction,
        ] as $prefix => $action) {
            if (!is_array($action)) {
                continue;
            }
            $fields = [
                $prefix . '_code' => (string)($action['action_code'] ?? ''),
                $prefix . '_family' => (string)($action['action_family'] ?? ''),
                $prefix . '_entry' => (string)($action['entry'] ?? ''),
                $prefix . '_success_criteria' => (string)($action['success_criteria'] ?? ''),
                $prefix . '_status' => (string)($action['status'] ?? ''),
            ];
            foreach ($fields as $field => $value) {
                if ($value === '') {
                    continue;
                }
                $question[$field] = $value;
                $evidence[$field] = $value;
            }
            $entryOptions = array_values(array_filter(
                (array)($action['entry_options'] ?? []),
                static fn($option): bool => is_array($option) && (string)($option['entry'] ?? '') !== ''
            ));
            if ($entryOptions !== []) {
                $question[$prefix . '_entry_options'] = $entryOptions;
                $evidence[$prefix . '_entry_options'] = $entryOptions;
            }
            foreach ([
                $prefix . '_related_question_keys' => $action['related_question_keys'] ?? [],
                $prefix . '_resolves_missing_codes' => $action['resolves_missing_codes'] ?? [],
                $prefix . '_live_closure_gap_codes' => $action['live_closure_gap_codes'] ?? [],
            ] as $field => $values) {
                $normalizedValues = array_values(array_unique(array_filter(array_map('strval', (array)$values))));
                if ($normalizedValues === []) {
                    continue;
                }
                $question[$field] = $normalizedValues;
                $evidence[$field] = $normalizedValues;
            }
        }
        $blockedActionCodes = array_values(array_unique((array)($summary['blocked_action_codes'] ?? [])));
        if ($blockedActionCodes !== []) {
            $question['blocked_action_codes'] = $blockedActionCodes;
            $evidence['blocked_action_codes'] = $blockedActionCodes;
        }
        $blockingGapCodes = [];
        foreach ([
            $evidence['blocking_missing_codes'] ?? [],
            $evidence['operation_blocking_missing_codes'] ?? [],
            $evidence['metric_domain_gap_codes'] ?? [],
            $evidence['direct_next_action_resolves_missing_codes'] ?? [],
            $evidence['primary_next_action_resolves_missing_codes'] ?? [],
        ] as $values) {
            foreach ((array)$values as $value) {
                $code = trim((string)$value);
                if ($code !== '') {
                    $blockingGapCodes[] = $code;
                }
            }
        }
        $blockingGapCodes = array_values(array_unique($blockingGapCodes));
        if (!in_array((string)($question['status'] ?? ''), ['proved', 'no_gap_reported'], true) && $blockingGapCodes !== []) {
            $question['blocking_gap_codes'] = $blockingGapCodes;
            $evidence['blocking_gap_codes'] = $blockingGapCodes;
        }
        $rawEmployeeDetail = (string)($question['employee_detail'] ?? $question['detail'] ?? '');
        $question['employee_detail'] = inspection_employee_readable_copy($rawEmployeeDetail !== ''
            ? $rawEmployeeDetail
            : inspection_employee_question_detail($question));
        $question['employee_next_action'] = inspection_employee_readable_copy((string)($question['employee_next_action'] ?? $question['next_action'] ?? ''));
        $question['evidence'] = $evidence;
        return $question;
    }, $questions));
}

function inspection_employee_readable_copy(string $text): string
{
    if ($text === '') {
        return '';
    }

    return strtr($text, [
        'CTRIP' => '携程',
        'MEITUAN' => '美团',
        'ctrip_source_rows_missing' => '携程目标日源数据缺失',
        'meituan_source_rows_missing' => '美团目标日源数据缺失',
        'ctrip_target_date_source_rows_missing' => '携程目标日源数据缺失',
        'meituan_target_date_source_rows_missing' => '美团目标日源数据缺失',
        'ctrip_etl_not_ready' => '携程标准事实层未就绪',
        'meituan_etl_not_ready' => '美团标准事实层未就绪',
        'ctrip_revenue_metrics_not_ready' => '携程收益指标未就绪',
        'meituan_revenue_metrics_not_ready' => '美团收益指标未就绪',
        'ctrip_traffic_facts_missing' => '携程流量/转化事实缺失',
        'meituan_traffic_facts_missing' => '美团流量/转化事实缺失',
        'ai_diagnosis_evidence_sample_missing' => 'AI 诊断证据样例缺失',
        'ai_diagnosis_action_items_blocked' => 'AI 动作项被上游缺口阻断',
        'ai_action_items_blocked' => 'AI 动作项被上游缺口阻断',
        'ai_action_items_missing' => 'AI 动作项缺失',
        'operation_execution_sample_missing' => '运营执行样例缺失',
        'operation_execution_ai_action_link_missing' => '运营执行未关联 OTA 诊断动作',
        'operation_execution_evidence_incomplete' => '运营执行证据不完整',
        '按 metric_trust 判断' => '按指标可信证据判断',
        '按 data_gaps 处理' => '按数据缺口处理',
        '按 数据缺口 处理' => '按数据缺口处理',
        '非 blocked action_items' => '非阻断动作项',
        'non blocked action_items' => '非阻断动作项',
        '非阻断 action_items' => '非阻断动作项',
        '非阻断 动作项' => '非阻断动作项',
        '非 blocked 动作项' => '非阻断动作项',
        'blocked action_items' => '阻断动作项',
        'blocked 动作项' => '阻断动作项',
        '为 blocked' => '为阻断',
        'blocked_by 上游缺口' => '上游阻断缺口',
        'blocked_by' => '上游阻断',
        'OTA 诊断 action_items' => 'OTA 诊断动作项',
        'AI action_items' => 'AI 动作项',
        'collection-reliability.source_date_evidence' => '采集可靠性里的目标日来源证据',
        'source_date_evidence' => '目标日来源证据',
        'evidence_sources/data_gaps/action_items' => '证据来源、数据缺口和动作项',
        'evidence_sources、data_gaps、action_items' => '证据来源、数据缺口、动作项',
        'evidence_sources、data_gaps 和 action_items' => '证据来源、数据缺口和动作项',
        'evidence_sources' => '证据来源',
        'evidence_refs' => '证据引用',
        'source_module=ota_diagnosis' => '来源模块=OTA 诊断',
        'source=ota_diagnosis#action_item' => '来源=OTA 诊断动作项',
        'latest_available' => '最近可用数据',
        'ETL status=ready' => '标准化状态已就绪',
        'revenue_status=ready' => '收益状态已就绪',
        'traffic_status=ready' => '流量状态已就绪',
        'conversion_status=ready' => '转化状态已就绪',
        'status=ready' => '状态已就绪',
        'OTA diagnosis' => 'OTA 诊断',
        'source_trace_id' => '来源追踪标识',
        'sync_task_id' => '同步任务标识',
        'data_source_id' => '数据来源标识',
        'data_gaps' => '数据缺口',
        'action_items' => '动作项',
        'action_item_id' => '动作项标识',
        'approval.status=approved' => '审批已通过',
        'execution.status=executed' => '执行已完成',
        'evidence.count>0' => '已有执行证据',
        'review.status' => '复盘状态',
        'execution_intents' => '执行意图',
        'execution_flow' => '执行流程',
        'metric_trust' => '指标可信证据',
        'raw_data' => '脱敏原始响应追踪',
        'data_type' => '数据类型',
        'accepted_rows' => '已接收行数',
        'rejected_rows' => '已拒绝行数',
        'validation_flags' => '校验标记',
        'online_daily_data' => 'OTA 日数据表',
        'source_date_evidence.platforms' => '目标日来源证据平台列表',
        'target_date_rows' => '目标日入库行数',
        'target_date_data_types' => '目标日数据类型',
        'revenue_metrics' => '收益指标',
        'amount' => '收入金额',
        'quantity' => '间夜数量',
        'room_nights' => '间夜数',
        'book_order_num' => '订单数',
        'order_count' => '订单数',
        'list_exposure' => '列表曝光',
        'detail_exposure' => '详情曝光',
        'flow_rate' => '流量转化率',
        'order_filling_num' => '填单数',
        'order_submit_num' => '提交订单数',
        'totals' => '收益汇总',
    ]);
}

function inspection_employee_question_detail(array $question): string
{
    $key = (string)($question['key'] ?? $question['question'] ?? '');
    $status = (string)($question['status'] ?? '');
    $evidence = is_array($question['evidence'] ?? null) ? $question['evidence'] : [];
    $missingPlatforms = inspection_employee_platform_list_text((array)($evidence['missing_platforms'] ?? []));
    $revenueReadyPlatforms = inspection_employee_platform_list_text((array)($evidence['revenue_ready_platforms'] ?? []));

    return match ($key) {
        'today_ota_collected' => $status === 'proved'
            ? '目标日携程和美团 OTA 数据均有入库证据；最近可用数据只作参考。'
            : '目标日 OTA 数据尚未完整证明' . ($missingPlatforms !== '' ? '，缺失平台：' . $missingPlatforms : '') . '；最近可用或历史数据不能替代目标日入库。',
        'trusted_fields' => $status === 'proved'
            ? '字段可信度已有目标日入库、字段资产、数据质量和指标可信证据支撑。'
            : '字段可信度仍受目标日源数据、字段资产、数据质量或指标可信证据缺口影响；未证明字段不能写成可信。',
        'missing_fields' => ((array)($evidence['data_gap_codes'] ?? [])) !== [] || ((array)($evidence['missing_field_codes'] ?? [])) !== []
            ? '字段缺口已显式列出；按数据缺口处理，不用 0 或空值兜底。'
            : '当前未返回字段缺口；仍以目标日采集和指标可信证据为准，不代表所有平台字段完备。',
        'revenue_traffic_conversion' => $status === 'proved'
            ? '收益、流量、转化均可基于目标日 OTA 事实复核。'
            : (($revenueReadyPlatforms !== '' ? '收益可先复核：' . $revenueReadyPlatforms . '；' : '') . '流量或转化事实不足的平台不得输出确定漏斗判断。'),
        'ai_evidence' => $status === 'proved'
            ? 'AI 建议已有证据来源、数据缺口和可执行动作项支撑。'
            : 'AI 依据被上游 OTA 证据缺口阻断；当前只能定位缺口，不能当作可执行经营建议。',
        'next_operation_action' => $status === 'proved'
            ? '运营动作已追溯到 OTA 诊断，并有审批、执行证据、复盘或 ROI 信号。'
            : '执行闭环尚未证明；必须先有可执行 AI 动作项，再保留审批、执行证据和复盘。',
        default => '当前员工问题尚未形成完整说明；按动作队列补齐证据后重新巡检。',
    };
}

function inspection_employee_platform_list_text(array $platforms): string
{
    $labels = [];
    foreach ($platforms as $platform) {
        $value = strtolower(trim((string)$platform));
        $label = match ($value) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => inspection_employee_readable_copy((string)$platform),
        };
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return implode('、', array_values(array_unique($labels)));
}

function inspection_missing_field_summary(array $dataGapCodes, array $missingFieldCodes): array
{
    $sourcesByCode = [];
    foreach ($dataGapCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $sourcesByCode[$code]['data_gap_codes'] = true;
        }
    }
    foreach ($missingFieldCodes as $code) {
        $code = trim((string)$code);
        if ($code !== '') {
            $sourcesByCode[$code]['missing_field_codes'] = true;
        }
    }

    $summary = [];
    foreach ($sourcesByCode as $code => $sources) {
        $sourceKeys = array_keys($sources);
        $summary[] = [
            'code' => $code,
            'label' => inspection_missing_field_label($code),
            'source_keys' => $sourceKeys,
            'source_text' => inspection_missing_field_source_text($sourceKeys),
            'business_impact' => inspection_missing_field_business_impact($code),
            'next_action' => inspection_missing_field_next_action($code, $sourceKeys),
            'policy' => '显式保留缺口；不使用 0、空值或成功状态替代。',
        ];
    }

    return $summary;
}

function inspection_missing_field_label(string $code): string
{
    return [
        'available_room_nights_missing' => '可售房晚缺失',
        'commission_fields_missing' => '佣金字段缺失',
        'net_revenue_fields_missing' => '净收入字段缺失',
        'lead_time_fields_missing' => '提前预订字段缺失',
        'cancellation_fields_missing' => '取消字段缺失',
        'cancel_room_nights_missing' => '取消房晚缺失',
        'competitor_price_fields_missing' => '竞品价格字段缺失',
    ][$code] ?? ($code !== '' ? '未识别字段缺口' : '未命名缺口');
}

function inspection_missing_field_business_impact(string $code): string
{
    return [
        'available_room_nights_missing' => '缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准。',
        'commission_fields_missing' => '缺佣金金额或佣金率，暂不能核算净收入和渠道成本。',
        'net_revenue_fields_missing' => '缺净收入输入，暂不能输出净 RevPAR 或真实到手收入。',
        'lead_time_fields_missing' => '缺提前预订天数，暂不能判断提前期结构和临近入住风险。',
        'cancellation_fields_missing' => '缺取消订单或取消金额，暂不能判断取消对收入的影响。',
        'cancel_room_nights_missing' => '缺取消房晚，暂不能计算房晚取消率。',
        'competitor_price_fields_missing' => '缺竞品价格，暂不能做竞品价差和调价判断。',
    ][$code] ?? '该缺口需要补齐字段定义或目标日样本后再判断。';
}

function inspection_missing_field_next_action(string $code, array $sourceKeys): string
{
    if (preg_match('/available_room_nights|net_revenue|commission|lead_time|cancellation|cancel_room_nights|competitor_price/i', $code)) {
        return '按字段资产核对平台返回和入库字段，再重跑收益指标核验。';
    }
    if (in_array('missing_field_codes', $sourceKeys, true)) {
        return '按字段缺口清单补齐字段定义或样本证据。';
    }
    return '按数据缺口清单补齐目标日证据后复跑诊断。';
}

function inspection_missing_field_source_text(array $sourceKeys): string
{
    $hasDataGap = in_array('data_gap_codes', $sourceKeys, true);
    $hasFieldGap = in_array('missing_field_codes', $sourceKeys, true);
    if ($hasDataGap && $hasFieldGap) {
        return '数据缺口 / 字段缺口';
    }
    return $hasFieldGap ? '字段缺口' : '数据缺口';
}

function inspection_metric_domain_summary(array $metricDomainReadiness): array
{
    $summary = [];
    foreach ($metricDomainReadiness as $row) {
        if (!is_array($row)) {
            continue;
        }
        $platform = strtolower(trim((string)($row['platform'] ?? '')));
        if ($platform === '') {
            continue;
        }
        $sourceRows = max(0, (int)($row['source_rows'] ?? $row['target_date_rows'] ?? 0));
        $trafficRows = max(0, (int)($row['traffic_rows'] ?? 0));
        $revenueReady = (string)($row['revenue_status'] ?? '') === 'ready';
        $trafficReady = (string)($row['traffic_status'] ?? '') === 'ready';
        $conversionReady = (string)($row['conversion_status'] ?? '') === 'ready';
        $targetTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($row['target_date_data_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        $missingDomains = array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($row['missing_domains'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
        $dataTypeText = inspection_metric_domain_data_type_list_text($targetTypes);

        $summary[] = [
            'platform' => $platform,
            'platform_label' => inspection_metric_domain_platform_text($platform),
            'revenue_text' => inspection_metric_domain_status_text((string)($row['revenue_status'] ?? 'missing')),
            'traffic_text' => inspection_metric_domain_status_text((string)($row['traffic_status'] ?? 'missing')),
            'conversion_text' => inspection_metric_domain_status_text((string)($row['conversion_status'] ?? 'missing')),
            'missing_text' => inspection_metric_domain_missing_list_text($missingDomains),
            'source_text' => '目标日源数据 ' . $sourceRows . ' 行 / 流量事实 ' . $trafficRows . ' 行',
            'problem' => inspection_metric_domain_problem_text($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
            'next_action' => inspection_metric_domain_next_action_text($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
            'policy' => '只读目标日 OTA 指标域' . ($dataTypeText !== '' ? ' / ' . $dataTypeText : '') . '；缺失时不输出确定结论。',
        ];
    }

    return $summary;
}

function inspection_metric_domain_platform_text(string $platform): string
{
    return match (strtolower(trim($platform))) {
        'ctrip' => '携程',
        'meituan' => '美团',
        default => $platform !== '' ? 'OTA 平台' : 'OTA',
    };
}

function inspection_metric_domain_status_text(string $status): string
{
    return strtolower(trim($status)) === 'ready' ? '可复核' : '缺失';
}

function inspection_metric_domain_data_type_list_text(array $types): string
{
    $labels = [];
    foreach ($types as $type) {
        $raw = strtolower(trim((string)$type));
        $labels[] = match (true) {
            in_array($raw, ['business', 'business_overview', 'revenue', 'order', 'orders'], true) => '经营/收益',
            in_array($raw, ['traffic', 'flow', 'flow_data'], true) => '流量/转化',
            in_array($raw, ['advertising', 'ads'], true) => '广告',
            in_array($raw, ['quality', 'quality_psi'], true) => '服务质量',
            in_array($raw, ['review', 'comment'], true) => '点评',
            $raw !== '' => '未识别数据类型',
            default => '',
        };
    }

    return implode('、', array_values(array_unique(array_filter($labels))));
}

function inspection_metric_domain_missing_list_text(array $domains): string
{
    $labels = [];
    foreach ($domains as $domain) {
        $labels[] = match (strtolower(trim((string)$domain))) {
            'revenue' => '收益',
            'traffic' => '流量',
            'conversion' => '转化',
            default => '',
        };
    }

    return implode('、', array_values(array_unique(array_filter($labels))));
}

function inspection_metric_domain_problem_text(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
{
    if ($revenueReady && $trafficReady && $conversionReady) {
        return '收益、流量、转化均可复核。';
    }
    if ($sourceRows <= 0) {
        return '目标日源数据缺失，收益、流量、转化都不能证明。';
    }
    if ($revenueReady && (!$trafficReady || !$conversionReady || $trafficRows <= 0)) {
        return '收益可先复核；流量/转化缺失，不能判断曝光到下单漏斗。';
    }
    if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
        return '流量/转化缺失，不能判断曝光、访问或下单转化问题。';
    }
    return '收益指标缺失，不能输出收入问题结论。';
}

function inspection_metric_domain_next_action_text(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
{
    if ($revenueReady && $trafficReady && $conversionReady) {
        return '可进入 OTA 经营诊断。';
    }
    if ($sourceRows <= 0) {
        return '先补目标日 OTA 源数据，再复跑收益指标核验。';
    }
    if (!$revenueReady) {
        return '复核标准事实层和收益指标输入。';
    }
    if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
        return '补齐流量/转化事实，再复核漏斗诊断。';
    }
    return '按缺口补齐目标日证据后复跑诊断。';
}

/**
 * @param array<string, mixed> $result
 * @return array<int, array<string, mixed>>
 */
function build_inspection_employee_questions(array $result): array
{
    $platforms = is_array($result['platforms'] ?? null) ? $result['platforms'] : [];
    $platformCounts = [];
    $platformFieldTrust = [];
    $sourceRows = 0;
    $metricTrustKeys = [];
    $dataGapCodes = [];
    $trafficRows = 0;
    $hasReadyMetrics = false;
    $metricDomainReadiness = [];

    foreach ($platforms as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $metrics = is_array($platform['metrics'] ?? null) ? $platform['metrics'] : [];
        $traffic = is_array($metrics['traffic'] ?? null) ? $metrics['traffic'] : [];
        $rowCount = (int)($platform['source_rows']['count'] ?? 0);
        $metricStatus = (string)($metrics['status'] ?? 'unknown');
        $platformTrafficRows = (int)($traffic['rows'] ?? 0);
        $platformRevenueReady = $metricStatus === 'ready';
        $platformTrafficReady = $platformTrafficRows > 0;
        $platformName = strtolower(trim((string)($platform['platform'] ?? '')));
        $platformTrustKeys = $rowCount > 0 && $metricStatus === 'ready'
            ? array_values(array_filter(array_map('strval', (array)($metrics['metric_trust_keys'] ?? []))))
            : [];
        $reportedTrustKeyCount = (int)($metrics['reported_metric_trust_key_count'] ?? count((array)($metrics['metric_trust_keys'] ?? [])));
        $platformTrustStatus = match (true) {
            $rowCount <= 0 => 'target_date_source_missing',
            $metricStatus === 'ready' && $platformTrustKeys !== [] => 'metric_trust_ready',
            $reportedTrustKeyCount > 0 => 'metric_trust_reference_only',
            default => 'metric_trust_missing',
        };
        $platformTrustReasons = [];
        if ($rowCount <= 0 && $platformName !== '') {
            $platformTrustReasons[] = $platformName . '_source_rows_missing';
        }
        if ($metricStatus !== 'ready' && $platformName !== '') {
            $platformTrustReasons[] = $platformName . '_revenue_metrics_not_ready';
        }
        if ($platformTrustKeys === [] && $reportedTrustKeyCount <= 0 && $platformName !== '') {
            $platformTrustReasons[] = $platformName . '_metric_trust_missing';
        }
        if ($platformTrustKeys === [] && $reportedTrustKeyCount > 0 && $platformName !== '') {
            $platformTrustReasons[] = $platformName . '_metric_trust_not_proved';
        }
        $missingDomains = [];
        if (!$platformRevenueReady) {
            $missingDomains[] = 'revenue';
        }
        if (!$platformTrafficReady) {
            $missingDomains[] = 'traffic';
            $missingDomains[] = 'conversion';
        }
        $sourceRows += $rowCount;
        $trafficRows += $platformTrafficRows;
        $hasReadyMetrics = $hasReadyMetrics || $platformRevenueReady;
        foreach ((array)($metrics['metric_trust_keys'] ?? []) as $key) {
            $metricTrustKeys[(string)$key] = true;
        }
        foreach ((array)($metrics['data_gap_codes'] ?? []) as $code) {
            $dataGapCodes[(string)$code] = true;
        }
        $latestAvailable = is_array($platform['source_rows']['latest_available'] ?? null)
            ? $platform['source_rows']['latest_available']
            : [];
        $platformCounts[] = [
            'platform' => (string)($platform['platform'] ?? ''),
            'source_rows' => $rowCount,
            'target_date_rows' => $rowCount,
            'etl_status' => (string)($platform['etl']['status'] ?? 'unknown'),
            'metric_status' => $metricStatus,
            'traffic_rows' => $platformTrafficRows,
            'latest_available' => $latestAvailable ?: null,
            'latest_available_date' => $latestAvailable['date'] ?? null,
            'latest_available_date_relation' => $latestAvailable['date_relation'] ?? null,
        ];
        $platformFieldTrust[] = [
            'platform' => (string)($platform['platform'] ?? ''),
            'target_date_rows' => $rowCount,
            'metric_status' => $metricStatus,
            'field_trust_status' => $platformTrustStatus,
            'metric_trust_key_count' => count($platformTrustKeys),
            'metric_trust_keys' => $platformTrustKeys,
            'reported_metric_trust_key_count' => $reportedTrustKeyCount,
            'reason_codes' => array_values(array_unique($platformTrustReasons)),
            'source_policy' => 'target_date_rows_plus_metric_trust_required',
        ];
        $metricDomainReadiness[] = [
            'platform' => (string)($platform['platform'] ?? ''),
            'revenue_status' => $platformRevenueReady ? 'ready' : 'missing',
            'traffic_status' => $platformTrafficReady ? 'ready' : 'missing',
            'conversion_status' => $platformTrafficReady ? 'ready' : 'missing',
            'missing_domains' => $missingDomains,
            'metric_status' => $metricStatus,
            'traffic_rows' => $platformTrafficRows,
            'source_rows' => $rowCount,
        ];
    }

    $missingSourcePlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($platformCounts, static fn(array $row): bool => (int)($row['source_rows'] ?? 0) === 0)
    ));
    $coveredSourcePlatformCount = count($platformCounts) - count($missingSourcePlatforms);
    $sourceCoverageStatus = $platformCounts === []
        ? ($sourceRows > 0 ? 'complete' : 'missing')
        : ($coveredSourcePlatformCount === count($platformCounts) ? 'complete' : ($coveredSourcePlatformCount > 0 ? 'partial' : 'missing'));
    $missingSourcePlatformText = implode('、', array_map('strtoupper', $missingSourcePlatforms));

    $externalChecks = (array)($result['external_evidence']['checks'] ?? []);
    $aiEvidenceProved = false;
    $aiEvidenceStatus = 'missing';
    $aiEvidenceDetails = [];
    $operationEvidenceProved = false;
    $operationEvidenceStatus = 'missing';
    $operationCounts = [];
    foreach ($externalChecks as $check) {
        if (!is_array($check)) {
            continue;
        }
        $checkStatus = (string)($check['status'] ?? '');
        if (($check['code'] ?? '') === 'ai_diagnosis_evidence') {
            $aiEvidenceStatus = in_array($checkStatus, ['proved', 'warning', 'missing'], true) ? $checkStatus : 'missing';
            $aiEvidenceProved = $checkStatus === 'proved';
            $aiEvidenceDetails = is_array($check['details'] ?? null) ? $check['details'] : [];
        }
        if (($check['code'] ?? '') === 'operation_execution_sample') {
            $operationEvidenceProved = $checkStatus === 'proved';
            $operationEvidenceStatus = in_array($checkStatus, ['proved', 'warning', 'missing'], true) ? $checkStatus : 'missing';
            $operationCounts = is_array($check['details'] ?? null) ? $check['details'] : [];
        }
    }
    $missingCodes = array_values(array_filter(array_map(
        static fn($item): string => is_array($item) ? (string)($item['code'] ?? '') : '',
        (array)($result['missing_requirements'] ?? [])
    ), static fn(string $code): bool => $code !== ''));
    $aiBlockingCodes = array_values(array_filter($missingCodes, static function (string $code): bool {
        return str_contains($code, 'source_rows_missing')
            || str_contains($code, 'etl_not_ready')
            || str_contains($code, 'revenue_metrics_not_ready')
            || str_contains($code, 'traffic_facts_missing')
            || str_contains($code, 'data_gaps_missing')
            || $code === 'evidence_scope_date_mismatch'
            || $code === 'ai_diagnosis_evidence_sample_missing'
            || $code === 'ai_diagnosis_action_items_blocked';
    }));
    $aiBlockingText = implode('、', array_slice($aiBlockingCodes, 0, 6));
    $aiPrerequisiteBlockingCodes = array_values(array_filter(
        $aiBlockingCodes,
        static fn(string $code): bool => $code !== 'ai_diagnosis_evidence_sample_missing'
    ));
    $aiQuestionBlockingCodes = $aiEvidenceProved
        ? []
        : ($aiBlockingCodes !== [] ? $aiBlockingCodes : ['ai_diagnosis_evidence_sample_missing']);
    $aiQuestionBlockingText = implode('、', array_slice($aiQuestionBlockingCodes, 0, 6));
    $operationGapCode = 'operation_execution_sample_missing';
    if ((string)($operationCounts['scope_date_status'] ?? '') === 'mismatch') {
        $operationGapCode = 'evidence_scope_date_mismatch';
    } else {
        foreach ([
            'operation_execution_ai_action_link_missing',
            'operation_execution_evidence_incomplete',
            'operation_execution_sample_missing',
        ] as $candidateOperationGapCode) {
            if (in_array($candidateOperationGapCode, $missingCodes, true)) {
                $operationGapCode = $candidateOperationGapCode;
                break;
            }
        }
        if ($operationEvidenceStatus === 'warning' && $operationGapCode === 'operation_execution_sample_missing') {
            $operationGapCode = 'operation_execution_evidence_incomplete';
        }
    }
    $aiActionGapCode = in_array('ai_diagnosis_action_items_blocked', $missingCodes, true)
        ? 'ai_action_items_blocked'
        : 'ai_action_items_missing';
    $operationBlockingCodes = $operationEvidenceStatus === 'proved'
        ? []
        : array_values(array_unique(array_filter(array_merge(
            $aiEvidenceProved ? [] : array_merge($aiPrerequisiteBlockingCodes, [$aiActionGapCode]),
            [$operationGapCode]
        ))));
    $operationQuestionStatus = $operationEvidenceStatus === 'missing' && $operationBlockingCodes !== []
        ? 'warning'
        : $operationEvidenceStatus;
    $revenueReadyPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['revenue_status'] ?? '') === 'ready')
    ));
    $trafficReadyPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['traffic_status'] ?? '') === 'ready')
    ));
    $conversionReadyPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['conversion_status'] ?? '') === 'ready')
    ));
    $revenueMissingPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['revenue_status'] ?? '') !== 'ready')
    ));
    $trafficMissingPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['traffic_status'] ?? '') !== 'ready')
    ));
    $conversionMissingPlatforms = array_values(array_map(
        static fn(array $row): string => (string)$row['platform'],
        array_filter($metricDomainReadiness, static fn(array $row): bool => ($row['conversion_status'] ?? '') !== 'ready')
    ));
    $metricDomainGapCodes = [];
    foreach ($metricDomainReadiness as $domain) {
        $platformName = strtolower(trim((string)($domain['platform'] ?? '')));
        if ($platformName === '') {
            continue;
        }
        if (($domain['revenue_status'] ?? '') !== 'ready') {
            $metricDomainGapCodes[$platformName . '_revenue_metrics_not_ready'] = true;
        }
        if (($domain['traffic_status'] ?? '') !== 'ready') {
            $metricDomainGapCodes[$platformName . '_traffic_facts_missing'] = true;
        }
    }
    $metricDomainGapCodes = array_values(array_keys($metricDomainGapCodes));
    $revenueReadyText = implode('、', array_map('strtoupper', $revenueReadyPlatforms));
    $metricDomainComplete = $metricDomainGapCodes === [];
    $metricProblemStatus = $hasReadyMetrics && $trafficRows > 0 && $metricDomainComplete ? 'proved' : ($hasReadyMetrics ? 'warning' : 'not_proved');
    $metricProblemNextAction = $hasReadyMetrics
        ? '收益指标可先复核：' . ($revenueReadyText !== '' ? $revenueReadyText : '部分平台') . '；流量/转化事实不足时，不输出流量/转化确定结论。'
        : '先补齐同日 OTA 源数据和标准事实层。';
    $metricTrustKeyList = array_values(array_keys($metricTrustKeys));
    $dataGapCodeList = array_values(array_keys($dataGapCodes));

    return [
        [
            'key' => 'today_ota_collected',
            'question' => '今天 OTA 数据有没有采到',
            'status' => $sourceCoverageStatus === 'complete' ? 'proved' : ($sourceCoverageStatus === 'partial' ? 'warning' : 'missing'),
            'evidence' => [
                'coverage_status' => $sourceCoverageStatus,
                'source_rows' => $sourceRows,
                'missing_platforms' => $missingSourcePlatforms,
                'platforms' => $platformCounts,
            ],
            'next_action' => $sourceCoverageStatus === 'complete'
                ? ''
                : '使用现有携程/美团手动或自动获取入口补齐缺失平台同日数据后重新巡检：' . ($missingSourcePlatformText !== '' ? $missingSourcePlatformText : '携程/美团'),
        ],
        [
            'key' => 'trusted_fields',
            'question' => '哪些字段可信',
            'status' => $sourceCoverageStatus === 'complete' && count($metricTrustKeys) > 0 ? 'proved' : ($sourceRows > 0 && count($metricTrustKeys) > 0 ? 'warning' : 'not_proved_no_source_rows'),
            'evidence' => [
                'metric_trust_key_count' => count($metricTrustKeys),
                'metric_trust_keys' => $metricTrustKeyList,
                'source_rows' => $sourceRows,
                'coverage_status' => $sourceCoverageStatus,
                'missing_platforms' => $missingSourcePlatforms,
                'platform_field_trust' => $platformFieldTrust,
            ],
            'next_action' => $sourceCoverageStatus === 'complete'
                ? ''
                : '先补齐缺失平台同日源数据，再按 metric_trust 判断字段可信度：' . ($missingSourcePlatformText !== '' ? $missingSourcePlatformText : '携程/美团'),
        ],
        [
            'key' => 'missing_fields',
            'question' => '哪些字段缺失',
            'status' => count($dataGapCodes) > 0 ? 'proved' : 'no_gap_reported',
            'evidence' => [
                'data_gap_codes' => $dataGapCodeList,
                'missing_field_codes' => $dataGapCodeList,
                'missing_field_summary' => inspection_missing_field_summary($dataGapCodeList, $dataGapCodeList),
            ],
            'next_action' => count($dataGapCodes) > 0 ? '按 data_gaps 处理字段缺口，不使用 0 或空值兜底。' : '',
        ],
        [
            'key' => 'revenue_traffic_conversion',
            'question' => '收入/流量/转化出了什么问题',
            'status' => $metricProblemStatus,
            'evidence' => [
                'has_ready_metrics' => $hasReadyMetrics,
                'traffic_rows' => $trafficRows,
                'metric_domain_readiness' => $metricDomainReadiness,
                'metric_domain_summary' => inspection_metric_domain_summary($metricDomainReadiness),
                'revenue_ready_platforms' => $revenueReadyPlatforms,
                'traffic_ready_platforms' => $trafficReadyPlatforms,
                'conversion_ready_platforms' => $conversionReadyPlatforms,
                'revenue_missing_platforms' => $revenueMissingPlatforms,
                'traffic_missing_platforms' => $trafficMissingPlatforms,
                'conversion_missing_platforms' => $conversionMissingPlatforms,
                'metric_domain_gap_codes' => $metricDomainGapCodes,
            ],
            'next_action' => $metricProblemNextAction,
        ],
        [
            'key' => 'ai_evidence',
            'question' => 'AI 建议依据是什么',
            'status' => $aiEvidenceStatus,
            'evidence' => [
                'external_evidence_path' => $result['external_evidence']['path'] ?? null,
                'proved' => $aiEvidenceProved,
                'diagnosis_status' => $aiEvidenceProved ? 'proved' : ($aiPrerequisiteBlockingCodes !== [] ? 'blocked_by_verified_ota_gaps' : 'missing_real_api_response'),
                'action_item_status' => $aiEvidenceProved ? 'actionable' : ($aiPrerequisiteBlockingCodes !== [] ? 'blocked_by_verified_ota_gaps' : 'missing'),
                'source_policy' => $aiPrerequisiteBlockingCodes !== [] ? 'read_existing_ota_gap_evidence_only' : 'missing_real_ota_diagnosis_response',
                'evidence_source_count' => max(0, (int)($aiEvidenceDetails['evidence_source_count'] ?? 0)),
                'data_gap_count' => max(count($aiBlockingCodes), (int)($aiEvidenceDetails['data_gap_count'] ?? 0)),
                'action_item_count' => max(0, (int)($aiEvidenceDetails['action_item_count'] ?? 0)),
                'actionable_action_item_count' => max(0, (int)($aiEvidenceDetails['actionable_action_item_count'] ?? 0)),
                'blocked_action_item_count' => max(0, (int)($aiEvidenceDetails['blocked_action_item_count'] ?? 0)),
                'data_gap_evidence_present' => $aiPrerequisiteBlockingCodes !== [],
                'scope_date_status' => (string)($aiEvidenceDetails['scope_date_status'] ?? ''),
                'scope_date' => $aiEvidenceDetails['scope_date'] ?? null,
                'expected_scope_date' => $aiEvidenceDetails['expected_scope_date'] ?? null,
                'blocking_missing_codes' => $aiQuestionBlockingCodes,
            ],
            'next_action' => $aiEvidenceProved
                ? ''
                : '先处理阻断项后再调用现有 OTA 诊断并附脱敏 evidence_sources/data_gaps/action_items：' . ($aiQuestionBlockingText !== '' ? $aiQuestionBlockingText : 'ai_diagnosis_evidence_sample_missing'),
        ],
        [
            'key' => 'next_operation_action',
            'question' => '下一步该执行什么动作',
            'status' => $operationQuestionStatus,
            'evidence' => [
                'external_evidence_path' => $result['external_evidence']['path'] ?? null,
                'proved' => $operationEvidenceProved,
                'operation_evidence_status' => $operationEvidenceStatus,
                'execution_intent_count' => (int)($operationCounts['execution_intent_count'] ?? 0),
                'execution_flow_item_count' => (int)($operationCounts['execution_flow_item_count'] ?? 0),
                'ota_diagnosis_linked_intent_count' => (int)($operationCounts['ota_diagnosis_linked_intent_count'] ?? 0),
                'ota_diagnosis_linked_flow_item_count' => (int)($operationCounts['ota_diagnosis_linked_flow_item_count'] ?? 0),
                'approved_count' => (int)($operationCounts['approved_count'] ?? 0),
                'executed_count' => (int)($operationCounts['executed_count'] ?? 0),
                'evidence_ready_count' => (int)($operationCounts['evidence_ready_count'] ?? 0),
                'execution_evidence_count' => (int)($operationCounts['execution_evidence_count'] ?? 0),
                'reviewed_count' => (int)($operationCounts['reviewed_count'] ?? 0),
                'roi_ready_count' => (int)($operationCounts['roi_ready_count'] ?? 0),
                'blocked_execution_count' => (int)($operationCounts['blocked_execution_count'] ?? 0),
                'completion_signal_count' => (int)($operationCounts['completion_signal_count'] ?? 0),
                'source_policy' => 'read_existing_operation_execution_state_only',
                'raw_data_exposed' => false,
                'scope_date_status' => (string)($operationCounts['scope_date_status'] ?? ''),
                'scope_date' => $operationCounts['scope_date'] ?? null,
                'expected_scope_date' => $operationCounts['expected_scope_date'] ?? null,
                'blocking_missing_codes' => $operationBlockingCodes,
            ],
            'next_action' => $operationEvidenceStatus === 'proved'
                ? ''
                : ($aiEvidenceProved
                    ? ($operationGapCode === 'operation_execution_ai_action_link_missing'
                        ? '已有执行流但未关联 OTA 诊断 action_items；先补齐 source、evidence_refs 或 action_item_id 关联。'
                        : ($operationEvidenceStatus === 'warning'
                        ? '补齐执行意图的审批通过、执行证据或复盘状态；未补齐前不标记运营闭环完成。'
                        : '创建或附上一个真实执行意图/执行流程样例，包含审批、执行证据或复盘状态。'))
                    : '先取得真实 OTA 诊断 action_items，再创建执行意图并保留审批、执行证据和复盘。'),
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $questions
 * @return array<string, mixed>
 */
function inspection_closure_top_action(array $questions): array
{
    foreach ($questions as $question) {
        if (in_array((string)($question['status'] ?? ''), ['proved', 'no_gap_reported'], true)) {
            continue;
        }
        $directStatus = (string)($question['direct_next_action_status'] ?? '');
        $useDirect = (string)($question['direct_next_action_code'] ?? '') !== '' && $directStatus !== 'blocked';
        $prefix = $useDirect ? 'direct_next_action' : 'primary_next_action';
        $code = (string)($question[$prefix . '_code'] ?? '');
        if ($code === '' && !$useDirect) {
            $prefix = 'direct_next_action';
            $code = (string)($question[$prefix . '_code'] ?? '');
        }
        if ($code === '') {
            continue;
        }
        $entryOptions = array_values(array_filter(
            (array)($question[$prefix . '_entry_options'] ?? []),
            static fn($option): bool => is_array($option) && (string)($option['entry'] ?? '') !== ''
        ));
        $relatedQuestionKeys = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_related_question_keys'] ?? [])))));
        $resolvesMissingCodes = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_resolves_missing_codes'] ?? [])))));
        $liveClosureGapCodes = array_values(array_unique(array_filter(array_map('strval', (array)($question[$prefix . '_live_closure_gap_codes'] ?? [])))));
        $platform = strtolower(trim((string)($question[$prefix . '_platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            if (str_contains($code, 'ctrip')) {
                $platform = 'ctrip';
            } elseif (str_contains($code, 'meituan')) {
                $platform = 'meituan';
            } else {
                $platform = '';
            }
        }
        return [
            'top_action_code' => $code,
            'top_action_platform' => $platform,
            'top_action_family' => (string)($question[$prefix . '_family'] ?? ''),
            'top_action_entry' => (string)($question[$prefix . '_entry'] ?? ''),
            'top_action_entry_options' => $entryOptions,
            'top_action_success_criteria' => (string)($question[$prefix . '_success_criteria'] ?? ''),
            'top_action_status' => (string)($question[$prefix . '_status'] ?? ''),
            'top_action_related_question_keys' => $relatedQuestionKeys,
            'top_action_resolves_missing_codes' => $resolvesMissingCodes,
            'top_action_live_closure_gap_codes' => $liveClosureGapCodes,
            'top_action' => (string)($question['next_action'] ?? ''),
            'top_action_employee_text' => (string)($question['employee_next_action'] ?? $question['next_action'] ?? ''),
            'top_question_key' => (string)($question['key'] ?? ''),
            'top_question' => (string)($question['question'] ?? ''),
        ];
    }

    return [
        'top_action_code' => '',
        'top_action_platform' => '',
        'top_action_family' => '',
        'top_action_entry' => '',
        'top_action_entry_options' => [],
        'top_action_success_criteria' => '',
        'top_action_status' => '',
        'top_action_related_question_keys' => [],
        'top_action_resolves_missing_codes' => [],
        'top_action_live_closure_gap_codes' => [],
        'top_action' => '',
        'top_action_employee_text' => '',
        'top_question_key' => '',
        'top_question' => '',
    ];
}

/**
 * @param array<string, mixed> $topAction
 * @param array<int, array<string, mixed>> $collectionSourceSummary
 * @return array<string, mixed>
 */
function inspection_top_action_source_snapshot(array $topAction, array $collectionSourceSummary): array
{
    $platform = strtolower(trim((string)($topAction['top_action_platform'] ?? $topAction['platform'] ?? '')));
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        $actionCode = strtolower((string)($topAction['top_action_code'] ?? $topAction['action_code'] ?? ''));
        foreach (['ctrip', 'meituan'] as $candidate) {
            if (str_contains($actionCode, $candidate)) {
                $platform = $candidate;
                break;
            }
        }
    }
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        $actionCode = strtolower((string)($topAction['top_action_code'] ?? $topAction['action_code'] ?? ''));
        if (in_array($actionCode, ['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'], true)) {
            foreach ($collectionSourceSummary as $row) {
                $candidate = strtolower((string)($row['platform'] ?? ''));
                if (in_array($candidate, ['ctrip', 'meituan'], true)) {
                    $platform = $candidate;
                    break;
                }
            }
        }
    }
    if (!in_array($platform, ['ctrip', 'meituan'], true)) {
        return [];
    }

    foreach ($collectionSourceSummary as $row) {
        if (!is_array($row) || strtolower((string)($row['platform'] ?? '')) !== $platform) {
            continue;
        }
        return [
            'platform' => $platform,
            'target_date' => (string)($row['target_date'] ?? ''),
            'storage_table' => (string)($row['storage_table'] ?? 'online_daily_data'),
            'source_policy' => (string)($row['source_policy'] ?? 'read_existing_online_daily_data_only'),
            'target_date_rows' => max(0, (int)($row['target_date_rows'] ?? 0)),
            'target_date_data_types' => array_values(array_filter(array_map('strval', (array)($row['target_date_data_types'] ?? [])))),
            'latest_available' => is_array($row['latest_available'] ?? null) ? $row['latest_available'] : null,
            'latest_available_reference_only' => (bool)($row['latest_available_reference_only'] ?? true),
            'proof_requirement' => 'source_date_evidence.platforms 中该平台 target_date_rows > 0',
            'reference_policy' => 'latest_available 只能作参考，不能替代目标日入库行证明。',
        ];
    }

    return [];
}

/**
 * @param array<int, array<string, mixed>> $questions
 * @param array<int, array<string, mixed>> $collectionSourceSummary
 * @return array<string, mixed>
 */
function build_inspection_closure_summary(array $questions, array $collectionSourceSummary = []): array
{
    $missing = array_values(array_filter($questions, static fn(array $item): bool => !in_array((string)($item['status'] ?? ''), ['proved', 'no_gap_reported'], true)));
    $topAction = inspection_closure_top_action($missing);
    return array_merge([
        'status' => $missing === [] ? 'passed' : 'incomplete',
        'metric_scope' => 'ota_channel',
        'employee_question_count' => count($questions),
        'proved_count' => count($questions) - count($missing),
        'missing_count' => count($missing),
        'missing_questions' => array_values(array_map(static fn(array $item): string => (string)$item['question'], $missing)),
        'missing_question_keys' => array_values(array_map(static fn(array $item): string => (string)$item['key'], $missing)),
        'source_policy' => 'read_existing_employee_questions_only',
        'reference_policy' => 'latest_available_and_history_rows_are_reference_only_not_target_date_proof',
        'protected_boundary' => '不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射；证据不足时不生成确定经营结论。',
        'top_action_source_snapshot' => inspection_top_action_source_snapshot($topAction, $collectionSourceSummary),
    ], $topAction);
}

function markdown_cell(mixed $value): string
{
    if (is_array($value)) {
        $value = implode('、', array_slice(array_map('strval', $value), 0, 8));
    }
    $text = trim((string)$value);
    if ($text === '') {
        return '-';
    }
    return str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $text);
}

function action_family_label(mixed $value): string
{
    return match ((string)$value) {
        'target_date_source_rows' => '采集补证',
        'standard_facts' => '标准事实',
        'revenue_metric_inputs' => '收益指标',
        'traffic_conversion_facts' => '流量/转化',
        'ai_diagnosis_evidence' => 'AI 证据',
        'operation_execution_evidence' => '执行闭环',
        'evidence_scope' => '证据范围',
        default => (string)$value,
    };
}

/**
 * @param array<int, array<string, mixed>> $platforms
 * @return array<int, string>
 */
function inspection_platform_coverage_summary(array $platforms): array
{
    $summary = [];
    foreach ($platforms as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $name = strtoupper(trim((string)($platform['platform'] ?? '')));
        if ($name === '') {
            continue;
        }
        $rows = (int)($platform['source_rows'] ?? $platform['target_date_rows'] ?? 0);
        $latestAvailable = is_array($platform['latest_available'] ?? null)
            ? $platform['latest_available']
            : [];
        $latestDate = trim((string)($latestAvailable['date'] ?? $platform['latest_available_date'] ?? ''));
        $dateRelation = trim((string)($latestAvailable['date_relation'] ?? $platform['latest_available_date_relation'] ?? ''));
        $text = $name . ':target_date_rows=' . $rows;
        if ($latestDate !== '') {
            $text .= ' latest_available_reference=' . $latestDate;
            if ($dateRelation !== '') {
                $text .= '(' . $dateRelation . ')';
            }
        }
        $summary[] = $text;
    }
    return $summary;
}

/**
 * @param array<int, array<string, mixed>> $platforms
 * @return array<int, string>
 */
function inspection_platform_field_trust_summary(array $platforms): array
{
    $summary = [];
    foreach ($platforms as $platform) {
        if (!is_array($platform)) {
            continue;
        }
        $name = strtoupper(trim((string)($platform['platform'] ?? '')));
        if ($name === '') {
            continue;
        }
        $rows = (int)($platform['target_date_rows'] ?? 0);
        $status = trim((string)($platform['field_trust_status'] ?? 'unknown'));
        $keyCount = (int)($platform['metric_trust_key_count'] ?? 0);
        $text = $name . ':' . $status . ' rows=' . $rows . ' metric_trust_keys=' . $keyCount;
        $summary[] = $text;
    }
    return $summary;
}

function inspection_employee_question_evidence(array $item): string
{
    $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
    $parts = [];
    $key = (string)($item['key'] ?? '');

    if ($key === 'today_ota_collected') {
        if (isset($evidence['coverage_status'])) {
            $parts[] = 'coverage: ' . (string)$evidence['coverage_status'];
        }
        $missingPlatforms = array_values(array_filter(array_map('strval', (array)($evidence['missing_platforms'] ?? []))));
        if ($missingPlatforms !== []) {
            $parts[] = 'missing_platforms: ' . implode('、', array_slice(array_map('strtoupper', $missingPlatforms), 0, 6));
        }
        $platformSummary = inspection_platform_coverage_summary((array)($evidence['platforms'] ?? []));
        if ($platformSummary !== []) {
            $parts[] = 'platform_rows: ' . implode('、', array_slice($platformSummary, 0, 6));
        }
    }

    if ($key === 'trusted_fields') {
        $metricTrustKeys = array_values(array_filter(array_map('strval', (array)($evidence['metric_trust_keys'] ?? []))));
        if ($metricTrustKeys !== []) {
            $parts[] = 'metric_trust: ' . implode('、', array_slice($metricTrustKeys, 0, 6));
        } elseif (array_key_exists('metric_trust_key_count', $evidence)) {
            $parts[] = 'metric_trust_count: ' . (int)$evidence['metric_trust_key_count'];
        }
        $platformTrustSummary = inspection_platform_field_trust_summary((array)($evidence['platform_field_trust'] ?? []));
        if ($platformTrustSummary !== []) {
            $parts[] = 'field_trust_by_platform: ' . implode('、', array_slice($platformTrustSummary, 0, 6));
        }
    }

    if ($key === 'missing_fields') {
        $gapCodes = array_values(array_filter(array_map('strval', (array)($evidence['data_gap_codes'] ?? $evidence['missing_field_codes'] ?? []))));
        $parts[] = $gapCodes !== []
            ? 'data_gaps: ' . implode('、', array_slice($gapCodes, 0, 6))
            : 'data_gaps: none reported';
    }

    if ($key === 'revenue_traffic_conversion') {
        foreach ([
            '收益ready' => 'revenue_ready_platforms',
            '收益缺失' => 'revenue_missing_platforms',
            '流量ready' => 'traffic_ready_platforms',
            '流量缺失' => 'traffic_missing_platforms',
            '转化ready' => 'conversion_ready_platforms',
            '转化缺失' => 'conversion_missing_platforms',
        ] as $label => $field) {
            $platforms = array_values(array_filter(array_map('strval', (array)($evidence[$field] ?? []))));
            if ($platforms !== []) {
                $parts[] = $label . ': ' . implode('、', array_slice(array_map('strtoupper', $platforms), 0, 6));
            }
        }
        $domainGaps = array_values(array_filter(array_map('strval', (array)($evidence['metric_domain_gap_codes'] ?? []))));
        if ($domainGaps !== []) {
            $parts[] = '指标域缺口: ' . implode('、', array_slice($domainGaps, 0, 6));
        }
    }

    if ($parts === [] && isset($evidence['coverage_status'])) {
        $parts[] = 'coverage: ' . (string)$evidence['coverage_status'];
    }
    $directActionCode = (string)($evidence['direct_next_action_code'] ?? '');
    if ($directActionCode !== '') {
        $parts[] = 'direct_action: ' . $directActionCode;
    }
    $primaryActionCode = (string)($evidence['primary_next_action_code'] ?? '');
    if ($primaryActionCode !== '' && $primaryActionCode !== $directActionCode) {
        $parts[] = 'first_action: ' . $primaryActionCode;
    }
    if (isset($evidence['linked_action_count'])) {
        $parts[] = 'linked_actions: ' . (int)$evidence['linked_action_count'];
    }
    $blockedActionCodes = array_values(array_filter(array_map('strval', (array)($evidence['blocked_action_codes'] ?? []))));
    if ($blockedActionCodes !== []) {
        $parts[] = 'blocked_actions: ' . implode('、', array_slice($blockedActionCodes, 0, 4));
    }
    if (isset($evidence['blocking_missing_codes'])) {
        $blocking = array_values(array_filter(array_map('strval', (array)$evidence['blocking_missing_codes'])));
        if ($blocking !== []) {
            $parts[] = 'blocking: ' . implode('、', array_slice($blocking, 0, 6));
        }
    }

    return implode('；', $parts);
}

/**
 * @param array<string, mixed> $result
 */
function render_phase1_live_closure_markdown(array $result): string
{
    $scope = is_array($result['scope'] ?? null) ? $result['scope'] : [];
    $lines = [
        '# 第一阶段 OTA 真实闭环巡检',
        '',
        '| 项 | 值 |',
        '|---|---|',
        '| 状态 | `' . (string)($result['status'] ?? 'unknown') . '` |',
        '| 模式 | `' . (string)($result['mode'] ?? 'inspect') . '` |',
        '| 日期 | ' . (string)($scope['date'] ?? '-') . ' |',
        '| 范围 | ' . (string)($scope['metric_scope'] ?? 'ota_channel') . ' |',
        '',
        '## 已证明',
        '',
    ];

    $proved = [];
    foreach ((array)($result['checks'] ?? []) as $check) {
        if (($check['status'] ?? '') === 'proved') {
            $proved[] = (string)($check['message'] ?? $check['code'] ?? '');
        }
    }
    foreach ((array)($result['platforms'] ?? []) as $platform) {
        foreach ((array)($platform['checks'] ?? []) as $check) {
            if (($check['status'] ?? '') === 'proved') {
                $proved[] = strtoupper((string)($platform['platform'] ?? 'OTA')) . '：' . (string)($check['message'] ?? $check['code'] ?? '');
            }
        }
    }
    foreach (array_values(array_filter(array_unique($proved))) as $item) {
        $lines[] = '- ' . $item;
    }
    if (count($proved) === 0) {
        $lines[] = '- 暂无可证明项。';
    }

    $lines[] = '';
    $lines[] = '## 员工六问';
    $lines[] = '';
    $lines[] = '| 问题 | 状态 | 证据摘要 | 下一步 | 关联动作 |';
    $lines[] = '|---|---|---|---|---|';
    foreach ((array)($result['employee_questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lines[] = sprintf(
            '| %s | `%s` | %s | %s | %s |',
            str_replace('|', '/', (string)($item['question'] ?? '-')),
            (string)($item['status'] ?? '-'),
            markdown_cell(inspection_employee_question_evidence($item)),
            str_replace('|', '/', (string)($item['employee_next_action'] ?? $item['next_action'] ?? '')),
            markdown_cell($item['next_action_codes'] ?? [])
        );
    }
    if (empty($result['employee_questions'])) {
        $lines[] = '| - | `missing` | - | 巡检未生成员工六问摘要 | - |';
    }

    $lines[] = '';
    $lines[] = '## 平台状态';
    $lines[] = '';
    $lines[] = '| 平台 | 源数据行 | ETL | 收益指标 | 流量事实 |';
    $lines[] = '|---|---:|---|---|---:|';
    foreach ((array)($result['platforms'] ?? []) as $platform) {
        $sourceRows = is_array($platform['source_rows'] ?? null) ? $platform['source_rows'] : [];
        $etl = is_array($platform['etl'] ?? null) ? $platform['etl'] : [];
        $metrics = is_array($platform['metrics'] ?? null) ? $platform['metrics'] : [];
        $traffic = is_array($metrics['traffic'] ?? null) ? $metrics['traffic'] : [];
        $lines[] = sprintf(
            '| %s | %d | `%s` | `%s` | %d |',
            (string)($platform['platform'] ?? '-'),
            (int)($sourceRows['count'] ?? 0),
            (string)($etl['status'] ?? '-'),
            (string)($metrics['status'] ?? '-'),
            (int)($traffic['rows'] ?? 0)
        );
    }

    $sourceSummary = (array)($result['collection_source_summary'] ?? []);
    $lines[] = '';
    $lines[] = '## collection_source_summary';
    $lines[] = '';
    $lines[] = '| platform | target_date_rows | latest_available | latest_relation | reference_only | etl | metric | traffic_rows | source_policy |';
    $lines[] = '|---|---:|---|---|---|---|---|---:|---|';
    foreach ($sourceSummary as $item) {
        if (!is_array($item)) {
            continue;
        }
        $latestAvailable = is_array($item['latest_available'] ?? null) ? $item['latest_available'] : [];
        $lines[] = sprintf(
            '| %s | %d | %s | `%s` | `%s` | `%s` | `%s` | %d | `%s` |',
            (string)($item['platform'] ?? '-'),
            (int)($item['target_date_rows'] ?? 0),
            (string)($latestAvailable['date'] ?? '-'),
            (string)($latestAvailable['date_relation'] ?? 'none'),
            (($item['latest_available_reference_only'] ?? true) ? 'true' : 'false'),
            (string)($item['etl_status'] ?? '-'),
            (string)($item['metric_status'] ?? '-'),
            (int)($item['traffic_rows'] ?? 0),
            (string)($item['source_policy'] ?? '-')
        );
    }
    if ($sourceSummary === []) {
        $lines[] = '| - | 0 | - | `none` | `true` | `unknown` | `unknown` | 0 | `read_existing_online_daily_data_only` |';
    }

    $lines[] = '';
    $lines[] = '## 缺失项';
    $lines[] = '';
    $missing = (array)($result['missing_requirements'] ?? []);
    if ($missing === []) {
        $lines[] = '- 无。';
    }
    foreach ($missing as $item) {
        if (!is_array($item)) {
            continue;
        }
        $lines[] = '- `' . (string)($item['code'] ?? 'missing') . '`：' . (string)($item['message'] ?? '');
        $missingMeta = array_values(array_filter([
            trim((string)($item['platform'] ?? '')) !== '' ? '平台：`' . (string)$item['platform'] . '`' : '',
            trim((string)($item['question_key'] ?? '')) !== '' ? '六问：`' . (string)$item['question_key'] . '`' : '',
            trim((string)($item['action_code'] ?? '')) !== '' ? '动作：`' . (string)$item['action_code'] . '`' : '',
            trim((string)($item['action_family'] ?? '')) !== '' ? '动作类型：`' . (string)$item['action_family'] . '`' : '',
        ], static fn(string $value): bool => $value !== ''));
        if ($missingMeta !== []) {
            $lines[] = '  归属：' . implode('；', $missingMeta);
        }
        $employeeExplanation = trim((string)($item['employee_explanation'] ?? ''));
        if ($employeeExplanation !== '') {
            $lines[] = '  员工解释：' . $employeeExplanation;
        }
        $limitedConclusions = array_values(array_filter(
            array_map('strval', (array)($item['limited_conclusions'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        if ($limitedConclusions !== []) {
            $lines[] = '  受限结论：' . implode('、', array_slice($limitedConclusions, 0, 8));
        }
        $stillUsableMetrics = array_values(array_filter(
            array_map('strval', (array)($item['still_usable_metrics'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        if ($stillUsableMetrics !== []) {
            $lines[] = '  仍可使用：' . implode('、', array_slice($stillUsableMetrics, 0, 8));
        }
        $explanationNextAction = trim((string)($item['explanation_next_action'] ?? ''));
        if ($explanationNextAction !== '') {
            $lines[] = '  补证据动作：' . $explanationNextAction;
        }
        $details = is_array($item['details'] ?? null) ? $item['details'] : [];
        $latestAvailable = is_array($details['latest_available'] ?? null) ? $details['latest_available'] : [];
        if ($latestAvailable !== []) {
            $lines[] = sprintf(
                '  最近可用：%s，日期关系：`%s`，行数：%d，数据类型：%s',
                (string)($latestAvailable['date'] ?? '-'),
                (string)($latestAvailable['date_relation'] ?? 'none'),
                (int)($latestAvailable['count'] ?? 0),
                implode('、', array_slice(array_map('strval', (array)($latestAvailable['data_types'] ?? [])), 0, 8))
            );
        }
        $next = is_array($item['next_action'] ?? null) ? $item['next_action'] : [];
        if ($next !== []) {
            $lines[] = '  动作：' . (string)($next['employee_action'] ?? $next['action'] ?? '');
            if (!empty($next['owner'])) {
                $lines[] = '  负责人：' . (string)$next['owner'];
            }
            if (!empty($next['entry'])) {
                $lines[] = '  入口：' . (string)$next['entry'];
            }
            $nextEvidenceNeeded = is_array($next['employee_evidence_needed'] ?? null)
                ? $next['employee_evidence_needed']
                : (is_array($next['evidence_needed'] ?? null) ? $next['evidence_needed'] : []);
            if ($nextEvidenceNeeded !== []) {
                $lines[] = '  所需证据：' . implode('、', array_slice(array_map('strval', $nextEvidenceNeeded), 0, 5));
            }
            if (!empty($next['employee_success_criteria'] ?? $next['success_criteria'] ?? '')) {
                $lines[] = '  完成判定：' . (string)($next['employee_success_criteria'] ?? $next['success_criteria']);
            }
            if (!empty($next['blocked_by_action_codes']) && is_array($next['blocked_by_action_codes'])) {
                $lines[] = '  先处理动作：' . implode('、', array_slice(array_map('strval', $next['blocked_by_action_codes']), 0, 5));
            }
            if (!empty($next['protected_boundary'])) {
                $lines[] = '  边界：' . (string)$next['protected_boundary'];
            }
        }
    }

    $lines[] = '';
    $lines[] = '## 下一步动作';
    $lines[] = '';
    $nextActions = (array)($result['next_actions'] ?? []);
    if ($nextActions === []) {
        $lines[] = '- 暂无待处理动作。';
    }
    if ($nextActions !== []) {
        $lines[] = '| 优先级 | 状态 | 动作类型 | 入口 | 动作编码 | 负责人 | 动作 | 员工解释 | 受限结论 | 仍可使用 | 补证据动作 | 巡检缺口 | 阻断 | 先处理动作 | 解除缺口 | 所需证据 | 完成判定 | 边界 |';
        $lines[] = '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|';
    }
    foreach ($nextActions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $lines[] = sprintf(
            '| `%s` | `%s` | %s | %s | `%s` | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |',
            markdown_cell($action['priority'] ?? '-'),
            markdown_cell($action['status'] ?? '-'),
            markdown_cell(action_family_label($action['action_family'] ?? '')),
            markdown_cell($action['entry'] ?? ''),
            markdown_cell($action['action_code'] ?? 'action'),
            markdown_cell($action['owner'] ?? '-'),
            markdown_cell($action['employee_action'] ?? $action['action'] ?? ''),
            markdown_cell($action['employee_explanation'] ?? ''),
            markdown_cell($action['limited_conclusions'] ?? []),
            markdown_cell($action['still_usable_metrics'] ?? []),
            markdown_cell($action['employee_explanation_next_action'] ?? $action['explanation_next_action'] ?? ''),
            markdown_cell($action['live_closure_gap_codes'] ?? []),
            markdown_cell($action['blocked_by'] ?? []),
            markdown_cell($action['blocked_by_action_codes'] ?? []),
            markdown_cell($action['resolves_missing_codes'] ?? []),
            markdown_cell($action['employee_evidence_needed'] ?? $action['evidence_needed'] ?? []),
            markdown_cell($action['employee_success_criteria'] ?? $action['success_criteria'] ?? ''),
            markdown_cell($action['protected_boundary'] ?? '')
        );
    }

    $lines[] = '';
    $lines[] = '## 结论';
    $lines[] = '';
    $lines[] = ($result['status'] ?? '') === 'passed'
        ? '当前证据满足第一阶段真实闭环验收。'
        : '当前证据仍不足，不能声明第一阶段业务闭环完成。';

    return implode(PHP_EOL, $lines);
}

try {
    $options = parse_args($argv);
    $platforms = trim((string)$options['platform']) !== ''
        ? [strtolower((string)$options['platform'])]
        : ['ctrip', 'meituan'];

    $result = [
        'status' => 'incomplete',
        'mode' => $options['strict'] ? 'verify' : 'inspect',
        'scope' => [
            'date' => $options['date'],
            'platforms' => $platforms,
            'hotel_id' => $options['hotel_id'] ?: null,
            'system_hotel_id' => $options['system_hotel_id'] ?: null,
            'table' => 'online_daily_data',
            'metric_scope' => 'ota_channel',
        ],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'next_actions' => [],
        'issues' => [],
    ];

    $app = new App();
    $app->initialize();
    add_check($result['checks'], 'app_bootstrap', 'proved', 'ThinkPHP application initialized.');

    if (!table_exists('online_daily_data')) {
        throw new RuntimeException('online_daily_data table does not exist.');
    }
    add_check($result['checks'], 'source_table_present', 'proved', 'online_daily_data table exists.');
    $columns = table_columns('online_daily_data');
    foreach (['id', 'hotel_id', 'data_date', 'source', 'raw_data', 'data_type'] as $column) {
        if (!isset($columns[$column])) {
            throw new RuntimeException('online_daily_data missing required column: ' . $column);
        }
    }
    add_check($result['checks'], 'source_columns_present', 'proved', 'Core online_daily_data columns exist.');

    foreach ($platforms as $platform) {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            add_missing($result, 'unsupported_platform', 'Only ctrip and meituan are supported in phase-one OTA closure.', [
                'platform' => $platform,
            ]);
            continue;
        }
        $result['platforms'][] = inspect_platform($platform, $columns, $options, $result);
    }
    $result['collection_source_summary'] = build_collection_source_summary($result['platforms'], (string)$options['date']);

    if ((string)$options['evidence'] !== '') {
        $evidence = read_json_file((string)$options['evidence']);
        $result['external_evidence'] = [
            'path' => $options['evidence'],
            'checks' => validate_external_evidence($evidence, $options, $result),
        ];
    } else {
        $generatedEvidence = build_inspection_blocked_diagnosis_evidence($result, $options);
        $result['external_evidence'] = [
            'path' => null,
            'source_policy' => $generatedEvidence === null ? 'missing_real_api_response' : 'generated_blocked_from_verified_missing_requirements',
            'checks' => $generatedEvidence === null ? [] : validate_external_evidence($generatedEvidence, $options, $result),
        ];
        if ($generatedEvidence === null) {
            add_missing($result, 'ai_diagnosis_evidence_sample_missing', 'No evidence JSON supplied for OTA diagnosis evidence_sources/data_gaps/action_items.');
            add_missing($result, 'operation_execution_sample_missing', 'No evidence JSON supplied for operation execution intent/flow sample.');
        }
    }

    $result['next_actions'] = finalize_inspection_next_actions($result);
    $result['employee_questions'] = build_inspection_employee_questions($result);
    $result['employee_questions'] = with_inspection_employee_question_action_codes($result['employee_questions'], $result['next_actions']);
    $result['closure_summary'] = build_inspection_closure_summary($result['employee_questions'], $result['collection_source_summary'] ?? []);
    $result['status'] = $result['missing_requirements'] === [] ? 'passed' : 'incomplete';
} catch (Throwable $e) {
    $result = $result ?? [
        'status' => 'failed',
        'mode' => 'inspect',
        'scope' => [],
        'checks' => [],
        'platforms' => [],
        'external_evidence' => null,
        'missing_requirements' => [],
        'next_actions' => [],
        'issues' => [],
    ];
    $result['status'] = 'failed';
    $result['issues'][] = [
        'severity' => 'error',
        'code' => 'inspector_runtime_error',
        'message' => $e->getMessage(),
    ];
}

$format = (string)(is_array($options ?? null) ? ($options['format'] ?? 'json') : 'json');
echo ($format === 'markdown'
    ? render_phase1_live_closure_markdown($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
) . PHP_EOL;

$hasError = ($result['status'] ?? '') === 'failed';
$strictIncomplete = ($result['mode'] ?? '') === 'verify' && ($result['missing_requirements'] ?? []) !== [];
exit($hasError || $strictIncomplete ? 1 : 0);
