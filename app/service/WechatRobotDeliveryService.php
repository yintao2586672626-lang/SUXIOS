<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class WechatRobotDeliveryService
{
    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, int> $onlyRobotIds
     * @return array<string, mixed>
     */
    public function deliverToHotel(int $hotelId, array $payload, array $onlyRobotIds = []): array
    {
        if ($hotelId <= 0) {
            return $this->emptyDelivery('binding_missing', $hotelId, 'hotel_scope_missing');
        }
        if (!$this->tableExists('competitor_wechat_robot')) {
            return $this->emptyDelivery('binding_missing', $hotelId, 'robot_table_missing');
        }

        $query = Db::name('competitor_wechat_robot')
            ->where('store_id', $hotelId)
            ->where('status', 1)
            ->order('id', 'asc');
        $onlyRobotIds = array_values(array_unique(array_filter(
            array_map('intval', $onlyRobotIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($onlyRobotIds !== []) {
            $query->whereIn('id', $onlyRobotIds);
        }
        $robots = $query->select()->toArray();
        if ($robots === []) {
            return $this->emptyDelivery('binding_missing', $hotelId, 'enabled_robot_binding_missing');
        }

        $sentCount = 0;
        $failures = [];
        foreach ($robots as $robot) {
            $robotId = (int)($robot['id'] ?? 0);
            $robotName = $this->safeText((string)($robot['name'] ?? ('机器人 #' . $robotId)), 80);
            try {
                $webhook = $this->revealWebhook((string)($robot['webhook'] ?? ''), $robotId);
            } catch (\Throwable) {
                $failures[] = [
                    'robot_id' => $robotId,
                    'name' => $robotName,
                    'reason' => 'Webhook解密失败，请检查应用密钥配置',
                ];
                continue;
            }
            if ($this->normalizeWebhook($webhook) === null) {
                $failures[] = [
                    'robot_id' => $robotId,
                    'name' => $robotName,
                    'reason' => 'Webhook无效或为空',
                ];
                continue;
            }

            $result = $this->transport !== null
                ? $this->normalizeTransportResult(call_user_func($this->transport, $webhook, $payload, $robotId))
                : $this->postJson($webhook, $payload);
            if (($result['success'] ?? false) === true) {
                $sentCount++;
                continue;
            }
            $failures[] = [
                'robot_id' => $robotId,
                'name' => $robotName,
                'reason' => $this->safeText((string)($result['error'] ?? '发送失败'), 180),
                'ambiguous' => ($result['ambiguous'] ?? false) === true,
            ];
        }

        $failedCount = count($failures);
        $status = $sentCount === count($robots)
            ? 'sent'
            : ($sentCount > 0 ? 'partial' : 'failed');
        return [
            'delivery_status' => $status,
            'hotel_id' => $hotelId,
            'robot_count' => count($robots),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $health
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    public function buildDailyReportPayload(array $report, string $hotelName, array $health = []): array
    {
        $readiness = is_array($report['result_readiness'] ?? null)
            ? $report['result_readiness']
            : (is_array($report['report_readiness'] ?? null) ? $report['report_readiness'] : []);
        $dataGaps = $this->reportDataGaps($report);
        $statusLabel = trim((string)($readiness['status_label'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = $dataGaps === [] ? '已生成（按已保存证据）' : '部分可用，存在数据缺口';
        }

        $lines = [
            '# 宿析OS AI经营日报',
            '> 门店：' . $this->safeText($hotelName, 80),
            '> 日期：' . $this->safeText((string)($report['report_date'] ?? '未返回'), 24),
            '> 数据状态：' . $this->safeText($statusLabel, 60),
            '',
            '**摘要**',
            $this->safeText((string)($report['summary'] ?? '当前日报未返回摘要。'), 500),
        ];

        $metrics = array_values(array_filter(
            (array)($report['yesterday_result']['metrics'] ?? []),
            'is_array'
        ));
        $metricLines = [];
        foreach (array_slice($metrics, 0, 6) as $metric) {
            $value = $metric['value'] ?? null;
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $metricLines[] = '- ' . $this->safeText((string)($metric['label'] ?? $metric['key'] ?? '指标'), 40)
                . '：' . $this->safeText((string)$value, 40)
                . $this->safeText((string)($metric['unit'] ?? ''), 12);
        }
        if ($metricLines !== []) {
            $lines[] = '';
            $lines[] = '**已返回指标**';
            array_push($lines, ...$metricLines);
        }

        if ($dataGaps !== []) {
            $lines[] = '';
            $lines[] = '**数据缺口（不以 0 代替）**';
            foreach (array_slice($dataGaps, 0, 4) as $gap) {
                $gapText = is_array($gap)
                    ? (string)($gap['message'] ?? $gap['label'] ?? $gap['code'] ?? '未说明缺口')
                    : (string)$gap;
                $lines[] = '- ' . $this->safeText($gapText, 180);
            }
        }

        $healthIssues = array_values(array_filter((array)($health['issues'] ?? []), 'is_array'));
        if ($healthIssues !== []) {
            $lines[] = '';
            $lines[] = '**巡检提醒**';
            foreach (array_slice($healthIssues, 0, 4) as $issue) {
                $lines[] = '- ' . $this->issueText($issue);
            }
        }

        $actions = array_values(array_filter((array)($report['recommended_actions'] ?? []), 'is_array'));
        if ($actions !== []) {
            $lines[] = '';
            $lines[] = '**建议动作（需人工确认）**';
            foreach (array_slice($actions, 0, 3) as $index => $action) {
                $actionText = (string)($action['action'] ?? $action['title'] ?? $action['reason'] ?? '未说明动作');
                $blocked = trim((string)($action['blocked_reason'] ?? ''));
                $lines[] = ($index + 1) . '. ' . $this->safeText($actionText, 180)
                    . ($blocked !== '' ? '（当前阻塞：' . $this->safeText($blocked, 120) . '）' : '');
            }
        }

        $scopeNote = (string)(
            $readiness['scope_note']
            ?? $report['report_scope']['scope_note']
            ?? '仅按本日报已保存的 OTA/经营证据展示，不自动代表全酒店完整经营事实。'
        );
        $lines[] = '';
        $lines[] = '> 范围说明：' . $this->safeText($scopeNote, 260);
        $lines[] = '> 本次发送只读取已保存日报，不触发 OTA 采集，也不改动平台数据。';

        return $this->markdownPayload($lines);
    }

    /**
     * @param array<string, mixed> $health
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    public function buildHealthAlertPayload(array $health, string $hotelName): array
    {
        $issues = array_values(array_filter((array)($health['issues'] ?? []), 'is_array'));
        $lines = [
            '# 宿析OS 数据健康预警',
            '> 门店：' . $this->safeText($hotelName, 80),
            '> 目标日期：' . $this->safeText((string)($health['target_date'] ?? '未返回'), 24),
            '> 状态：' . $this->safeText((string)($health['status'] ?? 'unverified'), 40),
            '',
            '**发现的问题**',
        ];
        if ($issues === []) {
            $lines[] = '- 当前未发现阻塞项。';
        } else {
            foreach (array_slice($issues, 0, 8) as $issue) {
                $lines[] = '- ' . $this->issueText($issue);
            }
        }

        $lines[] = '';
        $lines[] = '> 缺失、日期错误、门店不符或未回读的数据不会显示为 0，也不会进入 AI 经营结论。';
        $lines[] = '> 本预警只检查已保存状态；不会自动登录携程/美团，也不会触发重新采集。';
        return $this->markdownPayload($lines);
    }

    /**
     * @param array<int, array<string, mixed>> $reports
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    public function buildWeeklyDigestPayload(
        array $reports,
        string $hotelName,
        string $startDate,
        string $endDate
    ): array {
        $lines = [
            '# 宿析OS 周度复盘摘要',
            '> 门店：' . $this->safeText($hotelName, 80),
            '> 周期：' . $this->safeText($startDate, 16) . ' 至 ' . $this->safeText($endDate, 16),
            '> 已保存日报：' . count($reports) . ' / 7 天',
            '',
        ];
        if ($reports === []) {
            $lines[] = '**状态**';
            $lines[] = '- 本周期没有可回读的 AI 经营日报，未生成虚假的周度汇总。';
        } else {
            $lines[] = '**每日摘要**';
            foreach (array_slice($reports, 0, 7) as $report) {
                $date = $this->safeText((string)($report['report_date'] ?? '未知日期'), 16);
                $summary = $this->safeText((string)($report['summary'] ?? '未返回摘要'), 180);
                $gapCount = count($this->reportDataGaps($report));
                $lines[] = '- ' . $date . '：' . $summary . ($gapCount > 0 ? '（缺口 ' . $gapCount . ' 项）' : '');
            }
        }
        $lines[] = '';
        $lines[] = '> 本周报仅汇总已保存日报，不补齐缺失日期，不把 OTA 渠道证据扩展成全酒店财务事实。';
        $lines[] = '> 投递失败重试时只会重发本消息，不重新采集或重新生成报告。';
        return $this->markdownPayload($lines);
    }

    /** @return array{success: bool, error?: string, data?: array<string, mixed>, ambiguous?: bool} */
    public static function interpretWebhookResponse(string $response, int $httpStatus = 200): array
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'success' => false,
                'error' => '企业微信 Webhook 返回 HTTP ' . $httpStatus,
                'ambiguous' => true,
            ];
        }
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['success' => false, 'error' => '企业微信 Webhook 返回格式异常', 'ambiguous' => true];
        }
        if (!is_array($decoded) || !array_key_exists('errcode', $decoded) || !is_numeric($decoded['errcode'])) {
            return ['success' => false, 'error' => '企业微信 Webhook 返回缺少结果状态', 'ambiguous' => true];
        }
        $errorCode = (int)$decoded['errcode'];
        if ($errorCode !== 0) {
            $errorMessage = trim((string)($decoded['errmsg'] ?? ''));
            $errorMessage = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage) ?? '';
            $errorMessage = mb_substr($errorMessage, 0, 160, 'UTF-8');
            return [
                'success' => false,
                'error' => '企业微信 Webhook 拒绝请求（errcode=' . $errorCode . '）'
                    . ($errorMessage !== '' ? ': ' . $errorMessage : ''),
                'ambiguous' => false,
            ];
        }
        return ['success' => true, 'data' => $decoded];
    }

    /** @return array{success: bool, error?: string, data?: array<string, mixed>, ambiguous?: bool} */
    private function postJson(string $url, array $payload): array
    {
        $url = $this->normalizeWebhook($url);
        if ($url === null) {
            return ['success' => false, 'error' => '企业微信 Webhook 无效'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ['success' => false, 'error' => '企业微信消息编码失败'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['success' => false, 'error' => '企业微信请求初始化失败'];
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($response)) {
                return [
                    'success' => false,
                    'error' => $error !== '' ? $this->safeText($error, 160) : '企业微信请求失败',
                    'ambiguous' => true,
                ];
            }
            return self::interpretWebhookResponse($response, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 12,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            return [
                'success' => false,
                'error' => '企业微信 Webhook 请求失败，请检查网络或机器人配置',
                'ambiguous' => true,
            ];
        }
        $status = 200;
        foreach ((array)($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', (string)$header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }
        return self::interpretWebhookResponse($response, $status);
    }

    private function revealWebhook(string $stored, int $robotId): string
    {
        $stored = trim($stored);
        if ($stored === '' || !str_starts_with($stored, 'suxi-secret:v1:')) {
            return $stored;
        }
        if (!class_exists(WechatRobotWebhookSecret::class)) {
            throw new \RuntimeException('Encrypted webhook support is unavailable.');
        }
        return (new WechatRobotWebhookSecret())->reveal($stored, $robotId);
    }

    private function normalizeWebhook(string $webhook): ?string
    {
        $webhook = trim($webhook);
        if ($webhook === '' || strlen($webhook) > 512 || filter_var($webhook, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($webhook);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'qyapi.weixin.qq.com'
            || (string)($parts['path'] ?? '') !== '/cgi-bin/webhook/send'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
        ) {
            return null;
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        $key = $query['key'] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9\-]{16,128}$/', trim($key)) !== 1) {
            return null;
        }
        return $webhook;
    }

    /** @return array<string, mixed> */
    private function normalizeTransportResult(mixed $result): array
    {
        if ($result === true) {
            return ['success' => true];
        }
        if ($result === false || !is_array($result)) {
            return ['success' => false, 'error' => '发送失败', 'ambiguous' => true];
        }
        return [
            'success' => ($result['success'] ?? false) === true,
            'error' => (string)($result['error'] ?? ''),
            'ambiguous' => ($result['ambiguous'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyDelivery(string $status, int $hotelId, string $reason): array
    {
        return [
            'delivery_status' => $status,
            'hotel_id' => $hotelId,
            'robot_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'failures' => [],
            'reason' => $reason,
        ];
    }

    /** @param array<int, string> $lines */
    private function markdownPayload(array $lines): array
    {
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
            ],
        ];
    }

    /** @param array<string, mixed> $issue */
    private function issueText(array $issue): string
    {
        $platform = trim((string)($issue['platform'] ?? ''));
        $label = (string)($issue['message'] ?? $issue['label'] ?? $issue['code'] ?? '未说明问题');
        $nextAction = trim((string)($issue['next_action'] ?? ''));
        return ($platform !== '' ? '[' . strtoupper($this->safeText($platform, 16)) . '] ' : '')
            . $this->safeText($label, 180)
            . ($nextAction !== '' ? '；下一步：' . $this->safeText($nextAction, 160) : '');
    }

    /** @param array<string, mixed> $report @return array<int, mixed> */
    private function reportDataGaps(array $report): array
    {
        if (is_array($report['data_gaps'] ?? null)) {
            return array_values($report['data_gaps']);
        }
        $decoded = json_decode((string)($report['data_gaps_json'] ?? ''), true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = str_replace(['<', '>'], ['＜', '＞'], $value);
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/i', $table) !== 1) {
            return false;
        }
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . $table . "'"));
        } catch (\Throwable) {
            return false;
        }
    }
}
