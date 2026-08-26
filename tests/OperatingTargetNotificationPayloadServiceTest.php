<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingTargetNotificationPayloadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OperatingTargetNotificationPayloadServiceTest extends TestCase
{
    public function testBlockedFormalPreviewRemovesRawFactsMetricsAndIntegratedValues(): void
    {
        $preview = [
            'status' => 'ready',
            'hotel_id' => 80,
            'target_date' => '2026-07-27',
            'facts' => ['actual_revenue' => 8275.67],
            'metrics' => ['completion_rate_percent' => 82.76],
            'integrated_sources' => [
                'delivery_allowed' => false,
                'sources' => ['ctrip' => ['facts' => ['channel_revenue' => 1318]]],
            ],
            'integrated_message_preview' => [
                'status' => 'blocked',
                'content' => "# 当前阻断\n- 美团来源证据未通过。",
                'formal_values_rendered' => false,
            ],
        ];
        $gate = [
            'allowed' => false,
            'status' => 'formal_send_blocked',
            'blockers' => [[
                'code' => 'meituan_delivery_evidence_missing',
                'message' => '美团来源证据未通过。',
            ]],
        ];

        $method = new ReflectionMethod(
            OperatingTargetNotificationPayloadService::class,
            'formalReportPreview'
        );
        $sanitized = $method->invoke(new OperatingTargetNotificationPayloadService(), $preview, $gate);

        self::assertSame('blocked', $sanitized['status']);
        self::assertNull($sanitized['facts']);
        self::assertNull($sanitized['metrics']);
        self::assertNull($sanitized['integrated_sources']);
        self::assertFalse($sanitized['formal_values_rendered']);
        self::assertFalse($sanitized['debug_unverified_values_included']);
        self::assertSame(
            ['meituan_delivery_evidence_missing'],
            array_column($sanitized['blockers'], 'code')
        );
        self::assertStringNotContainsString('8275.67', json_encode($sanitized, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('1318', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function testAllowedFormalPreviewKeepsVerifiedValues(): void
    {
        $preview = [
            'status' => 'ready',
            'hotel_id' => 80,
            'target_date' => '2026-07-27',
            'facts' => ['actual_revenue' => 8275.67],
            'metrics' => ['completion_rate_percent' => 82.76],
        ];
        $method = new ReflectionMethod(
            OperatingTargetNotificationPayloadService::class,
            'formalReportPreview'
        );
        $result = $method->invoke(new OperatingTargetNotificationPayloadService(), $preview, [
            'allowed' => true,
            'status' => 'formal_send_allowed',
            'blockers' => [],
        ]);

        self::assertSame($preview, $result);
    }
}
