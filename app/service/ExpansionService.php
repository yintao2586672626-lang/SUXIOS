<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

class ExpansionService
{
    private LlmClient $client;
    private AiDecisionQualityService $decisionQualityService;
    private bool $tableEnsured = false;

    private const TASKS = [
        '市场调研',
        '物业评估',
        '合同谈判',
        '装修筹建',
        '证照办理',
        'OTA上线',
        '运营交接',
    ];

    public function __construct(?LlmClient $client = null, ?AiDecisionQualityService $decisionQualityService = null)
    {
        $this->client = $client ?: new LlmClient();
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
    }

    public function evaluateMarket(array $input): array
    {
        $city = $this->requiredText($input, 'city', '城市不能为空');
        $businessArea = $this->text($input, ['business_area', 'district', 'area']);
        $propertyArea = $this->requiredNumber($input, 'property_area', '物业面积不能为空');
        $estimatedRent = $this->requiredNumber($input, 'estimated_rent', '预估租金不能为空');
        $targetRoomCount = (int)$this->requiredNumber($input, 'target_room_count', '目标房量不能为空');
        $cityTier = $this->text($input, ['city_tier'], '');
        $decorationLevel = $this->text($input, ['decoration_level'], '');
        $primaryCustomer = $this->text($input, ['primary_customer'], '');
        $secondaryCustomer = $this->text($input, ['secondary_customer'], '');
        $targetCustomer = $this->text($input, ['target_customer'], implode('+', array_filter([$primaryCustomer, $secondaryCustomer])));
        if (trim((string)($input['primary_customer'] ?? '')) === '' && $targetCustomer !== '') {
            $customerParts = preg_split('/[+＋\/、,，]/u', $targetCustomer) ?: [];
            $customerParts = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $customerParts)));
            $primaryCustomer = $customerParts[0] ?? $primaryCustomer;
            $secondaryCustomer = $customerParts[1] ?? $secondaryCustomer;
        }
        $leaseYears = $this->optionalNumber($input, 'lease_years');
        $rentFreeMonths = $this->optionalNumber($input, 'rent_free_months');
        $depositMonths = $this->optionalNumber($input, 'deposit_months');
        $transferFee = $this->optionalNumber($input, 'transfer_fee');
        $fitoutBudget = $this->optionalNumber($input, 'fitout_budget');
        $expectedAdr = $this->optionalNumber($input, 'expected_adr');
        $expectedOccupancyRate = $this->optionalNumber($input, 'expected_occupancy_rate');
        $competitorCount = $this->optionalNumber($input, 'competitor_count');
        $otaMarketPenetrationRate = $this->optionalNumber($input, 'ota_market_penetration_rate');
        if ($otaMarketPenetrationRate === null) {
            $otaMarketPenetrationRate = $this->optionalNumber($input, 'ota_platform_market_penetration_rate');
        }
        $parkingSpaces = $this->optionalNumber($input, 'parking_spaces');
        $assetType = $this->text($input, ['asset_type'], '');
        $operationModel = $this->text($input, ['operation_model'], '');
        $contractStatus = $this->text($input, ['contract_status'], '');

        if ($propertyArea <= 0) {
            throw new InvalidArgumentException('物业面积必须大于0');
        }
        if ($estimatedRent < 0) {
            throw new InvalidArgumentException('预估租金不能为负数');
        }
        if ($targetRoomCount <= 0) {
            throw new InvalidArgumentException('目标房量必须大于0');
        }

        $score = 62;
        $scoreBreakdown = [[
            'label' => '基础分',
            'score_change' => 62,
            'raw_score_after' => 62,
            'reason' => '按城市酒店扩张初筛模型给出基础可评估分。',
        ]];
        $addScore = static function (string $label, int $change, string $reason) use (&$score, &$scoreBreakdown): void {
            $score += $change;
            $scoreBreakdown[] = [
                'label' => $label,
                'score_change' => $change,
                'raw_score_after' => (int)round($score),
                'reason' => $reason,
            ];
        };
        $riskPoints = [];
        $reasons = [];
        $missing = [];
        foreach ([
            '租期' => $leaseYears,
            '目标ADR' => $expectedAdr,
            '目标入住率' => $expectedOccupancyRate,
            '周边竞品数量' => $competitorCount,
        ] as $label => $value) {
            if ($value === null) {
                $missing[] = $label;
            }
        }
        foreach ([
            '装修定位' => $decorationLevel,
            '目标客群' => $targetCustomer,
            '物业形态' => $assetType,
            '经营模式' => $operationModel,
            '合同状态' => $contractStatus,
        ] as $label => $value) {
            if (trim($value) === '') {
                $missing[] = $label;
            }
        }
        $areaPerRoom = $propertyArea / max(1, $targetRoomCount);
        $rentPerRoom = $estimatedRent / max(1, $targetRoomCount);
        $rentPerSquare = $estimatedRent / max(1, $propertyArea);

        if ($businessArea === '') {
            $addScore('商圈信息', -8, '商圈信息为空，无法判断客源半径和竞品密度。');
            $missing[] = '商圈/区域';
            $riskPoints[] = '商圈信息为空，无法判断客源半径和竞品密度';
        } else {
            $reason = "已录入{$city}{$businessArea}，可进入实地商圈复核";
            $addScore('商圈信息', 8, $reason);
            $reasons[] = $reason;
        }

        if ($targetRoomCount >= 60 && $rentPerRoom <= 2600) {
            $reason = '房量大于60且单房月租处于可控区间';
            $addScore('房量与单房租金', 16, $reason);
            $reasons[] = $reason;
        } elseif ($targetRoomCount < 40) {
            $addScore('房量与单房租金', -18, '目标房量低于40间，规模效率和人员摊薄压力较高。');
            $riskPoints[] = '目标房量低于40间，规模效率和人员摊薄压力较高';
        } else {
            $reason = '房量处于可评估区间，需结合平面落位复核';
            $addScore('房量与单房租金', 4, $reason);
            $reasons[] = $reason;
        }

        if ($propertyArea < 1000 || $areaPerRoom < 18) {
            $addScore('面积与房型效率', -16, '物业面积或单房面积偏小，可能影响房型落位和公共区配置。');
            $riskPoints[] = '物业面积或单房面积偏小，可能影响房型落位和公共区配置';
        } elseif ($areaPerRoom <= 48) {
            $reason = '单房建筑面积匹配常规中端酒店模型';
            $addScore('面积与房型效率', 8, $reason);
            $reasons[] = $reason;
        } else {
            $addScore('面积与房型效率', -4, '单房建筑面积偏大，需关注坪效损耗。');
            $riskPoints[] = '单房建筑面积偏大，需关注坪效损耗';
        }

        if ($rentPerRoom > 3200 || $rentPerSquare > 85) {
            $addScore('租金压力', -22, '租金压力偏高，开业爬坡期现金流安全边际不足。');
            $riskPoints[] = '租金压力偏高，开业爬坡期现金流安全边际不足';
        } elseif ($rentPerRoom > 2600 || $rentPerSquare > 65) {
            $addScore('租金压力', -10, '租金略高，建议争取免租期、递增上限或装修补贴。');
            $riskPoints[] = '租金略高，建议争取免租期、递增上限或装修补贴';
        } else {
            $reason = '租金水平未触发高压阈值';
            $addScore('租金压力', 10, $reason);
            $reasons[] = $reason;
        }

        if (str_contains($targetCustomer, '商务') || str_contains($targetCustomer, '差旅')) {
            $reason = '目标客群适合城市商旅型价格带';
            $addScore('目标客群', 4, $reason);
            $reasons[] = $reason;
        }
        if (str_contains($decorationLevel, '高端')) {
            $addScore('装修定位', -4, '装修档次偏高时需控制单房投入，避免回收周期拉长。');
            $riskPoints[] = '装修档次偏高时需控制单房投入，避免回收周期拉长';
        }
        if ($leaseYears !== null && $leaseYears < 6) {
            $addScore('租期条件', -8, '租期低于6年，装修摊销和回收周期存在压力。');
            $riskPoints[] = '租期低于6年，装修摊销和回收周期存在压力';
        } elseif ($leaseYears !== null && $leaseYears >= 8) {
            $reason = '租期具备中长期经营稳定性';
            $addScore('租期条件', 4, $reason);
            $reasons[] = $reason;
        }
        if ($rentFreeMonths !== null && $rentFreeMonths < 3) {
            $addScore('免租期', -5, '免租期偏短，筹建期现金流缓冲不足。');
            $riskPoints[] = '免租期偏短，筹建期现金流缓冲不足';
        } elseif ($rentFreeMonths !== null && $rentFreeMonths >= 4) {
            $reason = '免租期可覆盖部分筹建爬坡压力';
            $addScore('免租期', 4, $reason);
            $reasons[] = $reason;
        }
        if ($fitoutBudget !== null && $targetRoomCount > 0) {
            $fitoutPerRoom = $fitoutBudget * 10000 / max(1, $targetRoomCount);
            if ($fitoutPerRoom > 90000) {
                $addScore('装修投入', -6, '单房装修投入偏高，需压缩非必要配置。');
                $riskPoints[] = '单房装修投入偏高，需压缩非必要配置';
            } elseif ($fitoutPerRoom > 0 && $fitoutPerRoom <= 65000) {
                $reason = '单房装修投入处于较可控区间';
                $addScore('装修投入', 3, $reason);
                $reasons[] = $reason;
            }
        }
        if ($depositMonths !== null && $depositMonths > 6) {
            $addScore('押金条件', -4, '押金月数偏高，会抬升前期资金占用。');
            $riskPoints[] = '押金月数偏高，会抬升前期资金占用';
        }
        if ($transferFee !== null && $transferFee > 0 && $targetRoomCount > 0 && ($transferFee * 10000 / max(1, $targetRoomCount)) > 50000) {
            $addScore('转让费', -5, '转让费摊到单房后偏高，需重新核算回本周期。');
            $riskPoints[] = '转让费摊到单房后偏高，需重新核算回本周期';
        }
        if ($expectedAdr !== null && $expectedAdr > 0) {
            if ($expectedAdr >= $rentPerRoom / 9) {
                $reason = '目标ADR对租金承压具备一定覆盖能力';
                $addScore('ADR承压能力', 4, $reason);
                $reasons[] = $reason;
            } else {
                $addScore('ADR承压能力', -4, '目标ADR偏低，需复核价格带与租金匹配度。');
                $riskPoints[] = '目标ADR偏低，需复核价格带与租金匹配度';
            }
        }
        if ($expectedOccupancyRate !== null && $expectedOccupancyRate > 0) {
            if ($expectedOccupancyRate < 65) {
                $addScore('入住率假设', -5, '目标入住率低于65%，项目爬坡安全边际不足。');
                $riskPoints[] = '目标入住率低于65%，项目爬坡安全边际不足';
            } elseif ($expectedOccupancyRate >= 78) {
                $reason = '目标入住率达到成熟门店经营区间';
                $addScore('入住率假设', 3, $reason);
                $reasons[] = $reason;
            }
        }
        if ($competitorCount !== null && $competitorCount > 25) {
            $addScore('竞品压力', -5, '周边竞品数量偏多，需强化差异化和价格带验证。');
            $riskPoints[] = '周边竞品数量偏多，需强化差异化和价格带验证';
        }
        if ($otaMarketPenetrationRate === null) {
            $addScore('OTA渗透率', 0, '未提供OTA平台市场渗透率，本项暂不加分。');
            $missing[] = 'OTA平台市场渗透率';
        } elseif ($otaMarketPenetrationRate < 35) {
            $addScore('OTA渗透率', -6, 'OTA平台市场渗透率偏低，线上自然流量和转化基础不足。');
            $riskPoints[] = 'OTA平台市场渗透率偏低，线上自然流量和转化基础不足';
        } elseif ($otaMarketPenetrationRate >= 60) {
            $reason = 'OTA平台市场渗透率较高，线上获客基础具备验证价值';
            $addScore('OTA渗透率', 5, $reason);
            $reasons[] = $reason;
        } else {
            $reason = 'OTA平台市场渗透率处于可验证区间，需结合竞品价格带复核';
            $addScore('OTA渗透率', 2, $reason);
            $reasons[] = $reason;
        }
        if ($parkingSpaces !== null && $parkingSpaces > 0 && $targetRoomCount > 0 && $parkingSpaces / $targetRoomCount >= 0.25) {
            $reason = '停车配比可增强自驾及商务客群承接';
            $addScore('停车配比', 2, $reason);
            $reasons[] = $reason;
        }

        $rawScore = (int)round($score);
        $score = $this->score($score);
        $missing = array_values(array_unique($missing));
        $hasEvidenceGaps = $missing !== [];
        $riskLevel = $this->riskLevel($score, $riskPoints);
        $priceBand = $this->priceBand($decorationLevel, $rentPerRoom);
        $competition = $businessArea === '' ? '待补充商圈后判断' : ($score >= 78 ? '中等竞争，可通过产品差异化切入' : '竞争压力偏高，需补充竞品价格与点评数据');

        $result = [
            'market_heat_score' => $score,
            'market_heat_score_formula' => [
                'base_score' => 62,
                'raw_score' => $rawScore,
                'final_score' => $score,
                'cap_rule' => '0-100封顶/保底',
                'semantics' => '基于录入条件和预设阈值的规则初筛指数，不是真实市场热度或投资成功概率',
            ],
            'score_type' => 'rule_screening_index',
            'source' => 'user_input_and_rule_thresholds',
            'decision_ready' => false,
            'manual_unverified' => true,
            'scope_note' => '规则指数只反映当前录入条件；缺少竞品、OTA、租约或经营证据时不形成投资结论。',
            'market_heat_score_breakdown' => $scoreBreakdown,
            'supply_competition_strength' => $competition,
            'price_band_suggestion' => $priceBand,
            'investment_risk_level' => $riskLevel,
            'recommended_property_type' => $this->propertyType($targetRoomCount, $areaPerRoom),
            'ai_operation_suggestions' => [
                $score >= 75 ? '可进入物业尽调和竞品实采，重点验证周边同档酒店ADR与出租率' : '先重谈租金或调整房量模型，再进入投资立项',
                $businessArea === '' ? '补充商圈与OTA平台渗透率数据' : '用3公里竞品价格、评分、点评量校准价格带',
                '将租金、免租期、装修单房投入拆成保守/基准/乐观三套现金流',
            ],
            'not_recommended_risks' => array_values(array_unique($riskPoints ?: ['当前证据不足，未形成可判定的风险清单；需补充竞品、客流与租约证据'])),
            'metrics' => [
                'area_per_room' => round($areaPerRoom, 1),
                'rent_per_room' => round($rentPerRoom, 0),
                'rent_per_square' => round($rentPerSquare, 1),
            ],
            'investment_conditions' => [
                ['label' => '城市线级', 'value' => $cityTier !== '' ? $cityTier : '待补充'],
                ['label' => '物业形态', 'value' => $assetType !== '' ? $assetType : '待补充'],
                ['label' => '经营模式', 'value' => $operationModel !== '' ? $operationModel : '待补充'],
                ['label' => '合同状态', 'value' => $contractStatus !== '' ? $contractStatus : '待补充'],
                ['label' => '主客群', 'value' => $primaryCustomer !== '' ? $primaryCustomer : '待补充'],
                ['label' => '辅助客群', 'value' => $secondaryCustomer !== '' ? $secondaryCustomer : '待补充'],
                ['label' => '租期', 'value' => $leaseYears !== null ? round($leaseYears, 1) . '年' : '待补充'],
                ['label' => '免租期', 'value' => $rentFreeMonths !== null ? round($rentFreeMonths, 1) . '个月' : '待补充'],
                ['label' => '押金', 'value' => $depositMonths !== null ? round($depositMonths, 1) . '个月' : '待补充'],
                ['label' => '转让费', 'value' => $transferFee !== null ? round($transferFee, 1) . '万元' : '待补充'],
                ['label' => '装修预算', 'value' => $fitoutBudget !== null ? round($fitoutBudget, 1) . '万元' : '待补充'],
                ['label' => '目标ADR', 'value' => $expectedAdr !== null ? round($expectedAdr, 0) . '元' : '待补充'],
                ['label' => '目标入住率', 'value' => $expectedOccupancyRate !== null ? round($expectedOccupancyRate, 1) . '%' : '待补充'],
                ['label' => '周边竞品', 'value' => $competitorCount !== null ? round($competitorCount, 0) . '家' : '待补充'],
                ['label' => 'OTA平台市场渗透率', 'value' => $otaMarketPenetrationRate !== null ? round($otaMarketPenetrationRate, 1) . '%' : '待补充'],
            ],
            'decision' => $hasEvidenceGaps
                ? '数据不足，仅可规则初筛'
                : ($score >= 80 ? '规则初筛偏积极，待人工尽调' : ($score >= 65 ? '规则初筛中性，待人工尽调' : '规则初筛偏谨慎，待人工尽调')),
            'data_status' => $this->dataStatus($missing),
            'rule_reasons' => array_values(array_unique($reasons)),
        ];

        $modelKey = trim((string)($input['model_key'] ?? $input['modelKey'] ?? 'deepseek_v4_default'));
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        $aiEvaluation = $this->buildMarketAiEvaluation($input, $result, $modelKey);
        if ($hasEvidenceGaps) {
            $gapText = implode('、', $missing);
            $aiEvaluation['summary'] = '当前缺少' . $gapText . '；模型或规则输出只用于安排补证，不形成投资结论。';
            $aiEvaluation['decision'] = '数据不足，仅可规则初筛';
            $aiEvaluation['market_judgement']['decision'] = '数据不足，仅可规则初筛';
            $aiEvaluation['assumptions'] = array_values(array_unique(array_merge(
                (array)($aiEvaluation['assumptions'] ?? []),
                ['缺失字段：' . $gapText, '规则初筛指数不是真实市场热度或投资成功概率']
            )));
        }
        $result['ai_evaluation'] = $aiEvaluation;
        if ($aiEvaluation['source'] === 'llm') {
            $result['ai_operation_suggestions'] = array_values(array_filter(array_map(
                static fn(array $item): string => trim((string)($item['detail'] ?? $item['title'] ?? '')),
                $aiEvaluation['recommendations'] ?? []
            ))) ?: $result['ai_operation_suggestions'];
        }

        return $result;
    }

    public function buildBenchmarkModel(array $input): array
    {
        $city = $this->requiredText($input, 'city', '城市不能为空');
        $businessArea = $this->text($input, ['business_area', 'district', 'area']);
        $targetPriceBand = $this->requiredText($input, 'target_price_band', '目标价格带不能为空');
        $hotelType = $this->requiredText($input, 'hotel_type', '酒店类型不能为空');
        $targetRoomCount = (int)$this->requiredNumber($input, 'target_room_count', '目标房量不能为空');

        if ($targetRoomCount <= 0) {
            throw new InvalidArgumentException('目标房量必须大于0');
        }

        $missing = $businessArea === '' ? ['商圈'] : [];
        $price = $this->priceRange($targetPriceBand);
        $baseHeat = $targetRoomCount >= 70 ? 86 : ($targetRoomCount >= 45 ? 78 : 66);
        $detailInputs = [
            'competitor_count' => $this->optionalNumberByKeys($input, ['competitor_count', 'benchmark_competitor_count']),
            'avg_competitor_price' => $this->optionalNumberByKeys($input, ['avg_competitor_price', 'competitor_avg_price', 'average_competitor_price']),
            'avg_competitor_score' => $this->optionalNumberByKeys($input, ['avg_competitor_score', 'competitor_avg_score', 'average_competitor_score']),
            'avg_review_count' => $this->optionalNumberByKeys($input, ['avg_review_count', 'competitor_avg_review_count', 'average_review_count']),
            'ota_heat_index' => $this->optionalNumberByKeys($input, ['ota_heat_index', 'benchmark_ota_heat_index']),
            'traffic_radius_km' => $this->optionalNumberByKeys($input, ['traffic_radius_km', 'sample_radius_km', 'competitor_radius_km']),
        ];
        $filledDetailKeys = array_keys(array_filter($detailInputs, static fn(?float $value): bool => $value !== null));
        $detailLabels = [
            'competitor_count' => '竞品数量',
            'avg_competitor_price' => '竞品均价',
            'avg_competitor_score' => '竞品均分',
            'avg_review_count' => '平均点评量',
            'ota_heat_index' => 'OTA热度指数',
            'traffic_radius_km' => '采样半径',
        ];
        $estimatedFields = array_values(array_map(
            static fn(string $key): string => $detailLabels[$key] ?? $key,
            array_diff(array_keys($detailInputs), $filledDetailKeys)
        ));
        $hasCompleteCompetitorInputs = count($filledDetailKeys) === count($detailInputs);
        $benchmarkSource = $hasCompleteCompetitorInputs ? 'user_provided_competitor_metrics' : 'synthetic_rule_scenario';
        $competitorCount = $detailInputs['competitor_count'] !== null
            ? (int)max(1, round($detailInputs['competitor_count']))
            : null;
        $avgCompetitorPrice = $detailInputs['avg_competitor_price'] !== null
            ? (int)max(1, round($detailInputs['avg_competitor_price']))
            : null;
        $avgCompetitorScore = $detailInputs['avg_competitor_score'] !== null
            ? round(max(1, min(5, $detailInputs['avg_competitor_score'])), 1)
            : null;
        $avgReviewCount = $detailInputs['avg_review_count'] !== null
            ? (int)max(1, round($detailInputs['avg_review_count']))
            : null;
        $otaHeatIndex = $detailInputs['ota_heat_index'] !== null
            ? (int)max(0, min(100, round($detailInputs['ota_heat_index'])))
            : null;
        $trafficRadiusKm = $detailInputs['traffic_radius_km'] !== null
            ? round(max(0.1, $detailInputs['traffic_radius_km']), 1)
            : null;
        $detailMetrics = [
            'competitor_count' => $competitorCount,
            'avg_competitor_price' => $avgCompetitorPrice,
            'avg_competitor_score' => $avgCompetitorScore,
            'avg_review_count' => $avgReviewCount,
            'ota_heat_index' => $otaHeatIndex,
            'traffic_radius_km' => $trafficRadiusKm,
            'data_completeness' => count($filledDetailKeys) . '/' . count($detailInputs),
            'estimated_fields' => $estimatedFields,
            'source' => $benchmarkSource,
        ];
        if ($hasCompleteCompetitorInputs) {
            $models = [
                [
                    'name' => '标杆模型A',
                    'score' => 4.8,
                    'price' => $price['mid'],
                    'room_count' => max($targetRoomCount, 70),
                    'heat' => min(95, $baseHeat + 5),
                    'selling_points' => ['核心房型少而清晰', '商务客源转化稳定', '点评关键词聚焦干净与便利'],
                    'learn_from' => '学习房型结构、点评运营和主力价格带稳定性',
                ],
                [
                    'name' => '标杆模型B',
                    'score' => 4.7,
                    'price' => max(1, $price['mid'] - 20),
                    'room_count' => max(50, $targetRoomCount - 8),
                    'heat' => $baseHeat,
                    'selling_points' => ['低波动价格策略', '渠道覆盖完整', '图片展示统一'],
                    'learn_from' => '学习渠道铺排、价格梯度和图片标准化',
                ],
                [
                    'name' => '标杆模型C',
                    'score' => 4.6,
                    'price' => $price['mid'] + 25,
                    'room_count' => max(40, $targetRoomCount + 10),
                    'heat' => max(60, $baseHeat - 6),
                    'selling_points' => ['差异化房型', '服务标签突出', '适合拉高ADR'],
                    'learn_from' => '学习服务标签和高价房型包装，不照搬成本结构',
                ],
            ];
            $distanceFactors = [0.6, 0.85, 1.15];
            $reviewFactors = [1.25, 1.0, 0.75];
            $models = array_map(function (array $model, int $index) use ($avgCompetitorPrice, $avgCompetitorScore, $avgReviewCount, $otaHeatIndex, $trafficRadiusKm, $competitorCount, $distanceFactors, $reviewFactors, $benchmarkSource): array {
                $priceGap = (int)round(((float)$model['price']) - $avgCompetitorPrice);
                $reviewCount = (int)max(1, round($avgReviewCount * ($reviewFactors[$index] ?? 1)));
                $distanceKm = round(max(0.1, $trafficRadiusKm * ($distanceFactors[$index] ?? 1)), 1);
                $scoreGap = round(((float)$model['score']) - $avgCompetitorScore, 1);
                $heatGap = (int)round(((float)$model['heat']) - $otaHeatIndex);
                $priceFit = 100 - min(45, abs($priceGap) / max(1, $avgCompetitorPrice) * 100);
                $scoreFit = 100 - min(35, abs($scoreGap) * 25);
                $heatFit = 100 - min(40, abs($heatGap));

                $model['source'] = $benchmarkSource;
                $model['distance_km'] = $distanceKm;
                $model['review_count'] = $reviewCount;
                $model['price_gap_to_market'] = $priceGap;
                $model['score_gap_to_market'] = $scoreGap;
                $model['heat_gap_to_market'] = $heatGap;
                $model['model_fit_score'] = $this->score($priceFit * 0.42 + $scoreFit * 0.33 + $heatFit * 0.25);
                $model['sample_basis'] = "{$trafficRadiusKm}公里内{$competitorCount}家竞品样本";

                return $model;
            }, $models, array_keys($models));
        } else {
            $models = [
                [
                    'name' => '情景模型A',
                    'price' => $price['mid'],
                    'room_count' => max($targetRoomCount, 70),
                    'selling_points' => ['精简主力房型', '以商务客群为目标假设', '围绕干净与便利设计点评运营'],
                    'learn_from' => '用于验证房型结构、点评运营和主力价格带假设',
                ],
                [
                    'name' => '情景模型B',
                    'price' => max(1, $price['mid'] - 20),
                    'room_count' => max(50, $targetRoomCount - 8),
                    'selling_points' => ['低波动价格策略', '目标渠道基础覆盖', '图片展示标准化'],
                    'learn_from' => '用于验证渠道铺排、价格梯度和图片标准化假设',
                ],
                [
                    'name' => '情景模型C',
                    'price' => $price['mid'] + 25,
                    'room_count' => max(40, $targetRoomCount + 10),
                    'selling_points' => ['差异化房型假设', '服务标签假设', '高ADR探索情景'],
                    'learn_from' => '用于验证服务标签和较高价格带，不代表真实竞品表现',
                ],
            ];
            $models = array_map(static function (array $model) use ($benchmarkSource): array {
                $model['source'] = $benchmarkSource;
                $model['score'] = null;
                $model['heat'] = null;
                $model['distance_km'] = null;
                $model['review_count'] = null;
                $model['price_gap_to_market'] = null;
                $model['score_gap_to_market'] = null;
                $model['heat_gap_to_market'] = null;
                $model['model_fit_score'] = null;
                $model['sample_basis'] = '基于用户输入及默认假设生成的规则情景，非真实竞品样本';
                $model['assumption_fields'] = [
                    'price' => '基于目标价格带生成的情景值',
                    'room_count' => '基于目标房量生成的情景值',
                ];

                return $model;
            }, $models);
        }

        $result = [
            'source' => $benchmarkSource,
            'position' => [
                'city' => $city,
                'business_area' => $businessArea,
                'target_price_band' => $targetPriceBand,
                'hotel_type' => $hotelType,
                'target_room_count' => $targetRoomCount,
                'detail_metrics' => $detailMetrics,
            ],
            'recommended_benchmarks' => $models,
            'copyable_strategies' => [
                'room_type' => $targetRoomCount >= 60 ? '保留2-3个主力房型，减少低频主题房占比' : '控制房型数量，优先保证标准大床/双床效率',
                'price' => "以{$price['low']}-{$price['high']}元作为挂牌主带，节假日上浮但保留低价引流房",
                'channel' => '携程/美团基础覆盖，重点维护高转化渠道的首图、权益和点评回复',
                'review' => '围绕卫生、隔音、交通、服务响应建立点评关键词',
                'image' => '首图突出房间真实尺度，补齐外立面、前台、卫浴和窗景',
                'service' => str_contains($hotelType, '商务') ? '强化发票、洗衣、延迟退房和安静楼层' : '强化入住指引、行李寄存和本地化推荐',
                'data' => $hasCompleteCompetitorInputs
                    ? "按{$trafficRadiusKm}公里、{$competitorCount}家竞品校准价格差、评分差和点评量，再确定主力标杆"
                    : '补充真实竞品数量、均价、评分、点评量、OTA热度和采样半径后，再校准主力标杆',
            ],
            'differentiation_suggestions' => [
                $businessArea === '' ? '补充商圈后再定义差异化锚点' : "围绕{$businessArea}的主要客源设计首图和权益",
                $targetRoomCount < 45 ? '小房量项目避免复制大店组织架构，优先做轻人效模型' : '用标准化房型提升清扫、人效和收益管理效率',
                $hasCompleteCompetitorInputs
                    ? '优先复核与竞品均价差在±30元、评分差在0.2分内的标杆样本'
                    : '补充真实竞品均价、评分和点评量后，再验证目标价格带',
                '选择一个强标签作为差异点，不同时堆叠过多卖点',
            ],
            'avoid_copying_points' => [
                '不要照搬真实酒店名称、装修造价和供应商配置',
                '不要复制超出自身房量承载能力的早餐、会议室或复杂服务',
                $hasCompleteCompetitorInputs
                    ? '不要用标杆高分直接推导本项目ADR，需结合租金和爬坡周期复核'
                    : '不要用情景模型直接推导本项目ADR，需补充真实竞品并结合租金和爬坡周期复核',
            ],
            'data_status' => array_merge($this->dataStatus($missing), [
                'source' => $benchmarkSource,
                'notice' => $hasCompleteCompetitorInputs
                    ? '当前竞品指标来自用户输入，仍需结合可追溯样本证据复核'
                    : '当前仅为基于用户输入及默认假设生成的规则情景，非真实竞品样本',
            ]),
        ];

        $modelKey = trim((string)($input['model_key'] ?? $input['modelKey'] ?? 'deepseek_v4_default'));
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        $result['ai_evaluation'] = $this->buildBenchmarkAiEvaluation($input, $result, $modelKey);

        return $result;
    }

    public function improveCollaboration(array $input): array
    {
        $projectName = $this->requiredText($input, 'project_name', '项目名称不能为空');
        $cityArea = $this->requiredText($input, 'city_area', '城市/区域不能为空');
        $currentStage = $this->requiredText($input, 'current_stage', '当前阶段不能为空');
        $owner = $this->requiredText($input, 'owner', '负责人不能为空');
        $expectedOnlineDate = $this->requiredText($input, 'expected_online_date', '预计上线时间不能为空');

        $today = new DateTimeImmutable(date('Y-m-d'));
        $onlineDate = $this->parseDate($expectedOnlineDate, '预计上线时间格式应为YYYY-MM-DD');
        $daysLeft = (int)$today->diff($onlineDate)->format('%r%a');
        $tasks = $this->normalizeTasks(is_array($input['tasks'] ?? null) ? $input['tasks'] : [], $currentStage, $today);

        $confirmedTasks = array_values(array_filter($tasks, fn (array $task): bool => ($task['is_observed'] ?? false) === true));
        $completed = count(array_filter($confirmedTasks, fn (array $task): bool => $task['status'] === '已完成'));
        $total = count($tasks);
        $confirmedTotal = count($confirmedTasks);
        $progress = $confirmedTotal > 0 ? (int)round($completed / $confirmedTotal * 100) : null;
        $riskPoints = [];

        foreach ($tasks as $task) {
            if ($task['status'] === '风险') {
                $riskPoints[] = $task['name'] . '已标记风险' . ($task['risk_note'] ? ': ' . $task['risk_note'] : '');
            }
            if ($task['status'] !== '已完成' && $task['due_date'] !== '') {
                $dueDate = $this->parseDate($task['due_date'], '任务截止时间格式应为YYYY-MM-DD');
                if ($dueDate < $today) {
                    $riskPoints[] = $task['name'] . '已逾期';
                }
            }
        }

        $criticalOpen = array_values(array_filter($tasks, fn (array $task): bool => in_array($task['name'], ['装修筹建', '证照办理', 'OTA上线'], true) && $task['status'] !== '已完成'));
        if ($daysLeft <= 15 && !empty($criticalOpen)) {
            $riskPoints[] = '距离上线不超过15天，装修/证照/OTA仍有关键节点未完成';
        } elseif ($daysLeft <= 30 && !empty($criticalOpen)) {
            $riskPoints[] = '临近上线，需日级跟踪装修、证照和OTA上线';
        }
        if ($daysLeft < 0) {
            $riskPoints[] = '预计上线时间已过期';
        }

        $riskLevel = $confirmedTotal === 0
            ? '待评估'
            : (!empty(array_filter($riskPoints, fn (string $item): bool => str_contains($item, '15天') || str_contains($item, '逾期') || str_contains($item, '已过期'))) ? '高风险' : (!empty($riskPoints) ? '中风险' : '未发现明确延误风险'));
        $nextTask = $this->nextTask($tasks);

        return [
            'project_overview' => [
                'project_name' => $projectName,
                'city_area' => $cityArea,
                'current_stage' => $currentStage,
                'owner' => $owner,
                'expected_online_date' => $expectedOnlineDate,
                'days_to_online' => $daysLeft,
            ],
            'task_board' => $tasks,
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'confirmed_total' => $confirmedTotal,
                'template_pending_count' => $total - $confirmedTotal,
                'percent' => $progress,
                'status_text' => $confirmedTotal > 0
                    ? "已确认完成{$completed}/{$confirmedTotal}项；另有" . ($total - $confirmedTotal) . '项模板待确认'
                    : '当前仅生成任务模板，尚无已确认进度',
            ],
            'delay_risk' => [
                'level' => $riskLevel,
                'points' => $riskPoints ?: ($confirmedTotal > 0
                    ? ['基于已确认任务，当前未发现明确延误信号；不等于已完成全部风险核验']
                    : ['任务状态、负责人和截止日期尚未由人工确认，暂不判断延误风险']),
            ],
            'next_actions' => [
                $nextTask ? '下一步优先推进：' . $nextTask['name'] . '，负责人：' . $nextTask['owner'] : '全部任务已完成，进入上线复盘',
                $riskLevel === '高风险' ? '启动日会机制，锁定证照、装修、OTA三个关键责任人' : '保持周级节奏，更新风险说明和截止时间',
                '所有风险任务需补充解决方案、截止时间和责任人',
            ],
            'data_status' => $this->dataStatus([]),
        ];
    }

    public function buildProjectReadiness(string $recordType, array $input, array $result): array
    {
        return (new ExpansionProjectReadinessService())->build($recordType, $input, $result);
    }

    public function readinessSummaryFromRows(array $rows): array
    {
        return (new ExpansionProjectReadinessService())->summaryFromRows($rows);
    }

    public function saveRecord(string $recordType, array $input, array $result, int $userId): int
    {
        $this->ensureTable();

        $summary = $this->recordSummary($recordType, $input, $result);
        $now = date('Y-m-d H:i:s');

        return (int)Db::name('expansion_records')->insertGetId([
            'tenant_id' => $this->tenantIdForUser($userId),
            'record_type' => $recordType,
            'project_name' => $summary['project_name'],
            'city_area' => $summary['city_area'],
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'decision' => $summary['decision'],
            'risk_level' => $summary['risk_level'],
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function records(int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $rows = $query->order('id', 'desc')->limit(50)->select()->toArray();
        return array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin, bool $lockForUpdate = false): array
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }
        if ($lockForUpdate) {
            $query->lock(true);
        }

        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('扩张记录不存在或无权访问');
        }

        return $this->formatRecord($row, true);
    }

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $now = date('Y-m-d H:i:s');
        $affected = $query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int)$affected <= 0) {
            throw new RuntimeException('扩张记录不存在或无权访问');
        }

        return true;
    }

    public function archiveByType(string $recordType, int $userId, bool $isSuperAdmin): int
    {
        return $this->archiveByTypes([$recordType], $userId, $isSuperAdmin);
    }

    public function archiveByTypes(array $recordTypes, int $userId, bool $isSuperAdmin): int
    {
        $this->ensureTable();

        $allowedTypes = ['market', 'benchmark', 'collaboration'];
        $recordTypes = array_values(array_unique(array_map(
            static fn(mixed $recordType): string => trim((string)$recordType),
            $recordTypes
        )));
        $recordTypes = array_values(array_filter($recordTypes, static fn(string $recordType): bool => $recordType !== ''));
        if (empty($recordTypes) || array_diff($recordTypes, $allowedTypes) !== []) {
            throw new InvalidArgumentException('扩张记录类型无效');
        }

        $query = Db::name('expansion_records')
            ->whereIn('record_type', $recordTypes)
            ->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $now = date('Y-m-d H:i:s');
        return (int)$query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function buildExecutionIntentInput(array $record, int $hotelId, array $overrides = []): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_id is required for expansion execution tracking');
        }

        $input = $this->decodeJson($record['input'] ?? $record['input_json'] ?? []);
        $result = $this->decodeJson($record['result'] ?? $record['result_json'] ?? []);
        $recordType = trim((string)($record['record_type'] ?? ''));
        $readiness = is_array($record['project_readiness'] ?? null)
            ? $record['project_readiness']
            : $this->buildProjectReadiness($recordType, $input, $result);
        $readinessStage = trim((string)($readiness['stage'] ?? ''));
        if (!in_array($readinessStage, ['review_ready', 'approved_pending_tracking'], true)) {
            throw new InvalidArgumentException(
                'expansion record is not ready for execution review: ' . ($readinessStage !== '' ? $readinessStage : 'readiness_stage missing')
            );
        }
        $date = date('Y-m-d');
        $dateStart = trim((string)($overrides['date_start'] ?? '')) ?: $date;
        $dateEnd = trim((string)($overrides['date_end'] ?? '')) ?: $dateStart;
        $projectName = trim((string)($record['project_name'] ?? $input['project_name'] ?? ''));
        if ($projectName === '') {
            $projectName = 'expansion_record_' . (int)($record['id'] ?? 0);
        }

        return [
            'source_module' => 'expansion',
            'source_record_id' => (int)($record['id'] ?? 0),
            'hotel_id' => $hotelId,
            'platform' => 'investment',
            'object_type' => 'expansion',
            'action_type' => 'expansion_post_decision_tracking',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => [
                'project_name' => $projectName,
                'record_type' => $recordType,
                'city_area' => (string)($record['city_area'] ?? $input['city_area'] ?? ''),
                'decision' => (string)($record['decision'] ?? $result['decision'] ?? ''),
                'risk_level' => (string)($record['risk_level'] ?? $result['investment_risk_level'] ?? ''),
                'readiness_stage' => $readinessStage,
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => 'pending_expansion_post_decision_tracking',
                'target_metric' => 'expansion_project_closure',
                'decision_stage' => $readinessStage,
                'next_action' => (string)($readiness['next_action'] ?? ''),
            ],
            'evidence' => [
                'record_type' => $recordType,
                'readiness_stage' => $readinessStage,
                'readiness_score' => (int)($readiness['score'] ?? 0),
                'missing_evidence' => array_values((array)($readiness['missing_evidence'] ?? [])),
                'decision' => (string)($record['decision'] ?? ''),
                'city_area' => (string)($record['city_area'] ?? ''),
                'source_scope' => 'expansion_screening_and_project_decision',
                'scope_notice' => 'Expansion evidence is project screening and investment-decision scope; OTA channel evidence remains channel-scope unless backed by whole-hotel operating data.',
            ],
            'expected_metric' => 'expansion_project_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($record['risk_level'] ?? $result['investment_risk_level'] ?? ''), (string)($record['decision'] ?? $result['decision'] ?? '')),
            'status' => 'pending_approval',
        ];
    }

    public function attachExecutionTracking(int $id, int $userId, bool $isSuperAdmin, array $tracking): array
    {
        $this->ensureTable();
        $intentId = (int)($tracking['execution_intent_id'] ?? $tracking['id'] ?? 0);
        if ($intentId <= 0) {
            throw new InvalidArgumentException('execution_intent_id is required');
        }

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('扩张记录不存在或无权访问');
        }

        $result = $this->decodeJson($row['result_json'] ?? '');
        $linkedIntentId = (int)($result['operation_execution_intent_id'] ?? $result['execution_intent_id'] ?? 0);
        if ($linkedIntentId > 0) {
            if ($linkedIntentId !== $intentId) {
                throw new RuntimeException('expansion record is already linked to a different execution intent', 409);
            }

            return $this->formatRecord($row, true);
        }

        $now = date('Y-m-d H:i:s');
        $trackingPayload = [
            'type' => 'operation_execution_intent',
            'execution_intent_id' => $intentId,
            'hotel_id' => (int)($tracking['hotel_id'] ?? 0),
            'status' => trim((string)($tracking['status'] ?? '')),
            'source_module' => 'expansion',
            'linked_at' => $now,
        ];

        $existing = $result['execution_tracking'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        if ($existing !== [] && array_keys($existing) !== range(0, count($existing) - 1)) {
            $existing = [$existing];
        }
        $existing[] = $trackingPayload;

        $result['execution_tracking'] = $existing;
        $result['operation_execution_intent_id'] = $intentId;
        $result['execution_intent_id'] = $intentId;
        $result['post_decision_tracking'] = [
            'status' => 'linked',
            'latest_execution_intent_id' => $intentId,
            'latest_status' => $trackingPayload['status'],
            'hotel_id' => $trackingPayload['hotel_id'],
            'linked_at' => $now,
        ];

        Db::name('expansion_records')->where('id', $id)->update([
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);

        $row['result_json'] = $result;
        $row['updated_at'] = $now;
        return $this->formatRecord($row, true);
    }

    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        DatabaseSchemaRequirement::assertTableColumns('expansion_records', [
            'id', 'tenant_id', 'record_type', 'project_name', 'city_area', 'input_json',
            'result_json', 'decision', 'risk_level', 'created_by', 'created_at',
            'updated_at', 'deleted_at',
        ]);
        $this->tableEnsured = true;
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
            if ($hotelId <= 0) {
                return null;
            }

            $hotelTenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
            return $hotelTenantId > 0 ? $hotelTenantId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function recordSummary(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'market') {
            $city = $this->text($input, ['city']);
            $businessArea = $this->text($input, ['business_area', 'district', 'area']);
            $cityArea = trim($city . ($businessArea !== '' ? ' ' . $businessArea : ''));

            return [
                'project_name' => $this->limitText(($cityArea !== '' ? $cityArea : '扩张') . '市场评估', 160),
                'city_area' => $this->limitText($cityArea, 160),
                'decision' => $this->limitText((string)($result['decision'] ?? ''), 120),
                'risk_level' => $this->limitText((string)($result['investment_risk_level'] ?? ''), 30),
            ];
        }

        if ($recordType === 'benchmark') {
            $position = is_array($result['position'] ?? null) ? $result['position'] : [];
            $city = $this->text($input, ['city'], (string)($position['city'] ?? ''));
            $businessArea = $this->text($input, ['business_area', 'district', 'area'], (string)($position['business_area'] ?? ''));
            $cityArea = trim($city . ($businessArea !== '' ? ' ' . $businessArea : ''));
            $strategies = is_array($result['copyable_strategies'] ?? null) ? $result['copyable_strategies'] : [];

            return [
                'project_name' => $this->limitText(($cityArea !== '' ? $cityArea : '扩张') . '标杆选模', 160),
                'city_area' => $this->limitText($cityArea, 160),
                'decision' => $this->limitText((string)($strategies['price'] ?? '标杆模型已生成'), 120),
                'risk_level' => '',
            ];
        }

        $delayRisk = is_array($result['delay_risk'] ?? null) ? $result['delay_risk'] : [];
        $progress = is_array($result['progress'] ?? null) ? $result['progress'] : [];
        $riskLevel = (string)($delayRisk['level'] ?? '');

        return [
            'project_name' => $this->limitText($this->text($input, ['project_name'], '协同提效项目'), 160),
            'city_area' => $this->limitText($this->text($input, ['city_area']), 160),
            'decision' => $this->limitText($riskLevel !== '' ? $riskLevel : (string)($progress['status_text'] ?? '协同看板已生成'), 120),
            'risk_level' => $this->limitText($riskLevel, 30),
        ];
    }

    private function formatRecord(array $row, bool $withDetail): array
    {
        $input = $this->decodeJson($row['input_json'] ?? '');
        $result = $this->decodeJson($row['result_json'] ?? '');
        $recordType = (string)($row['record_type'] ?? '');
        if ($recordType === 'market' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeMarketAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'market'),
            ]);
        }
        if ($recordType === 'benchmark' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeBenchmarkAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'benchmark'),
            ]);
        }
        $record = [
            'id' => (int)$row['id'],
            'record_type' => (string)($row['record_type'] ?? ''),
            'project_name' => (string)($row['project_name'] ?? ''),
            'city_area' => (string)($row['city_area'] ?? ''),
            'decision' => (string)($row['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'execution_intent_id' => (int)($result['operation_execution_intent_id'] ?? $result['execution_intent_id'] ?? 0),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'market_heat_score' => $result['market_heat_score'] ?? null,
                'investment_risk_level' => $result['investment_risk_level'] ?? ($row['risk_level'] ?? ''),
                'benchmark_count' => is_array($result['recommended_benchmarks'] ?? null) ? count($result['recommended_benchmarks']) : null,
                'progress_percent' => $result['progress']['percent'] ?? null,
            ],
            'project_readiness' => $this->buildProjectReadiness(
                (string)($row['record_type'] ?? ''),
                $input,
                $result
            ),
        ];

        if ($withDetail) {
            $record['input'] = $input;
            $record['result'] = $result;
        }

        return $record;
    }

    private function executionRiskLevel(string $riskLevel, string $decision): string
    {
        $text = strtolower($riskLevel . ' ' . $decision);
        if (str_contains($text, 'high') || str_contains($riskLevel, '高') || str_contains($decision, '暂缓') || str_contains($decision, '放弃')) {
            return 'high';
        }
        if (str_contains($text, 'medium') || str_contains($riskLevel, '中')) {
            return 'medium';
        }
        if (str_contains($text, 'low') || str_contains($riskLevel, '低') || str_contains($decision, '推进') || str_contains($decision, '通过')) {
            return 'low';
        }

        return 'medium';
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function limitText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function buildMarketAiEvaluation(array $input, array $result, string $modelKey): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店投资市场评估分析师。只输出符合 schema 的 JSON。必须基于用户输入和市场评估结果生成投决复核意见；market_heat_score 是预设阈值形成的规则初筛指数，不是真实市场热度或投资成功概率；不得改写或发明财务数字；缺少真实市场、竞品、租约或 OTA 数据时必须写入 assumptions，且不得输出推进、通过或可投资结论；watch_points 必须给出风险严重度、判断依据、潜在影响、复核动作、责任角色和完成时限；建议必须可执行、克制、面向投资决策。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'input' => $input,
                    'rule_result' => $result,
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $evaluation = $this->client->createJsonResponse($messages, $this->marketAiEvaluationSchema(), $modelKey);
            return $this->normalizeMarketAiEvaluation($evaluation, [
                'source' => 'llm',
                'model_key' => $modelKey,
                'generated_at' => date('Y-m-d H:i:s'),
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'market'),
            ]);
        } catch (Throwable $e) {
            return $this->buildFallbackMarketAiEvaluation($input, $result, $modelKey, $e->getMessage());
        }
    }

    private function buildFallbackMarketAiEvaluation(array $input, array $result, string $modelKey, string $reason): array
    {
        $score = (int)($result['market_heat_score'] ?? 0);
        $riskLevel = (string)($result['investment_risk_level'] ?? '中风险');
        $decision = (string)($result['decision'] ?? '谨慎推进');
        $suggestions = $this->stringList($result['ai_operation_suggestions'] ?? []);
        $risks = $this->stringList($result['not_recommended_risks'] ?? []);

        return $this->normalizeMarketAiEvaluation([
            'source' => 'fallback',
            'model_key' => $modelKey,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => '本地规则初筛指数为' . $score . '分，规则风险标记为' . $riskLevel . '，当前状态为' . $decision . '；该指数不代表真实市场热度或投资成功概率。',
            'decision' => $decision,
            'market_judgement' => [
                'supply_competition_strength' => (string)($result['supply_competition_strength'] ?? ''),
                'price_band_suggestion' => (string)($result['price_band_suggestion'] ?? ''),
                'decision' => $decision,
            ],
            'recommendations' => array_map(
                static fn(string $item): array => ['priority' => 'P1', 'title' => '经营建议', 'detail' => $item],
                array_slice($suggestions, 0, 5)
            ),
            'watch_points' => [
                [
                    'metric' => '真实竞品价格带',
                    'threshold' => '补齐3公里同档竞品ADR、评分、点评量',
                    'severity' => 'P0',
                    'evidence' => '当前缺少真实竞品价格、评分和点评样本，价格带仍以初筛模型为主。',
                    'impact' => '若目标ADR高于真实竞品承接能力，开业爬坡期入住率和现金流会被高估。',
                    'validation' => '实采不少于8家同档竞品，记录门市价、可订价、评分、点评量、近期入住表现后再校准目标ADR。',
                    'owner' => '投资拓展/收益管理',
                    'deadline' => '投决会前',
                    'action' => '未补齐前仅作为初筛结论，不进入最终投决。',
                ],
                [
                    'metric' => '租金承压',
                    'threshold' => '单房月租与租金坪效高于模型阈值',
                    'severity' => 'P1',
                    'evidence' => '租金、免租期和装修投入会共同决定项目现金流安全边际。',
                    'impact' => '租金条件未重谈时，回本周期可能被拉长，且淡季现金流缓冲不足。',
                    'validation' => '拆分保守、基准、乐观三套现金流，并分别测试租金下调、免租期延长和房量调整方案。',
                    'owner' => '投资测算/拓展谈判',
                    'deadline' => '合同条款锁定前',
                    'action' => '优先重谈租金、免租期或调整房量模型。',
                ],
            ],
            'assumptions' => ['AI模型不可用时启用本地规则兜底。', '尚未接入真实市场、竞品和 OTA 复核数据。'],
            'error' => mb_substr(trim($reason), 0, 120),
            'risk_points' => $risks,
        ], [
            'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'market'),
        ]);
    }

    private function normalizeMarketAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->expansionDecisionQualityContext([], [], 'market');
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'market_judgement' => $this->normalizeMarketJudgement($raw['market_judgement'] ?? $raw['marketJudgement'] ?? []),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => $this->cleanAiText((string)($raw['error'] ?? ''), 120),
        ];
    }

    private function normalizeMarketJudgement(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'supply_competition_strength' => $this->cleanAiText((string)($raw['supply_competition_strength'] ?? $raw['supplyCompetitionStrength'] ?? ''), 160),
            'price_band_suggestion' => $this->cleanAiText((string)($raw['price_band_suggestion'] ?? $raw['priceBandSuggestion'] ?? ''), 160),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
        ];
    }

    private function normalizeAiRecommendationItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            $detail = trim((string)($item['detail'] ?? $item['content'] ?? ''));
            if ($title === '' && $detail === '') {
                continue;
            }
            $priority = strtoupper(trim((string)($item['priority'] ?? 'P1')));
            if (!in_array($priority, ['P0', 'P1', 'P2'], true)) {
                $priority = 'P1';
            }
            $normalized[] = array_merge($item, [
                'priority' => $priority,
                'title' => $title !== '' ? $this->cleanAiText($title, 80) : '市场评估建议',
                'detail' => $this->cleanAiText($detail, 220),
            ]);
        }

        return array_slice($normalized, 0, 5);
    }

    private function normalizeAiWatchPointItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $metric = trim((string)($item['metric'] ?? ''));
            $threshold = trim((string)($item['threshold'] ?? ''));
            $action = trim((string)($item['action'] ?? ''));
            $severity = strtoupper(trim((string)($item['severity'] ?? $item['priority'] ?? 'P1')));
            if ($metric === '' && $threshold === '' && $action === '') {
                continue;
            }
            if (!in_array($severity, ['P0', 'P1', 'P2'], true)) {
                $severity = 'P1';
            }
            $normalized[] = [
                'metric' => $metric !== '' ? $this->cleanAiText($metric, 80) : '关键指标',
                'threshold' => $this->cleanAiText($threshold, 160),
                'action' => $this->cleanAiText($action, 220),
                'severity' => $severity,
                'evidence' => $this->cleanAiText((string)($item['evidence'] ?? $item['reason'] ?? ''), 220),
                'impact' => $this->cleanAiText((string)($item['impact'] ?? ''), 220),
                'validation' => $this->cleanAiText((string)($item['validation'] ?? $item['verification'] ?? $item['check_method'] ?? ''), 220),
                'owner' => $this->cleanAiText((string)($item['owner'] ?? ''), 80),
                'deadline' => $this->cleanAiText((string)($item['deadline'] ?? $item['timing'] ?? ''), 80),
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    private function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $list[] = $this->cleanAiText($value, 220);
            }
        }

        return array_values(array_unique($list));
    }

    private function cleanAiText(string $value, int $length): string
    {
        $value = preg_replace('/规则引擎|rule engine/i', '初筛模型', $value) ?? $value;
        return mb_substr(trim($value), 0, $length);
    }

    private function marketAiEvaluationSchema(): array
    {
        return [
            'x-governance' => [
                'module' => 'expansion',
                'scenario' => 'market_evaluation',
                'prompt_version' => 'expansion.market_evaluation.v1',
                'decision_impact' => 'investment',
                'knowledge_sources' => ['input', 'rule_result'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'decision', 'market_judgement', 'recommendations', 'watch_points', 'assumptions'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'decision' => ['type' => 'string'],
                'market_judgement' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['supply_competition_strength', 'price_band_suggestion', 'decision'],
                    'properties' => [
                        'supply_competition_strength' => ['type' => 'string'],
                        'price_band_suggestion' => ['type' => 'string'],
                        'decision' => ['type' => 'string'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['priority', 'title', 'detail'],
                        'properties' => [
                            'priority' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
                'watch_points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['severity', 'metric', 'threshold', 'evidence', 'impact', 'validation', 'owner', 'deadline', 'action'],
                        'properties' => [
                            'severity' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'metric' => ['type' => 'string'],
                            'threshold' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                            'impact' => ['type' => 'string'],
                            'validation' => ['type' => 'string'],
                            'owner' => ['type' => 'string'],
                            'deadline' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                    ],
                ],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function buildBenchmarkAiEvaluation(array $input, array $result, string $modelKey): array
    {
        if (($result['source'] ?? '') === 'synthetic_rule_scenario') {
            return $this->buildFallbackBenchmarkAiEvaluation(
                $input,
                $result,
                $modelKey,
                '竞品细化指标不完整，当前仅生成规则情景草案，未调用AI标杆复核。'
            );
        }

        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店扩张标杆选模分析师。只输出符合 schema 的 JSON。必须基于用户输入、竞品细化数据和标杆选模结果生成复核意见；不得发明真实酒店名称、未提供的竞品事实或财务数字；用户录入的竞品汇总指标仍需可追溯样本复核，不得描述为已验证真实酒店样本；不得输出“规则引擎”等内部实现描述，需用“初筛模型”或“当前输入”表达；缺少真实竞品、点评或 OTA 数据时写入 assumptions；建议必须可执行、克制、面向复制策略和差异化落地。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'input' => $input,
                    'benchmark_result' => $result,
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $evaluation = $this->client->createJsonResponse($messages, $this->benchmarkAiEvaluationSchema(), $modelKey);
            return $this->normalizeBenchmarkAiEvaluation($evaluation, [
                'source' => 'llm',
                'model_key' => $modelKey,
                'generated_at' => date('Y-m-d H:i:s'),
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'benchmark'),
            ]);
        } catch (Throwable $e) {
            return $this->buildFallbackBenchmarkAiEvaluation($input, $result, $modelKey, $e->getMessage());
        }
    }

    private function buildFallbackBenchmarkAiEvaluation(array $input, array $result, string $modelKey, string $reason): array
    {
        $isSyntheticScenario = ($result['source'] ?? '') === 'synthetic_rule_scenario';
        $benchmarks = is_array($result['recommended_benchmarks'] ?? null) ? $result['recommended_benchmarks'] : [];
        $defaultModelName = $isSyntheticScenario ? '情景模型A' : '标杆模型A';
        $best = $benchmarks[0] ?? ['name' => $defaultModelName, 'model_fit_score' => null];
        foreach ($benchmarks as $benchmark) {
            if ((int)($benchmark['model_fit_score'] ?? 0) > (int)($best['model_fit_score'] ?? 0)) {
                $best = $benchmark;
            }
        }
        $bestName = (string)($best['name'] ?? $defaultModelName);
        $fitScore = $best['model_fit_score'] ?? null;
        $strategies = is_array($result['copyable_strategies'] ?? null) ? $result['copyable_strategies'] : [];
        $strategyLabels = [
            'room_type' => '房型策略',
            'price' => '价格策略',
            'channel' => '渠道策略',
            'review' => '点评策略',
            'image' => '图片策略',
            'service' => '服务策略',
            'data' => '数据校准',
        ];

        $recommendations = [];
        foreach (array_slice($strategies, 0, 4, true) as $key => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $recommendations[] = [
                'priority' => $key === 'data' ? 'P0' : 'P1',
                'title' => $strategyLabels[(string)$key] ?? '复制策略',
                'detail' => $value,
            ];
        }

        $avoidPoints = $this->stringList($result['avoid_copying_points'] ?? []);
        $watchPoints = array_map(
            static fn(string $item): array => [
                'metric' => '不建议照搬',
                'threshold' => $item,
                'action' => '落地前结合自身房量、成本结构和商圈客源重新校准。',
            ],
            array_slice($avoidPoints, 0, 3)
        );

        return $this->normalizeBenchmarkAiEvaluation([
            'source' => 'fallback',
            'model_key' => $modelKey,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => $isSyntheticScenario
                ? '当前仅生成规则情景草案，可从' . $bestName . '开始验证；未接入完整竞品指标，不代表真实酒店样本或已验证匹配度。'
                : '本地标杆选模显示，优先参考' . $bestName . ($fitScore !== null ? '，匹配度约' . (int)$fitScore . '%' : '') . '，需结合真实竞品样本复核。',
            'decision' => $isSyntheticScenario
                ? '先补齐可追溯的竞品数量、价格、评分、点评量、OTA热度和采样半径，再形成标杆选择。'
                : '优先复制高匹配标杆的房型、价格和渠道做法，差异化卖点需单独验证。',
            'model_judgement' => [
                'best_fit_model' => $bestName,
                'copy_priority' => '先复制房型效率、主力价格带和渠道首图标准，再验证服务标签。',
                'differentiation_focus' => '围绕商圈主客源选择一个强标签，不同时堆叠过多卖点。',
            ],
            'recommendations' => $recommendations,
            'watch_points' => $watchPoints,
            'assumptions' => $isSyntheticScenario
                ? ['当前为基于用户输入及默认假设生成的规则情景，非真实竞品样本。', '竞品细化指标不完整，未生成真实样本匹配度。']
                : ['AI模型不可用时启用本地标杆选模兜底。', '用户录入的竞品汇总指标仍需可追溯样本复核。'],
            'error' => mb_substr(trim($reason), 0, 120),
        ], [
            'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'benchmark'),
        ]);
    }

    private function normalizeBenchmarkAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->expansionDecisionQualityContext([], [], 'benchmark');
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'model_judgement' => $this->normalizeBenchmarkJudgement($raw['model_judgement'] ?? $raw['modelJudgement'] ?? []),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => $this->cleanAiText((string)($raw['error'] ?? ''), 120),
        ];
    }

    private function expansionDecisionQualityContext(array $input, array $result, string $scenario): array
    {
        $source = trim((string)($result['source'] ?? ''));
        return [
            'scope' => 'investment_scenario',
            'data_basis' => [
                [
                    'ref' => 'expansion_user_input_snapshot',
                    'source' => 'user_provided_project_inputs',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'user_provided_unverified',
                    'summary' => '城市、物业、租金、房量、客群及竞品汇总来自当前录入，需保留人工来源凭证。',
                ],
                [
                    'ref' => 'expansion_' . $scenario . '_result',
                    'source' => $source !== '' ? $source : 'deterministic_expansion_result',
                    'scope' => 'investment_scenario',
                    'quality_status' => $source === 'verified_source_data' ? 'verified' : 'derived_unverified',
                    'summary' => '当前评估结果由录入数据和规则初筛派生，不代表真实市场热度或投资成功率。',
                ],
            ],
            'basis_summary' => (string)($result['decision'] ?? $result['summary'] ?? ''),
            'default_risk_level' => (string)($result['investment_risk_level'] ?? 'medium'),
            'review_window' => '进入投决会前，以真实竞品样本、租约、经营流水和同口径OTA渠道数据复核',
        ];
    }

    private function normalizeBenchmarkJudgement(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'best_fit_model' => $this->cleanAiText((string)($raw['best_fit_model'] ?? $raw['bestFitModel'] ?? ''), 120),
            'copy_priority' => $this->cleanAiText((string)($raw['copy_priority'] ?? $raw['copyPriority'] ?? ''), 180),
            'differentiation_focus' => $this->cleanAiText((string)($raw['differentiation_focus'] ?? $raw['differentiationFocus'] ?? ''), 180),
        ];
    }

    private function benchmarkAiEvaluationSchema(): array
    {
        return [
            'x-governance' => [
                'module' => 'expansion',
                'scenario' => 'benchmark_model',
                'prompt_version' => 'expansion.benchmark_model.v1',
                'decision_impact' => 'investment',
                'knowledge_sources' => ['input', 'benchmark_result'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'decision', 'model_judgement', 'recommendations', 'watch_points', 'assumptions'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'decision' => ['type' => 'string'],
                'model_judgement' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['best_fit_model', 'copy_priority', 'differentiation_focus'],
                    'properties' => [
                        'best_fit_model' => ['type' => 'string'],
                        'copy_priority' => ['type' => 'string'],
                        'differentiation_focus' => ['type' => 'string'],
                    ],
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['priority', 'title', 'detail'],
                        'properties' => [
                            'priority' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
                'watch_points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['metric', 'threshold', 'action'],
                        'properties' => [
                            'metric' => ['type' => 'string'],
                            'threshold' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                    ],
                ],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function normalizeTasks(array $tasks, string $currentStage, DateTimeImmutable $today): array
    {
        $inputByName = [];
        foreach ($tasks as $task) {
            if (is_array($task) && trim((string)($task['name'] ?? '')) !== '') {
                $inputByName[trim((string)$task['name'])] = $task;
            }
        }

        return array_map(function (string $name) use ($inputByName): array {
            $task = $inputByName[$name] ?? [];
            $isObserved = $task !== [];
            return [
                'name' => $name,
                'status' => $this->taskStatus((string)($task['status'] ?? '待确认')),
                'owner' => trim((string)($task['owner'] ?? '待分配')),
                'due_date' => trim((string)($task['due_date'] ?? '')),
                'risk_note' => trim((string)($task['risk_note'] ?? '')),
                'source' => $isObserved ? 'operator_provided' : 'rule_template',
                'evidence_status' => $isObserved ? 'operator_provided' : 'unconfirmed_template',
                'is_observed' => $isObserved,
            ];
        }, self::TASKS);
    }

    private function defaultTaskStatus(int $taskIndex, int $stageIndex): string
    {
        return '待确认';
    }

    private function stageIndex(string $stage): int
    {
        if (str_contains($stage, '上线')) {
            return 5;
        }
        if (str_contains($stage, '筹建') || str_contains($stage, '装修')) {
            return 3;
        }
        if (str_contains($stage, '签约') || str_contains($stage, '合同')) {
            return 2;
        }
        return 0;
    }

    private function nextTask(array $tasks): ?array
    {
        foreach ($tasks as $task) {
            if ($task['status'] !== '已完成') {
                return $task;
            }
        }
        return null;
    }

    private function taskStatus(string $status): string
    {
        return in_array($status, ['待确认', '未开始', '进行中', '已完成', '风险'], true) ? $status : '待确认';
    }

    private function dataStatus(array $missing): array
    {
        return [
            'status' => '待接入真实数据',
            'real_data_used' => false,
            'missing_fields' => array_values(array_unique($missing)),
            'notice' => empty($missing) ? '当前为规则引擎结果，待接入真实市场、竞品和项目数据' : '存在待补充字段，当前结果仅供初筛',
        ];
    }

    private function requiredText(array $input, string $key, string $message): string
    {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private function requiredNumber(array $input, string $key, string $message): float
    {
        if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
            throw new InvalidArgumentException($message);
        }
        if (!is_numeric($input[$key])) {
            throw new InvalidArgumentException($message . '，且必须为数字');
        }
        return (float)$input[$key];
    }

    private function optionalNumber(array $input, string $key): ?float
    {
        if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
            return null;
        }
        return is_numeric($input[$key]) ? (float)$input[$key] : null;
    }

    private function optionalNumberByKeys(array $input, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->optionalNumber($input, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function text(array $input, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }

    private function score(float $score): int
    {
        return (int)max(0, min(100, round($score)));
    }

    private function riskLevel(int $score, array $riskPoints): string
    {
        if ($score < 60 || count($riskPoints) >= 3) {
            return '高风险';
        }
        if ($score < 75 || count($riskPoints) >= 1) {
            return '中风险';
        }
        return '低风险';
    }

    private function priceBand(string $decorationLevel, float $rentPerRoom): string
    {
        if (str_contains($decorationLevel, '高端')) {
            return $rentPerRoom > 3000 ? '建议先压租后定位在320-420元' : '建议定位在300-420元';
        }
        if (str_contains($decorationLevel, '经济')) {
            return '建议定位在160-230元';
        }
        return $rentPerRoom > 2600 ? '建议定位在260-330元，并验证ADR承压能力' : '建议定位在220-320元';
    }

    private function propertyType(int $roomCount, float $areaPerRoom): string
    {
        if ($roomCount >= 80 && $areaPerRoom <= 48) {
            return '整栋或独立出入口中端商务酒店';
        }
        if ($roomCount >= 50) {
            return '集中楼层改造型中端/经济型酒店';
        }
        return '轻改造小体量精选项目';
    }

    private function priceRange(string $targetPriceBand): array
    {
        preg_match_all('/\d+/', $targetPriceBand, $matches);
        $numbers = array_map('intval', $matches[0] ?? []);
        if (count($numbers) >= 2) {
            $low = min($numbers[0], $numbers[1]);
            $high = max($numbers[0], $numbers[1]);
        } elseif (str_contains($targetPriceBand, '高')) {
            [$low, $high] = [320, 450];
        } elseif (str_contains($targetPriceBand, '经济')) {
            [$low, $high] = [160, 230];
        } else {
            [$low, $high] = [220, 320];
        }

        return ['low' => $low, 'high' => $high, 'mid' => (int)round(($low + $high) / 2)];
    }

    private function parseDate(string $date, string $message): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException($message);
        }
        return $parsed;
    }
}
