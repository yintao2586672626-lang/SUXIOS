<?php
declare(strict_types=1);

namespace app\service;

/**
 * Renders one persisted OTA competition bundle for Enterprise WeChat.
 *
 * This service never collects or recalculates OTA data. Lite and flagship
 * messages are two views of the same saved analysis bundle.
 */
final class WechatCompetitionReportRendererService
{
    public const EDITION_LITE = 'lite';
    public const EDITION_FLAGSHIP = 'flagship';

    /**
     * @param array<string, mixed> $report
     * @return array{
     *   payload: array{msgtype: string, markdown: array{content: string}},
     *   report_edition: string,
     *   status_only: bool,
     *   source_fingerprint: string,
     *   bundle_id: string
     * }
     */
    public function render(array $report, string $hotelName, string $edition): array
    {
        $edition = $this->normalizeEdition($edition);
        $bundle = is_array($report['competition_circle_bundle'] ?? null)
            ? $report['competition_circle_bundle']
            : [];
        if ($bundle === []) {
            throw new \InvalidArgumentException('competition_circle_bundle_missing');
        }

        $quality = is_array($bundle['quality'] ?? null) ? $bundle['quality'] : [];
        $qualityStatus = strtolower(trim((string)($quality['status'] ?? 'blocked')));
        $statusOnly = $qualityStatus !== 'available'
            || ($quality['decision_eligible'] ?? false) !== true;
        $sourceFingerprint = trim((string)($bundle['source_fingerprint'] ?? ''));
        $bundleId = trim((string)($bundle['bundle_id'] ?? ''));

        $lines = [
            '# 宿析OS OTA竞争汇报（' . ($edition === self::EDITION_FLAGSHIP ? '旗舰版' : '简版') . '）',
            '> 门店：' . $this->safeText($hotelName, 80),
            '> 数据日期：' . $this->safeText((string)($report['report_date'] ?? '未返回'), 24),
            '> 数据状态：' . $this->qualityLabel($qualityStatus),
            '',
        ];

        if ($edition === self::EDITION_FLAGSHIP) {
            $this->appendFlagship($lines, $report, $bundle, $statusOnly);
        } else {
            $this->appendLite($lines, $report, $bundle, $statusOnly);
        }

        $lines[] = '';
        $lines[] = '> 范围：仅限携程/美团 OTA 渠道，不代表全酒店经营事实。';
        $lines[] = '> 边界：只读取已保存日报；不触发采集，不自动改价、库存或投放。';
        if ($sourceFingerprint !== '') {
            $lines[] = '> 来源指纹：' . substr($sourceFingerprint, 0, 16);
        }

        return [
            'payload' => [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
                ],
            ],
            'report_edition' => $edition,
            'status_only' => $statusOnly,
            'source_fingerprint' => $sourceFingerprint,
            'bundle_id' => $bundleId,
        ];
    }

    /**
     * Render the first, compact WeCom message. Detailed groups, evidence and
     * gaps are shown in the visual card that follows this message.
     *
     * @param array<string, mixed> $report
     * @return array{
     *   payload: array{msgtype: string, markdown: array{content: string}},
     *   report_edition: string,
     *   status_only: bool,
     *   source_fingerprint: string,
     *   bundle_id: string
     * }
     */
    public function renderCompact(array $report, string $hotelName, string $edition): array
    {
        $edition = $this->normalizeEdition($edition);
        $bundle = is_array($report['competition_circle_bundle'] ?? null)
            ? $report['competition_circle_bundle']
            : [];
        if ($bundle === []) {
            throw new \InvalidArgumentException('competition_circle_bundle_missing');
        }

        $quality = is_array($bundle['quality'] ?? null) ? $bundle['quality'] : [];
        $qualityStatus = strtolower(trim((string)($quality['status'] ?? 'blocked')));
        $statusOnly = $qualityStatus !== 'available'
            || ($quality['decision_eligible'] ?? false) !== true;
        $sourceFingerprint = trim((string)($bundle['source_fingerprint'] ?? ''));
        $bundleId = trim((string)($bundle['bundle_id'] ?? ''));
        $lines = [
            '# 宿析OS OTA竞争商圈',
            '> ' . $this->safeText($hotelName, 80)
                . '｜' . $this->safeText((string)($report['report_date'] ?? '未返回'), 24)
                . '｜' . ($edition === self::EDITION_FLAGSHIP ? '旗舰版' : '简版'),
            '> 数据状态：' . $this->qualityLabel($qualityStatus),
            '',
            '**本次结论**',
        ];

        $judgments = $this->platformJudgments($bundle);
        if ($statusOnly || $judgments === []) {
            $lines[] = '证据门槛尚未通过；本次只展示已保存事实、候选竞品和数据缺口，不生成经营动作。';
        } else {
            foreach (array_slice($judgments, 0, 2) as $judgment) {
                $lines[] = '- ' . $judgment;
            }
        }

        $competitors = $this->focusCompetitorNames($bundle, 3);
        if ($competitors !== []) {
            $lines[] = '';
            $lines[] = '**优先核对竞品**';
            $lines[] = implode('、', $competitors);
        }

        $gapCount = count(array_values(array_filter(
            (array)($quality['data_gaps'] ?? []),
            'is_array'
        )));
        $lines[] = '';
        $lines[] = '> 随后图卡：渠道状态、竞品分组、'
            . ($statusOnly ? '数据缺口与行动门槛' : '行动建议与复盘条件')
            . ($gapCount > 0 ? '（当前缺口 ' . $gapCount . ' 项）' : '');
        $lines[] = '> 范围：仅限携程/美团OTA渠道；不自动改价、库存或投放。';
        if ($sourceFingerprint !== '') {
            $lines[] = '> 来源指纹：' . substr($sourceFingerprint, 0, 16);
        }

        return [
            'payload' => [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => mb_strcut(implode("\n", $lines), 0, 1800, 'UTF-8'),
                ],
            ],
            'report_edition' => $edition,
            'status_only' => $statusOnly,
            'source_fingerprint' => $sourceFingerprint,
            'bundle_id' => $bundleId,
        ];
    }

    public function normalizeEdition(string $edition): string
    {
        $edition = strtolower(trim($edition));
        if ($edition === '') {
            return self::EDITION_LITE;
        }
        if (!in_array($edition, [self::EDITION_LITE, self::EDITION_FLAGSHIP], true)) {
            throw new \InvalidArgumentException('wecom_report_edition_must_be_lite_or_flagship');
        }

        return $edition;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, mixed> $report
     * @param array<string, mixed> $bundle
     */
    private function appendLite(array &$lines, array $report, array $bundle, bool $statusOnly): void
    {
        $summary = trim((string)($report['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = '**昨日概览**';
            $lines[] = $this->safeText($summary, 260);
            $lines[] = '';
        }

        $judgments = $this->platformJudgments($bundle);
        $lines[] = '**核心判断**';
        if ($statusOnly || $judgments === []) {
            $lines[] = '- 当前证据不足，本次只汇报已返回事实与数据缺口。';
        } else {
            foreach (array_slice($judgments, 0, 2) as $judgment) {
                $lines[] = '- ' . $judgment;
            }
        }

        $competitors = $this->focusCompetitorNames($bundle, 5);
        if ($competitors !== []) {
            $lines[] = '';
            $lines[] = '**重点竞品**';
            $lines[] = implode('、', $competitors);
        }

        $this->appendActions($lines, $bundle, $statusOnly, 3, false);
        $this->appendGaps($lines, $bundle, 3);
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, mixed> $report
     * @param array<string, mixed> $bundle
     */
    private function appendFlagship(array &$lines, array $report, array $bundle, bool $statusOnly): void
    {
        $summary = trim((string)($report['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = '**管理摘要**';
            $lines[] = $this->safeText($summary, 300);
            $lines[] = '';
        }

        $lines[] = '**渠道角色与第一矛盾**';
        $judgments = $this->platformJudgments($bundle);
        if ($statusOnly || $judgments === []) {
            $lines[] = '- 证据门槛未通过，不输出渠道角色、确定归因或价格实验。';
        } else {
            foreach ($judgments as $judgment) {
                $lines[] = '- ' . $judgment;
            }
        }

        $groups = [
            'direct' => '直接竞品',
            'attack_benchmark' => '进攻标杆',
            'traffic_benchmark' => '流量标杆',
            'conversion_benchmark' => '转化标杆',
        ];
        $groupLines = [];
        foreach ($groups as $key => $label) {
            $names = $this->groupCompetitorNames($bundle, $key, 3);
            if ($names !== []) {
                $groupLines[] = '- ' . $label . '：' . implode('、', $names);
            }
        }
        if ($groupLines !== []) {
            $lines[] = '';
            $lines[] = '**竞品分组**';
            array_push($lines, ...$groupLines);
        }

        if (!$statusOnly) {
            $experimentLines = [];
            foreach (['ctrip' => '携程', 'meituan' => '美团'] as $platform => $label) {
                $analysis = is_array($bundle['analysis'][$platform] ?? null)
                    ? $bundle['analysis'][$platform]
                    : [];
                $experiment = is_array($analysis['price_experiment'] ?? null)
                    ? $analysis['price_experiment']
                    : [];
                if ($experiment === []) {
                    continue;
                }
                $hypothesis = $this->safeText((string)($experiment['hypothesis'] ?? ''), 180);
                $window = $this->safeText((string)($experiment['observation_window'] ?? ''), 100);
                if ($hypothesis !== '') {
                    $experimentLines[] = '- ' . $label . '：' . $hypothesis
                        . ($window !== '' ? '；观察：' . $window : '');
                }
            }
            if ($experimentLines !== []) {
                $lines[] = '';
                $lines[] = '**价格实验（人工确认后）**';
                array_push($lines, ...$experimentLines);
            }
        }

        $this->appendActions($lines, $bundle, $statusOnly, 3, true);
        $this->appendGaps($lines, $bundle, 6);
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, mixed> $bundle
     */
    private function appendActions(
        array &$lines,
        array $bundle,
        bool $statusOnly,
        int $limit,
        bool $includeReviewContract
    ): void {
        $lines[] = '';
        $lines[] = $statusOnly ? '**行动门槛**' : '**今日建议（必须人工确认）**';
        if ($statusOnly) {
            $lines[] = '- 数据未完整，本次不生成可执行调价、库存或投放建议。';
            return;
        }

        $recommendations = is_array($bundle['recommendations'] ?? null)
            ? $bundle['recommendations']
            : [];
        $items = array_values(array_filter((array)($recommendations['items'] ?? []), 'is_array'));
        if ($items === []) {
            $lines[] = '- 当前没有通过证据门槛的行动建议。';
            return;
        }

        foreach (array_slice($items, 0, $limit) as $index => $item) {
            $action = $this->safeText(
                (string)($item['action'] ?? $item['title'] ?? $item['reason'] ?? ''),
                $includeReviewContract ? 210 : 170
            );
            if ($action === '') {
                continue;
            }
            $line = ($index + 1) . '. ' . $action;
            if ($includeReviewContract) {
                $window = $this->safeText((string)($item['review_window'] ?? ''), 90);
                $rollback = $this->safeText((string)($item['rollback_condition'] ?? ''), 110);
                if ($window !== '') {
                    $line .= '；观察：' . $window;
                }
                if ($rollback !== '') {
                    $line .= '；回滚：' . $rollback;
                }
            }
            $lines[] = $line;
        }
        $lines[] = '- auto_write_ota=false；所有平台操作继续由人工批准。';
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, mixed> $bundle
     */
    private function appendGaps(array &$lines, array $bundle, int $limit): void
    {
        $quality = is_array($bundle['quality'] ?? null) ? $bundle['quality'] : [];
        $gaps = array_values(array_filter((array)($quality['data_gaps'] ?? []), 'is_array'));
        if ($gaps === []) {
            return;
        }

        $lines[] = '';
        $lines[] = '**数据缺口（不以0代替）**';
        foreach (array_slice($gaps, 0, $limit) as $gap) {
            $message = (string)($gap['message'] ?? $gap['code'] ?? '未说明缺口');
            $lines[] = '- ' . $this->safeText($message, 160);
        }
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<int, string>
     */
    private function platformJudgments(array $bundle): array
    {
        $result = [];
        foreach (['ctrip' => '携程', 'meituan' => '美团'] as $platform => $label) {
            $analysis = is_array($bundle['analysis'][$platform] ?? null)
                ? $bundle['analysis'][$platform]
                : [];
            if (($analysis['status'] ?? '') !== 'available') {
                continue;
            }
            $role = $this->safeText((string)($analysis['channel_role'] ?? ''), 60);
            $conflict = $this->safeText((string)($analysis['first_conflict'] ?? ''), 180);
            if ($role === '' && $conflict === '') {
                continue;
            }
            $result[] = $label . '｜角色：' . ($role !== '' ? $role : '未形成')
                . '｜第一矛盾：' . ($conflict !== '' ? $conflict : '未形成');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<int, string>
     */
    private function focusCompetitorNames(array $bundle, int $limit): array
    {
        $names = array_merge(
            $this->groupCompetitorNames($bundle, 'direct', $limit),
            $this->groupCompetitorNames($bundle, 'attack_benchmark', $limit)
        );

        return array_slice(array_values(array_unique($names)), 0, $limit);
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<int, string>
     */
    private function groupCompetitorNames(array $bundle, string $group, int $limit): array
    {
        $names = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $items = array_values(array_filter(
                (array)($bundle['candidate_competitors'][$platform][$group] ?? []),
                'is_array'
            ));
            foreach ($items as $item) {
                $name = $this->safeText((string)($item['hotel_name'] ?? ''), 60);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_slice(array_values(array_unique($names)), 0, $limit);
    }

    private function qualityLabel(string $status): string
    {
        return match ($status) {
            'available' => '可用于人工决策',
            'partial' => '部分可用，仅作情况汇报',
            'synthetic' => '模拟测试，不可执行',
            default => '已阻断，仅显示数据缺口',
        };
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = str_replace(['<', '>'], ['＜', '＞'], $value);

        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }
}
