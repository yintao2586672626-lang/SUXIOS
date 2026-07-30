<?php
declare(strict_types=1);

namespace app\service;

use app\model\OperationLog;
use think\facade\Db;

/**
 * Sends an hourly, hotel-bound operating observation. Test and formal group
 * delivery use separate idempotency identities so a test receipt can never
 * suppress a deliberate formal delivery. It is deliberately
 * separate from the formal daily-report gate: incomplete OTA data is useful
 * as an explicit monitoring signal, but must never be presented as a final
 * daily conclusion.
 */
final class HourlyHotelMonitorWechatService
{
    public function __construct(
        private readonly ?CloudDataHealthService $healthService = null,
        private readonly ?TemporalInsightService $temporalService = null,
        private readonly ?CloudAutomationStateStore $stateStore = null,
        private readonly ?WechatRobotDeliveryService $deliveryService = null,
        private readonly ?AiDailyReportService $reportService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(
        int $hotelId,
        int $robotId,
        bool $push = true,
        ?string $now = null,
        bool $testOnly = true
    ): array
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('测试门店不存在或未启用。');
        }
        $robot = Db::name('competitor_wechat_robot')
            ->where('id', $robotId)
            ->where('store_id', $hotelId)
            ->where('status', 1)
            ->field('id,store_id,name,status')
            ->find();
        if (!is_array($robot)) {
            throw new \InvalidArgumentException('未找到该门店启用中的测试群机器人。');
        }
        if ($testOnly && !$this->isTestRobot((string)($robot['name'] ?? ''))) {
            throw new \InvalidArgumentException('为避免误发正式群，每小时监控只能指定名称含“测试”的机器人。');
        }

