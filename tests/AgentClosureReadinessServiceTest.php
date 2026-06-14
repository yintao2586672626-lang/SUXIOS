<?php
declare(strict_types=1);

namespace Tests;

use app\model\AgentWorkOrder;
use app\model\AgentConversation;
use app\model\EnergySavingSuggestion;
use app\model\KnowledgeBase;
use app\model\MaintenancePlan;
use app\service\AgentClosureReadinessService;
use PHPUnit\Framework\TestCase;

final class AgentClosureReadinessServiceTest extends TestCase
{
    public function testComplaintConversationRequiresWorkOrderClosure(): void
    {
        $service = new AgentClosureReadinessService();

        $rows = $service->enrichConversationRows([[
            'id' => 88,
            'channel' => AgentConversation::CHANNEL_WECHAT,
            'message_type' => AgentConversation::MSG_TYPE_TEXT,
            'intent_type' => AgentConversation::INTENT_COMPLAINT,
            'emotion_score' => 0.65,
            'confidence_score' => 0.9,
            'user_message' => '房间异味严重',
        ]]);

        $readiness = $rows[0]['service_readiness'];

        self::assertSame('微信', $rows[0]['channel_name']);
        self::assertSame('投诉', $rows[0]['intent_type_name']);
        self::assertSame('conversation_needs_work_order', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertTrue($readiness['can_create_work_order']);
        self::assertContains('work_order', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('emotion_followup', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testLowConfidenceConversationRequiresManualReviewWithoutWorkOrder(): void
    {
        $service = new AgentClosureReadinessService();

        $readiness = $service->buildConversationReadiness([
            'intent_type' => AgentConversation::INTENT_INQUIRY,
            'emotion_score' => 0.1,
            'confidence_score' => 0.45,
        ]);

        self::assertSame('low_confidence_review', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertFalse($readiness['can_create_work_order']);
        self::assertSame(['manual_review'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testConversationLinkedToClosedWorkOrderIsClosed(): void
    {
        $service = new AgentClosureReadinessService();

        $readiness = $service->buildConversationReadiness([
            'intent_type' => AgentConversation::INTENT_SERVICE,
            'emotion_score' => 0.5,
        ], [[
            'id' => 12,
            'status' => AgentWorkOrder::STATUS_CLOSED,
            'priority' => AgentWorkOrder::PRIORITY_HIGH,
        ]]);

        self::assertSame('conversation_service_closed', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertFalse($readiness['can_create_work_order']);
        self::assertSame([12], $readiness['linked_work_order_ids']);
    }

    public function testDisabledKnowledgeExposesEnablementGap(): void
    {
        $service = new AgentClosureReadinessService();

        $readiness = $service->buildKnowledgeReadiness([
            'is_enabled' => KnowledgeBase::STATUS_DISABLED,
            'title' => '前台接待SOP',
            'content' => '入住接待流程、证件核验、押金说明和异常升级规则。',
            'keywords' => '入住,前台',
        ]);

        self::assertSame('knowledge_disabled', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['enabled_status'], array_column($readiness['missing_evidence'], 'code'));
        self::assertTrue($readiness['can_edit_knowledge']);
    }

    public function testEnabledKnowledgeRequiresRetrievalEntry(): void
    {
        $service = new AgentClosureReadinessService();

        $readiness = $service->buildKnowledgeReadiness([
            'is_enabled' => KnowledgeBase::STATUS_ENABLED,
            'title' => '早餐规则',
            'content' => '早餐供应时间为七点到十点，儿童、加床和会员权益按前台最新规则处理。',
            'keywords' => '',
            'tags' => [],
        ]);

        self::assertSame('knowledge_missing_keywords', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['retrieval_keywords'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testUsedKnowledgeBecomesClosedLoopEvidence(): void
    {
        $service = new AgentClosureReadinessService();

        $rows = $service->enrichKnowledgeRows([[
            'id' => 9,
            'is_enabled' => KnowledgeBase::STATUS_ENABLED,
            'title' => '退房延迟规则',
            'content' => '普通客人延迟退房需要按小时收费，会员客人根据等级享受免费延迟权益，异常情况由值班经理确认。',
            'keywords' => '退房,延迟,会员',
            'tags' => ['前台'],
        ]], [
            9 => ['conversation_count' => 3, 'latest_used_at' => '2026-06-14 10:20:00'],
        ]);

        $readiness = $rows[0]['knowledge_readiness'];

        self::assertSame('knowledge_active_used', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(3, $readiness['conversation_count']);
        self::assertSame('2026-06-14 10:20:00', $readiness['latest_used_at']);
    }

    public function testUrgentUnassignedWorkOrderExposesMissingClosureEvidence(): void
    {
        $service = new AgentClosureReadinessService();

        $rows = $service->enrichWorkOrderRows([[
            'status' => AgentWorkOrder::STATUS_PENDING,
            'priority' => AgentWorkOrder::PRIORITY_URGENT,
            'order_type' => AgentWorkOrder::TYPE_COMPLAINT,
            'source_type' => AgentWorkOrder::SOURCE_MANUAL,
            'assigned_to' => 0,
            'emotion_score' => 0.5,
        ]]);

        $readiness = $rows[0]['closure_readiness'];
        $missingCodes = array_column($readiness['missing_evidence'], 'code');

        self::assertSame('待处理', $rows[0]['status_name']);
        self::assertSame('客诉处理', $rows[0]['order_type_name']);
        self::assertSame('pending_assignment', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertContains('assignee', $missingCodes);
        self::assertContains('urgent_response', $missingCodes);
        self::assertContains('emotion_followup', $missingCodes);
    }

    public function testClosedWorkOrderIsClosedLoopEvidence(): void
    {
        $service = new AgentClosureReadinessService();

        $readiness = $service->buildWorkOrderReadiness([
            'status' => AgentWorkOrder::STATUS_CLOSED,
            'priority' => AgentWorkOrder::PRIORITY_NORMAL,
        ]);

        self::assertSame('service_closed', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testCompletedEnergySuggestionRequiresActualSavingEvidence(): void
    {
        $service = new AgentClosureReadinessService();

        $withoutSaving = $service->buildEnergySuggestionReadiness([
            'status' => EnergySavingSuggestion::STATUS_COMPLETED,
            'actual_saving' => 0,
        ]);
        $withSaving = $service->buildEnergySuggestionReadiness([
            'status' => EnergySavingSuggestion::STATUS_COMPLETED,
            'actual_saving' => 128.5,
        ]);

        self::assertSame('completed_pending_saving', $withoutSaving['stage']);
        self::assertFalse($withoutSaving['closed_loop']);
        self::assertSame(['actual_saving'], array_column($withoutSaving['missing_evidence'], 'code'));
        self::assertSame('saving_verified', $withSaving['stage']);
        self::assertTrue($withSaving['closed_loop']);
    }

    public function testMaintenancePlanDistinguishesOverdueAndExecutedCycles(): void
    {
        $service = new AgentClosureReadinessService();

        $overdue = $service->buildMaintenancePlanReadiness([
            'status' => MaintenancePlan::STATUS_ACTIVE,
            'next_maintenance_date' => '2020-01-01',
            'execution_count' => 0,
        ]);
        $executed = $service->buildMaintenancePlanReadiness([
            'status' => MaintenancePlan::STATUS_COMPLETED,
            'execution_count' => 1,
            'last_maintenance_date' => date('Y-m-d'),
        ]);

        self::assertSame('overdue', $overdue['stage']);
        self::assertFalse($overdue['closed_loop']);
        self::assertSame(['execution'], array_column($overdue['missing_evidence'], 'code'));
        self::assertSame('maintenance_completed', $executed['stage']);
        self::assertTrue($executed['closed_loop']);
    }
}
