<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
use think\Response;
use think\facade\Db;

class CompetitorWechatRobotController extends Base
{
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
        $list = $query->select()->toArray();

        return view('competitor_wechat_robot/index', [
            'list' => $list,
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
            'robot' => $robot,
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

        $insert = [
            'store_id' => (int)$data['store_id'],
            'name' => $data['name'],
            'webhook' => $data['webhook'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        Db::name('competitor_wechat_robot')->insert($insert);
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
        $update = [
            'store_id' => (int)($data['store_id'] ?? $robot['store_id']),
            'name' => $data['name'] ?? $robot['name'],
            'webhook' => $data['webhook'] ?? $robot['webhook'],
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
        if (empty($robot['webhook'])) {
            return json(['code' => 400, 'message' => 'Webhook为空']);
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '【测试】竞对价格监控发送正常',
            ],
        ];
        $result = $this->postJson((string)$robot['webhook'], $payload);
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
            if (empty($robot['webhook'])) {
                $failed[] = $robot['name'] ?: ('ID:' . $robot['id']);
                continue;
            }
            $result = $this->postJson((string)$robot['webhook'], $payload);
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
        $list = $query->page($pagination['page'], $pagination['page_size'])->select()->toArray();
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
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
        $insert = [
            'store_id' => (int)$data['store_id'],
            'name' => $data['name'],
            'webhook' => $data['webhook'],
            'status' => isset($data['status']) ? (int)$data['status'] : 1,
            'create_time' => date('Y-m-d H:i:s'),
        ];
        Db::name('competitor_wechat_robot')->insert($insert);
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
        $update = [
            'store_id' => (int)($data['store_id'] ?? $robot['store_id']),
            'name' => $data['name'] ?? $robot['name'],
            'webhook' => $data['webhook'] ?? $robot['webhook'],
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

    private function postJson(string $url, array $data): array
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            return ['success' => false, 'error' => $error['message'] ?? '请求失败'];
        }
        return ['success' => true, 'data' => $response];
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
