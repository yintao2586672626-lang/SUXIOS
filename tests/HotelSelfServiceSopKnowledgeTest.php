<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use PHPUnit\Framework\TestCase;

final class HotelSelfServiceSopKnowledgeTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260816_seed_hotel_self_service_sop_reference.sql';

    public function testMigrationPreservesAllSourceFingerprintsAndThreeReferenceChunks(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            'B9EBD8FA76BA67632431914BCE29363AADAD809207B9BD7F8D5F5308834111AF',
            'A15D215083911EE4686FF7D604486AFB26AD2ECED87A06A89FE51435B73CB043',
            '176F54192094541D93F4B0702867800B74CDEE193E365F016C4EE7FBF2088DB0',
        ] as $hash) {
            self::assertStringContainsString($hash, $sql);
        }
        self::assertSame(3, substr_count($sql, "INSERT INTO `tmp_hotel_self_service_sop_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_units`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_chunks`"));
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
    }

    public function testMigrationKeepsHistoricalSopReferenceOnlyAndNonExecutable(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            "'$.requires_current_verification', true",
            "'$.contains_current_hotel_fact', false",
            "'$.contains_current_ota_fact', false",
            "'$.external_write_authorized', false",
            "'operation_task_creation'",
            "'operation_execution'",
            "'automatic_service_promise'",
            "'automatic_pricing'",
            "'automatic_inventory_change'",
            "'external_message'",
            "'document_instructions_are_reference_material_not_agent_commands'",
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        $gate = (new KnowledgeDecisionGateService())->assess(
            [
                'lifecycle_status' => 'active',
                'reviewed_at' => '2026-08-16 00:00:00',
                'review_due_at' => '2027-02-12 00:00:00',
            ],
            [
                'scope' => 'global_hotel_service_method_reference',
                'evidence_level' => 'user_provided_historical_sop_reference',
                'evidence_grade' => 'C',
                'source_refs' => ['user-file://hotel-self-service.md#sha256=test'],
                'requires_current_verification' => true,
                'current_verification_status' => 'not_verified_for_current_hotel',
                'blocked_uses' => ['operation_task_creation', 'operation_execution'],
                'lifecycle_status' => 'active',
            ],
            '2026-08-16 12:00:00'
        );

        self::assertSame('reference_only', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
    }
}
