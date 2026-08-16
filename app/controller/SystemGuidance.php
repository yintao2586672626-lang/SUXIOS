<?php
declare(strict_types=1);

namespace app\controller;

use app\service\SystemUsageAssistantService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class SystemGuidance extends Base
{
    private SystemUsageAssistantService $assistant;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->assistant = new SystemUsageAssistantService();
    }

    public function guide(): Response
    {
        try {
            if (!$this->currentUser) {
                throw new RuntimeException('未登录');
            }
            $input = $this->requestData();
            $input['user_id'] = max(0, (int)($this->currentUser->id ?? 0));
            return $this->success($this->assistant->guide($input), '智能引导已生成');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e), $this->status($e));
        }
    }

    private function status(Throwable $e): int
    {
        if ($e->getMessage() === '未登录') {
            return 401;
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof InvalidArgumentException || $e->getMessage() === '未登录') {
            return $e->getMessage();
        }
        return '智能引导暂不可用，请稍后重试';
    }
}
