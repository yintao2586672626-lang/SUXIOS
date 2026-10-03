<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Auth;
use app\model\User;
use app\service\ProtectedCapabilityService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class AuthMiddlewareAuditTest extends TestCase
{
    use ReflectionHelper;

    public function testSanitizeAuditParamsMasksSensitiveNestedValuesAndTruncatesLongText(): void
    {
        $safe = $this->invokeNonPublic(new Auth(), 'sanitizeAuditParams', [[
            'token' => 'secret-token',
            'hotel_id' => 12,
            'nested' => [
                'password' => 'secret-password',
                'normal' => str_repeat('a', 130),
            ],
            'payload' => (object)['a' => 1],
        ]]);

        self::assertSame('***', $safe['token']);
        self::assertSame(12, $safe['hotel_id']);
        self::assertSame('***', $safe['nested']['password']);
        self::assertSame(str_repeat('a', 120) . '...', $safe['nested']['normal']);
        self::assertSame('[object]', $safe['payload']);
    }

    public function testResolveAuditHotelIdPrefersRequestHotelThenFallsBackToUserHotel(): void
    {
        $middleware = new Auth();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();
        $user->method('__isset')->with('hotel_id')->willReturn(true);
        $user->method('__get')->with('hotel_id')->willReturn(7);

        self::assertSame(15, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [['hotel_id' => '15'], $user]));
        self::assertSame(16, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [['system_hotel_id' => '16', 'hotel_id' => '15'], $user]));
        self::assertSame(7, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [[], $user]));
    }

    public function testRateLimitTenantIgnoresClientSuppliedTenantAndHotelIds(): void
    {
        $middleware = new Auth();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['tenant_id', 'hotel_id'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key): ?int => match ($key) {
                'tenant_id' => 7,
                'hotel_id' => 12,
                default => null,
            }
        );

        $tenantId = $this->invokeNonPublic($middleware, 'resolveTenantIdForRateLimit', [[
            'tenant_id' => 999,
            'system_hotel_id' => 998,
            'hotel_id' => 997,
        ], $user]);

        self::assertSame(7, $tenantId);
    }

    public function testRateLimitPolicyUsesStricterExportAndWriteBuckets(): void
    {
        $middleware = new Auth();

        $export = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', ['GET', '/api/daily-reports/export?hotel_id=7']);
        self::assertSame('export', $export['scope']);
        self::assertSame(10, $export['limit']);
        self::assertSame(3600, $export['window']);

        $write = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', ['POST', '/api/online-data/save-daily-data']);
        self::assertSame('write', $write['scope']);
        self::assertSame(60, $write['limit']);
        self::assertSame(60, $write['window']);
    }

    public function testProtectedRateLimitPolicyUsesCapabilityQuota(): void
    {
        $middleware = new Auth();
        $protected = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', [
            'POST',
            '/api/agent/ota-diagnosis',
            [
                'key' => 'ai_decision',
                'rate_limit' => [
                    'scope' => 'protected_ai_decision',
                    'limit' => 30,
                    'window' => 3600,
                ],
            ],
        ]);

        self::assertSame('protected_ai_decision', $protected['scope']);
        self::assertSame('api/agent/ota-diagnosis', $protected['path']);
        self::assertSame(30, $protected['limit']);
        self::assertSame(3600, $protected['window']);
        self::assertSame('ai_decision', $protected['capability']);
    }

    public function testManualOtaFetchUsesDedicatedBatchQuota(): void
    {
        $middleware = new Auth();
        $capability = [
            'key' => 'online_data',
            'rate_limit' => [
                'scope' => 'protected_online_data',
                'limit' => 60,
                'window' => 3600,
            ],
        ];

        foreach (['fetch-ctrip', 'fetch-meituan'] as $endpoint) {
            $policy = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', [
                'POST',
                '/api/online-data/' . $endpoint,
                $capability,
            ]);

            self::assertSame('protected_ota_manual_fetch', $policy['scope']);
            self::assertSame(600, $policy['limit']);
            self::assertSame(3600, $policy['window']);
            self::assertSame('online_data', $policy['capability']);
        }
    }

    public function testCollectionProgressUsesOrdinaryReadQuotaWithoutChangingDiagnosticQuota(): void
    {
        $middleware = new Auth();
        $capability = ['key'=>'collection_health','rate_limit'=>['scope'=>'protected_collection_health','limit'=>60,'window'=>3600]];
        $progress = $this->invokeNonPublic($middleware,'resolveRateLimitPolicy',['GET','/api/online-data/auto-fetch-status?hotel_id=7&include_detail=0',$capability]);
        $ordinary = $this->invokeNonPublic($middleware,'resolveRateLimitPolicy',['GET','/api/hotels',null]);
        self::assertSame($ordinary['limit'],$progress['limit']);
        self::assertSame($ordinary['window'],$progress['window']);
        self::assertGreaterThan($progress['window'] / 2,$progress['limit'], 'The existing two-second poll must fit in a read window.');
        $diagnostic = $this->invokeNonPublic($middleware,'resolveRateLimitPolicy',['GET','/api/online-data/collection-reliability',$capability]);
        self::assertSame(60,$diagnostic['limit']);
        self::assertSame(3600,$diagnostic['window']);
    }

    public function testRateLimitCacheKeyIncludesTenantUserIpEndpointAndWindowNamespace(): void
    {
        $key = $this->invokeNonPublic(new Auth(), 'buildRateLimitCacheKey', [
            7,
            42,
            '127.0.0.1',
            'protected_ai_decision',
            'POST',
            'api/agent/ota-diagnosis',
        ]);

        self::assertStringContainsString('tenant_7_user_42_ip_', $key);
        self::assertStringContainsString('scope_protected_ai_decision', $key);
        self::assertStringContainsString('endpoint_', $key);
        self::assertStringEndsWith('_window', $key);
    }

    public function testCollectionReadAliasesKeepCanonicalPoliciesAndCacheBuckets(): void
    {
        $this->assertRateLimitAliases([
            ['GET', '/api/online-data/auto-fetch-status', 'collection_status_read', 180, 60, true],
            ['GET', '/api/online-data/collection-reliability', 'protected_collection_health', 60, 3600, true],
            ['GET', '/api/daily-reports/export', 'protected_export', 10, 3600, true],
        ]);
    }

    public function testProtectedWriteAliasesKeepCanonicalPoliciesAndCacheBuckets(): void
    {
        $this->assertRateLimitAliases([
            ['POST', '/api/online-data/fetch-ctrip', 'protected_ota_manual_fetch', 600, 3600, true],
            ['POST', '/api/online-data/fetch-meituan', 'protected_ota_manual_fetch', 600, 3600, true],
            ['POST', '/api/agent/ota-diagnosis', 'protected_ai_decision', 30, 3600, true],
        ]);
    }

    public function testUnrelatedReadLoginAndWriteAliasesRetainOriginalPolicies(): void
    {
        $this->assertRateLimitAliases([
            ['GET', '/api/hotels', 'read', 180, 60, false],
            ['POST', '/api/auth/login', 'write', 60, 60, false],
            ['POST', '/api/online-data/save-daily-data', 'write', 60, 60, false],
        ]);
    }

    private function assertRateLimitAliases(array $cases): void
    {
        $originalRoute = config('route', []);
        app()->config->set(['url_html_suffix' => 'html'], 'route');
        try {
            $middleware = new Auth();
            $service = new ProtectedCapabilityService(ProtectedCapabilityService::defaultPolicy());
            foreach ($cases as [$method, $path, $scope, $limit, $window, $protected]) {
                $capability = $service->classifyPath($method, $path);
                if ($protected) self::assertIsArray($capability);
                else self::assertNull($capability);
                $canonical = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', [$method, $path, $capability]);
                self::assertSame($scope, $canonical['scope']);
                self::assertSame($limit, $canonical['limit']);
                self::assertSame($window, $canonical['window']);
                $canonicalKey = $this->invokeNonPublic($middleware, 'buildRateLimitCacheKey', [7, 42, '127.0.0.1', $scope, $method, $canonical['path']]);
                foreach (['', '?hotel_id=7', '.HTML', '.HTML?hotel_id=7', '.html?hotel_id=7&include_detail=0'] as $suffix) {
                    $uri = $path . $suffix;
                    $aliasCapability = $service->classifyPath($method, $uri);
                    self::assertSame($capability, $aliasCapability, $uri);
                    $alias = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', [$method, $uri, $aliasCapability]);
                    self::assertSame($canonical, $alias, $uri);
                    self::assertSame($canonicalKey, $this->invokeNonPublic($middleware, 'buildRateLimitCacheKey', [7, 42, '127.0.0.1', $scope, $method, $alias['path']]), $uri);
                }
                $unsupportedPath = ltrim($path . '.json', '/');
                self::assertSame($unsupportedPath, $service->normalizePath($path . '.json'), 'An unsupported suffix must retain its original path.');
                $unsupportedCapability = $service->classifyPath($method, $path . '.json');
                if ($unsupportedCapability !== null) self::assertSame($unsupportedPath, $unsupportedCapability['path']);
                else self::assertNull($unsupportedCapability);
            }
        } finally {
            app()->config->set($originalRoute, 'route');
        }
    }
}
