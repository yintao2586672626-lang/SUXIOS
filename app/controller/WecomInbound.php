<?php
declare(strict_types=1);

namespace app\controller;

use app\service\WecomInboundService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

/** Public encrypted callback only. No user-supplied verification flags are accepted. */
final class WecomInbound extends Base
{
    public function verify(string $bindingKey): Response
    {
        try {
            $plain = (new WecomInboundService())->verifyCallbackUrl(
                $bindingKey,
                (string)$this->request->get('timestamp', ''),
                (string)$this->request->get('nonce', ''),
                (string)$this->request->get('msg_signature', ''),
                (string)$this->request->get('echostr', '')
            );
            return response($plain, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (Throwable $e) {
            return response('callback verification failed', $this->status($e), ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    public function callback(string $bindingKey): Response
    {
        try {
            (new WecomInboundService())->handleCallback(
                $bindingKey,
                (string)$this->request->get('timestamp', ''),
                (string)$this->request->get('nonce', ''),
                (string)$this->request->get('msg_signature', ''),
                (string)$this->request->getContent()
            );
            return response('success', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        } catch (Throwable $e) {
            return response('callback rejected', $this->status($e), ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    private function status(Throwable $e): int
    {
        if (in_array((int)$e->getCode(), [403, 404, 409, 503], true)) {
            return (int)$e->getCode();
        }
        return $e instanceof InvalidArgumentException ? 422 : ($e instanceof RuntimeException ? 403 : 500);
    }
}
