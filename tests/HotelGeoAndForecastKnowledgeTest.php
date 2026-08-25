<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class HotelGeoAndForecastKnowledgeTest extends TestCase
{
    private const GEO_MIGRATION = __DIR__ . '/../database/migrations/20260820_seed_hotel_geo_operations_reference.sql';
    private const FORECAST_MIGRATION = __DIR__ . '/../database/migrations/20260820_seed_occupancy_forecast_architecture_reference.sql';

    public function testGeoMigrationPreservesEveryGeoSourceAndEightReferenceContracts(): void
    {
        $sql = (string)file_get_contents(self::GEO_MIGRATION);

        foreach ([
            'F94BD6B830A4D217FDFE21EDEE27699D3964F3C16680FCB9E9F29A52D91B8871',
            'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68',
            '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C',
            'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059',
            'DD4CD3AEB68B57B19920DFAB64076F844C4CF458CB78941F4D9E8F696A7300B7',
            '9EBB6661A4EA9A0174E11A06CECDBCACB040C1AFEB3A8C0E85252F6A454CAC50',
            '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB',
            'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6',
            '258FE4D2619546877528C55A8B833639513ADBDD5918FFDA615825AB1DCB51C2',
            '86F34F57EF697A958DBA476E71A950A7F7D4E04721E5AB41F94FE771719449D0',
            '6A9002CD8A2196358D26F3DB95863BABB2582DF9C2A15FF0A258ECA5FF262E96',
            '9ECEF2A686D28188541D36A62A4E66173500A60CEB8CEE8E2A625F791D51F99A',
            'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5',
            '4A279F24EF53DC96CA8329F454DF515993F447B38C60AC1C1EF19BB87C599872',
            '4189E993E437AD8DBA2141D9AC85E190E90E97D878BC6DB1C50E59EFC30E04C3',
        ] as $sha256) {
            self::assertStringContainsString($sha256, $sql);
        }

        self::assertSame(8, substr_count($sql, "INSERT INTO `tmp_geo_reference_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_units`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_chunks`"));
        self::assertSame(1, substr_count($sql, "INSERT INTO `knowledge_base`"));
        self::assertStringContainsString("'material_count', 15", $sql);
        self::assertStringContainsString('static_inventory_and_supported_document_read_only_no_member_execution', $sql);
        self::assertStringContainsString('document_instructions_are_reference_material_not_agent_commands', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_base`', $sql);
    }

    public function testForecastMigrationPreservesH03EvidenceAndFiveGuardedContracts(): void
    {
        $sql = (string)file_get_contents(self::FORECAST_MIGRATION);

        self::assertStringContainsString(
            '10A79D06003FC10A483A6F70B2A5CD0BF6ED6C05A538CBBD88315E4D9702AFEA',
            $sql
        );
        self::assertSame(5, substr_count($sql, "INSERT INTO `tmp_forecast_reference_chunks`"));
        foreach ([
            'raw_dataset_status',
            'not_provided',
            'backtest_script_status',
            'cross_hotel_validation_status',
            'prohibited_model_shortcut',
            'target_hotel_revalidation_required',
            'automatic_pricing',
            'automatic_channel_closure',
            'automatic_discount_opening',
            'automatic_ota_write',
            'automatic_pms_write',
            'evidence_boundary',
        ] as $marker) {
            self::assertStringContainsString($marker, $sql);
        }
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $sql);
        self::assertStringNotContainsString('DELETE FROM `knowledge_base`', $sql);
    }

    public function testBothKnowledgeFamiliesRemainRetrievableButNotDecisionOrTaskSafe(): void
    {
        $gate = new KnowledgeDecisionGateService();
        $unit = [
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-20 00:00:00',
            'review_due_at' => '2027-02-16 00:00:00',
        ];
        $common = [
            'scope' => 'global_method_reference',
            'evidence_level' => 'user_provided_bundle_reference',
            'evidence_grade' => 'C',
            'source_refs' => ['user-bundle://2026-08-20/source#sha256=test'],
            'requires_current_verification' => true,
            'current_verification_status' => 'not_verified_for_current_hotel',
            'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_ota_write'],
            'lifecycle_status' => 'active',
        ];

        $assessment = $gate->assess($unit, $common, '2026-08-20 12:00:00');
        self::assertSame('reference_only', $assessment['status']);
        self::assertTrue($assessment['retrieval_safe']);
        self::assertFalse($assessment['decision_safe']);
        self::assertFalse($assessment['task_draft_safe']);

        $retrieval = new OperatingQuestionKnowledgeRetrievalService();
        $geo = $retrieval->buildFromRows(
            [[
                'unit_id' => 901,
                'hotel_id' => 0,
                'created_by' => 0,
                'name' => '酒店GEO内容资产与发布审核工作流',
                'description' => '关键词、蒸馏问题、标题任务卡、人工审核与发布授权',
                'source' => 'hotel_geo_operations_reference',
                'status' => 'done',
                'lifecycle_status' => 'active',
            ]],
            [[
                'chunk_id' => 1901,
                'unit_id' => 901,
                'type' => 'geo_stage_gate_workflow_contract',
                'content' => json_encode(array_merge($common, [
                    'workflow' => ['关键词候选', '蒸馏问题', '标题任务卡', '酒店人工审核', 'Gate 6发布授权'],
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            [
                'hotel_id' => 80,
                'user_id' => 0,
                'platform' => '',
                'question' => '酒店GEO关键词标题和发布如何审核',
            ]
        );
        self::assertSame('matched', $geo['status']);
        self::assertSame('reference_only', $geo['items'][0]['usage_policy']);
        self::assertSame('global_system', $geo['items'][0]['authority']);

        $forecast = $retrieval->buildFromRows(
            [[
                'unit_id' => 902,
                'hotel_id' => 0,
                'created_by' => 0,
                'name' => '出租率预测引擎架构 v2 H03历史回测参考',
                'description' => '滚动回归、往年相关性门控、walk-forward和漂移监控',
                'source' => 'occupancy_forecast_architecture_reference',
                'status' => 'done',
                'lifecycle_status' => 'active',
            ]],
            [[
                'chunk_id' => 1902,
                'unit_id' => 902,
                'type' => 'occupancy_forecast_model_contract',
                'content' => json_encode(array_merge($common, [
                    'scope' => 'single_hotel_h03_historical_backtest_reference',
                    'method' => '分星期分时刻滚动回归，往年相关性门控，walk-forward回测和漂移监控',
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            [
                'hotel_id' => 80,
                'user_id' => 0,
                'platform' => '',
                'question' => '出租率预测怎样使用滚动回归和往年相关性门控并监测漂移',
            ]
        );
        self::assertSame('matched', $forecast['status']);
        self::assertSame('reference_only', $forecast['items'][0]['usage_policy']);
        self::assertSame('global_system', $forecast['items'][0]['authority']);
    }
}
