<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OperationAlertTaskBridgeTest extends TestCase
{
    use ReflectionHelper;

    public function testPersistedThresholdAlertBuildsPendingHumanChecklistWithoutOtaWrite(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'createExecutionIntentFromAlert'));

        $input = $this->invokeNonPublic($service, 'buildAlertExecutionIntentInput', [[
            'id' => 91,
            'hotel_id' => 7,
            'alert_type' => 'conversion_low',
            'level' => 'medium',
            'title' => '转化偏低',
            'message' => '订单/访客转化率低于3%',
            'source' => 'rule',
            'status' => 'unread',
            'related_date' => '2026-07-19',
            'action_suggestion' => '复核详情页到下单漏斗',
            'raw_data' => [
                'metric_key' => 'ota_conversion_rate',
                'threshold_value' => 3,
                'observed_value' => 2.4,
                'cookie' => 'must-not-enter-execution-evidence',
            ],
        ]]);

        self::assertSame('operation_alert', $input['source_module']);
        self::assertSame(91, $input['source_record_id']);
        self::assertSame(7, $input['hotel_id']);
        self::assertSame('ota', $input['platform']);
        self::assertSame('operation_checklist', $input['object_type']);
        self::assertSame('review_conversion_funnel', $input['action_type']);
        self::assertSame('ota_conversion_rate', $input['expected_metric']);
        self::assertSame('ota_channel', $input['target_value']['metric_scope']);
        self::assertFalse($input['evidence']['auto_write_ota']);
        self::assertSame(['operation_alert#91'], $input['evidence']['evidence_refs']);
        self::assertArrayNotHasKey('cookie', $input['evidence']['alert_context']);

        $payload = $service->buildExecutionIntentPayload([7], 7, $input, 3);
        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
    }

    public function testMeituanCompetitorAlertKeepsPlatformAndUsesStableIdempotencyNamespace(): void
    {
        $service = new OperationManagementService();
        $input = $this->invokeNonPublic($service, 'buildAlertExecutionIntentInput', [[
            'id' => 92,
            'hotel_id' => 7,
            'alert_type' => 'meituan_competitor_top1_changed',
            'level' => 'high',
            'title' => '美团重点竞对变化',
            'message' => 'TOP1竞对发生变化',
            'status' => 'unread',
            'related_date' => '2026-07-19',
            'raw_data' => ['change_signal_type' => 'top1_changed'],
        ]]);

        self::assertSame('meituan', $input['platform']);
        self::assertSame('review_meituan_competitor_change', $input['action_type']);
        self::assertSame('meituan_competitor_rank_signal', $input['expected_metric']);
        self::assertSame(
            'operation_alert_' . str_repeat('a', 32),
            $this->invokeNonPublic($service, 'normalizeTrustedExecutionIntentIdempotencyKey', [
                'operation_alert_' . str_repeat('a', 32),
            ])
        );
    }
}
