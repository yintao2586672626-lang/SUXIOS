<?php
declare(strict_types=1);
namespace Tests;
use app\service\FailureEvidenceService as Evidence;
use app\service\OtaUpstreamFailureService as Upstream;
use PHPUnit\Framework\TestCase;

final class FailureEvidenceServiceTest extends TestCase
{
    public function testResponseAuditKeepsOnlyKnownCodesAndSafeStatus(): void
    {
        $safe = Evidence::fromResponse(['data' => ['reason' => 'missing_resource_id', 'stage' => 'request_validation',
            'http_code' => 403, 'save_status' => 'blocked', 'raw_response' => 'synthetic-secret', 'business_message' => 'synthetic-secret']]);
        self::assertSame(['reason_code' => 'missing_resource_id', 'failure_stage' => 'request_validation', 'upstream_http_status' => 403, 'save_status' => 'blocked'], $safe);
        $unknown = Evidence::fromResponse(['data' => ['reason' => 'synthetic_secret_identifier', 'stage' => 'synthetic_secret_identifier', 'http_code' => 'secret']]);
        self::assertSame(['reason_code' => 'unclassified_response_failure', 'failure_stage' => 'response'], $unknown);
    }

    public function testExceptionEvidenceDistinguishesMigrationAndDatabaseWithoutMessageLeak(): void
    {
        $migration = Evidence::fromException(new \RuntimeException('Legacy Meituan plaintext credential requires Task6 migration; normal save cannot read or migrate it.'), 'validate_existing_metadata');
        self::assertSame('config_legacy_migration_required', $migration['reason']);
        self::assertSame(409, $migration['status']);
        $failure = Evidence::fromException(new \PDOException('synthetic SQL password=not-real'), 'metadata_write');
        self::assertSame('database_unavailable', $failure['reason']);
        self::assertStringNotContainsString('not-real', json_encode($failure));
        self::assertSame('config_save_failed', Evidence::fromException(new \RuntimeException('synthetic-secret'))['reason']);
        $pdo = new \PDOException('synthetic-private');
        $pdo->errorInfo = ['42S22', 1054, 'synthetic-private-column'];
        $wrapped = new \think\db\exception\PDOException($pdo, [], 'synthetic-private-sql');
        $schema = Evidence::fromException($wrapped, 'notification_user_state');
        self::assertSame('database_schema_unavailable', $schema['reason']);
        self::assertStringNotContainsString('synthetic-private', json_encode($schema));
    }

    public function testRedirectForbiddenHtmlAndRateLimitDoNotClaimExpiredLogin(): void
    {
        foreach ([302 => 'upstream_redirect', 307 => 'upstream_redirect', 401 => 'upstream_unauthorized', 403 => 'upstream_forbidden', 429 => 'upstream_rate_limited', 503 => 'upstream_http_error'] as $status => $reason) {
            $failure = Upstream::httpFailure($status);
            self::assertSame($reason, $failure['reason']);
            self::assertFalse(Upstream::explicitlyRequiresLogin($failure['error']));
            self::assertArrayNotHasKey('raw', $failure);
        }
        self::assertNull(Upstream::httpFailure(200));
        self::assertSame('upstream_html', Upstream::httpFailure(200, true)['reason']);
        foreach (['login required field is missing', 'forbidden', 'HTTP 302', '返回 HTML', '权限不足', '检查登录状态'] as $text) self::assertFalse(Upstream::explicitlyRequiresLogin($text));
        foreach (['Cookie已失效，请重新登录携程', '登录已过期', 'login_required', 'not logged in'] as $text) self::assertTrue(Upstream::explicitlyRequiresLogin($text));
        self::assertSame('meituan_api_error', Upstream::meituanBusinessFailure(403, 'forbidden')['reason']);
        self::assertSame('meituan_api_error', Upstream::meituanBusinessFailure(303, 'required field')['reason']);
        self::assertSame('login_required', Upstream::meituanBusinessFailure(303, '尚未登录')['reason']);
        self::assertTrue(Upstream::explicitlyRequiresLogin(Upstream::meituanBusinessFailure(303, '尚未登录')['error']));
    }
}
