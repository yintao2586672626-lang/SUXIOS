<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueDecisionFrameService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RevenueDecisionFrameServiceTest extends TestCase
{
    public function testExplicitPriceSelectionPreservesVisibleMappingAndTruthBoundary(): void
    {
        $frame = (new RevenueDecisionFrameService())->build(
            '今天怎么调整房价？',
            'price',
            ['status' => 'evidence_ready', 'evidence_counts' => ['facts' => 2]]
        );

        self::assertSame('selected', $frame['classification_status']);
        self::assertSame('price', $frame['primary_object']);
        self::assertSame('价格', $frame['primary_label']);
        self::assertSame(['RM-M03'], $frame['method_refs']['primary']);
        self::assertSame(['RM-M04', 'RM-M07'], $frame['method_refs']['supporting']);
        self::assertSame(['成本', '需求', '竞争', '净价', '目标'], $frame['key_inputs']);
        self::assertSame('source_codes_only_definitions_not_provided', $frame['method_refs']['definition_status']);
        self::assertSame('not_assessed', $frame['evidence_gate']['key_input_coverage']);
        self::assertFalse($frame['evidence_gate']['key_inputs_verified']);
        self::assertFalse($frame['evidence_gate']['can_execute']);
        self::assertSame(RevenueDecisionFrameService::SOURCE_FINGERPRINT, $frame['source']['fingerprint']);
    }

    public function testQuestionCanInferOneObjectButKeepsCrossDimensionQuestionAmbiguous(): void
    {
        $service = new RevenueDecisionFrameService();
        $channel = $service->build(
            '携程渠道的流量、佣金和取消怎么复核？',
            '',
            ['status' => 'evidence_ready', 'evidence_counts' => ['facts' => 1]]
        );
        self::assertSame('inferred', $channel['classification_status']);
        self::assertSame('channel', $channel['primary_object']);

        $ambiguous = $service->build(
            '房价和库存怎么一起调整？',
            '',
            ['status' => 'evidence_ready', 'evidence_counts' => ['facts' => 1]]
        );
        self::assertSame('ambiguous', $ambiguous['classification_status']);
        self::assertSame('', $ambiguous['primary_object']);
        self::assertSame(['price', 'inventory_progress'], array_column($ambiguous['candidate_objects'], 'key'));
        self::assertSame([], $ambiguous['key_inputs']);
    }

    public function testMissingFactsBlocksConclusionWithoutHidingTheSelectedFramework(): void
    {
        $frame = (new RevenueDecisionFrameService())->build(
            '库存与进度要检查什么？',
            'inventory_progress',
            ['status' => 'blocked_by_missing_facts', 'evidence_counts' => ['facts' => 0]]
        );

        self::assertSame('inventory_progress', $frame['primary_object']);
        self::assertSame('blocked_by_missing_facts', $frame['evidence_gate']['status']);
        self::assertStringContainsString('缺少同酒店、同平台、同日期严格回读事实', $frame['evidence_gate']['message']);
    }

    public function testRejectsUnknownExplicitDecisionObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('决策对象无效');
        (new RevenueDecisionFrameService())->build('test', 'profit', []);
    }
}