        $observedAt = $this->normalizeTime($now);
        $targetDate = $observedAt->modify('-1 day')->format('Y-m-d');
        $health = ($this->healthService ?? new CloudDataHealthService())
            ->inspectHotel($hotel, $targetDate, ['ctrip', 'meituan']);
        // The health view deliberately retains auxiliary field gaps.  Attach the
        // exact-date P0 receipt so the message does not mislabel an anchored,
        // dual-platform core loop as "Ctrip/Meituan data not complete".
        try {
            $health['p0_downstream_gate'] = (new P0OtaDownstreamGateService())
                ->resolveRuntime($targetDate, $hotelId, null, ['ctrip', 'meituan']);
        } catch (\Throwable) {
            $health['p0_downstream_gate'] = ['status' => 'unavailable'];
        }
        $insight = ($this->temporalService ?? new TemporalInsightService())
            ->overview([$hotelId], 30, 7, $observedAt->format('Y-m-d'));
        try {
            $aiDaily = ($this->reportService ?? new AiDailyReportService())->latest([$hotelId], $hotelId);
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
        $payload = self::buildPayload(
            $hotel,
            $insight,
            $health,
            $observedAt->format('Y-m-d H:i:s'),
            $aiDaily,
            $testOnly ? 'test' : 'formal'
        );

        if (!$push) {
            return [
                'status' => 'dry_run',
                'hotel_id' => $hotelId,
                'robot_id' => $robotId,
                'test_only' => $testOnly,
                'target_date' => $targetDate,
                'payload' => $payload,
            ];
        }

        $state = $this->stateStore ?? new CloudAutomationStateStore();
        $lock = $state->acquireLock();
        if (!is_resource($lock)) {
            return ['status' => 'in_progress', 'hotel_id' => $hotelId, 'robot_id' => $robotId, 'test_only' => $testOnly];
        }
        try {
            $record = $state->queueDelivery(
                'hourly_hotel_monitor',
                $hotelId,
                [
                    'monitor_hour' => $observedAt->format('Y-m-d-H'),
                    'robot_id' => $robotId,
                    'delivery_mode' => $testOnly ? 'test' : 'formal',
                    'target_date' => $targetDate,
                ],
                $payload,
                ['test_only' => $testOnly, 'collection_triggered' => false, 'report_generation_triggered' => false],
                'hourly-monitor:' . ($testOnly ? 'test' : 'formal') . ':' . $hotelId . ':' . $robotId . ':' . $observedAt->format('Y-m-d-H')
            );
            if (in_array((string)($record['status'] ?? ''), ['sent', 'sending', 'delivery_outcome_unknown'], true)) {
                return [
                    'status' => (string)($record['status'] ?? 'in_progress'),
                    'hotel_id' => $hotelId,
                    'robot_id' => $robotId,
                    'test_only' => $testOnly,
                    'delivery_key' => (string)($record['delivery_key'] ?? ''),
                    'idempotent_replay' => (string)($record['status'] ?? '') === 'sent',
                ];
            }
            $record = $state->beginDeliveryAttempt($record);
            $delivery = ($this->deliveryService ?? new WechatRobotDeliveryService())
                ->deliverToHotel($hotelId, $payload, [$robotId]);
            $record = $state->recordDeliveryAttempt($record, $delivery, 3);
            OperationLog::record(
                'wechat_monitor',
                $testOnly ? 'hourly_test_broadcast' : 'hourly_formal_broadcast',
                self::text((string)($hotel['name'] ?? ('门店 #' . $hotelId)), 80)
                    . ($testOnly ? '每小时经营监控测试群播报' : '每小时经营监控运营群播报'),
                0,
                $hotelId,
                ($delivery['delivery_status'] ?? '') === 'sent' ? null : '企业微信测试群未成功送达',
                [
                    'test_only' => $testOnly,
                    'robot_id' => $robotId,
                    'delivery_key' => (string)($record['delivery_key'] ?? ''),
                    'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
                    'target_date' => $targetDate,
                ]
            );
            return [
                'status' => (string)($record['status'] ?? 'failed'),
                'hotel_id' => $hotelId,
                'robot_id' => $robotId,
                'test_only' => $testOnly,
                'delivery_key' => (string)($record['delivery_key'] ?? ''),
                'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
                'sent_count' => (int)($delivery['sent_count'] ?? 0),
                'failed_count' => (int)($delivery['failed_count'] ?? 0),
            ];
        } finally {
            $state->releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $insight
     * @param array<string,mixed> $health
     * @param array<string,mixed> $aiDaily
     */
    public static function buildPayload(
        array $hotel,
        array $insight,
        array $health,
        string $observedAt,
        array $aiDaily = [],
        string $deliveryMode = 'test'
    ): array
    {
        $past = is_array($insight['past'] ?? null) ? $insight['past'] : [];
        $present = is_array($insight['present'] ?? null) ? $insight['present'] : [];
        $targetDate = self::targetDate($observedAt);
        $gaps = self::gaps($health, $past, $present);
        $lines = [
            '# ' . self::text((string)($hotel['name'] ?? '未命名门店'), 80) . '｜经营监控',
            '> ' . self::text($observedAt, 24) . ($deliveryMode === 'formal' ? '｜运营群' : '｜测试群'),
            '> 范围：已授权 OTA 渠道，不代表全酒店经营结果',
        ];

        self::appendMetrics(
            $lines,
            '今日进度｜事实',
            (array)($present['metrics'] ?? []),
            (string)($present['status'] ?? 'empty')
        );

        $lines[] = '';
        $lines[] = '**实时经营对比｜事实**';
        foreach (self::comparisonLines($past, $present) as $line) {
            $lines[] = '- ' . $line;
        }

        [$judgmentTitle, $judgmentText, $reportSource] = self::judgment(
            $aiDaily,
            $targetDate,
            $gaps
        );
        $lines[] = '';
        $lines[] = '**' . $judgmentTitle . '**';
        $lines[] = self::text($judgmentText, 420);

        $lines[] = '';
        $lines[] = '**缺口与下一步**';
        $p0Ready = self::p0Ready($health);
        $next = $gaps === []
            ? '等待下一小时更新。'
            : ($p0Ready
                ? '核心 OTA 事实已验证；辅助字段缺口将在后续采集中补充。'
                : '请在“昨日经营闭环”补齐数据后重试。');
        $lines[] = $gaps === []
            ? '- 当前已加载证据未记录阻塞缺口；仍以定稿回读为准。'
            : ($p0Ready
                ? '- 核心 OTA 事实已验证；仍有 ' . count($gaps) . ' 项辅助字段缺口，不以 0 或旧数据代替。'
                : '- 数据未齐：' . self::gapSummary($health, $gaps) . '，不以 0 或旧数据代替。');
        $lines[] = '- 下一步：' . self::text($next, 180);

        $snapshotTime = self::text((string)($present['as_of_time'] ?? ''), 24);
        $lines[] = '';
        $lines[] = '> 来源：online_daily_data（定稿/实时快照'
            . ($snapshotTime !== '' ? '，截至 ' . $snapshotTime : '')
            . '）；' . self::text($reportSource, 160);
        return ['msgtype' => 'markdown', 'markdown' => ['content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8')]];
    }

    /** @param array<int,string> $lines @param array<string,mixed> $metrics */
    private static function appendMetrics(array &$lines, string $title, array $metrics, string $status): void
    {
        $labels = ['ota_revenue' => '收益', 'ota_orders' => '订单', 'ota_room_nights' => '间夜', 'ota_list_exposure' => '曝光', 'ota_detail_exposure' => '浏览', 'ota_order_submit' => '提交订单'];
        $items = [];
        foreach ($labels as $key => $label) {
            $value = self::metricValue($metrics[$key] ?? null);
            if (is_numeric($value)) {
                $items[] = $label . '：' . rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
            }
        }
        $lines[] = '';
        $lines[] = '**' . $title . '**';
        if (!in_array(strtolower(trim($status)), ['ready', 'partial'], true)) {
            $lines[] = '- 尚未取得可展示数据（状态：' . self::statusText($status) . '）';
            return;
        }
        if ($items === []) {
            $lines[] = '- 尚未取得可展示数据（状态：' . self::statusText($status) . '）';
            return;
        }
        foreach ($items as $item) {
            $lines[] = '- ' . $item;
        }
    }

    /**
     * @param array<string,mixed> $past
     * @param array<string,mixed> $present
     * @return array<int,string>
     */
    private static function comparisonLines(array $past, array $present): array
    {
        $labels = [
            'ota_revenue' => '收益',
            'ota_orders' => '订单',
            'ota_room_nights' => '间夜',
        ];
        $comparison = is_array($present['comparison_to_latest_final'] ?? null)
            ? $present['comparison_to_latest_final']
            : [];
        $pastAvailable = in_array(strtolower(trim((string)($past['status'] ?? 'empty'))), ['ready', 'partial'], true);
        $presentAvailable = in_array(strtolower(trim((string)($present['status'] ?? 'empty'))), ['ready', 'partial'], true);
        $lines = [];
        foreach ($labels as $key => $label) {
            if (!$pastAvailable || !$presentAvailable) {
                break;
            }
            $row = is_array($comparison[$key] ?? null) ? $comparison[$key] : [];
            $current = $row['current_value'] ?? null;
            $latest = $row['latest_final_value'] ?? null;
            if (!is_numeric($current) || !is_numeric($latest)) {
                continue;
            }
            $change = is_numeric($row['change_percent'] ?? null)
                ? sprintf('%+.1f%%', (float)$row['change_percent'])
                : '变化率不可算';
            $date = self::text((string)($row['latest_final_date'] ?? '最近定稿日'), 20);
            $lines[] = $label . '：今日累计 ' . self::number((float)$current)
                . '，' . $date . ' ' . self::number((float)$latest) . '（' . $change . '）';
        }
        if ($lines !== []) {
            $lines[] = '口径：今日累计 vs 最近完整日，仅用于观察。';
            return $lines;
        }

        $pastItems = $pastAvailable ? self::metricItems((array)($past['metrics'] ?? [])) : [];
        $presentItems = $presentAvailable ? self::metricItems((array)($present['metrics'] ?? [])) : [];
        if ($pastItems !== []) {
            $series = array_values(array_filter((array)($past['series'] ?? []), 'is_array'));
            $latestDate = $series !== []
                ? self::text((string)($series[count($series) - 1]['date'] ?? ''), 20)
                : '';
            $lines[] = '最近定稿' . ($latestDate !== '' ? '（' . $latestDate . '）' : '')
                . '：' . implode('；', array_slice($pastItems, 0, 3));
        }
        if ($presentItems !== []) {
            $lines[] = '今日累计：' . implode('；', array_slice($presentItems, 0, 3));
        }
        $lines[] = '当前尚无同一指标的完整实时对比，不计算涨跌。';
        return $lines;
    }

    /** @param array<string,mixed> $metrics @return array<int,string> */
    private static function metricItems(array $metrics): array
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
            $value = self::metricValue($metrics[$key] ?? null);
            if (is_numeric($value)) {
                $items[] = $label . '：' . self::number((float)$value);
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $aiDaily
     * @param array<int,string> $gaps
     * @return array{0:string,1:string,2:string}
     */
    private static function judgment(array $aiDaily, string $targetDate, array $gaps): array
    {
        $report = is_array($aiDaily['report'] ?? null) ? $aiDaily['report'] : [];
        if ($report === []) {
            return [
                '收益判断｜未验证',
                $gaps === []
                    ? '目标日AI日报尚未生成；当前只确认指标可观察，不生成收益结论。'
                    : '目标日AI日报尚未生成；先补齐数据，再判断经营问题。',
                'ai_daily_reports（目标日报未生成）',
            ];
        }

        $reportDate = self::text((string)($report['report_date'] ?? ''), 24);
        $modelStatus = strtolower(trim((string)($report['model_status'] ?? 'unknown')));
        if ($reportDate !== $targetDate) {
            return [
                '收益判断｜未验证',
                '最近AI日报日期为 ' . ($reportDate !== '' ? $reportDate : '未返回')
                    . '，与目标日 ' . $targetDate . ' 不一致，不沿用旧结论。',
                'ai_daily_reports（最近日报 ' . ($reportDate !== '' ? $reportDate : '日期未返回') . '，未用于结论）',
            ];
        }

        $summary = self::text((string)($report['summary'] ?? ''), 360);
        if ($modelStatus === 'ok' && $summary !== '') {
            return [
                'AI研判｜日报 ' . $reportDate,
                $summary,
                'ai_daily_reports（' . $reportDate . '，模型状态可用）',
            ];
        }
        if ($modelStatus === 'not_requested' && $summary !== '') {
            return [
                '规则研判｜日报 ' . $reportDate,
                $summary,
                'ai_daily_reports（' . $reportDate . '，未调用AI模型）',
            ];
        }
        return [
            '收益判断｜未验证',
            '目标日日报状态为 ' . self::statusText($modelStatus) . '，当前不把它写成可用AI结论。',
            'ai_daily_reports（' . $reportDate . '，状态 ' . self::statusText($modelStatus) . '）',
        ];
    }

    private static function metricValue(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach (['latest_value', 'value', 'current_value'] as $key) {
            if (isset($value[$key]) && is_numeric($value[$key])) {
                return $value[$key];
            }
        }
        return null;
    }

    private static function statusText(string $status): string
    {
        return match (strtolower(trim($status))) {
            'ready' => '已取得',
            'partial' => '数据不完整',
            'empty' => '暂未取得',
            'blocked' => '暂不可用',
            'blocked_by_data_quality' => '数据质量门禁阻塞',
            'failed' => '生成失败',
            'invalid_output' => '结果未通过校验',
            'unknown' => '待确认',
            default => '待确认',
        };
    }

    private static function targetDate(string $observedAt): string
    {
        try {
            return (new \DateTimeImmutable($observedAt, new \DateTimeZone('Asia/Shanghai')))
                ->modify('-1 day')
                ->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** @param array<string,mixed> $health @param array<string,mixed> $past @param array<string,mixed> $present @return array<int,string> */
    private static function gaps(array $health, array $past, array $present): array
    {
        $gaps = [];
        foreach ((array)($health['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $gaps[] = (string)($issue['message'] ?? $issue['code'] ?? '数据校验未通过');
            }
        }
        foreach ([$past, $present] as $part) {
            foreach ((array)($part['data_gaps'] ?? []) as $gap) {
                $gaps[] = is_array($gap) ? (string)($gap['message'] ?? $gap['code'] ?? '') : (string)$gap;
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $gaps))));
    }

    /** @param array<string,mixed> $health @param array<int,string> $gaps */
    private static function gapSummary(array $health, array $gaps): string
    {
        $platforms = [];
        foreach ((array)($health['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $platform = strtolower(trim((string)($issue['platform'] ?? '')));
            if ($platform === 'ctrip') {
                $platforms[] = '携程';
            } elseif ($platform === 'meituan') {
                $platforms[] = '美团';
            }
        }
        $platforms = array_values(array_unique($platforms));
        return $platforms === [] ? ('存在 ' . count($gaps) . ' 项数据缺口') : implode('、', $platforms) . '数据未齐';
    }

    /** @param array<string,mixed> $health */
    private static function p0Ready(array $health): bool
    {
        return strtolower(trim((string)(($health['p0_downstream_gate']['status'] ?? '')))) === 'ready';
    }

    private function isTestRobot(string $name): bool
    {
        return str_contains($name, '测试');
    }

    private function normalizeTime(?string $now): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        return $now === null || trim($now) === '' ? new \DateTimeImmutable('now', $timezone) : new \DateTimeImmutable($now, $timezone);
    }

    private static function text(string $value, int $limit): string
    {
        return mb_strcut(trim(preg_replace('/[\r\n]+/', ' ', $value) ?? ''), 0, $limit, 'UTF-8');
    }
}
