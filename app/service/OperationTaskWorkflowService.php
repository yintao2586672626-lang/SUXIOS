<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Local task coordination. It never approves an intent or performs an external action. */
final class OperationTaskWorkflowService
{
    public const EVENTS = 'operation_task_workflow_events';
    public const PROPOSALS = 'operation_task_workflow_proposals';
    public const TYPES = [
        'conversion_optimization' => '转化优化',
        'price_check' => '价格检查',
        'service_remediation' => '服务问题整改',
    ];

    public function __construct(private $clock = null, private $assigneeCheck = null) {}

    public function propose(array $hotelIds, int $hotelId, array $input, int $actorId): array
    {
        $this->actor($actorId);
        $this->safeInput($input);
        $tenantId = $this->hotel($hotelIds, $hotelId);
        $this->ensureTables();
        $key = $this->token($input['recommendation_id'] ?? '', 'recommendation_id');
        if (($input['status'] ?? '') !== 'proposed' || ($input['requires_human_confirmation'] ?? null) !== true) {
            throw new InvalidArgumentException('建议必须为 proposed 并保留人工确认');
        }
        $evidence = (array)($input['evidence_snapshot'] ?? []);
        $scope = $this->scope((array)($evidence['scope'] ?? []));
        if ($scope['tenant_id'] !== $tenantId || $scope['hotel_id'] !== $hotelId) {
            throw new InvalidArgumentException('建议的租户或酒店范围不匹配');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)($evidence['fingerprint'] ?? ''))) {
            throw new InvalidArgumentException('建议证据指纹缺失');
        }
        $type = $this->workflowType($input['workflow_type'] ?? '');
        $criteria = $this->strings($input['completion_criteria'] ?? [], '完成条件');
        $window = $this->window((array)($input['review_window'] ?? []));
        $this->windowAfterTask($window, $scope);
        $evidence['source_refs'] = $this->strings($evidence['source_refs'] ?? [], '建议来源引用');
        $problem = $this->text($input['problem'] ?? '', '问题');
        $digest = $this->digest($input);
        return Db::transaction(function () use ($hotelIds, $hotelId, $tenantId, $key, $input, $evidence, $scope, $type, $criteria, $window, $problem, $digest, $actorId): array {
            $this->hotel($hotelIds, $hotelId, true);
            $prior = Db::name(self::PROPOSALS)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('recommendation_id', $key)->find();
            if ($prior) {
                if (!hash_equals($prior['request_digest'], $digest)) throw new RuntimeException('同一建议已保存不同版本，请回读原建议', 409);
                $intent = (new OperationManagementService())->readExecutionIntent((int)$prior['intent_id'], [$hotelId]);
                if (!hash_equals($digest, $this->digest(json_decode($prior['proposal_json'], true, 512, JSON_THROW_ON_ERROR)))
                    || !hash_equals($digest, $this->digest($intent['evidence']['workflow_proposal'] ?? null))) throw new RuntimeException('建议历史内容校验失败');
                return ['intent' => $intent, 'created' => false, 'replayed' => true, 'readback_verified' => true];
            }
            $intent = (new OperationManagementService())->createExecutionIntent([$hotelId], $hotelId, [
                'source_module' => 'manual', 'source_record_id' => 0, 'hotel_id' => $hotelId,
                'platform' => $scope['platform'], 'date_start' => $scope['date_start'], 'date_end' => $scope['date_end'],
                'object_type' => 'operation_checklist', 'action_type' => $type, 'expected_delta' => null,
                'target_value' => ['title' => self::TYPES[$type], 'action_text' => $problem, 'steps' => $criteria,
                    'acceptance_criteria' => $criteria, 'object_ref' => $scope['object_ref']],
                'evidence' => ['evidence_refs' => (array)($evidence['source_refs'] ?? []),
                    'workflow_proposal' => $input, 'link_status' => 'unverified_link',
                    'source_snapshot_digest' => $evidence['fingerprint']],
            ], $actorId, false, 'workflow_proposal_' . substr(hash('sha256', "$tenantId:$hotelId:$key"), 0, 32));
            Db::name(self::PROPOSALS)->insert([
                'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'recommendation_id' => $key,
                'intent_id' => (int)$intent['id'], 'request_digest' => $digest,
                'proposal_json' => $this->json($input), 'created_at' => $this->now(),
            ]);
            return ['intent' => (new OperationManagementService())->readExecutionIntent((int)$intent['id'], [$hotelId]),
                'created' => true, 'replayed' => false, 'readback_verified' => true];
        });
    }

    public function read(int $taskId, array $hotelIds, ?int $version = null): array
    {
        [$task, $intent] = $this->task($taskId, $hotelIds);
        $events = $this->events($task);
        if ($version !== null && ($version < 1 || $version > count($events))) throw new RuntimeException('任务历史版本 not found', 404);
        $selected = $version === null ? (end($events) ?: null) : $events[$version - 1];
        $state = $selected ? $selected['state'] : $this->legacy($task, $intent);
        foreach ($version === null ? ['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end'] : ['tenant_id', 'hotel_id'] as $key) {
            if ((string)$state['scope'][$key] !== (string)($intent[$key] ?? '')) throw new RuntimeException('任务范围已变更，历史不能套用当前对象', 409);
        }
        $state['current_approval_status'] = (string)$intent['status'];
        if ($version === null) {
            $state['approval_status'] = (string)$intent['status'];
            $state['legacy_execution_status'] = (string)$task['status'];
        }
        $state['historical'] = $version !== null;
        $state['readback_verified'] = true;
        $state['history'] = array_map(static fn(array $event): array => array_diff_key($event, ['state' => true]), $events);
        $state['next_step'] = $this->nextStep($state, $hotelIds, $version === null);
        return $state;
    }

    public function listing(array $hotelIds, int $hotelId): array
    {
        $tenantId = $this->hotel($hotelIds, $hotelId);
        $this->ensureTables();
        $rows = Db::name('operation_execution_tasks')->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')->order('id', 'desc')->limit(101)->column('id');
        $items = [];
        foreach (array_slice($rows, 0, 100) as $id) $items[] = $this->read((int)$id, [$hotelId]);
        return ['items' => $items, 'truncated' => count($rows) > 100, 'scope' => ['tenant_id' => $tenantId, 'hotel_id' => $hotelId], 'types' => self::TYPES];
    }

    /** Source reader for weekly plans; no caller-supplied projection can attest completion. */
    public function weeklySummary(int $tenantId, int $hotelId, array $tasks): array
    {
        $summary = ['status' => 'ready', 'configured' => 0, 'task_completed' => 0, 'execution_verified' => 0,
            'reviewed' => 0, 'effect_established' => 0, 'blocked' => 0, 'next_task' => null, 'versions' => []];
        if ($this->hotel([$hotelId], $hotelId) !== $tenantId) throw new InvalidArgumentException('周计划任务租户不匹配');
        if (!(new OperationManagementService())->tableExists(self::EVENTS)) return ['status' => 'migration_required'];
        foreach ($tasks as $task) {
            $s = $this->read((int)$task['id'], [$hotelId]);
            if ($s['version'] === 0) continue;
            $summary['configured']++;
            $summary['task_completed'] += (int)($s['task_status'] === 'completed');
            $summary['execution_verified'] += (int)($s['verification']['status'] === 'manual_verified');
            $summary['reviewed'] += (int)($s['review']['status'] === 'reviewed');
            $summary['blocked'] += (int)in_array($s['next_step']['key'], ['dependency_blocked', 'resolve_block', 'approval'], true);
            $last = end($s['history']);
            $summary['versions'][] = ['task_id' => $s['task_id'], 'version' => $s['version'], 'content_digest' => $last['content_digest']];
            if ($s['next_step']['key'] !== 'reviewed' && ($summary['next_task'] === null || $s['due_date'] < $summary['next_task']['due_date'])) {
                $summary['next_task'] = ['task_id' => $s['task_id'], 'due_date' => $s['due_date'], 'scope' => $s['scope'], 'next_step' => $s['next_step']];
            }
        }
        return $summary;
    }

    public function mutate(int $taskId, array $hotelIds, array $input, int $actorId): array
    {
        $this->actor($actorId);
        $this->safeInput($input);
        $requestKey = $this->token($input['request_id'] ?? '', 'request_id');
        if (!isset($input['expected_version']) || is_bool($input['expected_version']) || filter_var($input['expected_version'], FILTER_VALIDATE_INT) === false || (int)$input['expected_version'] < 0) {
            throw new InvalidArgumentException('必须提供当前 expected_version');
        }
        $digest = $this->digest($input);
        return Db::transaction(function () use ($taskId, $hotelIds, $input, $actorId, $requestKey, $digest): array {
            [$task, $intent] = $this->task($taskId, $hotelIds, true);
            $events = $this->events($task);
            foreach ($events as $event) {
                if ($event['request_id'] === $requestKey) {
                    if (!hash_equals($event['request_digest'], $digest)) throw new RuntimeException('重复提交内容不同，请回读原任务', 409);
                    return ['workflow' => $this->read($taskId, $hotelIds), 'saved_version' => $event['version'], 'replayed' => true];
                }
            }
            $state = $events === [] ? $this->legacy($task, $intent) : end($events)['state'];
            if ((int)$input['expected_version'] !== (int)$state['version']) throw new RuntimeException('任务版本冲突，请刷新后重新操作', 409);
            if (($intent['status'] ?? '') !== 'approved') throw new InvalidArgumentException('任务意图尚未获人工批准或已撤销');
            $action = (string)($input['action'] ?? '');
            $next = $this->transition($state, $input, $actorId, $hotelIds);
            $next['version'] = $state['version'] + 1;
            $next['updated_at'] = $this->now();
            $next['updated_by'] = $actorId;
            $next['approval_status'] = (string)$intent['status'];
            $next['legacy_execution_status'] = (string)$task['status'];
            $payload = ['state' => $next, 'action' => $action, 'request_digest' => $digest,
                'request_id' => $requestKey, 'actor_id' => $actorId, 'previous_digest' => $events === [] ? '' : end($events)['content_digest']];
            Db::name(self::EVENTS)->insert([
                'tenant_id' => $task['tenant_id'], 'hotel_id' => $task['hotel_id'], 'task_id' => $taskId,
                'version_no' => $next['version'], 'request_id' => $requestKey,
                'payload_json' => $this->json($payload), 'content_digest' => $this->digest($payload), 'created_at' => $next['updated_at'],
            ]);
            $read = $this->read($taskId, $hotelIds);
            if ($read['version'] !== $next['version']) throw new RuntimeException('任务保存回读失败');
            return ['workflow' => $read, 'saved_version' => $next['version'], 'replayed' => false];
        });
    }

    private function transition(array $s, array $in, int $actor, array $hotels): array
    {
        $action = (string)($in['action'] ?? '');
        if ($action === 'configure') {
            if ($s['version'] > 0 && !in_array($s['task_status'], ['pending', 'returned', 'reopened'], true)) throw new InvalidArgumentException('当前状态不能修改任务条件');
            $s['workflow_type'] = $this->workflowType($in['workflow_type'] ?? '');
            $scope = $this->scope((array)($in['scope'] ?? []));
            if (($s['source']['link_status'] ?? '') === 'scope_mismatch') throw new InvalidArgumentException('原建议与任务范围不匹配，不能据此配置或结案');
            foreach (['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end'] as $key) {
                if ((string)$scope[$key] !== (string)$s['scope'][$key]) throw new InvalidArgumentException('任务条件范围不匹配：' . $key);
            }
            if ($s['scope']['object_ref'] !== '' && $scope['object_ref'] !== $s['scope']['object_ref']) throw new InvalidArgumentException('任务对象不匹配');
            $s['scope'] = $scope;
            $s['assignee_id'] = $this->positive($in['assignee_id'] ?? null, '负责人');
            $this->assignee($s['assignee_id'], $scope);
            $s['due_date'] = $this->date($in['due_date'] ?? '', '截止日期');
            $s['completion_criteria'] = $this->strings($in['completion_criteria'] ?? [], '完成条件');
            $s['review_window'] = $this->window((array)($in['review_window'] ?? []));
            $this->windowAfterTask($s['review_window'], $scope);
            $s['dependencies'] = $this->dependencies($in['dependencies'] ?? [], $s, $hotels);
            $s['task_status'] = 'pending';
            $s['blocked_reason'] = '';
            return $s;
        }
        if ($s['version'] === 0) throw new InvalidArgumentException('旧任务须先补充负责人、对象和完成条件');
        if (in_array($action, ['start', 'record', 'complete'], true) && $s['assignee_id'] !== $actor) throw new InvalidArgumentException('仅当前负责人可以登记任务执行');
        if ($action === 'reschedule_review') {
            $s['review_window'] = $this->window((array)($in['review_window'] ?? []));
            $this->windowAfterTask($s['review_window'], $s['scope']);
            $this->windowAfterExecution($s);
            $s['review_schedule_reason'] = $this->text($in['reason'] ?? '', '调整复盘窗口原因');
            $s['review'] = ['status' => 'pending', 'effect_status' => 'unestablished', 'causality_claimed' => false];
            return $s;
        }
        if ($action === 'postpone') {
            if ($s['task_status'] === 'completed') throw new InvalidArgumentException('完成任务需先重开才能延期');
            $due = $this->date($in['due_date'] ?? '', '新截止日期');
            if ($due <= $s['due_date']) throw new InvalidArgumentException('延期日期必须晚于原截止日期');
            $s['due_date'] = $due;
            $s['schedule_reason'] = $this->text($in['reason'] ?? '', '延期原因');
            if (isset($in['review_window'])) {
                $s['review_window'] = $this->window((array)$in['review_window']);
                $this->windowAfterTask($s['review_window'], $s['scope']);
            }
            return $s;
        }
        if (in_array($action, ['return', 'reopen'], true)) {
            if ($action === 'reopen' && $s['task_status'] !== 'completed') throw new InvalidArgumentException('仅完成任务可以重开');
            if ($action === 'return' && !in_array($s['task_status'], ['in_progress', 'completed', 'blocked'], true)) throw new InvalidArgumentException('当前状态不能退回');
            $s['task_status'] = $action === 'return' ? 'returned' : 'reopened';
            $s['blocked_reason'] = $this->text($in['reason'] ?? '', '退回或重开原因');
            $s['cycle']++;
            $s['execution_records'] = [];
            $s['verification'] = ['status' => 'pending', 'reason' => '新轮次需要重新核实'];
            $s['review'] = ['status' => 'pending', 'effect_status' => 'unestablished', 'causality_claimed' => false];
            return $s;
        }
        if ($action === 'block') {
            if ($s['task_status'] === 'completed') throw new InvalidArgumentException('完成任务须退回或重开');
            $s['task_status'] = 'blocked';
            $s['blocked_reason'] = $this->text($in['reason'] ?? '', '阻塞原因');
            return $s;
        }
        if ($action === 'start') {
            if (!in_array($s['task_status'], ['pending', 'returned', 'reopened', 'blocked'], true)) throw new InvalidArgumentException('当前任务不能开始');
            $this->assertDependencies($s, $hotels);
            $s['task_status'] = 'in_progress';
            $s['blocked_reason'] = '';
            return $s;
        }
        if ($action === 'record') {
            if ($s['task_status'] !== 'in_progress') throw new InvalidArgumentException('请先开始任务再登记执行材料');
            $record = $this->record((array)($in['record'] ?? []), $s, $actor);
            $s['execution_records'][] = $record;
            $s['verification'] = ['status' => 'pending', 'reason' => '材料已保存，尚未人工核实'];
            return $s;
        }
        if ($action === 'complete') {
            if ($s['task_status'] !== 'in_progress') throw new InvalidArgumentException('仅进行中的任务可以完成填报');
            $this->assertDependencies($s, $hotels);
            if ($s['execution_records'] === []) throw new InvalidArgumentException('需要执行记录；截图不能自动完成任务');
            $checked = $in['completed_criteria'] ?? null;
            if (!is_array($checked) || !array_is_list($checked) || count($checked) !== count($s['completion_criteria'])) {
                throw new InvalidArgumentException('完成条件未逐项确认');
            }
            $seen = [];
            foreach ($checked as $criterion) {
                if (!is_string($criterion) || !in_array($criterion, $s['completion_criteria'], true) || in_array($criterion, $seen, true)) {
                    throw new InvalidArgumentException('完成条件未逐项确认');
                }
                $seen[] = $criterion;
            }
            $s['task_status'] = 'completed';
            $s['completed_at'] = $this->now();
            $s['verification'] = ['status' => 'pending', 'reason' => '任务完成填报，执行仍待核实'];
            return $s;
        }
        if ($action === 'verify') {
            if ($s['task_status'] !== 'completed') throw new InvalidArgumentException('请先完成任务填报');
            $this->assertDependencies($s, $hotels);
            if (($in['human_confirmed'] ?? null) !== true) throw new InvalidArgumentException('执行核实必须由人主动确认');
            $note = $this->text($in['reason'] ?? '', '核实说明');
            $qualified = array_values(array_filter($s['execution_records'], static fn(array $r): bool => $r['kind'] === 'manual_check' && $r['checks'] !== []));
            if ($qualified === []) {
                $s['verification'] = ['status' => 'pending', 'reason' => '截图或回执为弱证据，需补充同对象逐项人工核查'];
                return $s;
            }
            $checks = array_values(array_unique(array_merge(...array_column($qualified, 'checks'))));
            if (array_diff($s['completion_criteria'], $checks) !== []) throw new InvalidArgumentException('执行核查未覆盖全部完成条件');
            $s['verification'] = ['status' => 'manual_verified', 'reason' => $note, 'verified_by' => $actor, 'verified_at' => $this->now(), 'source' => 'human_attestation'];
            return $s;
        }
        if ($action === 'review') {
            if ($s['task_status'] !== 'completed' || $s['verification']['status'] !== 'manual_verified') throw new InvalidArgumentException('需先完成人工执行核实再复盘');
            $this->assertDependencies($s, $hotels);
            $this->windowAfterExecution($s);
            if ($s['review_window']['followup_end'] >= substr($this->now(), 0, 10)) throw new InvalidArgumentException('复盘窗口尚未完整结束');
            if (($in['causality_claimed'] ?? false) !== false) throw new InvalidArgumentException('前后比较不能声明因果效果');
            $s['review'] = $this->review((array)($in['review'] ?? []), $s, $actor);
            return $s;
        }
        throw new InvalidArgumentException('不支持的工作流操作');
    }

    private function record(array $r, array $s, int $actor): array
    {
        if ($this->scope((array)($r['scope'] ?? [])) !== $s['scope']) throw new InvalidArgumentException('证据酒店、平台、日期或对象不匹配');
        $kind = (string)($r['kind'] ?? '');
        if (!in_array($kind, ['screenshot', 'receipt', 'manual_check'], true)) throw new InvalidArgumentException('证据类型无效');
        $date = $this->date($r['performed_on'] ?? '', '执行日期');
        if ($date > substr($this->now(), 0, 10) || $date < $s['scope']['date_start']) throw new InvalidArgumentException('实际执行日期不能早于任务业务日或晚于今天');
        return ['scope' => $s['scope'], 'kind' => $kind, 'performed_on' => $date,
            'reference' => $this->text($r['reference'] ?? '', '材料引用'), 'note' => $this->text($r['note'] ?? '', '执行记录'),
            'checks' => $kind === 'manual_check' ? $this->strings($r['checks'] ?? [], '已核查条件') : [],
            'recorded_by' => $actor, 'recorded_at' => $this->now(), 'evidence_status' => 'unverified'];
    }

    private function review(array $r, array $s, int $actor): array
    {
        $note = $this->text($r['note'] ?? '', '复盘结论与其他影响因素');
        $base = ['status' => 'reviewed', 'effect_status' => 'unestablished', 'causality_claimed' => false,
            'note' => $note, 'reviewed_by' => $actor, 'reviewed_at' => $this->now(), 'window' => $s['review_window']];
        if (empty($r['before']) || empty($r['after'])) return $base + ['comparison_status' => 'missing', 'delta' => null, 'reason' => '前后证据缺失，不以零补齐'];
        $before = $this->observation((array)$r['before'], $s, 'baseline');
        $after = $this->observation((array)$r['after'], $s, 'followup');
        if ($before['metric'] !== $after['metric'] || $before['unit'] !== $after['unit']) throw new InvalidArgumentException('复盘口径或单位不一致');
        $days = static fn(array $o): int => (int)(new DateTimeImmutable($o['scope']['date_start']))->diff(new DateTimeImmutable($o['scope']['date_end']))->days;
        if ($days($before) !== $days($after)) return $base + ['comparison_status' => 'incomparable', 'before' => $before, 'after' => $after, 'delta' => null, 'reason' => '前后窗口天数不同，仅保存观察，不直接比较'];
        $delta = $after['value'] - $before['value'];
        return $base + ['comparison_status' => 'manual_observation', 'before' => $before, 'after' => $after,
            'delta' => $delta, 'delta_unit' => $before['unit'] === 'percent' ? 'percentage_point' : $before['unit'],
            'reason' => '人工录入同范围前后观察；变化不证明动作的因果效果'];
    }

    private function observation(array $r, array $s, string $period): array
    {
        $scope = $this->scope((array)($r['scope'] ?? []));
        $expected = $s['scope'];
        $expected['date_start'] = $s['review_window'][$period . '_start'];
        $expected['date_end'] = $s['review_window'][$period . '_end'];
        if ($scope !== $expected) throw new InvalidArgumentException('复盘证据范围或时间窗不匹配');
        $unit = (string)($r['unit'] ?? '');
        if (!in_array($unit, ['percent', 'ratio', 'CNY', 'count', 'score', 'minutes'], true)) throw new InvalidArgumentException('必须明确指标单位');
        $raw = $r['value'] ?? null;
        if ((!is_int($raw) && !is_float($raw) && !is_string($raw)) || $raw === '' || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) throw new InvalidArgumentException('缺失或无效指标不能当作零');
        $value = (float)$raw;
        if (($unit === 'ratio' && $value > 1) || ($unit === 'percent' && $value > 100) || ($unit === 'count' && floor($value) !== $value)) throw new InvalidArgumentException('指标数值与单位不匹配');
        return ['scope' => $scope, 'metric' => $this->text($r['metric'] ?? '', '指标定义'), 'unit' => $unit,
            'value' => $value, 'reference' => $this->text($r['reference'] ?? '', '来源引用'), 'source' => 'manual_input'];
    }

    private function legacy(array $task, array $intent): array
    {
        $target = json_decode((string)($intent['target_value_json'] ?? '{}'), true) ?: [];
        $evidence = json_decode((string)($intent['evidence_json'] ?? '{}'), true) ?: [];
        $proposal = $evidence['workflow_proposal'] ?? $evidence['evidence_recommendation'] ?? null;
        $proposalScope = $proposal['evidence_snapshot']['scope'] ?? $proposal['scope'] ?? [];
        $object = (string)($target['object_ref'] ?? $target['room_type_key'] ?? $proposalScope['object_ref'] ?? '');
        $linkStatus = 'reference_only';
        foreach (['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end'] as $key) {
            if ($proposalScope !== [] && (string)($proposalScope[$key] ?? '') !== (string)($intent[$key] ?? '')) $linkStatus = 'scope_mismatch';
        }
        if ($proposalScope !== [] && $object !== (string)($proposalScope['object_ref'] ?? '')) $linkStatus = 'scope_mismatch';
        return ['contract_version' => 'operation_task_workflow.v1', 'task_id' => (int)$task['id'], 'intent_id' => (int)$intent['id'],
            'version' => 0, 'cycle' => 1, 'task_status' => 'legacy_unconfigured', 'workflow_type' => '', 'assignee_id' => null,
            'due_date' => null, 'completion_criteria' => [], 'dependencies' => [], 'blocked_reason' => '', 'execution_records' => [],
            'scope' => ['tenant_id' => (int)$task['tenant_id'], 'hotel_id' => (int)$task['hotel_id'], 'platform' => (string)$intent['platform'],
                'date_start' => (string)$intent['date_start'], 'date_end' => (string)$intent['date_end'], 'object_ref' => $object],
            'source' => ['module' => $intent['source_module'] ?? 'legacy', 'record_id' => (int)($intent['source_record_id'] ?? 0),
                'proposal' => $proposal, 'link_status' => $linkStatus],
            'review_window' => null, 'verification' => ['status' => 'pending', 'reason' => '旧记录尚未通过新合同核实'],
            'review' => ['status' => 'pending', 'effect_status' => 'unestablished', 'causality_claimed' => false]];
    }

    private function events(array $task): array
    {
        try {
            $rows = Db::name(self::EVENTS)->where('tenant_id', $task['tenant_id'])->where('hotel_id', $task['hotel_id'])
                ->where('task_id', $task['id'])->order('version_no', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            throw new RuntimeException('任务工作流存储不可用，请确认迁移及数据库状态（migration_required）', 503, $e);
        }
        $events = []; $previous = '';
        foreach ($rows as $index => $row) {
            $p = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals($row['content_digest'], $this->digest($p)) || $p['previous_digest'] !== $previous
                || (int)$row['version_no'] !== $index + 1 || (int)$p['state']['version'] !== $index + 1
                || (int)$p['state']['task_id'] !== (int)$task['id'] || (int)$p['state']['scope']['tenant_id'] !== (int)$task['tenant_id']
                || (int)$p['state']['scope']['hotel_id'] !== (int)$task['hotel_id'] || $row['request_id'] !== $p['request_id']) {
                throw new RuntimeException('任务历史完整性校验失败');
            }
            $previous = $row['content_digest'];
            $events[] = $p + ['version' => (int)$row['version_no'], 'content_digest' => $previous, 'created_at' => $row['created_at']];
        }
        return $events;
    }

    private function task(int $id, array $hotels, bool $lock = false): array
    {
        $task = Db::name('operation_execution_tasks')->where('id', $id)->whereIn('hotel_id', $hotels)->whereNull('deleted_at')->find();
        if (!$task) throw new RuntimeException('任务 not found', 404);
        $tenant = $this->hotel($hotels, (int)$task['hotel_id'], $lock);
        if ($lock) $task = Db::name('operation_execution_tasks')->where('id', $id)->whereNull('deleted_at')->lock(true)->find();
        if (!$task || (int)$task['tenant_id'] !== $tenant) throw new RuntimeException('任务 not found', 404);
        $intent = Db::name('operation_execution_intents')->where('id', $task['intent_id'])->where('tenant_id', $tenant)
            ->where('hotel_id', $task['hotel_id'])->whereNull('deleted_at')->find();
        if (!$intent) throw new RuntimeException('任务意图 not found', 404);
        return [$task, $intent];
    }

    private function hotel(array $hotelIds, int $hotel, bool $lock = false): int
    {
        if ($hotel < 1 || !in_array($hotel, array_map('intval', $hotelIds), true)) throw new RuntimeException('酒店 not found', 404);
        $row = Db::name('hotels')->where('id', $hotel)->lock($lock)->find();
        if (!$row || (int)($row['tenant_id'] ?? 0) <= 0) throw new RuntimeException('酒店 not found', 404);
        return (int)$row['tenant_id'];
    }

    private function ensureTables(): void
    {
        foreach ([self::EVENTS, self::PROPOSALS] as $table) {
            if (!(new OperationManagementService())->tableExists($table)) throw new RuntimeException('任务工作流存储尚未迁移（migration_required）', 503);
        }
    }

    private function dependencies(mixed $input, array $state, array $hotels): array
    {
        if (!is_array($input) || count($input) > 30) throw new InvalidArgumentException('依赖列表无效或超过30项');
        $ids = [];
        foreach ($input as $raw) {
            $id = $this->positive($raw, '依赖任务');
            if ($id === $state['task_id']) throw new InvalidArgumentException('任务不能依赖自身');
            $this->dependencyState($id, $state);
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids)); sort($ids);
        $visit = function (int $id, array $path) use (&$visit, $state): void {
            if ($id === $state['task_id'] || in_array($id, $path, true)) throw new InvalidArgumentException('任务依赖形成循环');
            if (count($path) > 60) throw new InvalidArgumentException('依赖层级过深');
            $dep = $this->dependencyState($id, $state);
            foreach ($dep['dependencies'] as $child) $visit($child, [...$path, $id]);
        };
        foreach ($ids as $id) $visit($id, []);
        return $ids;
    }

    private function dependencyState(int $id, array $s): array
    {
        [$task, $intent] = $this->task($id, [$s['scope']['hotel_id']]);
        if ($intent['platform'] !== $s['scope']['platform']) throw new InvalidArgumentException('依赖任务平台不匹配');
        $events = $this->events($task);
        $dep = $events === [] ? $this->legacy($task, $intent) : end($events)['state'];
        $dep['approval_status'] = $intent['status'];
        return $dep;
    }

    private function assertDependencies(array $s, array $hotels): void
    {
        foreach ($s['dependencies'] as $id) {
            $d = $this->dependencyState($id, $s);
            if ($d['approval_status'] !== 'approved' || $d['task_status'] !== 'completed' || $d['verification']['status'] !== 'manual_verified') {
                throw new InvalidArgumentException('前置任务 #' . $id . ' 尚未完成并核实');
            }
        }
    }

    private function nextStep(array $s, array $hotels, bool $live): array
    {
        if (!$live) return ['key' => 'history', 'label' => '历史版本仅供回读'];
        if ($s['approval_status'] !== 'approved') return ['key' => 'approval', 'label' => '等待有效人工审批'];
        if ($s['version'] === 0) return ['key' => 'configure', 'label' => '补齐负责人、对象、期限和完成条件'];
        try { $this->assertDependencies($s, $hotels); }
        catch (\Throwable $e) { return ['key' => 'dependency_blocked', 'label' => '先处理前置任务：' . $e->getMessage()]; }
        if ($s['task_status'] === 'blocked') return ['key' => 'resolve_block', 'label' => '解除阻塞后重新开始：' . $s['blocked_reason']];
        if ($s['task_status'] !== 'completed') return ['key' => $s['task_status'] === 'in_progress' ? 'record' : 'start', 'label' => '由负责人执行并逐项登记材料', 'overdue' => $s['due_date'] < substr($this->now(), 0, 10)];
        if ($s['verification']['status'] !== 'manual_verified') return ['key' => 'verify', 'label' => '人工核实执行；弱证据需退回补充'];
        try { $this->windowAfterExecution($s); }
        catch (InvalidArgumentException $e) { return ['key' => 'adjust_review_window', 'label' => '按实际执行日期调整复盘窗口']; }
        if ($s['review']['status'] === 'reviewed') return ['key' => 'reviewed', 'label' => '复盘已记录；效果未建立因果关系'];
        if ($s['review_window']['followup_end'] >= substr($this->now(), 0, 10)) return ['key' => 'wait_window', 'label' => '等待复盘窗口结束后的完整数据'];
        return ['key' => 'review', 'label' => '读取同口径前后窗口并记录复盘'];
    }

    private function assignee(int $id, array $scope): void
    {
        if ($this->assigneeCheck !== null) { $valid = ($this->assigneeCheck)($id, $scope); }
        else {
            $user = \app\model\User::where('id', $id)->whereNull('deleted_at')->find();
            $valid = $user && ($user->isSuperAdmin() || (int)$user->tenant_id === $scope['tenant_id'])
                && $user->hasHotelPermission($scope['hotel_id'], 'operation.execute');
        }
        if (!$valid) throw new InvalidArgumentException('负责人不具备该酒店运营权限');
    }
    private function scope(array $s): array
    {
        $start = $this->date($s['date_start'] ?? '', '范围起日'); $end = $this->date($s['date_end'] ?? '', '范围止日');
        if ($end < $start) throw new InvalidArgumentException('日期范围倒置');
        $platform = (string)($s['platform'] ?? '');
        if (!in_array($platform, ['ctrip', 'meituan', 'pms', 'manual'], true)) throw new InvalidArgumentException('平台范围缺失或不支持');
        return ['tenant_id' => $this->positive($s['tenant_id'] ?? null, '租户'), 'hotel_id' => $this->positive($s['hotel_id'] ?? null, '酒店'),
            'platform' => $platform, 'date_start' => $start, 'date_end' => $end, 'object_ref' => $this->text($s['object_ref'] ?? '', '执行对象')];
    }
    private function window(array $w): array
    {
        $out = [];
        foreach (['baseline_start', 'baseline_end', 'followup_start', 'followup_end'] as $k) $out[$k] = $this->date($w[$k] ?? '', '复盘时间窗 ' . $k);
        if ($out['baseline_start'] > $out['baseline_end'] || $out['followup_start'] > $out['followup_end'] || $out['baseline_end'] >= $out['followup_start']) throw new InvalidArgumentException('复盘前后窗口必须有序且不重叠');
        return $out;
    }
    private function windowAfterTask(array $window, array $scope): void { if ($window['followup_start'] <= $scope['date_end']) throw new InvalidArgumentException('后窗必须晚于任务业务日期'); }
    private function windowAfterExecution(array $s): void
    {
        $dates = array_column($s['execution_records'], 'performed_on');
        if ($dates !== [] && $s['review_window']['followup_start'] <= max($dates)) throw new InvalidArgumentException('后窗须晚于实际执行日期，请调整复盘窗口');
    }
    private function workflowType(mixed $s): string { if (!is_string($s) || !isset(self::TYPES[$s])) throw new InvalidArgumentException('请选择转化优化、价格检查或服务问题整改'); return $s; }
    private function positive(mixed $v, string $label): int { if (is_bool($v) || filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < 1) throw new InvalidArgumentException($label . '无效'); return (int)$v; }
    private function actor(int $v): void { if ($v <= 0) throw new InvalidArgumentException('需要已登录操作人'); }
    private function text(mixed $s, string $label): string { if (!is_string($s) || trim($s) === '' || mb_strlen($s) > 2000) throw new InvalidArgumentException($label . '必填且不超过2000字'); return trim($s); }
    private function token(mixed $s, string $label): string { if (!is_string($s) || !preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $s)) throw new InvalidArgumentException($label . '无效'); return $s; }
    private function strings(mixed $v, string $label): array { if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > 30) throw new InvalidArgumentException($label . '须为1至30条'); return array_values(array_unique(array_map(fn($s) => $this->text($s, $label), $v))); }
    private function date(mixed $v, string $label): string { if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) throw new InvalidArgumentException($label . '无效'); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $v); if (!$date || $date->format('Y-m-d') !== $v) throw new InvalidArgumentException($label . '无效'); return $v; }
    private function now(): string { return $this->clock ? ($this->clock)() : (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'); }
    private function json(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function digest(mixed $v): string { $sort = function ($x) use (&$sort) { if (!is_array($x)) return $x; if (!array_is_list($x)) ksort($x); return array_map($sort, $x); }; return hash('sha256', $this->json($sort($v))); }
    private function safeInput(array $v): void
    {
        if (strlen($this->json($v)) > 100000) throw new InvalidArgumentException('任务提交过大');
        array_walk_recursive($v, static function ($value, $key): void {
            if (preg_match('/password|cookie|authorization|token|secret|localstorage/i', (string)$key)
                || (is_string($value) && preg_match('/(?:Bearer\s+|(?:password|cookie|authorization|access_token)\s*[:=])/i', $value))) {
                throw new InvalidArgumentException('任务证据不得包含凭证材料');
            }
        });
    }
}
