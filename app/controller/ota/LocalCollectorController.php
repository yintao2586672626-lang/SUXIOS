<?php
declare(strict_types=1);

namespace app\controller\ota;

use app\controller\Base;
use app\service\FixedWindowRateLimiter;
use app\service\OtaLocalCollectorService;
use RuntimeException;
use think\facade\Log;
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
    private const PAIR_BODY_LIMIT_BYTES = 16_384;
    private const DEVICE_BODY_LIMIT_BYTES = 65_536;
    private const DEVICE_ENDPOINT_RATE_LIMITS = [
        'pair' => ['limit' => 10, 'window' => 60],
        'heartbeat' => ['limit' => 300, 'window' => 60],
        'next_task' => ['limit' => 300, 'window' => 60],
        'progress' => ['limit' => 600, 'window' => 60],
        'result' => ['limit' => 120, 'window' => 60],
    ];

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
        return $this->runDeviceEndpoint('pair', function (): array {
            $this->assertRequestBodyWithinLimit(self::PAIR_BODY_LIMIT_BYTES);

            return $this->service()->pairDevice($this->requestData());
        });
    }

    public function heartbeat(): Response
    {
        return $this->runDeviceEndpoint('heartbeat', function (): array {
            $this->assertRequestBodyWithinLimit(self::DEVICE_BODY_LIMIT_BYTES);
            [$deviceId, $token] = $this->deviceCredentials();

            return $this->service()->heartbeat($deviceId, $token, $this->requestData());
        });
    }

    public function nextTask(): Response
    {
        return $this->runDeviceEndpoint('next_task', function (): array {
            [$deviceId, $token] = $this->deviceCredentials();

            return $this->service()->nextTask($deviceId, $token);
        });
    }

    public function progress(int $taskId): Response
    {
        return $this->runDeviceEndpoint('progress', function () use ($taskId): array {
            $this->assertRequestBodyWithinLimit(self::DEVICE_BODY_LIMIT_BYTES);
            [$deviceId, $token] = $this->deviceCredentials();
            $input = $this->requestData();

            return $this->service()->updateTaskProgress($deviceId, $token, $taskId, $input);
        });
    }

    public function result(int $taskId): Response
    {
        return $this->runDeviceEndpoint('result', function () use ($taskId): array {
            $this->assertRequestBodyWithinLimit(OtaLocalCollectorService::MAX_RESULT_BYTES);
            [$deviceId, $token] = $this->deviceCredentials();
            $rawBytes = strlen((string)$this->request->getContent());
            $input = $this->requestData();

            return $this->service()->submitTaskResult(
                $deviceId,
                $token,
                $taskId,
                $input,
                $rawBytes
            );
        });
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
        $headerToken = trim((string)$this->request->header('X-Collector-Token', ''));
        $authorizationToken = '';
        if ($authorization !== '') {
            if (preg_match('/^Collector\s+(.+)$/i', $authorization, $matches) !== 1) {
                throw new RuntimeException('采集设备认证信息无效。', 401);
            }
            $authorizationToken = trim((string)$matches[1]);
            if ($authorizationToken === '') {
                throw new RuntimeException('采集设备认证信息无效。', 401);
            }
        }
        if ($headerToken !== ''
            && $authorizationToken !== ''
            && !hash_equals($headerToken, $authorizationToken)
        ) {
            throw new RuntimeException('采集设备认证信息无效。', 401);
        }
        $token = $headerToken !== '' ? $headerToken : $authorizationToken;

        return [$deviceId, $token];
    }

    private function assertRequestBodyWithinLimit(int $limit): void
    {
        $contentLength = trim((string)$this->request->header('Content-Length', ''));
        if ($contentLength !== ''
            && (!ctype_digit($contentLength) || (int)$contentLength > $limit)
        ) {
            throw new RuntimeException('采集请求体超过允许大小。', 413);
        }
        if (strlen((string)$this->request->getContent()) > $limit) {
            throw new RuntimeException('采集请求体超过允许大小。', 413);
        }
    }

    private function runDeviceEndpoint(string $scope, callable $action): Response
    {
        $config = self::DEVICE_ENDPOINT_RATE_LIMITS[$scope] ?? null;
        if (!is_array($config)) {
            return $this->error('本机采集服务暂时不可用，请稍后重试。', 500);
        }

        $rateLimitResponse = $this->enforceDeviceEndpointRateLimit(
            $scope,
            (int)$config['limit'],
            (int)$config['window']
        );
        if ($rateLimitResponse instanceof Response) {
            return $rateLimitResponse;
        }

        return $this->run($action);
    }

    private function enforceDeviceEndpointRateLimit(string $scope, int $limit, int $window): ?Response
    {
        $safeScope = (string)preg_replace('/[^a-z0-9_]/i', '_', $scope);
        $ipHash = substr(hash('sha256', (string)$this->request->ip()), 0, 16);
        $key = sprintf('ota_local_collector_rate_%s_%s', $safeScope, $ipHash);

        try {
            $rateLimit = $this->fixedWindowRateLimiter()->consume($key, $limit, $window);
        } catch (Throwable $exception) {
            Log::error('OTA local collector rate limiter unavailable.', [
                'exception_type' => get_debug_type($exception),
                'scope' => $safeScope,
                'ip_hash' => $ipHash,
            ]);

            return $this->error('限流服务暂不可用，请稍后重试。', 503, [
                'reason' => 'rate_limiter_unavailable',
                'retry_after' => 1,
            ])->header(['Retry-After' => '1']);
        }

        if (($rateLimit['allowed'] ?? false) === true) {
            return null;
        }

        Log::warning('OTA local collector public endpoint rate limited.', [
            'scope' => $safeScope,
            'limit' => $limit,
            'window' => $window,
            'ip_hash' => $ipHash,
        ]);

        $retryAfter = max(1, (int)($rateLimit['retry_after'] ?? 1));
        return $this->error('请求过于频繁，请稍后再试。', 429, [
            'reason' => 'rate_limited',
            'retry_after' => $retryAfter,
            'limit' => $limit,
            'window' => $window,
        ])->header(['Retry-After' => (string)$retryAfter]);
    }

    protected function fixedWindowRateLimiter(): FixedWindowRateLimiter
    {
        return new FixedWindowRateLimiter();
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
            Log::error('OTA local collector controller failed.', [
                'exception_type' => get_debug_type($e),
            ]);

            return $this->error('本机采集服务暂时不可用，请稍后重试；如仍失败请联系管理员。', 500);
        }
    }
}
