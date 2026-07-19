<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
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
                'content' => '【测试】竞对价格监控发送正常',
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
        $robots = Db::name('competitor_wechat_robot')
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->select()
            ->toArray();
        if (empty($robots)) {
            return json(['code' => 404, 'message' => '该门店未绑定机器人']);
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '【测试】竞对价格监控发送正常',
            ],
        ];

        $failed = [];
        foreach ($robots as $robot) {
            try {
                $webhook = $this->revealRobotWebhook(
                    (string)($robot['webhook'] ?? ''),
                    (int)($robot['id'] ?? 0)
                );
            } catch (\RuntimeException $e) {
                $failed[] = $robot['name'] ?: ('ID:' . $robot['id']);
                continue;
            }
            if ($webhook === '') {
                $failed[] = $robot['name'] ?: ('ID:' . $robot['id']);
                continue;
            }
            $result = $this->postJson($webhook, $payload);
            if (!$result['success']) {
                $failed[] = $robot['name'] ?: ('ID:' . $robot['id']);
            }
        }

        if (empty($failed)) {
            return json(['code' => 200, 'message' => '全部发送成功']);
        }
        return json(['code' => 500, 'message' => '部分失败: ' . implode('、', $failed)]);
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
