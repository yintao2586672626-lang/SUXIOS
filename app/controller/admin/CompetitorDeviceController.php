<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\CompetitorDevice;
use app\model\CompetitorHotel;
use app\model\OperationLog;
use app\service\CompetitorDeviceAuthService;
use think\Response;

class CompetitorDeviceController extends Base
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
        $query = CompetitorDevice::field(
            'id,tenant_id,user_id,store_id,device_id,name,platform,status,last_time,token_hint,token_version,revoked_at,create_time'
        )->order('id', 'desc');
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select()->toArray();

        $now = time();
        foreach ($list as &$item) {
            $lastTime = isset($item['last_time']) ? strtotime((string)$item['last_time']) : 0;
            $item['is_online'] = $lastTime > 0 && ($now - $lastTime) <= 600;
        }

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    public function create(): Response
    {
        $this->checkSuperAdmin();
        $deviceId = trim((string)$this->request->post('device_id', ''));
        $name = trim((string)$this->request->post('name', $deviceId));
        $platform = trim((string)$this->request->post('platform', ''));
        $storeId = (int)$this->request->post('store_id', 0);
        $userId = (int)$this->request->post('user_id', 0);
        if (preg_match('/^[A-Za-z0-9._:-]{3,120}$/D', $deviceId) !== 1) {
            return $this->error('device_id格式无效', 422);
        }
        if (!in_array($platform, CompetitorHotel::platformCodes(), true)) {
            return $this->error('平台不受支持', 422);
        }

        $auth = new CompetitorDeviceAuthService();
        $scope = $auth->resolveActiveScope($userId, $storeId);
        if ($scope === null) {
            return $this->error('用户无该门店采集权限或门店未启用', 403);
        }
        if (CompetitorDevice::where('device_id', $deviceId)
            ->where('platform', $platform)
            ->where('store_id', $storeId)
            ->find()
        ) {
            return $this->error('该设备、平台和门店绑定已存在', 409);
        }

        $credential = $auth->issueCredential();
        $binding = new CompetitorDevice();
        $binding->tenant_id = $scope['tenant_id'];
        $binding->user_id = $scope['user_id'];
        $binding->store_id = $scope['store_id'];
        $binding->device_id = $deviceId;
        $binding->name = $name !== '' ? mb_substr($name, 0, 120) : $deviceId;
        $binding->platform = $platform;
        $binding->token_hash = $credential['hash'];
        $binding->token_hint = $credential['hint'];
        $binding->token_version = 1;
        $binding->status = 1;
        $binding->revoked_at = null;
        $binding->save();

        OperationLog::record('competitor_device', 'create', '创建竞对采集设备绑定', $this->currentUser->id, $storeId, null, [
            'binding_id' => (int)$binding->id,
            'device_id' => $deviceId,
            'platform' => $platform,
            'store_id' => $storeId,
            'bound_user_id' => $userId,
        ]);

        return $this->oneTimeCredentialResponse($binding, $credential['token'], '设备绑定已创建，请立即保存一次性Token');
    }

    public function rotateToken(int $id): Response
    {
        $this->checkSuperAdmin();
        $binding = CompetitorDevice::find($id);
        if (!$binding) {
            return $this->error('设备绑定不存在', 404);
        }

        $auth = new CompetitorDeviceAuthService();
        $credential = $auth->issueCredential();
        $binding->token_hash = $credential['hash'];
        $binding->token_hint = $credential['hint'];
        $binding->token_version = max(1, (int)($binding->token_version ?? 1)) + 1;
        $binding->save();

        OperationLog::record('competitor_device', 'rotate_token', '轮换竞对采集设备Token', $this->currentUser->id, (int)$binding->store_id, null, [
            'binding_id' => (int)$binding->id,
            'device_id' => (string)$binding->device_id,
            'platform' => (string)$binding->platform,
            'store_id' => (int)$binding->store_id,
            'token_version' => (int)$binding->token_version,
        ]);

        return $this->oneTimeCredentialResponse($binding, $credential['token'], 'Token已轮换，请立即保存新Token');
    }

    public function updateStatus(int $id): Response
    {
        $this->checkSuperAdmin();
        $binding = CompetitorDevice::find($id);
        if (!$binding) {
            return $this->error('设备绑定不存在', 404);
        }
        $status = (int)$this->request->post('status', -1);
        if (!in_array($status, [0, 1], true)) {
            return $this->error('status必须为0或1', 422);
        }
        if ($status === 1) {
            if (trim((string)($binding->token_hash ?? '')) === '') {
                return $this->error('启用前必须先轮换Token', 422);
            }
            $binding->status = 1;
            $binding->revoked_at = null;
            if (!(new CompetitorDeviceAuthService())->bindingScopeIsActive($binding)) {
                return $this->error('绑定用户已无该门店采集权限或门店未启用', 403);
            }
        } else {
            $binding->status = 0;
            $binding->revoked_at = date('Y-m-d H:i:s');
        }
        $binding->token_version = max(1, (int)($binding->token_version ?? 1)) + 1;
        $binding->save();

        OperationLog::record('competitor_device', 'status', $status === 1 ? '启用竞对采集设备绑定' : '停用竞对采集设备绑定', $this->currentUser->id, (int)$binding->store_id, null, [
            'binding_id' => (int)$binding->id,
            'device_id' => (string)$binding->device_id,
            'platform' => (string)$binding->platform,
            'store_id' => (int)$binding->store_id,
            'status' => $status,
            'token_version' => (int)$binding->token_version,
        ]);

        return $this->success($this->publicBinding($binding), $status === 1 ? '设备绑定已启用' : '设备绑定已停用');
    }

    private function oneTimeCredentialResponse(CompetitorDevice $binding, string $token, string $message): Response
    {
        $payload = $this->publicBinding($binding);
        $payload['device_token'] = $token;
        $payload['token_visible_once'] = true;

        return $this->success($payload, $message)->header([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /** @return array<string, mixed> */
    private function publicBinding(CompetitorDevice $binding): array
    {
        return [
            'id' => (int)$binding->id,
            'tenant_id' => (int)$binding->tenant_id,
            'user_id' => (int)$binding->user_id,
            'store_id' => (int)$binding->store_id,
            'device_id' => (string)$binding->device_id,
            'name' => (string)$binding->name,
            'platform' => (string)$binding->platform,
            'status' => (int)$binding->status,
            'token_hint' => (string)$binding->token_hint,
            'token_version' => (int)$binding->token_version,
            'revoked_at' => $binding->revoked_at,
            'last_time' => $binding->last_time,
        ];
    }
}
