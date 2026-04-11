<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\CompetitorHotel;
use app\model\OperationLog;
use think\Response;
use think\facade\Db;

class CompetitorHotelController extends Base
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
        $status = $this->request->get('status', '');

        $query = CompetitorHotel::order('id', 'desc');
        if ($storeId !== '') {
            $query->where('store_id', (int)$storeId);
        }
        if ($platform !== '') {
            $query->where('platform', $platform);
        }
        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 门店列表（使用已有 hotels 表）
     */
    public function stores(): Response
    {
        $this->checkSuperAdmin();
        $stores = Db::name('hotels')->field('id,name')->order('id', 'asc')->select()->toArray();
        return $this->success($stores);
    }

    public function create(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->request->post();

        $this->validate($data, [
            'store_id' => 'require|integer',
            'platform' => 'require|in:mt,xc',
            'city' => 'require',
            'hotel_name' => 'require',
        ], [
            'store_id.require' => '请输入门店ID',
            'platform.require' => '请选择平台',
            'city.require' => '请输入城市',
            'hotel_name.require' => '请输入酒店名称',
        ]);

        $hotel = new CompetitorHotel();
        $hotel->store_id = (int)$data['store_id'];
        $hotel->platform = $data['platform'];
        $hotel->city = $data['city'];
        $hotel->hotel_name = $data['hotel_name'];
        $hotel->hotel_code = $data['hotel_code'] ?? '';
        $hotel->status = isset($data['status']) ? (int)$data['status'] : 1;
        $hotel->save();

        OperationLog::record('competitor', 'create', '新增竞对酒店: ' . $hotel->hotel_name, $this->currentUser->id);
        return $this->success($hotel, '新增成功');
    }

    public function update(int $id): Response
    {
        $this->checkSuperAdmin();
        $hotel = CompetitorHotel::find($id);
        if (!$hotel) {
            return $this->error('记录不存在');
        }

        $data = $this->request->post();
        if (isset($data['store_id'])) $hotel->store_id = (int)$data['store_id'];
        if (isset($data['platform'])) $hotel->platform = $data['platform'];
        if (isset($data['city'])) $hotel->city = $data['city'];
        if (isset($data['hotel_name'])) $hotel->hotel_name = $data['hotel_name'];
        if (isset($data['hotel_code'])) $hotel->hotel_code = $data['hotel_code'];
        if (isset($data['status'])) $hotel->status = (int)$data['status'];
        $hotel->save();

        OperationLog::record('competitor', 'update', '更新竞对酒店: ' . $hotel->hotel_name, $this->currentUser->id);
        return $this->success($hotel, '更新成功');
    }

    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();
        $hotel = CompetitorHotel::find($id);
        if (!$hotel) {
            return $this->error('记录不存在');
        }
        $name = $hotel->hotel_name;
        $hotel->delete();

        OperationLog::record('competitor', 'delete', '删除竞对酒店: ' . $name, $this->currentUser->id);
        return $this->success(null, '删除成功');
    }
}
