<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Env;

final class CloudAutomationService
{
    private CloudAutomationStateStore $stateStore;
    private CloudDataHealthService $healthService;
    private WechatRobotDeliveryService $deliveryService;
    private AiDailyReportService $reportService;

    public function __construct(
        ?CloudAutomationStateStore $stateStore = null,
        ?CloudDataHealthService $healthService = null,
        ?WechatRobotDeliveryService $deliveryService = null,
        ?AiDailyReportService $reportService = null
    ) {
        $this->stateStore = $stateStore ?? new CloudAutomationStateStore();
        $this->healthService = $healthService ?? new CloudDataHealthService();
        $this->deliveryService = $deliveryService ?? new WechatRobotDeliveryService();
        $this->reportService = $reportService ?? new AiDailyReportService();
    }

    /** @return resource|null */
    public function acquireLock()
    {
        return $this->stateStore->acquireLock();
    }

    /** @param resource|null $handle */
    public function releaseLock($handle): void
    {
        $this->stateStore->releaseLock($handle);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(string $mode, string $targetDate, array $options = []): array
    {
        date_default_timezone_set('Asia/Shanghai');
        $mode = strtolower(trim($mode));
        return match ($mode) {
            'daily' => $this->runDaily($targetDate, $options),
            'health' => $this->runHealth($targetDate, $options),
            'weekly' => $this->runWeekly($targetDate, $options),
            'retry' => $this->runRetry($options),
            default => throw new \InvalidArgumentException('Cloud automation mode must be daily, health, weekly, retry, or status.'),
        };
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        date_default_timezone_set('Asia/Shanghai');
        return [
            'status' => 'ok',
            'time' => date('Y-m-d H:i:s'),
            'automation' => $this->stateStore->statusSummary(),
            'boundary' => 'Delivery retry reads persisted message payloads only and never triggers OTA collection or report generation.',
        ];
    }

    /**
     * Deliver one already-persisted report through the same durable state and
     * lock used by scheduled automation. Repeated/concurrent requests cannot
     * create a second external send for the same report payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function deliverSavedDailyReport(
        int $hotelId,
        int $reportId,
        string $reportDate,
        array $payload,
        array $context = []
    ): array {
        $lock = $this->acquireLock();
        if (!is_resource($lock)) {
            return [
                'status' => 'in_progress',
                'delivery_status' => 'in_progress',
                'hotel_id' => $hotelId,
                'robot_count' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'failures' => [],
                'reason' => 'cloud_delivery_lock_busy',
            ];
        }
        try {
            return $this->deliverPayload(
                'daily_report',
                $hotelId,
                [
                    'report_id' => $reportId,
                    'report_date' => $reportDate,
                    'scope' => 'ota_channel',
                ],
                $payload,
                array_merge($context, [
                    'report_id' => $reportId,
                    'report_date' => $reportDate,
                    'collection_triggered' => false,
                    'report_generation_triggered' => false,
                ]),
                true,
                10
            );
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runDaily(string $targetDate, array $options): array
    {
        $this->assertDate($targetDate);
        $run = $this->stateStore->beginRun('daily', $targetDate);
        $push = !empty($options['push']);
        $useLlm = !empty($options['use_llm']);
        $limit = max(1, min(100, (int)($options['limit'] ?? 30)));
        $maxAttempts = max(1, min(50, (int)($options['max_attempts'] ?? 10)));
        $patrol = $this->triggerPatrol($targetDate, $limit);
        $hotels = $this->healthService->enabledHotels($limit);

        $hotelResults = [];
        $generated = 0;
        $blocked = 0;
        $deliveryProblems = 0;
        $healthProblemHotels = 0;
        foreach ($hotels as $hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $hotelName = trim((string)($hotel['name'] ?? '')) ?: ('酒店 #' . $hotelId);
            try {
                $health = $this->healthService->inspectHotel($hotel, $targetDate, $this->requiredPlatforms());
                if (!empty($health['issues'])) {
                    $healthProblemHotels++;
                    $healthDelivery = $this->deliverHealthAlert(
                        $health,
                        $hotelName,
                        $push,
                        $maxAttempts,
                        ['source_mode' => 'daily', 'run_id' => (string)$run['run_id']]
                    );
                    if (!in_array((string)($healthDelivery['status'] ?? ''), ['sent', 'skipped_sent', 'dry_run'], true)) {
                        $deliveryProblems++;
                    }
                } else {
                    $healthDelivery = ['status' => 'not_required'];
                }

                if (empty($health['can_generate_report'])) {
                    $blocked++;
                    $hotelResults[] = [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotelName,
                        'status' => 'blocked_by_data_health',
                        'health' => $this->publicHealth($health),
                        'health_delivery' => $healthDelivery,
                        'report_generation_triggered' => false,
                    ];
                    continue;
                }

                try {
                    $report = $this->reportService->generate(
                        [$hotelId],
                        $hotelId,
                        $targetDate,
                        0,
                        ['use_llm' => $useLlm]
                    );
                } catch (\Throwable $e) {
                    $runtimeHealth = $health;
                    $runtimeHealth['status'] = 'blocked';
                    $runtimeHealth['issues'][] = [
                        'code' => 'ai_daily_report_generation_failed',
                        'platform' => '',
                        'message' => 'AI经营日报生成失败。',
                        'blocking' => true,
                        'next_action' => '查看云端任务日志，修复后重新运行日报任务。',
                    ];
                    $failureDelivery = $this->deliverHealthAlert(
                        $runtimeHealth,
                        $hotelName,
                        $push,
                        $maxAttempts,
                        ['source_mode' => 'daily', 'run_id' => (string)$run['run_id']]
                    );
                    $blocked++;
                    if (!in_array((string)($failureDelivery['status'] ?? ''), ['sent', 'skipped_sent', 'dry_run'], true)) {
                        $deliveryProblems++;
                    }
                    $hotelResults[] = [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotelName,
                        'status' => 'report_generation_failed',
                        'error' => $this->safeError($e),
                        'health_delivery' => $failureDelivery,
                        'report_generation_triggered' => true,
                    ];
                    continue;
                }

                $reportId = (int)($report['id'] ?? 0);
                if ($reportId <= 0) {
                    throw new \RuntimeException('AI daily report generation returned no persisted report.');
                }
                $generated++;
                $payload = $this->deliveryService->buildDailyReportPayload($report, $hotelName, $health);
                $reportDelivery = $this->deliverPayload(
                    'daily_report',
                    $hotelId,
                    [
                        'report_id' => $reportId,
                        'report_date' => $targetDate,
                        'scope' => 'ota_channel',
                    ],
                    $payload,
                    [
                        'run_id' => (string)$run['run_id'],
                        'report_id' => $reportId,
                        'report_date' => $targetDate,
                        'collection_triggered' => false,
                        'report_generation_triggered' => true,
                    ],
                    $push,
                    $maxAttempts
                );
                if (!in_array((string)($reportDelivery['status'] ?? ''), ['sent', 'skipped_sent', 'dry_run'], true)) {
                    $deliveryProblems++;
                }
                $hotelResults[] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'status' => 'report_generated',
                    'health' => $this->publicHealth($health),
                    'report_id' => $reportId,
                    'model_status' => (string)($report['model_status'] ?? ''),
                    'report_delivery' => $reportDelivery,
                    'report_generation_triggered' => true,
                ];
            } catch (\Throwable $e) {
                $blocked++;
                $hotelResults[] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'status' => 'failed',
                    'error' => $this->safeError($e),
                ];
            }
        }

        $status = $this->dailyRunStatus(count($hotels), $generated, $blocked, $deliveryProblems, $healthProblemHotels, $patrol);
        $summary = [
            'push_enabled' => $push,
            'use_llm' => $useLlm,
            'patrol' => $patrol,
            'hotel_count' => count($hotels),
            'reports_generated' => $generated,
            'blocked_hotels' => $blocked,
            'health_problem_hotels' => $healthProblemHotels,
            'delivery_problem_count' => $deliveryProblems,
            'hotels' => $hotelResults,
            'collection_triggered' => false,
            'report_generation_triggered' => $generated > 0,
            'retry_boundary' => 'Failed delivery is persisted as a message payload. Retry does not recollect data or regenerate reports.',
        ];
        $finished = $this->stateStore->finishRun($run, $status, 'daily_finished', $summary);
        $this->recordRunAudit($finished, $summary);
        return $finished;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runHealth(string $targetDate, array $options): array
    {
        $this->assertDate($targetDate);
        $run = $this->stateStore->beginRun('health', $targetDate);
        $push = !empty($options['push']);
        $limit = max(1, min(100, (int)($options['limit'] ?? 30)));
        $maxAttempts = max(1, min(50, (int)($options['max_attempts'] ?? 10)));
        $patrol = $this->triggerPatrol($targetDate, $limit);
        $hotels = $this->healthService->enabledHotels($limit);
        $results = [];
        $problemHotels = 0;
        $deliveryProblems = 0;
        foreach ($hotels as $hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $hotelName = trim((string)($hotel['name'] ?? '')) ?: ('酒店 #' . $hotelId);
            try {
                $health = $this->healthService->inspectHotel($hotel, $targetDate, $this->requiredPlatforms());
                $delivery = ['status' => 'not_required'];
                if (!empty($health['issues'])) {
                    $problemHotels++;
                    $delivery = $this->deliverHealthAlert(
                        $health,
                        $hotelName,
                        $push,
                        $maxAttempts,
                        ['source_mode' => 'health', 'run_id' => (string)$run['run_id']]
                    );
                    if (!in_array((string)($delivery['status'] ?? ''), ['sent', 'skipped_sent', 'dry_run'], true)) {
                        $deliveryProblems++;
                    }
                }
                $results[] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'health' => $this->publicHealth($health),
                    'delivery' => $delivery,
                ];
            } catch (\Throwable $e) {
                $problemHotels++;
                $results[] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'health' => ['status' => 'failed'],
                    'error' => $this->safeError($e),
                ];
            }
        }

        $status = count($hotels) === 0
            ? 'blocked'
            : ($deliveryProblems > 0 || ($patrol['success'] ?? false) !== true ? 'partial' : 'succeeded');
        $summary = [
            'push_enabled' => $push,
            'patrol' => $patrol,
            'hotel_count' => count($hotels),
            'problem_hotel_count' => $problemHotels,
            'delivery_problem_count' => $deliveryProblems,
            'hotels' => $results,
            'collection_triggered' => false,
            'report_generation_triggered' => false,
        ];
        $finished = $this->stateStore->finishRun($run, $status, 'health_finished', $summary);
        $this->recordRunAudit($finished, $summary);
        return $finished;
    }

    /**
     * $targetDate is the inclusive week end date.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runWeekly(string $targetDate, array $options): array
    {
        $this->assertDate($targetDate);
        $run = $this->stateStore->beginRun('weekly', $targetDate);
        $push = !empty($options['push']);
        $limit = max(1, min(100, (int)($options['limit'] ?? 30)));
        $maxAttempts = max(1, min(50, (int)($options['max_attempts'] ?? 10)));
        $startDate = (new \DateTimeImmutable($targetDate))->modify('-6 days')->format('Y-m-d');
        $hotels = $this->healthService->enabledHotels($limit);
        $results = [];
        $deliveryProblems = 0;
        foreach ($hotels as $hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $hotelName = trim((string)($hotel['name'] ?? '')) ?: ('酒店 #' . $hotelId);
            $reports = $this->weeklyReports($hotelId, $startDate, $targetDate);
            $payload = $this->deliveryService->buildWeeklyDigestPayload($reports, $hotelName, $startDate, $targetDate);
            $delivery = $this->deliverPayload(
                'weekly_digest',
                $hotelId,
                [
                    'week_start' => $startDate,
                    'week_end' => $targetDate,
                    'report_ids' => array_values(array_filter(array_map(
                        static fn(array $report): int => (int)($report['id'] ?? 0),
                        $reports
                    ))),
                ],
                $payload,
                [
                    'run_id' => (string)$run['run_id'],
                    'week_start' => $startDate,
                    'week_end' => $targetDate,
                    'saved_report_count' => count($reports),
                    'collection_triggered' => false,
                    'report_generation_triggered' => false,
                ],
                $push,
                $maxAttempts
            );
            if (!in_array((string)($delivery['status'] ?? ''), ['sent', 'skipped_sent', 'dry_run'], true)) {
                $deliveryProblems++;
            }
            $results[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'saved_report_count' => count($reports),
                'status' => count($reports) === 7 ? 'complete' : (count($reports) > 0 ? 'partial' : 'missing'),
                'delivery' => $delivery,
            ];
        }
        $status = count($hotels) === 0 ? 'blocked' : ($deliveryProblems > 0 ? 'partial' : 'succeeded');
        $summary = [
            'push_enabled' => $push,
            'week_start' => $startDate,
            'week_end' => $targetDate,
            'hotel_count' => count($hotels),
            'delivery_problem_count' => $deliveryProblems,
            'hotels' => $results,
            'collection_triggered' => false,
            'report_generation_triggered' => false,
        ];
        $finished = $this->stateStore->finishRun($run, $status, 'weekly_finished', $summary);
        $this->recordRunAudit($finished, $summary);
        return $finished;
    }

    /**
     * This method intentionally has no access to patrol or report generation.
     * It reads only persisted message payloads from CloudAutomationStateStore.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function runRetry(array $options): array
    {
        $run = $this->stateStore->beginRun('retry', '');
        $limit = max(1, min(100, (int)($options['limit'] ?? 20)));
        $maxAttempts = max(1, min(50, (int)($options['max_attempts'] ?? 10)));
        $records = $this->stateStore->dueDeliveries($limit);
        $results = [];
        $failed = 0;
        foreach ($records as $record) {
            $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
            $hotelId = (int)($record['hotel_id'] ?? 0);
            if ($payload === [] || $hotelId <= 0) {
                $delivery = [
                    'delivery_status' => 'failed',
                    'hotel_id' => $hotelId,
                    'robot_count' => 0,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'failures' => [],
                    'reason' => 'persisted_payload_invalid',
                ];
            } else {
                $record = $this->stateStore->beginDeliveryAttempt($record);
                $delivery = $this->deliveryService->deliverToHotel(
                    $hotelId,
                    $payload,
                    array_values(array_filter(array_map('intval', (array)($record['pending_robot_ids'] ?? []))))
                );
            }
            $updated = $this->stateStore->recordDeliveryAttempt($record, $delivery, $maxAttempts);
            $status = (string)($updated['status'] ?? 'failed');
            if ($status !== 'sent') {
                $failed++;
            }
            $this->recordDeliveryAudit($updated, $delivery, true);
            $results[] = [
                'delivery_key' => (string)($updated['delivery_key'] ?? ''),
                'kind' => (string)($updated['kind'] ?? ''),
                'hotel_id' => $hotelId,
                'status' => $status,
                'attempts' => (int)($updated['attempts'] ?? 0),
                'next_retry_at' => (string)($updated['next_retry_at'] ?? ''),
            ];
        }
        $status = $failed > 0 ? 'partial' : 'succeeded';
        $summary = [
            'due_count' => count($records),
            'failed_count' => $failed,
            'deliveries' => $results,
            'collection_triggered' => false,
            'report_generation_triggered' => false,
            'boundary' => 'Only persisted message payloads were retried.',
        ];
        $finished = $this->stateStore->finishRun($run, $status, 'retry_finished', $summary);
        $this->recordRunAudit($finished, $summary);
        return $finished;
    }

    /**
     * @param array<string, mixed> $health
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function deliverHealthAlert(
        array $health,
        string $hotelName,
        bool $push,
        int $maxAttempts,
        array $context
    ): array {
        $payload = $this->deliveryService->buildHealthAlertPayload($health, $hotelName);
        $issueCodes = array_values(array_filter(array_map(
            static fn(array $issue): string => (string)($issue['code'] ?? ''),
            array_values(array_filter((array)($health['issues'] ?? []), 'is_array'))
        )));
        sort($issueCodes);
        return $this->deliverPayload(
            'data_health_alert',
            (int)($health['hotel_id'] ?? 0),
            [
                'target_date' => (string)($health['target_date'] ?? ''),
                'status' => (string)($health['status'] ?? ''),
                'issue_codes' => $issueCodes,
            ],
            $payload,
            array_merge($context, [
                'target_date' => (string)($health['target_date'] ?? ''),
                'issue_codes' => $issueCodes,
                'collection_triggered' => false,
                'report_generation_triggered' => false,
            ]),
            $push,
            $maxAttempts
        );
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function deliverPayload(
        string $kind,
        int $hotelId,
        array $identity,
        array $payload,
        array $context,
        bool $push,
        int $maxAttempts
    ): array {
        if (!$push) {
            return [
                'status' => 'dry_run',
                'kind' => $kind,
                'hotel_id' => $hotelId,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'collection_triggered' => false,
            ];
        }

        $record = $this->stateStore->queueDelivery($kind, $hotelId, $identity, $payload, $context);
        if ((string)($record['status'] ?? '') === 'sent') {
            $lastResult = is_array($record['last_result'] ?? null) ? $record['last_result'] : [];
            return [
                'status' => 'skipped_sent',
                'delivery_status' => 'sent',
                'delivery_key' => (string)($record['delivery_key'] ?? ''),
                'attempts' => (int)($record['attempts'] ?? 0),
                'robot_count' => (int)($lastResult['robot_count'] ?? 0),
                'sent_count' => (int)($lastResult['sent_count'] ?? 0),
                'failed_count' => 0,
                'failures' => [],
                'idempotent_replay' => true,
            ];
        }
        if (in_array((string)($record['status'] ?? ''), ['sending', 'delivery_outcome_unknown'], true)) {
            return [
                'status' => 'in_progress',
                'delivery_status' => 'in_progress',
                'delivery_key' => (string)($record['delivery_key'] ?? ''),
                'attempts' => (int)($record['attempts'] ?? 0),
                'robot_count' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'failures' => [],
                'reason' => 'previous_delivery_outcome_ambiguous',
            ];
        }
        $record = $this->stateStore->beginDeliveryAttempt($record);
        $delivery = $this->deliveryService->deliverToHotel(
            $hotelId,
            $payload,
            array_values(array_filter(array_map('intval', (array)($record['pending_robot_ids'] ?? []))))
        );
        $updated = $this->stateStore->recordDeliveryAttempt($record, $delivery, $maxAttempts);
        $this->recordDeliveryAudit($updated, $delivery, false);
        return [
            'status' => (string)($updated['status'] ?? 'failed'),
            'delivery_key' => (string)($updated['delivery_key'] ?? ''),
            'attempts' => (int)($updated['attempts'] ?? 0),
            'next_retry_at' => (string)($updated['next_retry_at'] ?? ''),
            'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
            'robot_count' => (int)($delivery['robot_count'] ?? 0),
            'sent_count' => (int)($delivery['sent_count'] ?? 0),
            'failed_count' => (int)($delivery['failed_count'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function triggerPatrol(string $targetDate, int $limit): array
    {
        $token = trim($this->environmentValue('CRON_TOKEN', ''));
        if ($token === '') {
            return ['success' => false, 'status' => 'cron_token_missing', 'snapshot_count' => 0];
        }
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 'curl_extension_missing', 'snapshot_count' => 0];
        }
        $baseUrl = rtrim(trim($this->environmentValue('CLOUD_AUTOMATION_BASE_URL', 'https://127.0.0.1')), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://127.0.0.1';
        }
        $url = $baseUrl . '/api/online-data/daily-workbench-patrol-cron?' . http_build_query([
            'target_date' => $targetDate,
            'limit' => max(1, min(30, $limit)),
        ]);
        $parts = parse_url($url);
        $loopback = is_array($parts) && in_array(strtolower((string)($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true);
        $https = is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https';
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 'curl_init_failed', 'snapshot_count' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Cron-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => !($loopback && $https),
            CURLOPT_SSL_VERIFYHOST => $loopback && $https ? 0 : 2,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw)) {
            return [
                'success' => false,
                'status' => 'request_failed',
                'message' => $this->safeText($error !== '' ? $error : 'request_failed', 160),
                'snapshot_count' => 0,
            ];
        }
        $decoded = json_decode($raw, true);
        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($decoded) || (int)($decoded['code'] ?? 500) !== 200) {
            return [
                'success' => false,
                'status' => 'endpoint_failed',
                'http_status' => $httpStatus,
                'message' => $this->safeText((string)($decoded['message'] ?? ('HTTP ' . $httpStatus)), 160),
                'snapshot_count' => 0,
            ];
        }
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $snapshotRows = array_values(array_filter((array)($data['snapshots'] ?? []), 'is_array'));
        if ($snapshotRows === [] && is_array($data['snapshot'] ?? null)) {
            $snapshotRows[] = $data['snapshot'];
        }
        return [
            'success' => true,
            'status' => 'completed',
            'snapshot_count' => (int)($data['snapshot_count'] ?? count($snapshotRows)),
            'run_ids' => array_values(array_filter(array_map(
                static fn(array $snapshot): string => (string)($snapshot['run_id'] ?? ''),
                $snapshotRows
            ))),
            'boundary' => 'Patrol reads existing OTA evidence only.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function weeklyReports(int $hotelId, string $startDate, string $endDate): array
    {
        try {
            $rows = Db::name('ai_daily_reports')
                ->where('hotel_id', $hotelId)
                ->whereBetween('report_date', [$startDate, $endDate])
                ->whereNull('deleted_at')
                ->order('report_date', 'desc')
                ->order('id', 'desc')
                ->limit(7)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as &$row) {
            foreach ([
                'yesterday_result_json' => 'yesterday_result',
                'data_gaps_json' => 'data_gaps',
                'recommended_actions_json' => 'recommended_actions',
            ] as $source => $target) {
                $decoded = json_decode((string)($row[$source] ?? ''), true);
                $row[$target] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($row);
        return $rows;
    }

    /** @param array<string, mixed> $health @return array<string, mixed> */
    private function publicHealth(array $health): array
    {
        return [
            'status' => (string)($health['status'] ?? ''),
            'can_generate_report' => !empty($health['can_generate_report']),
            'blocking_issue_count' => (int)($health['blocking_issue_count'] ?? 0),
            'issue_codes' => array_values(array_filter(array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                array_values(array_filter((array)($health['issues'] ?? []), 'is_array'))
            ))),
            'readback' => is_array($health['readback'] ?? null) ? $health['readback'] : [],
        ];
    }

