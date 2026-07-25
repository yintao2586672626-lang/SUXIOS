<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds the sanitized, source-backed model used by enterprise WeChat
 * operating-monitor image cards.
 *
 * This service never collects OTA data and never sends a message. It only
 * consumes already-normalized temporal facts and data-health results.
 */
final class WechatMonitorVisualCardService
{
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** @var array<string, array{label:string,unit:string}> */
    private const METRICS = [
        'ota_revenue' => ['label' => '收益', 'unit' => '元'],
        'ota_orders' => ['label' => '订单', 'unit' => '单'],
        'ota_room_nights' => ['label' => '间夜', 'unit' => '间夜'],
        'ota_list_exposure' => ['label' => '列表曝光', 'unit' => '次'],
        'ota_detail_exposure' => ['label' => '详情浏览', 'unit' => '次'],
        'ota_order_submit' => ['label' => '提交订单', 'unit' => '次'],
    ];

    /**
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $insight
     * @param array<string,mixed> $health
     * @param array<string,mixed> $aiDaily
     * @return array<string,mixed>
     */
    public function buildModel(
        array $hotel,
        array $insight,
        array $health,
        array $aiDaily,
        string $observedAt
    ): array {
        $observed = $this->dateTime($observedAt);
        $targetDate = $observed->modify('-1 day')->format('Y-m-d');
        $past = is_array($insight['past'] ?? null) ? $insight['past'] : [];
        $present = is_array($insight['present'] ?? null) ? $insight['present'] : [];
        $pastStatus = $this->status((string)($past['status'] ?? 'empty'));
        $presentStatus = $this->status((string)($present['status'] ?? 'empty'));
        $pastSeries = array_values(array_filter((array)($past['series'] ?? []), 'is_array'));
        $factAuthorityBlocked = $this->hasFactAuthorityBlocker($health);
        if ($factAuthorityBlocked) {
            $pastStatus = 'blocked';
            $presentStatus = 'blocked';
        }
        $latestFinalDate = $this->latestSeriesDate($pastSeries);
        $p0Ready = $this->p0Ready($health);
        $gaps = $this->gaps($health, $past, $present, $p0Ready);
        $metrics = $this->metricRows(
            (array)($past['metrics'] ?? []),
            (array)($present['metrics'] ?? []),
            (array)($present['comparison_to_latest_final'] ?? []),
            $pastSeries,
            $pastStatus,
            $presentStatus,
            $latestFinalDate
        );
        $trend = $this->trend($pastSeries, $pastStatus);
        $judgment = $this->judgment($aiDaily, $targetDate);

        $cardType = $metrics === []
            ? 'gap'
            : ($gaps === [] ? 'facts' : 'partial');
        $statusLabel = match ($cardType) {
            'facts' => '事实已取得',
            'partial' => '部分可用',
            default => '数据未齐',
        };

        return [
            'schema' => 'suxi.wecom.monitor.visual-card.v1',
            'card_type' => $cardType,
            'status_label' => $statusLabel,
            'hotel' => [
                'id' => max(0, (int)($hotel['id'] ?? 0)),
                'name' => $this->text((string)($hotel['name'] ?? '未命名门店'), 80),
            ],
            'observed_at' => $observed->format('Y-m-d H:i:s'),
            'target_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'scope_label' => '已授权 OTA 渠道，不代表全酒店完整经营结果',
            'present' => [
                'status' => $presentStatus,
                'status_label' => $this->statusLabel($presentStatus),
                'as_of_time' => $this->text((string)($present['as_of_time'] ?? ''), 24),
            ],
            'latest_final' => [
                'status' => $pastStatus,
                'status_label' => $this->statusLabel($pastStatus),
                'date' => $latestFinalDate,
                // `partial` means only part of the authorized channel facts
                // are available.  Calling it a final daily result would turn
                // an honest gap into a stronger claim than the evidence.
                'column_label' => $pastStatus === 'ready' ? '最近定稿' : '最近已取得',
            ],
            'metrics' => $metrics,
            'trend' => $trend,
            'judgment' => $judgment,
            'gaps' => $gaps,
            'next_action' => $gaps === []
                ? '等待下一小时更新，并以最新定稿回读为准。'
                : ($p0Ready
                    ? '核心 OTA 事实已验证；辅助字段将在后续采集中补充，不影响当前已验证事实展示。'
                    : '请在“昨日经营闭环”补齐缺失数据后重新生成图卡。'),
            'sources' => [
                'online_daily_data（已保存并回读的 OTA 渠道事实）',
                'ai_daily_reports（仅使用目标日且状态可用的研判）',
            ],
            'truth_rules' => [
                'missing_values_are_null' => true,
                'old_data_not_used_as_today' => true,
                'zero_only_when_verified_numeric_fact' => true,
            ],
        ];
    }

