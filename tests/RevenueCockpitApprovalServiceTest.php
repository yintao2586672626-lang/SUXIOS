<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitApprovalService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RevenueCockpitApprovalServiceTest extends TestCase
{
    public function testAllOtaApprovalUsesSeparateVerifiedSourceRefsAndStopsBeforeExecution(): void
    {
        $captured = [];
        $service = new RevenueCockpitApprovalService(
            static function (
                int $tenantId,
                int $hotelId,
                string $businessDate,
                int $actorId,
                array $refs
            ) use (&$captured): array {
                $captured = compact('tenantId', 'hotelId', 'businessDate', 'actorId', 'refs');
                return [
                    'status' => 'pending_approval',
                    'execution_intent' => ['id' => 91, 'status' => 'pending_approval', 'tasks' => []],
                    'persistence_status' => 'readback_verified',
                    'execution_task_created' => false,
                    'execution_task_count' => 0,
                    'external_action_triggered' => false,
                    'reused_existing_intent' => false,
                ];
            }
        );

        $result = $service->createFromOverview(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'all_ota',
            7
        );

        self::assertSame('pending_approval', $result['status']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertFalse($result['execution_task_created']);
        self::assertFalse($result['external_action_triggered']);
        self::assertSame(
            ['ctrip', 'ctrip', 'meituan', 'meituan', 'dingdandao_pms'],
            array_column($captured['refs'], 'platform')
        );
        self::assertSame(
            [[101, 102], [101, 102], [201], [201], [301]],
            array_column($captured['refs'], 'row_ids')
        );
        self::assertSame(
            ['ota_channel', 'ota_current_collection_receipt', 'ota_channel', 'ota_current_collection_receipt', 'whole_hotel_accommodation'],
            array_column($captured['refs'], 'fact_scope')
        );
        self::assertTrue($result['boundaries']['human_approval_required']);
        self::assertFalse($result['boundaries']['automatic_execution']);
        self::assertFalse($result['boundaries']['ota_write']);
    }

    public function testAllOtaApprovalRejectsMissingSecondPlatformReadback(): void
    {
        $overview = $this->overview();
        $overview['three_source_fact_layer']['sources']['meituan_ota']['data_status'] = 'not_verified';
        $service = new RevenueCockpitApprovalService(static fn(): array => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_evidence_not_readback_verified');
        $service->createFromOverview($overview, 10, 20, '2026-08-20', 'all_ota', 7);
    }

    public function testApprovalRejectsRowsThatMissTheCockpitStrictFactGate(): void
    {
        $overview = $this->overview();
        $overview['cockpit_strict_evidence']['platforms']['meituan'] = [
            'source_strict_readback' => false,
            'accepted_row_ids' => [],
            'rejected_row_ids' => [201],
        ];
        $service = new RevenueCockpitApprovalService(static fn(): array => []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_evidence_not_readback_verified');
        $service->createFromOverview($overview, 10, 20, '2026-08-20', 'meituan', 7);
    }

    public function testLatestSameDayCollectionFailureCannotBorrowAnOlderStrictSuccess(): void
    {
        $overview = $this->overview();
        $overview['dual_ota_field_closure']['platforms']['meituan']['status'] = 'partial';
        $overview['dual_ota_field_closure']['platforms']['meituan']['current_collection_blocker_status'] = 'collection_failed';
        $overview['dual_ota_field_closure']['platforms']['meituan']['current_receipt_record_ids'] = [];
        $overview['dual_ota_field_closure']['platforms']['meituan']['revenue_analysis']['status'] = 'blocked';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_current_receipt_not_ready');
        (new RevenueCockpitApprovalService(static fn(): array => []))->createFromOverview(
            $overview,
            10,
            20,
            '2026-08-20',
            'meituan',
            7
        );
    }

    public function testStrictRowsMustBelongToTheCurrentAcceptedReceipt(): void
    {
        $overview = $this->overview();
        $overview['dual_ota_field_closure']['platforms']['meituan']['current_receipt_record_ids'] = [299];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_meituan_current_receipt_not_ready');
        (new RevenueCockpitApprovalService())->evidenceContext(
            $overview,
            10,
            20,
            '2026-08-20',
            'meituan'
        );
    }

    public function testApprovalPreservesSelectedMeituanCloudPmsEvidenceIdentity(): void
    {
        $overview = $this->overview();
        $factLayer =& $overview['three_source_fact_layer'];
        $pms = $factLayer['sources']['dingdandao_pms'];
        unset($factLayer['sources']['dingdandao_pms']);
        $pms['source']['table'] = 'meituan_cloud_pms_captures';
        $pms['source']['provider'] = 'meituan_cloud_pms';
        $factLayer['sources']['meituan_cloud_pms'] = $pms;
        $factLayer['pms_binding'] = [
            'binding_status' => 'configured',
            'effective_provider' => 'meituan_cloud_pms',
        ];
        $capturedRefs = [];
        $service = new RevenueCockpitApprovalService(
            static function (
                int $tenantId,
                int $hotelId,
                string $businessDate,
                int $actorId,
                array $refs
            ) use (&$capturedRefs): array {
                $capturedRefs = $refs;
                return [
                    'status' => 'pending_approval',
                    'execution_intent' => [
                        'id' => 92,
                        'status' => 'pending_approval',
                        'tasks' => [],
                    ],
                    'persistence_status' => 'readback_verified',
                    'execution_task_created' => false,
                    'execution_task_count' => 0,
                    'external_action_triggered' => false,
                    'reused_existing_intent' => false,
                ];
            }
        );

        $result = $service->createFromOverview(
            $overview,
            10,
            20,
            '2026-08-20',
            'all_ota',
            7
        );

        self::assertSame('pending_approval', $result['status']);
        self::assertSame(5, $result['cockpit_scope']['evidence_ref_count']);
        self::assertSame('meituan_cloud_pms', $capturedRefs[4]['platform']);
        self::assertSame(
            'meituan_cloud_pms_captures',
            $capturedRefs[4]['table']
        );
        self::assertNotContains(
            'dingdandao_pms',
            array_column($capturedRefs, 'platform')
        );
    }

    public function testApprovalDropsPmsEvidenceWhenItsScopeOrProviderIdentityMismatches(): void
    {
        $variants = [
            'cross_tenant' => ['tenant_id', 99],
            'cross_hotel' => ['system_hotel_id', 99],
            'wrong_provider' => ['provider', 'meituan_cloud_pms'],
        ];
        foreach ($variants as $case => [$field, $value]) {
            $overview = $this->overview();
            $overview['three_source_fact_layer']['sources']['dingdandao_pms']
                ['source'][$field] = $value;
            $capturedRefs = [];
            $service = new RevenueCockpitApprovalService(
                static function (
                    int $tenantId,
                    int $hotelId,
                    string $businessDate,
                    int $actorId,
                    array $refs
                ) use (&$capturedRefs): array {
                    $capturedRefs = $refs;
                    return [
                        'status' => 'pending_approval',
                        'execution_intent' => [
                            'id' => 93,
                            'status' => 'pending_approval',
                            'tasks' => [],
                        ],
                        'persistence_status' => 'readback_verified',
                        'execution_task_created' => false,
                        'execution_task_count' => 0,
                        'external_action_triggered' => false,
                        'reused_existing_intent' => false,
                    ];
                }
            );

            $result = $service->createFromOverview(
                $overview,
                10,
                20,
                '2026-08-20',
                'all_ota',
                7
            );

            self::assertSame(4, $result['cockpit_scope']['evidence_ref_count'], $case);
            self::assertNotContains(
                'dingdandao_pms',
                array_column($capturedRefs, 'platform'),
                $case
            );
        }
    }

    public function testApprovalUsesMetricStrictRowsWhenTheSourceSummaryRowIsDifferent(): void
    {
        $overview = $this->overview();
        $overview['three_source_fact_layer']['sources']['meituan_ota']['source']['row_ids'] = [999];
        $service = new RevenueCockpitApprovalService();

        $context = $service->evidenceContext($overview, 10, 20, '2026-08-20', 'meituan');
        $meituanRef = array_values(array_filter(
            $context['evidence_refs'],
            static fn(array $ref): bool => ($ref['platform'] ?? '') === 'meituan'
        ))[0];

        self::assertSame([201], $meituanRef['row_ids']);
        self::assertTrue($meituanRef['readback_verified']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $meituanRef['fact_content_digest']);
    }

    public function testCreateRejectsAReusedLifecycleThatHasAlreadyProgressed(): void
    {
        $service = new RevenueCockpitApprovalService(static fn(): array => [
            'status' => 'approved',
            'execution_intent' => [
                'id' => 91,
                'status' => 'approved',
                'tasks' => [['id' => 501, 'status' => 'pending']],
            ],
            'persistence_status' => 'readback_verified',
            'execution_task_created' => true,
            'execution_task_count' => 1,
            'external_action_triggered' => false,
            'reused_existing_intent' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revenue_cockpit_action_lifecycle_already_progressed');
        $service->createFromOverview($this->overview(), 10, 20, '2026-08-20', 'meituan', 7);
    }

    public function testEvidenceIdentityChangesWhenTheSameSourceRowFactsChange(): void
    {
        $service = new RevenueCockpitApprovalService();
        $before = $service->evidenceContext($this->overview(), 10, 20, '2026-08-20', 'meituan');
        $changedOverview = $this->overview();
        $changedOverview['three_source_fact_layer']['sources']['meituan_ota']['facts']['revenue'] = 601.0;
        $after = $service->evidenceContext($changedOverview, 10, 20, '2026-08-20', 'meituan');

        $beforeRef = array_values(array_filter(
            $before['evidence_refs'],
            static fn(array $ref): bool => ($ref['platform'] ?? '') === 'meituan'
        ))[0];
        $afterRef = array_values(array_filter(
            $after['evidence_refs'],
            static fn(array $ref): bool => ($ref['platform'] ?? '') === 'meituan'
        ))[0];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $beforeRef['fact_content_digest']);
        self::assertNotSame($beforeRef['fact_content_digest'], $afterRef['fact_content_digest']);
    }

    public function testReadFromOverviewRestoresTheExactPersistedLifecycleWithoutWriting(): void
    {
        $captured = [];
        $service = new RevenueCockpitApprovalService(
            null,
            static function (
                int $tenantId,
                int $hotelId,
                string $businessDate,
                array $refs
            ) use (&$captured): ?array {
                $captured = compact('tenantId', 'hotelId', 'businessDate', 'refs');
                return [
                    'id' => 92,
                    'tenant_id' => 10,
                    'hotel_id' => 20,
                    'source_module' => 'operating_loop_approval',
                    'source_record_id' => 201,
                    'platform' => 'hotel_operation',
                    'object_type' => 'operation_checklist',
                    'action_type' => 'human_review_operating_cycle',
                    'date_start' => '2026-08-20',
                    'date_end' => '2026-08-20',
                    'status' => 'approved',
                    'tasks' => [['id' => 501, 'status' => 'pending']],
                ];
            }
        );

        $result = $service->readFromOverview(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'meituan'
        );

        self::assertTrue($result['found']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertSame('approved', $result['status']);
        self::assertSame(92, $result['execution_intent']['id']);
        self::assertSame(1, $result['execution_task_count']);
        self::assertSame(
            ['meituan', 'meituan', 'dingdandao_pms'],
            array_column($captured['refs'], 'platform')
        );
        self::assertSame(
            ['ota_channel', 'ota_current_collection_receipt', 'whole_hotel_accommodation'],
            array_column($captured['refs'], 'fact_scope')
        );
        self::assertTrue($result['boundaries']['read_only']);
        self::assertFalse($result['boundaries']['automatic_approval']);
        self::assertFalse($result['boundaries']['ota_write']);
    }

    public function testReadFromOverviewReportsNotSavedWithoutInventingAnIntent(): void
    {
        $service = new RevenueCockpitApprovalService(null, static fn(): ?array => null);

        $result = $service->readFromOverview(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'meituan'
        );

        self::assertFalse($result['found']);
        self::assertSame('not_saved', $result['status']);
        self::assertSame('not_saved', $result['persistence_status']);
        self::assertNull($result['execution_intent']);
        self::assertSame(0, $result['execution_task_count']);
    }

    public function testSelectedOpportunityBuildsATraceableObservationCardWithoutNumericTarget(): void
    {
        $service = new RevenueCockpitApprovalService();
        $overview = $this->overview();
        $context = $service->evidenceContext($overview, 10, 20, '2026-08-20', 'meituan');
        $context['action_context'] = [
            'opportunity_key' => 'detail_conversion_shortage',
            'action_title' => '详情页转化不足',
            'action_object' => 'revenue_cockpit_opportunity:detail_conversion_shortage',
            'action_description' => '核对列表到详情路径、首图卖点和价格权益。',
            'reason' => '详情曝光已严格回读；当前变化不能证明任何单一原因。',
            'decision_snapshot_id' => 77,
            'decision_snapshot_digest' => str_repeat('a', 64),
            'opportunity_digest' => str_repeat('b', 64),
        ];
        $method = new \ReflectionMethod($service, 'managedActionCard');
        $args = [$overview, &$context, 7];
        /** @var array<string,mixed> $card */
        $card = $method->invokeArgs($service, $args);

        self::assertSame('revenue_cockpit_action', $card['source']['module']);
        self::assertSame('detail_conversion_shortage', $card['trace']['opportunity_key']);
        self::assertSame(77, $card['trace']['decision_snapshot_id']);
        self::assertSame('详情页转化不足', $card['action']['title']);
        self::assertSame('revenue_cockpit_opportunity:detail_conversion_shortage', $card['action']['object']);
        self::assertSame('detail_exposure', $card['metric_contract']['metric_key']);
        self::assertSame('observe', $card['metric_contract']['expected_direction']);
        self::assertSame('observation', $card['metric_contract']['target_type']);
        self::assertNull($card['metric_contract']['target_value']);
        self::assertNull($card['metric_contract']['expected_delta']);
        self::assertNotEmpty($card['risk']['stop_conditions']);
        self::assertSame(7, $card['responsibility']['owner_id']);
        self::assertSame($card['responsibility']['due_at'], $card['execution_window']['end_at']);
        self::assertSame('Asia/Shanghai', $card['execution_window']['timezone']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $card['content_digest']);
    }

    public function testSemanticIdentityIgnoresEntryRecordAndGeneratedProseButNotMetric(): void
    {
        $lifecycle = new OperationActionLifecycleService();
        $base = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_record_id' => 101,
            'source_module' => 'revenue_cockpit_action',
            'platform' => 'ctrip',
            'business_date' => '2026-08-20',
            'metric_key' => 'list_exposure',
            'metric_unit' => 'unique_users',
            'metric_value' => 100,
            'metric_rows' => [[
                'ref' => 'online_daily_data#101',
                'platform' => 'ctrip',
                'business_date' => '2026-08-20',
                'metric' => 'list_exposure',
                'value' => 100,
                'unit' => 'unique_users',
            ]],
            'fact_refs' => ['online_daily_data#101'],
            'fact_snapshot_digest' => str_repeat('c', 64),
            'action_title' => '核对流量入口',
            'action_object' => 'ctrip:list_exposure',
            'action_description' => '从收益驾驶舱生成。',
        ];
        $left = $lifecycle->buildRevenueCockpitObservationCard($base, 7);
        $right = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'source_record_id' => 202,
            'source_module' => 'operating_question',
            'action_title' => '复核携程曝光',
            'action_description' => '从经营问答生成的同一事实行动。',
        ]), 8);
        $differentMetric = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'source_record_id' => 303,
            'metric_key' => 'detail_exposure',
            'metric_unit' => 'exposure_count',
            'metric_rows' => [[
                'ref' => 'online_daily_data#101',
                'platform' => 'ctrip',
                'business_date' => '2026-08-20',
                'metric' => 'detail_exposure',
                'value' => 100,
                'unit' => 'exposure_count',
            ]],
        ]), 7);

        self::assertSame(
            $lifecycle->actionIdentityDigest($left),
            $lifecycle->actionIdentityDigest($right)
        );
        self::assertNotSame(
            $lifecycle->actionIdentityDigest($left),
            $lifecycle->actionIdentityDigest($differentMetric)
        );
    }

    public function testSelectedOpportunitiesHaveDistinctSemanticIdentities(): void
    {
        $lifecycle = new OperationActionLifecycleService();
        $base = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_record_id' => 101,
            'source_module' => 'revenue_cockpit_action',
            'platform' => 'ctrip',
            'business_date' => '2026-08-20',
            'metric_key' => 'list_exposure',
            'metric_unit' => 'unique_users',
            'metric_value' => 100,
            'metric_rows' => [[
                'ref' => 'online_daily_data#101',
                'platform' => 'ctrip',
                'business_date' => '2026-08-20',
                'metric' => 'list_exposure',
                'value' => 100,
                'unit' => 'unique_users',
            ]],
            'fact_refs' => ['online_daily_data#101'],
            'fact_snapshot_digest' => str_repeat('c', 64),
            'decision_snapshot_id' => 77,
            'decision_snapshot_digest' => str_repeat('a', 64),
            'action_title' => '核查机会',
            'action_object' => 'revenue_cockpit_opportunity',
            'action_description' => '由用户核查。',
        ];
        $traffic = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'opportunity_key' => 'traffic_entry_shortage',
            'opportunity_digest' => str_repeat('1', 64),
        ]), 7);
        $detail = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'opportunity_key' => 'detail_conversion_shortage',
            'opportunity_digest' => str_repeat('2', 64),
        ]), 7);

        self::assertNotSame(
            $lifecycle->actionIdentityDigest($traffic),
            $lifecycle->actionIdentityDigest($detail)
        );
    }

    public function testSameOpportunityKeepsIdentityAcrossDecisionSnapshotRefresh(): void
    {
        $lifecycle = new OperationActionLifecycleService();
        $base = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_record_id' => 101,
            'source_module' => 'revenue_cockpit_action',
            'platform' => 'ctrip',
            'business_date' => '2026-08-20',
            'metric_key' => 'list_exposure',
            'metric_unit' => 'exposures',
            'metric_value' => 100,
            'metric_rows' => [[
                'ref' => 'online_daily_data#101',
                'platform' => 'ctrip',
                'business_date' => '2026-08-20',
                'metric' => 'list_exposure',
                'value' => 100,
                'unit' => 'exposures',
            ]],
            'fact_refs' => ['online_daily_data#101'],
            'fact_snapshot_digest' => str_repeat('c', 64),
            'opportunity_key' => 'traffic_entry_shortage',
            'action_title' => '核查流量入口',
            'action_object' => 'revenue_cockpit_opportunity:traffic_entry_shortage',
            'action_description' => '由用户核查。',
        ];
        $first = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'decision_snapshot_id' => 77,
            'decision_snapshot_digest' => str_repeat('a', 64),
            'opportunity_digest' => str_repeat('1', 64),
        ]), 7);
        $refreshed = $lifecycle->buildRevenueCockpitObservationCard(array_merge($base, [
            'source_record_id' => 202,
            'decision_snapshot_id' => 88,
            'decision_snapshot_digest' => str_repeat('b', 64),
            'opportunity_digest' => str_repeat('2', 64),
        ]), 8);

        self::assertSame(
            $lifecycle->actionIdentityDigest($first),
            $lifecycle->actionIdentityDigest($refreshed)
        );
    }

    public function testSingleRecommendationRequiresCompleteDecisionSnapshotLineage(): void
    {
        $service = new RevenueCockpitApprovalService();
        $normalize = new \ReflectionMethod($service, 'normalizeActionContext');
        $base = [
            'opportunity_key' => 'traffic_entry_shortage',
            'action_title' => '核查流量入口',
            'action_object' => 'revenue_cockpit_opportunity:traffic_entry_shortage',
            'action_description' => '由用户核查。',
        ];
        foreach ([
            'all lineage missing' => $base,
            'snapshot digest missing' => array_merge($base, [
                'decision_snapshot_id' => 77,
                'opportunity_digest' => str_repeat('b', 64),
            ]),
            'opportunity digest missing' => array_merge($base, [
                'decision_snapshot_id' => 77,
                'decision_snapshot_digest' => str_repeat('a', 64),
            ]),
            'opportunity key missing' => [
                'decision_snapshot_id' => 77,
                'decision_snapshot_digest' => str_repeat('a', 64),
                'opportunity_digest' => str_repeat('b', 64),
            ],
        ] as $label => $context) {
            try {
                $normalize->invoke($service, $context);
                self::fail($label . ' must fail closed');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('revenue_cockpit_action_context_incomplete', $error->getMessage());
            }
        }

        $complete = $normalize->invoke($service, array_merge($base, [
            'decision_snapshot_id' => 77,
            'decision_snapshot_digest' => str_repeat('a', 64),
            'opportunity_digest' => str_repeat('b', 64),
        ]));
        self::assertSame(77, $complete['decision_snapshot_id']);
        self::assertSame([], $normalize->invoke($service, []));
    }

    /** @return array<string,mixed> */
    private function overview(): array
    {
        $ota = static fn(string $platform, array $rowIds, array $facts): array => [
            'data_status' => 'readback_verified',
            'business_date' => '2026-08-20',
            'actual_business_date' => '2026-08-20',
            'source' => [
                'table' => 'online_daily_data',
                'data_date' => '2026-08-20',
                'platform' => $platform,
                'row_ids' => $rowIds,
                'readback_status' => 'readback_verified',
            ],
            'facts' => $facts,
        ];
        $metricEvidence = static fn(array $rowIds): array => [
            'source_status' => 'readback_verified',
            'requested_row_ids' => $rowIds,
            'accepted_row_ids' => $rowIds,
            'rejected_row_ids' => [],
            'strict_readback' => true,
        ];
        $closurePlatform = static fn(array $rowIds): array => [
            'status' => 'ready',
            'identity_status' => 'verified',
            'business_date' => '2026-08-20',
            'latest_collection' => [
                'status' => 'accepted',
                'claim_allowed' => true,
                'exact_run_readback_status' => 'verified',
                'receipt_record_ids' => $rowIds,
                'accepted_record_ids' => $rowIds,
            ],
            'current_collection_blocker_status' => null,
            'current_receipt_record_ids' => $rowIds,
            'revenue_analysis' => [
                'status' => 'ready',
                'blocked_reason' => null,
            ],
        ];
        return [
            'hotel_id' => 20,
            'business_date' => '2026-08-20',
            'cockpit_strict_evidence' => [
                'contract_version' => 'revenue_cockpit_strict_evidence.v1',
                'tenant_id' => 10,
                'hotel_id' => 20,
                'business_date' => '2026-08-20',
                'strict_gate' => 'history_success+validation_verified+readback_verified',
                'platforms' => [
                    'ctrip' => [
                        'source_strict_readback' => true,
                        'accepted_row_ids' => [101, 102],
                        'rejected_row_ids' => [],
                        'metrics' => [
                            'revenue' => $metricEvidence([101, 102]),
                            'orders' => $metricEvidence([101, 102]),
                            'detail_exposure' => $metricEvidence([101, 102]),
                            'list_exposure' => $metricEvidence([101, 102]),
                        ],
                    ],
                    'meituan' => [
                        'source_strict_readback' => true,
                        'accepted_row_ids' => [201],
                        'rejected_row_ids' => [],
                        'metrics' => [
                            'revenue' => $metricEvidence([201]),
                            'orders' => $metricEvidence([201]),
                            'detail_exposure' => $metricEvidence([201]),
                        ],
                    ],
                ],
            ],
            'dual_ota_field_closure' => [
                'contract_version' => 'dual_ota_field_closure.v1',
                'tenant_id' => 10,
                'hotel_id' => 20,
                'business_date' => '2026-08-20',
                'closure_digest' => str_repeat('d', 64),
                'platforms' => [
                    'ctrip' => $closurePlatform([101, 102]),
                    'meituan' => $closurePlatform([201]),
                ],
            ],
            'three_source_fact_layer' => [
                'business_date' => '2026-08-20',
                'hotel' => [
                    'tenant_id' => 10,
                    'system_hotel_id' => 20,
                    'name' => '测试酒店',
                ],
                'sources' => [
                    'ctrip_ota' => $ota('ctrip', [102, 101], [
                        'revenue' => 800.0,
                        'orders' => 8,
                        'detail_exposure' => 500,
                        'list_exposure' => 1000,
                    ]),
                    'meituan_ota' => $ota('meituan', [201], [
                        'revenue' => 600.0,
                        'orders' => 6,
                        'detail_exposure' => 400,
                    ]),
                    'dingdandao_pms' => [
                        'data_status' => 'readback_verified',
                        'business_date' => '2026-08-20',
                        'actual_business_date' => '2026-08-20',
                        'source' => [
                            'table' => 'dingdandao_operating_target_captures',
                            'data_date' => '2026-08-20',
                            'record_id' => 301,
                            'tenant_id' => 10,
                            'system_hotel_id' => 20,
                            'readback_status' => 'readback_verified',
                        ],
                    ],
                ],
                'facts' => [
                    'ota_channel' => [
                        'combined' => [
                            'revenue' => 1400.0,
                            'orders' => 14,
                            'detail_exposure' => 900,
                        ],
                    ],
                ],
            ],
        ];
    }
}
