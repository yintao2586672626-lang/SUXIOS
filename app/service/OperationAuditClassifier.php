<?php
declare(strict_types=1);

namespace app\service;

class OperationAuditClassifier
{
    private const AUDITED_PREFIXES = [
        'api/admin/competitor-price-logs' => ['module' => 'competitor', 'label' => '竞对价格'],
        'api/admin/competitor-devices' => ['module' => 'competitor', 'label' => '竞对设备'],
        'api/online-data' => ['module' => 'online_data', 'label' => '线上数据'],
        'api/agent' => ['module' => 'agent', 'label' => 'AI工具箱'],
        'api/ai' => ['module' => 'ai', 'label' => 'AI决策'],
        'api/operation' => ['module' => 'operation', 'label' => '运营管理'],
        'api/macro-signals' => ['module' => 'macro_signals', 'label' => '宏观经营信号'],
        'api/lifecycle' => ['module' => 'lifecycle', 'label' => '全生命周期服务'],
        'api/strategy' => ['module' => 'strategy', 'label' => '策略推演'],
        'api/simulation' => ['module' => 'simulation', 'label' => '量化模拟'],
        'api/expansion' => ['module' => 'expansion', 'label' => '扩张管理'],
        'api/transfer' => ['module' => 'transfer', 'label' => '转让决策'],
        'api/opening' => ['module' => 'opening', 'label' => '开业管理'],
    ];

    private const EXCLUDED_PREFIXES = [
        'api/auth',
        'api/health',
        'api/operation-logs',
    ];

    private const MANUAL_LOGGED_PATHS = [
        'api/online-data/fetch-ctrip',
        'api/online-data/fetch-meituan',
        'api/online-data/fetch-ctrip-traffic',
        'api/online-data/ctrip/traffic',
        'api/online-data/fetch-meituan-traffic',
        'api/online-data/fetch-meituan-comments',
        'api/online-data/fetch-ctrip-comments',
        'api/online-data/fetch-custom',
        'api/online-data/auto-fetch',
        'api/online-data/retry-auto-fetch',
        'api/online-data/ai-analysis',
        'api/agent/analyze-captured-ota-data',
        'api/agent/summarize-captured-ota-analysis',
        'api/agent/feasibility-report/generate',
    ];

    private const MANUAL_LOGGED_PREFIXES = [
        'api/agent/feasibility-report/regenerate',
    ];

    private const ANALYSIS_KEYWORDS = [
        'analysis',
        'analyze',
        'forecast',
        'strategy',
        'simulate',
        'simulation',
        'calculate',
        'evaluation',
        'benchmark',
        'pricing',
        'timing',
        'dashboard',
        'overview',
        'trends',
        'root-cause',
        'feasibility',
        'revenue',
        'diagnosis',
        'report',
        'generate',
        'recalculate',
    ];

    private const SEGMENT_LABELS = [
        'data-analysis' => '数据分析',
        'daily-data-list' => '线上数据列表',
        'daily-data-summary' => '线上数据汇总',
        'history' => '历史数据',
        'latest' => '最新数据',
        'cookie-status' => 'Cookie状态',
        'full-data' => '运营全量数据',
        'root-cause' => '根因分析',
        'strategy-simulation' => '策略模拟',
        'market-evaluation' => '市场评估',
        'benchmark-model' => '标杆模型',
        'collaboration-efficiency' => '协同效率',
        'pricing' => '转让定价',
        'timing' => '转让时机',
        'dashboard' => '看板数据',
        'overview' => '概览数据',
        'detail' => '详情数据',
        'trends' => '趋势数据',
        'external' => '外部数据',
        'simulate' => '策略推演',
        'calculate' => '量化测算',
        'records' => '记录列表',
        'source' => '真实数据源',
        'revenue-analysis' => '收益分析',
        'competitor-analysis' => '竞对分析',
        'demand-forecasts' => '需求预测',
        'price-suggestions' => '定价建议',
        'revenue-dashboard' => '收益看板',
        'feasibility-report' => '可行性报告',
        'generate-tasks' => '生成任务',
        'competitor-price-logs' => '竞对价格日志',
        'competitor-devices' => '竞对设备',
    ];

    public function classify(string $method, string $uri): ?array
    {
        $path = $this->normalizePath($uri);
        if ($path === '') {
            return null;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($this->pathMatchesPrefix($path, $prefix)) {
                return null;
            }
        }

        $method = strtoupper($method);
        if ($method === 'POST' && $this->isManualLoggedPath($path)) {
            return null;
        }

        foreach (self::AUDITED_PREFIXES as $prefix => $meta) {
            if (!$this->pathMatchesPrefix($path, $prefix)) {
                continue;
            }

            $action = $this->resolveAction($method, $path);
            if ($action === null) {
                return null;
            }

            $label = $this->buildLabel($path, $prefix, $meta['label']);
            $category = $action === 'analyze_data' ? 'analysis' : 'acquisition';

            return [
                'module' => $meta['module'],
                'action' => $action,
                'category' => $category,
                'path' => $path,
                'description' => ($category === 'analysis' ? '生成/查看分析: ' : '获取/查看数据: ') . $label,
            ];
        }

        return null;
    }

    private function resolveAction(string $method, string $path): ?string
    {
        if ($this->containsAnalysisKeyword($path)) {
            return 'analyze_data';
        }

        if ($method === 'GET') {
            return 'view_data';
        }

        return null;
    }

    private function normalizePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $uri;
        }

        $path = trim($path);
        $path = trim($path, '/');

        return strtolower($path);
    }

    private function pathMatchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private function isManualLoggedPath(string $path): bool
    {
        if (in_array($path, self::MANUAL_LOGGED_PATHS, true)) {
            return true;
        }

        foreach (self::MANUAL_LOGGED_PREFIXES as $prefix) {
            if ($this->pathMatchesPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsAnalysisKeyword(string $path): bool
    {
        foreach (self::ANALYSIS_KEYWORDS as $keyword) {
            if (str_contains($path, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function buildLabel(string $path, string $prefix, string $moduleLabel): string
    {
        $relative = trim(substr($path, strlen($prefix)), '/');
        if ($relative === '') {
            return $moduleLabel;
        }

        $segments = array_values(array_filter(explode('/', $relative), static function (string $segment): bool {
            return $segment !== '' && !ctype_digit($segment);
        }));

        if (empty($segments)) {
            return $moduleLabel;
        }

        $labels = array_map([$this, 'segmentLabel'], $segments);
        return $moduleLabel . ' / ' . implode(' / ', $labels);
    }

    private function segmentLabel(string $segment): string
    {
        return self::SEGMENT_LABELS[$segment] ?? str_replace('-', '_', $segment);
    }
}
