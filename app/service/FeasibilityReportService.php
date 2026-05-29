<?php
declare(strict_types=1);

namespace app\service;

use app\model\FeasibilityReport;
use think\facade\Db;

class FeasibilityReportService
{
    private LlmClient $client;

    public function __construct(?LlmClient $client = null)
    {
        $this->client = $client ?: new LlmClient();
    }

    public function generate(array $input, int $userId): array
    {
        $this->ensureTable();
        $input = $this->normalizeInput($input);
        $tenantId = $this->tenantIdForUser($userId);
        $snapshot = $this->buildSnapshot($input, $tenantId);
        $calculation = $this->calculate($input, $snapshot);
        $report = $this->buildAiReport($input, $snapshot, $calculation);
        $report = $this->mergeFinancials($report, $input, $calculation);

        $record = FeasibilityReport::create([
            'tenant_id' => $tenantId,
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

    public function regenerate(int $id, int $userId, bool $isSuperAdmin): ?array
    {
        $this->ensureTable();
        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $old = $query->find();
        if (!$old) {
            return null;
        }

        return $this->generate((array) $old->input_json, $userId);
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin): ?array
    {
        $this->ensureTable();
        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $record = $query->find();
        return $record ? $this->formatRecord($record) : null;
    }

    public function list(int $page = 1, int $pageSize = 10, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $this->ensureTable();
        $query = FeasibilityReport::whereNull('deleted_at')->order('id', 'desc');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

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

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        $this->ensureTable();

        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        return $query->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function ensureTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS feasibility_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT NULL,
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
                INDEX idx_feasibility_reports_tenant_user (tenant_id, created_by, id),
                INDEX idx_created_by (created_by),
                INDEX idx_grade (conclusion_grade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->ensureTenantColumns();
    }

    private function ensureTenantColumns(): void
    {
        Db::execute("ALTER TABLE feasibility_reports ADD COLUMN IF NOT EXISTS tenant_id INT UNSIGNED DEFAULT NULL COMMENT '绉熸埛ID锛岄粯璁よ窡闅忓垱寤虹敤鎴? AFTER id");
        Db::execute("ALTER TABLE feasibility_reports ADD INDEX IF NOT EXISTS idx_feasibility_reports_tenant_user (tenant_id, created_by, id)");
    }

    private function applyTenantScope($query, int $userId, bool $isSuperAdmin): void
    {
        if ($isSuperAdmin) {
            return;
        }

        $tenantId = $this->tenantIdForUser($userId);
        if ($tenantId === null) {
            $query->where('tenant_id', -1);
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    private function tenantIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $row = Db::name('users')->where('id', $userId)->field('tenant_id,hotel_id')->find();
            if (!$row) {
                return null;
            }

            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }

            $hotelId = (int)($row['hotel_id'] ?? 0);
            return $hotelId > 0 ? $hotelId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeInput(array $input): array
    {
        $fields = [
            'project_name', 'city', 'district', 'address', 'target_brand_level',
            'target_customer', 'notes', 'model_key',
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

    private function buildSnapshot(array $input, ?int $tenantId = null): array
    {
        $daily = $this->safeRows('daily_reports', fn () => $this->buildTenantSnapshotQuery('daily_reports', $tenantId)->order('report_date', 'desc')->limit(30)->select()->toArray());
        $online = $this->safeRows('online_daily_data', fn () => $this->buildTenantSnapshotQuery('online_daily_data', $tenantId)->order('id', 'desc')->limit(30)->select()->toArray());
        $competitors = $this->safeRows('competitor_hotel', fn () => $this->buildTenantSnapshotQuery('competitor_hotel', $tenantId)->where('status', 1)->limit(20)->select()->toArray());
        $prices = $this->safeRows('competitor_price_log', fn () => $this->buildTenantSnapshotQuery('competitor_price_log', $tenantId)->order('id', 'desc')->limit(50)->select()->toArray());

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

    private function buildTenantSnapshotQuery(string $table, ?int $tenantId)
    {
        $query = Db::name($table);
        if ($tenantId === null || !$this->tableHasColumn($table, 'tenant_id')) {
            $query->where('id', -1);
            return $query;
        }

        $query->where('tenant_id', $tenantId);
        return $query;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $safeTable = str_replace('`', '', $table);
            $safeColumn = str_replace("'", "''", $column);
            return !empty(Db::query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"));
        } catch (\Throwable $e) {
            return false;
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

        $modelKey = trim((string)($input['model_key'] ?? 'deepseek_v4_default'));
        try {
            return $this->client->createJsonResponse($messages, $this->schema(), $modelKey !== '' ? $modelKey : 'deepseek_v4_default');
        } catch (\Throwable $e) {
            return $this->buildFallbackReport($input, $snapshot, $calculation, $e->getMessage());
        }
    }

    private function buildFallbackReport(array $input, array $snapshot, array $calculation, string $reason): array
    {
        $base = $calculation['scenarios'][1];
        $payback = $base['payback_months'] ?? null;
        $riskLevel = (string)($base['risk_level'] ?? '中');
        $grade = 'C';
        if ($riskLevel === '低' && $payback !== null && $payback <= 30) {
            $grade = 'A';
        } elseif ($riskLevel !== '高' && $payback !== null && $payback <= 42) {
            $grade = 'B';
        } elseif ($riskLevel === '高') {
            $grade = 'D';
        }

        $location = trim((string)$input['city'] . ' ' . (string)$input['district'] . ' ' . (string)$input['address']);
        $sourceCounts = $snapshot['source_counts'] ?? [];
        $hasLocalEvidence = array_sum(array_map('intval', is_array($sourceCounts) ? $sourceCounts : [])) > 0;

        return [
            'conclusion_grade' => $grade,
            'conclusion_text' => $grade === 'A' || $grade === 'B' ? '可进入下一轮尽调，需继续校验租金、ADR和入住率假设。' : '当前条件需谨慎推进，建议先优化租金或投资规模。',
            'core_reason' => '本地测算显示基准情景回本周期为' . ($payback === null ? '不可回本' : $payback . '个月') . '，风险等级为' . $riskLevel . '。',
            'summary' => [
                'project_name' => (string)$input['project_name'],
                'location' => $location,
                'room_count' => (int)$input['room_count'],
                'total_investment' => (float)$calculation['total_investment'],
                'payback_months' => $payback === null ? 0 : (float)$payback,
            ],
            'basic_info' => [],
            'market_judgement' => [
                'market_score' => $hasLocalEvidence ? 70 : 60,
                'competition_level' => $hasLocalEvidence ? '可参考本地经营/OTA样本' : '真实样本不足',
                'recommended_model' => (string)($input['target_brand_level'] ?: '中端精选'),
                'target_customer' => (string)($input['target_customer'] ?: '商务差旅'),
                'reasoning' => $hasLocalEvidence ? '已读取系统历史数据快照，仍需补充竞品实采。' : '缺少足够历史样本，本次主要依据用户输入和本地财务模型。',
            ],
            'financial_scenarios' => $calculation['scenarios'],
            'risk_list' => [
                [
                    'risk' => '回本周期',
                    'level' => $riskLevel,
                    'reason' => $payback === null ? '基准情景月净现金流为负。' : '基准情景回本周期为' . $payback . '个月。',
                    'action' => '复核ADR、入住率、租金和单房装修投入。',
                ],
                [
                    'risk' => '数据来源',
                    'level' => $hasLocalEvidence ? '低' : '中',
                    'reason' => $hasLocalEvidence ? '已有部分系统数据快照。' : '缺少充分经营、OTA和竞对样本。',
                    'action' => '接入近期日报、OTA订单和竞对价格后重新生成。',
                ],
            ],
            'action_plan' => [
                ['title' => '复核核心假设', 'priority' => 'P0', 'detail' => '校验ADR、入住率、月租金、装修预算和转让费。'],
                ['title' => '补齐外部样本', 'priority' => 'P1', 'detail' => '补充竞对价格、评分、商圈客流和OTA转化数据。'],
                ['title' => '形成投决结论', 'priority' => 'P2', 'detail' => '按保守、基准、乐观三情景确定是否立项。'],
            ],
            'assumptions' => array_values(array_unique(array_merge(
                $calculation['assumptions'],
                ['LLM报告生成失败，已启用本地测算兜底：' . mb_substr($reason, 0, 120)]
            ))),
            'evidence' => [
                [
                    'source' => 'system',
                    'title' => '宿析OS本地测算',
                    'url' => '',
                    'summary' => '基于用户输入、系统快照和内置财务公式生成。',
                ],
            ],
        ];
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
            'x-governance' => [
                'module' => 'agent',
                'scenario' => 'feasibility_report',
                'prompt_version' => 'agent.feasibility_report.v1',
                'decision_impact' => 'investment',
                'knowledge_sources' => ['user_input', 'system_snapshot', 'deterministic_calculation'],
            ],
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
