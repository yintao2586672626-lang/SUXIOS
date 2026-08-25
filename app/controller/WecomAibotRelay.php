<?php
declare(strict_types=1);

namespace app\controller;

use app\service\WecomAibotService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

/** Loopback-only relay used by the official WeCom AI Bot WebSocket worker. */
final class WecomAibotRelay extends Base
{
    public function ingest(): Response
    {
        try {
            $this->assertTrustedRelay();
            return $this->success((new WecomAibotService())->ingest($this->requestData()));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信智能机器人事件处理失败'), $this->status($e));
        }
    }

    public function delivery(int $id): Response
    {
        try {
            $this->assertTrustedRelay();
            $input = $this->requestData();
            return $this->success((new WecomAibotService())->recordDelivery(
                $id,
                (string)($input['status'] ?? ''),
                (string)($input['reference'] ?? '')
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '企业微信智能机器人回执保存失败'), $this->status($e));
        }
    }

    private function assertTrustedRelay(): void
    {
        $expected = trim((string)env('SUXIOS_WECOM_AIBOT_RELAY_TOKEN', ''));
        $provided = trim((string)$this->request->header('X-SUXIOS-Relay-Token', ''));
        $ip = trim((string)$this->request->ip());
        if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
            throw new RuntimeException('企业微信智能机器人中继仅允许本机访问', 403);
        }
        if (strlen($expected) < 32 || strlen($provided) < 32 || !hash_equals($expected, $provided)) {
            throw new RuntimeException('企业微信智能机器人中继认证失败', 403);
        }
    }

    private function status(Throwable $e): int
    {
        if (in_array((int)$e->getCode(), [403, 404, 409, 422, 503], true)) {
            return (int)$e->getCode();
        }
        return $e instanceof InvalidArgumentException ? 422 : 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        return $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : $fallback;
    }
}
