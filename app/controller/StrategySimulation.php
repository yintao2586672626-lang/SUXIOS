<?php
declare(strict_types=1);

namespace app\controller;

use app\model\StrategyDataSnapshot;
use app\model\StrategySimulationRecord;
use think\exception\ValidateException;
use think\facade\Db;
use think\Response;

class StrategySimulation extends Base
{
    private const PROPERTY_FIT_CONFIG = [
        '中端精选' => ['area_per_room_min' => 30, 'area_per_room_max' => 50, 'preferred_room_count_min' => 70],
        '经济型' => ['area_per_room_min' => 22, 'area_per_room_max' => 36, 'preferred_room_count_min' => 60],
        '中高端商务' => ['area_per_room_min' => 36, 'area_per_room_max' => 60, 'preferred_room_count_min' => 80],
        '精品度假' => ['area_per_room_min' => 45, 'area_per_room_max' => 80, 'preferred_room_count_min' => 40],
    ];

    public function simulate(): Response
    {
        try {
            $missingTables = $this->getMissingStrategyTables();
            if (!empty($missingTables)) {
                return $this->error(
                    '战略推演数据表缺失: ' . implode(', ', $missingTables) . '，请执行 database/migrations/20260509_create_strategy_simulation_tables.sql',
                    500
                );
            }

            $input = $this->normalizeInput($this->request->post());
            $localData = $this->collectLocalData($input);
            $externalData = $this->collectExternalData($input);
            $dataSnapshot = $this->buildDataSnapshot($localData, $externalData);
            $scores = $this->calculateScores($input, $localData, $externalData);
            $recommendation = $this->buildRecommendation($input, $scores);
            $risk = $this->buildRisk($scores, $recommendation);
            $recordId = $this->saveRecord($input, $dataSnapshot, $scores, $recommendation, $risk);

            return $this->success([
                'total_score' => $scores['total_score'],
                'risk_level' => $risk['risk_level'],
                'decision' => $recommendation['decision'],
                'scores' => $scores['items'],
                'recommendation' => $recommendation,
                'risk' => $risk,
                'data_snapshot' => $dataSnapshot,
                'record_id' => $recordId,
            ], '战略推演成功');
        } catch (ValidateException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('战略推演失败: ' . $e->getMessage(), 500);
        }
    }

