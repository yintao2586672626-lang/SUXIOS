<?php
declare(strict_types=1);

namespace app\service;

use app\model\FeasibilityReport;
use think\facade\Db;

class FeasibilityReportService
{
    private OpenAIClient $client;

    public function __construct(?OpenAIClient $client = null)
    {
        $this->client = $client ?: new OpenAIClient();
    }

    public function generate(array $input, int $userId): array
    {
        $this->ensureTable();
        $input = $this->normalizeInput($input);
        $snapshot = $this->buildSnapshot($input);
        $calculation = $this->calculate($input, $snapshot);
        $report = $this->buildAiReport($input, $snapshot, $calculation);
        $report = $this->mergeFinancials($report, $input, $calculation);

        $record = FeasibilityReport::create([
            'project_name' => $input['project_name'],
            'input_json' => $input,
            'snapshot_json' => $snapshot,
            'report_json' => $report,
            'conclusion_grade' => $report['conclusion_grade'] ?? 'C',
            'payback_months' => $report['summary']['payback_months'] ?? null,
            'total_investment' => $report['summary']['total_investment'] ?? 0,
            'created_by' => $userId,
        ]);

        return $this->formatRecord($record);
    }

    public function regenerate(int $id, int $userId): ?array
    {
        $this->ensureTable();
        $old = FeasibilityReport::where('id', $id)->whereNull('deleted_at')->find();
        if (!$old) {
            return null;
        }

        return $this->generate((array) $old->input_json, $userId);
    }

    public function detail(int $id): ?array
    {
        $this->ensureTable();
        $record = FeasibilityReport::where('id', $id)->whereNull('deleted_at')->find();
        return $record ? $this->formatRecord($record) : null;
    }

