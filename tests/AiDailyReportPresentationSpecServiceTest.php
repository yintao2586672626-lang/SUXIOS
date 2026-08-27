<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportPresentationSpecService;
use app\service\AiDailyReportPresentationArtifactService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AiDailyReportPresentationSpecServiceTest extends TestCase
{
    public function testBuildPreservesVerifiedZeroAndSeparatesEvidenceClasses(): void
    {
        $service = new AiDailyReportPresentationSpecService();
        $spec = $service->build($this->trustedReport(), 'owner');

        self::assertSame('suxios.ai_daily_report.presentation_spec.v1', $spec['schema_version']);
        self::assertSame('SUXIOS', $spec['visual_system']['brand']);
        self::assertFalse($spec['visual_system']['external_brand_adopted']);
        self::assertSame(3, $spec['source_report']['tenant_id']);
        self::assertFalse($spec['authorization']['external_write_authorized']);
        self::assertFalse($spec['authorization']['ota_write_authorized']);
        self::assertFalse($spec['authorization']['pms_write_authorized']);
        self::assertSame('not_rendered', $spec['render_contract']['html']['status']);
        self::assertSame('not_rendered', $spec['render_contract']['pptx']['status']);
        self::assertSame('pass', $spec['qa']['spec_validation_status']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $spec['spec_fingerprint']);

        $byId = [];
        foreach ($spec['evidence_ledger'] as $item) {
            $byId[$item['id']] = $item;
        }
        self::assertSame('VERIFIED_FACT', $byId['F-01']['class']);
        self::assertSame(0, $byId['F-01']['value']);
        self::assertSame('UNKNOWN', $byId['F-02']['class']);
        self::assertNull($byId['F-02']['value']);
        self::assertSame('UNKNOWN', $byId['F-03']['class']);
        self::assertNull($byId['F-03']['value']);
        self::assertSame('DERIVED_METRIC', $byId['D-01']['class']);
        self::assertSame('UNKNOWN', $byId['J-01']['class']);
        self::assertSame('hypothesis_review_required', $byId['J-01']['status']);
        self::assertFalse($byId['J-01']['raw_text_republished']);
        self::assertFalse($byId['J-01']['causality_claimed']);
        self::assertSame('ACTION_RECOMMENDATION', $byId['R-01']['class']);
        self::assertFalse($byId['R-01']['execution_authorized']);
        self::assertFalse($byId['R-01']['external_write_authorized']);
        self::assertSame('UNKNOWN', $byId['U-01']['class']);
        self::assertNull($byId['U-01']['value']);

        self::assertSame($spec, $service->build($this->trustedReport(), 'owner'));
        self::assertStringNotContainsString(
            'JHIRA',
            json_encode($spec['visual_system'], JSON_UNESCAPED_UNICODE)
        );
        foreach ($spec['slides'] as $slide) {
            self::assertNotSame('', trim((string)($slide['title'] ?? '')));
            self::assertLessThanOrEqual(5, count((array)($slide['evidence_ids'] ?? [])));
        }
    }

    public function testUnverifiedSourceNeverBecomesFactOrDefaultZero(): void
    {
        $report = $this->trustedReport();
        $report['source_refs'][0]['readback_verified'] = false;
        $report['source_refs'][0]['persistence']['readback_verified'] = false;

        $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
        $byId = [];
        foreach ($spec['evidence_ledger'] as $item) {
            $byId[$item['id']] = $item;
        }

        self::assertSame('UNKNOWN', $byId['F-01']['class']);
        self::assertNull($byId['F-01']['value']);
        self::assertSame('UNKNOWN', $byId['D-01']['class']);
        self::assertNull($byId['D-01']['value']);
        self::assertSame('unverified', $spec['deck']['data_status']);
        self::assertSame('unverified', $spec['qa']['source_readback_status']);
    }

    public function testTrainingAudienceRemovesHotelIdentityAndSourceKeys(): void
    {
        $report = $this->trustedReport();
        $report['summary'] = '2026-08-22 店长确认先检查首图。';
        $report['result_layers']['human_judgments'] = [[
            'target_type' => 'overall',
            'decision' => 'accepted',
            'comment' => '店长张三确认执行',
        ]];
        $report['data_gaps'][0]['source_ref'] = 'private-row#2026-08-22';
        $spec = (new AiDailyReportPresentationSpecService())->build($report, 'training');

        self::assertNull($spec['source_report']['report_id']);
        self::assertNull($spec['source_report']['tenant_id']);
        self::assertNull($spec['source_report']['hotel_id']);
        self::assertNull($spec['source_report']['business_date']);
        self::assertSame(
            'identity_fields_removed_content_review_required',
            $spec['source_report']['anonymization_status']
        );
        self::assertStringStartsWith('source#', $spec['evidence_ledger'][0]['source_refs'][0]);
        $encoded = json_encode($spec, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('online_daily_data#99', $encoded);
        self::assertStringNotContainsString('private-row', $encoded);
        self::assertStringNotContainsString('2026-08-22', $encoded);
        self::assertStringNotContainsString('张三', $encoded);
        self::assertNotContains(
            'HUMAN_DECISION',
            array_column($spec['evidence_ledger'], 'class')
        );
    }

    public function testVerifiedSourceIdentityAndMetricCoverageMustBothMatch(): void
    {
        foreach (['data_source_id', 'hotel_id', 'data_date', 'quality_status'] as $missingField) {
            $report = $this->trustedReport();
            if ($missingField === 'hotel_id') {
                $report['source_refs'][0]['hotel_id'] = 999;
            } elseif ($missingField === 'data_date') {
                $report['source_refs'][0]['data_date'] = '2026-08-21';
            } elseif ($missingField === 'quality_status') {
                unset($report['source_refs'][0]['quality_status']);
            } else {
                unset($report['source_refs'][0]['data_source_id']);
            }
            $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
            self::assertSame('UNKNOWN', $spec['evidence_ledger'][0]['class'], $missingField);
            self::assertSame([], $spec['evidence_ledger'][0]['source_refs'], $missingField);
        }

        $report = $this->trustedReport();
        $report['source_refs'][] = [
            'key' => 'online_daily_data#100',
            'platform' => 'meituan',
            'data_source_id' => 100,
            'hotel_id' => 7,
            'data_date' => '2026-08-22',
            'quality_status' => 'ok',
            'readback_verified' => true,
            'metric_keys' => ['revenue'],
        ];
        $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
        self::assertSame(['online_daily_data#99'], $spec['evidence_ledger'][0]['source_refs']);
        $byId = array_column($spec['evidence_ledger'], null, 'id');
        self::assertSame('同口径竞品样本待补齐', $byId['U-01']['label']);
        self::assertSame('competitor_same_scope_missing', $byId['U-01']['gap_code']);
    }

    public function testUnverifiedSummaryCausalProseAndIncompleteHumanDecisionCannotBypassLedger(): void
    {
        $report = $this->trustedReport();
        $report['summary'] = '全酒店营收下降50%，因为房价过高。';
        $report['result_layers']['anomaly_signals'][0]['message'] = '房价高导致转化下降。';
        $report['result_layers']['ai_assistance']['summary'] = '因为房价高所以订单下降。';
        $report['result_layers']['human_judgments'] = [[
            'target_type' => 'overall',
            'decision' => '',
            'comment' => '没有正式决定',
        ], [
            'id' => 'judgment-1',
            'target_type' => 'overall',
            'decision' => 'accepted',
            'comment' => '已核对结构化事实',
            'user_id' => 9,
            'recorded_at' => '2026-08-24 01:02:03',
        ]];

        $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
        $encoded = json_encode($spec, JSON_UNESCAPED_UNICODE);
        $byId = array_column($spec['evidence_ledger'], null, 'id');

        self::assertStringNotContainsString('全酒店营收下降50%', $encoded);
        self::assertStringStartsWith('已验证事实', $spec['deck']['summary']);
        self::assertSame($spec['deck']['summary'], $spec['slides'][1]['message']);
        self::assertSame('UNKNOWN', $byId['J-01']['class']);
        self::assertSame('hypothesis_review_required', $byId['J-01']['status']);
        self::assertStringNotContainsString('房价高导致转化下降', $byId['J-01']['statement']);
        self::assertFalse($byId['J-01']['raw_text_republished']);
        self::assertSame('UNKNOWN', $byId['J-AI-01']['class']);
        self::assertSame('hypothesis_review_required', $byId['J-AI-01']['status']);
        self::assertStringNotContainsString('因为房价高所以订单下降', $byId['J-AI-01']['statement']);
        self::assertFalse($byId['J-AI-01']['raw_text_republished']);
        self::assertCount(1, array_filter(
            $spec['evidence_ledger'],
            static fn(array $item): bool => ($item['class'] ?? '') === 'HUMAN_DECISION'
        ));
        self::assertSame('accepted：已核对结构化事实', $byId['H-01']['statement']);
    }

    public function testAllFreeTextInterpretationsRemainUnknownWithoutKeywordDependence(): void
    {
        $phrases = [
            '房价高导致转化下降。',
            '转化下降源于房价过高。',
            '受房价影响，转化持续下降。',
            '房价过高使订单减少。',
            '房价拖累了渠道转化。',
            '流量漏斗表现良好。',
        ];

        $signalFingerprints = [];
        foreach ($phrases as $phrase) {
            $report = $this->trustedReport();
            $report['result_layers']['anomaly_signals'][0]['message'] = $phrase;
            $report['result_layers']['ai_assistance']['summary'] = $phrase;
            $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
            $byId = array_column($spec['evidence_ledger'], null, 'id');
            foreach (['J-01', 'J-AI-01'] as $evidenceId) {
                self::assertSame('UNKNOWN', $byId[$evidenceId]['class'], $phrase . ':' . $evidenceId);
                self::assertSame('hypothesis_review_required', $byId[$evidenceId]['status'], $phrase . ':' . $evidenceId);
                self::assertFalse($byId[$evidenceId]['raw_text_republished'], $phrase . ':' . $evidenceId);
                self::assertFalse($byId[$evidenceId]['causality_claimed'], $phrase . ':' . $evidenceId);
                self::assertStringNotContainsString($phrase, $byId[$evidenceId]['statement'], $evidenceId);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $byId[$evidenceId]['raw_review_sha256'], $evidenceId);
            }
            self::assertSame(hash('sha256', $phrase), $byId['J-AI-01']['raw_review_sha256']);
            $signalFingerprints[] = $byId['J-01']['raw_review_sha256'];
        }
        self::assertCount(count($phrases), array_unique($signalFingerprints));
    }

    public function testSignalMetadataCannotRepublishFreeTextThroughAnyLabelField(): void
    {
        $rawFields = ['type', 'code', 'key', 'label', 'name', 'evidence', 'message', 'description'];
        $fingerprints = [];
        foreach ($rawFields as $field) {
            $phrase = '房价驱动订单下降-' . $field;
            $report = $this->trustedReport();
            $signal = [
                'type' => 'custom_signal',
                'reference_basis' => ['status' => 'available'],
                $field => $phrase,
            ];
            $report['result_layers']['anomaly_signals'] = [$signal];
            $spec = (new AiDailyReportPresentationSpecService())->build($report, 'owner');
            $byId = array_column($spec['evidence_ledger'], null, 'id');
            $signalEvidence = $byId['J-01'];

            self::assertSame('异常信号 1', $signalEvidence['label'], $field);
            self::assertSame('UNKNOWN', $signalEvidence['class'], $field);
            self::assertSame('hypothesis_review_required', $signalEvidence['status'], $field);
            self::assertFalse($signalEvidence['raw_text_republished'], $field);
            self::assertStringNotContainsString($phrase, json_encode($spec, JSON_UNESCAPED_UNICODE), $field);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signalEvidence['raw_review_sha256'], $field);
            $fingerprints[] = $signalEvidence['raw_review_sha256'];
        }
        self::assertCount(count($rawFields), array_unique($fingerprints));
    }

    public function testPresentationReadPathsRequirePositiveTenantScopeBeforeQuerying(): void
    {
        $specService = new AiDailyReportPresentationSpecService();
        try {
            $specService->readLatest(42, [7], 0, 'owner');
            self::fail('spec read must reject a missing tenant scope');
        } catch (InvalidArgumentException $error) {
            self::assertSame('presentation tenant scope is required', $error->getMessage());
        }

        $artifactService = new AiDailyReportPresentationArtifactService();
        try {
            $artifactService->readLatest(42, [7], 0, 'owner', false, 1, str_repeat('a', 64));
            self::fail('artifact latest read must reject a missing tenant scope');
        } catch (InvalidArgumentException $error) {
            self::assertSame('presentation tenant scope is required', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('presentation tenant scope is required');
        $artifactService->readExact(42, 9, [7], 0, false);
    }

    public function testArtifactLatestKeepsLegacyIncludeBundlePositionAndFailsClosedWithoutSpecIdentity(): void
    {
        $service = new AiDailyReportPresentationArtifactService();
        $method = new \ReflectionMethod($service, 'readLatest');
        self::assertSame([
            'reportId',
            'hotelIds',
            'tenantId',
            'audience',
            'includeBundle',
            'presentationSpecId',
            'expectedSpecFingerprint',
        ], array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters()
        ));

        self::assertNull(
            $service->readLatest(42, [7], 3, 'owner', true),
            'The legacy five-argument call must not throw or fall back to an artifact from an older spec.'
        );
    }

    public function testInvalidAudienceFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AiDailyReportPresentationSpecService())->build($this->trustedReport(), 'public');
    }

    public function testRoutesAndStorageMigrationsExposeVerifiedArtifactLoop(): void
    {
        $route = (string)file_get_contents(__DIR__ . '/../route/app.php');
        $controller = (string)file_get_contents(__DIR__ . '/../app/controller/AiDailyReport.php');
        $artifactServiceSource = (string)file_get_contents(
            __DIR__ . '/../app/service/AiDailyReportPresentationArtifactService.php'
        );
        $migration = (string)file_get_contents(
            __DIR__ . '/../database/migrations/20260823_zz_create_ai_report_presentation_specs.sql'
        );
        $artifactMigration = (string)file_get_contents(
            __DIR__ . '/../database/migrations/20260823_zzzz_create_ai_report_presentation_artifacts.sql'
        );
        $artifactStatusMigration = (string)file_get_contents(
            __DIR__ . '/../database/migrations/20260824_a_refine_ai_report_presentation_artifact_readback_status.sql'
        );

        self::assertStringContainsString("Route::post('/:id/presentation-spec', 'AiDailyReport/savePresentationSpec')", $route);
        self::assertStringContainsString("Route::get('/:id/presentation-spec', 'AiDailyReport/presentationSpec')", $route);
        self::assertStringContainsString("Route::post('/:id/presentation-artifacts', 'AiDailyReport/savePresentationArtifact')", $route);
        self::assertStringContainsString("Route::get('/:id/presentation-artifacts', 'AiDailyReport/presentationArtifact')", $route);
        self::assertStringContainsString("Route::get('/:id/presentation-artifacts/:artifactId', 'AiDailyReport/presentationArtifactById')", $route);
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/AiDailyReport.php');
        $delivery = (string)file_get_contents(
            dirname(__DIR__) . '/public/components/system/ai-daily-report-delivery.js'
        );
        self::assertStringContainsString("'status' => 'not_generated'", $controller);
        self::assertStringContainsString('presentation_spec_not_generated', $controller);
        self::assertStringContainsString('presentation_artifact_not_generated', $controller);
        self::assertStringContainsString("artifact.status === 'not_generated'", $delivery);
        self::assertStringContainsString('saveAndReadback(', $controller);
        self::assertStringContainsString('readLatest(', $controller);
        self::assertStringContainsString('presentationSpecService->readLatest', $controller);
        self::assertStringContainsString("->where('presentation_spec_id', \$presentationSpecId)", $artifactServiceSource);
        self::assertStringContainsString("->where('spec_fingerprint', \$expectedSpecFingerprint)", $artifactServiceSource);
        self::assertStringContainsString("'report.export'", $controller);
        self::assertStringContainsString('`spec_fingerprint` CHAR(64) NOT NULL', $migration);
        self::assertStringContainsString('`spec_json` JSON NOT NULL', $migration);
        self::assertStringContainsString("`render_status` VARCHAR(30) NOT NULL DEFAULT 'not_rendered'", $migration);
        self::assertStringContainsString('`artifact_blob` MEDIUMBLOB NOT NULL', $artifactMigration);
        self::assertStringContainsString('`content_sha256` CHAR(64) NOT NULL', $artifactMigration);
        self::assertStringContainsString('`presentation_spec_id` BIGINT UNSIGNED NOT NULL', $artifactMigration);
        self::assertStringContainsString("DEFAULT 'rendered_pending_readback'", $artifactStatusMigration);
        self::assertStringNotContainsString('DELETE FROM', $migration);
        self::assertStringNotContainsString('DELETE FROM', $artifactMigration);
        self::assertStringNotContainsString('DELETE FROM', $artifactStatusMigration);
    }

    /** @return array<string,mixed> */
    private function trustedReport(): array
    {
        return [
            'id' => 42,
            'tenant_id' => 3,
            'hotel_id' => 7,
            'report_date' => '2026-08-22',
            'summary' => '昨日OTA证据已回读；先处理曝光到详情环节。',
            'result_contract' => [
                'result_version' => str_repeat('a', 64),
                'metric_version' => 'ai_daily_report_metric.v1',
                'reference_version' => str_repeat('b', 64),
                'boundary' => 'OTA渠道事实不扩大为全酒店财务结论。',
            ],
            'source_refs' => [[
                'key' => 'online_daily_data#99',
                'platform' => 'ctrip',
                'data_source_id' => 99,
                'hotel_id' => 7,
                'data_date' => '2026-08-22',
                'quality_status' => 'ok',
                'readback_verified' => true,
                'persistence' => ['readback_verified' => true],
                'metric_keys' => ['book_order_num', 'flow_rate'],
                'field_fact_metric_keys' => ['order_count'],
            ]],
            'result_layers' => [
                'source_facts' => [[
                    'key' => 'orders',
                    'label' => 'OTA订单',
                    'value' => 0,
                    'unit' => '单',
                    'data_status' => 'available',
                    'metric_scope' => 'ota_channel',
                ], [
                    'key' => 'whole_hotel_revenue',
                    'label' => '全酒店营收',
                    'value' => 99999,
                    'unit' => '元',
                    'data_status' => 'available',
                    'metric_scope' => 'whole_hotel_daily_report',
                ], [
                    'key' => 'unsupported_fact',
                    'label' => '未覆盖OTA指标',
                    'value' => 88,
                    'unit' => '',
                    'data_status' => 'available',
                    'metric_scope' => 'ota_channel',
                ]],
                'derived_metrics' => [[
                    'key' => 'flow_rate',
                    'label' => '曝光到详情率',
                    'value' => 12.5,
                    'unit' => '%',
                    'metric_scope' => 'ota_channel',
                ]],
                'anomaly_signals' => [[
                    'label' => '曝光到详情信号',
                    'message' => '相对声明参考区间偏低，需继续核验素材与流量结构。',
                    'reference_basis' => ['status' => 'available'],
                ]],
                'ai_assistance' => [
                    'status' => 'available',
                    'summary' => '可能与图片信息清晰度有关，不能据此确认根因。',
                ],
                'human_judgments' => [],
            ],
            'recommended_actions' => [[
                'title' => '复核首图信息',
                'action' => '人工核对首图是否准确表达酒店类型和核心卖点。',
                'status' => 'pending_approval',
            ]],
            'data_gaps' => [[
                'code' => 'competitor_same_scope_missing',
                'message' => '缺少同商圈同口径竞品样本。',
            ]],
        ];
    }
}
