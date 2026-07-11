<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;

final class PlatformDataSyncPrivacyBoundaryTest extends TestCase
{
    public function testSyncTaskResponseDropsExternalDiagnosticText(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSyncTaskRowForResponse');
        $method->setAccessible(true);

        $task = $method->invoke($service, [
            'id' => 71,
            'status' => 'failed',
            'message' => 'platform rejected request: token=test-only-secret',
            'stats_json' => json_encode([
                'normalized_count' => 0,
                'saved_count' => 0,
                'payload_keys' => ['response', 'token'],
                'sync_diagnostics' => [
                    'target_date' => '2026-07-09',
                    'requires_target_date_traffic' => true,
                    'target_date_rows' => 0,
                    'target_date_traffic_rows' => 0,
                    'field_fact_status' => 'not_loaded',
                    'p0_status' => 'blocked',
                    'missing_inputs' => ['target_date_traffic_rows'],
                    'operator_message' => 'platform rejected request: token=test-only-secret',
                    'adapter_status' => 'failed',
                    'adapter_message' => 'Authorization: Bearer test-only-secret',
                ],
                'collection_quality' => [
                    'primary_quality_state' => 'collection_failed',
                    'quality_flags' => ['task_status_failed'],
                    'metric_scope' => 'ota_channel',
                    'next_action' => 'inspect_collection_failure',
                    'evidence' => [
                        'p0_status' => 'token=test-only-secret',
                        'field_fact_status' => 'secret=test-only-secret',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $stats = json_decode((string)$task['stats_json'], true);

        self::assertSame('collection_failed', $task['message']);
        self::assertSame(['response'], $stats['payload_keys']);
        self::assertSame('2026-07-09', $stats['sync_diagnostics']['target_date']);
        self::assertSame('failed', $stats['sync_diagnostics']['adapter_status']);
        self::assertSame('collection_failed', $stats['sync_diagnostics']['operator_message']);
        self::assertArrayNotHasKey('adapter_message', $stats['sync_diagnostics']);
        self::assertSame('collection_failed', $stats['collection_quality']['primary_quality_state']);
        self::assertSame('inspect_collection_failure', $stats['collection_quality']['next_action']);
        self::assertSame('unknown', $stats['collection_quality']['evidence']['p0_status']);
        self::assertSame('unknown', $stats['collection_quality']['evidence']['field_fact_status']);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testSyncLogResponseDropsExternalDiagnosticText(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSyncLogRowForResponse');
        $method->setAccessible(true);

        $log = $method->invoke($service, [
            'event' => 'sync_finished',
            'message' => 'platform rejected request: cookie=test-only-secret',
            'context_json' => json_encode([
                'sync_diagnostics' => [
                    'target_date' => '2026-07-09',
                    'operator_message' => 'platform rejected request: cookie=test-only-secret',
                    'adapter_status' => 'failed',
                    'adapter_message' => 'cookie=test-only-secret',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $context = json_decode((string)$log['context_json'], true);

        self::assertSame('collection_failed', $log['message']);
        self::assertSame('collection_failed', $context['sync_diagnostics']['operator_message']);
        self::assertArrayNotHasKey('adapter_message', $context['sync_diagnostics']);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testDataSourceResponseUsesOpaqueCredentialIndicators(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSourceRow');
        $method->setAccessible(true);

        $source = $method->invoke($service, [
            'id' => 72,
            'config_json' => json_encode([
                'token' => 'test-only-secret',
                'headers' => 'Authorization: Bearer test-only-secret',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => json_encode(['cookies' => 'test-only-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error' => 'platform rejected request: token=test-only-secret',
        ]);

        self::assertTrue($source['has_secret']);
        self::assertTrue($source['has_cookies']);
        self::assertArrayNotHasKey('cookies_preview', $source);
        self::assertSame('[configured]', $source['config']['token']);
        self::assertSame('Authorization: [configured]', $source['config']['headers']);
        self::assertSame('collection_failed', $source['last_error']);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testResourceCatalogDropsLegacyTaskErrorText(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildResourcePlatformStatus');
        $method->setAccessible(true);

        $status = $method->invoke($service,
            ['resource' => 'traffic', 'data_type' => 'traffic'],
            'meituan',
            [[
                'id' => 81,
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'enabled' => 1,
                'status' => 'ready',
            ]],
            [[
                'id' => 82,
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'status' => 'failed',
                'message' => 'Authorization: Bearer test-only-secret',
                'finished_at' => '2026-07-10 08:20:00',
                'stats_json' => json_encode(['normalized_count' => 0, 'saved_count' => 0]),
            ]],
            [],
        );

        self::assertSame('collection_failed', $status['missing_reason']);
        self::assertSame('collection_failed', $status['latest_task']['message']);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testMeituanReviewConfigRejectsCookieApiCustody(): void
    {
        $endpoint = new class {
            use \app\controller\concern\MeituanConfigConcern;

            private function checkPermission(): void
            {
            }

            protected function error(string $message = '操作失败', int $code = 400, mixed $data = null): \think\Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }

            protected function success(mixed $data = null, string $message = '操作成功'): \think\Response
            {
                return json(['code' => 200, 'message' => $message, 'data' => $data]);
            }
        };

        $response = $endpoint->saveMeituanCommentConfig();
        $content = (string)$response->getContent();

        self::assertSame(422, $response->getCode());
        self::assertStringNotContainsString('test-only-secret', $content);
        self::assertStringNotContainsString('Authorization:', $content);
    }
}
