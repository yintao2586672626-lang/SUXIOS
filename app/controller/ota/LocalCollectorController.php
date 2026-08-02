<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\controller\Base;
use app\service\OtaLocalCollectorService;
use RuntimeException;
use think\Response;
use Throwable;

/**
 * Account-owned local collector control plane.
 *
 * Authenticated endpoints manage devices, account mappings and tasks. Device
 * endpoints use a one-time pairing code and a token whose hash is stored by
 * the server. Browser Profile, Cookie and session storage never enter here.
 */
final class LocalCollectorController extends Base
{
    public function status(): Response
    {
        return $this->run(fn(): array => $this->service()->status($this->currentUser));
    }

    public function pairCode(): Response
    {
        return $this->run(
            fn(): array => $this->service()->createPairCode($this->currentUser, $this->requestData())
        );
    }

    public function createAccount(): Response
    {
        return $this->run(
            fn(): array => $this->service()->createAccount($this->currentUser, $this->requestData())
        );
    }

    public function bindHotel(int $accountId): Response
    {
        return $this->run(
            fn(): array => $this->service()->bindHotel($this->currentUser, $accountId, $this->requestData())
        );
    }

    public function unbindHotel(int $accountId, int $hotelId): Response
    {
        return $this->run(
            fn(): array => $this->service()->unbindHotel($this->currentUser, $accountId, $hotelId)
        );
    }

    public function createTask(): Response
    {
        return $this->run(
            fn(): array => $this->service()->createTask($this->currentUser, $this->requestData())
        );
    }

    public function revokeDevice(int $deviceId): Response
    {
        return $this->run(
            fn(): array => $this->service()->revokeDevice($this->currentUser, $deviceId)
        );
    }

    public function pair(): Response
    {
        return $this->run(fn(): array => $this->service()->pairDevice($this->requestData()));
    }

    public function heartbeat(): Response
    {
        [$deviceId, $token] = $this->deviceCredentials();

        return $this->run(
            fn(): array => $this->service()->heartbeat($deviceId, $token, $this->requestData())
        );
    }

    public function nextTask(): Response
    {
        [$deviceId, $token] = $this->deviceCredentials();

        return $this->run(fn(): array => $this->service()->nextTask($deviceId, $token));
    }

    public function progress(int $taskId): Response
    {
        [$deviceId, $token] = $this->deviceCredentials();
        $input = $this->requestData();

        return $this->run(fn(): array => $this->service()->updateTaskProgress(
            $deviceId,
            $token,
            $taskId,
            $input
        ));
    }

    public function result(int $taskId): Response
    {
        [$deviceId, $token] = $this->deviceCredentials();
        $input = $this->requestData();
        $rawBytes = strlen((string)$this->request->getContent());

        return $this->run(fn(): array => $this->service()->submitTaskResult(
            $deviceId,
            $token,
            $taskId,
            $input,
            $rawBytes
        ));
    }

    private function service(): OtaLocalCollectorService
    {
        return $this->app->make(OtaLocalCollectorService::class, [], true);
    }

    /** @return array{0: string, 1: string} */
    private function deviceCredentials(): array
    {
        $deviceId = trim((string)$this->request->header('X-Collector-Device-Id', ''));
        $authorization = trim((string)$this->request->header('Authorization', ''));
        $token = trim((string)$this->request->header('X-Collector-Token', ''));
        if ($token === '' && preg_match('/^Collector\s+(.+)$/i', $authorization, $matches) === 1) {
            $token = trim((string)$matches[1]);
        }

        return [$deviceId, $token];
    }

    private function run(callable $action): Response
    {
        try {
            return $this->success($action());
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            if ($code < 400 || $code > 599) {
                $code = 400;
            }

            return $this->error($e->getMessage(), $code);
        } catch (Throwable $e) {
            trace('ota_local_collector_controller_failed: ' . $e->getMessage(), 'error');

            return $this->error('本机采集服务暂时不可用，请稍后重试；如仍失败请联系管理员。', 500);
        }
    }
}
