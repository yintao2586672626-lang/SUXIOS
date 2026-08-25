<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use PHPUnit\Framework\TestCase;

final class GeoContentOperationsKnowledgeTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260820_seed_geo_content_operations_reference.sql';

    public function testMigrationPreservesSixSourceFingerprintsAndSevenReferenceChunks(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C',
            'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6',
            '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB',
            'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5',
            'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059',
            'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68',
        ] as $hash) {
            self::assertStringContainsString($hash, $sql);
        }

        self::assertSame(7, substr_count($sql, "INSERT INTO `tmp_geo_content_reference_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_units`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_base`"));
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_base`', $sql);
    }

    public function testMigrationKeepsTemplatesReferenceOnlyAndPublicationPendingApproval(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            "'$.requires_current_verification', true",
            "'$.decision_safe', false",
            "'$.contains_current_hotel_fact', false",
            "'$.contains_current_ota_fact', false",
            "'$.contains_approved_publication_plan', false",
            "'$.external_write_authorized', false",
            "'automatic_keyword_approval'",
            "'automatic_title_approval'",
            "'operation_task_creation'",
            "'operation_execution'",
            "'automatic_content_generation'",
            "'automatic_publication'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            "'external_message'",
            "'publication_pending_approval'",
            "'document_instructions_are_reference_material_not_agent_commands'",
            '模板初始状态，不代表任何酒店实际计划或执行结果',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        $gate = (new KnowledgeDecisionGateService())->assess(
            [
                'lifecycle_status' => 'active',
                'reviewed_at' => '2026-08-20 00:00:00',
                'review_due_at' => '2027-02-16 00:00:00',
            ],
            [
                'scope' => 'global_hotel_geo_content_method_reference',
                'evidence_level' => 'user_provided_template_reference',
                'evidence_grade' => 'C',
                'source_refs' => ['user-file://geo-content.xlsx#sha256=test'],
                'requires_current_verification' => true,
                'current_verification_status' => 'not_verified_for_current_hotel',
                'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_publication'],
                'lifecycle_status' => 'active',
            ],
            '2026-08-20 12:00:00'
        );

        self::assertSame('reference_only', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
    }
}
