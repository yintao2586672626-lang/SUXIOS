<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\CompetitorDevice;
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
        $query = CompetitorDevice::order('id', 'desc');
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
}
