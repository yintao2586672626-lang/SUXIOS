<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Manual case coaching with immutable evidence and versioned reviews. */
class ManagerCoachingService
{
    public const VERSION = 'manager_coaching.v1';
    private const METHODS = ['unknown' => '补充原因证据', 'knowledge' => '标准学习与复述',
        'skill' => '示范与实操', 'execution' => '责任标准与行为复查', 'objective' => '流程资源整改'];

    public function create(int $tenantId, int $hotelId, int $managerId, int $actorId, array $input): array
    {
        $caseId = (int)($input['case_id'] ?? 0);
        $case = $this->caseForScope($tenantId, $hotelId, $managerId, $caseId);
        if (($case['is_voided'] ?? false) === true) throw new InvalidArgumentException('已作废案例不能发起带教计划');
        $key = $this->text($input['idempotency_key'] ?? '', '重试标识', 100);
        $inputDigest = $this->digest([$tenantId, $hotelId, $managerId, $actorId, $input]);
        return Db::transaction(function () use ($tenantId, $hotelId, $managerId, $actorId, $input, $case, $caseId, $key, $inputDigest) {
            Db::name('manager_capability_cases')->where('id', $caseId)->where('hotel_id', $hotelId)->lock(true)->find();
            $existing = Db::name('manager_coaching_plans')->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)
                ->where('created_by', $actorId)->where('idempotency_key', $key)->find();
            if ($existing) {
                if (!hash_equals($existing['input_digest'], $inputDigest)) throw new InvalidArgumentException('重试标识已用于不同计划');
                return $this->read($tenantId, $hotelId, $managerId, (int)$existing['id']);
            }
            $plan = $this->normalizePlan($input, $hotelId, $actorId);
            if ($plan['business_date'] < $case['business_date']) throw new InvalidArgumentException('计划起始日期不能早于来源案例日期');
            $plan['case_snapshot'] = ['id' => $caseId, 'business_date' => $case['business_date'],
                'problem_facts' => $case['problem_facts'], 'source_quality_status' => 'manual_declared'];
            $plan['schema_version'] = self::VERSION;
            $plan['knowledge_snapshots'] = [];
            $refs = array_values(array_unique(array_map('intval', (array)($input['knowledge_chunk_ids'] ?? []))));
            if (count($refs) > 8) throw new InvalidArgumentException('每个计划最多引用 8 个知识版本');
            foreach ($refs as $chunkId) $plan['knowledge_snapshots'][] = (new KnowledgeReferenceService())->source($chunkId, $hotelId, $actorId);
            if ($plan['cause'] === 'knowledge' && !$refs) throw new InvalidArgumentException('知识问题需要引用至少一个知识版本');
            $status = $plan['cause'] === 'unknown' ? 'pending_diagnosis' : 'planned';
            $now = date('Y-m-d H:i:s');
            $id = (int)Db::name('manager_coaching_plans')->insertGetId([
                'tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'manager_user_id' => $managerId,
                'case_id' => $caseId, 'created_by' => $actorId, 'idempotency_key' => $key,
                'input_digest' => $inputDigest, 'revision' => 1, 'status' => $status,
                'plan_json' => $this->json($plan), 'content_digest' => $this->digest($plan),
                'due_on' => $plan['due_on'], 'review_on' => $plan['review_on'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->event($id, $tenantId, $hotelId, $actorId, 'created', 1, $key, $inputDigest, ['plan' => $plan]);
            return $this->read($tenantId, $hotelId, $managerId, $id);
        });
    }

    public function listing(int $tenantId, int $hotelId, int $managerId): array
    {
        $rows = Db::name('manager_coaching_plans')->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)
            ->where('manager_user_id', $managerId)->order('id', 'desc')->limit(100)->select()->toArray();
        return ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'manager_user_id' => $managerId,
            'list' => array_map(fn($r) => $this->format($r), $rows), 'limit' => 100];
    }

