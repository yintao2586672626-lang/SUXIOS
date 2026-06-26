<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AiEvaluationCase;
use app\model\AiModelCallLog;
use app\model\AiPromptVersion;
use app\model\OperationLog;
use app\service\AiEvaluationBatchReplayService;
use think\exception\HttpException;
use think\Response;

class AiGovernance extends Base
{
    private function checkSuperAdmin(): void
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            throw new HttpException(403, '仅超级管理员可访问AI治理');
        }
    }

    public function summary(): Response
    {
        $this->checkSuperAdmin();
        $todayStart = date('Y-m-d 00:00:00');

        return $this->success([
            'model_calls_total' => (int)AiModelCallLog::count(),
            'model_calls_today' => (int)AiModelCallLog::where('created_at', '>=', $todayStart)->count(),
            'low_confidence_total' => (int)AiModelCallLog::where('low_confidence', 1)->count(),
            'pending_human_confirmation_total' => (int)AiModelCallLog::where('human_confirmation_status', 'pending')->count(),
            'failed_or_blocked_total' => (int)AiModelCallLog::whereIn('status', ['failed', 'blocked'])->count(),
            'prompt_versions_total' => (int)AiPromptVersion::count(),
            'active_prompt_versions_total' => (int)AiPromptVersion::where('status', 'active')->count(),
            'evaluation_cases_total' => (int)AiEvaluationCase::count(),
            'active_evaluation_cases_total' => (int)AiEvaluationCase::where('status', 'active')->count(),
        ]);
    }

    public function logs(): Response
    {
        $this->checkSuperAdmin();
        $pagination = $this->getPagination();
        $query = AiModelCallLog::where([]);

        foreach (['request_id', 'module', 'scenario', 'model_key', 'status', 'prompt_version', 'human_confirmation_status', 'evaluation_set', 'eval_case_id'] as $field) {
            $value = trim((string)$this->request->param($field, ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }
        if ($this->request->param('low_confidence', '') !== '') {
            $query->where('low_confidence', (int)$this->request->param('low_confidence'));
        }
        if ($this->request->param('human_confirmation_required', '') !== '') {
            $query->where('human_confirmation_required', (int)$this->request->param('human_confirmation_required'));
        }
        $startDate = trim((string)$this->request->param('start_date', ''));
        $startAt = $this->normalizeDateTimeFilter($startDate, false);
        if ($startDate !== '' && $startAt === '') {
            return $this->error('start_date 格式仅支持 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS', 422);
        }
        if ($startAt !== '') {
            $query->where('created_at', '>=', $startAt);
        }

        $endDate = trim((string)$this->request->param('end_date', ''));
        $endAt = $this->normalizeDateTimeFilter($endDate, true);
        if ($endDate !== '' && $endAt === '') {
            return $this->error('end_date 格式仅支持 YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS', 422);
        }
        if ($endAt !== '') {
            $query->where('created_at', '<=', $endAt);
        }

        $total = (int)(clone $query)->count();
        $rows = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select()
            ->toArray();

        return $this->success([
            'list' => array_map([$this, 'formatLogRow'], $rows),
            'total' => $total,
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ]);
    }

    public function logDetail(int $id): Response
    {
        $this->checkSuperAdmin();
        if ($id <= 0) {
            return $this->error('日志ID无效', 422);
        }

        $row = AiModelCallLog::where('id', $id)->find();
        if (!$row) {
            return $this->error('AI调用日志不存在', 404);
        }

        return $this->success($this->formatLogRow($row->toArray(), true));
    }

    public function confirmLog(int $id): Response
    {
        $this->checkSuperAdmin();
        if ($id <= 0) {
            return $this->error('日志ID无效', 422);
        }

        $data = $this->requestData();
        $status = trim((string)($data['status'] ?? 'confirmed'));
        if (!in_array($status, ['confirmed', 'rejected'], true)) {
            return $this->error('人工确认状态仅支持 confirmed/rejected', 422);
        }

        $row = AiModelCallLog::where('id', $id)->find();
        if (!$row) {
            return $this->error('AI调用日志不存在', 404);
        }

        $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 500);
        $row->save([
            'human_confirmation_status' => $status,
            'human_confirmed_by' => (int)($this->currentUser->id ?? 0),
            'human_confirmed_at' => date('Y-m-d H:i:s'),
            'human_confirmation_note' => $note,
        ]);

        OperationLog::record('ai_governance', 'confirm_model_call', '人工确认AI模型调用日志: ' . $id, (int)($this->currentUser->id ?? 0), null, null, [
            'status' => $status,
            'note' => $note,
        ]);

        $row = AiModelCallLog::where('id', $id)->find();
        return $this->success($this->formatLogRow($row->toArray(), true), 'AI调用日志已确认');
    }

    public function promptVersions(): Response
    {
        $this->checkSuperAdmin();
        $pagination = $this->getPagination();
        $query = AiPromptVersion::where([]);
        foreach (['prompt_key', 'scenario', 'status'] as $field) {
            $value = trim((string)$this->request->param($field, ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $total = (int)(clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select()
            ->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ]);
    }

    public function savePromptVersion(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->requestData();
        $promptKey = mb_substr(trim((string)($data['prompt_key'] ?? '')), 0, 120);
        $version = mb_substr(trim((string)($data['version'] ?? '')), 0, 80);
        if ($promptKey === '' || $version === '') {
            return $this->error('prompt_key 和 version 必填', 422);
        }

        $status = $this->normalizeActiveArchiveStatus($data['status'] ?? 'active');
        if ($status === '') {
            return $this->error('status 仅支持 active/archived', 422);
        }

        $content = (string)($data['content'] ?? '');
        $contentHash = $content !== '' ? hash('sha256', $content) : strtolower(trim((string)($data['content_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $contentHash)) {
            return $this->error('content 或 64位 content_hash 必填', 422);
        }

        $payload = [
            'scenario' => mb_substr(trim((string)($data['scenario'] ?? '')), 0, 120),
            'content_hash' => $contentHash,
            'description' => mb_substr(trim((string)($data['description'] ?? '')), 0, 500),
            'status' => $status,
            'created_by' => (int)($this->currentUser->id ?? 0),
        ];

        $row = AiPromptVersion::where('prompt_key', $promptKey)->where('version', $version)->find();
        if ($row) {
            $row->save($payload);
        } else {
            $row = AiPromptVersion::create(array_merge([
                'prompt_key' => $promptKey,
                'version' => $version,
            ], $payload));
        }

        $row = AiPromptVersion::where('prompt_key', $promptKey)->where('version', $version)->find();
        return $this->success($row->toArray(), 'Prompt版本已保存');
    }

    public function evaluationCases(): Response
    {
        $this->checkSuperAdmin();
        $pagination = $this->getPagination();
        $query = AiEvaluationCase::where([]);
        foreach (['case_key', 'scenario', 'prompt_version', 'status'] as $field) {
            $value = trim((string)$this->request->param($field, ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $total = (int)(clone $query)->count();
        $list = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select()
            ->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $pagination['page'],
            'page_size' => $pagination['page_size'],
        ]);
    }

    public function saveEvaluationCase(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->requestData();
        $caseKey = mb_substr(trim((string)($data['case_key'] ?? '')), 0, 120);
        if ($caseKey === '') {
            return $this->error('case_key 必填', 422);
        }

        $status = $this->normalizeActiveArchiveStatus($data['status'] ?? 'active');
        if ($status === '') {
            return $this->error('status 仅支持 active/archived', 422);
        }

        $payload = [
            'scenario' => mb_substr(trim((string)($data['scenario'] ?? '')), 0, 120),
            'prompt_version' => mb_substr(trim((string)($data['prompt_version'] ?? '')), 0, 120),
            'input_json' => is_array($data['input'] ?? null) ? $data['input'] : ($data['input_json'] ?? []),
            'expected_json' => is_array($data['expected'] ?? null) ? $data['expected'] : ($data['expected_json'] ?? []),
            'metric_json' => is_array($data['metrics'] ?? null) ? $data['metrics'] : ($data['metric_json'] ?? []),
            'status' => $status,
            'created_by' => (int)($this->currentUser->id ?? 0),
        ];

        $row = AiEvaluationCase::where('case_key', $caseKey)->find();
        if ($row) {
            $row->save($payload);
        } else {
            $row = AiEvaluationCase::create(array_merge(['case_key' => $caseKey], $payload));
        }

        $row = AiEvaluationCase::where('case_key', $caseKey)->find();
        return $this->success($row->toArray(), '评估集用例已保存');
    }

    public function replayEvaluationCases(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->requestData();

        $evaluationSet = mb_substr(trim((string)($data['evaluation_set'] ?? $this->request->param('evaluation_set', ''))), 0, 120);
        if ($evaluationSet === '') {
            return $this->error('evaluation_set 必填，用于区分本次批量评估归属', 422);
        }

        $query = AiEvaluationCase::where('status', 'active');
        $scenario = mb_substr(trim((string)($data['scenario'] ?? $this->request->param('scenario', ''))), 0, 120);
        if ($scenario !== '') {
            $query->where('scenario', $scenario);
        }
        $promptVersion = mb_substr(trim((string)($data['prompt_version'] ?? $this->request->param('prompt_version', ''))), 0, 120);
        if ($promptVersion !== '') {
            $query->where('prompt_version', $promptVersion);
        }
        $caseKeys = $this->normalizeStringList($data['case_keys'] ?? $data['case_key'] ?? $this->request->param('case_keys', []));
        if (!empty($caseKeys)) {
            $query->whereIn('case_key', $caseKeys);
        }

        $limit = max(1, min(100, (int)($data['limit'] ?? $this->request->param('limit', 50))));
        $dryRun = $this->normalizeReplayBool($data['dry_run'] ?? $this->request->param('dry_run', null), true);
        if (array_key_exists('execute', $data) || $this->request->param('execute', null) !== null) {
            $dryRun = !$this->normalizeReplayBool($data['execute'] ?? $this->request->param('execute', null), false);
        }
        $allowExternalModelCall = $this->normalizeReplayBool(
            $data['allow_external_model_call'] ?? $this->request->param('allow_external_model_call', null),
            false
        );
        $modelKey = mb_substr(trim((string)($data['model_key'] ?? $this->request->param('model_key', 'deepseek_v4_default'))), 0, 100);

        $rows = $query->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $result = (new AiEvaluationBatchReplayService())->run($rows, [
            'evaluation_set' => $evaluationSet,
            'model_key' => $modelKey,
            'dry_run' => $dryRun,
            'allow_external_model_call' => $allowExternalModelCall,
        ]);

        if (!$dryRun && !$allowExternalModelCall) {
            return $this->success($result, '评估集回放已阻断：未授权外部模型调用');
        }

        return $this->success($result, $dryRun ? '评估集回放计划已生成' : '评估集回放已执行');
    }

    public function archiveEvaluationCase(int $id): Response
    {
        $this->checkSuperAdmin();
        if ($id <= 0) {
            return $this->error('评估用例ID无效', 422);
        }

        $updated = AiEvaluationCase::where('id', $id)->update([
            'status' => 'archived',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($updated <= 0) {
            return $this->error('评估用例不存在', 404);
        }

        return $this->success(['id' => $id], '评估集用例已归档');
    }

    private function formatLogRow(array $row, bool $withDetail = false): array
    {
        $governance = is_array($row['governance_json'] ?? null) ? $row['governance_json'] : [];
        $base = [
            'id' => (int)($row['id'] ?? 0),
            'request_id' => (string)($row['request_id'] ?? ''),
            'module' => (string)($row['module'] ?? ''),
            'scenario' => (string)($row['scenario'] ?? ''),
            'provider' => (string)($row['provider'] ?? ''),
            'model_key' => (string)($row['model_key'] ?? ''),
            'model_name' => (string)($row['model_name'] ?? ''),
            'prompt_version' => (string)($row['prompt_version'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'http_status' => (int)($row['http_status'] ?? 0),
            'latency_ms' => (int)($row['latency_ms'] ?? 0),
            'confidence_score' => $row['confidence_score'] ?? null,
            'low_confidence' => !empty($row['low_confidence']),
            'low_confidence_reason' => (string)($governance['low_confidence_reason'] ?? ''),
            'decision_impact' => (string)($governance['decision_impact'] ?? ''),
            'human_confirmation_required' => !empty($row['human_confirmation_required']),
            'human_confirmation_status' => (string)($row['human_confirmation_status'] ?? ''),
            'human_confirmation_reason' => (string)($governance['human_confirmation_reason'] ?? ''),
            'knowledge_source_count' => count(is_array($row['knowledge_sources_json'] ?? null) ? $row['knowledge_sources_json'] : []),
            'evaluation_set' => (string)($row['evaluation_set'] ?? ($governance['evaluation_set'] ?? '')),
            'eval_case_id' => (string)($row['eval_case_id'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];

        if (!$withDetail) {
            return $base;
        }

        return array_merge($base, [
            'prompt_hash' => (string)($row['prompt_hash'] ?? ''),
            'prompt_preview' => (string)($row['prompt_preview'] ?? ''),
            'response_hash' => (string)($row['response_hash'] ?? ''),
            'response_preview' => (string)($row['response_preview'] ?? ''),
            'error_type' => (string)($row['error_type'] ?? ''),
            'error_message' => (string)($row['error_message'] ?? ''),
            'knowledge_sources' => is_array($row['knowledge_sources_json'] ?? null) ? $row['knowledge_sources_json'] : [],
            'governance' => $governance,
            'human_confirmed_by' => (int)($row['human_confirmed_by'] ?? 0),
            'human_confirmed_at' => (string)($row['human_confirmed_at'] ?? ''),
            'human_confirmation_note' => (string)($row['human_confirmation_note'] ?? ''),
        ]);
    }

    private function normalizeDateTimeFilter(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return str_replace('T', ' ', $value);
        }
        return '';
    }

    private function normalizeActiveArchiveStatus(mixed $value): string
    {
        $status = trim((string)$value);
        if ($status === '') {
            return 'active';
        }
        return in_array($status, ['active', 'archived'], true) ? $status : '';
    }

    private function normalizeReplayBool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = mb_substr(trim((string)$item), 0, 120);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }
}
