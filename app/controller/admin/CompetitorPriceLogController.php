<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\CompetitorPriceLog;
use think\Response;

class CompetitorPriceLogController extends Base
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
        $platform = $this->request->get('platform', '');
        $city = $this->request->get('city', '');
        $hotelId = $this->request->get('hotel_id', '');

        $query = CompetitorPriceLog::order('id', 'desc');
        if ($storeId !== '') $query->where('store_id', (int)$storeId);
        if ($platform !== '') $query->where('platform', $platform);
        if ($city !== '') $query->where('city', $city);
        if ($hotelId !== '') $query->where('hotel_id', (int)$hotelId);

        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }
}
