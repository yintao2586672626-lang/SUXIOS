<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class SemanticGlossaryKnowledgeRetrievalTest extends TestCase
{
    public function testVersionedGlossaryBatchRemainsRetrievableBehindCurrentManifestPointer(): void
    {
        $result = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows(
            [$this->unit()],
            [$this->manifestChunk(), $this->batchChunk()],
            [
                'hotel_id' => 80,
                'user_id' => 7,
                'platform' => 'ctrip',
                'question' => 'Typeless词库在哪里导入？',
            ]
        );

        self::assertSame('matched', $result['status']);
        self::assertSame(2, $result['items'][0]['chunk_id']);
        self::assertSame('semantic_glossary', $result['items'][0]['source']);
        self::assertSame('reference_only', $result['items'][0]['gate_status']);
        self::assertSame('reference_only', $result['items'][0]['usage_policy']);
        self::assertStringContainsString('Typeless', $result['items'][0]['excerpt']);
    }

    public function testDateSegmentsStayStringsDuringLexicalScoring(): void
    {
        $result = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows(
            [$this->unit()],
            [$this->batchChunk()],
            [
                'hotel_id' => 80,
                'user_id' => 7,
                'platform' => 'meituan',
                'question' => '2026-08-09 美团订单是多少',
            ]
        );

        self::assertSame('matched', $result['status']);
        self::assertSame(2, $result['items'][0]['chunk_id']);
        self::assertGreaterThan(0, $result['items'][0]['retrieval_score']);
    }

    /** @return array<string,mixed> */
    private function unit(): array
    {
        return [
            'unit_id' => 70,
            'hotel_id' => 0,
            'stable_key' => 'global:semantic_glossary:unified',
            'current_chunk_id' => 1,
            'name' => '宿析OS统一语义词库',
            'source' => 'semantic_glossary',
            'status' => 'done',
            'description' => '统一规范词、别名、来源、指标与路由。',
            'created_by' => 0,
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-26 00:55:00',
            'review_due_at' => '2027-02-21 00:00:00',
        ];
    }

    /** @return array<string,mixed> */
    private function manifestChunk(): array
    {
        return [
            'chunk_id' => 1,
            'unit_id' => 70,
            'type' => 'semantic_glossary_reference',
            'version_no' => 4,
            'lifecycle_status' => 'active',
            'superseded_by_chunk_id' => null,
            'content' => json_encode($this->content([
                'seed_key' => 'semantic_glossary:2026-08-26.3:manifest',
                'type' => 'semantic_glossary_manifest',
                'search_text' => '语义词库 维护说明 来源指纹',
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array<string,mixed> */
    private function batchChunk(): array
    {
        return [
            'chunk_id' => 2,
            'unit_id' => 70,
            'type' => 'semantic_glossary_reference',
            'version_no' => 4,
            'lifecycle_status' => 'active',
            'superseded_by_chunk_id' => null,
            'content' => json_encode($this->content([
                'seed_key' => 'semantic_glossary:2026-08-26.3:batch:0001',
                'type' => 'semantic_glossary_batch',
                'search_text' => 'Typeless 词库 导入CSV 美团 订单 曝光量 ADR',
                'concepts' => [[
                    'canonical_term' => 'Typeless',
                    'aliases' => ['Typeless词典'],
                    'category' => 'personal_common',
                    'route_key' => 'knowledge-center',
                ]],
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function content(array $extra): array
    {
        return array_merge([
            'scope' => 'global_semantic_reference_and_navigation',
            'evidence_level' => 'user_provided_and_project_curated_semantic_mapping',
            'evidence_grade' => 'C',
            'source_refs' => ['user-file://Typeless.csv#sha256=e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53'],
            'reviewed_at' => '2026-08-26 00:55:00',
            'review_due_at' => '2027-02-21 00:00:00',
            'review_interval_days' => 180,
            'lifecycle_status' => 'active',
            'platforms' => [],
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
            'content_execution_policy' => 'data_only_never_execute',
        ], $extra);
    }
}