    public function read(int $tenantId, int $hotelId, int $managerId, int $id): array
    {
        $row = $this->row($tenantId, $hotelId, $managerId, $id);
        $events = Db::name('manager_coaching_events')->where('plan_id', $id)->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)->order('id', 'asc')->select()->toArray();
        foreach ($events as &$event) $event['payload'] = $this->decode($event['payload_json']);
        unset($event);
        return ['plan' => $this->format($row), 'events' => $events, 'persistence_status' => 'readback_verified',
            'boundaries' => ['source_quality' => 'manual_declared', 'operating_effect_verified' => false,
                'updates_capability_score' => false, 'automatic_execution' => false, 'external_send' => false]];
    }

    public function mutate(int $tenantId, int $hotelId, int $managerId, int $actorId, int $id, string $kind, array $input): array
    {
        if (!in_array($kind, ['edit', 'evidence', 'review', 'defer', 'cancel', 'recur', 'knowledge'], true)) throw new InvalidArgumentException('不支持的带教操作');
        $key = $this->text($input['idempotency_key'] ?? '', '重试标识', 100);
        $inputDigest = $this->digest([$kind, $input]);
        return Db::transaction(function () use ($tenantId, $hotelId, $managerId, $actorId, $id, $kind, $input, $key, $inputDigest) {
            $row = $this->row($tenantId, $hotelId, $managerId, $id, true);
            $replay = Db::name('manager_coaching_events')->where('plan_id', $id)->where('actor_id', $actorId)->where('idempotency_key', $key)->find();
            if ($replay) {
                if (!hash_equals($replay['input_digest'], $inputDigest)) throw new InvalidArgumentException('重试标识已用于不同内容');
                return $this->read($tenantId, $hotelId, $managerId, $id);
            }
            if ((int)($input['expected_revision'] ?? 0) !== (int)$row['revision']) throw new RuntimeException('版本冲突：计划已更新，请刷新比较；当前草稿已保留');
            $case = $this->caseForScope($tenantId, $hotelId, $managerId, (int)$row['case_id']);
            if (($case['is_voided'] ?? false) === true && $kind !== 'cancel') throw new InvalidArgumentException('原案例已作废，只能取消该计划');
            if ($row['status'] === 'cancelled') throw new InvalidArgumentException('已取消计划不能继续修改');
            if ($row['status'] === 'completed' && !in_array($kind, ['recur', 'knowledge'], true)) throw new InvalidArgumentException('已达目标计划只能记录复发或整理知识');
            $plan = $this->decode($row['plan_json']);
            $state = $this->read($tenantId, $hotelId, $managerId, $id);
            $status = (string)$row['status'];
            $payload = [];
            switch ($kind) {
                case 'edit':
                    if (array_filter($state['events'], static fn($e) => $e['event_type'] === 'evidence')) throw new InvalidArgumentException('已有实操证据，目标标准已冻结；请通过复查或新计划调整');
                    $updated = $this->normalizePlan($input, $hotelId, $actorId);
                    if ($updated['business_date'] < $case['business_date']) throw new InvalidArgumentException('计划起始日期不能早于来源案例日期');
                    $snapshots = [];
                    foreach (array_unique(array_map('intval', (array)($input['knowledge_chunk_ids'] ?? []))) as $chunkId) {
                        if (count($snapshots) >= 8) throw new InvalidArgumentException('每个计划最多引用 8 个知识版本');
                        $snapshots[] = (new KnowledgeReferenceService())->source($chunkId, $hotelId, $actorId);
                    }
                    if ($updated['cause'] === 'knowledge' && !$snapshots) throw new InvalidArgumentException('知识问题需要引用知识版本');
                    $plan = array_merge($plan, $updated, ['knowledge_snapshots' => $snapshots]);
                    $status = $plan['cause'] === 'unknown' ? 'pending_diagnosis' : 'planned';
                    $payload = ['plan' => $plan];
                    break;
                case 'evidence':
                    if ($plan['cause'] === 'unknown') throw new InvalidArgumentException('请先核实原因并保存计划，再提交实操证据');
                    $stage = (string)($input['stage'] ?? '');
                    if (!in_array($stage, ['learned', 'practiced', 'independent'], true)) throw new InvalidArgumentException('请选择学习、练习或独立完成阶段');
                    $payload = $this->evidence($input, $plan);
                    $payload['stage'] = $stage;
                    $payload['independent_completion'] = $stage === 'independent';
                    $status = $stage === 'independent' ? 'awaiting_review' : 'in_progress';
                    break;
                case 'review':
                    $conclusion = (string)($input['conclusion'] ?? '');
                    if (!in_array($conclusion, ['improved', 'target_met', 'not_improved', 'insufficient'], true)) throw new InvalidArgumentException('请选择复查结论');
                    $payload = ['conclusion' => $conclusion, 'note' => $this->text($input['note'] ?? '', '复查说明', 5000),
                        'observed_on' => $this->date($input['observed_on'] ?? '', '复查日期'), 'criteria' => $plan['acceptance_criteria'],
                        'source_quality_status' => 'manual_declared'];
                    if ($payload['observed_on'] > $this->today() || $payload['observed_on'] < $plan['business_date']) throw new InvalidArgumentException('复查日期必须位于案例日期与今天之间');
                    if ($conclusion !== 'insufficient') {
                        if ($plan['cause'] === 'unknown') throw new InvalidArgumentException('原因尚未核实，只能记录证据不足');
                        if ($payload['observed_on'] < $plan['review_on']) throw new InvalidArgumentException('尚未到计划复查日期，可记录证据不足或等待复查');
                        $payload = array_merge($payload, $this->evidence($input, $plan));
                        if ($payload['sample_count'] < $plan['minimum_samples']) throw new InvalidArgumentException('复查样本少于计划约定，结论应为证据不足');
                    }
                    if ($conclusion === 'target_met') {
                        $cycleStart = 0;
                        foreach ($state['events'] as $prior) {
                            if ($prior['event_type'] === 'recur') $cycleStart = (int)$prior['id'];
                        }
                        $independent = array_filter($state['events'], static fn($e) =>
                            (int)$e['id'] > $cycleStart && $e['event_type'] === 'evidence'
                            && ($e['payload']['stage'] ?? '') === 'independent'
                            && ($e['payload']['observed_on'] ?? '') <= $payload['observed_on']);
                        if (!$independent || ($input['criteria_confirmed'] ?? false) !== true) throw new InvalidArgumentException('达到目标需要独立完成证据并逐项确认验收标准');
                    }
                    $status = match ($conclusion) { 'target_met' => 'completed', 'insufficient' => 'awaiting_evidence', default => 'needs_followup' };
                    if ($status !== 'completed') {
                        $plan['review_on'] = $this->futureDate($input['next_review_on'] ?? '', '下次复查日期');
                        $payload['next_review_on'] = $plan['review_on'];
                    }
                    break;
                case 'defer':
                    $payload = ['note' => $this->text($input['note'] ?? '', '延期原因', 2000), 'previous_review_on' => $plan['review_on']];
                    $plan['review_on'] = $this->futureDate($input['next_review_on'] ?? '', '下次复查日期');
                    $payload['next_review_on'] = $plan['review_on'];
                    $status = $plan['cause'] === 'unknown' ? 'pending_diagnosis' : 'awaiting_evidence';
                    break;
                case 'cancel':
                    $payload = ['note' => $this->text($input['note'] ?? '', '取消原因', 2000)];
                    $status = 'cancelled';
                    break;
                case 'recur':
                    if ($status !== 'completed') throw new InvalidArgumentException('只有已达到目标的计划可以记录复发');
                    $payload = $this->evidence($input, $plan);
                    $plan['review_on'] = $this->futureDate($input['next_review_on'] ?? '', '下次复查日期');
                    $payload['next_review_on'] = $plan['review_on'];
                    $status = 'needs_followup';
                    break;
                case 'knowledge':
                    $reviews = array_values(array_filter($state['events'], static fn($e) => $e['event_type'] === 'review'));
                    if (!$reviews) throw new InvalidArgumentException('至少完成一次复查后才能整理参考经验');
                    $title = $this->text($input['title'] ?? '', '经验标题', 180);
                    $summary = $this->text($input['summary'] ?? '', '可公开的经验摘要', 5000);
                    $content = (new KnowledgeReferenceService())->referenceContent($title,
                        ['objective' => $summary, 'steps' => $this->text($input['steps'] ?? '', '经验步骤', 5000),
                            'applicability' => $this->text($input['applicability'] ?? '', '适用条件', 2000),
                            'stop_conditions' => $this->text($input['stop_conditions'] ?? '', '停止条件', 2000),
                            'acceptance_criteria' => $this->text($input['acceptance_criteria'] ?? '', '脱敏验收方法', 2000)], []);
                    // Only user-reviewed anonymized prose is copied; no case/person evidence is published.
                    $content['source_refs'] = ['manager_coaching_plan#' . $id . '/revision/' . $row['revision']];
                    $content['coaching_origin'] = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'plan_id' => $id,
                        'revision' => (int)$row['revision'], 'review_event_id' => (int)end($reviews)['id']];
                    $content['boundaries'][] = '人工脱敏整理；原始人员案例仅在带教权限范围内可见。培训复查不证明经营效果。';
                    $saved = (new KnowledgeReferenceService())->persist($hotelId, $actorId, $content,
                        ['idempotency_key' => 'coaching-' . $id . '-' . $key]);
                    $payload = ['knowledge_unit_id' => (int)$saved['unit']['unit_id'], 'knowledge_chunk_id' => (int)$saved['chunk']['chunk_id'],
                        'content_digest' => $saved['chunk']['content_digest'], 'formal_knowledge' => false];
                    break;
            }
            $revision = (int)$row['revision'] + 1;
            Db::name('manager_coaching_plans')->where('id', $id)->where('revision', $row['revision'])->update([
                'revision' => $revision, 'status' => $status, 'plan_json' => $this->json($plan), 'content_digest' => $this->digest($plan),
                'due_on' => $plan['due_on'], 'review_on' => $plan['review_on'], 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $eventId = $this->event($id, $tenantId, $hotelId, $actorId, $kind, $revision, $key, $inputDigest, $payload);
            $result = $this->read($tenantId, $hotelId, $managerId, $id);
            if ((int)$result['plan']['revision'] !== $revision || !in_array($eventId, array_map('intval', array_column($result['events'], 'id')), true)) throw new RuntimeException('带教操作独立回读不一致');
            return $result;
        });
    }

    protected function caseForScope(int $tenantId, int $hotelId, int $managerId, int $caseId): array
    { return (new ManagerCapabilityScoringService())->readCase($tenantId, $hotelId, $managerId, $caseId); }

    private function normalizePlan(array $input, int $hotelId, int $actorId): array
    {
        $cause = (string)($input['cause'] ?? 'unknown');
        if (!isset(self::METHODS[$cause])) throw new InvalidArgumentException('原因分类无效');
        $plan = ['cause' => $cause, 'method' => self::METHODS[$cause]];
        foreach (['title' => '计划标题', 'cause_basis' => '原因依据或待核实问题', 'objective' => '目标行为',
            'steps' => '执行步骤', 'acceptance_criteria' => '验收标准', 'responsible_name' => '带教或整改负责人'] as $key => $label) {
            $plan[$key] = $this->text($input[$key] ?? '', $label, $key === 'steps' ? 6000 : 2000);
        }
        $plan['business_date'] = $this->date($input['business_date'] ?? $this->today(), '计划起始日期');
        if ($plan['business_date'] > $this->today()) throw new InvalidArgumentException('计划起始日期不能晚于今天');
        $plan['due_on'] = $this->date($input['due_on'] ?? '', '截止日期');
        $plan['review_on'] = $this->date($input['review_on'] ?? '', '复查日期');
        if ($plan['due_on'] < $plan['business_date'] || $plan['review_on'] < $plan['due_on']) throw new InvalidArgumentException('日期必须按起始、截止、复查顺序填写');
        $plan['minimum_samples'] = filter_var($input['minimum_samples'] ?? null, FILTER_VALIDATE_INT);
        if ($plan['minimum_samples'] === false || $plan['minimum_samples'] < 1 || $plan['minimum_samples'] > 10000) throw new InvalidArgumentException('最低复查样本数必须为 1–10000 的整数');
        return $plan;
    }

    private function evidence(array $input, array $plan): array
    {
        $date = $this->date($input['observed_on'] ?? '', '证据日期');
        if ($date < $plan['business_date'] || $date > $this->today()) throw new InvalidArgumentException('证据日期必须位于计划起始日期与今天之间');
        $samples = filter_var($input['sample_count'] ?? null, FILTER_VALIDATE_INT);
        if ($samples === false || $samples < 1 || $samples > 10000) throw new InvalidArgumentException('证据样本数必须为 1–10000 的整数');
        return ['observed_on' => $date, 'sample_count' => $samples, 'evidence_ref' => $this->text($input['evidence_ref'] ?? '', '证据位置', 1000),
            'note' => $this->text($input['note'] ?? '', '观察记录', 5000), 'source_quality_status' => 'manual_declared'];
    }
    private function row(int $tenantId, int $hotelId, int $managerId, int $id, bool $lock = false): array
    {
        $row = Db::name('manager_coaching_plans')->where('id', $id)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)
            ->where('manager_user_id', $managerId)->lock($lock)->find();
        if (!$row) throw new RuntimeException('带教计划不存在或无权查看');
        return $row;
    }
    private function format(array $row): array
    {
        $row['content'] = $this->decode($row['plan_json']);
        unset($row['plan_json'], $row['input_digest'], $row['idempotency_key']);
        if (!(new KnowledgeContentDigestService())->matches($row['content_digest'], $row['content'])) throw new RuntimeException('带教计划内容校验失败');
        $row['overdue'] = !in_array($row['status'], ['completed', 'cancelled'], true) && $row['review_on'] < $this->today();
        return $row;
    }
    private function event(int $id, int $tenantId, int $hotelId, int $actorId, string $kind, int $revision, string $key, string $digest, array $payload): int
    {
        return (int)Db::name('manager_coaching_events')->insertGetId(['plan_id' => $id, 'tenant_id' => $tenantId, 'hotel_id' => $hotelId,
            'actor_id' => $actorId, 'event_type' => $kind, 'revision' => $revision, 'idempotency_key' => $key,
            'input_digest' => $digest, 'payload_json' => $this->json($payload), 'created_at' => date('Y-m-d H:i:s')]);
    }
    private function date(mixed $value, string $label): string
    {
        $text = is_string($value) ? $value : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date || $date->format('Y-m-d') !== $text) throw new InvalidArgumentException($label . '格式无效');
        return $text;
    }
    private function futureDate(mixed $value, string $label): string
    { $date = $this->date($value, $label); if ($date <= $this->today()) throw new InvalidArgumentException($label . '必须晚于今天'); return $date; }
    private function text(mixed $value, string $label, int $limit): string
    { if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) throw new InvalidArgumentException($label . '不能为空且不能超过 ' . $limit . ' 字'); return trim($value); }
    protected function today(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d'); }
    private function digest(array $value): string { return (new KnowledgeContentDigestService())->digest($value); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function decode(mixed $value): array { return is_array($value) ? $value : json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR); }
}
