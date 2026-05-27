<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelConfig;
use app\model\User;
use RuntimeException;
use think\facade\Db;

class RevenueResearchService
{
    /**
     * @return array<string, mixed>
     */
    public function run(string $productKey, string $modelKey, ?User $user = null, ?int $hotelId = null): array
    {
        $product = $this->product($productKey);
        $modelKey = trim($modelKey) !== '' ? trim($modelKey) : 'deepseek_chat';
        $permittedHotelIds = $user ? array_map('intval', $user->getPermittedHotelIds()) : [];
        $hotelIds = $permittedHotelIds;
        if ($hotelId !== null && $hotelId > 0) {
            if ($permittedHotelIds && !in_array($hotelId, $permittedHotelIds, true)) {
                throw new RuntimeException('当前账号没有该酒店的预测权限。', 403);
            }
            $hotelIds = [$hotelId];
        }
        $localSources = $this->collectLocalSources($product, $hotelIds);
        $businessForecast = $this->buildBusinessForecast($hotelIds);
        $gaps = $this->evaluateGaps($product, $localSources);
        $webResult = $modelKey === 'openai_fast'
            ? $this->callOpenAiWebSearch($this->resolveOpenAiConfig($modelKey), $product, $localSources, $gaps, $businessForecast)
            : $this->callConfiguredModel($modelKey, $product, $localSources, $gaps, $businessForecast);
        $status = empty($gaps) && (bool)($businessForecast['available'] ?? false) ? 'done' : 'pending_data';
        $result = $this->normalizeAiResult($webResult['result'], $product, $gaps, $businessForecast);

        return [
            'status' => $status,
            'product_key' => $product['key'],
            'local_sources' => $localSources,
            'web_sources' => $webResult['web_sources'],
            'business_forecast' => $businessForecast,
            'result' => $result,
            'gaps' => $gaps,
            'hotel_scope' => [
                'mode' => $hotelId !== null && $hotelId > 0 ? 'single_hotel' : 'all_permitted_hotels',
                'hotel_id' => $hotelId,
                'hotel_ids' => $hotelIds,
            ],
            'model_key' => $modelKey,
            'generation_mode' => $webResult['generation_mode'] ?? 'configured_model',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $productKey): array
    {
        $products = $this->products();
        $key = trim($productKey);
        if (!isset($products[$key])) {
            throw new RuntimeException('不支持的收益研究产品方向：' . $key, 422);
        }

        return $products[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function products(): array
    {
        return [
            'demand-forecast' => [
                'key' => 'demand-forecast',
                'name' => '需求预测',
                'query' => 'hotel demand forecasting stay date lead time WAPE sMAPE OTA revenue management',
                'module' => '酒店AI工具箱 / 收益管理 / 收益分析',
                'task' => '基于入住日、提前期、价格、库存、取消修正和节假日，生成酒店 OTA 需求预测的数据字段、模型选择、评估指标和上线验收清单。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 180, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num'], 'label' => '连续日级订单、价格和间夜数据', 'collect_from' => '平台数据自动获取：携程/美团日级经营数据'],
                    ['table' => 'daily_reports', 'min_count' => 30, 'fields' => ['report_date', 'occupancy_rate', 'revenue', 'room_count'], 'label' => '酒店日报入住率、收入和房量校验数据', 'collect_from' => '经营日报或日报导入'],
                ],
            ],
            'cancellation-risk' => [
                'key' => 'cancellation-risk',
                'name' => '取消率预测',
                'query' => 'hotel booking cancellation prediction free cancellation recoverable room nights hazard model',
                'module' => '待新增：取消风险订单表、预警列表、净需求修正接口',
                'task' => '设计取消率预测产品：输入特征、标签定义、PR-AUC 与可回收间夜指标、预警阈值、超售控制动作，以及旧订单数据兼容方案。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['cancel_order_num', 'cancel_rate', 'free_cancel_rule', 'order_id'], 'label' => '订单级取消标签、取消规则和可回收间夜', 'collect_from' => 'OTA订单明细、取消政策、取消流水'],
                ],
            ],
            'price-elasticity' => [
                'key' => 'price-elasticity',
                'name' => '价格弹性与收益管理',
                'query' => 'hotel dynamic pricing price elasticity constrained optimization contextual bandit RevPAR',
                'module' => '酒店AI工具箱 / 收益管理 / 定价建议',
                'task' => '用酒店价格、库存、竞对价格、节假日和提前期，输出价格弹性建模方案、约束条件、调价策略和人工审批规则。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 90, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num'], 'label' => '日级价格、销量和间夜数据', 'collect_from' => '平台数据自动获取'],
                    ['table' => 'competitor_analysis', 'min_count' => 10, 'fields' => ['analysis_date', 'our_price', 'competitor_price', 'price_index'], 'label' => '竞对价格与价格指数', 'collect_from' => '竞对价格监控'],
                ],
            ],
            'channel-attribution' => [
                'key' => 'channel-attribution',
                'name' => '渠道归因与增量评估',
                'query' => 'CUPED A/B testing difference in differences hotel OTA channel attribution incrementality',
                'module' => '酒店AI工具箱 / OTA诊断',
                'task' => '为酒店 OTA 活动评估设计增量归因方案：实验分组、历史协变量、MDE、ROI、置信区间和不可随机时的 DiD/BSTS 备选。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 30, 'fields' => ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'], 'label' => '曝光、访问、提交和订单转化链路', 'collect_from' => '携程/美团流量、订单、广告数据采集'],
                ],
            ],
            'customer-segmentation' => [
                'key' => 'customer-segmentation',
                'name' => '客群细分',
                'query' => 'hotel customer segmentation RFM KMeans HDBSCAN propensity model OTA repeat customer',
                'module' => '待新增：客群特征宽表、分群结果、运营触达动作',
                'task' => '设计酒店客群细分产品：RFM 字段、聚类特征、分群稳定性、可解释标签、触达策略和用户匿名化权限边界。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['customer_id', 'guest_id', 'order_id', 'last_stay_date', 'order_amount'], 'label' => '匿名用户主键、订单频次、最近入住和客单价', 'collect_from' => '订单明细、会员系统或脱敏客史'],
                ],
            ],
            'ltv' => [
                'key' => 'ltv',
                'name' => 'LTV 预测',
                'query' => 'hotel customer lifetime value BG NBD Gamma Gamma gradient boosting survival regression',
                'module' => '待新增：用户生命周期表、LTV 预测结果、CAC 联动配置',
                'task' => '设计酒店 LTV 预测：历史 LTV 与预测 LTV 区分、订单频次/间隔/客单价特征、MAE/RMSE/Decile Lift/Calibration 验收口径。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['customer_id', 'guest_id', 'order_id', 'order_amount', 'refund_amount', 'acquisition_cost'], 'label' => '用户级订单序列、退款取消和获客成本', 'collect_from' => '订单明细、会员系统、投放成本表'],
                ],
            ],
            'anomaly-detection' => [
                'key' => 'anomaly-detection',
                'name' => '异常检测',
                'query' => 'hotel OTA anomaly detection STL ESD isolation forest conversion rate alert root cause',
                'module' => '项目AI管理 / 运营管理 / 策见·预警推送',
                'task' => '设计酒店 OTA 异常检测：订单、库存、价格、转化率、点评、接口失败码的规则阈值、误报率、发现时间和恢复时间指标。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 30, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num', 'comment_score'], 'label' => '日级经营、订单和点评指标', 'collect_from' => '平台数据自动获取'],
                    ['table' => 'operation_alerts', 'min_count' => 0, 'fields' => ['alert_type', 'level', 'message', 'raw_data'], 'label' => '运营预警落点', 'collect_from' => '运营管理预警表'],
                ],
            ],
            'review-topic' => [
                'key' => 'review-topic',
                'name' => '点评主题与服务缺口识别',
                'query' => 'hotel review topic modeling BERT embedding service gap detection negative review recall',
                'module' => '数据配置 / 携程点评 / 美团点评',
                'task' => '基于酒店点评文本和评分，输出主题识别、差评召回、服务缺口、人工复核规则和整改闭环模板。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['comment_text', 'review_text', 'comment_score', 'comment_time', 'reply_content'], 'label' => '点评文本、评分、入住日期和回复内容', 'collect_from' => '携程点评/美团点评浏览器采集'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, int> $hotelIds
     * @return array<int, array<string, mixed>>
     */
    private function collectLocalSources(array $product, array $hotelIds): array
    {
        return [
            $this->knowledgeUnitsSummary($product),
            $this->knowledgeBaseSummary($product, $hotelIds),
            $this->tableSummary('online_daily_data', '平台日级数据', 'data_date', ['data_date', 'amount', 'quantity', 'book_order_num', 'comment_score', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num', 'raw_data'], $hotelIds),
            $this->tableSummary('daily_reports', '经营日报', 'report_date', ['report_date', 'occupancy_rate', 'revenue', 'room_count', 'guest_count', 'report_data'], $hotelIds),
            $this->tableSummary('demand_forecasts', '需求预测结果', 'forecast_date', ['forecast_date', 'predicted_occupancy', 'predicted_demand', 'confidence_score', 'historical_data'], $hotelIds),
            $this->tableSummary('price_suggestions', '定价建议', 'suggestion_date', ['suggestion_date', 'current_price', 'suggested_price', 'min_price', 'max_price', 'competitor_data', 'factors'], $hotelIds),
            $this->tableSummary('competitor_analysis', '竞对分析', 'analysis_date', ['analysis_date', 'our_price', 'competitor_price', 'price_difference', 'price_index', 'competitor_data'], $hotelIds),
            $this->tableSummary('operation_alerts', '运营预警', 'related_date', ['alert_type', 'level', 'title', 'message', 'source', 'status', 'raw_data'], $hotelIds),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function knowledgeUnitsSummary(array $product): array
    {
        if (!$this->tableExists('knowledge_units') || !$this->tableExists('knowledge_chunks')) {
            return [
                'source' => 'knowledge_units',
                'label' => '智能知识单元',
                'status' => 'missing_table',
                'count' => 0,
                'summary' => '知识中枢表不存在',
            ];
        }

        $keywords = $this->knowledgeKeywords($product);
        $query = Db::name('knowledge_units');
        $this->applyKeywordRawWhere($query, ['name', 'description'], $keywords, 'ku');
        $rows = $query->order('unit_id', 'desc')->limit(8)->select()->toArray();
        $unitIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $rows)));
        $chunkCount = $unitIds ? (int)Db::name('knowledge_chunks')->whereIn('unit_id', $unitIds)->count() : 0;

        return [
            'source' => 'knowledge_units',
            'label' => '智能知识单元',
            'status' => $rows ? 'available' : 'empty',
            'count' => count($rows),
            'chunk_count' => $chunkCount,
            'summary' => $rows ? '已检索到相关知识单元' : '未检索到相关知识单元',
            'items' => array_map(static fn(array $row): array => [
                'unit_id' => (int)($row['unit_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
            ], $rows),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function knowledgeBaseSummary(array $product, array $hotelIds): array
    {
        if (!$this->tableExists('knowledge_base')) {
            return [
                'source' => 'knowledge_base',
                'label' => '员工知识库',
                'status' => 'missing_table',
                'count' => 0,
                'summary' => '员工知识库表不存在',
            ];
        }

        $keywords = $this->knowledgeKeywords($product);
        $query = Db::name('knowledge_base')->where('is_enabled', 1);
        $this->applyKeywordRawWhere($query, ['title', 'content', 'keywords'], $keywords, 'kb');
        $columns = $this->tableColumns('knowledge_base');
        if ($hotelIds && isset($columns['hotel_id'])) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        $rows = $query->field('id,title,keywords,hotel_id')->order('id', 'desc')->limit(8)->select()->toArray();
        return [
            'source' => 'knowledge_base',
            'label' => '员工知识库',
            'status' => $rows ? 'available' : 'empty',
            'count' => count($rows),
            'summary' => $rows ? '已检索到相关员工知识库内容' : '未检索到相关员工知识库内容',
            'items' => array_map(static fn(array $row): array => [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'keywords' => (string)($row['keywords'] ?? ''),
            ], $rows),
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function tableSummary(string $table, string $label, string $dateColumn, array $fields, array $hotelIds): array
    {
        if (!$this->tableExists($table)) {
            return [
                'source' => $table,
                'label' => $label,
                'status' => 'missing_table',
                'count' => 0,
                'summary' => $label . '表不存在',
                'fields_available' => [],
                'fields_missing' => $fields,
            ];
        }

        $columns = $this->tableColumns($table);
        $available = array_values(array_intersect($fields, array_keys($columns)));
        $missing = array_values(array_diff($fields, $available));
        $countQuery = $this->scopedQuery($table, $columns, $hotelIds);
        $count = (int)$countQuery->count();
        $range = [];
        if ($dateColumn !== '' && isset($columns[$dateColumn]) && $count > 0) {
            $rangeRow = $this->scopedQuery($table, $columns, $hotelIds)
                ->field('MIN(`' . $dateColumn . '`) AS min_date, MAX(`' . $dateColumn . '`) AS max_date')
                ->find();
            if (is_array($rangeRow)) {
                $range = [
                    'start' => (string)($rangeRow['min_date'] ?? ''),
                    'end' => (string)($rangeRow['max_date'] ?? ''),
                ];
            }
        }

        return [
            'source' => $table,
            'label' => $label,
            'status' => $count > 0 ? 'available' : 'empty',
            'count' => $count,
            'date_range' => $range,
            'fields_available' => $available,
            'fields_missing' => $missing,
            'summary' => $count > 0 ? $label . '已存在 ' . $count . ' 条记录' : $label . '暂无记录',
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function buildBusinessForecast(array $hotelIds): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [
                'available' => false,
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'online_daily_data 表不存在，无法生成酒店经营预测。',
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $columns = $this->tableColumns('online_daily_data');
        $required = ['data_date', 'amount', 'quantity', 'book_order_num'];
        $missing = array_values(array_diff($required, array_keys($columns)));
        if ($missing) {
            return [
                'available' => false,
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'online_daily_data 缺少预测必需字段：' . implode('、', $missing),
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $fields = array_values(array_intersect([
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'hotel_name',
            'hotel_id',
            'system_hotel_id',
        ], array_keys($columns)));
        $fieldSql = implode(',', array_map(static fn(string $field): string => '`' . $field . '`', $fields));
        $rows = $this->scopedQuery('online_daily_data', $columns, $hotelIds)
            ->field($fieldSql)
            ->order('data_date', 'desc')
            ->limit(3000)
            ->select()
            ->toArray();

        $daily = [];
        $sourceCounts = [];
        $hotelNames = [];
        foreach ($rows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $revenue = $this->numberValue($row['amount'] ?? 0);
            $roomNights = $this->numberValue($row['quantity'] ?? 0);
            $orders = $this->numberValue($row['book_order_num'] ?? 0);
            if ($revenue <= 0 && $roomNights <= 0 && $orders <= 0) {
                continue;
            }

            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'revenue' => 0.0,
                    'room_nights' => 0.0,
                    'orders' => 0.0,
                    'row_count' => 0,
                ];
            }
            $daily[$date]['revenue'] += $revenue;
            $daily[$date]['room_nights'] += $roomNights;
            $daily[$date]['orders'] += $orders;
            $daily[$date]['row_count']++;

            $source = trim((string)($row['source'] ?? ''));
            if ($source !== '') {
                $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            }
            $hotelName = trim((string)($row['hotel_name'] ?? ''));
            if ($hotelName !== '') {
                $hotelNames[$hotelName] = true;
            }
        }

        if (!$daily) {
            return [
                'available' => false,
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => '未找到可用于预测的有效经营记录。需要至少包含收入、间夜或订单数的日级数据。',
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        usort($daily, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
        $sampleDays = count($daily);
        if ($sampleDays < 3) {
            return [
                'available' => false,
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => '有效经营日少于 3 天，暂不生成预测，避免用孤立样本外推。',
                'sample_days' => $sampleDays,
                'date_range' => [
                    'start' => (string)($daily[0]['date'] ?? ''),
                    'end' => (string)($daily[$sampleDays - 1]['date'] ?? ''),
                ],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $recent7 = $this->aggregateWindow(array_slice($daily, -7));
        $previous7 = $this->aggregateWindow(array_slice($daily, max(0, $sampleDays - 14), min(7, max(0, $sampleDays - 7))));
        $recent30 = $this->aggregateWindow(array_slice($daily, -30));
        $trend = $previous7['avg_daily_revenue'] > 0
            ? ($recent7['avg_daily_revenue'] - $previous7['avg_daily_revenue']) / $previous7['avg_daily_revenue']
            : 0.0;
        $trend = max(-0.3, min(0.3, $trend));
        $forecast7 = $this->forecastWindow($recent7, 7, $trend);
        $forecast30 = $this->forecastWindow($recent30, 30, $trend);
        $dailyForecast = $this->buildDailyForecast($forecast7, $trend);
        $confidence = $sampleDays >= 60 ? 'high' : ($sampleDays >= 30 ? 'medium' : ($sampleDays >= 14 ? 'low' : 'very_low'));
        $riskSignals = [];
        if ($trend < -0.1) {
            $riskSignals[] = '最近7天收入均值较前7天下降超过10%，需关注流量、价格和竞对动作。';
        }
        if (($recent7['adr'] ?? 0) > 0 && ($recent30['adr'] ?? 0) > 0 && $recent7['adr'] < $recent30['adr'] * 0.9) {
            $riskSignals[] = '最近7天 ADR 低于近30天均值超过10%，需复盘低价订单、促销和房型结构。';
        }
        if ($sampleDays < 30) {
            $riskSignals[] = '样本少于30个有效经营日，预测只能作为短期经营参考。';
        }

        return [
            'available' => true,
            'method' => '最近7天移动均值 + 最近7天/前7天趋势修正，趋势修正封顶为正负30%。',
            'generated_at' => date('Y-m-d H:i:s'),
            'sample_days' => $sampleDays,
            'confidence' => $confidence,
            'date_range' => [
                'start' => (string)$daily[0]['date'],
                'end' => (string)$daily[$sampleDays - 1]['date'],
            ],
            'hotel_names' => array_slice(array_keys($hotelNames), 0, 8),
            'source_counts' => $sourceCounts,
            'recent_7d' => $recent7,
            'previous_7d' => $previous7,
            'recent_30d' => $recent30,
            'trend_percent' => round($trend * 100, 2),
            'forecast_7d' => $forecast7,
            'forecast_30d' => $forecast30,
            'daily_forecast' => $dailyForecast,
            'risk_signals' => $riskSignals,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function aggregateWindow(array $rows): array
    {
        $days = count($rows);
        $revenue = 0.0;
        $roomNights = 0.0;
        $orders = 0.0;
        foreach ($rows as $row) {
            $revenue += (float)($row['revenue'] ?? 0);
            $roomNights += (float)($row['room_nights'] ?? 0);
            $orders += (float)($row['orders'] ?? 0);
        }

        return [
            'days' => $days,
            'revenue' => round($revenue, 2),
            'room_nights' => round($roomNights, 0),
            'orders' => round($orders, 0),
            'adr' => $roomNights > 0 ? round($revenue / $roomNights, 2) : 0.0,
            'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'avg_daily_revenue' => $days > 0 ? round($revenue / $days, 2) : 0.0,
            'avg_daily_room_nights' => $days > 0 ? round($roomNights / $days, 2) : 0.0,
            'avg_daily_orders' => $days > 0 ? round($orders / $days, 2) : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private function forecastWindow(array $window, int $days, float $trend): array
    {
        $factor = 1 + $trend * 0.5;
        $revenue = max(0, (float)($window['avg_daily_revenue'] ?? 0) * $days * $factor);
        $roomNights = max(0, (float)($window['avg_daily_room_nights'] ?? 0) * $days * $factor);
        $orders = max(0, (float)($window['avg_daily_orders'] ?? 0) * $days * $factor);

        return [
            'days' => $days,
            'revenue' => round($revenue, 2),
            'room_nights' => round($roomNights, 0),
            'orders' => round($orders, 0),
            'adr' => $roomNights > 0 ? round($revenue / $roomNights, 2) : (float)($window['adr'] ?? 0),
            'aov' => $orders > 0 ? round($revenue / $orders, 2) : (float)($window['aov'] ?? 0),
            'trend_adjustment_percent' => round(($factor - 1) * 100, 2),
        ];
    }

    /**
     * @param array<string, mixed> $forecast7
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyForecast(array $forecast7, float $trend): array
    {
        $daily = [];
        $baseRevenue = ((float)($forecast7['revenue'] ?? 0)) / 7;
        $baseRoomNights = ((float)($forecast7['room_nights'] ?? 0)) / 7;
        $baseOrders = ((float)($forecast7['orders'] ?? 0)) / 7;
        $today = strtotime(date('Y-m-d'));
        for ($i = 1; $i <= 7; $i++) {
            $factor = 1 + $trend * ($i - 4) / 28;
            $roomNights = max(0, $baseRoomNights * $factor);
            $orders = max(0, $baseOrders * $factor);
            $revenue = max(0, $baseRevenue * $factor);
            $daily[] = [
                'date' => date('Y-m-d', (int)$today + 86400 * $i),
                'revenue' => round($revenue, 2),
                'room_nights' => round($roomNights, 0),
                'orders' => round($orders, 0),
                'adr' => $roomNights > 0 ? round($revenue / $roomNights, 2) : 0.0,
            ];
        }
        return $daily;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @return array<int, array<string, mixed>>
     */
    private function evaluateGaps(array $product, array $localSources): array
    {
        $indexed = [];
        foreach ($localSources as $source) {
            $indexed[(string)$source['source']] = $source;
        }

        $gaps = [];
        foreach (($product['rules'] ?? []) as $rule) {
            $table = (string)$rule['table'];
            $source = $indexed[$table] ?? null;
            if (!$source || ($source['status'] ?? '') === 'missing_table') {
                $gaps[] = $this->gap($rule, 'missing_table', '缺少数据表：' . $table);
                continue;
            }

            $missingFields = array_values(array_intersect((array)$rule['fields'], (array)($source['fields_missing'] ?? [])));
            if ($missingFields) {
                $gaps[] = $this->gap($rule, 'missing_fields', '缺少字段：' . implode('、', $missingFields), $missingFields);
                continue;
            }

            $minCount = (int)($rule['min_count'] ?? 0);
            $count = (int)($source['count'] ?? 0);
            if ($count < $minCount) {
                $gaps[] = $this->gap($rule, 'insufficient_rows', '样本量不足：当前 ' . $count . ' 条，至少需要 ' . $minCount . ' 条');
            }
        }

        return $gaps;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function gap(array $rule, string $type, string $reason, array $fields = []): array
    {
        return [
            'type' => $type,
            'table' => (string)($rule['table'] ?? ''),
            'label' => (string)($rule['label'] ?? ''),
            'fields' => $fields ?: (array)($rule['fields'] ?? []),
            'reason' => $reason,
            'collect_from' => (string)($rule['collect_from'] ?? ''),
            'priority' => 'high',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOpenAiConfig(string $modelKey): array
    {
        $config = AiModelConfig::where('model_key', $modelKey)->where('is_enabled', 1)->find();
        if (!$config) {
            throw new RuntimeException('缺少 OpenAI 配置：请进入“系统设置 > AI模型配置”配置并启用 openai_fast。', 422);
        }

        $baseUrl = rtrim(trim((string)$config->base_url), '/');
        $modelName = trim((string)$config->model_name);
        if ($baseUrl === '' || stripos($baseUrl, 'api.openai.com') === false) {
            throw new RuntimeException('openai_fast 必须使用 OpenAI Responses API 地址：https://api.openai.com/v1。', 422);
        }
        if ($modelName === '') {
            throw new RuntimeException('OpenAI 模型名称为空，请进入“系统设置 > AI模型配置”补充模型名称。', 422);
        }
        if (trim((string)$config->api_key_encrypted) === '') {
            throw new RuntimeException('OpenAI API Key 为空，请进入“系统设置 > AI模型配置”重新保存 openai_fast。', 422);
        }

        $secret = trim((string)env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            throw new RuntimeException('AI_CONFIG_SECRET 未配置，无法读取 openai_fast 的密钥。', 422);
        }
        $apiKey = AiModelConfig::decryptApiKey((string)$config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            throw new RuntimeException('openai_fast API Key 解密失败，请确认 AI_CONFIG_SECRET 与保存密钥时一致。', 422);
        }

        return [
            'model_key' => $modelKey,
            'model' => $modelName,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function callOpenAiWebSearch(array $config, array $product, array $localSources, array $gaps, array $businessForecast): array
    {
        $lastMessage = '';
        foreach (['web_search', 'web_search_preview'] as $toolType) {
            $payload = $this->openAiResponsesPayload($config, $product, $localSources, $gaps, $businessForecast, $toolType);
            $response = $this->sendOpenAiResponsesRequest($config, $payload);
            $status = (int)$response['status'];
            $data = $response['data'];

            if ($status >= 200 && $status < 300) {
                $text = $this->extractOutputText($data);
                $parsed = json_decode($this->extractJsonText($text), true);
                if (!is_array($parsed)) {
                    $parsed = [
                        'summary' => $text,
                        'forecast_assumptions' => [],
                        'key_metrics' => [],
                        'risk_signals' => ['OpenAI 未返回结构化 JSON，已保留原始文本。'],
                        'recommended_actions' => [],
                        'data_gaps' => [],
                        'confidence_note' => '',
                        'next_review_date' => '',
                    ];
                }

                return [
                    'result' => $parsed,
                    'web_sources' => $this->extractWebSources($data),
                    'generation_mode' => 'openai_web_search',
                ];
            }

            $lastMessage = (string)($data['error']['message'] ?? ('OpenAI Responses API HTTP ' . $status));
            if ($toolType === 'web_search' && preg_match('/web_search|tool/i', $lastMessage)) {
                continue;
            }
            break;
        }

        throw new RuntimeException('OpenAI 联网检索失败：' . $this->sanitize($lastMessage), 502);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function callConfiguredModel(string $modelKey, array $product, array $localSources, array $gaps, array $businessForecast): array
    {
        try {
            $messages = [
                [
                    'role' => 'system',
                    'content' => '你是酒店 OTA 收益管理预测分析师。必须基于本地经营数据和给定预测基线输出经营预测、风险信号和可执行运营动作；不得编造本地不存在的数据，不输出开发实现方案，不写知识库。输出必须是 JSON。',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($product, $localSources, $gaps, $businessForecast, false),
                ],
            ];
            $result = (new LlmClient())->createJsonResponse($messages, $this->resultSchema(), $modelKey);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), $this->llmErrorCode($e->getMessage()));
        }

        $result['forecast_assumptions'] = array_values(array_unique(array_merge(
            $this->stringList($result['forecast_assumptions'] ?? []),
            [(string)($businessForecast['method'] ?? '本地经营预测基线')]
        )));

        return [
            'result' => $result,
            'web_sources' => [],
            'generation_mode' => 'deepseek_model',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function openAiResponsesPayload(array $config, array $product, array $localSources, array $gaps, array $businessForecast, string $toolType): array
    {
        $schema = $this->resultSchema();
        unset($schema['x-governance']);

        return [
            'model' => $config['model'],
            'tools' => [
                [
                    'type' => $toolType,
                    'search_context_size' => 'medium',
                    'user_location' => [
                        'type' => 'approximate',
                        'country' => 'CN',
                        'timezone' => 'Asia/Shanghai',
                    ],
                ],
            ],
            'tool_choice' => 'required',
            'include' => ['web_search_call.action.sources'],
            'input' => [
                [
                    'role' => 'system',
                    'content' => '你是酒店 OTA 收益管理预测分析师。必须区分本地已有经营数据、联网资料和缺失数据；输出经营预测、风险信号和可执行运营动作，不输出开发实现方案，不写知识库。输出必须是 JSON。',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($product, $localSources, $gaps, $businessForecast, true),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'revenue_business_prediction',
                    'schema' => $schema,
                    'strict' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    private function sendOpenAiResponsesRequest(array $config, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('当前 PHP 未启用 curl 扩展，无法调用 OpenAI Responses API。', 500);
        }

        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($rawPayload === false) {
            throw new RuntimeException('OpenAI 请求体编码失败：' . json_last_error_msg(), 500);
        }

        $ch = curl_init(rtrim((string)$config['base_url'], '/') . '/responses');
        if ($ch === false) {
            throw new RuntimeException('无法初始化 OpenAI Responses API 请求。', 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $rawPayload,
            CURLOPT_TIMEOUT => 90,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OpenAI 联网检索请求失败：' . $this->sanitize($error ?: 'network error'), 502);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI 返回内容不是有效 JSON。', 502);
        }

        return ['status' => $status, 'data' => $data];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     */
    private function buildPrompt(array $product, array $localSources, array $gaps, array $businessForecast, bool $requiresWebSources): string
    {
        $sourceRule = $requiresWebSources
            ? '引用来源必须来自联网检索，并返回可点击来源。'
            : '当前默认使用 DeepSeek 配置模型，不要求联网引用；不得编造网页来源或假链接。';

        return implode("\n\n", [
            '产品方向：' . $product['name'],
            '系统落点：' . $product['module'],
            '研究关键词：' . $product['query'],
            'AI任务：基于本地经营数据和预测基线，输出未来7天与30天的酒店经营预测、风险信号、价格/渠道/运营动作。不要输出代码实现方案，不要写入知识库。',
            '本地已有信息摘要：' . json_encode($localSources, JSON_UNESCAPED_UNICODE),
            '本地经营预测基线：' . json_encode($businessForecast, JSON_UNESCAPED_UNICODE),
            '本地缺口清单：' . json_encode($gaps, JSON_UNESCAPED_UNICODE),
            '请输出经营预测结果。要求：1. ' . $sourceRule . ' 2. 对本地缺失数据只列补数要求，不假设已经存在；3. 所有建议必须能直接服务酒店生意，如调价、控房、投放、活动、点评整改或补数；4. 如果本地样本不足，必须明确置信度限制。',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultSchema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'x-governance' => [
                'module' => 'revenue_research',
                'scenario' => 'business_prediction',
                'prompt_version' => 'revenue_research.business_prediction.v1',
                'decision_impact' => 'operational',
                'knowledge_sources' => ['local_sources', 'business_forecast', 'data_gaps'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'forecast_assumptions' => $stringArray,
                'key_metrics' => $stringArray,
                'risk_signals' => $stringArray,
                'recommended_actions' => $stringArray,
                'data_gaps' => $stringArray,
                'confidence_note' => ['type' => 'string'],
                'next_review_date' => ['type' => 'string'],
            ],
            'required' => ['summary', 'forecast_assumptions', 'key_metrics', 'risk_signals', 'recommended_actions', 'data_gaps', 'confidence_note', 'next_review_date'],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function normalizeAiResult(array $result, array $product, array $gaps, array $businessForecast): array
    {
        $forecast7 = (array)($businessForecast['forecast_7d'] ?? []);
        $forecast30 = (array)($businessForecast['forecast_30d'] ?? []);
        $baselineMetrics = [];
        if ($forecast7) {
            $baselineMetrics[] = '未来7天预测收入约 ' . round((float)($forecast7['revenue'] ?? 0), 2) . ' 元，间夜约 ' . round((float)($forecast7['room_nights'] ?? 0)) . '，ADR 约 ' . round((float)($forecast7['adr'] ?? 0), 2) . ' 元。';
        }
        if ($forecast30) {
            $baselineMetrics[] = '未来30天预测收入约 ' . round((float)($forecast30['revenue'] ?? 0), 2) . ' 元，间夜约 ' . round((float)($forecast30['room_nights'] ?? 0)) . '，ADR 约 ' . round((float)($forecast30['adr'] ?? 0), 2) . ' 元。';
        }
        $gapTexts = array_map(static fn(array $gap): string => (string)$gap['reason'], $gaps);

        return [
            'title' => $product['name'] . '经营预测',
            'summary' => (string)($result['summary'] ?? ($businessForecast['message'] ?? '已生成经营预测。')),
            'forecast_assumptions' => $this->stringList($result['forecast_assumptions'] ?? []),
            'key_metrics' => array_values(array_unique(array_merge($baselineMetrics, $this->stringList($result['key_metrics'] ?? [])))),
            'risk_signals' => array_values(array_unique(array_merge(
                $this->stringList($businessForecast['risk_signals'] ?? []),
                $this->stringList($result['risk_signals'] ?? [])
            ))),
            'recommended_actions' => $this->stringList($result['recommended_actions'] ?? []),
            'data_gaps' => array_values(array_unique(array_merge($this->stringList($result['data_gaps'] ?? []), $gapTexts))),
            'confidence_note' => (string)($result['confidence_note'] ?? ('预测置信度：' . (string)($businessForecast['confidence'] ?? 'unknown'))),
            'next_review_date' => (string)($result['next_review_date'] ?? date('Y-m-d', strtotime('+1 day'))),
            'module' => (string)$product['module'],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function knowledgeKeywords(array $product): array
    {
        return array_values(array_unique(array_filter([
            (string)$product['name'],
            '收益管理',
            'OTA',
            '酒店',
        ])));
    }

    /**
     * @param array<string, bool> $columns
     * @param array<int, int> $hotelIds
     */
    private function scopedQuery(string $table, array $columns, array $hotelIds)
    {
        $query = Db::name($table);
        if (!$hotelIds) {
            return $query;
        }
        if ($table === 'online_daily_data' && isset($columns['system_hotel_id'])) {
            return $query->whereIn('system_hotel_id', $hotelIds);
        }
        if (isset($columns['hotel_id'])) {
            return $query->whereIn('hotel_id', $hotelIds);
        }
        return $query;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        }
        return $cache[$table];
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!$this->tableExists($table)) {
            $cache[$table] = [];
            return [];
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }

    /**
     * @param mixed $query
     * @param array<int, string> $fields
     * @param array<int, string> $keywords
     */
    private function applyKeywordRawWhere($query, array $fields, array $keywords, string $prefix): void
    {
        $parts = [];
        $bind = [];
        foreach (array_values($keywords) as $index => $keyword) {
            $fieldParts = [];
            foreach ($fields as $field) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                    continue;
                }
                $name = $prefix . '_' . $field . '_' . $index;
                $fieldParts[] = '`' . $field . '` LIKE :' . $name;
                $bind[$name] = '%' . $keyword . '%';
            }
            if ($fieldParts) {
                $parts[] = '(' . implode(' OR ', $fieldParts) . ')';
            }
        }

        if ($parts) {
            $query->whereRaw('(' . implode(' OR ', $parts) . ')', $bind);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, string>>
     */
    private function extractWebSources(array $data): array
    {
        $sources = [];
        $add = static function ($title, $url) use (&$sources): void {
            $url = trim((string)$url);
            if ($url === '' || isset($sources[$url])) {
                return;
            }
            $sources[$url] = [
                'title' => trim((string)$title) ?: $url,
                'url' => $url,
            ];
        };

        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['action']['sources'] ?? []) as $source) {
                if (is_array($source)) {
                    $add($source['title'] ?? '', $source['url'] ?? '');
                }
            }
            foreach (($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }
                foreach (($content['annotations'] ?? []) as $annotation) {
                    if (is_array($annotation) && ($annotation['type'] ?? '') === 'url_citation') {
                        $add($annotation['title'] ?? '', $annotation['url'] ?? '');
                    }
                }
            }
        }

        return array_slice(array_values($sources), 0, 10);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractOutputText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }
        $parts = [];
        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function extractJsonText(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }

    private function numberValue($value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return 0.0;
        }
        $normalized = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
        return is_numeric($normalized) ? (float)$normalized : 0.0;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        $result = [];
        foreach ($value as $item) {
            $text = trim(is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE));
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }

    private function llmErrorCode(string $message): int
    {
        return preg_match('/配置|API Key|AI_CONFIG_SECRET|未找到|模型|启用|Base URL/u', $message) ? 422 : 502;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ****', $message) ?? $message;
        return mb_substr(trim($message), 0, 300);
    }
}
