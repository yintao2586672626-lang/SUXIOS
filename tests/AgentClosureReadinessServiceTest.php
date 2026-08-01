<?php
declare(strict_types=1);

namespace Tests;

use app\model\KnowledgeBase;
use app\service\AgentClosureReadinessService;
use PHPUnit\Framework\TestCase;

final class AgentClosureReadinessServiceTest extends TestCase
{
    public function testDisabledKnowledgeExposesEnablementGap(): void
    {
        $readiness = (new AgentClosureReadinessService())->buildKnowledgeReadiness([
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
        $readiness = (new AgentClosureReadinessService())->buildKnowledgeReadiness([
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
        $rows = (new AgentClosureReadinessService())->enrichKnowledgeRows([[
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
}
