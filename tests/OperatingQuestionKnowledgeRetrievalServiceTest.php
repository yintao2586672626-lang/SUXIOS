<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class OperatingQuestionKnowledgeRetrievalServiceTest extends TestCase
{
    public function testRetrievalKeepsOwnedFormalAndSystemGlobalKnowledgeWithinHotelScope(): void
    {
        $service = new OperatingQuestionKnowledgeRetrievalService();
        $units = [
            $this->unit(1, 20, 7, '我的携程曝光优化'),
            $this->unit(2, 20, 8, '同店其他人的私有知识'),
            $this->unit(3, 0, 0, '系统曝光方法'),
            $this->unit(4, 20, 88, '酒店正式曝光SOP', 'formal_operating_sop', 'hotel-20-exposure-sop'),
            $this->unit(5, 21, 7, '其他酒店正式SOP', 'formal_operating_sop', 'hotel-21-exposure-sop'),
        ];
        $chunks = [
            $this->chunk(101, 1, '携程曝光下降时先核验列表曝光与详情曝光。'),
            $this->chunk(102, 2, '其他人的曝光方法不应进入。'),
            $this->chunk(103, 3, '曝光诊断需要先检查来源和口径。'),
            $this->chunk(104, 4, '正式SOP要求曝光异常时先复核采集状态。'),
            $this->chunk(105, 5, '其他酒店曝光方法不应进入。'),
        ];

        $result = $service->buildFromRows($units, $chunks, [
            'hotel_id' => 20,
            'user_id' => 7,
            'platform' => 'ctrip',
            'question' => '携程曝光下降应该怎么复核？',
        ]);

        self::assertSame('matched', $result['status']);
        self::assertEqualsCanonicalizing(
            ['knowledge_chunks#101', 'knowledge_chunks#103', 'knowledge_chunks#104'],
            array_column($result['items'], 'ref')
        );
        self::assertNotContains('knowledge_chunks#102', array_column($result['items'], 'ref'));
        self::assertNotContains('knowledge_chunks#105', array_column($result['items'], 'ref'));
        self::assertSame(OperatingQuestionKnowledgeRetrievalService::METHOD, $result['method']);
    }

    public function testRetrievalExcludesUnverifiedCaseAndPlatformMismatchWithoutRelaxingNoMatch(): void
    {
        $service = new OperatingQuestionKnowledgeRetrievalService();
        $units = [$this->unit(1, 20, 7, '携程订单优化')];
        $chunks = [
            $this->chunk(101, 1, '订单优化案例', [
                'scope' => 'case_reference',
            ]),
            $this->chunk(102, 1, '订单优化上传材料', [
                'evidence_level' => 'user_provided_unverified',
            ]),
            $this->chunk(103, 1, '订单优化仅适用于美团', [
                'platforms' => ['meituan'],
            ]),
        ];

        $result = $service->buildFromRows($units, $chunks, [
            'hotel_id' => 20,
            'user_id' => 7,
            'platform' => 'ctrip',
            'question' => '携程订单怎么优化？',
        ]);

        self::assertSame('no_match', $result['status']);
        self::assertSame('lexical_no_match', $result['reason']);
        self::assertSame([], $result['items']);
        self::assertGreaterThanOrEqual(3, $result['excluded_count']);
    }

    public function testRetrievalUsesStableOrderAndDoesNotExposeSecretsOrRawMaterial(): void
    {
        $service = new OperatingQuestionKnowledgeRetrievalService();
        $units = [
            $this->unit(1, 20, 7, '曝光复核'),
            $this->unit(2, 0, 0, '曝光复核'),
        ];
        $chunks = [
            $this->chunk(12, 1, '曝光复核步骤', [
                'api_key' => 'secret-api-key',
                'raw_text' => 'secret raw document',
                'steps' => ['曝光复核步骤', '先核验来源'],
            ]),
            $this->chunk(11, 1, '曝光复核步骤'),
            $this->chunk(10, 2, '曝光复核步骤'),
        ];

        $result = $service->buildFromRows($units, $chunks, [
            'hotel_id' => 20,
            'user_id' => 7,
            'platform' => 'ctrip',
            'question' => '曝光复核',
        ]);

        self::assertSame([11, 12, 10], array_column($result['items'], 'chunk_id'));
        $excerpt = implode(' ', array_column($result['items'], 'excerpt'));
        self::assertStringContainsString('先核验来源', $excerpt);
        self::assertStringNotContainsString('secret-api-key', $excerpt);
        self::assertStringNotContainsString('secret raw document', $excerpt);
        foreach ($result['items'] as $item) {
            self::assertMatchesRegularExpression('/^knowledge_chunks#[1-9][0-9]*$/', $item['ref']);
            self::assertMatchesRegularExpression('/^knowledge_units#[1-9][0-9]*$/', $item['unit_ref']);
            self::assertContains($item['usage_policy'], ['decision_support', 'reference_only', 'known_unknown']);
        }
    }

    /** @return array<string,mixed> */
    private function unit(
        int $unitId,
        int $hotelId,
        int $createdBy,
        string $name,
        string $source = 'manual',
        string $stableKey = ''
    ): array {
        return [
            'unit_id' => $unitId,
            'hotel_id' => $hotelId,
            'created_by' => $createdBy,
            'name' => $name,
            'description' => '曝光与订单经营知识',
            'source' => $source,
            'stable_key' => $stableKey,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-01 00:00:00',
            'review_due_at' => '2027-08-01 00:00:00',
        ];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function chunk(int $chunkId, int $unitId, string $text, array $overrides = []): array
    {
        return [
            'chunk_id' => $chunkId,
            'unit_id' => $unitId,
            'type' => '运营SOP',
            'lifecycle_status' => 'active',
            'superseded_by_chunk_id' => 0,
            'content' => array_merge([
                'scope' => 'generic_methodology',
                'evidence_level' => 'reviewed_method',
                'source_refs' => ['knowledge-test-source'],
                'platforms' => ['ctrip'],
                'steps' => [$text],
            ], $overrides),
        ];
    }
}