    public function list(int $page = 1, int $pageSize = 10): array
    {
        $this->ensureTable();
        $query = FeasibilityReport::whereNull('deleted_at')->order('id', 'desc');
        $total = (clone $query)->count();
        $list = $query->page($page, $pageSize)->select()->toArray();

        return [
            'list' => array_map(fn ($row) => $this->formatArrayRecord($row, false), $list),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => (int) ceil($total / max(1, $pageSize)),
            ],
        ];
    }

    public function ensureTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS feasibility_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_name VARCHAR(120) NOT NULL,
                input_json JSON NULL,
                snapshot_json JSON NULL,
                report_json JSON NULL,
                conclusion_grade VARCHAR(8),
                payback_months DECIMAL(10,2) NULL,
                total_investment DECIMAL(14,2) DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL,
                INDEX idx_created_by (created_by),
                INDEX idx_grade (conclusion_grade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function normalizeInput(array $input): array
    {
        $fields = [
            'project_name', 'city', 'district', 'address', 'target_brand_level',
            'target_customer', 'notes',
        ];
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = trim((string) ($input[$field] ?? ''));
        }

        $numberFields = [
            'property_area', 'room_count', 'monthly_rent', 'lease_years',
            'decoration_budget', 'transfer_fee', 'opening_cost', 'adr', 'occ',
        ];
        foreach ($numberFields as $field) {
            $result[$field] = round((float) ($input[$field] ?? 0), 2);
        }

        if ($result['project_name'] === '') {
            throw new \InvalidArgumentException('项目名称不能为空');
        }
        if ($result['room_count'] <= 0) {
            throw new \InvalidArgumentException('房间数必须大于 0');
        }
        if ($result['property_area'] <= 0) {
            throw new \InvalidArgumentException('物业面积必须大于 0');
        }

        return $result;
    }

    private function buildSnapshot(array $input): array
    {
        $daily = $this->safeRows('daily_reports', fn () => Db::name('daily_reports')->order('report_date', 'desc')->limit(30)->select()->toArray());
        $online = $this->safeRows('online_daily_data', fn () => Db::name('online_daily_data')->order('id', 'desc')->limit(30)->select()->toArray());
        $competitors = $this->safeRows('competitor_hotel', fn () => Db::name('competitor_hotel')->where('status', 1)->limit(20)->select()->toArray());
        $prices = $this->safeRows('competitor_price_log', fn () => Db::name('competitor_price_log')->order('id', 'desc')->limit(50)->select()->toArray());

        return [
            'daily_summary' => $this->summarizeDailyReports($daily),
            'online_summary' => $this->summarizeOnlineData($online),
            'competitor_summary' => $this->summarizeCompetitors($competitors, $prices),
            'source_counts' => [
                'daily_reports' => count($daily),
                'online_daily_data' => count($online),
                'competitor_hotels' => count($competitors),
                'competitor_price_logs' => count($prices),
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function summarizeDailyReports(array $rows): array
    {
        $adr = [];
        $occ = [];
        $revenue = [];
        foreach ($rows as $row) {
            $data = is_array($row['report_data'] ?? null) ? $row['report_data'] : json_decode((string) ($row['report_data'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            foreach (['adr', 'avg_room_price', 'day_adr'] as $key) {
                if (!empty($data[$key])) {
                    $adr[] = (float) $data[$key];
                    break;
                }
            }
            foreach (['occ', 'occupancy_rate', 'day_occ_rate'] as $key) {
                if (!empty($data[$key])) {
                    $value = (float) $data[$key];
                    $occ[] = $value > 1 ? $value / 100 : $value;
                    break;
                }
            }
            foreach (['day_revenue', 'revenue', 'room_revenue'] as $key) {
                if (!empty($data[$key])) {
                    $revenue[] = (float) $data[$key];
                    break;
                }
            }
        }

        return [
            'avg_adr' => $this->avg($adr),
            'avg_occ' => $this->avg($occ),
            'avg_revenue' => $this->avg($revenue),
        ];
    }

    private function safeRows(string $table, callable $reader): array
    {
        try {
            return $reader();
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), $table) !== false || stripos($e->getMessage(), 'Base table') !== false) {
                return [];
            }
            throw $e;
        }
    }

    private function summarizeOnlineData(array $rows): array
    {
        return [
            'sample_count' => count($rows),
            'has_real_ota_data' => count($rows) > 0,
        ];
    }

    private function summarizeCompetitors(array $hotels, array $prices): array
    {
        $priceValues = array_values(array_filter(array_map(fn ($row) => (float) ($row['price'] ?? 0), $prices), fn ($v) => $v > 0));
        return [
            'competitor_count' => count($hotels),
            'avg_competitor_price' => $this->avg($priceValues),
        ];
    }

    private function calculate(array $input, array $snapshot): array
    {
        $assumptions = [];
        $roomCount = max(1, (float) $input['room_count']);
        $areaPerRoom = round((float) $input['property_area'] / $roomCount, 2);
        $openingCost = (float) ($input['opening_cost'] ?: round($roomCount * 2500));
        if (empty($input['opening_cost'])) {
            $assumptions[] = '开办费按 2500 元/间估算。';
        }

        $baseAdr = (float) $input['adr'];
        if ($baseAdr <= 0) {
            $baseAdr = (float) ($snapshot['daily_summary']['avg_adr'] ?: $snapshot['competitor_summary']['avg_competitor_price'] ?: 260);
            $assumptions[] = '未输入 ADR，使用系统历史/竞对数据估算，若无样本则按 260 元估算。';
        }

        $baseOcc = (float) $input['occ'];
        if ($baseOcc <= 0) {
            $baseOcc = (float) ($snapshot['daily_summary']['avg_occ'] ?: 0.72);
            $assumptions[] = '未输入 OCC，使用系统历史入住率估算，若无样本则按 72% 估算。';
        } elseif ($baseOcc > 1) {
            $baseOcc = $baseOcc / 100;
        }

        $totalInvestment = (float) $input['decoration_budget'] + (float) $input['transfer_fee'] + $openingCost;
        $scenarios = [
            $this->scenario('保守情景', $roomCount, $baseAdr * 0.9, max(0.35, $baseOcc - 0.10), $input, $totalInvestment),
            $this->scenario('基准情景', $roomCount, $baseAdr, $baseOcc, $input, $totalInvestment),
            $this->scenario('乐观情景', $roomCount, $baseAdr * 1.08, min(0.95, $baseOcc + 0.08), $input, $totalInvestment),
        ];

        return [
            'area_per_room' => $areaPerRoom,
            'opening_cost' => $openingCost,
            'total_investment' => round($totalInvestment, 2),
            'scenarios' => $scenarios,
            'assumptions' => $assumptions,
        ];
    }

    private function scenario(string $name, float $roomCount, float $adr, float $occ, array $input, float $totalInvestment): array
    {
        $revpar = $adr * $occ;
        $monthlyRevenue = $roomCount * 30 * $revpar;
        $variableCost = $monthlyRevenue * 0.18;
        $fixedCost = (float) $input['monthly_rent'] + $roomCount * 900 + $roomCount * 260 + 18000;
        $monthlyCost = $fixedCost + $variableCost;
        $net = $monthlyRevenue - $monthlyCost;
        $payback = $net > 0 ? $totalInvestment / $net : null;
        $rentRatio = $monthlyRevenue > 0 ? (float) $input['monthly_rent'] / $monthlyRevenue : 0;

        return [
            'name' => $name,
            'adr' => round($adr, 2),
            'occ' => round($occ, 4),
            'revpar' => round($revpar, 2),
            'monthly_revenue' => round($monthlyRevenue, 2),
            'monthly_operating_cost' => round($monthlyCost, 2),
            'monthly_net_cashflow' => round($net, 2),
            'payback_months' => $payback === null ? null : round($payback, 1),
            'rent_ratio' => round($rentRatio, 4),
            'risk_level' => $this->scenarioRisk($net, $payback, $rentRatio),
        ];
    }

    private function scenarioRisk(float $net, ?float $payback, float $rentRatio): string
    {
        if ($net <= 0 || $payback === null || $rentRatio >= 0.42) {
            return '高';
        }
        if ($payback > 42 || $rentRatio >= 0.32) {
            return '中';
        }
        return '低';
    }

    private function buildAiReport(array $input, array $snapshot, array $calculation): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店投资可行性分析师。只输出符合 schema 的 JSON。财务数字必须采用用户输入和本地测算，不要编造来源；无来源的数据写入 assumptions；风险评级保持保守。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'user_input' => $input,
                    'system_snapshot' => $snapshot,
                    'deterministic_calculation' => $calculation,
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        return $this->client->createJsonResponse($messages, $this->schema());
    }

    private function mergeFinancials(array $report, array $input, array $calculation): array
    {
        $base = $calculation['scenarios'][1];
        $report['summary'] = array_merge($report['summary'] ?? [], [
            'project_name' => $input['project_name'],
            'location' => trim($input['city'] . ' ' . $input['district'] . ' ' . $input['address']),
            'room_count' => (int) $input['room_count'],
            'total_investment' => $calculation['total_investment'],
            'payback_months' => $base['payback_months'] ?? 0,
        ]);
        $report['financial_scenarios'] = $calculation['scenarios'];
        $report['assumptions'] = array_values(array_unique(array_merge($calculation['assumptions'], $report['assumptions'] ?? [])));
        $report['basic_info'] = [
            ['label' => '项目名称', 'value' => $input['project_name'], 'source' => '用户输入'],
            ['label' => '城市区域', 'value' => trim($input['city'] . ' ' . $input['district']), 'source' => '用户输入'],
            ['label' => '物业面积', 'value' => $input['property_area'] . '㎡', 'source' => '用户输入'],
            ['label' => '计划房量', 'value' => $input['room_count'] . '间', 'source' => '用户输入'],
            ['label' => '单房面积', 'value' => $calculation['area_per_room'] . '㎡/间', 'source' => '本地测算'],
            ['label' => '总投资', 'value' => $calculation['total_investment'], 'source' => '本地测算'],
        ];

        return $report;
    }

    private function formatRecord(FeasibilityReport $record): array
    {
        return $this->formatArrayRecord($record->toArray(), true);
    }

    private function formatArrayRecord(array $row, bool $full): array
    {
        $data = [
            'id' => (int) $row['id'],
            'project_name' => $row['project_name'],
            'conclusion_grade' => $row['conclusion_grade'] ?? '',
            'payback_months' => $row['payback_months'] ?? null,
            'total_investment' => $row['total_investment'] ?? 0,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        if ($full) {
            $data['input'] = $row['input_json'] ?? [];
            $data['snapshot'] = $row['snapshot_json'] ?? [];
            $data['report'] = $row['report_json'] ?? [];
        }
        return $data;
    }

    private function avg(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => is_numeric($value) && (float) $value > 0));
        return $values ? round(array_sum($values) / count($values), 2) : 0.0;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['conclusion_grade', 'conclusion_text', 'core_reason', 'summary', 'basic_info', 'market_judgement', 'financial_scenarios', 'risk_list', 'action_plan', 'assumptions', 'evidence'],
            'properties' => [
                'conclusion_grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                'conclusion_text' => ['type' => 'string'],
                'core_reason' => ['type' => 'string'],
                'summary' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['project_name', 'location', 'room_count', 'total_investment', 'payback_months'],
                    'properties' => [
                        'project_name' => ['type' => 'string'],
                        'location' => ['type' => 'string'],
                        'room_count' => ['type' => 'number'],
                        'total_investment' => ['type' => 'number'],
                        'payback_months' => ['type' => 'number'],
                    ],
                ],
                'basic_info' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'value', 'source'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => ['string', 'number']],
                            'source' => ['type' => 'string'],
                        ],
                    ],
                ],
                'market_judgement' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['market_score', 'competition_level', 'recommended_model', 'target_customer', 'reasoning'],
                    'properties' => [
                        'market_score' => ['type' => 'number'],
                        'competition_level' => ['type' => 'string'],
                        'recommended_model' => ['type' => 'string'],
                        'target_customer' => ['type' => 'string'],
                        'reasoning' => ['type' => 'string'],
                    ],
                ],
                'financial_scenarios' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'adr', 'occ', 'monthly_revenue', 'monthly_net_cashflow', 'payback_months', 'rent_ratio', 'risk_level'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'adr' => ['type' => 'number'],
                            'occ' => ['type' => 'number'],
                            'monthly_revenue' => ['type' => 'number'],
                            'monthly_net_cashflow' => ['type' => 'number'],
                            'payback_months' => ['type' => ['number', 'null']],
                            'rent_ratio' => ['type' => 'number'],
                            'risk_level' => ['type' => 'string'],
                        ],
                    ],
                ],
                'risk_list' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['risk', 'level', 'reason', 'action'],
                        'properties' => [
                            'risk' => ['type' => 'string'],
                            'level' => ['type' => 'string', 'enum' => ['高', '中', '低']],
                            'reason' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                    ],
                ],
                'action_plan' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'priority', 'detail'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['source', 'title', 'url', 'summary'],
                        'properties' => [
                            'source' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
