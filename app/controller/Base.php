<?php
declare(strict_types=1);

namespace app\controller;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\Response;

abstract class Base
{
    /**
     * Request实例
     */
    protected $request;

    /**
     * 应用实例
     */
    protected $app;

    /**
     * 当前用户
     */
    protected $currentUser = null;

    /**
     * 构造方法
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;

        // 获取当前登录用户
        $this->currentUser = $this->request->user ?? null;
    }

    /**
     * 成功响应
     */
    protected function success($data = null, string $message = '操作成功', int $code = 200): Response
    {
        $result = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'time' => time(),
        ];

        return json($result);
    }

    /**
     * 失败响应
     */
    protected function error(string $message = '操作失败', int $code = 400, $data = null): Response
    {
        $result = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'time' => time(),
        ];

        return json($result);
    }

    /**
     * 分页数据响应
     */
    protected function paginate($list, int $total, int $page, int $pageSize): Response
    {
        return $this->success([
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => ceil($total / $pageSize),
            ],
        ]);
    }

    /**
     * 验证数据
     */
    protected function validate(array $data, array $rules, array $message = [])
    {
        $validate = new Validate();
        $validate->rule($rules)->message($message);

        if (!$validate->check($data)) {
            throw new ValidateException($validate->getError());
        }
    }

    /**
     * 获取分页参数
     */
    protected function getPagination(): array
    {
        $page = (int) $this->request->param('page', 1);
        $pageSize = (int) $this->request->param('page_size', 10);

        return [
            'page' => max(1, $page),
            'page_size' => min(100, max(1, $pageSize)),
        ];
    }

    /**
     * 检查用户是否有酒店关联（非超级管理员必须有）
     */
    protected function requireHotel(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        
        if (!$this->currentUser->isSuperAdmin() && empty($this->currentUser->hotel_id)) {
            abort(403, '您未关联酒店，请联系管理员');
        }
    }
}
