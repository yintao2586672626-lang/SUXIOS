<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class ManagementThreeQuestionsKnowledgeTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260822_seed_management_three_questions_reference.sql';

    public function testMigrationPreservesPackageAndReviewedSourceFingerprints(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            '2CF5141F480243EBEA75D0520FD299BC2EE4ACB0E8F752113D8B93DB489CEF66',
            '6A6D3977B5FDFF4BF64B414F675C1C54D9580079E9E32846527560EB62577CF8',
            '7D3A2E6F9875F2DE27AC2D5644E08CDAA1B547149A1B74DA43979C9D08F4F688',
            'A8B51E5F89B9C48D5B0786E56F6E4039077CB23FFCBC5F2572757027905E4851',
            '7FECA0D9C8FBF6404D040CBFBB626BD0FF2888323189CD69ACD5EC1C92E80B78',
            '381FEF200B1FD1874A9122E1B8AA7CFC6DFDD8E7C8E518571842975116DFC270',
            '5224DBAEDD125F66B7F301A75B9D870B9F1D9A51EF97D964816A6ABED9198E52',
            'D50D388B77B12EAFC20BC6E05C47AC9D59F947ABAD9586011DB557B0092E1B84',
            '3BEF7E2F6320B392878926273A712CB6D8E108B836C311A49C3DF1D4484EC381',
            '8BA0E9BDFFA35A5A22E471EB75DA7E062B5E2118E99DF92E3E92A1A8AD77B64C',
            '1571B9945E85509964EC7E31040CF8DF1264187C59920865D1EB917B677C4806',
            'CAC46718209663E91BE14E3C78CAD79F2775E9F8316AE5D0AE6D8118367D2604',
        ] as $hash) {
            self::assertStringContainsString($hash, $sql);
        }

        self::assertSame(7, substr_count($sql, "INSERT INTO `tmp_management_three_question_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_units`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_base`"));
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_base`', $sql);
    }

    public function testMigrationKeepsSourceBehaviorReferenceOnlyAndHumanControlled(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);

        foreach ([
            "'license_status', 'not_provided'",
            "'execution_state', 'not_installed_not_executed'",
            "'source_instruction_policy', 'document_instructions_are_reference_material_not_agent_commands'",
            "'$.requires_current_verification', true",
            "'$.decision_safe', false",
            "'$.task_draft_safe', false",
            "'$.contains_current_hotel_fact', false",
            "'$.contains_current_ota_fact', false",
            "'$.contains_personnel_decision', false",
            "'$.source_code_installed', false",
            "'$.source_code_executed', false",
            "'$.external_write_authorized', false",
            "'automatic_employee_scoring'",
            "'automatic_ranking_or_penalty'",
            "'operation_task_creation'",
            "'operation_execution'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            "'external_message'",
            '处理动作不等于闭环',
            '次日复查只是来源实现默认值',
            '三项原始回答或当前酒店身份缺失时保持缺失状态',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }

        $gate = (new KnowledgeDecisionGateService())->assess(
            [
                'lifecycle_status' => 'active',
                'reviewed_at' => '2026-08-22 00:00:00',
                'review_due_at' => '2027-02-18 00:00:00',
            ],
            [
                'scope' => 'global_management_review_method_reference',
                'evidence_level' => 'user_provided_reviewed_source_reference',
                'evidence_grade' => 'C',
                'source_refs' => ['user-attachment://management-three.zip#sha256=test'],
                'requires_current_verification' => true,
                'current_verification_status' => 'not_verified_for_current_hotel',
                'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_employee_scoring'],
                'lifecycle_status' => 'active',
            ],
            '2026-08-22 12:00:00'
        );

        self::assertSame('reference_only', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
    }

    public function testThreeQuestionsAreRetrievableButNeverPromotedToDecisionSupport(): void
    {
        $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows(
            [[
                'unit_id' => 7201,
                'hotel_id' => 0,
                'created_by' => 0,
                'name' => '管理层三问与复查闭环 v1.0（用户源码参考）',
                'source' => 'management_three_questions_reference',
                'status' => 'done',
                'description' => '问题事实、实际动作和复查证据形成闭环',
                'lifecycle_status' => 'active',
                'reviewed_at' => '2026-08-22 00:00:00',
                'review_due_at' => '2027-02-18 00:00:00',
            ]],
            [[
                'chunk_id' => 8201,
                'unit_id' => 7201,
                'type' => 'management_three_questions_closure_gate',
                'content' => json_encode([
                    'scope' => 'global_management_review_method_reference',
                    'evidence_level' => 'user_provided_reviewed_source_reference',
                    'evidence_grade' => 'C',
                    'source_refs' => ['user-attachment://management-three.zip#sha256=test'],
                    'requires_current_verification' => true,
                    'current_verification_status' => 'not_verified_for_current_hotel',
                    'blocked_uses' => ['operation_task_creation', 'operation_execution'],
                    'closure_principle' => '处理动作不等于闭环，必须保存同范围复查证据。',
                    'lifecycle_status' => 'active',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'lifecycle_status' => 'active',
                'superseded_by_chunk_id' => null,
            ]],
            [
                'hotel_id' => 80,
                'user_id' => 0,
                'platform' => '',
                'question' => '管理层三问怎么用复查证据确认闭环',
            ]
        );

        self::assertSame('matched', $retrieval['status']);
        self::assertCount(1, $retrieval['items']);
        self::assertSame('reference_only', $retrieval['items'][0]['usage_policy']);
        self::assertSame('global_system', $retrieval['items'][0]['authority']);
    }
}
