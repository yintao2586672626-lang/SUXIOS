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

    private const TASKS = [
        '市场调研',
        '物业评估',
        '合同谈判',
        '装修筹建',
        '证照办理',
        'OTA上线',
        '运营交接',
    ];

    public function __construct(?LlmClient $client = null)
    {
        $this->client = $client ?: new LlmClient();
    }

    public function evaluateMarket(array $input): array
    {
        $city = $this->requiredText($input, 'city', '城市不能为空');
        $businessArea = $this->text($input, ['business_area', 'district', 'area']);
        $propertyArea = $this->requiredNumber($input, 'property_area', '物业面积不能为空');
        $estimatedRent = $this->requiredNumber($input, 'estimated_rent', '预估租金不能为空');
        $targetRoomCount = (int)$this->requiredNumber($input, 'target_room_count', '目标房量不能为空');
        $cityTier = $this->text($input, ['city_tier'], '');
        $decorationLevel = $this->text($input, ['decoration_level'], '中端精选-标准');
        $primaryCustomer = $this->text($input, ['primary_customer'], '商务差旅');
        $secondaryCustomer = $this->text($input, ['secondary_customer'], '会议会展');
        $targetCustomer = $this->text($input, ['target_customer'], $primaryCustomer . '+' . $secondaryCustomer);
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
        $assetType = $this->text($input, ['asset_type'], '集中楼层');
        $operationModel = $this->text($input, ['operation_model'], '直营');
        $contractStatus = $this->text($input, ['contract_status'], '待谈判');

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
            ],
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
            'not_recommended_risks' => array_values(array_unique($riskPoints ?: ['暂无硬性否决项，但需接入真实竞品和客流数据后复核'])),
            'metrics' => [
                'area_per_room' => round($areaPerRoom, 1),
                'rent_per_room' => round($rentPerRoom, 0),
                'rent_per_square' => round($rentPerSquare, 1),
            ],
            'investment_conditions' => [
                ['label' => '城市线级', 'value' => $cityTier !== '' ? $cityTier : '待补充'],
                ['label' => '物业形态', 'value' => $assetType],
                ['label' => '经营模式', 'value' => $operationModel],
                ['label' => '合同状态', 'value' => $contractStatus],
                ['label' => '主客群', 'value' => $primaryCustomer],
                ['label' => '辅助客群', 'value' => $secondaryCustomer],
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
            'decision' => $score >= 80 ? '建议推进' : ($score >= 65 ? '谨慎推进' : '不建议按当前条件推进'),
            'data_status' => $this->dataStatus($missing),
            'rule_reasons' => array_values(array_unique($reasons)),
        ];

        $modelKey = trim((string)($input['model_key'] ?? $input['modelKey'] ?? 'deepseek_v4_default'));
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        $aiEvaluation = $this->buildMarketAiEvaluation($input, $result, $modelKey);
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
        $competitorCount = (int)max(1, round($detailInputs['competitor_count'] ?? ($targetRoomCount >= 70 ? 16 : 10)));
        $avgCompetitorPrice = (int)max(1, round($detailInputs['avg_competitor_price'] ?? $price['mid']));
        $avgCompetitorScore = round(max(1, min(5, $detailInputs['avg_competitor_score'] ?? 4.6)), 1);
        $avgReviewCount = (int)max(1, round($detailInputs['avg_review_count'] ?? ($targetRoomCount >= 70 ? 420 : 260)));
        $otaHeatIndex = (int)max(0, min(100, round($detailInputs['ota_heat_index'] ?? $baseHeat)));
        $trafficRadiusKm = round(max(0.1, $detailInputs['traffic_radius_km'] ?? 3), 1);
        $detailMetrics = [
            'competitor_count' => $competitorCount,
            'avg_competitor_price' => $avgCompetitorPrice,
            'avg_competitor_score' => $avgCompetitorScore,
            'avg_review_count' => $avgReviewCount,
            'ota_heat_index' => $otaHeatIndex,
            'traffic_radius_km' => $trafficRadiusKm,
            'data_completeness' => count($filledDetailKeys) . '/' . count($detailInputs),
            'estimated_fields' => $estimatedFields,
        ];
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
        $models = array_map(function (array $model, int $index) use ($avgCompetitorPrice, $avgCompetitorScore, $avgReviewCount, $otaHeatIndex, $trafficRadiusKm, $competitorCount, $distanceFactors, $reviewFactors): array {
            $priceGap = (int)round(((float)$model['price']) - $avgCompetitorPrice);
            $reviewCount = (int)max(1, round($avgReviewCount * ($reviewFactors[$index] ?? 1)));
            $distanceKm = round(max(0.1, $trafficRadiusKm * ($distanceFactors[$index] ?? 1)), 1);
            $scoreGap = round(((float)$model['score']) - $avgCompetitorScore, 1);
            $heatGap = (int)round(((float)$model['heat']) - $otaHeatIndex);
            $priceFit = 100 - min(45, abs($priceGap) / max(1, $avgCompetitorPrice) * 100);
            $scoreFit = 100 - min(35, abs($scoreGap) * 25);
            $heatFit = 100 - min(40, abs($heatGap));

            $model['distance_km'] = $distanceKm;
            $model['review_count'] = $reviewCount;
            $model['price_gap_to_market'] = $priceGap;
            $model['score_gap_to_market'] = $scoreGap;
            $model['heat_gap_to_market'] = $heatGap;
            $model['model_fit_score'] = $this->score($priceFit * 0.42 + $scoreFit * 0.33 + $heatFit * 0.25);
            $model['sample_basis'] = "{$trafficRadiusKm}公里内{$competitorCount}家竞品样本";

            return $model;
        }, $models, array_keys($models));

        $result = [
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
                'data' => "按{$trafficRadiusKm}公里、{$competitorCount}家竞品校准价格差、评分差和点评量，再确定主力标杆",
            ],
            'differentiation_suggestions' => [
                $businessArea === '' ? '补充商圈后再定义差异化锚点' : "围绕{$businessArea}的主要客源设计首图和权益",
                $targetRoomCount < 45 ? '小房量项目避免复制大店组织架构，优先做轻人效模型' : '用标准化房型提升清扫、人效和收益管理效率',
                "优先复核与竞品均价差在±30元、评分差在0.2分内的标杆样本",
                '选择一个强标签作为差异点，不同时堆叠过多卖点',
            ],
            'avoid_copying_points' => [
                '不要照搬真实酒店名称、装修造价和供应商配置',
                '不要复制超出自身房量承载能力的早餐、会议室或复杂服务',
                '不要用标杆高分直接推导本项目ADR，需结合租金和爬坡周期复核',
            ],
            'data_status' => $this->dataStatus($missing),
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

        $completed = count(array_filter($tasks, fn (array $task): bool => $task['status'] === '已完成'));
        $total = count($tasks);
        $progress = (int)round($completed / max(1, $total) * 100);
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

        $riskLevel = !empty(array_filter($riskPoints, fn (string $item): bool => str_contains($item, '15天') || str_contains($item, '逾期') || str_contains($item, '已过期'))) ? '高风险' : (!empty($riskPoints) ? '中风险' : '低风险');
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
                'percent' => $progress,
                'status_text' => "已完成{$completed}/{$total}项",
            ],
            'delay_risk' => [
                'level' => $riskLevel,
                'points' => $riskPoints ?: ['暂无明确延误风险，按当前节奏推进'],
            ],
            'next_actions' => [
                $nextTask ? '下一步优先推进：' . $nextTask['name'] . '，负责人：' . $nextTask['owner'] : '全部任务已完成，进入上线复盘',
                $riskLevel === '高风险' ? '启动日会机制，锁定证照、装修、OTA三个关键责任人' : '保持周级节奏，更新风险说明和截止时间',
                '所有风险任务需补充解决方案、截止时间和责任人',
            ],
            'data_status' => $this->dataStatus([]),
        ];
    }

    public function saveRecord(string $recordType, array $input, array $result, int $userId): int
    {
        $this->ensureTable();

        $summary = $this->recordSummary($recordType, $input, $result);
        $now = date('Y-m-d H:i:s');

        return (int)Db::name('expansion_records')->insertGetId([
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
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $rows = $query->order('id', 'desc')->limit(50)->select()->toArray();
        return array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
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
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $now = date('Y-m-d H:i:s');
        return (int)$query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function ensureTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS expansion_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                record_type VARCHAR(30) NOT NULL DEFAULT '',
                project_name VARCHAR(160) NOT NULL DEFAULT '',
                city_area VARCHAR(160) NOT NULL DEFAULT '',
                input_json JSON DEFAULT NULL,
                result_json JSON DEFAULT NULL,
                decision VARCHAR(120) NOT NULL DEFAULT '',
                risk_level VARCHAR(30) NOT NULL DEFAULT '',
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_expansion_records_type_user (record_type, created_by, id),
                INDEX idx_expansion_records_city_area (city_area)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
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
        $result = $this->decodeJson($row['result_json'] ?? '');
        $record = [
            'id' => (int)$row['id'],
            'record_type' => (string)($row['record_type'] ?? ''),
            'project_name' => (string)($row['project_name'] ?? ''),
            'city_area' => (string)($row['city_area'] ?? ''),
            'decision' => (string)($row['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'market_heat_score' => $result['market_heat_score'] ?? null,
                'investment_risk_level' => $result['investment_risk_level'] ?? ($row['risk_level'] ?? ''),
                'benchmark_count' => is_array($result['recommended_benchmarks'] ?? null) ? count($result['recommended_benchmarks']) : null,
                'progress_percent' => $result['progress']['percent'] ?? null,
            ],
        ];

        if ($withDetail) {
            $record['input'] = $this->decodeJson($row['input_json'] ?? '');
            $record['result'] = $result;
        }

        return $record;
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
                'content' => '你是酒店投资市场评估分析师。只输出符合 schema 的 JSON。必须基于用户输入和市场评估结果生成投决复核意见；不得改写或发明财务数字；缺少真实市场、竞品或 OTA 数据时写入 assumptions；watch_points 必须给出风险严重度、判断依据、潜在影响、复核动作、责任角色和完成时限；建议必须可执行、克制、面向投资决策。',
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
            ]);
        } catch (Throwable $e) {
            return $this->buildFallbackMarketAiEvaluation($result, $modelKey, $e->getMessage());
        }
    }

    private function buildFallbackMarketAiEvaluation(array $result, string $modelKey, string $reason): array
    {
        $score = (int)($result['market_heat_score'] ?? 0);
        $riskLevel = (string)($result['investment_risk_level'] ?? '中风险');
        $decision = (string)($result['decision'] ?? '谨慎推进');
        $suggestions = $this->stringList($result['ai_operation_suggestions'] ?? []);
        $risks = $this->stringList($result['not_recommended_risks'] ?? []);

        return [
            'source' => 'fallback',
            'model_key' => $modelKey,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => '本地规则评估显示，市场热度评分为' . $score . '分，投资风险为' . $riskLevel . '，当前建议为' . $decision . '。',
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
        ];
    }

    private function normalizeMarketAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'market_judgement' => $this->normalizeMarketJudgement($raw['market_judgement'] ?? $raw['marketJudgement'] ?? []),
            'recommendations' => $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
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
            $normalized[] = [
                'priority' => $priority,
                'title' => $title !== '' ? $this->cleanAiText($title, 80) : '市场评估建议',
                'detail' => $this->cleanAiText($detail, 220),
            ];
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
        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店扩张标杆选模分析师。只输出符合 schema 的 JSON。必须基于用户输入、竞品细化数据和标杆选模结果生成复核意见；不得发明真实酒店名称或未提供的财务数字；不得输出“规则引擎”等内部实现描述，需用“初筛模型”或“当前输入”表达；缺少真实竞品、点评或 OTA 数据时写入 assumptions；建议必须可执行、克制、面向复制策略和差异化落地。',
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
            ]);
        } catch (Throwable $e) {
            return $this->buildFallbackBenchmarkAiEvaluation($result, $modelKey, $e->getMessage());
        }
    }

    private function buildFallbackBenchmarkAiEvaluation(array $result, string $modelKey, string $reason): array
    {
        $benchmarks = is_array($result['recommended_benchmarks'] ?? null) ? $result['recommended_benchmarks'] : [];
        $best = $benchmarks[0] ?? ['name' => '标杆模型A', 'model_fit_score' => null];
        foreach ($benchmarks as $benchmark) {
            if ((int)($benchmark['model_fit_score'] ?? 0) > (int)($best['model_fit_score'] ?? 0)) {
                $best = $benchmark;
            }
        }
        $bestName = (string)($best['name'] ?? '标杆模型A');
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

        return [
            'source' => 'fallback',
            'model_key' => $modelKey,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => '本地标杆选模显示，优先参考' . $bestName . ($fitScore !== null ? '，匹配度约' . (int)$fitScore . '%' : '') . '，需结合真实竞品样本复核。',
            'decision' => '优先复制高匹配标杆的房型、价格和渠道做法，差异化卖点需单独验证。',
            'model_judgement' => [
                'best_fit_model' => $bestName,
                'copy_priority' => '先复制房型效率、主力价格带和渠道首图标准，再验证服务标签。',
                'differentiation_focus' => '围绕商圈主客源选择一个强标签，不同时堆叠过多卖点。',
            ],
            'recommendations' => $recommendations,
            'watch_points' => $watchPoints,
            'assumptions' => ['AI模型不可用时启用本地标杆选模兜底。', '尚未接入真实竞品酒店名称、点评文本和 OTA 转化数据。'],
            'error' => mb_substr(trim($reason), 0, 120),
        ];
    }

    private function normalizeBenchmarkAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'model_judgement' => $this->normalizeBenchmarkJudgement($raw['model_judgement'] ?? $raw['modelJudgement'] ?? []),
            'recommendations' => $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => $this->cleanAiText((string)($raw['error'] ?? ''), 120),
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

        $stageIndex = $this->stageIndex($currentStage);
        return array_map(function (string $name, int $index) use ($inputByName, $stageIndex, $today): array {
            $task = $inputByName[$name] ?? [];
            return [
                'name' => $name,
                'status' => $this->taskStatus((string)($task['status'] ?? $this->defaultTaskStatus($index, $stageIndex))),
                'owner' => trim((string)($task['owner'] ?? '待分配')),
                'due_date' => trim((string)($task['due_date'] ?? $today->modify('+' . (($index + 1) * 7) . ' days')->format('Y-m-d'))),
                'risk_note' => trim((string)($task['risk_note'] ?? '')),
            ];
        }, self::TASKS, array_keys(self::TASKS));
    }

    private function defaultTaskStatus(int $taskIndex, int $stageIndex): string
    {
        if ($taskIndex < $stageIndex) {
            return '已完成';
        }
        if ($taskIndex === $stageIndex) {
            return '进行中';
        }
        return '未开始';
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
        return in_array($status, ['未开始', '进行中', '已完成', '风险'], true) ? $status : '未开始';
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
