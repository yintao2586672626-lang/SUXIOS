<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
use app\service\AiDailyReportService;
use app\service\WechatRobotWebhookSecret;
use think\Response;
use think\facade\Db;

class CompetitorWechatRobotController extends Base
{
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

        $query = Db::name('competitor_wechat_robot')->order('id', 'desc');
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
        $robot = Db::name('competitor_wechat_robot')->where('id', $id)->find();
        if (!$robot) {
            abort(404, '记录不存在');
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
            'name' => $data['name'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        $this->insertProtectedRobot($insert, $webhook);
        OperationLog::record('competitor', 'create_robot', '新增企业微信机器人', $this->currentUser->id);

        return redirect((string)url('admin/CompetitorWechatRobotController/index'));
    }

    public function update(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = Db::name('competitor_wechat_robot')->where('id', $id)->find();
        if (!$robot) {
            abort(404, '记录不存在');
        }

        $data = $this->request->post();
        try {
            $storedWebhook = $this->resolveStoredRobotWebhookForUpdate($data, $robot);
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
        Db::name('competitor_wechat_robot')->where('id', $id)->update($update);
        OperationLog::record('competitor', 'update_robot', '更新企业微信机器人', $this->currentUser->id);

        return redirect((string)url('admin/CompetitorWechatRobotController/index'));
    }

    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();
        Db::name('competitor_wechat_robot')->where('id', $id)->delete();
        OperationLog::record('competitor', 'delete_robot', '删除企业微信机器人', $this->currentUser->id);
        return json(['code' => 200, 'message' => '删除成功']);
    }

    public function testSend(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = Db::name('competitor_wechat_robot')->where('id', $id)->find();
        if (!$robot) {
            return json(['code' => 404, 'message' => '记录不存在']);
        }
        try {
            $webhook = $this->revealRobotWebhook((string)($robot['webhook'] ?? ''), $id);
        } catch (\RuntimeException $e) {
            return json(['code' => 500, 'message' => $this->robotWebhookDecryptFailureMessage()]);
        }
        if ($webhook === '') {
            return json(['code' => 400, 'message' => 'Webhook为空']);
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '# 宿析OS 企业微信联通测试' . "\n" . '> 机器人配置可用，消息发送链路正常。',
            ],
        ];
        $result = $this->postJson($webhook, $payload);
        if ($result['success']) {
            return json(['code' => 200, 'message' => '发送成功']);
        }
        return json(['code' => 500, 'message' => '发送失败: ' . $result['error']]);
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
            return json(['code' => 404, 'message' => '该门店未绑定机器人', 'data' => $delivery]);
        }
        if ($status === 'sent') {
            return json(['code' => 200, 'message' => '全部发送成功', 'data' => $delivery]);
        }

        return json([
            'code' => 500,
            'message' => $status === 'partial' ? '部分机器人发送失败' : '发送失败',
            'data' => $delivery,
        ]);
    }

    /**
     * API: 机器人列表
     */
    public function apiIndex(): Response
    {
        $this->checkSuperAdmin();
        $storeId = $this->request->get('store_id', '');
        $query = Db::name('competitor_wechat_robot')->order('id', 'desc');
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
        $robot = Db::name('competitor_wechat_robot')->where('id', $id)->find();
        if (!$robot) {
            return $this->error('记录不存在', 404);
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
            'name' => $data['name'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        try {
            $this->insertProtectedRobot($insert, $webhook);
        } catch (\RuntimeException $e) {
            return $this->error($this->robotWebhookEncryptionFailureMessage(), 500);
        }
        return $this->success(null, '保存成功');
    }

    /**
     * API: 更新
     */
    public function apiUpdate(int $id): Response
    {
        $this->checkSuperAdmin();
        $robot = Db::name('competitor_wechat_robot')->where('id', $id)->find();
        if (!$robot) {
            return $this->error('记录不存在');
        }
        $data = $this->request->post();
        try {
            $storedWebhook = $this->resolveStoredRobotWebhookForUpdate($data, $robot);
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
        Db::name('competitor_wechat_robot')->where('id', $id)->update($update);
        return $this->success(null, '保存成功');
    }

    /**
     * API: 删除
     */
    public function apiDelete(int $id): Response
    {
        $this->checkSuperAdmin();
        Db::name('competitor_wechat_robot')->where('id', $id)->delete();
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
        $this->checkSuperAdmin();
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
        $payload = $this->buildAiDailyReportPayload($report, $hotelName);
        $delivery = $this->sendPayloadToStore($hotelId, $payload);
        $status = (string)($delivery['delivery_status'] ?? 'failed');
        $auditData = array_merge($this->deliveryAuditData($delivery), [
            'report_id' => $reportId,
            'report_date' => (string)($report['report_date'] ?? ''),
            'result_status' => (string)($report['result_readiness']['status'] ?? 'unverified'),
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
        if ($status === 'sent') {
            return $this->success($delivery, 'AI经营日报已发送到企业微信群');
        }
        if ($status === 'partial') {
            return $this->success($delivery, '部分企业微信机器人发送成功', 207);
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
    private function sendPayloadToStore(int $storeId, array $payload): array
    {
        $robots = Db::name('competitor_wechat_robot')
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
        foreach ($robots as $robot) {
            $robotId = (int)($robot['id'] ?? 0);
            $robotName = $this->safeRobotText((string)($robot['name'] ?? ('机器人 #' . $robotId)), 80);
            try {
                $webhook = $this->revealRobotWebhook((string)($robot['webhook'] ?? ''), $robotId);
            } catch (\RuntimeException $e) {
                $failures[] = ['robot_id' => $robotId, 'name' => $robotName, 'reason' => $this->robotWebhookDecryptFailureMessage()];
                continue;
            }
            if ($webhook === '') {
                $failures[] = ['robot_id' => $robotId, 'name' => $robotName, 'reason' => 'Webhook为空'];
                continue;
            }
            $result = $this->postJson($webhook, $payload);
            if (($result['success'] ?? false) === true) {
                $sentCount++;
                continue;
            }
            $failures[] = [
                'robot_id' => $robotId,
                'name' => $robotName,
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
        ];
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
