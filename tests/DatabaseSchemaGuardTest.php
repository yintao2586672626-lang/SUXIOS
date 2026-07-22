<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\DatabaseSchemaGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Request;
use think\Response;

final class DatabaseSchemaGuardTest extends TestCase
{
    public function testReadyStatusIsCachedInsideTheGuardInstance(): void
    {
        $resolutions = 0;
        $nextCalls = 0;
        $guard = new DatabaseSchemaGuard(static function () use (&$resolutions): array {
            $resolutions++;
            return ['ready' => true];
        }, 5.0);
        $next = static function (Request $request) use (&$nextCalls): Response {
            $nextCalls++;
            return Response::create('ok');
        };

        self::assertSame('ok', $guard->handle(new Request(), $next)->getContent());
        self::assertSame('ok', $guard->handle(new Request(), $next)->getContent());
        self::assertSame(1, $resolutions);
        self::assertSame(2, $nextCalls);
    }

    public function testOutdatedSchemaFailsClosedWithUpgradeAction(): void
    {
        $guard = new DatabaseSchemaGuard(static fn(): array => [
            'ready' => false,
            'registry_exists' => true,
            'current_version' => '20260701_old',
            'required_version' => '20260722_new',
            'pending' => ['20260722_new.sql'],
            'application_table_count' => 10,
            'version_mismatches' => [],
            'checksum_mismatches' => [],
            'baseline_checksum_mismatches' => [],
            'unknown_registrations' => [],
        ]);

        $response = $guard->handle(new Request(), static fn(Request $request): Response => Response::create('unsafe'));
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getCode());
        self::assertSame('database_schema_upgrade_required', $payload['error']);
        self::assertStringContainsString('db:migrate', $payload['action']);
    }

    public function testVersionCheckFailureFailsClosedWithoutLeakingTheException(): void
    {
        $guard = new DatabaseSchemaGuard(static function (): array {
            throw new RuntimeException('secret database detail');
        });

        $response = $guard->handle(new Request(), static fn(Request $request): Response => Response::create('unsafe'));

        self::assertSame(503, $response->getCode());
        self::assertStringContainsString('database_schema_check_failed', (string)$response->getContent());
        self::assertStringNotContainsString('secret database detail', (string)$response->getContent());
    }
}