    /**
     * Build the image message body accepted by an enterprise WeChat group
     * robot. The existing delivery service can send this payload unchanged.
     *
     * @return array{msgtype:string,image:array{base64:string,md5:string}}
     */
    public function imagePayloadFromFile(string $imagePath): array
    {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new \InvalidArgumentException('图卡文件不存在或不可读取。');
        }
        $size = filesize($imagePath);
        if (!is_int($size) || $size <= 0) {
            throw new \RuntimeException('图卡文件为空。');
        }
        if ($size > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('图卡超过企业微信图片消息 2MB 限制。');
        }
        $bytes = file_get_contents($imagePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('图卡读取失败。');
        }
        if (!$this->isPng($bytes) && !$this->isJpeg($bytes)) {
            throw new \InvalidArgumentException('企业微信图卡只接受 PNG 或 JPEG。');
        }

        return [
            'msgtype' => 'image',
            'image' => [
                'base64' => base64_encode($bytes),
                'md5' => md5($bytes),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $pastMetrics
     * @param array<string,mixed> $presentMetrics
     * @param array<string,mixed> $comparison
     * @param array<int,array<string,mixed>> $pastSeries
     * @return array<int,array<string,mixed>>
     */
    private function metricRows(
        array $pastMetrics,
        array $presentMetrics,
        array $comparison,
        array $pastSeries,
        string $pastStatus,
        string $presentStatus,
        string $latestFinalDate
    ): array {
        $allowPast = in_array($pastStatus, ['ready', 'partial'], true);
        $allowPresent = in_array($presentStatus, ['ready', 'partial'], true);
        $rows = [];
        foreach (self::METRICS as $key => $meta) {
            $compare = is_array($comparison[$key] ?? null) ? $comparison[$key] : [];
            $today = $allowPresent
                ? $this->numberOrNull($compare['current_value'] ?? $presentMetrics[$key] ?? null)
                : null;
            $latest = $allowPast
                ? $this->numberOrNull($compare['latest_final_value'] ?? $pastMetrics[$key] ?? null)
                : null;
            if ($today === null && $latest === null) {
                continue;
            }
            $usedComparisonValue = $allowPast && $this->numberOrNull($compare['latest_final_value'] ?? null) !== null;
            $referenceDate = $usedComparisonValue
                ? $this->date((string)($compare['latest_final_date'] ?? ''))
                : '';
            if ($referenceDate === '') {
                $referenceDate = $this->latestMetricDate($pastSeries, $key);
            }
            if ($referenceDate === '') {
                $referenceDate = $latestFinalDate;
            }
            $change = null;
            if ($today !== null && $latest !== null && $latest != 0.0) {
                $change = round(($today - $latest) / abs($latest) * 100, 1);
            }
            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'unit' => $meta['unit'],
                'today_value' => $today,
                'latest_final_value' => $latest,
                'latest_final_date' => $referenceDate,
                'change_percent' => $change,
                'today_status' => $today === null ? 'missing' : 'fact',
                'reference_status' => $latest === null ? 'missing' : 'fact',
            ];
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $series */
    private function latestMetricDate(array $series, string $metricKey): string
    {
        $latest = '';
        foreach ($series as $row) {
            $date = $this->date((string)($row['date'] ?? ''));
            if ($date !== '' && $this->numberOrNull($row[$metricKey] ?? null) !== null && $date > $latest) {
                $latest = $date;
            }
        }
        return $latest;
    }

    /**
     * @param array<int,array<string,mixed>> $series
     * @return array<string,mixed>
     */
    private function trend(array $series, string $pastStatus): array
    {
        if (!in_array($pastStatus, ['ready', 'partial'], true)) {
            return $this->emptyTrend('历史事实尚未取得，未生成趋势图。');
        }
        foreach (array_slice(array_keys(self::METRICS), 0, 3) as $metricKey) {
            $points = [];
            foreach ($series as $row) {
                $date = $this->date((string)($row['date'] ?? ''));
                $value = $this->numberOrNull($row[$metricKey] ?? null);
                if ($date === '' || $value === null) {
                    continue;
                }
                $points[] = ['date' => $date, 'value' => $value];
            }
            $points = array_slice($points, -14);
            if (count($points) < 2) {
                continue;
            }
            return [
                'status' => 'ready',
                'metric_key' => $metricKey,
                'label' => self::METRICS[$metricKey]['label'] . '趋势',
                'unit' => self::METRICS[$metricKey]['unit'],
                'points' => $points,
                'note' => '仅展示已保存并回读的 OTA 渠道历史事实。',
            ];
        }
        return $this->emptyTrend('同一指标少于 2 个有效日期，未生成虚假趋势。');
    }

    /** @return array<string,mixed> */
    private function emptyTrend(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'metric_key' => null,
            'label' => '趋势暂不可用',
            'unit' => '',
            'points' => [],
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $aiDaily
     * @return array<string,mixed>
     */
    private function judgment(array $aiDaily, string $targetDate): array
    {
        $report = is_array($aiDaily['report'] ?? null) ? $aiDaily['report'] : [];
        if ($report === []) {
            return [
                'status' => 'unverified',
                'label' => '研判未验证',
                'text' => '目标日经营日报尚未生成，当前图卡只展示事实和缺口。',
                'source_date' => null,
            ];
        }
        $reportDate = $this->date((string)($report['report_date'] ?? ''));
        $modelStatus = strtolower(trim((string)($report['model_status'] ?? 'unknown')));
        $summary = $this->text((string)($report['summary'] ?? ''), 360);
        if ($reportDate !== $targetDate) {
            return [
                'status' => 'unverified',
                'label' => '研判未验证',
                'text' => '最近研判与目标日不一致，不沿用旧结论。',
                'source_date' => $reportDate !== '' ? $reportDate : null,
            ];
        }
        if ($modelStatus === 'ok' && $summary !== '') {
            return [
                'status' => 'ai',
                'label' => 'AI研判',
                'text' => $summary,
                'source_date' => $reportDate,
            ];
        }
        // A rule-only daily summary may be produced by a different aggregate
        // path than the temporal facts used in this card.  Until that report
        // carries metric-level evidence anchors, do not place its free-text
        // totals next to the exact-date facts: a disagreement would look like
        // a confirmed conclusion to the hotel owner.  The stored report is
        // retained for administrators; this card stays facts-and-gaps only.
        if ($modelStatus === 'not_requested' && $summary !== '') {
            return [
                'status' => 'unverified',
                'label' => '研判待核对',
                'text' => '目标日规则日报尚未完成与本卡事实的一致性核对，当前只展示已保存并回读的 OTA 事实和数据缺口。',
                'source_date' => $reportDate,
            ];
        }
        return [
            'status' => 'unverified',
            'label' => '研判未验证',
            'text' => '目标日报状态不可用于经营结论，当前只展示事实和缺口。',
            'source_date' => $reportDate !== '' ? $reportDate : null,
        ];
    }

    /**
     * @param array<string,mixed> $health
     * @param array<string,mixed> $past
     * @param array<string,mixed> $present
     * @return array<int,string>
     */
    private function gaps(array $health, array $past, array $present, bool $p0Ready = false): array
    {
        $gaps = [];
        foreach ((array)($health['issues'] ?? []) as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $code = strtolower(trim((string)($issue['code'] ?? '')));
            // An exact P0 receipt has already proved the core channel facts.
            // Health retains diagnostic detail from every auxiliary row, which
            // is useful for administrators but should not turn into opaque
            // internal codes in an owner-facing card.
            if ($p0Ready && !$this->isFactAuthorityBlockerCode($code)) {
                continue;
            }
            $platform = strtolower(trim((string)($issue['platform'] ?? '')));
            $prefix = match ($platform) {
                'ctrip' => '携程：',
                'meituan' => '美团：',
                default => '',
            };
            $message = $this->issueMessage($issue);
            $this->pushGap($gaps, $prefix . $message);
        }
        foreach ([$past, $present] as $partIndex => $part) {
            if ($p0Ready && $partIndex === 0) {
                // Yesterday's exact P0 receipt is stronger than stale
                // diagnostic residue attached to the historical insight.
                continue;
            }
            if ($p0Ready && $partIndex === 1
                && !in_array($this->status((string)($part['status'] ?? 'empty')), ['ready', 'partial'], true)) {
                $this->pushGap($gaps, '今天尚未取得实时快照。');
                continue;
            }
            foreach ((array)($part['data_gaps'] ?? []) as $gap) {
                $message = is_array($gap)
                    ? (string)($gap['message'] ?? $gap['label'] ?? $gap['reason'] ?? $gap['code'] ?? '')
                    : (string)$gap;
                $this->pushGap($gaps, $message);
            }
        }
        if ($p0Ready) {
            $this->pushGap($gaps, '核心 OTA 事实已验证；其余为辅助字段缺口，不以 0 或旧数据代替。');
        }
        return array_slice(array_values(array_unique($gaps)), 0, 6);
    }

    /** @param array<string,mixed> $health */
    private function p0Ready(array $health): bool
    {
        return strtolower(trim((string)($health['p0_downstream_gate']['status'] ?? ''))) === 'ready';
    }

    /** @param array<string,mixed> $issue */
    private function issueMessage(array $issue): string
    {
        $code = strtolower(trim((string)($issue['code'] ?? '')));
        $localized = [
            'hotel_tenant_scope_missing' => '门店缺少权威租户范围，当前记录不能用于报告。',
            'binding_missing' => '未找到启用中的门店数据源绑定。',
            'login_expired' => '平台登录状态已过期或需要重新登录。',
            'latest_collection_partial' => '最近一次采集只完成部分数据，快照不足以生成报告。',
            'target_date_missing' => '目标日期没有可回读的数据。',
            'future_dated_for_target' => '最新保存日期晚于目标日期，不能替代目标日事实。',
            'stale_before_target' => '最新保存日期早于目标日期，不能用旧数据替代。',
            'hotel_scope_mismatch' => '回读记录不属于当前门店。',
            'tenant_scope_missing' => '已保存记录缺少租户范围，无法确认数据归属。',
            'tenant_scope_mismatch' => '回读记录与门店租户范围不一致。',
            'data_source_binding_missing' => '已保存记录缺少数据源绑定，无法确认 Profile 或门店身份。',
            'data_source_hotel_mismatch' => '数据源编号未绑定到当前门店或平台。',
            'validation_failed' => '回读记录未通过数据质量校验。',
            'validation_partial' => '目标日已有部分可回读事实，但字段证据尚未补齐。',
            'readback_unverified' => '记录已保存但未通过数据库回读校验。',
            'field_evidence_missing' => '目标日记录缺少可识别的字段或来源证据。',
            'core_business_metrics_missing' => '目标日缺少可回读的 OTA 经营核心指标。',
        ][$code] ?? '';
        if ($localized !== '') {
            return $localized;
        }
        $message = (string)($issue['message'] ?? $issue['label'] ?? '');
        if ($message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1) {
            return $message;
        }
        return $code !== '' ? '数据校验未通过，请按缺口代码核查：' . $code : '数据校验未通过。';
    }

    /** @param array<string,mixed> $health */
    private function hasFactAuthorityBlocker(array $health): bool
    {
        foreach ((array)($health['issues'] ?? []) as $issue) {
            if (is_array($issue) && $this->isFactAuthorityBlockerCode(strtolower(trim((string)($issue['code'] ?? ''))))) {
                return true;
            }
        }
        return false;
    }

    private function isFactAuthorityBlockerCode(string $code): bool
    {
        return in_array($code, [
            'hotel_tenant_scope_missing', 'binding_missing', 'hotel_scope_mismatch',
            'tenant_scope_missing', 'tenant_scope_mismatch',
            'data_source_binding_missing', 'data_source_hotel_mismatch',
            'readback_unverified', 'validation_failed',
        ], true);
    }

    /** @param array<int,string> $gaps */
    private function pushGap(array &$gaps, string $message): void
    {
        $message = $this->text($message, 160);
        if ($message !== '') {
            $gaps[] = $message;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $series
     */
    private function latestSeriesDate(array $series): string
    {
        for ($index = count($series) - 1; $index >= 0; $index--) {
            $date = $this->date((string)($series[$index]['date'] ?? ''));
            if ($date !== '') {
                return $date;
            }
        }
        return '';
    }

    private function numberOrNull(mixed $value): ?float
    {
        if (is_array($value)) {
            foreach (['latest_value', 'value', 'current_value'] as $key) {
                if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                    return (float)$value[$key];
                }
            }
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['ready', 'partial', 'empty', 'blocked'], true)
            ? $status
            : 'empty';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready' => '已取得',
            'partial' => '部分可用',
            'blocked' => '暂不可用',
            default => '暂未取得',
        };
    }

    private function date(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function dateTime(string $value): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        if (trim($value) === '') {
            throw new \InvalidArgumentException('图卡观察时间无效。');
        }
        try {
            $date = new \DateTimeImmutable(trim($value), $timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('图卡观察时间无效。');
        }
        return $date->setTimezone($timezone);
    }

    private function text(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, max(1, $limit), 'UTF-8');
    }

    private function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
    }

    private function isJpeg(string $bytes): bool
    {
        return str_starts_with($bytes, "\xff\xd8\xff");
    }
}