    public function records(): Response
    {
        try {
            $missingTables = $this->getMissingStrategyTables();
            if (!empty($missingTables)) {
                return $this->error('战略推演数据表缺失: ' . implode(', ', $missingTables), 500);
            }

            $query = StrategySimulationRecord::whereNull('deleted_at');
            if (!$this->currentUser->isSuperAdmin()) {
                $query->where('created_by', (int)($this->currentUser->id ?? 0));
            }

            $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
            return $this->success([
                'list' => array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows)),
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取战略推演记录失败: ' . $e->getMessage(), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('战略推演记录ID无效', 422);
            }

            $query = StrategySimulationRecord::where('id', $id)->whereNull('deleted_at');
            if (!$this->currentUser->isSuperAdmin()) {
                $query->where('created_by', (int)($this->currentUser->id ?? 0));
            }

            $row = $query->find();
            if (!$row) {
                return $this->error('战略推演记录不存在或无权访问', 404);
            }

            return $this->success($this->formatRecord($row->toArray(), true));
        } catch (\Throwable $e) {
            return $this->error('获取战略推演详情失败: ' . $e->getMessage(), 400);
        }
    }

    public function archive(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('战略推演记录ID无效', 422);
            }

            $query = StrategySimulationRecord::where('id', $id)->whereNull('deleted_at');
            if (!$this->currentUser->isSuperAdmin()) {
                $query->where('created_by', (int)($this->currentUser->id ?? 0));
            }

            $now = date('Y-m-d H:i:s');
            $updated = $query->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
            if ($updated <= 0) {
                return $this->error('战略推演记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '战略推演记录已归档');
        } catch (\Throwable $e) {
            return $this->error('战略推演记录归档失败: ' . $e->getMessage(), 400);
        }
    }

    private function normalizeInput(array $data): array
    {
        $input = [
            'project_name' => trim((string)($data['project_name'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'district' => trim((string)($data['district'] ?? '')),
            'address' => trim((string)($data['address'] ?? '')),
            'property_area' => (float)($data['property_area'] ?? 0),
            'room_count' => (int)($data['room_count'] ?? 0),
            'monthly_rent' => (float)($data['monthly_rent'] ?? 0),
            'decoration_budget' => (float)($data['decoration_budget'] ?? 0),
            'lease_years' => (int)($data['lease_years'] ?? 0),
            'rent_free_months' => (int)($data['rent_free_months'] ?? 0),
            'business_type' => trim((string)($data['business_type'] ?? '')),
            'target_customer' => trim((string)($data['target_customer'] ?? $data['primary_customer'] ?? '')),
            'competitor_count' => max(0, (int)($data['competitor_count'] ?? 0)),
            'target_hotel_level' => trim((string)($data['target_hotel_level'] ?? $data['target_grade'] ?? '')),
        ];

        $this->validate($input, [
            'project_name' => 'require',
            'city' => 'require',
            'room_count' => 'gt:0',
            'property_area' => 'gt:0',
            'monthly_rent' => 'egt:0',
            'decoration_budget' => 'egt:0',
        ], [
            'project_name.require' => '项目名称必填',
            'city.require' => '城市必填',
            'room_count.gt' => '房间数必须大于0',
            'property_area.gt' => '物业面积必须大于0',
            'monthly_rent.egt' => '月租金不能为负数',
            'decoration_budget.egt' => '装修预算不能为负数',
        ]);

        if ($input['business_type'] === '') {
            $input['business_type'] = '核心商务区';
        }
        if ($input['target_customer'] === '') {
            $input['target_customer'] = '商务差旅';
        }
        if ($input['target_hotel_level'] === '') {
            $input['target_hotel_level'] = '中端精选';
        }

        return $input;
    }

    private function collectLocalData(array $input): array
    {
        $hotelIds = $this->currentUser ? $this->currentUser->getPermittedHotelIds() : [];
        $data = [
            'hotel_ids' => $hotelIds,
            'daily_reports' => ['count' => 0, 'avg_occupancy' => null, 'avg_revenue' => null, 'avg_room_count' => null],
            'online_daily_data' => ['count' => 0, 'total_quantity' => 0, 'total_orders' => 0, 'avg_score' => null, 'avg_conversion' => null, 'competitor_hotels' => 0],
            'competitor_analysis' => ['count' => 0, 'competitor_hotels' => 0, 'avg_competitor_price' => null, 'avg_price_index' => null],
            'data_sources' => [],
            'missing_data' => [],
        ];

        if ($this->tableExists('daily_reports')) {
            $query = Db::name('daily_reports')->where('report_date', '>=', date('Y-m-d', strtotime('-90 days')));
            if ($hotelIds) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $query->field('occupancy_rate,revenue,room_count,report_data')->limit(120)->select()->toArray();
            $data['daily_reports'] = $this->summarizeDailyReports($rows);
            $data['data_sources'][] = 'daily_reports';
        } else {
            $data['missing_data'][] = 'daily_reports';
        }

        if ($this->tableExists('online_daily_data')) {
            $query = Db::name('online_daily_data')->where('data_date', '>=', date('Y-m-d', strtotime('-90 days')));
            if ($hotelIds) {
                $query->whereIn('system_hotel_id', $hotelIds);
            }
            $rows = $query->field('hotel_id,hotel_name,amount,quantity,book_order_num,comment_score,qunar_comment_score,raw_data,data_value,source')->limit(300)->select()->toArray();
            $data['online_daily_data'] = $this->summarizeOnlineData($rows);
            $data['data_sources'][] = 'online_daily_data';
        } else {
            $data['missing_data'][] = 'online_daily_data';
        }

        if ($this->tableExists('competitor_analysis')) {
            $query = Db::name('competitor_analysis')->where('analysis_date', '>=', date('Y-m-d', strtotime('-90 days')));
            if ($hotelIds) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $rows = $query->field('competitor_hotel_id,competitor_price,price_index')->limit(200)->select()->toArray();
            $data['competitor_analysis'] = $this->summarizeCompetitorAnalysis($rows);
            $data['data_sources'][] = 'competitor_analysis';
        } else {
            $data['missing_data'][] = 'competitor_analysis';
        }

        return $data;
    }

    private function collectExternalData(array $input): array
    {
        $cacheHours = max(1, (int)env('STRATEGY_DATA_CACHE_HOURS', 24));
        $cached = StrategyDataSnapshot::where([
            'city' => $input['city'],
            'district' => $input['district'],
            'business_type' => $input['business_type'],
            'target_customer' => $input['target_customer'],
        ])->where('created_at', '>=', date('Y-m-d H:i:s', time() - $cacheHours * 3600))
            ->order('id', 'desc')
            ->find();

        if ($cached) {
            $normalized = $cached->normalized_json ?: [];
            $normalized['cache_hit'] = true;
            $normalized['snapshot_id'] = $cached->id;
            return $normalized;
        }

        $amapKey = trim((string)env('AMAP_KEY', ''));
        $baiduKey = trim((string)env('BAIDU_MAP_KEY', ''));
        if ($amapKey === '' && $baiduKey === '') {
            return [
                'available' => false,
                'used' => false,
                'reason' => 'missing_api_key',
                'freshness' => 'external_not_configured',
                'poi_counts' => [],
                'source_summary' => ['外部数据未接入，当前为本地数据推演'],
                'missing_data' => ['AMAP_KEY', 'BAIDU_MAP_KEY'],
            ];
        }

        $raw = [];
        $normalized = [
            'available' => true,
            'used' => false,
            'reason' => '',
            'freshness' => 'today_cache',
            'poi_counts' => [],
            'source_summary' => [],
            'missing_data' => [],
        ];

        if ($amapKey !== '') {
            $poi = $this->fetchAmapPoi($input, $amapKey);
            $raw['amap'] = $poi['raw'];
            $normalized['poi_counts'] = $poi['counts'];
            $normalized['used'] = $poi['used'];
            $normalized['source_summary'][] = $poi['used'] ? '高德 POI 搜索' : '高德 POI 搜索失败';
            if (!$poi['used']) {
                $normalized['missing_data'][] = 'amap_poi';
            }
        } else {
            $normalized['missing_data'][] = 'AMAP_KEY';
        }

        if (!$normalized['used'] && $baiduKey !== '') {
            $normalized['source_summary'][] = '百度 Place API 已配置，当前作为备用数据源记录';
        } elseif ($baiduKey === '') {
            $normalized['missing_data'][] = 'BAIDU_MAP_KEY';
        }

        StrategyDataSnapshot::create([
            'city' => $input['city'],
            'district' => $input['district'],
            'address' => $input['address'],
            'business_type' => $input['business_type'],
            'target_customer' => $input['target_customer'],
            'source_type' => $normalized['used'] ? 'external' : 'external_failed',
            'source_name' => $normalized['used'] ? implode(',', $normalized['source_summary']) : 'configured_source',
            'source_date' => date('Y-m-d'),
            'freshness_level' => $normalized['used'] ? 'today_cache' : 'local_only',
            'raw_json' => $raw,
            'normalized_json' => $normalized,
        ]);

        return $normalized;
    }

    private function calculateScores(array $input, array $localData, array $externalData): array
    {
        $items = [
            'market_demand' => $this->scoreMarketDemand($input, $localData, $externalData),
            'competition' => $this->scoreCompetition($input, $localData, $externalData),
            'property_fit' => $this->scorePropertyFit($input, $localData),
            'cost_pressure' => $this->scoreCostPressure($input, $localData),
            'exit_safety' => $this->scoreExitSafety($input),
        ];

        $total = round(
            $items['market_demand']['score'] * 0.25
            + $items['competition']['score'] * 0.20
            + $items['property_fit']['score'] * 0.20
            + $items['cost_pressure']['score'] * 0.20
            + $items['exit_safety']['score'] * 0.15
        );

        return [
            'total_score' => $this->clampScore($total),
            'items' => $items,
        ];
    }

    private function buildRecommendation(array $input, array $scores): array
    {
        $total = $scores['total_score'];
        $competitionScore = $scores['items']['competition']['score'];
        $costScore = $scores['items']['cost_pressure']['score'];
        $decision = $total >= 85 ? '建议推进' : ($total >= 70 ? '谨慎推进' : ($total >= 60 ? '需要重构条件后再推进' : '不建议推进'));
        $pressure = $competitionScore >= 80 ? '低' : ($competitionScore >= 60 ? '中' : '高');

        return [
            'recommended_model' => $input['target_hotel_level'] . '酒店 + ' . $input['business_type'] . '客源模型',
            'target_customer' => $input['target_customer'],
            'competition_pressure' => $pressure,
            'decision_direction' => '智略定方向',
            'decision' => $decision,
            'key_actions' => [
                '补充周边同档竞品价格带和点评量',
                '复核物业消防、层高、排水和房量落位条件',
                $costScore < 70 ? '继续谈判租金、免租期或装修投入上限' : '锁定租金递增和装修预算边界',
            ],
            'main_risks' => [
                $pressure === '高' ? '同档竞品密度较高，开业爬坡和 ADR 上浮承压' : '需持续跟踪新增供给和竞品翻牌',
                '外部客流与 POI 数据不足时，需用实地调研复核需求强度',
            ],
            'next_data_to_verify' => [
                '500米-3公里竞品酒店数量、价格带、评分和点评量',
                '近90天 OTA 浏览、转化、入住和点评数据',
                '租约递增条款、免租期、装修清单和退出条件',
            ],
        ];
    }

    private function formatRecord(array $row, bool $withDetail): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        if (empty($input)) {
            $input = [
                'project_name' => $row['project_name'] ?? '',
                'city' => $row['city'] ?? '',
                'district' => $row['district'] ?? '',
                'address' => $row['address'] ?? '',
                'property_area' => (float)($row['property_area'] ?? 0),
                'room_count' => (int)($row['room_count'] ?? 0),
                'monthly_rent' => (float)($row['monthly_rent'] ?? 0),
                'decoration_budget' => (float)($row['decoration_budget'] ?? 0),
                'lease_years' => (int)($row['lease_years'] ?? 0),
                'rent_free_months' => (int)($row['rent_free_months'] ?? 0),
                'business_type' => $row['business_type'] ?? '',
                'target_customer' => $row['target_customer'] ?? '',
                'target_hotel_level' => $row['target_hotel_level'] ?? '',
                'competitor_count' => (int)($row['competitor_count'] ?? 0),
            ];
        }

        $scoreJson = $this->decodeJson($row['score_json'] ?? []);
        $recommendation = $this->decodeJson($row['recommendation_json'] ?? []);
        $risk = $this->decodeJson($row['risk_json'] ?? []);
        $dataSnapshot = $this->decodeJson($row['data_snapshot_json'] ?? []);
        $totalScore = (int)($scoreJson['total_score'] ?? 0);
        $scoreItems = $scoreJson['items'] ?? $scoreJson;

        $record = [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ($input['project_name'] ?? '')),
            'city' => (string)($row['city'] ?? ($input['city'] ?? '')),
            'district' => (string)($row['district'] ?? ($input['district'] ?? '')),
            'total_score' => $totalScore,
            'risk_level' => (string)($risk['risk_level'] ?? ''),
            'decision' => (string)($recommendation['decision'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];

        if (!$withDetail) {
            return $record;
        }

        return array_merge($record, [
            'input' => $input,
            'scores' => $scoreItems,
            'recommendation' => $recommendation,
            'risk' => $risk,
            'data_snapshot' => $dataSnapshot,
        ]);
    }

    private function saveRecord(array $input, array $dataSnapshot, array $scores, array $recommendation, array $risk): int
    {
        $record = StrategySimulationRecord::create([
            'project_name' => $input['project_name'],
            'city' => $input['city'],
            'district' => $input['district'],
            'address' => $input['address'],
            'property_area' => $input['property_area'],
            'room_count' => $input['room_count'],
            'monthly_rent' => $input['monthly_rent'],
            'decoration_budget' => $input['decoration_budget'],
            'lease_years' => $input['lease_years'],
            'rent_free_months' => $input['rent_free_months'],
            'business_type' => $input['business_type'],
            'target_customer' => $input['target_customer'],
            'competitor_count' => $input['competitor_count'],
            'target_hotel_level' => $input['target_hotel_level'],
            'input_json' => $input,
            'data_snapshot_json' => $dataSnapshot,
            'score_json' => $scores,
            'recommendation_json' => $recommendation,
            'risk_json' => $risk,
            'created_by' => $this->currentUser->id ?? 0,
        ]);

        return (int)$record->id;
    }

    private function scoreMarketDemand(array $input, array $localData, array $externalData): array
    {
        $base = ['核心商务区' => 88, '交通枢纽' => 84, '文旅景区' => 80, '产业园区' => 76, '社区配套' => 66][$input['business_type']] ?? 70;
        $reasons = ["商圈类型为{$input['business_type']}，基础需求分为{$base}"];
        $sources = ['用户输入'];
        $missing = [];

        if ($input['target_customer'] === '商务差旅') {
            $base += 4;
            $reasons[] = '目标客源为商务差旅，与商务/产业客流匹配度较高';
        }

        $online = $localData['online_daily_data'];
        if ($online['count'] > 0) {
            $sources[] = 'online_daily_data';
            $base += min(8, $online['total_quantity'] / max(1, $online['count']) / 3);
            if ($online['avg_score'] !== null && $online['avg_score'] >= 4.6) {
                $base += 3;
                $reasons[] = '本地 OTA 评分均值较高，说明同类供给具备基础接受度';
            }
            $reasons[] = '已使用本地 OTA 入住、订单、评分数据校准需求强度';
        } else {
            $missing[] = '本地 OTA 历史入住/浏览/转化数据';
        }

        $poiCounts = $externalData['poi_counts'] ?? [];
        if (!empty($poiCounts)) {
            $sources[] = '外部 POI 数据';
            $demandPoi = ($poiCounts['office'] ?? 0) + ($poiCounts['transport'] ?? 0) + ($poiCounts['scenic'] ?? 0) + ($poiCounts['hospital'] ?? 0) + ($poiCounts['school'] ?? 0);
            $base += min(8, $demandPoi * 1.2);
            $reasons[] = "周边办公/交通/景区/医院/学校 POI 合计{$demandPoi}个";
        } else {
            $missing[] = '外部 POI 数据';
        }

        return $this->scoreItem($base, $reasons, $sources, $missing);
    }

    private function scoreCompetition(array $input, array $localData, array $externalData): array
    {
        $competitorCount = max($input['competitor_count'], $localData['online_daily_data']['competitor_hotels'], $localData['competitor_analysis']['competitor_hotels']);
        $marketDemandHint = ['核心商务区' => 8, '交通枢纽' => 6, '文旅景区' => 5][$input['business_type']] ?? 0;
        $score = 96 - $competitorCount * 5 + $marketDemandHint;
        $reasons = ["识别竞品数量{$competitorCount}个，竞争越激烈分数越低"];
        $sources = ['用户输入'];
        $missing = [];

        if ($localData['online_daily_data']['competitor_hotels'] > 0) {
            $sources[] = 'online_daily_data';
            $reasons[] = '已使用本地 OTA 榜单酒店数量辅助判断供给密度';
        }
        if ($localData['competitor_analysis']['count'] > 0) {
            $sources[] = 'competitor_analysis';
            $price = round((float)$localData['competitor_analysis']['avg_competitor_price']);
            $reasons[] = "已有竞品价格监控数据，竞品均价约{$price}元";
        } else {
            $missing[] = '竞品价格带/评分/点评量';
        }
        if (!empty($externalData['poi_counts']['hotel'])) {
            $sources[] = '外部酒店 POI';
            $score -= min(10, $externalData['poi_counts']['hotel'] * 2);
            $reasons[] = "外部 POI 显示周边酒店约{$externalData['poi_counts']['hotel']}个";
        }

        return $this->scoreItem($score, $reasons, $sources, $missing);
    }

    private function scorePropertyFit(array $input, array $localData): array
    {
        $config = self::PROPERTY_FIT_CONFIG[$input['target_hotel_level']] ?? self::PROPERTY_FIT_CONFIG['中端精选'];
        $areaPerRoom = $input['property_area'] / max(1, $input['room_count']);
        $mid = ($config['area_per_room_min'] + $config['area_per_room_max']) / 2;
        $score = 100 - abs($areaPerRoom - $mid) * 2;
        $reasons = [
            "单房建筑面积约" . round($areaPerRoom, 1) . "㎡",
            "{$input['target_hotel_level']}建议单房建筑面积区间为{$config['area_per_room_min']}-{$config['area_per_room_max']}㎡",
        ];
        if ($input['room_count'] >= $config['preferred_room_count_min']) {
            $score += 4;
            $reasons[] = '房量达到目标模型的基础规模要求';
        } else {
            $score -= 6;
            $reasons[] = '房量低于目标模型的建议规模，需复核坪效';
        }

        return $this->scoreItem($score, $reasons, ['用户输入', '物业适配参数'], []);
    }

    private function scoreCostPressure(array $input, array $localData): array
    {
        $rentPerRoom = $input['monthly_rent'] / max(1, $input['room_count']);
        $decorationPerRoom = $input['decoration_budget'] / max(1, $input['room_count']);
        $score = 100 - max(0, $rentPerRoom - 1800) / 45 - max(0, $decorationPerRoom - 23000) / 600;
        $score += min(8, $input['rent_free_months'] * 1.5);
        if ($input['lease_years'] >= 10) {
            $score += 4;
        }

        return $this->scoreItem($score, [
            '单房月租约' . round($rentPerRoom) . '元',
            '单房装修约' . round($decorationPerRoom) . '元',
            "免租期{$input['rent_free_months']}个月、租期{$input['lease_years']}年，对成本压力有修正",
        ], ['用户输入'], []);
    }

    private function scoreExitSafety(array $input): array
    {
        $decorationPerRoom = $input['decoration_budget'] / max(1, $input['room_count']);
        $score = 58 + min(18, $input['lease_years'] * 2) + min(10, $input['rent_free_months'] * 1.5) - max(0, $decorationPerRoom - 26000) / 900;
        $genericBonus = in_array($input['target_hotel_level'], ['经济型', '中端精选', '中高端商务'], true) ? 6 : 0;
        $score += $genericBonus;

        return $this->scoreItem($score, [
            "租期{$input['lease_years']}年、免租期{$input['rent_free_months']}个月",
            '单房沉没装修成本约' . round($decorationPerRoom) . '元',
            $genericBonus > 0 ? '目标模型通用性较强，退出或转租安全边际较好' : '目标模型偏定制化，退出安全需额外验证',
        ], ['用户输入'], ['租约退出条款', '物业转租限制']);
    }

    private function buildRisk(array $scores, array $recommendation): array
    {
        $total = $scores['total_score'];
        return [
            'risk_level' => $total >= 85 ? '低风险' : ($total >= 70 ? '中风险' : ($total >= 60 ? '中高风险' : '高风险')),
            'main_risks' => $recommendation['main_risks'],
        ];
    }

    private function scoreItem(float $score, array $reasons, array $sources, array $missing): array
    {
        $score = $this->clampScore($score);
        return [
            'score' => $score,
            'level' => $score >= 80 ? '高' : ($score >= 60 ? '中' : '低'),
            'reasons' => array_values(array_unique($reasons)),
            'data_sources' => array_values(array_unique($sources)),
            'missing_data' => array_values(array_unique($missing)),
        ];
    }

    private function buildDataSnapshot(array $localData, array $externalData): array
    {
        $externalUsed = (bool)($externalData['used'] ?? false);
        $externalAvailable = (bool)($externalData['available'] ?? false);
        return [
            'local_data_used' => !empty($localData['data_sources']),
            'external_data_used' => $externalUsed,
            'external_data_available' => $externalAvailable,
            'external_data_reason' => $externalData['reason'] ?? ($externalUsed ? '' : 'missing_api_key'),
            'freshness' => $externalUsed ? ($externalData['freshness'] ?? 'today_cache') : 'local_only',
            'source_summary' => array_values(array_merge($localData['data_sources'], $externalData['source_summary'] ?? [])),
            'local_data' => $localData,
            'external_data' => $externalData,
        ];
    }

    private function summarizeDailyReports(array $rows): array
    {
        $occupancy = [];
        $revenue = [];
        $rooms = [];
        foreach ($rows as $row) {
            $reportData = $this->decodeJson($row['report_data'] ?? null);
            $occupancy[] = (float)($row['occupancy_rate'] ?? $reportData['day_occ_rate'] ?? 0);
            $revenue[] = (float)($row['revenue'] ?? $reportData['day_revenue'] ?? 0);
            $rooms[] = (float)($row['room_count'] ?? $reportData['day_total_rooms'] ?? 0);
        }
        return [
            'count' => count($rows),
            'avg_occupancy' => $this->averagePositive($occupancy),
            'avg_revenue' => $this->averagePositive($revenue),
            'avg_room_count' => $this->averagePositive($rooms),
        ];
    }

    private function summarizeOnlineData(array $rows): array
    {
        $scores = [];
        $conversion = [];
        $hotelIds = [];
        $quantity = 0;
        $orders = 0;
        foreach ($rows as $row) {
            $hotelIds[] = $row['hotel_id'] ?? '';
            $quantity += (int)($row['quantity'] ?? 0) + (int)($row['data_value'] ?? 0);
            $orders += (int)($row['book_order_num'] ?? 0);
            $score = max((float)($row['comment_score'] ?? 0), (float)($row['qunar_comment_score'] ?? 0));
            if ($score > 0) {
                $scores[] = $score;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? null);
            foreach (['convertionRate', 'qunarDetailCR', 'conversionRate'] as $key) {
                if (isset($raw[$key]) && (float)$raw[$key] > 0) {
                    $conversion[] = (float)$raw[$key];
                }
            }
        }
        return [
            'count' => count($rows),
            'total_quantity' => $quantity,
            'total_orders' => $orders,
            'avg_score' => $this->averagePositive($scores),
            'avg_conversion' => $this->averagePositive($conversion),
            'competitor_hotels' => count(array_filter(array_unique($hotelIds))),
        ];
    }

    private function summarizeCompetitorAnalysis(array $rows): array
    {
        return [
            'count' => count($rows),
            'competitor_hotels' => count(array_filter(array_unique(array_column($rows, 'competitor_hotel_id')))),
            'avg_competitor_price' => $this->averagePositive(array_column($rows, 'competitor_price')),
            'avg_price_index' => $this->averagePositive(array_column($rows, 'price_index')),
        ];
    }

    private function fetchAmapPoi(array $input, string $key): array
    {
        $keywords = [
            'hotel' => '酒店',
            'office' => '写字楼',
            'transport' => '地铁站',
            'scenic' => '景点',
            'hospital' => '医院',
            'school' => '学校',
        ];
        $counts = [];
        $raw = [];
        $used = false;
        $region = $input['city'] . $input['district'];
        foreach ($keywords as $type => $keyword) {
            $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
                'key' => $key,
                'keywords' => $keyword,
                'city' => $region,
                'offset' => 10,
                'page' => 1,
                'extensions' => 'base',
            ]);
            $response = $this->curlJson($url);
            $raw[$type] = $response;
            $count = (int)($response['count'] ?? 0);
            $counts[$type] = min($count, 50);
            if (($response['status'] ?? '') === '1') {
                $used = true;
            }
        }
        return ['used' => $used, 'counts' => $counts, 'raw' => $raw];
    }

    private function getMissingStrategyTables(): array
    {
        $requiredTables = [
            'strategy_simulation_records',
            'strategy_data_snapshots',
        ];

        return array_values(array_filter($requiredTables, fn (string $table): bool => !$this->tableExists($table)));
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function curlJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => '0', 'info' => 'curl_unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!$body) {
            return ['status' => '0', 'info' => 'request_failed'];
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : ['status' => '0', 'info' => 'invalid_json'];
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $data = json_decode($value, true);
        return is_array($data) ? $data : [];
    }

    private function averagePositive(array $values): ?float
    {
        $values = array_values(array_filter(array_map('floatval', $values), fn($v) => $v > 0));
        if (!$values) {
            return null;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function clampScore(float $score): int
    {
        return (int)max(0, min(100, round($score)));
    }
}
