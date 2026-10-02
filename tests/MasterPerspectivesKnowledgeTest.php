<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

final class MasterPerspectivesKnowledgeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $this->root = $root;
    }

    public function testSourceManifestPreservesAllPackageFingerprintsWithoutInstallingSkills(): void
    {
        $manifest = $this->json('docs/knowledge/master-perspectives/source-manifest.json');
        self::assertSame('suxios.master_perspectives.source_manifest.v1', $manifest['schema_version']);
        self::assertSame('32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970', $manifest['outer_zip_sha256']);
        self::assertSame(165, $manifest['nested_archive_count']);
        self::assertCount(18, $manifest['category_counts']);
        self::assertSame(165, array_sum(array_column($manifest['category_counts'], 'count')));
        self::assertSame(
            ['invalid' => 5, 'previewed' => 150, 'review_required' => 10],
            $manifest['static_review']['preview_status_counts']
        );
        self::assertSame(0, $manifest['static_review']['unsafe_path_count']);
        self::assertSame(0, $manifest['static_review']['symlink_count']);
        self::assertSame(0, $manifest['static_review']['script_file_count']);
        self::assertSame(0, $manifest['static_review']['requested_tool_permission_count']);
        self::assertSame(['MIT' => 165], $manifest['static_review']['license_spdx_counts']);
        self::assertSame(165, $manifest['static_review']['bundled_git_history_package_count']);
        self::assertSame('not_performed', $manifest['provenance_boundary']['live_repository_refresh']);
        self::assertSame('untrusted_source_authored_synthesis', $manifest['provenance_boundary']['persona_claims']);

        $items = $manifest['items'];
        self::assertCount(165, $items);
        self::assertCount(165, array_unique(array_column($items, 'archive_sha256')));
        self::assertCount(165, array_unique(array_column($items, 'skill_sha256')));
        self::assertCount(165, array_unique(array_column($items, 'embedded_repository_url')));
        self::assertCount(165, array_unique(array_column($items, 'embedded_commit')));

        $invalidNames = [];
        foreach ($items as $item) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['archive_sha256']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $item['skill_sha256']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $item['embedded_commit']);
            self::assertStringStartsWith('https://github.com/', $item['embedded_repository_url']);
            self::assertSame('MIT', $item['license_spdx']);
            self::assertSame([], $item['static_preview']['script_files']);
            self::assertSame([], $item['static_preview']['requested_tool_permissions']);
            self::assertSame('not_installed_reference_only', $item['install_status']);
            self::assertFalse($item['source_runtime_verified']);
            if ($item['static_preview']['status'] === 'invalid') {
                $invalidNames[] = $item['display_name'];
            }
        }
        self::assertEqualsCanonicalizing(['朱熹', '鬼谷子', '诸葛亮', '苏东坡', '王夫之'], $invalidNames);

        $raw = (string)file_get_contents($this->root . '/docs/knowledge/master-perspectives/source-manifest.json');
        self::assertStringNotContainsString('F:/wx/', $raw);
        self::assertStringNotContainsString('F:\\wx\\', $raw);
        self::assertStringNotContainsString('xwechat_files', $raw);
    }

    public function testMethodPackKeepsEveryLensDormantAndTraceable(): void
    {
        $pack = $this->json('docs/knowledge/master-perspectives/method-pack.json');
        self::assertSame('suxios.master_perspectives.method_pack.v1', $pack['schema_version']);
        self::assertSame(165, $pack['entry_count']);
        self::assertSame('reference_only', $pack['usage_policy']['mode']);
        self::assertSame(5, $pack['usage_policy']['selection_limit']);
        self::assertSame('do_not_impersonate_or_claim_authentic_person_view', $pack['usage_policy']['persona_policy']);

        $keys = [];
        foreach ($pack['entries'] as $entry) {
            $key = $entry['category_code'] . ':' . $entry['item_index'];
            self::assertArrayNotHasKey($key, $keys);
            $keys[$key] = true;
            self::assertNotSame('', trim((string)$entry['display_name']));
            self::assertNotSame('', trim((string)$entry['description']));
            self::assertNotEmpty($entry['core_ideas']);
            self::assertNotEmpty($entry['use_cases']);
            self::assertNotEmpty($entry['source_trigger_examples']);
            self::assertSame('reference_lens_only', $entry['perspective_role']);
            self::assertFalse($entry['activation_allowed']);
            self::assertSame('source_package_synthesis_unverified', $entry['authenticity_status']);
            self::assertStringStartsWith('embedded-git://https://github.com/', $entry['source_ref']);
        }
        self::assertCount(165, $keys);
    }

    public function testIntegratedModelSelectsFiniteEvidenceFirstPanelAndFailsClosed(): void
    {
        $model = $this->json('docs/knowledge/master-perspectives/integrated-model.json');
        $pack = $this->json('docs/knowledge/master-perspectives/method-pack.json');
        $skillNames = array_fill_keys(array_column($pack['entries'], 'skill_name'), true);

        self::assertSame('suxios.hotel_operating_multi_lens_review.v1', $model['model_key']);
        self::assertSame('none_of_165_skills_installed', $model['source_assessment']['installation_status']);
        self::assertCount(7, $model['lens_domains']);
        self::assertSame(2, $model['panel_selection_contract']['minimum_lenses']);
        self::assertSame(5, $model['panel_selection_contract']['maximum_lenses']);
        self::assertContains('evidence_and_uncertainty', $model['panel_selection_contract']['required_domains_for_operating_diagnosis']);

        foreach ($model['lens_domains'] as $domain) {
            self::assertNotSame('', trim((string)$domain['business_question']));
            self::assertCount(4, $domain['source_lenses']);
            foreach ($domain['source_lenses'] as $lens) {
                self::assertArrayHasKey($lens['skill_name'], $skillNames);
                self::assertNotSame('', trim((string)$lens['adapted_probe']));
            }
        }

        self::assertSame('C', $model['boundaries']['evidence_grade']);
        self::assertFalse($model['boundaries']['decision_safe']);
        self::assertFalse($model['boundaries']['task_draft_safe']);
        self::assertFalse($model['boundaries']['contains_current_hotel_fact']);
        self::assertFalse($model['boundaries']['contains_current_ota_fact']);
        self::assertFalse($model['boundaries']['external_write_authorized']);
        self::assertContains('automatic_pricing', $model['boundaries']['blocked_uses']);
        self::assertContains('automatic_ota_or_pms_write', $model['boundaries']['blocked_uses']);
        self::assertContains('unverified_historical_quote', $model['boundaries']['blocked_uses']);

        $cases = array_column($model['golden_cases'], null, 'case_id');
        self::assertSame('pending_approval', $cases['synthetic_ctrip_exposure_drop']['expected']['status']);
        self::assertContains('causal_effect_confirmed', $cases['synthetic_ctrip_exposure_drop']['expected']['must_not_claim']);
        self::assertSame('not_ready', $cases['missing_hotel_and_date']['expected']['status']);
        self::assertNull($cases['missing_hotel_and_date']['expected']['smallest_action_draft']);
        self::assertTrue($cases['lens_disagreement_without_decisive_evidence']['expected']['disagreement_preserved']);
        self::assertFalse($cases['lens_disagreement_without_decisive_evidence']['expected']['causality_claimed']);
    }

    public function testMigrationIsIdempotentTraceableAndMirroredToExistingKnowledgeEntry(): void
    {
        $migrationPath = $this->root . '/database/migrations/20260820_b_absorb_master_perspectives_multi_lens_knowledge.sql';
        self::assertFileExists($migrationPath);
        $migration = (string)file_get_contents($migrationPath);

        foreach (['source-manifest.json', 'method-pack.json', 'integrated-model.json'] as $name) {
            $path = $this->root . '/docs/knowledge/master-perspectives/' . $name;
            self::assertStringContainsString(strtoupper(hash_file('sha256', $path)), $migration);
        }
        foreach ([
            '32C06DE45983119EFD6F7CFA9B1E8CA5CE59F8A4E5339267DC383A5FC0EE3970',
            '酒店经营多视角审视与反证方法',
            "'nested_skill_count', 165",
            "'static_preview_counts', JSON_OBJECT('previewed', 150, 'review_required', 10, 'invalid', 5)",
            "'$.decision_policy', 'reference_only_human_review'",
            "'$.decision_safe', false",
            "'$.task_draft_safe', false",
            "'$.external_write_authorized', false",
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }
        foreach ([
            'master_perspective_source_scope_reference',
            'multi_lens_evidence_and_uncertainty',
            'multi_lens_customer_and_value',
            'multi_lens_competition_and_strategy',
            'multi_lens_operations_and_execution',
            'multi_lens_risk_and_resilience',
            'multi_lens_communication_and_alignment',
            'multi_lens_ethics_and_fairness',
            'multi_lens_selection_and_disagreement_contract',
            'multi_lens_hotel_review_workflow',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }
        self::assertSame(10, substr_count($migration, 'INSERT INTO `tmp_master_lens_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('F:/wx/', $migration);
        self::assertStringNotContainsString('F:\\wx\\', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
    }

    public function testOperatingQuestionRetrievesTheMethodAsReferenceOnlyAndRejectsMissingTraceability(): void
    {
        $unit = [
            'unit_id' => 820,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '酒店经营多视角审视与反证方法',
            'description' => '经营诊断 多视角 反证 客户价值',
            'source' => 'revenue_operations_decision_support',
            'status' => 'done',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-20 00:00:00',
            'review_due_at' => '2027-02-16 00:00:00',
        ];
        $content = [
            'scope' => 'global_methodology_reference',
            'evidence_level' => 'adapted_reference_method',
            'evidence_grade' => 'C',
            'source_refs' => ['repo-doc://integrated-model#sha256=4C86CD42'],
            'platforms' => ['ctrip', 'meituan', 'suxios_internal'],
            'search_terms' => ['携程', '曝光', '经营诊断', '多视角', '反证'],
            'steps' => ['先锁定酒店、平台、日期、指标、来源和回读状态。'],
            'reviewed_at' => '2026-08-20 00:00:00',
            'review_due_at' => '2027-02-16 00:00:00',
            'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_ota_write'],
            'external_write_authorized' => false,
        ];
        $chunk = [
            'chunk_id' => 8201,
            'unit_id' => 820,
            'type' => 'multi_lens_hotel_review_workflow',
            'lifecycle_status' => 'active',
            'superseded_by_chunk_id' => 0,
            'content' => $content,
        ];

        $result = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows([$unit], [$chunk], [
            'hotel_id' => 80,
            'user_id' => 7,
            'platform' => 'ctrip',
            'question' => '携程曝光下降，怎么做多视角经营诊断和反证？',
        ]);
        self::assertSame('matched', $result['status']);
        self::assertSame(1, $result['returned_count']);
        self::assertSame('reference_only', $result['items'][0]['usage_policy']);
        self::assertSame('C', $result['items'][0]['evidence_grade']);

        $gate = (new KnowledgeDecisionGateService())->assess($unit, $content, '2026-08-20 12:00:00');
        self::assertSame('reference_only', $gate['status']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);

        $chunk['content']['source_refs'] = [];
        $blocked = (new OperatingQuestionKnowledgeRetrievalService())->buildFromRows([$unit], [$chunk], [
            'hotel_id' => 80,
            'user_id' => 7,
            'platform' => 'ctrip',
            'question' => '携程曝光下降怎么诊断？',
        ]);
        self::assertSame('no_match', $blocked['status']);
        self::assertSame([], $blocked['items']);
        self::assertGreaterThanOrEqual(1, $blocked['excluded_count']);
    }

    /** @return array<string,mixed> */
    private function json(string $relativePath): array
    {
        $path = $this->root . '/' . $relativePath;
        self::assertFileExists($path);
        $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
