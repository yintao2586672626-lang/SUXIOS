<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use PHPUnit\Framework\TestCase;

final class JhiraPresentationRepositoryKnowledgeTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260823_zzz_seed_jhira_presentation_reference.sql';

    public function testMigrationPinsSourceAndCreatesFourIdempotentReferenceChunks(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            '4dc9898c86ef3c4589c903e69ad12f6e398dcf28',
            '8bfc490509e9fb46a44a81dc0f753355ce3b6c5c9b4e9737e929136431334fdd',
            'cee95b70b70ccd899a058f31fb918a4e9a45b6da50c4ef318368cd07e10f2497',
            '0554A144FA19673D34B01E09AE51F7C0DB67E17F1F72E4CC44C4FE3CFF4BD26D',
            'DE85C8F3F43F9DB7EB4CDFE907CE0E4FE33866EF96DD53EFF708F6486902F756',
            '33D0986CE8C821A50491BF527EF65F44FA9067A5FBCFCBF6B6237A652F8ABFC4',
            '251AD76AF71E754A361D75858995D90F1144D1452128936A93A8D021AD275270',
            'C4B7E85AA76DBFB5E11FEE7B9C5E43EFDF053D9BEF860C091EAFAB1BD1F7FF4C',
        ] as $fingerprint) {
            self::assertStringContainsString($fingerprint, $sql);
        }

        self::assertSame(4, substr_count(
            $sql,
            "INSERT INTO `tmp_jhira_presentation_reference` (`unit_id`, `type`, `content`, `created_by`, `created_at`)"
        ));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_units`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_base`"));
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_base`', $sql);
    }

    public function testMigrationKeepsRepositoryReferenceOnlyAndRenderingUnclaimed(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            "'mechanism_gate', 'fail'",
            "'reproduction_gate', 'indeterminate'",
            "'direct_reuse', 'blocked'",
            "'package_installed', false",
            "'source_code_copied', false",
            "'$.decision_safe', false",
            "'$.task_draft_safe', false",
            "'$.contains_current_hotel_fact', false",
            "'$.contains_current_ota_fact', false",
            "'$.external_write_authorized', false",
            "'source_code_reuse'",
            "'skill_installation'",
            "'automatic_quality_pass'",
            "'automatic_publication'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            'spec_persistence_only_rendering_not_performed',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        $gate = (new KnowledgeDecisionGateService())->assess(
            [
                'lifecycle_status' => 'active',
                'reviewed_at' => '2026-08-23 00:00:00',
                'review_due_at' => '2027-02-19 00:00:00',
            ],
            [
                'scope' => 'global_presentation_delivery_method_reference',
                'evidence_level' => 'fixed_commit_static_audit_and_bounded_test_replay',
                'evidence_grade' => 'C',
                'source_refs' => ['https://github.com/example/repo@commit'],
                'requires_current_verification' => true,
                'current_verification_status' => 'not_current_hotel_fact_and_not_render_proof',
                'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_publication'],
                'lifecycle_status' => 'active',
                'decision_safe' => false,
                'task_draft_safe' => false,
            ],
            '2026-08-23 12:00:00'
        );

        self::assertSame('reference_only', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
    }
}
