<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
use app\service\AiDailyReportService;
use app\service\CloudAutomationService;
use app\service\WechatCompetitionReportDeliveryService;
use app\service\WechatCompetitionReportRendererService;
use app\service\WechatRobotWebhookSecret;
use think\Response;
use think\facade\Db;

class CompetitorWechatRobotController extends Base
{
    private const ADMIN_NOTIFICATION_SCOPE = 'admin_shared';

    private ?WechatRobotWebhookSecret $webhookSecret = null;

    private function checkSuperAdmin(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '无权限操作');
        }
    }

    public function index(): Response
    {
        $this->checkSuperAdmin();
        $storeId = $this->request->get('store_id', '');
        $stores = $this->getStores();

        $query = $this->adminManagedRobotQuery()->order('id', 'desc');
        if ($storeId !== '') {
            $query->where('store_id', (int)$storeId);
        }
        $maskedList = array_map(
            fn(array $robot): array => $this->formatRobotListRow($robot),
            $query->select()->toArray()
        );

        return view('competitor_wechat_robot/index', [
            'list' => $maskedList,
            'stores' => $stores,
            'filter_store_id' => $storeId,
        ]);
    }

    public function add(): Response
    {
        $this->checkSuperAdmin();
        return view('competitor_wechat_robot/add', [
            'stores' => $this->getStores(),
        ]);
    }

    public function edit(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = $this->findAdminManagedRobot($id);
        if (!$robot) {
            abort(404, '门店共享机器人不存在');
        }
        return view('competitor_wechat_robot/edit', [
            'robot' => $this->formatRobotDetailRow($robot),
            'stores' => $this->getStores(),
        ]);
    }

    public function save(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->request->post();

        $this->validate($data, [
            'store_id' => 'require|integer',
            'name' => 'require',
            'webhook' => 'require',
        ], [
            'store_id.require' => '请选择门店',
            'name.require' => '请输入机器人名称',
            'webhook.require' => '请输入Webhook地址',
        ]);
        $webhook = $this->normalizeRobotWebhook((string)$data['webhook']);
        if ($webhook === null) {
            abort(400, $this->robotWebhookValidationMessage());
        }
        $insert = [
            'store_id' => (int)$data['store_id'],
            'owner_user_id' => null,
            'notification_scope' => self::ADMIN_NOTIFICATION_SCOPE,
            'name' => $data['name'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        try {
            $this->insertProtectedRobot($insert, $webhook);
        } catch (\RuntimeException $e) {
            abort(500, $this->robotSaveFailureMessage($e));
        }
        OperationLog::record('competitor', 'create_robot', '新增企业微信机器人', $this->currentUser->id);

        return redirect((string)url('admin/CompetitorWechatRobotController/index'));
    }

    public function update(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = $this->findAdminManagedRobot($id);
        if (!$robot) {
            abort(404, '门店共享机器人不存在');
        }

        $data = $this->request->post();
        try {
            $storedWebhook = $this->resolveStoredRobotWebhookForUpdate($data, $robot);
            $webhookChanged = $this->robotWebhookChanged($data, $robot);
        } catch (\RuntimeException $e) {
            abort(500, $this->robotWebhookEncryptionFailureMessage());
        }
        if ($storedWebhook === null) {
            abort(400, $this->robotWebhookValidationMessage());
        }
        $update = [
            'store_id' => (int)($data['store_id'] ?? $robot['store_id']),
            'name' => $data['name'] ?? $robot['name'],
            'webhook' => $storedWebhook,
            'status' => isset($data['status']) ? (int)$data['status'] : (int)$robot['status'],
        ];
        try {
            $this->persistAdminManagedRobotUpdate($id, $update, $webhookChanged);
        } catch (\RuntimeException $e) {
            abort(500, $this->robotSaveFailureMessage($e));
        }
        OperationLog::record('competitor', 'update_robot', '更新企业微信机器人', $this->currentUser->id);

        return redirect((string)url('admin/CompetitorWechatRobotController/index'));
    }

    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();
        if ($this->findAdminManagedRobot($id) === null) {
            return json(['code' => 404, 'message' => '门店共享机器人不存在'], 404);
        }
        $this->adminManagedRobotQuery()->where('id', $id)->delete();
        OperationLog::record('competitor', 'delete_robot', '删除企业微信机器人', $this->currentUser->id);
        return json(['code' => 200, 'message' => '删除成功']);
    }

    public function testSend(int $id): Response
    {
        $this->checkSuperAdmin();
        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '# 宿析OS 企业微信联通测试' . "\n" . '> 机器人配置可用，消息发送链路正常。',
            ],
        ];
        $result = $this->testAdminManagedRobot($id, $payload);
        if (($result['eligible'] ?? false) !== true) {
            return json(['code' => 404, 'message' => '门店共享机器人不存在'], 404);
        }
        if (($result['success'] ?? false) === true) {
            return json(['code' => 200, 'message' => '发送成功']);
        }
        $httpCode = (int)($result['http_code'] ?? 500);
        $httpStatus = $httpCode >= 400 && $httpCode <= 599 ? $httpCode : 500;
        return json([
            'code' => $httpCode,
            'message' => '发送失败: ' . (string)($result['error'] ?? '企业微信未确认送达'),
        ], $httpStatus);
    }

    /**
     * 按门店测试发送（同时发送所有Webhook）
     */
    public function testSendStore(int $storeId): Response
    {
        $this->checkSuperAdmin();
        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '# 宿析OS 企业微信联通测试' . "\n" . '> 机器人配置可用，消息发送链路正常。',
            ],
        ];
        $delivery = $this->sendPayloadToStore($storeId, $payload);
        $status = (string)($delivery['delivery_status'] ?? 'failed');
        OperationLog::record(
            'competitor',
            'test_wecom_robot',
            '测试企业微信机器人',
            (int)$this->currentUser->id,
            $storeId,
            $status === 'sent' ? null : '企业微信测试发送未全部成功',
            $this->deliveryAuditData($delivery)
        );

        if ($status === 'binding_missing') {
            return json(['code' => 404, 'message' => '该门店未绑定共享机器人', 'data' => $delivery], 404);
        }
        if ($status === 'sent') {
            return json(['code' => 200, 'message' => '全部发送成功', 'data' => $delivery]);
        }

        return json([
            'code' => 500,
            'message' => $status === 'partial' ? '部分机器人发送失败' : '发送失败',
            'data' => $delivery,
        ], 500);
    }

    /**
     * API: 机器人列表
     */
    public function apiIndex(): Response
    {
        $this->checkSuperAdmin();
        $storeId = $this->request->get('store_id', '');
        $query = $this->adminManagedRobotQuery()->order('id', 'desc');
        if ($storeId !== '') {
            $query->where('store_id', (int)$storeId);
        }
        $pagination = $this->getPagination();
        $total = $query->count();
        $maskedList = array_map(
            fn(array $robot): array => $this->formatRobotListRow($robot),
            $query->page($pagination['page'], $pagination['page_size'])->select()->toArray()
        );
        return $this->paginate($maskedList, $total, $pagination['page'], $pagination['page_size']);
    }

    public function apiDetail(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = $this->findAdminManagedRobot($id);
        if (!$robot) {
            return $this->error('门店共享机器人不存在', 404);
        }
        return $this->success($this->formatRobotDetailRow($robot));
    }

    /**
     * API: 新增
     */
    public function apiSave(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->request->post();
        $this->validate($data, [
            'store_id' => 'require|integer',
            'name' => 'require',
            'webhook' => 'require',
        ]);
        $webhook = $this->normalizeRobotWebhook((string)$data['webhook']);
        if ($webhook === null) {
            return $this->error($this->robotWebhookValidationMessage(), 400);
        }
        $insert = [
            'store_id' => (int)$data['store_id'],
            'owner_user_id' => null,
            'notification_scope' => self::ADMIN_NOTIFICATION_SCOPE,
            'name' => $data['name'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        try {
            $this->insertProtectedRobot($insert, $webhook);
        } catch (\RuntimeException $e) {
            return $this->error($this->robotSaveFailureMessage($e), 500);
        }
        return $this->success(null, '保存成功');
    }

    /**
     * API: 更新
     */
    public function apiUpdate(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = $this->findAdminManagedRobot($id);
        if (!$robot) {
            return $this->error('门店共享机器人不存在', 404);
        }
        $data = $this->request->post();
        try {
            $storedWebhook = $this->resolveStoredRobotWebhookForUpdate($data, $robot);
            $webhookChanged = $this->robotWebhookChanged($data, $robot);
        } catch (\RuntimeException $e) {
            return $this->error($this->robotWebhookEncryptionFailureMessage(), 500);
        }
        if ($storedWebhook === null) {
            return $this->error($this->robotWebhookValidationMessage(), 400);
        }
        $update = [
            'store_id' => (int)($data['store_id'] ?? $robot['store_id']),
            'name' => $data['name'] ?? $robot['name'],
            'webhook' => $storedWebhook,
            'status' => isset($data['status']) ? (int)$data['status'] : (int)$robot['status'],
        ];
        try {
            $this->persistAdminManagedRobotUpdate($id, $update, $webhookChanged);
        } catch (\RuntimeException $e) {
            return $this->error($this->robotSaveFailureMessage($e), 500);
        }
        return $this->success(null, '保存成功');
    }

    /**
     * API: 删除
     */
    public function apiDelete(int $id): Response
    {
        $this->checkSuperAdmin();
        if ($this->findAdminManagedRobot($id) === null) {
            return $this->error('门店共享机器人不存在', 404);
        }
        $this->adminManagedRobotQuery()->where('id', $id)->delete();
        return $this->success(null, '删除成功');
    }

    /**
     * API: 门店测试发送
     */
    public function apiTestStore(int $storeId): Response
    {
        return $this->testSendStore($storeId);
    }

    /**
     * API: 将一份已保存、已按酒店范围回读的 AI 经营日报发送到企业微信群。
     */
    public function apiSendAiDailyReport(int $id): Response
    {
        if (!$this->currentUser) {
            return $this->error('请先登录后再发送企业微信汇报', 401);
        }
        $input = $this->requestData();
        $requestedEdition = trim((string)($input['edition'] ?? WechatCompetitionReportRendererService::EDITION_LITE));
        $renderer = new WechatCompetitionReportRendererService();
        try {
            $requestedEdition = $renderer->normalizeEdition($requestedEdition);
        } catch (\InvalidArgumentException) {
            return $this->error('企业微信汇报版本必须是简版或旗舰版', 422);
        }
        $isAdmin = (bool)$this->currentUser->isSuperAdmin();
        if ($requestedEdition === WechatCompetitionReportRendererService::EDITION_FLAGSHIP && !$isAdmin) {
            return $this->error('旗舰版企业微信汇报仅允许管理员生成和发送', 403);
        }

        $reportId = $id;
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        if (empty($hotelIds)) {
            return $this->error('当前账号没有可发送日报的门店范围', 403);
        }

        $report = (new AiDailyReportService())->read($reportId, $hotelIds);
        if (!is_array($report)) {
            return $this->error('AI经营日报不存在或不在当前门店权限范围内', 404);
        }

        $hotelId = (int)($report['hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            return $this->error('AI经营日报缺少有效门店范围', 422);
        }

        $hotelName = trim((string)Db::name('hotels')->where('id', $hotelId)->value('name'));
        if ($hotelName === '') {
            $hotelName = '酒店 #' . $hotelId;
        }
        $competitionBundle = is_array($report['competition_circle_bundle'] ?? null)
            ? $report['competition_circle_bundle']
            : [];
        if ($competitionBundle === [] && $requestedEdition === WechatCompetitionReportRendererService::EDITION_FLAGSHIP) {
            return $this->error('该历史日报没有竞争商圈结果，不能生成旗舰版企业微信汇报', 422);
        }
        if ($competitionBundle !== []) {
            if (($report['competition_bundle_readback']['exact_readback_verified'] ?? false) !== true) {
                return $this->error('竞争商圈结果未通过保存后精确回读，请重新生成日报后再发送', 409, [
                    'readback_status' => (string)($report['competition_bundle_readback']['status'] ?? 'legacy_unverified'),
                    'exact_readback_verified' => false,
                ]);
            }
            $delivery = (new WechatCompetitionReportDeliveryService())->deliver(
                $report,
                $hotelId,
                $hotelName,
                $requestedEdition,
                ['requested_by' => (int)$this->currentUser->id]
            );
            $rendered = [
                'report_edition' => (string)($delivery['report_edition'] ?? $requestedEdition),
                'status_only' => (bool)($delivery['status_only'] ?? true),
                'source_fingerprint' => (string)($delivery['source_fingerprint'] ?? ''),
                'bundle_id' => (string)($delivery['bundle_id'] ?? ''),
            ];
        } else {
            $payload = $this->buildAiDailyReportPayload($report, $hotelName);
            $rendered = [
                'report_edition' => WechatCompetitionReportRendererService::EDITION_LITE,
                'status_only' => false,
                'source_fingerprint' => '',
                'bundle_id' => '',
            ];
            $delivery = (new CloudAutomationService())->deliverSavedDailyReport(
                $hotelId,
                $reportId,
                (string)($report['report_date'] ?? ''),
                $payload,
                [
                    'requested_by' => (int)$this->currentUser->id,
                    'report_edition' => (string)$rendered['report_edition'],
                    'status_only' => (bool)$rendered['status_only'],
                    'source_fingerprint' => (string)$rendered['source_fingerprint'],
                    'bundle_id' => (string)$rendered['bundle_id'],
                    'artifact_kind' => 'summary_text',
                ]
            );
        }
        $delivery = array_merge($delivery, [
            'report_edition' => (string)$rendered['report_edition'],
            'status_only' => (bool)$rendered['status_only'],
            'source_fingerprint' => (string)$rendered['source_fingerprint'],
            'bundle_id' => (string)$rendered['bundle_id'],
            'single_calculation' => $competitionBundle !== [],
        ]);
        $status = (string)($delivery['delivery_status'] ?? 'failed');
        $auditData = array_merge($this->deliveryAuditData($delivery), [
            'report_id' => $reportId,
            'report_date' => (string)($report['report_date'] ?? ''),
            'result_status' => (string)($report['result_readiness']['status'] ?? 'unverified'),
            'report_edition' => (string)$rendered['report_edition'],
            'status_only' => (bool)$rendered['status_only'],
            'source_fingerprint' => (string)$rendered['source_fingerprint'],
        ]);
        OperationLog::record(
            'ai_daily_report',
            'send_wecom',
            '发送AI经营日报到企业微信',
            (int)$this->currentUser->id,
            $hotelId,
            $status === 'sent' ? null : '企业微信日报发送未全部成功',
            $auditData
        );

        if ($status === 'binding_missing') {
            return $this->error('该门店尚未绑定启用中的企业微信机器人', 404, $delivery);
        }
        if ($status === 'in_progress') {
            return $this->error('该日报正在投递，或上次投递结果需要人工确认；本次未重复发送', 409, $delivery);
        }
        if ($status === 'blocked_by_p0_ota_gate') {
            return $this->error('正式企业微信汇报缺少目标日期的真实OTA校验凭证', 409, $delivery);
        }
        if ($status === 'sent') {
            $editionLabel = (string)$rendered['report_edition'] === WechatCompetitionReportRendererService::EDITION_FLAGSHIP
                ? '旗舰版'
                : '简版';
            return $this->success($delivery, $editionLabel . '企业微信文字与图卡均已发送');
        }
        if ($status === 'partial') {
            return $this->success($delivery, '企业微信文字或图卡仅部分送达，请查看分项状态', 207);
        }

        return $this->error('企业微信发送失败，请查看机器人配置和发送状态', 502, $delivery);
    }

    /**
     * @param array<string, mixed> $report
     * @return array{msgtype: string, markdown: array{content: string}}
     */
    private function buildAiDailyReportPayload(array $report, string $hotelName): array
    {
        $readiness = is_array($report['result_readiness'] ?? null) ? $report['result_readiness'] : [];
        $lines = [
            '# 宿析OS AI经营日报',
            '> 门店：' . $this->safeRobotText($hotelName, 80),
            '> 日期：' . $this->safeRobotText((string)($report['report_date'] ?? '未返回'), 24),
            '> 数据状态：' . $this->safeRobotText((string)($readiness['status_label'] ?? '未核验'), 40),
            '',
            '**摘要**',
            $this->safeRobotText((string)($report['summary'] ?? '当前日报未返回摘要。'), 500),
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
            $label = $this->safeRobotText((string)($metric['label'] ?? $metric['key'] ?? '指标'), 40);
            $unit = $this->safeRobotText((string)($metric['unit'] ?? ''), 12);
            $metricLines[] = '- ' . $label . '：' . $this->safeRobotText((string)$value, 40) . $unit;
        }
        if (!empty($metricLines)) {
            $lines[] = '';
            $lines[] = '**已返回指标**';
            array_push($lines, ...$metricLines);
        }

        $dataGaps = array_values(array_filter((array)($report['data_gaps'] ?? []), 'is_array'));
        if (!empty($dataGaps)) {
            $lines[] = '';
            $lines[] = '**数据缺口（不以 0 代替）**';
            foreach (array_slice($dataGaps, 0, 3) as $gap) {
                $gapText = (string)($gap['message'] ?? $gap['label'] ?? $gap['code'] ?? '未说明缺口');
                $lines[] = '- ' . $this->safeRobotText($gapText, 180);
            }
        }

        $actions = array_values(array_filter((array)($report['recommended_actions'] ?? []), 'is_array'));
        if (!empty($actions)) {
            $lines[] = '';
            $lines[] = '**建议动作（需人工确认）**';
            foreach (array_slice($actions, 0, 3) as $index => $action) {
                $actionText = (string)($action['action'] ?? $action['title'] ?? $action['reason'] ?? '未说明动作');
                $blocked = trim((string)($action['blocked_reason'] ?? ''));
                $lines[] = ($index + 1) . '. ' . $this->safeRobotText($actionText, 180)
                    . ($blocked !== '' ? '（当前阻塞：' . $this->safeRobotText($blocked, 120) . '）' : '');
            }
        }

        $scopeNote = (string)(
            $readiness['scope_note']
            ?? $report['report_scope']['scope_note']
            ?? '仅按本日报已保存证据展示，不自动代表全酒店完整经营事实。'
        );
        $lines[] = '';
        $lines[] = '> 范围说明：' . $this->safeRobotText($scopeNote, 260);
        $lines[] = '> 本次发送只读取已保存日报，不触发 OTA 采集，也不改动平台数据。';

        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => mb_strcut(implode("\n", $lines), 0, 3800, 'UTF-8'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendPayloadToStore(
        int $storeId,
        array $payload,
        ?callable $transport = null
    ): array
    {
        $robots = $this->adminManagedRobotQuery()
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if (empty($robots)) {
            return [
                'delivery_status' => 'binding_missing',
                'hotel_id' => $storeId,
                'robot_count' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'failures' => [],
            ];
        }

        $sentCount = 0;
        $failures = [];
        $results = [];
        foreach ($robots as $robot) {
            $robotId = (int)($robot['id'] ?? 0);
            $result = $this->testAdminManagedRobot(
                $robotId,
                $payload,
                $transport,
                $storeId,
                true
            );
            $results[] = [
                'robot_id' => $robotId,
                'status' => (string)($result['test_status'] ?? 'failed'),
                'tested_at' => $result['tested_at'] ?? null,
                'state_persisted' => (bool)($result['state_persisted'] ?? false),
            ];
            if (($result['success'] ?? false) === true) {
                $sentCount++;
                continue;
            }
            $failures[] = [
                'robot_id' => $robotId,
                'name' => (string)($result['name'] ?? ('机器人 #' . $robotId)),
                'reason' => $this->safeRobotText((string)($result['error'] ?? '发送失败'), 180),
            ];
        }

        $failedCount = count($failures);
        $status = $sentCount === count($robots)
            ? 'sent'
            : ($sentCount > 0 ? 'partial' : 'failed');
        return [
            'delivery_status' => $status,
            'hotel_id' => $storeId,
            'robot_count' => count($robots),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'failures' => $failures,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function testAdminManagedRobot(
        int $robotId,
        array $payload,
        ?callable $transport = null,
        ?int $expectedStoreId = null,
        bool $requireEnabled = false
    ): array {
        return Db::transaction(function () use (
            $robotId,
            $payload,
            $transport,
            $expectedStoreId,
            $requireEnabled
        ): array {
            $robot = $this->findAdminManagedRobot($robotId, true);
            if ($robot === null
                || ($expectedStoreId !== null && (int)($robot['store_id'] ?? 0) !== $expectedStoreId)
                || ($requireEnabled && (int)($robot['status'] ?? 0) !== 1)
            ) {
                return [
                    'eligible' => false,
                    'success' => false,
                    'state_persisted' => false,
                    'test_status' => 'failed',
                    'error' => '机器人配置已变化，本次未发送',
                    'http_code' => 409,
                ];
            }

            $storedWebhook = (string)($robot['webhook'] ?? '');
            $robotName = $this->safeRobotText(
                (string)($robot['name'] ?? ('机器人 #' . $robotId)),
                80
            );
            $deliverySuccess = false;
            $error = '';
            $httpCode = 500;
            try {
                $webhook = $this->revealRobotWebhook($storedWebhook, $robotId);
            } catch (\RuntimeException) {
                $webhook = '';
                $error = $this->robotWebhookDecryptFailureMessage();
            }
            if ($error === '') {
                if ($webhook === '') {
                    $error = 'Webhook为空';
                    $httpCode = 400;
                } else {
                    $sender = $transport === null
                        ? fn(string $url, array $body, array $currentRobot): array =>
                            $this->postJson($url, $body)
                        : \Closure::fromCallable($transport);
                    try {
                        $delivery = $sender($webhook, $payload, $robot);
                        $deliverySuccess = is_array($delivery)
                            && (($delivery['success'] ?? false) === true);
                        $error = $deliverySuccess
                            ? ''
                            : (string)($delivery['error'] ?? '企业微信未确认送达');
                    } catch (\Throwable) {
                        $error = $this->robotWebhookRequestFailureMessage();
                    }
                }
            }

            $testedAt = date('Y-m-d H:i:s');
            $testStatus = $deliverySuccess ? 'sent' : 'failed';
            $updated = $this->adminManagedRobotQuery()
                ->where('id', $robotId)
                ->where('store_id', (int)$robot['store_id'])
                ->where('webhook', $storedWebhook)
                ->update([
                    'last_tested_at' => $testedAt,
                    'last_test_status' => $testStatus,
                ]);
            $statePersisted = $updated === 1;
            if (!$statePersisted) {
                $deliverySuccess = false;
                $testStatus = 'binding_changed';
                $error = 'Webhook 配置已变化，请重新测试当前配置';
                $httpCode = 409;
            }

            return [
                'eligible' => true,
                'robot_id' => $robotId,
                'name' => $robotName,
                'success' => $deliverySuccess,
                'state_persisted' => $statePersisted,
                'test_status' => $testStatus,
                'tested_at' => $statePersisted ? $testedAt : null,
                'error' => $error,
                'http_code' => $httpCode,
            ];
        });
    }

    /** @param array<string, mixed> $delivery */
    private function deliveryAuditData(array $delivery): array
    {
        return [
            'outcome' => match ((string)($delivery['delivery_status'] ?? 'failed')) {
                'sent' => 'success',
                'partial' => 'partial',
                default => 'failed',
            },
            'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
            'robot_count' => (int)($delivery['robot_count'] ?? 0),
            'sent_count' => (int)($delivery['sent_count'] ?? 0),
            'failed_count' => (int)($delivery['failed_count'] ?? 0),
        ];
    }

    private function safeRobotText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $value = str_replace(['<', '>'], ['＜', '＞'], $value);
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }

    private function formatRobotListRow(array $robot): array
    {
        $storedWebhook = trim((string)($robot['webhook'] ?? ''));
        return [
            'id' => (int)($robot['id'] ?? 0),
            'store_id' => (int)($robot['store_id'] ?? 0),
            'name' => (string)($robot['name'] ?? ''),
            'webhook_masked' => $storedWebhook !== ''
                ? 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=******'
                : '',
            'webhook_configured' => $storedWebhook !== '',
            'notification_scope' => self::ADMIN_NOTIFICATION_SCOPE,
            'scope_label' => '门店共享',
            'status' => (int)($robot['status'] ?? 0),
            'create_time' => $robot['create_time'] ?? null,
        ];
    }

    private function formatRobotDetailRow(array $robot): array
    {
        $row = $this->formatRobotListRow($robot);
        $row['webhook'] = '';
        $row['webhook_placeholder'] = $row['webhook_configured']
            ? '留空则保留当前 Webhook：' . $row['webhook_masked']
            : '请输入企业微信 Webhook';
        return $row;
    }

    private function adminManagedRobotQuery()
    {
        return Db::name('competitor_wechat_robot')
            ->where(function ($query): void {
                $query->whereNull('owner_user_id')
                    ->whereOr('owner_user_id', 0);
            })
            ->where(function ($query): void {
                $query->whereNull('notification_scope')
                    ->whereOr('notification_scope', '')
                    ->whereOr('notification_scope', self::ADMIN_NOTIFICATION_SCOPE);
            });
    }

    private function findAdminManagedRobot(int $id, bool $lock = false): ?array
    {
        $query = $this->adminManagedRobotQuery()->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }
        $robot = $query->find();
        return is_array($robot) ? $robot : null;
    }

    private function resolveStoredRobotWebhookForUpdate(array $data, array $robot): ?string
    {
        if (!array_key_exists('webhook', $data) || trim((string)$data['webhook']) === '') {
            $existingWebhook = trim((string)($robot['webhook'] ?? ''));
            return $existingWebhook !== '' ? $existingWebhook : null;
        }

        $webhook = $this->normalizeRobotWebhook((string)$data['webhook']);
        if ($webhook === null) {
            return null;
        }
        return $this->protectRobotWebhookForStorage($webhook, (int)($robot['id'] ?? 0));
    }

    private function robotWebhookChanged(array $data, array $robot): bool
    {
        if (!array_key_exists('webhook', $data) || trim((string)$data['webhook']) === '') {
            return false;
        }
        $webhook = $this->normalizeRobotWebhook((string)$data['webhook']);
        if ($webhook === null) {
            return false;
        }
        $current = $this->revealRobotWebhook(
            (string)($robot['webhook'] ?? ''),
            (int)($robot['id'] ?? 0)
        );
        return !hash_equals(trim($current), $webhook);
    }

    private function normalizeRobotWebhook(string $webhook): ?string
    {
        $webhook = trim($webhook);
        if ($webhook === '') {
            return null;
        }
        $parts = parse_url($webhook);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $query = (string)($parts['query'] ?? '');
        parse_str($query, $queryParams);
        $key = $queryParams['key'] ?? '';

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || $scheme !== 'https'
            || $host !== 'qyapi.weixin.qq.com'
            || $path !== '/cgi-bin/webhook/send'
            || !is_string($key)
            || trim($key) === ''
        ) {
            return null;
        }
        return $webhook;
    }

    private function robotWebhookValidationMessage(): string
    {
        return '企业微信 Webhook 必须使用 https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...';
    }

    private function postJson(string $url, array $data): array
    {
        $url = $this->normalizeRobotWebhook($url);
        if ($url === null) {
            return ['success' => false, 'error' => $this->robotWebhookValidationMessage()];
        }
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timeout' => 10,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['success' => false, 'error' => $this->robotWebhookRequestFailureMessage()];
        }
        return $this->interpretRobotWebhookResponse(
            $response,
            isset($http_response_header) && is_array($http_response_header) ? $http_response_header : []
        );
    }

    /**
     * @param array<int, string> $responseHeaders
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    private function interpretRobotWebhookResponse(string $response, array $responseHeaders = []): array
    {
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches) === 1) {
                $status = (int)$matches[1];
            }
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            return ['success' => false, 'error' => '企业微信 Webhook 返回 HTTP ' . $status];
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['success' => false, 'error' => '企业微信 Webhook 返回格式异常'];
        }
        if (!is_array($decoded) || !array_key_exists('errcode', $decoded) || !is_numeric($decoded['errcode'])) {
            return ['success' => false, 'error' => '企业微信 Webhook 返回缺少结果状态'];
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
            ];
        }

        return ['success' => true, 'data' => $decoded];
    }

    private function robotWebhookRequestFailureMessage(): string
    {
        return '企业微信 Webhook 请求失败，请检查网络或机器人配置';
    }

    /** @param array<string, mixed> $insert */
    private function insertProtectedRobot(array $insert, string $webhook): int
    {
        return Db::transaction(function () use ($insert, $webhook): int {
            $insert = $this->withRobotTenantScope($insert);
            $insert['webhook'] = '';
            $robotId = (int)Db::name('competitor_wechat_robot')->insertGetId($insert);
            if ($robotId <= 0) {
                throw new \RuntimeException($this->robotWebhookEncryptionFailureMessage());
            }
            $storedWebhook = $this->protectRobotWebhookForStorage($webhook, $robotId);
            $updated = Db::name('competitor_wechat_robot')
                ->where('id', $robotId)
                ->where('webhook', '')
                ->update(['webhook' => $storedWebhook]);
            if ($updated !== 1) {
                throw new \RuntimeException($this->robotWebhookEncryptionFailureMessage());
            }
            return $robotId;
        });
    }

    /** @param array<string, mixed> $update */
    private function persistAdminManagedRobotUpdate(
        int $robotId,
        array $update,
        bool $webhookChanged
    ): void {
        Db::transaction(function () use ($robotId, $update, $webhookChanged): void {
            if ($webhookChanged) {
                $robotFields = $this->tableFields('competitor_wechat_robot');
                if (in_array('last_test_status', $robotFields, true)) {
                    $update['last_test_status'] = 'pending';
                }
                if (in_array('last_tested_at', $robotFields, true)) {
                    $update['last_tested_at'] = null;
                }
            }
            $update = $this->withRobotTenantScope($update);
            $this->adminManagedRobotQuery()->where('id', $robotId)->update($update);
            if ($webhookChanged) {
                $this->invalidateNotificationPlans($robotId);
            }
        });
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function withRobotTenantScope(array $values): array
    {
        if (!$this->tableHasColumn('competitor_wechat_robot', 'tenant_id')) {
            return $values;
        }
        if (!$this->tableHasColumn('hotels', 'tenant_id')) {
            throw new \RuntimeException('robot_hotel_tenant_scope_unavailable');
        }
        $storeId = (int)($values['store_id'] ?? 0);
        $tenantId = $storeId > 0
            ? (int)Db::name('hotels')->where('id', $storeId)->value('tenant_id')
            : 0;
        if ($tenantId <= 0) {
            throw new \RuntimeException('robot_hotel_tenant_scope_missing');
        }
        $values['tenant_id'] = $tenantId;
        return $values;
    }

    private function invalidateNotificationPlans(int $robotId): void
    {
        $fields = $this->tableFields('manual_notifications');
        if (!in_array('test_robot_id', $fields, true)
            || !in_array('schedule_status', $fields, true)
        ) {
            return;
        }
        $values = ['schedule_status' => 'awaiting_test'];
        foreach ([
            'last_test_status' => 'never_tested',
            'last_test_message' => null,
            'last_tested_at' => null,
            'last_tested_by' => null,
        ] as $field => $value) {
            if (in_array($field, $fields, true)) {
                $values[$field] = $value;
            }
        }
        if (in_array('update_time', $fields, true)) {
            $values['update_time'] = date('Y-m-d H:i:s');
        }
        $query = Db::name('manual_notifications')->where('test_robot_id', $robotId);
        if (in_array('enabled', $fields, true)) {
            $query->where('enabled', 1);
        }
        $query->update($values);
    }

    /** @return array<int, string> */
    private function tableFields(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
            return is_array($fields) ? array_values($fields) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableFields($table), true);
    }

    private function protectRobotWebhookForStorage(string $webhook, int $robotId): string
    {
        try {
            return $this->webhookSecret()->protect($webhook, $robotId);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->robotWebhookEncryptionFailureMessage(), 0, $e);
        }
    }

    private function revealRobotWebhook(string $stored, int $robotId): string
    {
        try {
            return $this->webhookSecret()->reveal($stored, $robotId);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->robotWebhookDecryptFailureMessage(), 0, $e);
        }
    }

    private function webhookSecret(): WechatRobotWebhookSecret
    {
        return $this->webhookSecret ??= new WechatRobotWebhookSecret();
    }

    private function robotWebhookEncryptionFailureMessage(): string
    {
        return 'Webhook 安全存储失败，请检查应用密钥配置';
    }

    private function robotSaveFailureMessage(\RuntimeException $error): string
    {
        return str_starts_with($error->getMessage(), 'robot_hotel_tenant_scope_')
            ? '门店租户范围不可用，无法安全保存企业微信机器人'
            : $this->robotWebhookEncryptionFailureMessage();
    }

    private function robotWebhookDecryptFailureMessage(): string
    {
        return 'Webhook 解密失败，请检查应用密钥配置';
    }

    private function getStores(): array
    {
        $tables = Db::query("SHOW TABLES LIKE 'store'");
        if (!empty($tables)) {
            return Db::name('store')->field('id,name')->order('id', 'asc')->select()->toArray();
        }
        return Db::name('hotels')->field('id,name')->order('id', 'asc')->select()->toArray();
    }
}
