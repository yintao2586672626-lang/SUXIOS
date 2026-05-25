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
        $httpStatus = ($code >= 100 && $code <= 599) ? $code : 400;
        $result = [
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'time' => time(),
        ];

        return json($result, $httpStatus);
    }

    /**
     * 分页数据响应
     */
    protected function requestData(): array
    {
        $data = $this->request->post();

        if (empty($data) && strtoupper((string)$this->request->method()) === 'PUT') {
            $data = $this->request->put();
        }

        if (empty($data)) {
            $raw = (string)$this->request->getContent();
            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        return is_array($data) ? $data : [];
    }

    protected function validatePasswordPolicy(string $password, string $label = 'Password'): ?string
    {
        $minLength = (int)\app\model\SystemConfig::getValue(\app\model\SystemConfig::KEY_PASSWORD_MIN_LENGTH, '6');
        $minLength = max(6, $minLength);

        if (strlen($password) < $minLength) {
            return "{$label}至少{$minLength}个字符";
        }

        $requireSpecial = \app\model\SystemConfig::getValue(\app\model\SystemConfig::KEY_PASSWORD_REQUIRE_SPECIAL, '0');
        if (in_array((string)$requireSpecial, ['1', 'true', 'on', 'yes'], true) && !preg_match('/[^A-Za-z0-9]/', $password)) {
            return "{$label}必须包含特殊字符";
        }

        return null;
    }

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
        
        if (!$this->currentUser->isSuperAdmin() && empty($this->currentUser->getPermittedHotelIds())) {
            abort(403, '您未关联酒店，请联系管理员');
        }
    }
}
