<?php
declare(strict_types=1);
namespace app\controller;

use app\service\GuestStayFileImportService;
use app\service\HotelScopeService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class GuestStayImport extends Base
{
    public function preview(): Response { return $this->run(false); }
    public function import(): Response { return $this->run(true); }
    private function run(bool $save): Response
    {
        try {
            if (!$this->currentUser) throw new RuntimeException('未登录', 401);
            $hotel = (int)$this->request->param('hotel_id', 0);
            if (!$this->currentUser->hasHotelPermission($hotel, 'operation.execute')) throw new RuntimeException('无此酒店运营权限', 403);
            $tenant = $this->currentUser->isSuperAdmin() ? 0 : (int)$this->currentUser->tenant_id;
            if (!$this->currentUser->isSuperAdmin() && $tenant <= 0) throw new RuntimeException('租户上下文缺失', 403);
            $file = $this->request->file('file'); if (!$file) throw new InvalidArgumentException('请选择 JD06 文件');
            $payload = json_decode((string)$this->request->post('options', '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new InvalidArgumentException('导入配置无效');
            $service = new GuestStayFileImportService(); $method = $save ? 'import' : 'preview';
            $result = $service->{$method}($tenant, (new HotelScopeService())->accessibleHotelIds($this->currentUser, 'operation.execute'), $hotel, (int)$this->currentUser->id, $file->getPathname(), strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION)), $payload);
            return $this->success($result, $save ? '匿名事件已保存并精确回读' : '文件已解析；原始身份不会保存');
        } catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException || $error instanceof \JsonException ? 422 : (int)$error->getCode();
            if (!in_array($status, [401, 403, 404, 409, 422, 503], true)) return $this->error('JD06导入服务失败，请检查服务和存储后重试', 500);
            return $this->error($error instanceof \JsonException ? '导入配置JSON无效' : $error->getMessage(), $status);
        }
    }
}
