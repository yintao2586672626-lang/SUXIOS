<?php
declare(strict_types=1);

use app\middleware\Cors;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Config;
use think\Container;
use think\Request;
use think\Response;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

final class Tc387CorsOriginRestrictionL8EvalTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://console.suxios.test';
    private const MALICIOUS_ORIGIN = 'https://console.suxios.test.attacker.invalid';
    private const UNALLOWED_ORIGIN = 'https://untrusted.invalid';

    private const ORIGIN_POLICY = [
        'allowed_origins' => [self::ALLOWED_ORIGIN],
        'allow_credentials' => true,
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
    ];

    private const SCENARIOS = [
        'DX-3089' => [
            'actor_scope' => 'authorized',
            'data_completeness' => 'complete',
            'freshness' => 'fresh',
            'upstream_state' => 'success',
        ],
        'DX-3090' => [
            'actor_scope' => 'authorized',
            'data_completeness' => 'complete',
            'freshness' => 'stale',
            'upstream_state' => 'failure',
        ],
        'DX-3091' => [
            'actor_scope' => 'authorized',
            'data_completeness' => 'missing_required',
            'freshness' => 'fresh',
            'upstream_state' => 'failure',
        ],
        'DX-3092' => [
            'actor_scope' => 'authorized',
            'data_completeness' => 'missing_required',
            'freshness' => 'stale',
            'upstream_state' => 'success',
        ],
        'DX-3093' => [
            'actor_scope' => 'restricted',
            'data_completeness' => 'complete',
            'freshness' => 'fresh',
            'upstream_state' => 'failure',
        ],
        'DX-3094' => [
            'actor_scope' => 'restricted',
            'data_completeness' => 'complete',
            'freshness' => 'stale',
            'upstream_state' => 'success',
        ],
        'DX-3095' => [
            'actor_scope' => 'restricted',
            'data_completeness' => 'missing_required',
            'freshness' => 'fresh',
            'upstream_state' => 'success',
        ],
        'DX-3096' => [
            'actor_scope' => 'restricted',
            'data_completeness' => 'missing_required',
            'freshness' => 'stale',
            'upstream_state' => 'failure',
        ],
    ];

    private Container $previousContainer;
    private App $syntheticApp;
    private Config $syntheticConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->syntheticApp = new App(dirname(__DIR__, 2));
        Container::setInstance($this->syntheticApp);

        $config = new Config();
        $this->syntheticApp->instance('config', $config);
        $config->set(self::ORIGIN_POLICY, 'cors');
        $this->syntheticConfig = $config;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testDx3089AuthorizedCompleteFreshSuccess(): void
    {
        $this->runScenario('DX-3089');
    }

    public function testDx3090AuthorizedCompleteStaleFailure(): void
    {
        $this->runScenario('DX-3090');
    }

    public function testDx3091AuthorizedMissingRequiredFreshFailure(): void
    {
        $this->runScenario('DX-3091');
    }

    public function testDx3092AuthorizedMissingRequiredStaleSuccess(): void
    {
        $this->runScenario('DX-3092');
    }

    public function testDx3093RestrictedCompleteFreshFailure(): void
    {
        $this->runScenario('DX-3093');
    }

    public function testDx3094RestrictedCompleteStaleSuccess(): void
    {
        $this->runScenario('DX-3094');
    }

    public function testDx3095RestrictedMissingRequiredFreshSuccess(): void
    {
        $this->runScenario('DX-3095');
    }

    public function testDx3096RestrictedMissingRequiredStaleFailure(): void
    {
        $this->runScenario('DX-3096');
    }

    private function runScenario(string $caseId): void
    {
        $factors = self::SCENARIOS[$caseId];
        $origin = $this->originFor($factors);
        $originAllowed = $origin === self::ALLOWED_ORIGIN;
        $upstreamStatus = $factors['upstream_state'] === 'success' ? 200 : 503;
        $requestMethod = $factors['upstream_state'] === 'success' ? 'POST' : 'PUT';
        $violations = [];

        if ($this->syntheticConfig->get('cors') !== self::ORIGIN_POLICY) {
            $violations['origin-config'] = '本地合成 Origin 白名单配置未按预期安装。';
        }

        $normalNextCalls = 0;
        $normalRequest = $this->makeRequest($caseId, $requestMethod, $origin, $factors);
        $normalResponse = (new Cors())->handle(
            $normalRequest,
            function (Request $received) use (
                $caseId,
                $factors,
                $upstreamStatus,
                &$normalNextCalls
            ): Response {
                ++$normalNextCalls;

                $response = Response::create(
                    'synthetic-' . $factors['upstream_state'],
                    'html',
                    $upstreamStatus
                );
                $response->header([
                    'X-Synthetic-Request-Id' => 'tc387-' . strtolower($caseId),
                    'X-Synthetic-Freshness-Seen' => (string) $received->header('x-synthetic-freshness'),
                ]);

                return $response;
            }
        );

        if ($normalNextCalls !== 1) {
            $violations['normal-next'] = sprintf(
                '普通响应必须且只能调用一次上游，实际 %d 次。',
                $normalNextCalls
            );
        }
        if ($normalResponse->getCode() !== $upstreamStatus) {
            $violations['normal-status'] = sprintf(
                '普通响应未保留 %s 上游状态：期望 %d，实际 %d。',
                $factors['upstream_state'],
                $upstreamStatus,
                $normalResponse->getCode()
            );
        }
        if ($this->headerValue($normalResponse, 'X-Synthetic-Freshness-Seen') !== $factors['freshness']) {
            $violations['normal-freshness'] = '普通响应未保留 L8 freshness 因子。';
        }
        $this->collectCorsDecisionViolations(
            $violations,
            $normalResponse,
            $originAllowed,
            '普通响应'
        );

        $preflightNextCalls = 0;
        $preflightRequest = $this->makeRequest($caseId, 'OPTIONS', $origin, $factors);
        $preflightResponse = (new Cors())->handle(
            $preflightRequest,
            function () use (&$preflightNextCalls): Response {
                ++$preflightNextCalls;
                return Response::create('preflight-must-not-reach-upstream', 'html', 500);
            }
        );

        if ($preflightNextCalls !== 0) {
            $violations['preflight-next'] = sprintf(
                '预检请求不应调用上游，实际 %d 次。',
                $preflightNextCalls
            );
        }
        if ($originAllowed && $preflightResponse->getCode() !== 204) {
            $violations['preflight-status'] = sprintf(
                '允许来源的预检响应应为 204，实际 %d。',
                $preflightResponse->getCode()
            );
        }
        $this->collectCorsDecisionViolations(
            $violations,
            $preflightResponse,
            $originAllowed,
            '预检响应'
        );

        if ($originAllowed) {
            $allowedMethods = $this->tokenList(
                $this->headerValue($preflightResponse, 'Access-Control-Allow-Methods')
            );
            $allowedHeaders = array_map(
                'strtolower',
                $this->tokenList(
                    $this->headerValue($preflightResponse, 'Access-Control-Allow-Headers')
                )
            );

            if ($allowedMethods === ['*'] || !in_array($requestMethod, $allowedMethods, true)) {
                $violations['preflight-methods'] = sprintf(
                    '允许来源的预检方法必须明确包含 %s 且不能是 wildcard。',
                    $requestMethod
                );
            }
            foreach (['content-type', 'authorization'] as $requiredHeader) {
                if ($allowedHeaders === ['*'] || !in_array($requiredHeader, $allowedHeaders, true)) {
                    $violations['preflight-headers-' . $requiredHeader] = sprintf(
                        '允许来源的预检头必须明确包含 %s 且不能是 wildcard。',
                        $requiredHeader
                    );
                }
            }
        }

        self::assertSame(
            [],
            array_values($violations),
            sprintf(
                "%s [%s] CORS 来源限制契约违规：\n- %s",
                $caseId,
                $this->factorSignature($factors),
                implode("\n- ", array_values($violations))
            )
        );
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function originFor(array $factors): ?string
    {
        if ($factors['data_completeness'] === 'missing_required') {
            return null;
        }

        if ($factors['actor_scope'] === 'authorized') {
            return self::ALLOWED_ORIGIN;
        }

        return $factors['freshness'] === 'fresh'
            ? self::MALICIOUS_ORIGIN
            : self::UNALLOWED_ORIGIN;
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function makeRequest(
        string $caseId,
        string $method,
        ?string $origin,
        array $factors
    ): Request {
        $headers = [
            'authorization' => 'Bearer synthetic-tc387-eval-token',
            'content-type' => 'application/json',
            'x-synthetic-actor-scope' => $factors['actor_scope'],
            'x-synthetic-data-completeness' => $factors['data_completeness'],
            'x-synthetic-freshness' => $factors['freshness'],
            'x-synthetic-upstream-state' => $factors['upstream_state'],
        ];

        if ($origin !== null) {
            $headers['origin'] = $origin;
        }
        if ($method === 'OPTIONS') {
            $headers['access-control-request-method'] = $factors['upstream_state'] === 'success'
                ? 'POST'
                : 'PUT';
            $headers['access-control-request-headers'] = 'Content-Type, Authorization';
        }

        $request = new Request();
        $request->setMethod($method);
        $request->setUrl('/__eval__/tc-387/' . strtolower($caseId));
        $request->withServer([
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
        ]);
        $request->withHeader($headers);
        $request->withCookie(['suxi_eval_session' => 'synthetic-local-session']);

        return $request;
    }

    /**
     * @param array<string,string> $violations
     */
    private function collectCorsDecisionViolations(
        array &$violations,
        Response $response,
        bool $originAllowed,
        string $surface
    ): void {
        $prefix = $surface === '普通响应' ? 'normal-cors' : 'preflight-cors';
        $allowOrigin = $this->headerValue($response, 'Access-Control-Allow-Origin');
        $allowCredentials = strtolower(
            (string) $this->headerValue($response, 'Access-Control-Allow-Credentials')
        );

        if ($allowOrigin === '*') {
            $violations[$prefix . '-wildcard'] = $surface
                . ' 对敏感 API 返回 Access-Control-Allow-Origin: *。';
        }
        if ($allowOrigin === '*' && $allowCredentials === 'true') {
            $violations[$prefix . '-wildcard-credentials'] = $surface
                . ' 出现 wildcard 与 credentials=true 的禁止组合。';
        }

        if ($originAllowed) {
            if ($allowOrigin !== self::ALLOWED_ORIGIN) {
                $violations[$prefix . '-allowed-origin'] = sprintf(
                    '%s 未精确回显已配置允许来源；期望 %s，实际 %s。',
                    $surface,
                    self::ALLOWED_ORIGIN,
                    $allowOrigin ?? '<缺失>'
                );
            }
            if ($allowCredentials !== 'true') {
                $violations[$prefix . '-credentials'] = $surface
                    . ' 未对已配置允许来源显式返回 Access-Control-Allow-Credentials: true。';
            }

            return;
        }

        if ($allowOrigin !== null && $allowOrigin !== '') {
            $violations[$prefix . '-unauthorized-origin'] = sprintf(
                '%s 对未允许、恶意或缺失 Origin 返回了放行来源 %s。',
                $surface,
                $allowOrigin
            );
        }
        if ($allowCredentials === 'true') {
            $violations[$prefix . '-unauthorized-credentials'] = $surface
                . ' 对未允许、恶意或缺失 Origin 返回了 credentials=true。';
        }
    }

    private function headerValue(Response $response, string $name): ?string
    {
        foreach ($response->getHeader() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                return implode(', ', array_map('strval', $value));
            }

            return trim((string) $value);
        }

        return null;
    }

    /** @return list<string> */
    private function tokenList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $token): string => trim($token),
            explode(',', $value)
        ), static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function factorSignature(array $factors): string
    {
        return implode('/', [
            $factors['actor_scope'],
            $factors['data_completeness'],
            $factors['freshness'],
            $factors['upstream_state'],
        ]);
    }
}
