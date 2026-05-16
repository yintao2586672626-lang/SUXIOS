<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

class ExpansionService
{
    private const TASKS = [
        '市场调研',
        '物业评估',
        '合同谈判',
        '装修筹建',
        '证照办理',
        'OTA上线',
        '运营交接',
    ];

    public function evaluateMarket(array $input): array
    {
        $city = $this->requiredText($input, 'city', '城市不能为空');
        $businessArea = $this->text($input, ['business_area', 'district', 'area']);
        $propertyArea = $this->requiredNumber($input, 'property_area', '物业面积不能为空');
        $estimatedRent = $this->requiredNumber($input, 'estimated_rent', '预估租金不能为空');
        $targetRoomCount = (int)$this->requiredNumber($input, 'target_room_count', '目标房量不能为空');
        $decorationLevel = $this->text($input, ['decoration_level'], '中端精选');
        $targetCustomer = $this->text($input, ['target_customer'], '商务差旅');

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
        $riskPoints = [];
        $reasons = [];
        $missing = [];
        $areaPerRoom = $propertyArea / max(1, $targetRoomCount);
        $rentPerRoom = $estimatedRent / max(1, $targetRoomCount);
        $rentPerSquare = $estimatedRent / max(1, $propertyArea);

        if ($businessArea === '') {
            $score -= 8;
            $missing[] = '商圈/区域';
            $riskPoints[] = '商圈信息为空，无法判断客源半径和竞品密度';
        } else {
            $score += 8;
            $reasons[] = "已录入{$city}{$businessArea}，可进入实地商圈复核";
        }

        if ($targetRoomCount >= 60 && $rentPerRoom <= 2600) {
            $score += 16;
            $reasons[] = '房量大于60且单房月租处于可控区间';
        } elseif ($targetRoomCount < 40) {
            $score -= 18;
            $riskPoints[] = '目标房量低于40间，规模效率和人员摊薄压力较高';
        } else {
            $score += 4;
            $reasons[] = '房量处于可评估区间，需结合平面落位复核';
        }

        if ($propertyArea < 1000 || $areaPerRoom < 18) {
            $score -= 16;
            $riskPoints[] = '物业面积或单房面积偏小，可能影响房型落位和公共区配置';
        } elseif ($areaPerRoom <= 48) {
            $score += 8;
            $reasons[] = '单房建筑面积匹配常规中端酒店模型';
        } else {
            $score -= 4;
            $riskPoints[] = '单房建筑面积偏大，需关注坪效损耗';
        }

        if ($rentPerRoom > 3200 || $rentPerSquare > 85) {
            $score -= 22;
            $riskPoints[] = '租金压力偏高，开业爬坡期现金流安全边际不足';
        } elseif ($rentPerRoom > 2600 || $rentPerSquare > 65) {
            $score -= 10;
            $riskPoints[] = '租金略高，建议争取免租期、递增上限或装修补贴';
        } else {
            $score += 10;
            $reasons[] = '租金水平未触发高压阈值';
        }

        if (str_contains($targetCustomer, '商务') || str_contains($targetCustomer, '差旅')) {
            $score += 4;
            $reasons[] = '目标客群适合城市商旅型价格带';
        }
        if (str_contains($decorationLevel, '高端')) {
            $score -= 4;
            $riskPoints[] = '装修档次偏高时需控制单房投入，避免回收周期拉长';
        }

        $score = $this->score($score);
        $riskLevel = $this->riskLevel($score, $riskPoints);
        $priceBand = $this->priceBand($decorationLevel, $rentPerRoom);
        $competition = $businessArea === '' ? '待补充商圈后判断' : ($score >= 78 ? '中等竞争，可通过产品差异化切入' : '竞争压力偏高，需补充竞品价格与点评数据');

        return [
            'market_heat_score' => $score,
            'supply_competition_strength' => $competition,
            'price_band_suggestion' => $priceBand,
            'investment_risk_level' => $riskLevel,
            'recommended_property_type' => $this->propertyType($targetRoomCount, $areaPerRoom),
            'ai_operation_suggestions' => [
                $score >= 75 ? '可进入物业尽调和竞品实采，重点验证周边同档酒店ADR与出租率' : '先重谈租金或调整房量模型，再进入投资立项',
                $businessArea === '' ? '补充商圈、地铁/办公/医院/景区等客源锚点信息' : '用3公里竞品价格、评分、点评量校准价格带',
                '将租金、免租期、装修单房投入拆成保守/基准/乐观三套现金流',
            ],
            'not_recommended_risks' => array_values(array_unique($riskPoints ?: ['暂无硬性否决项，但需接入真实竞品和客流数据后复核'])),
            'metrics' => [
                'area_per_room' => round($areaPerRoom, 1),
                'rent_per_room' => round($rentPerRoom, 0),
                'rent_per_square' => round($rentPerSquare, 1),
            ],
            'decision' => $score >= 80 ? '建议推进' : ($score >= 65 ? '谨慎推进' : '不建议按当前条件推进'),
            'data_status' => $this->dataStatus($missing),
            'rule_reasons' => array_values(array_unique($reasons)),
        ];
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

        return [
            'position' => [
                'city' => $city,
                'business_area' => $businessArea,
                'target_price_band' => $targetPriceBand,
                'hotel_type' => $hotelType,
                'target_room_count' => $targetRoomCount,
            ],
            'recommended_benchmarks' => $models,
            'copyable_strategies' => [
                'room_type' => $targetRoomCount >= 60 ? '保留2-3个主力房型，减少低频主题房占比' : '控制房型数量，优先保证标准大床/双床效率',
                'price' => "以{$price['low']}-{$price['high']}元作为挂牌主带，节假日上浮但保留低价引流房",
                'channel' => '携程/美团基础覆盖，重点维护高转化渠道的首图、权益和点评回复',
                'review' => '围绕卫生、隔音、交通、服务响应建立点评关键词',
                'image' => '首图突出房间真实尺度，补齐外立面、前台、卫浴和窗景',
                'service' => str_contains($hotelType, '商务') ? '强化发票、洗衣、延迟退房和安静楼层' : '强化入住指引、行李寄存和本地化推荐',
            ],
            'differentiation_suggestions' => [
                $businessArea === '' ? '补充商圈后再定义差异化锚点' : "围绕{$businessArea}的主要客源设计首图和权益",
                $targetRoomCount < 45 ? '小房量项目避免复制大店组织架构，优先做轻人效模型' : '用标准化房型提升清扫、人效和收益管理效率',
                '选择一个强标签作为差异点，不同时堆叠过多卖点',
            ],
            'avoid_copying_points' => [
                '不要照搬真实酒店名称、装修造价和供应商配置',
                '不要复制超出自身房量承载能力的早餐、会议室或复杂服务',
                '不要用标杆高分直接推导本项目ADR，需结合租金和爬坡周期复核',
            ],
            'data_status' => $this->dataStatus($missing),
        ];
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
