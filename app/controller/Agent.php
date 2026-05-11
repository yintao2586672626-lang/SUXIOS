<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\AgentTask;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\EnergyConsumption;
use app\model\Device;
use app\model\DeviceCategory;
use app\model\DeviceMaintenance;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\AgentWorkOrder;
use app\model\AgentConversation;
use app\model\EnergyBenchmark;
use app\model\EnergySavingSuggestion;
use app\model\MaintenancePlan;
use app\model\OperationLog;
use app\model\AiModelConfig;
use app\service\FeasibilityReportService;
use think\Response;
use think\facade\Db;

/**
 * Agent控制器
 * 管理三个AI Agent的功能：智能员工、收益管理、资产运维
 */
class Agent extends Base
{
    private function feasibilityService(): FeasibilityReportService
    {
        return new FeasibilityReportService();
    }

    private function callLlm(string $prompt, string $modelKey = 'openai_fast'): array
    {
        $config = $this->getLlmConfigByModelKey($modelKey);
        if (($config['ok'] ?? false) !== true) {
            return $config;
        }

        $apiKey = (string) $config['api_key'];
        $baseUrl = rtrim((string) $config['base_url'], '/');

        $payload = [
            'model' => $config['model'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ]),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($baseUrl . '/chat/completions', false, $context);
        if ($response === false) {
            return ['ok' => false, 'message' => '网络请求失败', 'code' => 502];
        }

        $headers = $http_response_header ?? [];
        $statusCode = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'message' => '模型返回异常', 'code' => 502];
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return ['ok' => false, 'message' => '模型返回异常', 'code' => 502];
        }

        return ['ok' => true, 'content' => trim($content)];
    }

    private function getLlmConfigByModelKey(string $modelKey): array
    {
        $modelKey = trim($modelKey) !== '' ? trim($modelKey) : 'deepseek_chat';

        $dbConfig = $this->getDatabaseLlmConfigByModelKey($modelKey);
        if ($dbConfig !== null) {
            return $dbConfig;
        }

        return $this->getEnvLlmConfigByModelKey($modelKey);
    }

    private function getDatabaseLlmConfigByModelKey(string $modelKey): ?array
    {
        try {
            $config = AiModelConfig::where('model_key', $modelKey)->find();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$config) {
            return null;
        }

        if ((int) $config->is_enabled !== 1) {
            return ['ok' => false, 'message' => '模型配置已禁用', 'code' => 400];
        }

        $baseUrl = rtrim(trim((string) $config->base_url), '/');
        $modelName = trim((string) $config->model_name);
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => '未配置模型 base_url', 'code' => 400];
        }
        if ($modelName === '') {
            return ['ok' => false, 'message' => '未配置模型名称', 'code' => 400];
        }

        if (trim((string) $config->api_key_encrypted) === '') {
            return ['ok' => false, 'message' => '未配置模型 API Key', 'code' => 400];
        }

        $secret = trim((string) env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            return ['ok' => false, 'message' => '未配置 AI_CONFIG_SECRET', 'code' => 400];
        }

        $apiKey = AiModelConfig::decryptApiKey((string) $config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            return ['ok' => false, 'message' => '模型 API Key 解密失败', 'code' => 400];
        }

        return [
            'ok' => true,
            'provider' => (string) $config->provider,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $modelName,
            'model_key' => $modelKey,
            'source' => 'database',
        ];
    }

    private function getEnvLlmConfigByModelKey(string $modelKey): array
    {
        $configs = [
            'deepseek_chat' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => 'deepseek-chat',
            ],
            'deepseek_reasoner' => [
                'provider' => 'deepseek',
                'base_url' => rtrim(trim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1')), '/'),
                'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
                'model' => 'deepseek-reasoner',
            ],
            'openai_fast' => [
                'provider' => 'openai',
                'base_url' => rtrim(trim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1')), '/'),
                'api_key' => trim((string) env('OPENAI_API_KEY', '')),
                'model' => trim((string) env('OPENAI_MODEL', '')),
            ],
        ];

        if (!isset($configs[$modelKey])) {
            return ['ok' => false, 'message' => '未识别的 model_key', 'code' => 422];
        }

        $config = $configs[$modelKey];
        if ($config['api_key'] === '') {
            $envName = $config['provider'] === 'deepseek' ? 'DEEPSEEK_API_KEY' : 'OPENAI_API_KEY';
            return ['ok' => false, 'message' => '未配置 ' . $envName, 'code' => 400];
        }
        if ($config['base_url'] === '') {
            $envName = $config['provider'] === 'deepseek' ? 'DEEPSEEK_BASE_URL' : 'OPENAI_BASE_URL';
            return ['ok' => false, 'message' => '未配置 ' . $envName, 'code' => 400];
        }
        if ($config['model'] === '') {
            return ['ok' => false, 'message' => '未配置 OPENAI_MODEL', 'code' => 400];
        }

        $config['ok'] = true;
        $config['model_key'] = $modelKey;
        $config['source'] = 'env';
        return $config;
    }

    private function isAllowedLlmModelKey(string $modelKey): bool
    {
        if (in_array($modelKey, ['deepseek_chat', 'deepseek_reasoner', 'openai_fast'], true)) {
            return true;
        }

        try {
            return AiModelConfig::where('model_key', $modelKey)->find() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查管理员权限
     */
    protected function checkAdmin(): void
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            abort(403, '只有超级管理员可以访问Agent功能');
        }
    }

    // ==================== Agent概览 ====================

    /**
     * 获取Agent概览数据
     */
    public function overview(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 获取三个Agent的状态
        $agentConfigs = AgentConfig::where('hotel_id', $hotelId)
            ->column('agent_type, is_enabled', 'agent_type');
        
        // 获取今日任务统计
        $todayTasks = AgentTask::where('hotel_id', $hotelId)
            ->whereDay('create_time', date('Y-m-d'))
            ->field('agent_type, status, COUNT(*) as count')
            ->group('agent_type, status')
            ->select();
        
        $taskStats = [];
        foreach ($todayTasks as $task) {
            $type = $task['agent_type'];
            $status = $task['status'];
            if (!isset($taskStats[$type])) {
                $taskStats[$type] = [
                    'total' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'completed' => 0,
                    'failed' => 0,
                ];
            }
            $taskStats[$type]['total'] += $task['count'];
            if ($status == AgentTask::STATUS_PENDING) {
                $taskStats[$type]['pending'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_RUNNING) {
                $taskStats[$type]['running'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_COMPLETED) {
                $taskStats[$type]['completed'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_FAILED) {
                $taskStats[$type]['failed'] = $task['count'];
            }
        }
        
        // 获取最近日志
        $recentLogs = AgentLog::where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(10)
            ->select();
        
        return $this->success([
            'agents' => [
                'staff' => [
                    'name' => '智能员工Agent',
                    'type' => AgentConfig::AGENT_TYPE_STAFF,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_STAFF]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_STAFF] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '👥',
                    'description' => '前台客服、工单处理、知识库问答',
                ],
                'revenue' => [
                    'name' => '收益管理Agent',
                    'type' => AgentConfig::AGENT_TYPE_REVENUE,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_REVENUE]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_REVENUE] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '💰',
                    'description' => '竞对价格监控、定价建议、需求预测',
                ],
                'asset' => [
                    'name' => '资产运维Agent',
                    'type' => AgentConfig::AGENT_TYPE_ASSET,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_ASSET]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_ASSET] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '🔧',
                    'description' => '能耗监控、设备维护预警',
                ],
            ],
            'recent_logs' => $recentLogs,
        ]);
    }

    public function testLlm(): Response
    {
        $this->checkAdmin();

        $prompt = trim((string) $this->request->param('prompt', ''));
        if ($prompt === '') {
            $prompt = '请用一句话说明你已接入宿析OS';
        }

        $result = $this->callLlm($prompt);
        if (($result['ok'] ?? false) !== true) {
            return $this->error((string) $result['message'], (int) $result['code']);
        }

        return $this->success(['content' => $result['content']], 'success');
    }

    public function otaDiagnosis(): Response
    {
        $this->checkAdmin();

        $hotelId = (int) $this->request->param('hotel_id', 0);
        $platform = strtolower(trim((string) $this->request->param('platform', 'ctrip')));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));
        $analysisType = strtolower(trim((string) $this->request->param('analysis_type', 'traffic')));
        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_chat'));
        if ($modelKey === '') {
            $modelKey = 'deepseek_chat';
        }

        if ($hotelId <= 0) {
            return $this->error('hotel_id 必须大于 0', 422);
        }
        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未识别的 model_key', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 不能晚于 end_date', 422);
        }
        if (!in_array($analysisType, ['traffic', 'business', 'all'], true)) {
            return $this->error('analysis_type 仅支持 traffic、business、all', 422);
        }

        try {
            $rows = $this->queryOtaDiagnosisData($hotelId, $platform, $startDate, $endDate, $analysisType);
            if (empty($rows)) {
                return $this->success([
                    'core_conclusion' => '指定条件下暂无 OTA 数据，未调用大模型。',
                    'main_problems' => [],
                    'possible_reasons' => [],
                    'recommended_actions' => [],
                    'priority' => 'none',
                    'data_anomalies_needing_confirmation' => ['请确认酒店、平台、日期范围和数据类型是否已有采集入库。'],
                ], '暂无 OTA 数据');
            }

            $summary = $this->buildOtaDiagnosisSummary($rows, $hotelId, $platform, $startDate, $endDate, $analysisType);
            $llmResult = $this->callLlm($this->buildOtaDiagnosisPrompt($summary), $modelKey);
            if (($llmResult['ok'] ?? false) !== true) {
                return $this->error((string) $llmResult['message'], (int) $llmResult['code']);
            }

            $diagnosis = $this->parseOtaDiagnosisResult((string) $llmResult['content']);

            return $this->success($diagnosis, 'success');
        } catch (\Throwable $e) {
            return $this->error('OTA 诊断失败: ' . $e->getMessage(), 500);
        }
    }

    private function isDateString(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $time = strtotime($date);
        return $time !== false && date('Y-m-d', $time) === $date;
    }

    private function queryOtaDiagnosisData(int $hotelId, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $columns = $this->onlineDailyDataColumns();
        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'hotel_name',
            'system_hotel_id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'raw_data',
        ], array_keys($columns)));

        $buildQuery = function (string $hotelField, $hotelValue) use ($platform, $startDate, $endDate, $analysisType, $fields, $columns) {
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where($hotelField, $hotelValue)
                ->where('source', $platform)
                ->where('data_date', '>=', $startDate)
                ->where('data_date', '<=', $endDate);

            if (isset($columns['data_type']) && $analysisType === 'traffic') {
                $query->where('data_type', 'traffic');
            } elseif (isset($columns['data_type']) && $analysisType === 'business') {
                $query->whereIn('data_type', ['business', '']);
            }

            return $query->order('data_date', 'asc')->order('id', 'asc');
        };

        $rows = $buildQuery('system_hotel_id', $hotelId)->select()->toArray();
        if (!empty($rows)) {
            return $rows;
        }

        return $buildQuery('hotel_id', (string) $hotelId)->select()->toArray();
    }

    private function onlineDailyDataColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM online_daily_data') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }

        return $columns;
    }

    private function buildOtaDiagnosisSummary(array $rows, int $hotelId, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $summary = [
            'scope' => [
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'analysis_type' => $analysisType,
            ],
            'record_count' => count($rows),
            'date_count' => 0,
            'hotel_names' => [],
            'totals' => [
                'amount' => 0.0,
                'quantity' => 0,
                'book_order_num' => 0,
                'data_value' => 0.0,
                'list_exposure' => 0.0,
                'detail_visitors' => 0.0,
                'order_visitors' => 0.0,
                'submit_users' => 0.0,
            ],
            'averages' => [
                'comment_score' => 0.0,
                'qunar_comment_score' => 0.0,
                'adr' => 0.0,
            ],
            'daily' => [],
            'dimensions' => [],
            'data_anomalies' => [],
        ];

        $commentScores = [];
        $qunarCommentScores = [];
        $invalidRawCount = 0;
        $zeroValueCount = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }

            if (!isset($summary['daily'][$date])) {
                $summary['daily'][$date] = [
                    'date' => $date,
                    'amount' => 0.0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                    'data_value' => 0.0,
                    'list_exposure' => 0.0,
                    'detail_visitors' => 0.0,
                    'order_visitors' => 0.0,
                    'submit_users' => 0.0,
                ];
            }

            $hotelName = trim((string) ($row['hotel_name'] ?? ''));
            if ($hotelName !== '') {
                $summary['hotel_names'][$hotelName] = true;
            }

            $dimension = trim((string) ($row['dimension'] ?? ''));
            $dimensionKey = $dimension !== '' ? $dimension : '未标注维度';
            if (!isset($summary['dimensions'][$dimensionKey])) {
                $summary['dimensions'][$dimensionKey] = ['record_count' => 0, 'data_value' => 0.0];
            }

            $amount = (float) ($row['amount'] ?? 0);
            $quantity = (int) ($row['quantity'] ?? 0);
            $bookOrderNum = (int) ($row['book_order_num'] ?? 0);
            $dataValue = (float) ($row['data_value'] ?? 0);

            $summary['totals']['amount'] += $amount;
            $summary['totals']['quantity'] += $quantity;
            $summary['totals']['book_order_num'] += $bookOrderNum;
            $summary['totals']['data_value'] += $dataValue;
            $summary['daily'][$date]['amount'] += $amount;
            $summary['daily'][$date]['quantity'] += $quantity;
            $summary['daily'][$date]['book_order_num'] += $bookOrderNum;
            $summary['daily'][$date]['data_value'] += $dataValue;
            $summary['dimensions'][$dimensionKey]['record_count']++;
            $summary['dimensions'][$dimensionKey]['data_value'] += $dataValue;

            if ((float) ($row['comment_score'] ?? 0) > 0) {
                $commentScores[] = (float) $row['comment_score'];
            }
            if ((float) ($row['qunar_comment_score'] ?? 0) > 0) {
                $qunarCommentScores[] = (float) $row['qunar_comment_score'];
            }

            $raw = [];
            if (!empty($row['raw_data'])) {
                $decoded = json_decode((string) $row['raw_data'], true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $invalidRawCount++;
                }
            }

            $traffic = $this->extractOtaTrafficMetrics($row, $raw);
            foreach ($traffic as $key => $value) {
                $summary['totals'][$key] += $value;
                $summary['daily'][$date][$key] += $value;
            }

            if ($amount <= 0 && $quantity <= 0 && $bookOrderNum <= 0 && $dataValue <= 0) {
                $zeroValueCount++;
            }
        }

        $summary['date_count'] = count($summary['daily']);
        $summary['hotel_names'] = array_values(array_keys($summary['hotel_names']));
        $summary['daily'] = array_values($summary['daily']);
        $summary['dimensions'] = $this->topDimensionStats($summary['dimensions']);
        $summary['averages']['comment_score'] = $this->average($commentScores);
        $summary['averages']['qunar_comment_score'] = $this->average($qunarCommentScores);
        $summary['averages']['adr'] = $this->percentSafeAverage($summary['totals']['amount'], $summary['totals']['quantity']);
        $summary['derived_rates'] = [
            'detail_rate' => $this->percentRate($summary['totals']['detail_visitors'], $summary['totals']['list_exposure']),
            'order_rate' => $this->percentRate($summary['totals']['order_visitors'], $summary['totals']['detail_visitors']),
            'submit_rate' => $this->percentRate($summary['totals']['submit_users'], $summary['totals']['order_visitors']),
        ];

        $missingDates = $this->missingDates($startDate, $endDate, array_column($summary['daily'], 'date'));
        if (!empty($missingDates)) {
            $summary['data_anomalies'][] = '日期缺失: ' . implode(',', $missingDates);
        }
        if ($invalidRawCount > 0) {
            $summary['data_anomalies'][] = '原始 JSON 解析失败记录数: ' . $invalidRawCount;
        }
        if ($zeroValueCount > 0) {
            $summary['data_anomalies'][] = '全指标为 0 的记录数: ' . $zeroValueCount;
        }

        return $summary;
    }

    private function extractOtaTrafficMetrics(array $row, array $raw): array
    {
        $listExposure = $this->readRowNumber($row, 'list_exposure');
        if ($listExposure === null) {
            $listExposure = $this->readSummaryNumber($raw, ['listExposure', 'list_exposure', 'exposure'], null);
        }
        if ($listExposure === null && ($row['data_type'] ?? '') === 'traffic') {
            $listExposure = (float) ($row['data_value'] ?? 0);
        }

        $detailVisitors = $this->readRowNumber($row, 'detail_exposure');
        if ($detailVisitors === null) {
            $detailVisitors = $this->readSummaryNumber($raw, ['detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors'], 0);
        }

        $orderVisitors = $this->readRowNumber($row, 'order_filling_num');
        if ($orderVisitors === null) {
            $orderVisitors = $this->readSummaryNumber($raw, ['orderFillingNum', 'order_filling_num', 'orderVisitors'], 0);
        }

        $submitUsers = $this->readRowNumber($row, 'order_submit_num');
        if ($submitUsers === null) {
            $submitUsers = $this->readSummaryNumber($raw, ['orderSubmitNum', 'order_submit_num', 'submitUsers'], 0);
        }

        return [
            'list_exposure' => (float) ($listExposure ?? 0),
            'detail_visitors' => (float) ($detailVisitors ?? 0),
            'order_visitors' => (float) ($orderVisitors ?? 0),
            'submit_users' => (float) ($submitUsers ?? 0),
        ];
    }

    private function readRowNumber(array $row, string $key): ?float
    {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (float) $row[$key];
        }
        return null;
    }

    private function readSummaryNumber(array $data, array $keys, ?float $default): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return $default;
    }

    private function topDimensionStats(array $dimensions): array
    {
        uasort($dimensions, function (array $a, array $b): int {
            return $b['data_value'] <=> $a['data_value'];
        });
        return array_slice($dimensions, 0, 10, true);
    }

    private function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function percentRate(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator * 100, 2);
    }

    private function percentSafeAverage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator, 2);
    }

    private function missingDates(string $startDate, string $endDate, array $existingDates): array
    {
        $existing = array_flip($existingDates);
        $missing = [];
        for ($time = strtotime($startDate); $time <= strtotime($endDate); $time += 86400) {
            $date = date('Y-m-d', $time);
            if (!isset($existing[$date])) {
                $missing[] = $date;
            }
        }
        return $missing;
    }

    private function buildOtaDiagnosisPrompt(array $summary): string
    {
        return "你是宿析OS酒店经营分析顾问。只基于以下结构化 OTA 数据摘要输出诊断，不要编造未提供的数据。\n"
            . "必须返回 JSON，字段为 core_conclusion、main_problems、possible_reasons、recommended_actions、priority、data_anomalies_needing_confirmation。\n"
            . "main_problems、possible_reasons、recommended_actions、data_anomalies_needing_confirmation 必须是数组；priority 只能是 high、medium、low。\n"
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function parseOtaDiagnosisResult(string $content): array
    {
        $json = trim($content);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $json, $matches)) {
            $json = trim($matches[1]);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'core_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'main_problems' => [],
                'possible_reasons' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies_needing_confirmation' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
            ];
        }

        return [
            'core_conclusion' => (string) ($data['core_conclusion'] ?? ''),
            'main_problems' => array_values((array) ($data['main_problems'] ?? [])),
            'possible_reasons' => array_values((array) ($data['possible_reasons'] ?? [])),
            'recommended_actions' => array_values((array) ($data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
            'data_anomalies_needing_confirmation' => array_values((array) ($data['data_anomalies_needing_confirmation'] ?? [])),
        ];
    }

    public function feasibilityReportGenerate(): Response
    {
        $this->checkAdmin();

        try {
            $data = $this->request->post();
            $report = $this->feasibilityService()->generate($data, (int) ($this->currentUser->id ?? 0));
            OperationLog::record('agent', 'feasibility_generate', '生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'report_id' => $report['id'] ?? 0,
                'project_name' => $report['project_name'] ?? '',
            ]);

            return $this->success($report, '报告生成成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_generate', '生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportDetail(): Response
    {
        $this->checkAdmin();

        $id = (int) $this->request->param('id', 0);
        $report = $this->feasibilityService()->detail($id);
        if (!$report) {
            return $this->error('报告不存在', 404);
        }

        return $this->success($report);
    }

    public function feasibilityReportRegenerate(): Response
    {
        $this->checkAdmin();

        try {
            $id = (int) $this->request->param('id', 0);
            $report = $this->feasibilityService()->regenerate($id, (int) ($this->currentUser->id ?? 0));
            if (!$report) {
                return $this->error('报告不存在', 404);
            }

            OperationLog::record('agent', 'feasibility_regenerate', '重新生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'source_report_id' => $id,
                'report_id' => $report['id'] ?? 0,
            ]);

            return $this->success($report, '报告重新生成成功');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_regenerate', '重新生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告重新生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportList(): Response
    {
        $this->checkAdmin();

        $pagination = $this->getPagination();
        return $this->success($this->feasibilityService()->list($pagination['page'], $pagination['page_size']));
    }

    // ==================== Agent配置 ====================

    /**
     * 获取Agent配置
     */
    public function getConfig(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        
        $config = AgentConfig::where('hotel_id', $hotelId)
            ->where('agent_type', $agentType)
            ->find();
        
        if (!$config) {
            // 返回默认配置
            $defaultConfigs = [
                AgentConfig::AGENT_TYPE_STAFF => [
                    'auto_reply' => true,
                    'work_order_auto_create' => true,
                    'knowledge_base_enabled' => true,
                    'max_response_time' => 30,
                    'notification_channels' => ['wechat', 'sms'],
                ],
                AgentConfig::AGENT_TYPE_REVENUE => [
                    'price_monitor_interval' => 60,
                    'auto_pricing_enabled' => false,
                    'pricing_strategy' => 'balanced',
                    'min_profit_margin' => 15,
                    'max_price_adjustment' => 20,
                    'notification_channels' => ['wechat'],
                ],
                AgentConfig::AGENT_TYPE_ASSET => [
                    'energy_monitor_enabled' => true,
                    'anomaly_detection_enabled' => true,
                    'maintenance_reminder_days' => 7,
                    'energy_alert_threshold' => 20,
                    'notification_channels' => ['wechat'],
                ],
            ];
            
            return $this->success([
                'agent_type' => $agentType,
                'is_enabled' => false,
                'config_data' => $defaultConfigs[$agentType] ?? [],
            ]);
        }
        
        return $this->success($config);
    }

    /**
     * 保存Agent配置
     */
    public function saveConfig(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'is_enabled' => 'require|integer|in:0,1',
        ]);
        
        $config = AgentConfig::where('hotel_id', $data['hotel_id'])
            ->where('agent_type', $data['agent_type'])
            ->find();
        
        if (!$config) {
            $config = new AgentConfig();
            $config->hotel_id = $data['hotel_id'];
            $config->agent_type = $data['agent_type'];
        }
        
        $config->is_enabled = $data['is_enabled'];
        $config->config_data = $data['config_data'] ?? [];
        $config->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'config_update',
            'Agent配置已更新',
            AgentLog::LEVEL_INFO,
            ['is_enabled' => $data['is_enabled']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '配置保存成功');
    }

    // ==================== 智能员工Agent ====================

    /**
     * 获取知识库列表
     */
    public function knowledgeList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $query = KnowledgeBase::where('hotel_id', $hotelId);
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        if ($keyword) {
            $query->searchKeyword($keyword);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('sort_order', 'asc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 保存知识库条目
     */
    public function saveKnowledge(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        if (!empty($data['id'])) {
            $knowledge = KnowledgeBase::find($data['id']);
            if (!$knowledge) {
                return $this->error('知识库条目不存在');
            }
        } else {
            $knowledge = new KnowledgeBase();
            $knowledge->hotel_id = $data['hotel_id'];
        }
        
        $knowledge->category_id = $data['category_id'] ?? 0;
        $knowledge->title = $data['title'];
        $knowledge->content = $data['content'];
        $knowledge->keywords = $data['keywords'] ?? '';
        $knowledge->tags = $data['tags'] ?? [];
        $knowledge->sort_order = $data['sort_order'] ?? 0;
        $knowledge->is_enabled = $data['is_enabled'] ?? 1;
        $knowledge->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_update',
            '知识库条目已保存: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['knowledge_id' => $knowledge->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $knowledge->id], '保存成功');
    }

    /**
     * 删除知识库条目
     */
    public function deleteKnowledge(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $knowledge = KnowledgeBase::find($id);
        
        if (!$knowledge) {
            return $this->error('知识库条目不存在');
        }
        
        $hotelId = $knowledge->hotel_id;
        $title = $knowledge->title;
        $knowledge->delete();
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_delete',
            '知识库条目已删除: ' . $title,
            AgentLog::LEVEL_WARNING,
            [],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '删除成功');
    }

    /**
     * 获取知识库分类
     */
    public function knowledgeCategories(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $tree = KnowledgeCategory::getTree($hotelId);
        
        return $this->success($tree);
    }

    // ==================== 智能员工Agent - 增强功能 ====================

    /**
     * 获取工单列表
     */
    public function workOrders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $priority = (int) $this->request->param('priority', 0);
        $type = (int) $this->request->param('type', 0);
        
        $query = AgentWorkOrder::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        if ($priority > 0) {
            $query->where('priority', $priority);
        }
        if ($type > 0) {
            $query->where('order_type', $type);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['assignee', 'room'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建工单
     */
    public function createWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        $order = AgentWorkOrder::createOrder($data['hotel_id'], [
            'source_type' => $data['source_type'] ?? AgentWorkOrder::SOURCE_MANUAL,
            'order_type' => $data['order_type'] ?? AgentWorkOrder::TYPE_OTHER,
            'priority' => $data['priority'] ?? AgentWorkOrder::PRIORITY_NORMAL,
            'title' => $data['title'],
            'content' => $data['content'],
            'guest_name' => $data['guest_name'] ?? '',
            'guest_phone' => $data['guest_phone'] ?? '',
            'room_id' => $data['room_id'] ?? 0,
            'room_number' => $data['room_number'] ?? '',
            'emotion_score' => $data['emotion_score'] ?? 0,
            'tags' => $data['tags'] ?? [],
            'created_by' => $this->currentUser->id ?? 0,
            'assigned_to' => $data['assigned_to'] ?? 0,
        ]);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_create',
            '工单已创建: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['order_id' => $order->id, 'priority' => $order->priority],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $order->id], '工单创建成功');
    }

    /**
     * 分配工单
     */
    public function assignWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $userId = (int) $this->request->param('user_id', 0);
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->assign($userId);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_assign',
            '工单已分配给: ' . ($order->assignee->realname ?? '未知'),
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单分配成功');
    }

    /**
     * 解决工单
     */
    public function resolveWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $solution = (string) $this->request->param('solution', '');
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->resolve($solution);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_resolve',
            '工单已解决: ' . $order->title,
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单已解决');
    }

    /**
     * 获取工单统计
     */
    public function workOrderStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $pending = AgentWorkOrder::getPendingStats($hotelId);
        $today = AgentWorkOrder::getTodayStats($hotelId);
        
        return $this->success([
            'pending' => $pending,
            'today' => $today,
        ]);
    }

    /**
     * 获取对话记录
     */
    public function conversations(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $channel = (int) $this->request->param('channel', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $pagination = $this->getPagination();
        $result = AgentConversation::search($hotelId, $keyword, $channel, $pagination['page'], $pagination['page_size']);
        
        return $this->paginate($result['list'], $result['total'], $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取对话统计
     */
    public function conversationStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $days = (int) $this->request->param('days', 7);
        
        $today = AgentConversation::getTodayStats($hotelId);
        $intents = AgentConversation::getIntentStats($hotelId, $days);
        $emotions = AgentConversation::getEmotionStats($hotelId, $days);
        
        return $this->success([
            'today' => $today,
            'intent_distribution' => $intents,
            'emotion_analysis' => $emotions,
        ]);
    }

    /**
     * 获取智能员工Agent综合仪表板
     */
    public function staffDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 工单统计
        $workOrderStats = AgentWorkOrder::getPendingStats($hotelId);
        
        // 对话统计
        $todayConversations = AgentConversation::getTodayStats($hotelId);
        
        // 知识库统计
        $knowledgeStats = [
            'total' => KnowledgeBase::where('hotel_id', $hotelId)->count(),
            'enabled' => KnowledgeBase::where('hotel_id', $hotelId)->where('is_enabled', 1)->count(),
            'hot' => KnowledgeBase::getHotKnowledge($hotelId, 5),
        ];
        
        // 高优先级工单
        $urgentOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->whereIn('status', [AgentWorkOrder::STATUS_PENDING, AgentWorkOrder::STATUS_PROCESSING])
            ->where('priority', '>=', AgentWorkOrder::PRIORITY_HIGH)
            ->order('priority', 'desc')
            ->limit(5)
            ->select();
        
        // 需要转人工的工单
        $needTransferOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->where('status', AgentWorkOrder::STATUS_PENDING)
            ->where('emotion_score', '>=', 0.4)
            ->order('emotion_score', 'desc')
            ->limit(5)
            ->select();
        
        return $this->success([
            'work_orders' => $workOrderStats,
            'conversations' => $todayConversations,
            'knowledge_base' => $knowledgeStats,
            'urgent_orders' => $urgentOrders,
            'need_transfer_orders' => $needTransferOrders,
        ]);
    }

    // ==================== 收益管理Agent - 增强功能 ====================

    /**
     * 获取需求预测
     */
    public function demandForecasts(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d'));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d', strtotime('+30 days')));
        
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        
        // 获取准确率统计
        $accuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        return $this->success([
            'forecasts' => $forecasts,
            'accuracy' => $accuracy,
            'high_demand_dates' => DemandForecast::getHighDemandDates($hotelId, 80),
        ]);
    }

    /**
     * 创建需求预测
     */
    public function createForecast(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'forecast_date' => 'require|date',
            'room_type_id' => 'require|integer',
            'predicted_occupancy' => 'require|float',
        ]);
        
        $forecast = DemandForecast::createForecast($data['hotel_id'], $data['forecast_date'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_create',
            '需求预测已创建: ' . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            ['forecast_id' => $forecast->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $forecast->id], '预测创建成功');
    }

    /**
     * 获取竞对分析
     */
    public function competitorAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        // 获取价格矩阵
        $priceMatrix = CompetitorAnalysis::getPriceMatrix($hotelId, $date);
        
        // 获取价格波动预警
        $alerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 20);
        
        // 获取价格趋势
        $competitors = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->group('competitor_hotel_id')
            ->column('competitor_hotel_id');
        
        $trends = [];
        foreach ($competitors as $competitorId) {
            $trends[$competitorId] = CompetitorAnalysis::getPriceTrend($hotelId, $competitorId);
        }
        
        return $this->success([
            'price_matrix' => $priceMatrix,
            'alerts' => $alerts,
            'trends' => $trends,
            'date' => $date,
        ]);
    }

    /**
     * 记录竞对价格
     */
    public function recordCompetitorPrice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'competitor_hotel_id' => 'require|integer',
            'our_price' => 'require|float',
            'competitor_price' => 'require|float',
        ]);
        
        $analysis = CompetitorAnalysis::recordAnalysis(
            $data['hotel_id'],
            $data['competitor_hotel_id'],
            $data
        );
        
        return $this->success(['id' => $analysis->id], '记录成功');
    }

    /**
     * 获取定价建议列表
     */
    public function priceSuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        $query = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 审批定价建议
     */
    public function approvePrice(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', 'approve'); // approve/reject
        $remark = (string) $this->request->param('remark', '');
        
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('定价建议不存在');
        }
        
        if ($action === 'approve') {
            $suggestion->approve($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已批准';
        } else {
            $suggestion->reject($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已拒绝';
        }
        
        // 记录日志
        AgentLog::record(
            $suggestion->hotel_id,
            AgentLog::AGENT_TYPE_REVENUE,
            'price_' . $action,
            $message . ': ' . $suggestion->room_type_name,
            AgentLog::LEVEL_INFO,
            ['suggestion_id' => $id, 'suggested_price' => $suggestion->suggested_price],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, $message);
    }

    /**
     * 获取收益分析数据（增强版 - 含RevPAR分析）
     */
    public function revenueAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        
        // 获取需求预测统计
        $forecastStats = DemandForecast::getAccuracyStats($hotelId, 30);
        $highDemandDates = DemandForecast::getHighDemandDates($hotelId, 80);
        
        // 计算RevPAR趋势（基于预测和历史数据）
        $revparTrend = [];
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        foreach ($forecasts as $forecast) {
            $revparTrend[] = [
                'date' => $forecast->forecast_date,
                'predicted_revpar' => $forecast->predicted_revpar,
                'predicted_occupancy' => $forecast->predicted_occupancy,
                'confidence' => $forecast->confidence_score,
            ];
        }
        
        // 获取定价策略建议
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates);
        
        return $this->success([
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 生成定价策略建议
     */
    private function generatePricingStrategies(int $hotelId, array $highDemandDates): array
    {
        $strategies = [];
        
        if (count($highDemandDates) > 0) {
            $strategies[] = [
                'type' => 'high_demand',
                'title' => '高需求日期动态提价',
                'description' => '检测到 ' . count($highDemandDates) . ' 个高需求日期，建议在这些日期实施动态溢价策略',
                'suggested_action' => '在高需求日期将基础房价提高10-20%',
                'expected_impact' => '预计RevPAR提升 8-15%',
            ];
        }
        
        // 检查竞对价格差距
        $recentAnalysis = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->where('analysis_date', date('Y-m-d'))
            ->select();
        
        $higherCount = 0;
        $lowerCount = 0;
        foreach ($recentAnalysis as $item) {
            if ($item->price_difference > 0) {
                $higherCount++;
            } elseif ($item->price_difference < 0) {
                $lowerCount++;
            }
        }
        
        if ($higherCount > $lowerCount) {
            $strategies[] = [
                'type' => 'competitor_price',
                'title' => '竞对价格跟进',
                'description' => '我方价格高于竞对的情况较多，可能导致客源流失',
                'suggested_action' => '针对部分房型适当降价，保持价格竞争力',
                'expected_impact' => '预计提升入住率 3-5%',
            ];
        }
        
        return $strategies;
    }

    /**
     * 获取收益管理Agent综合仪表板
     */
    public function revenueDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 今日定价建议
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', date('Y-m-d'))
            ->with('roomType')
            ->select();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15);
        
        // 本周RevPAR预测
        $weekForecasts = DemandForecast::getForecastRange(
            $hotelId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+7 days'))
        );
        
        $avgPredictedRevpar = 0;
        if (count($weekForecasts) > 0) {
            $totalRevpar = array_sum(array_column($weekForecasts->toArray(), 'predicted_revpar'));
            $avgPredictedRevpar = round($totalRevpar / count($weekForecasts), 2);
        }
        
        return $this->success([
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'high_demand_count' => count(DemandForecast::getHighDemandDates($hotelId, 80)),
        ]);
    }

    // ==================== 资产运维Agent - 增强功能 ====================

    /**
     * 获取能耗数据
     */
    public function energyData(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取趋势数据
        $trend = [];
        if ($energyType > 0) {
            $trend = EnergyConsumption::getTrend($hotelId, $energyType, $startDate, $endDate);
        }
        
        // 获取今日数据
        $todayData = [];
        $types = [
            EnergyConsumption::TYPE_ELECTRICITY,
            EnergyConsumption::TYPE_WATER,
            EnergyConsumption::TYPE_GAS,
        ];
        foreach ($types as $type) {
            $todayData[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 获取异常记录
        $anomalies = EnergyConsumption::getAnomalies($hotelId, $startDate, $endDate, 10);
        
        // 获取能耗基准对比
        $benchmarkComparison = EnergyBenchmark::getComparisonReport($hotelId, date('Y-m-d'));
        
        return $this->success([
            'trend' => $trend,
            'today' => $todayData,
            'anomalies' => $anomalies,
            'benchmark_comparison' => $benchmarkComparison,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 获取能耗基准列表
     */
    public function energyBenchmarks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        
        $query = EnergyBenchmark::where('hotel_id', $hotelId);
        
        if ($energyType > 0) {
            $query->where('energy_type', $energyType);
        }
        
        $list = $query->with('device')
            ->where('is_active', 1)
            ->order('id', 'desc')
            ->select();
        
        return $this->success($list);
    }

    /**
     * 设置能耗基准
     */
    public function saveEnergyBenchmark(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'energy_type' => 'require|integer',
            'benchmark_value' => 'require|float',
        ]);
        
        $benchmark = EnergyBenchmark::setBenchmark($data['hotel_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'benchmark_update',
            '能耗基准已更新: ' . $benchmark->energy_type_name,
            AgentLog::LEVEL_INFO,
            ['benchmark_id' => $benchmark->id, 'value' => $benchmark->benchmark_value],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $benchmark->id], '基准设置成功');
    }

    /**
     * 自动计算基准
     */
    public function autoCalculateBenchmark(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $days = (int) $this->request->param('days', 30);
        
        $benchmark = EnergyBenchmark::autoCalculateBenchmark($hotelId, $energyType, $days);
        
        return $this->success(['benchmark_value' => $benchmark], '计算完成');
    }

    /**
     * 获取节能建议
     */
    public function energySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = EnergySavingSuggestion::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('implementer')
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 生成节能建议
     */
    public function generateEnergySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $suggestions = EnergySavingSuggestion::autoGenerate($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'suggestion_generate',
            '自动生成 ' . count($suggestions) . ' 条节能建议',
            AgentLog::LEVEL_INFO,
            ['count' => count($suggestions)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($suggestions), 'suggestions' => $suggestions], '生成成功');
    }

    /**
     * 更新节能建议状态
     */
    public function updateEnergySuggestion(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', '');
        
        $suggestion = EnergySavingSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('建议不存在');
        }
        
        switch ($action) {
            case 'approve':
                $suggestion->approve();
                $message = '建议已批准';
                break;
            case 'start':
                $suggestion->startImplementation($this->currentUser->id ?? 0);
                $message = '开始实施';
                break;
            case 'complete':
                $actualSaving = (float) $this->request->param('actual_saving', 0);
                $suggestion->complete($actualSaving);
                $message = '实施完成';
                break;
            default:
                return $this->error('未知操作');
        }
        
        return $this->success(null, $message);
    }

    /**
     * 获取维护计划
     */
    public function maintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $deviceId = (int) $this->request->param('device_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = MaintenancePlan::where('hotel_id', $hotelId);
        
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['device', 'category'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建设备维护计划
     */
    public function createMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'device_id' => 'require|integer',
            'plan_name' => 'require|max:200',
        ]);
        
        $plan = MaintenancePlan::createForDevice($data['hotel_id'], $data['device_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'plan_create',
            '维护计划已创建: ' . $data['plan_name'],
            AgentLog::LEVEL_INFO,
            ['plan_id' => $plan->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $plan->id], '计划创建成功');
    }

    /**
     * 执行维护计划
     */
    public function executeMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $result = (string) $this->request->param('result', '');
        $actualCost = (float) $this->request->param('actual_cost', 0);
        
        $plan = MaintenancePlan::find($id);
        if (!$plan) {
            return $this->error('计划不存在');
        }
        
        $maintenance = $plan->execute(date('Y-m-d'), $this->currentUser->id ?? 0, $result, $actualCost);
        
        return $this->success(['maintenance_id' => $maintenance->id], '维护记录已创建');
    }

    /**
     * 获取维护提醒
     */
    public function maintenanceReminders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $upcoming = MaintenancePlan::getUpcomingPlans($hotelId, 7);
        $overdue = MaintenancePlan::getOverduePlans($hotelId);
        
        return $this->success([
            'upcoming' => $upcoming,
            'overdue' => $overdue,
        ]);
    }

    /**
     * 自动生成默认维护计划
     */
    public function autoGenerateMaintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $plans = MaintenancePlan::autoGenerateDefaultPlans($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'plan_auto_generate',
            '自动生成 ' . count($plans) . ' 个维护计划',
            AgentLog::LEVEL_INFO,
            ['count' => count($plans)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($plans)], '生成成功');
    }

    /**
     * 获取资产运维Agent综合仪表板
     */
    public function assetDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $deviceStats = Device::getStatistics($hotelId);
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 能耗统计
        $todayEnergy = [];
        foreach ([EnergyConsumption::TYPE_ELECTRICITY, EnergyConsumption::TYPE_WATER, EnergyConsumption::TYPE_GAS] as $type) {
            $todayEnergy[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 维护统计
        $maintenanceStats = MaintenancePlan::getExecutionStats($hotelId);
        
        // 节能建议统计
        $savingStats = EnergySavingSuggestion::getImplementationStats($hotelId);
        $highPrioritySuggestions = EnergySavingSuggestion::getHighPriority($hotelId, 5);
        
        // 异常告警
        $anomalies = EnergyConsumption::getAnomalies($hotelId, date('Y-m-d', strtotime('-7 days')), date('Y-m-d'), 5);
        
        return $this->success([
            'devices' => array_merge($deviceStats, ['faulty' => $faultyDevices]),
            'energy' => $todayEnergy,
            'maintenance' => $maintenanceStats,
            'saving_suggestions' => array_merge($savingStats, ['high_priority' => $highPrioritySuggestions]),
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * 获取设备列表
     */
    public function deviceList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        
        $query = Device::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取设备统计
     */
    public function deviceStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $stats = Device::getStatistics($hotelId);
        
        // 待维护设备
        $pendingMaintenance = Device::getPendingMaintenance($hotelId);
        
        // 故障设备
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 今日维护任务
        $todayTasks = DeviceMaintenance::getTodayTasks($hotelId);
        
        return $this->success([
            'statistics' => $stats,
            'pending_maintenance' => $pendingMaintenance,
            'faulty_devices' => $faultyDevices,
            'today_tasks' => $todayTasks,
        ]);
    }

    /**
     * 创建设备
     */
    public function saveDevice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'name' => 'require|max:100',
            'category_id' => 'require|integer',
        ]);
        
        if (!empty($data['id'])) {
            $device = Device::find($data['id']);
            if (!$device) {
                return $this->error('设备不存在');
            }
        } else {
            $device = new Device();
            $device->hotel_id = $data['hotel_id'];
            $device->status = Device::STATUS_NORMAL;
        }
        
        $device->name = $data['name'];
        $device->category_id = $data['category_id'];
        $device->model = $data['model'] ?? '';
        $device->location = $data['location'] ?? '';
        $device->install_date = $data['install_date'] ?? null;
        $device->warranty_expire = $data['warranty_expire'] ?? null;
        $device->maintenance_cycle = $data['maintenance_cycle'] ?? 90;
        $device->purchase_cost = $data['purchase_cost'] ?? 0;
        $device->is_monitored = $data['is_monitored'] ?? 1;
        $device->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'device_update',
            '设备已保存: ' . $data['name'],
            AgentLog::LEVEL_INFO,
            ['device_id' => $device->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $device->id], '保存成功');
    }

    // ==================== Agent日志 ====================

    /**
     * 获取Agent日志
     */
    public function logs(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $logLevel = (int) $this->request->param('log_level', 0);
        
        $query = AgentLog::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($logLevel > 0) {
            $query->where('log_level', $logLevel);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('user')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取Agent任务
     */
    public function tasks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = AgentTask::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建Agent任务
     */
    public function createTask(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'task_type' => 'require|integer',
            'task_name' => 'require|max:200',
        ]);
        
        $task = AgentTask::createTask(
            $data['hotel_id'],
            $data['agent_type'],
            $data['task_type'],
            $data['task_name'],
            $data['params'] ?? [],
            $data['priority'] ?? AgentTask::PRIORITY_NORMAL
        );
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'task_create',
            'Agent任务已创建: ' . $data['task_name'],
            AgentLog::LEVEL_INFO,
            ['task_id' => $task->id, 'task_type' => $data['task_type']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $task->id], '任务创建成功');
    }
}
