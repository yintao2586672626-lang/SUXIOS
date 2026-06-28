<?php
declare(strict_types=1);

namespace Tests;

use app\controller\RevenueAi;
use app\model\PriceSuggestion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class RevenueAiControllerTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): RevenueAi
    {
        $reflection = new ReflectionClass(RevenueAi::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testRequestedEnabledChannelsKeepsCtripScopeExplicit(): void
    {
        self::assertSame(['ctrip'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'platform' => ' Ctrip ',
        ]]));
        self::assertSame(['ctrip'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'enabled_channels' => 'ctrip,ctrip',
        ]]));
        self::assertSame(['ctrip', 'meituan'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'enabled_channels' => ['ctrip', 'meituan'],
        ]]));
        self::assertSame([], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[]]));
    }

    public function testRequestedEnabledChannelsRejectsUnknownScope(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revenue_ai_channel_invalid');

        $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'platform' => 'unknown',
        ]]);
    }

    public function testReviewPayloadKeepsManualExecutionBoundary(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionReviewPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
        ], 'approve']);

        self::assertSame(88, $payload['suggestion_id']);
        self::assertSame('approved', $payload['status']);
        self::assertSame('已批准', $payload['status_label']);
        self::assertTrue($payload['manual_review_required']);
        self::assertTrue($payload['advisory_only']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('/api/revenue-ai/price-suggestions/88/execution-intent', $payload['allowed_endpoint']);
        self::assertSame('create_execution_intent', $payload['next_action']);
        self::assertContains('ota_write', $payload['forbidden_actions']);
        self::assertContains('update_room_type_base_price', $payload['forbidden_actions']);
    }

    public function testReviewPayloadSupportsApproveWithChangesWithoutOtaWrite(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionReviewPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
            'suggested_price' => 318,
            'factors' => [
                'manual_review' => [
                    'version' => 1,
                    'action' => 'approve_with_changes',
                    'original_suggested_price' => 318,
                    'approved_price' => 328,
                    'auto_write_ota' => true,
                    'local_price_updated' => true,
                ],
            ],
        ], 'approve_with_changes']);

        self::assertSame('approve_with_changes', $payload['action']);
        self::assertTrue($payload['modified_review']);
        self::assertSame(318.0, $payload['original_suggested_price']);
        self::assertSame(328.0, $payload['approved_price']);
        self::assertSame(1, $payload['review_version']);
        self::assertSame('price_suggestions.factors.manual_review_versions', $payload['review_storage']);
        self::assertFalse($payload['manual_review']['auto_write_ota']);
        self::assertFalse($payload['manual_review']['local_price_updated']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('/api/revenue-ai/price-suggestions/88/execution-intent', $payload['allowed_endpoint']);
    }

    public function testManualReviewStateVersionsPlainApproveWithoutOtaWrite(): void
    {
        $state = $this->invokeNonPublic($this->controller(), 'buildManualReviewState', [[
            'id' => 88,
            'hotel_id' => 7,
            'suggested_price' => 318,
            'factors' => [
                'manual_review_versions' => [
                    ['version' => 1, 'action' => 'approve_with_changes', 'approved_price' => 328],
                ],
            ],
        ], 'approve', 9, '同意执行原建议价', null]);

        $review = $state['review'];
        self::assertSame(2, $review['version']);
        self::assertSame('approve', $review['action']);
        self::assertSame('approved', $review['status_after']);
        self::assertSame(318.0, $review['original_suggested_price']);
        self::assertSame(318.0, $review['approved_price']);
        self::assertSame(0.0, $review['price_delta']);
        self::assertSame(9, $review['reviewed_by']);
        self::assertSame('同意执行原建议价', $review['remark']);
        self::assertFalse($review['auto_write_ota']);
        self::assertFalse($review['local_price_updated']);
        self::assertFalse($review['ota_write']);
        self::assertSame('price_suggestions.factors.manual_review_versions', $review['version_storage']);
        self::assertCount(2, $state['factors']['manual_review_versions']);
        self::assertSame($review, $state['factors']['manual_review']);
    }

    public function testManualReviewStateVersionsRejectWithoutApprovedPrice(): void
    {
        $state = $this->invokeNonPublic($this->controller(), 'buildManualReviewState', [[
            'id' => 88,
            'hotel_id' => 7,
            'suggested_price' => 318,
        ], 'reject', 9, '保护价依据不足', null]);

        $review = $state['review'];
        self::assertSame(1, $review['version']);
        self::assertSame('reject', $review['action']);
        self::assertSame('rejected', $review['status_after']);
        self::assertSame(318.0, $review['original_suggested_price']);
        self::assertNull($review['approved_price']);
        self::assertNull($review['price_delta']);
        self::assertSame('保护价依据不足', $review['remark']);
        self::assertFalse($review['auto_write_ota']);
        self::assertFalse($review['local_price_updated']);
        self::assertFalse($review['ota_write']);
        self::assertSame($review, $state['factors']['manual_review_versions'][0]);
    }

    public function testExecutionIntentPayloadDoesNotClaimPriceApplication(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionExecutionIntentPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
        ], [
            'id' => 99,
            'source_module' => 'price_suggestion',
            'source_record_id' => 88,
            'status' => 'pending_approval',
        ], true]);

        self::assertSame(88, $payload['suggestion_id']);
        self::assertSame(99, $payload['execution_intent']['id']);
        self::assertTrue($payload['execution_intent_existing']);
        self::assertSame('price_suggestion', $payload['source_module']);
        self::assertSame('ops-track', $payload['target_page']);
        self::assertSame('approve_intent', $payload['target_action']);
        self::assertSame(99, $payload['target_id']);
        self::assertSame('intent', $payload['target_kind']);
        self::assertTrue($payload['manual_review_required']);
        self::assertTrue($payload['advisory_only']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('operation_execution_manual_evidence', $payload['next_action']);
    }
}
