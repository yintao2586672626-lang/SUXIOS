<?php
declare(strict_types=1);
namespace app\controller;

use app\service\GuestPublicFeedbackService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

/** Public capability endpoints: no record lookup, account access, or staff operation is exposed. */
final class GuestFeedback extends Base
{
    public function entry(): Response { return $this->run(false); }
    public function submit(): Response { return $this->run(true); }
    private function run(bool $submit): Response
    {
        try {
            $input = $this->requestData(); if (!is_string($input['token'] ?? null)) throw new InvalidArgumentException('反馈入口标识无效'); $token = $input['token']; unset($input['token']);
            if (!$submit && $input !== []) throw new InvalidArgumentException('入口请求字段无效');
            $service = new GuestPublicFeedbackService();
            return $this->success($submit ? $service->submit($token, (string)$this->request->server('REMOTE_ADDR', ''), $input) : $service->entry($token), $submit ? '反馈已收取' : '入口可用')->header(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException ? 422 : (int)$error->getCode();
            if (!in_array($status, [404, 409, 422, 429, 503], true)) return $this->error('反馈服务暂不可用，请联系前台', 503);
            return $this->error($error->getMessage(), $status)->header(['Cache-Control' => 'no-store']);
        }
    }
}