    /** @param array<string, mixed> $patrol */
    private function dailyRunStatus(
        int $hotelCount,
        int $generated,
        int $blocked,
        int $deliveryProblems,
        int $healthProblemHotels,
        array $patrol
    ): string {
        if ($hotelCount === 0 || ($generated === 0 && $blocked > 0)) {
            return 'blocked';
        }
        if (($patrol['success'] ?? false) !== true || $blocked > 0 || $deliveryProblems > 0 || $healthProblemHotels > 0) {
            return 'partial';
        }
        return 'succeeded';
    }

    /** @return array<int, string> */
    private function requiredPlatforms(): array
    {
        $configured = trim($this->environmentValue('CLOUD_AUTOMATION_REQUIRED_PLATFORMS', 'ctrip,meituan'));
        $platforms = array_values(array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            explode(',', $configured)
        )));
        $platforms = array_values(array_intersect($platforms, ['ctrip', 'meituan']));
        return $platforms !== [] ? array_values(array_unique($platforms)) : ['ctrip', 'meituan'];
    }

    private function environmentValue(string $name, string $default = ''): string
    {
        $systemValue = getenv($name);
        if (is_string($systemValue) && trim($systemValue) !== '') {
            return $systemValue;
        }
        return (string)Env::get($name, $default);
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $summary */
    private function recordRunAudit(array $run, array $summary): void
    {
        try {
            $this->writeAudit(
                'run_' . substr((string)($run['mode'] ?? 'unknown'), 0, 30),
                '云端自动化任务完成: ' . (string)($run['run_id'] ?? ''),
                null,
                in_array((string)($run['status'] ?? ''), ['succeeded', 'partial'], true) ? null : 'cloud_automation_run_not_succeeded',
                [
                    'outcome' => (string)($run['status'] ?? '') === 'succeeded' ? 'success' : 'partial',
                    'run_id' => (string)($run['run_id'] ?? ''),
                    'mode' => (string)($run['mode'] ?? ''),
                    'target_date' => (string)($run['target_date'] ?? ''),
                    'status' => (string)($run['status'] ?? ''),
                    'hotel_count' => (int)($summary['hotel_count'] ?? 0),
                    'collection_triggered' => (bool)($summary['collection_triggered'] ?? false),
                    'report_generation_triggered' => (bool)($summary['report_generation_triggered'] ?? false),
                ]
            );
        } catch (\Throwable) {
            // The durable JSON run record remains authoritative if audit logging is unavailable.
        }
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $delivery */
    private function recordDeliveryAudit(array $record, array $delivery, bool $retry): void
    {
        try {
            $status = (string)($delivery['delivery_status'] ?? 'failed');
            $this->writeAudit(
                $retry ? 'retry_message' : 'deliver_message',
                ($retry ? '重试' : '发送') . '云端自动化企业微信消息',
                (int)($record['hotel_id'] ?? 0),
                $status === 'sent' ? null : 'cloud_message_delivery_not_succeeded',
                [
                    'outcome' => $status === 'sent' ? 'success' : ($status === 'partial' ? 'partial' : 'failed'),
                    'delivery_key' => (string)($record['delivery_key'] ?? ''),
                    'kind' => (string)($record['kind'] ?? ''),
                    'delivery_status' => $status,
                    'attempts' => (int)($record['attempts'] ?? 0),
                    'robot_count' => (int)($delivery['robot_count'] ?? 0),
                    'sent_count' => (int)($delivery['sent_count'] ?? 0),
                    'failed_count' => (int)($delivery['failed_count'] ?? 0),
                    'collection_triggered' => false,
                    'report_generation_triggered' => false,
                ]
            );
        } catch (\Throwable) {
            // Delivery state is already stored in the durable spool.
        }
    }

    /** @param array<string, mixed> $extraData */
    private function writeAudit(
        string $action,
        string $description,
        ?int $hotelId,
        ?string $errorInfo,
        array $extraData
    ): void {
        $fields = [];
        try {
            $fields = array_keys(Db::getFields('operation_logs'));
        } catch (\Throwable) {
            // The insert below will be skipped when the audit table is unavailable.
        }
        if ($fields === []) {
            return;
        }
        $tenantId = 0;
        if ($hotelId !== null && $hotelId > 0 && in_array('tenant_id', $fields, true)) {
            try {
                $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
            } catch (\Throwable) {
                $tenantId = 0;
            }
        }
        $payload = [
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'user_id' => null,
            'hotel_id' => $hotelId !== null && $hotelId > 0 ? $hotelId : null,
            'module' => 'cloud_automation',
            'action' => substr($action, 0, 50),
            'description' => mb_substr($description, 0, 500, 'UTF-8'),
            'ip' => '',
            'user_agent' => 'systemd/cloud-automation',
            'create_time' => date('Y-m-d H:i:s'),
            'error_info' => $errorInfo !== null ? mb_substr($errorInfo, 0, 1000, 'UTF-8') : null,
            'extra_data' => json_encode($extraData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        Db::name('operation_logs')->insert(array_intersect_key($payload, array_flip($fields)));
    }

    private function assertDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('target-date must use YYYY-MM-DD.');
        }
    }

    private function safeError(\Throwable $error): string
    {
        return $this->safeText($error->getMessage() !== '' ? $error->getMessage() : $error::class, 240);
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/(key|token|secret|cookie|password)\s*[=:]\s*[^\s,;]+/i', '$1=<redacted>', $value) ?? '';
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }
}
