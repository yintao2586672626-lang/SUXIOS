<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ManualNotificationService
{
    /** @deprecated Kept only for source compatibility with older callers. */
    public const TEST_HOTEL_ID = 80;
    /** @deprecated Kept only for source compatibility with older callers. */
    public const TEST_ROBOT_ID = 1;
    /** @deprecated Kept only for source compatibility with older callers. */
    public const TEST_ROBOT_NAME = '漠蓝测试';
    public const DYNAMIC_REPORT_TYPE = 'operating_target_report';
    public const OPERATING_DAILY_REPORT_TYPE = 'operating_daily_report';

    private const TIMEZONE = 'Asia/Shanghai';
    private const TYPES = [
        self::OPERATING_DAILY_REPORT_TYPE => '经营日报',
        self::DYNAMIC_REPORT_TYPE => '每日经营目标报告',
        'ai_analysis_result' => 'AI分析结果',
        'anomaly_alert' => '异常预警',
        'task_notification' => '任务通知',
        'today_revenue_management' => '今日收益管理',
        'future_room_status' => '远期房态',
        'daily_review' => '今日复盘',
        'blank_custom' => '空白自定义',
    ];
    private const SEND_METHODS = [
        'wecom_test' => '企业微信测试机器人（仅测试群）',
        'wecom_formal' => '企业微信正式计划机器人',
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
        private readonly ?WechatRobotDeliveryService $deliveries = null
    ) {
        $this->testDispatcher = $testDispatcher;
    }

    public static function isDynamicReportType(string $type): bool
    {
        return in_array(
            trim($type),
            [self::DYNAMIC_REPORT_TYPE, self::OPERATING_DAILY_REPORT_TYPE],
            true
        );
    }

    /** @return array<string, mixed> */
    public function metadata(
        string $businessDate = '',
        int $tenantId = 0,
        int $hotelId = 0,
        int $robotId = 0
    ): array
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
                'dynamic' => self::isDynamicReportType($key),
            ];
        }

        try {
            $scheduler = $this->dispatchLedger()->latestScheduleRun($tenantId, $hotelId, $robotId);
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
                'hotel_id' => null,
                'robot_id' => null,
                'robot_name' => null,
                'selection_required' => true,
                'formal_group_delivery_allowed' => true,
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
        $requestedId = max(0, (int)($input['id'] ?? 0));
        $existing = null;
        if ($requestedId > 0) {
            $existing = Db::name('manual_notifications')
                ->where('id', $requestedId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->find();
            if (!is_array($existing)) {
                throw new \RuntimeException('manual_notification_not_found');
            }
        }
        $targetRobotId = ($normalized['target_robot_provided'] ?? false) === true
            ? (int)$normalized['target_robot_id']
            : (int)($existing['test_robot_id'] ?? 0);
        $targetRobotName = ($normalized['target_robot_provided'] ?? false) === true
            ? (string)$normalized['target_robot_name']
            : trim((string)($existing['test_robot_name'] ?? ''));
        if ((string)$normalized['send_method'] === 'wecom_formal'
            && ($targetRobotId <= 0 || $targetRobotName === '')
        ) {
            throw new \InvalidArgumentException('manual_notification_target_required');
        }
        if ($targetRobotId > 0 || $targetRobotName !== '') {
            $binding = $this->deliveryService()->resolvePlanRobot(
                $tenantId,
                $hotelId,
                $targetRobotId,
                $targetRobotName,
                (int)($existing['created_by'] ?? $userId),
                (string)$normalized['send_method'] === 'wecom_formal' ? 'formal' : 'test'
            );
            if (($binding['eligible'] ?? false) !== true) {
                throw new \InvalidArgumentException('manual_notification_target_binding_invalid');
            }
        }

        $testStillValid = is_array($existing)
            && (string)($existing['last_test_status'] ?? '') === 'sent'
            && (int)($existing['test_robot_id'] ?? 0) === $targetRobotId
            && trim((string)($existing['test_robot_name'] ?? '')) === $targetRobotName
            && in_array((string)($existing['send_method'] ?? ''), ['wecom_test', 'wecom_formal'], true)
            && in_array((string)$normalized['send_method'], ['wecom_test', 'wecom_formal'], true)
            && $this->sameTestedPlan($existing, $normalized);
        $now = $this->now()->format('Y-m-d H:i:s');
        $scheduleStatus = $normalized['enabled'] && $normalized['trigger_type'] !== 'manual_test'
            ? ($testStillValid ? 'schedule_enabled' : 'awaiting_test')
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
            'last_test_status' => $testStillValid
                ? (string)$existing['last_test_status']
                : 'never_tested',
            'last_test_message' => $testStillValid ? ($existing['last_test_message'] ?? null) : null,
            'last_tested_at' => $testStillValid ? ($existing['last_tested_at'] ?? null) : null,
            'last_tested_by' => $testStillValid ? ($existing['last_tested_by'] ?? null) : null,
            'test_robot_id' => $targetRobotId > 0 ? $targetRobotId : null,
            'test_robot_name' => $targetRobotName !== '' ? $targetRobotName : null,
            'created_by' => $userId,
            'update_time' => $now,
        ];
        if ($requestedId > 0) {
            unset($recordData['tenant_id'], $recordData['hotel_id'], $recordData['created_by']);
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
        $this->assertTestRequest(
            $confirmed,
            $targetRobotId,
            $targetRobotName,
            $idempotencyKey
        );
        $storedScope = Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->field('tenant_id,hotel_id')
            ->find();
        if (!is_array($storedScope)
            || (int)($storedScope['tenant_id'] ?? 0) !== $tenantId
            || (int)($storedScope['hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $record = $this->read($tenantId, $hotelId, $notificationId);
        if (!in_array((string)$record['send_method'], ['wecom_test', 'wecom_formal'], true)) {
            throw new \InvalidArgumentException('manual_notification_test_method_forbidden');
        }
        $persistedRobotId = (int)($record['target_robot_id'] ?? $record['test_robot_id'] ?? 0);
        $persistedRobotName = trim((string)(
            $record['target_robot_name'] ?? $record['test_robot_name'] ?? ''
        ));
        if (($persistedRobotId > 0 && $persistedRobotId !== $targetRobotId)
            || ($persistedRobotName !== '' && $persistedRobotName !== trim($targetRobotName))
        ) {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $binding = $this->deliveryService()->resolvePlanRobot(
            $tenantId,
            $hotelId,
            $targetRobotId,
            trim($targetRobotName),
            (int)($record['created_by'] ?? $userId),
            'test'
        );
        if (($binding['eligible'] ?? false) !== true) {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $robot = [
            'id' => (int)$binding['robot_id'],
            'name' => (string)$binding['robot_name'],
        ];

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
            (int)$robot['id'],
            (string)$robot['name'],
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
                'formal_group_delivery_allowed' => (string)$record['send_method'] === 'wecom_formal'
                    && (string)$dispatch['status'] === 'sent',
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
                '经营目标报告未通过来源、日期、质量或目标门禁；测试群未发送。'
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
                (int)$robot['id'],
                $candidate['payload'],
                [
                    'notification_id' => $notificationId,
                    'dispatch_id' => (int)$dispatch['id'],
                    'business_date' => $businessDate,
                    'request_kind' => 'immediate_test',
                    'tenant_id' => $tenantId,
                    'robot_name' => (string)$robot['name'],
                    'owner_user_id' => (int)($record['created_by'] ?? $userId),
                    'mode' => 'test',
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
            ? '测试消息已送达“' . (string)$robot['name'] . '”，并保存企业微信业务成功记录。'
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
            'target_robot_id' => (int)$robot['id'],
            'target_robot_name' => (string)$robot['name'],
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
        $now = $this->now();
        $retry = $this->dispatchLedger()->dispatchForRetry(
            $tenantId,
            $hotelId,
            $dispatchId,
            $now
        );
        $mode = strtolower(trim((string)($retry['delivery_mode'] ?? '')));
        if (!in_array($mode, ['test', 'formal'], true)) {
            throw new \InvalidArgumentException('manual_notification_retry_mode_forbidden');
        }
        $record = $this->read($tenantId, $hotelId, (int)$retry['notification_id']);
        if ((int)($record['target_robot_id'] ?? 0) !== (int)$retry['robot_id']
            || trim((string)($record['target_robot_name'] ?? '')) !== (string)$retry['robot_name']
        ) {
            throw new \InvalidArgumentException('manual_notification_retry_target_changed');
        }
        if ($mode === 'formal'
            && ((string)$record['send_method'] !== 'wecom_formal'
                || (bool)($record['enabled'] ?? false) !== true
                || (string)$record['schedule_status'] !== 'schedule_enabled')
        ) {
            throw new \InvalidArgumentException('manual_notification_formal_plan_inactive');
        }
        $binding = $this->deliveryService()->resolvePlanRobot(
            $tenantId,
            $hotelId,
            (int)$retry['robot_id'],
            (string)$retry['robot_name'],
            (int)($record['created_by'] ?? $userId),
            $mode
        );
        if (($binding['eligible'] ?? false) !== true) {
            throw new \InvalidArgumentException('manual_notification_retry_target_invalid');
        }
        if ($this->testDispatcher === null) {
            throw new \RuntimeException('manual_notification_dispatcher_missing');
        }

        $attempt = $this->dispatchLedger()->beginAttempt(
            $dispatchId,
            $now,
            'explicit_retry',
            (string)($retry['previous_status'] ?? '') === 'outcome_unknown'
        );
        if (($attempt['allowed'] ?? false) !== true) {
            throw new \InvalidArgumentException((string)$attempt['reason_code']);
        }
        $delivery = [];
        $exception = null;
        try {
            $delivery = call_user_func(
                $this->testDispatcher,
                $hotelId,
                (int)$retry['robot_id'],
                $retry['payload'],
                [
                    'notification_id' => (int)$retry['notification_id'],
                    'dispatch_id' => $dispatchId,
                    'request_kind' => 'explicit_retry',
                    'tenant_id' => $tenantId,
                    'robot_name' => (string)$retry['robot_name'],
                    'owner_user_id' => (int)($record['created_by'] ?? $userId),
                    'mode' => $mode,
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
        if ($mode === 'test') {
            $this->persistTestResult(
                $tenantId,
                $hotelId,
                (int)$retry['notification_id'],
                $userId,
                ['id' => (int)$retry['robot_id'], 'name' => (string)$retry['robot_name']],
                $sent ? 'sent' : (string)$finished['status'],
                $sent
                    ? '显式重试已送达“' . (string)$retry['robot_name'] . '”。'
                    : '显式重试未确认送达，已保存真实状态。'
            );
        }
        return [
            'delivery_status' => (string)$finished['status'],
            'dispatch' => $finished,
            'delivery_mode' => $mode,
            'formal_group_delivery_allowed' => $mode === 'formal',
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
        $targetRobotProvided = array_key_exists('target_robot_id', $input)
            || array_key_exists('target_robot_name', $input)
            || array_key_exists('test_robot_id', $input)
            || array_key_exists('test_robot_name', $input);
        $targetRobotId = max(0, (int)($input['target_robot_id'] ?? $input['test_robot_id'] ?? 0));
        $targetRobotName = $this->safeText(
            (string)($input['target_robot_name'] ?? $input['test_robot_name'] ?? ''),
            120
        );
        if ($targetRobotProvided && (($targetRobotId > 0) !== ($targetRobotName !== ''))) {
            throw new \InvalidArgumentException('manual_notification_target_required');
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
            'target_robot_provided' => $targetRobotProvided,
            'target_robot_id' => $targetRobotId,
            'target_robot_name' => $targetRobotName,
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
        if (self::isDynamicReportType($type)) {
            if ($tenantId <= 0) {
                return $this->blockedDynamicPreview(
                    $data,
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
            $scheduleStatus = trim((string)($data['schedule_status'] ?? ''));
            if ($scheduleStatus === '') {
                $scheduleStatus = (bool)$data['enabled']
                    && (string)$data['trigger_type'] !== 'manual_test'
                        ? 'awaiting_test'
                        : 'saved_only';
            }
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
                'schedule_status' => $scheduleStatus,
                'schedule_status_label' => $this->scheduleStatusLabel($scheduleStatus),
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
        if (self::isDynamicReportType((string)$record['template_type'])) {
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
        $status = trim((string)($data['schedule_status'] ?? ''));
        if ($status === '') {
            $status = $enabled && $triggerType !== 'manual_test' ? 'awaiting_test' : 'saved_only';
        }
        $statusLabel = $this->scheduleStatusLabel($status);
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
    private function blockedDynamicPreview(array $data, string $code, string $message): array
    {
        return [
            'title' => (string)$data['title'],
            'body' => (string)$data['body'],
            'notification_type' => (string)($data['template_type'] ?? self::DYNAMIC_REPORT_TYPE),
            'template_type' => (string)($data['template_type'] ?? self::DYNAMIC_REPORT_TYPE),
            'notification_type_label' => self::TYPES[
                (string)($data['template_type'] ?? self::DYNAMIC_REPORT_TYPE)
            ] ?? self::TYPES[self::DYNAMIC_REPORT_TYPE],
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
            'target_robot_id' => $this->positiveOrNull($row['test_robot_id'] ?? null),
            'target_robot_name' => $row['test_robot_name'] ?? null,
            'formal_delivery_configured' => (string)($row['send_method'] ?? '') === 'wecom_formal'
                && $this->positiveOrNull($row['test_robot_id'] ?? null) !== null,
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
                'test_robot_id' => $robot === null ? null : (int)($robot['id'] ?? 0),
                'test_robot_name' => $robot === null
                    ? null
                    : (string)($robot['name'] ?? ''),
                'update_time' => $now,
            ]);
        $targetRobotId = $robot === null ? null : $this->positiveOrNull($robot['id'] ?? null);
        $targetRobotName = $robot === null ? null : trim((string)($robot['name'] ?? ''));
        $formalAllowed = (string)$record['send_method'] === 'wecom_formal'
            && $scheduleStatus === 'schedule_enabled';
        return [
            'delivery_status' => $status,
            'message' => $message,
            'schedule_status' => $scheduleStatus,
            'schedule_status_label' => $this->scheduleStatusLabel($scheduleStatus),
            'target_hotel_id' => $hotelId,
            'target_robot_id' => $targetRobotId,
            'target_robot_name' => $targetRobotName,
            'formal_group_delivery_allowed' => $formalAllowed,
        ];
    }

    private function assertTestRequest(
        bool $confirmed,
        int $targetRobotId,
        string $targetRobotName,
        string $idempotencyKey
    ): void {
        if (!$confirmed) {
            throw new \InvalidArgumentException('manual_notification_test_confirmation_required');
        }
        if ($targetRobotId <= 0 || trim($targetRobotName) === '') {
            throw new \InvalidArgumentException('manual_notification_test_target_forbidden');
        }
        $idempotencyKey = trim($idempotencyKey);
        if (mb_strlen($idempotencyKey, 'UTF-8') < 8
            || mb_strlen($idempotencyKey, 'UTF-8') > 128
            || preg_match('/^[A-Za-z0-9:_-]+$/', $idempotencyKey) !== 1
        ) {
            throw new \InvalidArgumentException('manual_notification_idempotency_key_invalid');
        }
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
            ? '经营目标报告门禁未通过。'
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
            self::OPERATING_DAILY_REPORT_TYPE => $date . ' 经营日报',
            self::DYNAMIC_REPORT_TYPE => $date . ' 每日经营目标报告',
            'ai_analysis_result' => $date . ' AI分析结果',
            'anomaly_alert' => $date . ' 异常预警',
            'task_notification' => $date . ' 任务通知',
            'today_revenue_management' => $date . ' 今日收益管理',
            'future_room_status' => $date . ' 远期房态',
            'daily_review' => $date . ' 今日复盘',
            default => $date . ' 自定义通知',
        };
    }

    private function defaultBody(string $type, string $date): string
    {
        return match ($type) {
            self::OPERATING_DAILY_REPORT_TYPE,
            self::DYNAMIC_REPORT_TYPE => implode("\n", [
                '【每日经营目标报告】',
                '酒店：{酒店名称}',
                '经营日期：{经营日期}',
                '正文由同酒店、同日期的已保存经营目标和已核验经营事实动态生成。',
                '缺失、身份不匹配、采集失败或未验证时阻断发送，不以零或旧数据补齐。',
            ]),
            'ai_analysis_result' => implode("\n", [
                '【AI分析结果】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '业务服务尚未提供分析正文；请保存真实分析结果后再启用发送。',
            ]),
            'anomaly_alert' => implode("\n", [
                '【异常预警】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '业务服务尚未提供异常事实；请保存真实异常正文后再启用发送。',
            ]),
            'task_notification' => implode("\n", [
                '【任务通知】',
                '酒店：{酒店名称}',
                '业务日期：{经营日期}',
                '业务服务尚未提供任务正文；请保存真实任务内容后再启用发送。',
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

    /** @param array<string, mixed> $existing @param array<string, mixed> $normalized */
    private function sameTestedPlan(array $existing, array $normalized): bool
    {
        foreach ([
            'notification_type',
            'template_type',
            'business_date',
            'title',
            'body',
            'trigger_type',
            'planned_send_at',
        ] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($normalized[$field] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    private function targetPayloads(): OperatingTargetNotificationPayloadService
    {
        return $this->operatingTargetPayloads ?? new OperatingTargetNotificationPayloadService();
    }

    private function dispatchLedger(): ManualNotificationDispatchLedgerService
    {
        return $this->ledger ?? new ManualNotificationDispatchLedgerService();
    }

    private function deliveryService(): WechatRobotDeliveryService
    {
        return $this->deliveries ?? new WechatRobotDeliveryService();
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
