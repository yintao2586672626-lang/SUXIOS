<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ManualNotificationService
{
    public const DYNAMIC_REPORT_TYPE = 'operating_target_report';
    public const OPERATING_BRIEF_TYPE = 'single_hotel_operating_brief';

    private const TIMEZONE = 'Asia/Shanghai';
    private const TYPES = [
        self::OPERATING_BRIEF_TYPE => '单店经营事实简报',
        self::DYNAMIC_REPORT_TYPE => '每日经营目标报告',
        'today_revenue_management' => '今日收益管理',
        'future_room_status' => '远期房态',
        'daily_review' => '今日复盘',
        'blank_custom' => '空白自定义',
    ];
    private const SEND_METHODS = [
        'wecom_test' => '企业微信测试机器人（仅测试群）',
        'manual_preview' => '仅保存与页面预览',
    ];
    private const TRIGGER_TYPES = [
        'manual_test' => '手动测试',
        'daily_fixed_time' => '每日固定时间',
        'hourly_on_the_hour' => '每小时整点',
    ];

    /** @var callable|null */
    private $testDispatcher;

    public function __construct(
        ?callable $testDispatcher = null,
        private readonly ?OperatingTargetNotificationPayloadService $operatingTargetPayloads = null,
        private readonly ?ManualNotificationDispatchLedgerService $ledger = null,
        private readonly ?ManualNotificationTestTargetService $testTargets = null,
        private readonly ?SingleHotelOperatingBriefPayloadService $operatingBriefPayloads = null
    ) {
        $this->testDispatcher = $testDispatcher;
    }

    /** @return array<string, mixed> */
    public function metadata(string $businessDate = '', int $hotelId = 0): array
    {
        $date = $this->normalizeDate(
            $businessDate === '' ? $this->now()->format('Y-m-d') : $businessDate
        );
        $templates = [];
        foreach (self::TYPES as $key => $label) {
            $templates[] = [
                'key' => $key,
                'label' => $label,
                'title' => $this->defaultTitle($key, $date),
                'body' => $this->defaultBody($key, $date),
                'dynamic' => in_array(
                    $key,
                    [self::OPERATING_BRIEF_TYPE, self::DYNAMIC_REPORT_TYPE],
                    true
                ),
            ];
        }

        $testTarget = $hotelId > 0 ? $this->testTargetResolver()->resolve($hotelId) : null;
        try {
            $scheduler = $this->dispatchLedger()->latestScheduleRun(
                $hotelId,
                (int)($testTarget['robot_id'] ?? 0)
            );
        } catch (\Throwable) {
            $scheduler = [
                'status' => 'not_deployed',
                'message' => '尚未取得云端调度运行记录。',
            ];
        }

        return [
            'types' => $templates,
            'send_methods' => array_map(
                static fn(string $label, string $key): array => ['key' => $key, 'label' => $label],
                self::SEND_METHODS,
                array_keys(self::SEND_METHODS)
            ),
            'trigger_types' => array_map(
                static fn(string $label, string $key): array => ['key' => $key, 'label' => $label],
                self::TRIGGER_TYPES,
                array_keys(self::TRIGGER_TYPES)
            ),
            'variables' => ['{酒店名称}', '{经营日期}', '{统计时间}', '{数据状态}'],
            'test_target' => [
                'hotel_id' => $hotelId,
                'robot_id' => (int)($testTarget['robot_id'] ?? 0),
                'robot_name' => (string)($testTarget['robot_name'] ?? ''),
                'binding_status' => (string)($testTarget['binding_status'] ?? 'test_binding_missing'),
                'formal_group_delivery_allowed' => false,
            ],
            'scheduler_status' => (string)($scheduler['status'] ?? 'not_deployed'),
            'scheduler_note' => (string)($scheduler['message'] ?? '调度状态以最近一次云端运行记录为准。'),
            'latest_schedule_run' => $scheduler,
        ];
    }

    /**
     * Page preview only. The optional tenant id keeps backward compatibility
     * for static previews; dynamic reports require a real tenant scope.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(
        int $hotelId,
        string $hotelName,
        array $input,
        int $tenantId = 0
    ): array {
        $normalized = $this->normalizeInput($input);
        return $this->renderPreview($tenantId, $hotelId, $hotelName, $normalized);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function save(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $hotelName,
        array $input
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('manual_notification_scope_invalid');
        }
        $normalized = $this->normalizeInput($input);
        $now = $this->now()->format('Y-m-d H:i:s');
        $scheduleStatus = $normalized['enabled'] && $normalized['trigger_type'] !== 'manual_test'
            ? 'awaiting_test'
            : 'saved_only';
        $recordData = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'notification_type' => $normalized['notification_type'],
            'template_type' => $normalized['template_type'],
            'business_date' => $normalized['business_date'],
            'title' => $normalized['title'],
            'body' => $normalized['body'],
            'send_method' => $normalized['send_method'],
            'trigger_type' => $normalized['trigger_type'],
            'planned_send_at' => $normalized['planned_send_at'],
            'enabled' => $normalized['enabled'] ? 1 : 0,
            'schedule_status' => $scheduleStatus,
            'last_test_status' => 'never_tested',
            'created_by' => $userId,
            'update_time' => $now,
        ];
        $requestedId = max(0, (int)($input['id'] ?? 0));
        if ($requestedId > 0) {
            $existing = Db::name('manual_notifications')
                ->where('id', $requestedId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->field('id')
                ->find();
            if (!is_array($existing)) {
                throw new \RuntimeException('manual_notification_not_found');
            }
            unset($recordData['tenant_id'], $recordData['hotel_id'], $recordData['created_by']);
            $recordData['last_test_message'] = null;
            $recordData['last_tested_at'] = null;
            $recordData['last_tested_by'] = null;
            $recordData['test_robot_id'] = null;
            $recordData['test_robot_name'] = null;
            Db::name('manual_notifications')->where('id', $requestedId)->update($recordData);
            $id = $requestedId;
            $operation = 'updated';
        } else {
            $recordData['create_time'] = $now;
            $id = (int)Db::name('manual_notifications')->insertGetId($recordData);
            if ($id <= 0) {
                throw new \RuntimeException('manual_notification_save_failed');
            }
            $operation = 'created';
        }

        $record = $this->read($tenantId, $hotelId, $id);
        return [
            'record' => $record,
            'preview' => $this->renderPreview($tenantId, $hotelId, $hotelName, $record),
            'readback_verified' => (int)$record['id'] === $id,
            'operation' => $operation,
        ];
    }

    /** @return array<string, mixed> */
    public function read(int $tenantId, int $hotelId, int $id): array
    {
        $row = Db::name('manual_notifications')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('manual_notification_not_found');
        }
        return $this->present($row);
    }

    /** @return array<string, mixed> */
    public function history(int $tenantId, int $hotelId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $rows = Db::name('manual_notifications')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
        return [
            'list' => array_map(fn(array $row): array => $this->present($row), $rows),
            'total' => (int)Db::name('manual_notifications')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function dispatchHistory(int $tenantId, int $hotelId, int $limit = 50): array
    {
        return $this->dispatchLedger()->history($tenantId, $hotelId, $limit);
    }

    /**
     * One explicit real send to the test robot. The idempotency key is required
     * and is stored only as a short SHA-256-derived dispatch window.
     *
     * @return array<string, mixed>
     */
    public function testPush(
        int $tenantId,
        int $hotelId,
        int $notificationId,
        int $userId,
        bool $confirmed,
        int $targetRobotId,
        string $targetRobotName,
        string $hotelName,
        string $idempotencyKey = ''
    ): array {
        $record = $this->read($tenantId, $hotelId, $notificationId);
        if ((string)$record['send_method'] !== 'wecom_test') {
            throw new \InvalidArgumentException('manual_notification_test_method_forbidden');
        }
        $robot = $this->assertTestRequest(
            $hotelId,
            $confirmed,
            $targetRobotId,
            $targetRobotName,
            $idempotencyKey
        );

        $businessDate = (string)$record['business_date'];
        $candidate = $this->deliveryCandidate(
            $tenantId,
            $hotelId,
            $hotelName,
            $record,
            $businessDate,
            'immediate_test'
        );
        $window = 'i:' . substr(hash('sha256', trim($idempotencyKey)), 0, 30);
        $candidateStatus = (string)($candidate['status'] ?? 'blocked');
        $reportBlocked = $candidateStatus !== 'ready' || !is_array($candidate['payload'] ?? null);
        $dispatcherMissing = $this->testDispatcher === null;
        $blocked = $reportBlocked || $dispatcherMissing;
        $claim = $this->dispatchLedger()->claim(
            $notificationId,
            $tenantId,
            $hotelId,
            $window,
            'test',
            (string)$record['trigger_type'],
            'immediate_test',
            (int)$robot['robot_id'],
            (string)$robot['robot_name'],
            $businessDate,
            $candidate,
            $this->now(),
            $blocked ? 'blocked' : 'claimed',
            $blocked
                ? ($dispatcherMissing ? 'test_dispatcher_missing' : (string)($candidate['reason_code'] ?? 'report_gate_blocked'))
                : 'dispatch_claimed',
            $blocked
                ? ($dispatcherMissing ? '测试发送器未配置。' : $this->candidateBlockerMessage($candidate))
                : null
        );
        $dispatch = $claim['dispatch'];
        if ($claim['claimed'] === false) {
            return [
                'delivery_status' => (string)$dispatch['status'],
                'message' => '相同幂等请求已处理，未重复发送。',
                'idempotent_replay' => true,
                'dispatch' => $dispatch,
                'formal_group_delivery_allowed' => false,
            ];
        }
        if ($blocked) {
            if ($dispatcherMissing) {
                $result = $this->persistTestResult(
                    $tenantId,
                    $hotelId,
                    $notificationId,
                    $userId,
                    $robot,
                    'test_dispatcher_missing',
                    '测试发送器未配置；未触发Webhook或正式群。'
                );
                $result['dispatch'] = $dispatch;
                return $result;
            }
            $result = $this->persistTestResult(
                $tenantId,
                $hotelId,
                $notificationId,
                $userId,
                $robot,
                'blocked',
                '经营消息未通过酒店、日期、来源、质量或所选模块门禁；测试群未发送。'
            );
            $result['dispatch'] = $dispatch;
            $result['report_gate'] = $candidate['formal_send_gate'] ?? null;
            return $result;
        }
        $attempt = $this->dispatchLedger()->beginAttempt((int)$dispatch['id'], $this->now());
        if (($attempt['allowed'] ?? false) !== true) {
            return [
                'delivery_status' => 'blocked',
                'message' => '发送台账状态不允许再次调用外部发送器。',
                'dispatch' => $dispatch,
                'formal_group_delivery_allowed' => false,
            ];
        }
        $delivery = [];
        $exception = null;
        try {
            $delivery = call_user_func(
                $this->testDispatcher,
                $hotelId,
                (int)$robot['robot_id'],
                $candidate['payload'],
                [
                    'notification_id' => $notificationId,
                    'dispatch_id' => (int)$dispatch['id'],
                    'business_date' => $businessDate,
                    'request_kind' => 'immediate_test',
                ]
            );
            $delivery = is_array($delivery) ? $delivery : [];
        } catch (\Throwable $error) {
            $exception = $error;
        }
        $finished = $this->dispatchLedger()->finishAttempt(
            (int)$dispatch['id'],
            (int)$attempt['attempt_id'],
            $delivery,
            $this->now(),
            $exception
        );
        $sent = (string)$finished['status'] === 'sent';
        $message = $sent
            ? '测试消息已送达“' . (string)$robot['robot_name'] . '”，并保存企业微信业务成功记录。'
            : ((string)$finished['status'] === 'outcome_unknown'
                ? '企业微信发送结果不明确，已阻止自动重试，正式群未触发。'
                : '测试消息未送达，已保存失败记录，正式群未触发。');
        $result = $this->persistTestResult(
            $tenantId,
            $hotelId,
            $notificationId,
            $userId,
            $robot,
            $sent ? 'sent' : (string)$finished['status'],
            $message
        );
        $result['delivery_status'] = (string)$finished['status'];
        $result['dispatch'] = $finished;
        $result['delivery'] = [
            'delivery_status' => (string)$finished['status'],
            'target_hotel_id' => $hotelId,
            'target_robot_id' => (int)$robot['robot_id'],
            'target_robot_name' => (string)$robot['robot_name'],
            'formal_group_delivery_allowed' => false,
        ];
        return $result;
    }

    /** @return array<string, mixed> */
    public function retryDispatch(
        int $tenantId,
        int $hotelId,
        int $dispatchId,
        int $userId,
        bool $confirmed
    ): array {
        if (!$confirmed) {
            throw new \InvalidArgumentException('manual_notification_retry_confirmation_required');
        }
        $retry = $this->dispatchLedger()->dispatchForRetry($tenantId, $hotelId, $dispatchId);
        if ((string)($retry['dispatch']['delivery_mode'] ?? '') !== 'test') {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $robot = $this->testTargetResolver()->resolve(
            $hotelId,
            (int)$retry['robot_id'],
            (string)$retry['robot_name']
        );
        if ($robot === null) {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        if ($this->testDispatcher === null) {
            throw new \RuntimeException('manual_notification_test_dispatcher_missing');
        }

        $attempt = $this->dispatchLedger()->beginAttempt($dispatchId, $this->now());
        if (($attempt['allowed'] ?? false) !== true) {
            throw new \InvalidArgumentException((string)$attempt['reason_code']);
        }
        $delivery = [];
        $exception = null;
        try {
            $delivery = call_user_func(
                $this->testDispatcher,
                $hotelId,
                (int)$robot['robot_id'],
                $retry['payload'],
                [
                    'notification_id' => (int)$retry['notification_id'],
                    'dispatch_id' => $dispatchId,
                    'request_kind' => 'explicit_retry',
                ]
            );
            $delivery = is_array($delivery) ? $delivery : [];
        } catch (\Throwable $error) {
            $exception = $error;
        }
        $finished = $this->dispatchLedger()->finishAttempt(
            $dispatchId,
            (int)$attempt['attempt_id'],
            $delivery,
            $this->now(),
            $exception
        );
        $sent = (string)$finished['status'] === 'sent';
        $this->persistTestResult(
            $tenantId,
            $hotelId,
            (int)$retry['notification_id'],
            $userId,
            $robot,
            $sent ? 'sent' : (string)$finished['status'],
            $sent
                ? '显式重试已送达“' . (string)$robot['robot_name'] . '”。'
                : '显式重试未确认送达，已保存真实状态。'
        );
        return [
            'delivery_status' => (string)$finished['status'],
            'dispatch' => $finished,
            'formal_group_delivery_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeInput(array $input): array
    {
        $type = trim((string)($input['template_type'] ?? $input['notification_type'] ?? ''));
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('manual_notification_type_invalid');
        }
        $businessDate = $this->normalizeDate((string)($input['business_date'] ?? ''));
        $title = $this->safeText((string)($input['title'] ?? ''), 120);
        $body = $this->safeMultiline((string)($input['body'] ?? ''), 5000);
        if ($title === '' || $body === '') {
            throw new \InvalidArgumentException('manual_notification_content_required');
        }
        if (preg_match('/[A-Za-z]/', $body) === 1) {
            throw new \InvalidArgumentException('manual_notification_body_chinese_only');
        }
        $sendMethod = trim((string)($input['send_method'] ?? 'wecom_test'));
        if (!isset(self::SEND_METHODS[$sendMethod])) {
            throw new \InvalidArgumentException('manual_notification_send_method_invalid');
        }
        $triggerType = trim((string)($input['trigger_type'] ?? 'manual_test'));
        if (!isset(self::TRIGGER_TYPES[$triggerType])) {
            throw new \InvalidArgumentException('manual_notification_trigger_invalid');
        }
        $plannedSendAt = $this->normalizeDateTime($input['planned_send_at'] ?? null);
        if ($triggerType === 'daily_fixed_time' && $plannedSendAt === null) {
            throw new \InvalidArgumentException('manual_notification_schedule_required');
        }
        return [
            'notification_type' => $type,
            'template_type' => $type,
            'business_date' => $businessDate,
            'title' => $title,
            'body' => $body,
            'send_method' => $sendMethod,
            'trigger_type' => $triggerType,
            'planned_send_at' => $plannedSendAt,
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function renderPreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        array $data
    ): array {
        $type = (string)($data['template_type'] ?? $data['notification_type'] ?? '');
        if ($type === self::OPERATING_BRIEF_TYPE) {
            if ($tenantId <= 0) {
                return $this->blockedDynamicPreview(
                    $data,
                    $type,
                    'tenant_scope_missing',
                    '缺少租户范围，单店经营事实简报不可预览。'
                );
            }
            $page = $this->briefPayloads()->pagePreview(
                $tenantId,
                $hotelId,
                $hotelName,
                (string)$data['business_date']
            );
            return [
                'title' => (string)$data['title'],
                'body' => (string)$data['body'],
                'notification_type' => $type,
                'template_type' => $type,
                'notification_type_label' => self::TYPES[$type],
                'business_date' => (string)$data['business_date'],
                'send_method' => (string)$data['send_method'],
                'send_method_label' => self::SEND_METHODS[(string)$data['send_method']],
                'trigger_type' => (string)$data['trigger_type'],
                'trigger_type_label' => self::TRIGGER_TYPES[(string)$data['trigger_type']],
                'planned_send_at' => $data['planned_send_at'] ?? null,
                'enabled_requested' => (bool)$data['enabled'],
                'schedule_status' => (bool)$data['enabled'] && (string)$data['trigger_type'] !== 'manual_test'
                    ? 'awaiting_test'
                    : 'saved_only',
                'schedule_status_label' => (bool)$data['enabled'] && (string)$data['trigger_type'] !== 'manual_test'
                    ? '等待一次真实测试成功后启用'
                    : '仅保存/仅测试',
                'scheduler_connected' => false,
                'dynamic_report' => true,
                'message_mode' => 'base_operating_facts',
                'delivery_status' => 'preview_only',
                'report_gate' => $page['base_fact_gate'] ?? null,
                'payload' => $page['payload'] ?? null,
                'operating_target_record_id' => 0,
                'snapshot_revision_no' => 0,
                'preview_fingerprint' => (string)($page['preview_fingerprint'] ?? ''),
            ];
        }
        if ($type === self::DYNAMIC_REPORT_TYPE) {
            if ($tenantId <= 0) {
                return $this->blockedDynamicPreview(
                    $data,
                    $type,
                    'tenant_scope_missing',
                    '缺少租户范围，动态经营目标报告不可预览。'
                );
            }
            $page = $this->targetPayloads()->pagePreview(
                $tenantId,
                $hotelId,
                $hotelName,
                (string)$data['business_date']
            );
            return [
                'title' => (string)$data['title'],
                'body' => (string)$data['body'],
                'notification_type' => $type,
                'template_type' => $type,
                'notification_type_label' => self::TYPES[$type],
                'business_date' => (string)$data['business_date'],
                'send_method' => (string)$data['send_method'],
                'send_method_label' => self::SEND_METHODS[(string)$data['send_method']],
                'trigger_type' => (string)$data['trigger_type'],
                'trigger_type_label' => self::TRIGGER_TYPES[(string)$data['trigger_type']],
                'planned_send_at' => $data['planned_send_at'] ?? null,
                'enabled_requested' => (bool)$data['enabled'],
                'schedule_status' => (bool)$data['enabled'] && (string)$data['trigger_type'] !== 'manual_test'
                    ? 'awaiting_test'
                    : 'saved_only',
                'schedule_status_label' => (bool)$data['enabled'] && (string)$data['trigger_type'] !== 'manual_test'
                    ? '等待一次真实测试成功后启用'
                    : '仅保存/仅测试',
                'scheduler_connected' => false,
                'dynamic_report' => true,
                'delivery_status' => 'preview_only',
                'report_gate' => $page['formal_send_gate'] ?? null,
                'payload' => $page['payload'] ?? null,
                'operating_target_record_id' => (int)($page['operating_target_record_id'] ?? 0),
                'snapshot_revision_no' => (int)($page['snapshot_revision_no'] ?? 0),
                'preview_fingerprint' => (string)($page['preview_fingerprint'] ?? ''),
            ];
        }
        return $this->staticPreview($hotelId, $hotelName, $data);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function deliveryCandidate(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        array $record,
        string $businessDate,
        string $deliveryMode
    ): array {
        if ((string)$record['template_type'] === self::OPERATING_BRIEF_TYPE) {
            return $this->briefPayloads()->build(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $deliveryMode
            );
        }
        if ((string)$record['template_type'] === self::DYNAMIC_REPORT_TYPE) {
            $today = $this->now()->format('Y-m-d');
            if ($deliveryMode === 'immediate_test' && $businessDate !== $today) {
                return [
                    'status' => 'blocked',
                    'reason_code' => 'operating_target_business_date_not_today',
                    'business_date' => $businessDate,
                    'preview_fingerprint' => hash('sha256', $hotelId . '|' . $businessDate . '|not_today'),
                    'formal_send_gate' => [
                        'allowed' => false,
                        'status' => 'formal_send_blocked',
                        'blockers' => [[
                            'code' => 'operating_target_business_date_not_today',
                            'message' => '立即测试只允许当天经营目标报告。',
                        ]],
                    ],
                    'payload' => null,
                ];
            }
            return $this->targetPayloads()->build(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $deliveryMode
            );
        }

        $preview = $this->staticPreview($hotelId, $hotelName, $record, true);
        return [
            'status' => 'ready',
            'reason_code' => 'static_notification_ready',
            'business_date' => $businessDate,
            'preview_fingerprint' => hash('sha256', $this->json($preview['payload'])),
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => null,
            'payload' => $preview['payload'],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function staticPreview(
        int $hotelId,
        string $hotelName,
        array $data,
        bool $testMode = false
    ): array {
        $type = (string)($data['template_type'] ?? $data['notification_type'] ?? '');
        $plannedSendAt = trim((string)($data['planned_send_at'] ?? ''));
        $enabled = (bool)($data['enabled'] ?? false);
        $triggerType = (string)($data['trigger_type'] ?? 'manual_test');
        $status = $enabled && $triggerType !== 'manual_test' ? 'awaiting_test' : 'saved_only';
        $statusLabel = $status === 'awaiting_test'
            ? '等待一次真实测试成功后启用'
            : '仅保存/仅测试';
        $modeLabel = $testMode ? '明确点击的测试推送' : '页面实时预览（未发送）';
        $variables = [
            '{酒店名称}' => $hotelName !== '' ? $hotelName : '未取得',
            '{经营日期}' => (string)($data['business_date'] ?? '') ?: '未取得',
            '{统计时间}' => $plannedSendAt !== '' ? $plannedSendAt : '待配置',
            '{数据状态}' => $statusLabel,
        ];
        $renderedTitle = strtr((string)($data['title'] ?? ''), $variables);
        $renderedBody = strtr((string)($data['body'] ?? ''), $variables);
        $content = implode("\n", [
            '# 宿析OS｜' . $this->safeText($renderedTitle, 120),
            '> 当前模式：' . $modeLabel,
            '> 酒店：' . $this->safeText($hotelName, 80) . '（ID ' . $hotelId . '）',
            '> 通知类型：' . (self::TYPES[$type] ?? '未取得'),
            '> 业务日期：' . ((string)($data['business_date'] ?? '') ?: '未取得'),
            '> 计划发送：' . ($plannedSendAt !== '' ? $plannedSendAt : '待配置'),
            '> 发送触发：' . (self::TRIGGER_TYPES[$triggerType] ?? '待配置'),
            '> 状态：' . $statusLabel,
            '',
            $renderedBody,
            '',
            '> 未取得的数据未使用0或旧日数据补齐；当前预览不会触发正式群。',
        ]);
        return [
            'title' => $renderedTitle,
            'body' => $renderedBody,
            'notification_type' => $type,
            'template_type' => $type,
            'notification_type_label' => self::TYPES[$type] ?? '未取得',
            'business_date' => (string)($data['business_date'] ?? ''),
            'send_method' => (string)($data['send_method'] ?? 'manual_preview'),
            'send_method_label' => self::SEND_METHODS[(string)($data['send_method'] ?? '')] ?? '待配置',
            'trigger_type' => $triggerType,
            'trigger_type_label' => self::TRIGGER_TYPES[$triggerType] ?? '待配置',
            'planned_send_at' => $plannedSendAt !== '' ? $plannedSendAt : null,
            'enabled_requested' => $enabled,
            'schedule_status' => $status,
            'schedule_status_label' => $statusLabel,
            'scheduler_connected' => false,
            'dynamic_report' => false,
            'delivery_status' => 'preview_only',
            'payload' => [
                'msgtype' => 'markdown',
                'markdown' => ['content' => $content],
            ],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function blockedDynamicPreview(
        array $data,
        string $type,
        string $code,
        string $message
    ): array
    {
        return [
            'title' => (string)$data['title'],
            'body' => (string)$data['body'],
            'notification_type' => $type,
            'template_type' => $type,
            'notification_type_label' => self::TYPES[$type] ?? '动态经营消息',
            'business_date' => (string)$data['business_date'],
            'send_method' => (string)$data['send_method'],
            'trigger_type' => (string)$data['trigger_type'],
            'planned_send_at' => $data['planned_send_at'] ?? null,
            'schedule_status' => 'blocked',
            'schedule_status_label' => '数据门禁阻断',
            'delivery_status' => 'preview_unavailable',
            'dynamic_report' => true,
            'report_gate' => [
                'allowed' => false,
                'status' => 'formal_send_blocked',
                'blockers' => [['code' => $code, 'message' => $message]],
            ],
            'payload' => null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $body = (string)($row['body'] ?? '');
        $scheduleStatus = (string)($row['schedule_status'] ?? 'saved_only');
        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'notification_type' => (string)($row['notification_type'] ?? ''),
            'notification_type_label' => self::TYPES[(string)($row['notification_type'] ?? '')] ?? '未取得',
            'template_type' => (string)($row['template_type'] ?? $row['notification_type'] ?? ''),
            'template_type_label' => self::TYPES[(string)($row['template_type'] ?? $row['notification_type'] ?? '')] ?? '未取得',
            'business_date' => (string)($row['business_date'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'body' => $body,
            'body_summary' => mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 120),
            'send_method' => (string)($row['send_method'] ?? ''),
            'send_method_label' => self::SEND_METHODS[(string)($row['send_method'] ?? '')] ?? '待配置',
            'trigger_type' => (string)($row['trigger_type'] ?? 'manual_test'),
            'trigger_type_label' => self::TRIGGER_TYPES[(string)($row['trigger_type'] ?? '')] ?? '待配置',
            'planned_send_at' => $row['planned_send_at'] ?? null,
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'schedule_status' => $scheduleStatus,
            'schedule_status_label' => $this->scheduleStatusLabel($scheduleStatus),
            'last_test_status' => (string)($row['last_test_status'] ?? 'never_tested'),
            'last_test_message' => $row['last_test_message'] ?? null,
            'last_tested_at' => $row['last_tested_at'] ?? null,
            'test_robot_id' => $this->positiveOrNull($row['test_robot_id'] ?? null),
            'test_robot_name' => $row['test_robot_name'] ?? null,
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => $row['create_time'] ?? null,
            'updated_at' => $row['update_time'] ?? null,
        ];
    }

    /** @param array<string, mixed>|null $robot @return array<string, mixed> */
    private function persistTestResult(
        int $tenantId,
        int $hotelId,
        int $notificationId,
        int $userId,
        ?array $robot,
        string $status,
        string $message
    ): array {
        $record = $this->read($tenantId, $hotelId, $notificationId);
        $now = $this->now()->format('Y-m-d H:i:s');
        $scheduleStatus = (string)$record['schedule_status'];
        if ($status === 'sent') {
            $scheduleStatus = (bool)$record['enabled']
                && (string)$record['trigger_type'] !== 'manual_test'
                    ? 'schedule_enabled'
                    : 'test_verified';
        }
        Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->update([
                'schedule_status' => $scheduleStatus,
                'last_test_status' => $status,
                'last_test_message' => $this->safeText($message, 255),
                'last_tested_at' => $now,
                'last_tested_by' => $userId,
                'test_robot_id' => $robot === null
                    ? null
                    : (int)($robot['robot_id'] ?? $robot['id'] ?? 0),
                'test_robot_name' => $robot === null
                    ? null
                    : (string)($robot['robot_name'] ?? $robot['name'] ?? ''),
                'update_time' => $now,
            ]);
        return [
            'delivery_status' => $status,
            'message' => $message,
            'schedule_status' => $scheduleStatus,
            'schedule_status_label' => $this->scheduleStatusLabel($scheduleStatus),
            'target_hotel_id' => $hotelId,
            'target_robot_id' => (int)($robot['robot_id'] ?? $robot['id'] ?? 0),
            'target_robot_name' => (string)($robot['robot_name'] ?? $robot['name'] ?? ''),
            'formal_group_delivery_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function assertTestRequest(
        int $hotelId,
        bool $confirmed,
        int $targetRobotId,
        string $targetRobotName,
        string $idempotencyKey
    ): array {
        if (!$confirmed) {
            throw new \InvalidArgumentException('manual_notification_test_confirmation_required');
        }
        $robot = $this->testTargetResolver()->resolve(
            $hotelId,
            $targetRobotId,
            $targetRobotName
        );
        if ($robot === null) {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $idempotencyKey = trim($idempotencyKey);
        if (mb_strlen($idempotencyKey, 'UTF-8') < 8
            || mb_strlen($idempotencyKey, 'UTF-8') > 128
            || preg_match('/^[A-Za-z0-9:_-]+$/', $idempotencyKey) !== 1
        ) {
            throw new \InvalidArgumentException('manual_notification_idempotency_key_invalid');
        }
        return $robot;
    }

    private function testTargetResolver(): ManualNotificationTestTargetService
    {
        return $this->testTargets ?? new ManualNotificationTestTargetService();
    }

    /** @param array<string, mixed> $candidate */
    private function candidateBlockerMessage(array $candidate): string
    {
        $messages = [];
        foreach ((array)($candidate['formal_send_gate']['blockers'] ?? []) as $blocker) {
            if (is_array($blocker) && trim((string)($blocker['message'] ?? '')) !== '') {
                $messages[] = trim((string)$blocker['message']);
            }
        }
        return $messages === []
            ? '经营消息的数据门禁未通过。'
            : implode('；', array_slice($messages, 0, 3));
    }

    private function scheduleStatusLabel(string $status): string
    {
        return match ($status) {
            'awaiting_test' => '等待一次真实测试成功后启用',
            'schedule_enabled' => '定时发送已启用',
            'test_verified' => '测试发送已验证',
            'blocked' => '数据或发送门禁阻断',
            default => '仅保存/仅测试',
        };
    }

    private function defaultTitle(string $type, string $date): string
    {
        return match ($type) {
            self::OPERATING_BRIEF_TYPE => $date . ' 单店经营事实简报',
            self::DYNAMIC_REPORT_TYPE => $date . ' 每日经营目标报告',
            'today_revenue_management' => $date . ' 今日收益管理',
            'future_room_status' => $date . ' 远期房态',
            'daily_review' => $date . ' 今日复盘',
            default => $date . ' 自定义通知',
        };
    }

    private function defaultBody(string $type, string $date): string
    {
        return match ($type) {
            self::OPERATING_BRIEF_TYPE => implode("\n", [
                '【单店经营事实简报】',
                '酒店：{酒店名称}',
                '经营日期：{经营日期}',
                '基础内容只使用同酒店、同日期、已核验并回读的订单来了住宿经营事实。',
                '经营目标未启用时标记不适用；携程和美团为独立可选渠道块，缺失不阻断基础事实。',
            ]),
            self::DYNAMIC_REPORT_TYPE => implode("\n", [
                '【每日经营目标报告】',
                '酒店：{酒店名称}',
                '经营日期：{经营日期}',
                '正文由同酒店、同日期的已保存经营目标和已核验经营事实动态生成。',
                '缺失、身份不匹配、采集失败或未验证时阻断发送，不以零或旧数据补齐。',
            ]),
            'today_revenue_management' => implode("\n", [
                '【今日收益管理】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '统计时间：{统计时间}',
                '一、今日经营目标：未取得',
                '二、当前经营结果：未取得',
                '三、收益动作：待配置',
                '数据说明：未取得项未使用零值或旧日数据补齐。',
            ]),
            'future_room_status' => implode("\n", [
                '【远期房态】',
                '酒店：{酒店名称}',
                '快照日期：{经营日期}',
                '一、未来入住日期：未取得',
                '二、房态来源与范围：未取得',
                '三、可售与已售房量：未取得',
                '四、运营动作：待配置',
            ]),
            'daily_review' => implode("\n", [
                '【今日复盘】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '一、目标完成情况：未取得',
                '二、执行结果：未取得',
                '三、问题与原因：未取得',
                '四、明日动作：待配置',
            ]),
            default => implode("\n", [
                '【自定义通知】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '通知内容：待配置',
                '数据说明：无法确认的数据请写“未取得”。',
            ]),
        };
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('manual_notification_date_invalid');
        }
        return $value;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$parsed) {
            throw new \InvalidArgumentException('manual_notification_schedule_invalid');
        }
        return $parsed->format('Y-m-d H:i:s');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    private function targetPayloads(): OperatingTargetNotificationPayloadService
    {
        return $this->operatingTargetPayloads ?? new OperatingTargetNotificationPayloadService();
    }

    private function briefPayloads(): SingleHotelOperatingBriefPayloadService
    {
        return $this->operatingBriefPayloads ?? new SingleHotelOperatingBriefPayloadService();
    }

    private function dispatchLedger(): ManualNotificationDispatchLedgerService
    {
        return $this->ledger ?? new ManualNotificationDispatchLedgerService();
    }

    private function positiveOrNull(mixed $value): ?int
    {
        $number = (int)$value;
        return $number > 0 ? $number : null;
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function safeMultiline(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $limit, 'UTF-8');
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_json_failed');
        }
        return $json;
    }
}
