<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\DatabaseSchemaGuard;
use app\service\SchemaVersionStatusCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\Request;
use think\Response;

final class DatabaseSchemaGuardTest extends TestCase
{
    public function testReadyStatusIsSharedAcrossGuardInstances(): void
    {
        $resolutions = 0;
        $nextCalls = 0;
        $store = [];
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static fn(): string => 'test-deploy-a',
            $lockDirectory
        );
        $resolver = static function () use (&$resolutions): array {
            $resolutions++;
            return ['ready' => true];
        };
        $firstGuard = new DatabaseSchemaGuard($resolver, 5.0, $cache);
        $secondGuard = new DatabaseSchemaGuard($resolver, 5.0, $cache);
        $next = static function (Request $request) use (&$nextCalls): Response {
            $nextCalls++;
            return Response::create('ok');
        };

        try {
            self::assertSame('ok', $firstGuard->handle(new Request(), $next)->getContent());
            self::assertSame('ok', $secondGuard->handle(new Request(), $next)->getContent());
            self::assertSame(1, $resolutions);
            self::assertSame(2, $nextCalls);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testHealthReadinessBypassesTheFullSchemaResolver(): void
    {
        $request = (new Request())->setPathinfo('/api/health');
        $guard = new DatabaseSchemaGuard(static function (): array {
            throw new RuntimeException('full schema resolver should not run');
        });

        $response = $guard->handle(
            $request,
            static fn(Request $request): Response => Response::create('health-ok')
        );

        self::assertSame('health-ok', $response->getContent());
    }

    public function testNotReadyStatusIsSharedWithoutRepeatingResolver(): void
    {
        $store = [];
        $resolutions = 0;
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static fn(): string => 'test-not-ready-deploy',
            $lockDirectory
        );
        $resolver = static function () use (&$resolutions): array {
            $resolutions++;
            return [
                'ready' => false,
                'registry_exists' => true,
                'current_version' => '20260724_old',
                'required_version' => '20260725_new',
                'pending' => ['20260725_new.sql'],
                'application_table_count' => 10,
            ];
        };

        try {
            $first = (new DatabaseSchemaGuard($resolver, 60.0, $cache))
                ->handle(new Request(), static fn(Request $request): Response => Response::create('unsafe'));
            $second = (new DatabaseSchemaGuard($resolver, 60.0, $cache))
                ->handle(new Request(), static fn(Request $request): Response => Response::create('unsafe'));

            self::assertSame(503, $first->getCode());
            self::assertSame(503, $second->getCode());
            self::assertSame(1, $resolutions);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testResolverFailureReleasesRefreshLockAndDoesNotPoisonCache(): void
    {
        $store = [];
        $resolutions = 0;
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static fn(): string => 'test-resolver-recovery-deploy',
            $lockDirectory
        );
        $failingResolver = static function () use (&$resolutions): array {
            $resolutions++;
            throw new RuntimeException('schema resolver failed');
        };
        $readyResolver = static function () use (&$resolutions): array {
            $resolutions++;
            return ['ready' => true];
        };

        try {
            $failed = (new DatabaseSchemaGuard($failingResolver, 60.0, $cache))
                ->handle(new Request(), static fn(Request $request): Response => Response::create('unsafe'));
            $recovered = (new DatabaseSchemaGuard($readyResolver, 60.0, $cache))
                ->handle(new Request(), static fn(Request $request): Response => Response::create('ok'));

            self::assertSame(503, $failed->getCode());
            self::assertSame('ok', $recovered->getContent());
            self::assertSame(2, $resolutions);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testDeploymentIdentityChangeDoesNotReuseAnOlderStatus(): void
    {
        $store = [];
        $reader = static function (string $key) use (&$store): mixed {
            return $store[$key] ?? null;
        };
        $writer = static function (string $key, array $value, int $ttl) use (&$store): bool {
            $store[$key] = $value;
            return true;
        };
        $deleter = static function (string $key) use (&$store): bool {
            unset($store[$key]);
            return true;
        };
        $config = ['database' => 'hotelx'];
        $root = dirname(__DIR__);
        $firstDeploy = new SchemaVersionStatusCache(
            $config,
            $root,
            $reader,
            $writer,
            $deleter,
            static fn(): string => 'deploy-a'
        );
        $secondDeploy = new SchemaVersionStatusCache(
            $config,
            $root,
            $reader,
            $writer,
            $deleter,
            static fn(): string => 'deploy-b'
        );

        self::assertTrue($firstDeploy->put(['ready' => true]));
        self::assertSame(['ready' => true], $firstDeploy->get());
        self::assertNull($secondDeploy->get());
    }

    public function testRefreshLockDoubleChecksCacheBeforeRunningResolver(): void
    {
        $reads = 0;
        $resolutions = 0;
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static function (string $key) use (&$reads): mixed {
                $reads++;
                return $reads === 1 ? null : [
                    'payload_version' => 1,
                    'deployment_identity' => 'deploy-lock-test',
                    'status' => ['ready' => true],
                ];
            },
            static fn(string $key, array $value, int $ttl): bool => true,
            static fn(string $key): bool => true,
            static fn(): string => 'deploy-lock-test',
            $lockDirectory
        );

        try {
            $status = $cache->remember(static function () use (&$resolutions): array {
                $resolutions++;
                return ['ready' => false];
            });
            self::assertTrue($status['ready']);
            self::assertSame(2, $reads);
            self::assertSame(0, $resolutions);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testCacheWriteFailureIsReportedOnlyOnce(): void
    {
        $messages = [];
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static fn(string $key): mixed => null,
            static fn(string $key, array $value, int $ttl): bool => false,
            static fn(string $key): bool => true,
            static fn(): string => 'deploy-write-failure-test',
            $lockDirectory,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        try {
            self::assertFalse($cache->put(['ready' => true]));
            self::assertFalse($cache->put(['ready' => true]));
            self::assertCount(1, $messages);
            self::assertStringContainsString('write failed', $messages[0]);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testMissingCacheEntryIsAlreadyCleared(): void
    {
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static fn(string $key): mixed => null,
            static fn(string $key, array $value, int $ttl): bool => true,
            static fn(string $key): bool => false,
            static fn(): string => 'deploy-clear-missing-test',
            sys_get_temp_dir()
        );

        self::assertTrue($cache->clear());
    }

    public function testCacheInvalidationFailsClosedWhenReadyEntryRemains(): void
    {
        $messages = [];
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'suxios-schema-guard-' . bin2hex(random_bytes(6));
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static fn(string $key): mixed => [
                'payload_version' => 1,
                'deployment_identity' => 'deploy-clear-failure-test',
                'status' => ['ready' => true],
            ],
            static fn(string $key, array $value, int $ttl): bool => true,
            static fn(string $key): bool => false,
            static fn(): string => 'deploy-clear-failure-test',
            $lockDirectory,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        try {
            self::assertFalse($cache->clear());
            self::assertCount(1, $messages);
            self::assertStringContainsString('invalidation failed', $messages[0]);
        } finally {
            foreach (glob($lockDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($lockDirectory);
        }
    }

    public function testUnavailableLockDirectoryFallsBackToResolverAndReports(): void
    {
        $messages = [];
        $resolutions = 0;
        $lockPath = tempnam(sys_get_temp_dir(), 'suxios-schema-lock-file-');
        self::assertIsString($lockPath);
        $cache = new SchemaVersionStatusCache(
            ['database' => 'hotelx'],
            dirname(__DIR__),
            static fn(string $key): mixed => null,
            static fn(string $key, array $value, int $ttl): bool => true,
            static fn(string $key): bool => true,
            static fn(): string => 'deploy-lock-directory-failure-test',
            $lockPath,
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        try {
            $status = $cache->remember(static function () use (&$resolutions): array {
                $resolutions++;
                return ['ready' => true];
            });
            self::assertTrue($status['ready']);
            self::assertSame(1, $resolutions);
            self::assertCount(1, $messages);
            self::assertStringContainsString('lock directory', $messages[0]);
        } finally {
            @unlink($lockPath);
        }
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
