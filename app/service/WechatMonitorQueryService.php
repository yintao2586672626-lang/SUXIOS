<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Answers a bounded set of Chinese operating questions from persisted HOTEL
 * facts. This service does not receive or send WeCom messages by itself.
 */
final class WechatMonitorQueryService
{
    /** @var callable|null */
    private $contextProvider;

    public function __construct(?callable $contextProvider = null)
    {
        $this->contextProvider = $contextProvider;
    }

    /** @return array<string, mixed> */
    public function answer(int $hotelId, string $question, ?string $now = null): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('企业微信追问缺少有效门店绑定。');
        }
        $question = self::safeText($question, 300);
        if ($question === '') {
            throw new \InvalidArgumentException('企业微信追问内容不能为空。');
        }

        $observedAt = self::normalizeTime($now);
        $context = $this->contextProvider !== null
            ? call_user_func($this->contextProvider, $hotelId, $observedAt)
            : $this->loadContext($hotelId, $observedAt);
        if (!is_array($context)) {
            throw new \RuntimeException('企业微信经营追问上下文不可用。');
        }

        $intent = self::detectIntent($question);
        $hotel = is_array($context['hotel'] ?? null) ? $context['hotel'] : [];
        $insight = is_array($context['insight'] ?? null) ? $context['insight'] : [];
        $health = is_array($context['health'] ?? null) ? $context['health'] : [];
        $aiDaily = is_array($context['ai_daily'] ?? null) ? $context['ai_daily'] : [];
        $present = is_array($insight['present'] ?? null) ? $insight['present'] : [];
        $past = is_array($insight['past'] ?? null) ? $insight['past'] : [];
        $report = is_array($aiDaily['report'] ?? null) ? $aiDaily['report'] : [];
        $gaps = self::collectGaps($health, $past, $present, $aiDaily, $report);
        $sources = self::sourceRefs($past, $present, $report, $observedAt);

        $lines = [
            '# ' . self::safeText((string)($hotel['name'] ?? '未命名门店'), 80) . '｜经营追问',
            '> ' . $observedAt->format('Y-m-d H:i:s') . '｜已授权 OTA 渠道',
        ];
        $status = 'answered';

        if ($intent === 'today_progress') {
            $lines[] = '';
            $lines[] = '**【事实｜今日进度】**';
            $todayReason = self::safeText((string)($present['today_reason'] ?? ''), 220);
            if ($todayReason !== '') {
                $lines[] = $todayReason;
            }
            $metricText = self::metricText((array)($present['metrics'] ?? []), false);
            $lines[] = $metricText !== '' ? $metricText : '今天尚无可展示的有效 OTA 实时指标，不以 0 代替。';
            if ($metricText === '') {
                $status = 'partial';
            }
        } elseif ($intent === 'realtime_comparison') {
            $lines[] = '';
            $lines[] = '**【事实｜实时对比】**';
            $comparisonText = self::comparisonText((array)($present['comparison_to_latest_final'] ?? []));
            $lines[] = $comparisonText !== ''
                ? $comparisonText
                : '当前没有同时具备“今日累计”和“最近定稿日”的可比指标。';
            $lines[] = '**【未验证】** 今日累计与最近完整日不是同一时间口径，只作观察，不直接作为执行结论。';
            if ($comparisonText === '') {
                $status = 'partial';
            }
        } elseif ($intent === 'revenue_judgment') {
            $lines[] = '';
            $lines[] = '**【事实】**';
            $factText = self::metricText((array)($present['metrics'] ?? []), false);
            $lines[] = $factText !== '' ? $factText : '当前没有可展示的实时经营事实。';
            [$judgmentLabel, $judgmentText, $judgmentReady] = self::judgment($report);
            $lines[] = '';
            $lines[] = '**【' . $judgmentLabel . '】**';
            $lines[] = $judgmentText;
            if (!$judgmentReady) {
                $status = 'partial';
            }
        } elseif ($intent === 'data_gaps') {
            $lines[] = '';
            $lines[] = '**【未验证/缺口】**';
            if ($gaps === []) {
                $lines[] = '当前已加载证据未记录阻塞缺口；这不等于所有渠道和全酒店数据均已完整。';
            } else {
                foreach (array_slice($gaps, 0, 5) as $gap) {
                    $lines[] = '- ' . self::safeText($gap, 180);
                }
                $status = 'partial';
            }
        } elseif ($intent === 'next_action') {
            $lines[] = '';
            $lines[] = '**【下一步｜需人工确认】**';
            $actions = self::nextActions($health, $report);
            if ($actions === []) {
                $lines[] = '当前没有经过证据约束的具体动作；先补齐缺口，再判断经营问题。';
                $status = 'partial';
            } else {
                foreach (array_slice($actions, 0, 3) as $index => $action) {
                    $lines[] = ($index + 1) . '. ' . self::safeText($action, 180);
                }
            }
        } elseif ($intent === 'source') {
            $lines[] = '';
            $lines[] = '**【来源】**';
            foreach ($sources as $source) {
                $lines[] = '- ' . self::safeText($source, 220);
            }
        } else {
            $lines[] = '';
            $lines[] = '**可追问内容**';
            $lines[] = '今日进度、实时对比、收益研判、数据缺口、下一步、数据来源。';
            $lines[] = '回答只使用已保存并回读的门店证据；缺数据时会直接说明缺口。';
        }

        if (!in_array($intent, ['data_gaps', 'source', 'unknown'], true) && $gaps !== []) {
            $lines[] = '';
            $lines[] = '**【关键缺口】**';
            foreach (array_slice($gaps, 0, 2) as $gap) {
                $lines[] = '- ' . self::safeText($gap, 180);
            }
            $status = 'partial';
        }
        if ($intent !== 'source') {
            $lines[] = '';
            $lines[] = '> 来源：' . self::safeText(implode('；', $sources), 420);
        }
        $lines[] = '> 范围：仅反映已授权 OTA 渠道，不代表全酒店完整经营结果。';

        return [
            'status' => $status,
            'intent' => $intent,
            'hotel_id' => $hotelId,
            'observed_at' => $observedAt->format('Y-m-d H:i:s'),
            'metric_scope' => 'ota_channel',
            'reply_text' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
            'sources' => $sources,
            'data_gaps' => $gaps,
        ];
    }

    public static function detectIntent(string $question): string
    {
        $question = mb_strtolower(trim($question), 'UTF-8');
        $rules = [
            'source' => ['来源', '哪来的', '数据从哪', '依据', '证据'],
            'data_gaps' => ['缺口', '缺什么', '少什么', '为什么没数据', '数据齐不齐', '异常'],
            'next_action' => ['下一步', '怎么办', '怎么做', '建议', '动作'],
            'realtime_comparison' => ['对比', '变化', '环比', '比昨天', '涨了', '降了'],
            'revenue_judgment' => ['收益', '研判', '判断', '经营怎么样', '问题在哪'],
            'today_progress' => ['今天', '今日', '进度', '现在', '实时'],
        ];
        foreach ($rules as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($question, $keyword)) {
                    return $intent;
                }
            }
        }
        return 'unknown';
    }

    /** @return array<string, mixed> */
    private function loadContext(int $hotelId, \DateTimeImmutable $observedAt): array
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('企业微信事件绑定的门店不存在或未启用。');
        }

        $today = $observedAt->format('Y-m-d');
        $targetDate = $observedAt->modify('-1 day')->format('Y-m-d');
        $insight = (new TemporalInsightService())->overview([$hotelId], 30, 7, $today);
        $health = (new CloudDataHealthService())->inspectHotel($hotel, $targetDate, ['ctrip', 'meituan']);
        try {
            $aiDaily = (new AiDailyReportService())->latest([$hotelId], $hotelId);
        } catch (\Throwable) {
            $aiDaily = [
                'report' => null,
                'data_status' => 'read_failed',
                'data_gaps' => [[
                    'code' => 'ai_daily_report_read_failed',
                    'message' => 'AI日报读取失败，当前不输出AI经营结论。',
                ]],
            ];
        }
        return [
            'hotel' => $hotel,
            'insight' => $insight,
            'health' => $health,
            'ai_daily' => $aiDaily,
        ];
    }

    /** @param array<string, mixed> $metrics */
    private static function metricText(array $metrics, bool $past): string
    {
        $labels = [
            'ota_revenue' => '收益',
            'ota_orders' => '订单',
            'ota_room_nights' => '间夜',
            'ota_list_exposure' => '曝光',
            'ota_detail_exposure' => '浏览',
            'ota_order_submit' => '提交订单',
        ];
        $items = [];
        foreach ($labels as $key => $label) {
            $value = $metrics[$key] ?? null;
            if ($past && is_array($value)) {
                $value = $value['latest_value'] ?? null;
            }
            if (is_numeric($value)) {
                $items[] = $label . ' ' . self::number((float)$value);
            }
        }
        return implode('；', $items);
    }

    /** @param array<string, mixed> $comparison */
    private static function comparisonText(array $comparison): string
    {
        $labels = [
            'ota_revenue' => '收益',
            'ota_orders' => '订单',
            'ota_room_nights' => '间夜',
        ];
        $items = [];
        foreach ($labels as $key => $label) {
            $row = is_array($comparison[$key] ?? null) ? $comparison[$key] : [];
            $current = $row['current_value'] ?? null;
            $latest = $row['latest_final_value'] ?? null;
            if (!is_numeric($current) || !is_numeric($latest)) {
                continue;
            }
            $change = is_numeric($row['change_percent'] ?? null)
                ? sprintf('%+.1f%%', (float)$row['change_percent'])
                : '变化率不可算';
            $date = self::safeText((string)($row['latest_final_date'] ?? '最近定稿日'), 20);
            $items[] = $label . ' 今日累计 ' . self::number((float)$current)
                . '，' . $date . ' ' . self::number((float)$latest) . '（' . $change . '）';
        }
        return implode('；', $items);
    }

    /** @param array<string, mixed> $report @return array{0:string,1:string,2:bool} */
    private static function judgment(array $report): array
    {
        if ($report === []) {
            return ['未验证/缺口', '尚无已保存的AI日报，当前不生成AI经营结论。', false];
        }
        $date = self::safeText((string)($report['report_date'] ?? '日期未返回'), 24);
        $status = strtolower(trim((string)($report['model_status'] ?? 'unknown')));
        $summary = self::safeText((string)($report['summary'] ?? ''), 500);
        if ($status === 'ok' && $summary !== '') {
            return ['AI研判｜日报 ' . $date, $summary, true];
        }
        if ($status === 'not_requested' && $summary !== '') {
            return ['规则研判｜日报 ' . $date, $summary, true];
        }
        return [
            '未验证/缺口',
            '日报 ' . $date . ' 的研判状态为 ' . self::modelStatusText($status) . '，当前不把它写成可用AI结论。',
            false,
        ];
    }

    /**
     * @param array<string, mixed> $health
     * @param array<string, mixed> $past
     * @param array<string, mixed> $present
     * @param array<string, mixed> $aiDaily
     * @param array<string, mixed> $report
     * @return array<int, string>
     */
    private static function collectGaps(
        array $health,
        array $past,
        array $present,
        array $aiDaily,
        array $report
    ): array {
        $items = [];
        foreach ([
            $health['issues'] ?? [],
            $past['data_gaps'] ?? [],
            $present['data_gaps'] ?? [],
            $aiDaily['data_gaps'] ?? [],
            $report['data_gaps'] ?? [],
        ] as $gaps) {
            foreach ((array)$gaps as $gap) {
                $text = is_array($gap)
                    ? (string)($gap['message'] ?? $gap['label'] ?? $gap['code'] ?? '')
                    : (string)$gap;
                $text = self::safeText($text, 220);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }
        return array_values(array_unique($items));
    }

    /** @param array<string, mixed> $health @param array<string, mixed> $report @return array<int, string> */
    private static function nextActions(array $health, array $report): array
    {
        $actions = [];
        foreach ((array)($health['issues'] ?? []) as $issue) {
            if (is_array($issue) && trim((string)($issue['next_action'] ?? '')) !== '') {
                $actions[] = (string)$issue['next_action'];
            }
        }
        foreach ((array)($report['recommended_actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $text = (string)($action['action'] ?? $action['title'] ?? '');
            if (trim($text) !== '') {
                $actions[] = $text;
            }
        }
        return array_values(array_unique(array_map(
            static fn(string $item): string => self::safeText($item, 220),
            $actions
        )));
    }

    /**
     * @param array<string, mixed> $past
     * @param array<string, mixed> $present
     * @param array<string, mixed> $report
     * @return array<int, string>
     */
    private static function sourceRefs(
        array $past,
        array $present,
        array $report,
        \DateTimeImmutable $observedAt
    ): array {
        $sources = [
            '历史事实：online_daily_data 定稿快照',
            '今日进度：online_daily_data 实时快照，截至 ' . self::safeText(
                (string)($present['as_of_time'] ?? $observedAt->format('Y-m-d H:i:s')),
                24
            ),
        ];
        if ($report !== []) {
            $sources[] = '研判：ai_daily_reports 日报 '
                . self::safeText((string)($report['report_date'] ?? '日期未返回'), 24)
                . '，模型状态 ' . self::modelStatusText((string)($report['model_status'] ?? 'unknown'));
        } else {
            $sources[] = '研判：AI日报未生成或未成功读取';
        }
        return $sources;
    }

    private static function normalizeTime(?string $now): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        return $now === null || trim($now) === ''
            ? new \DateTimeImmutable('now', $timezone)
            : new \DateTimeImmutable($now, $timezone);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function modelStatusText(string $status): string
    {
        return match (strtolower(trim($status))) {
            'ok' => '可用',
            'not_requested' => '规则研判（未调用AI模型）',
            'blocked_by_data_quality' => '数据质量门禁阻塞',
            'failed' => '生成失败',
            'invalid_output' => '结果未通过校验',
            default => '待确认',
        };
    }

    private static function safeText(string $value, int $limit): string
    {
        return mb_strcut(trim(preg_replace('/[\r\n]+/', ' ', $value) ?? ''), 0, $limit, 'UTF-8');
    }
}
