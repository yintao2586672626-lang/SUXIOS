<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaDailyOperationFinalizer;
use app\service\CanonicalOtaInvestigationActionService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CanonicalOtaDailyOperationFinalizerTest extends TestCase
{
    public function testVerifiedCtripSingletonCompletesFourChecksWhenMeituanIsBlocked(): void
    {
        $calls = [];
        $service = new CanonicalOtaDailyOperationFinalizer(
            function (array $scope) use (&$calls): array {
                $calls[] = ['draft', $scope];
                return $this->draftResult($scope, false);
            },
            function (array $scope, array $authorization) use (&$calls): array {
                $calls[] = ['action', $scope, $authorization['plan_id']];
                return $this->actionResult($scope, false);
            },
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $this->promotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $this->authorization()
        );

        self::assertSame('verified', $result['status']);
        self::assertSame('verified', $result['analysis_status']);
        self::assertSame('ota_channel', $result['metric_scope']);
        self::assertSame('ctrip', $result['platform_scope']);
        self::assertSame(4, $result['trusted_operational_check_count']);
        self::assertSame(0, $result['trusted_external_operation_count']);
        self::assertFalse($result['whole_hotel_conclusion_claimed']);
        self::assertFalse($result['external_action_triggered']);
        self::assertFalse($result['business_outcome_claimed']);
        self::assertFalse($result['causality_claimed']);
        self::assertSame($this->scope(), $result['scope']);
        self::assertSame('hotel80_ctrip_daily_goal_019fe32a_v1', $result['authorization_plan_id']);
        self::assertSame([
            ['draft', $this->scope()],
            ['action', $this->scope(), 'hotel80_ctrip_daily_goal_019fe32a_v1'],
        ], $calls);
    }

    public function testSameReceiptReplayReturnsTheSameFourIdsAndIdempotentTrue(): void
    {
        $run = 0;
        $service = new CanonicalOtaDailyOperationFinalizer(
            function (array $scope) use (&$run): array {
                return $this->draftResult($scope, $run > 0);
            },
            function (array $scope, array $authorization) use (&$run): array {
                $result = $this->actionResult($scope, $run > 0);
                $run++;
                return $result;
            },
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $this->promotionReceiptResolver()
        );

        $first = $service->finalize($this->collection(), $this->finalization(), 1, 80, $this->authorization());
        $second = $service->finalize($this->collection(), $this->finalization(), 1, 80, $this->authorization());

        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertSame($first['records'], $second['records']);
        self::assertSame($first['action_set_digest'], $second['action_set_digest']);
        self::assertSame(4, count($second['records']));
    }

    public function testVerifiedMeituanMultiRowPromotionCompletesExactlyFourMeituanChecks(): void
    {
        $authorization = $this->meituanAuthorization();
        $calls = [];
        $service = new CanonicalOtaDailyOperationFinalizer(
            function (array $scope) use (&$calls): array {
                $calls[] = ['draft', $scope['platform']];
                return $this->draftResult($scope, false);
            },
            function (array $scope, array $grant) use (&$calls): array {
                $calls[] = ['action', $scope['platform'], $grant['platform']];
                return $this->actionResult($scope, false);
            },
            $this->grantResolver($authorization),
            $this->noOwnerResolver(),
            $this->meituanPromotionReceiptResolver(true)
        );

        $result = $service->finalize(
            $this->meituanCollection(true),
            $this->meituanFinalization(true),
            1,
            80,
            ['meituan' => $authorization]
        );

        self::assertSame('verified', $result['status']);
        self::assertSame('meituan', $result['selected_platform']);
        self::assertSame(['meituan'], $result['strict_ready_platforms']);
        self::assertSame(['meituan'], $result['authorized_ready_platforms']);
        self::assertSame(4, $result['trusted_operational_check_count']);
        self::assertSame(
            CanonicalOtaInvestigationActionService::actionTypesForPlatform('meituan'),
            array_column($result['records'], 'action_type')
        );
        self::assertSame([
            ['draft', 'meituan'],
            ['action', 'meituan', 'meituan'],
        ], $calls);
    }

    public function testBothPlatformsReadySelectsOnlyCtripByFixedPriority(): void
    {
        $ctrip = $this->authorization();
        $meituan = $this->meituanAuthorization();
        $service = new CanonicalOtaDailyOperationFinalizer(
            fn(array $scope): array => $this->draftResult($scope, false),
            fn(array $scope, array $grant): array => $this->actionResult($scope, false),
            $this->grantMapResolver(['ctrip' => $ctrip, 'meituan' => $meituan]),
            $this->noOwnerResolver(),
            $this->bothPromotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->bothCollection(),
            $this->bothFinalization(),
            1,
            80,
            ['ctrip' => $ctrip, 'meituan' => $meituan]
        );

        self::assertSame('verified', $result['status']);
        self::assertSame('ctrip', $result['selected_platform']);
        self::assertSame(['ctrip', 'meituan'], $result['strict_ready_platforms']);
        self::assertSame(['ctrip', 'meituan'], $result['authorized_ready_platforms']);
        self::assertSame(4, count($result['records']));
    }

    public function testExistingMeituanOwnerRemainsStickyWhenCtripLaterBecomesReady(): void
    {
        $ctrip = $this->authorization();
        $meituan = $this->meituanAuthorization();
        $ownerScope = $this->meituanScope();
        $service = new CanonicalOtaDailyOperationFinalizer(
            fn(array $scope): array => $this->draftResult($scope, true),
            fn(array $scope, array $grant): array => $this->actionResult($scope, true),
            $this->grantMapResolver(['ctrip' => $ctrip, 'meituan' => $meituan]),
            $this->selectedOwnerResolver($ownerScope),
            $this->bothPromotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->bothCollection(),
            $this->bothFinalization(),
            1,
            80,
            ['ctrip' => $ctrip, 'meituan' => $meituan]
        );

        self::assertSame('verified', $result['status']);
        self::assertSame('meituan', $result['selected_platform']);
        self::assertTrue($result['idempotent']);
        self::assertSame(4, count($result['records']));
    }

    public function testExistingOwnerEvidenceDriftBlocksWithoutFallingBackToOtherPlatform(): void
    {
        $called = false;
        $ctrip = $this->authorization();
        $meituan = $this->meituanAuthorization();
        $service = new CanonicalOtaDailyOperationFinalizer(
            static function (array $scope) use (&$called): array {
                $called = true;
                return [];
            },
            static function (array $scope, array $grant) use (&$called): array {
                $called = true;
                return [];
            },
            $this->grantMapResolver(['ctrip' => $ctrip, 'meituan' => $meituan]),
            $this->selectedOwnerResolver($this->meituanScope()),
            $this->bothPromotionReceiptResolver()
        );
        $finalization = $this->bothFinalization();
        $finalization['platform_results']['meituan']['promotion']['sync_task_id'] = 9999;

        $result = $service->finalize(
            $this->bothCollection(),
            $finalization,
            1,
            80,
            ['ctrip' => $ctrip, 'meituan' => $meituan]
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_daily_operation_owner_evidence_drift', $result['reason']);
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertFalse($called);
    }

    public function testMissingScheduledAuthorizationBlocksBeforeAnyWriteRunner(): void
    {
        $called = false;
        $service = $this->neverCalledService($called);

        $result = $service->finalize($this->collection(), $this->finalization(), 1, 80, []);

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_daily_operation_authorization_missing', $result['reason']);
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertFalse($called);
    }

    public function testTamperedScheduledAuthorizationBlocksBeforeAnyWriteRunner(): void
    {
        $called = false;
        $service = $this->neverCalledService($called);
        $authorization = $this->authorization();
        $authorization['hotel_id'] = 81;

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $authorization
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_daily_operation_authorization_missing', $result['reason']);
        self::assertFalse($called);
    }

    public function testSelfRehashedFabricatedAuthorizationIsRejectedWhenServerGrantDoesNotMatch(): void
    {
        $called = false;
        $service = $this->neverCalledService($called);
        $authorization = $this->authorization();
        $authorization['plan_id'] = 'forged_but_rehashed_plan';
        $authorization['content_digest'] = $this->digest($authorization);

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $authorization
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_daily_operation_authorization_not_granted', $result['reason']);
        self::assertFalse($called);
    }

    public function testRealtimeIsNotApplicableWithoutInspectingAuthorizationOrWriting(): void
    {
        $called = false;
        $service = $this->neverCalledService($called);
        $collection = $this->collection();
        $collection['data_period'] = 'realtime_snapshot';

        $result = $service->finalize($collection, [], 1, 80, []);

        self::assertSame('not_applicable', $result['status']);
        self::assertSame('canonical_daily_operation_non_historical_period', $result['reason']);
        self::assertFalse($called);
    }

    public function testMultipleAuthoritativeRowsFailClosedWithoutPickingTheFirst(): void
    {
        $called = false;
        $service = $this->neverCalledService($called);
        $finalization = $this->finalization();
        $finalization['platform_results']['ctrip']['promotion']['row_ids'] = [501, 502];

        $result = $service->finalize(
            $this->collection(),
            $finalization,
            1,
            80,
            $this->authorization()
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('canonical_daily_operation_ctrip_operation_row_ambiguous', $result['reason']);
        self::assertFalse($called);
    }

    public function testSameTaskNonPromotedRowCannotReplacePersistedSelectedOperationRow(): void
    {
        $originalDigest = (string)$this->finalization()['platform_results']['ctrip']['promotion'][
            'promotion_receipt_digest'
        ];
        $withOriginalDigest = $this->withForgedCtripOperationRow(
            $this->finalization(),
            490,
            $originalDigest
        );
        $withAnotherDigest = $this->withForgedCtripOperationRow(
            $this->finalization(),
            490,
            str_repeat('9', 64)
        );
        $withoutSelector = $withOriginalDigest;
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $field) {
            unset($withoutSelector['platform_results']['ctrip']['promotion'][$field]);
        }

        foreach ([
            [$withOriginalDigest, 'canonical_daily_operation_ctrip_promotion_receipt_untrusted'],
            [$withAnotherDigest, 'canonical_daily_operation_ctrip_promotion_receipt_untrusted'],
            [$withoutSelector, 'canonical_daily_operation_ctrip_operation_row_ambiguous'],
        ] as [$finalization, $reason]) {
            $called = false;
            $service = $this->neverCalledService($called);

            $result = $service->finalize(
                $this->collection(),
                $finalization,
                1,
                80,
                $this->authorization()
            );

            self::assertSame('blocked', $result['status']);
            self::assertSame('scope_validation', $result['stage']);
            self::assertSame($reason, $result['reason']);
            self::assertSame(0, $result['trusted_operational_check_count']);
            self::assertFalse($called);
        }
    }

    public function testPersistedLegacySingletonReceiptWithoutSelectorIsRejected(): void
    {
        $collection = $this->collection();
        $legacyReceipt = $this->promotionReceipt(
            'ctrip',
            [501],
            501,
            (string)$collection['collection_anchor_hash']
        );
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $field) {
            unset($legacyReceipt[$field]);
        }
        $legacyReceipt['content_digest'] = $this->promotionReceiptDigest($legacyReceipt);
        $finalization = $this->finalization();
        $finalization['platform_results']['ctrip']['promotion']['promotion_receipt_digest'] =
            $legacyReceipt['content_digest'];
        $called = false;
        $service = $this->neverCalledService(
            $called,
            $this->promotionReceiptMapResolver(['ctrip' => $legacyReceipt])
        );

        $result = $service->finalize(
            $collection,
            $finalization,
            1,
            80,
            $this->authorization()
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('scope_validation', $result['stage']);
        self::assertSame(
            'canonical_daily_operation_ctrip_promotion_receipt_untrusted',
            $result['reason']
        );
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertFalse($called);
    }

    public function testAnyPromotionOrCollectionScopeDriftBlocksWithoutRunners(): void
    {
        $cases = [];
        $blockedPromotion = $this->finalization();
        $blockedPromotion['platform_results']['ctrip']['status'] = 'blocked';
        $cases[] = [$this->collection(), $blockedPromotion, 1, 80];
        $wrongTenant = $this->finalization();
        $wrongTenant['tenant_id'] = 2;
        $cases[] = [$this->collection(), $wrongTenant, 1, 80];
        $wrongTask = $this->collection();
        $wrongTask['source_tasks'][0]['sync_task_id'] = 999;
        $cases[] = [$wrongTask, $this->finalization(), 1, 80];
        $wrongDate = $this->finalization();
        $wrongDate['platform_results']['ctrip']['promotion']['target_date'] = '2026-08-07';
        $cases[] = [$this->collection(), $wrongDate, 1, 80];
        $wrongHotel = $this->collection();
        $wrongHotel['hotel_id'] = 81;
        $cases[] = [$wrongHotel, $this->finalization(), 1, 80];

        foreach ($cases as [$collection, $finalization, $tenantId, $hotelId]) {
            $called = false;
            $service = $this->neverCalledService($called);
            $result = $service->finalize(
                $collection,
                $finalization,
                $tenantId,
                $hotelId,
                $this->authorization()
            );
            self::assertSame('blocked', $result['status']);
            self::assertSame(0, $result['trusted_operational_check_count']);
            self::assertFalse($called);
        }
    }

    public function testDraftFailureDoesNotCallActionRunner(): void
    {
        $actionCalled = false;
        $service = new CanonicalOtaDailyOperationFinalizer(
            static fn(array $scope): array => throw new RuntimeException('secret filesystem detail'),
            static function (array $scope, array $authorization) use (&$actionCalled): array {
                $actionCalled = true;
                return [];
            },
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $this->promotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $this->authorization()
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('draft_save', $result['stage']);
        self::assertSame('canonical_daily_operation_draft_save_blocked', $result['reason']);
        self::assertFalse($actionCalled);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testActionFailureKeepsVerifiedDraftStateAndReportsZeroChecks(): void
    {
        $service = new CanonicalOtaDailyOperationFinalizer(
            fn(array $scope): array => $this->draftResult($scope, false),
            static fn(array $scope, array $authorization): array => throw new RuntimeException('database details'),
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $this->promotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $this->authorization()
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('action_persist', $result['stage']);
        self::assertSame('canonical_daily_operation_draft_saved_action_blocked', $result['reason']);
        self::assertTrue($result['draft_readback_verified']);
        self::assertSame(4, $result['draft_count']);
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertFalse($result['db_readback_verified']);
    }

    public function testActionTruthBoundaryFailureIsBlocked(): void
    {
        $service = new CanonicalOtaDailyOperationFinalizer(
            fn(array $scope): array => $this->draftResult($scope, true),
            function (array $scope, array $authorization): array {
                $result = $this->actionResult($scope, true);
                $result['business_outcome_claimed'] = true;
                return $result;
            },
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $this->promotionReceiptResolver()
        );

        $result = $service->finalize(
            $this->collection(),
            $this->finalization(),
            1,
            80,
            $this->authorization()
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame(0, $result['trusted_operational_check_count']);
        self::assertFalse($result['business_outcome_claimed']);
    }

    /** @return array<string,mixed> */
    private function collection(): array
    {
        $tasks = [
            [
                'data_source_id' => 25,
                'sync_task_id' => 3096,
                'platform' => 'ctrip',
                'collection_status' => 'success',
                'p0_status' => 'ready',
                'historical_core_contract_status' => 'ready',
                'row_ids' => [490, 501],
            ],
            [
                'data_source_id' => 68,
                'sync_task_id' => 3093,
                'platform' => 'meituan',
                'collection_status' => 'partial',
                'p0_status' => 'blocked',
                'historical_core_contract_status' => 'ready',
                'row_ids' => [601],
            ],
        ];
        return [
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'required_platforms' => ['ctrip', 'meituan'],
            'source_tasks' => $tasks,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => OtaCollectionAnchorService::hash($tasks),
        ];
    }

    /** @return array<string,mixed> */
    private function finalization(?array $collection = null): array
    {
        $collection = $collection ?? $this->collection();
        $promotionReceipt = $this->promotionReceipt(
            'ctrip',
            [501],
            501,
            (string)$collection['collection_anchor_hash']
        );
        return [
            'status' => 'partial',
            'tenant_id' => 1,
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'promoted_platforms' => ['ctrip'],
            'blocked_platforms' => ['meituan'],
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $collection['collection_anchor_hash'],
            'canonical_history_complete' => false,
            'platform_results' => [
                'ctrip' => [
                    'status' => 'verified',
                    'promotion' => $this->promotionResult($promotionReceipt),
                ],
                'meituan' => [
                    'status' => 'blocked',
                    'promotion' => [
                        'status' => 'blocked',
                        'readback_verified' => false,
                        'sensitive_values_exposed' => false,
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function authorization(): array
    {
        $authorization = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => 'hotel80_ctrip_daily_goal_019fe32a_v1',
            'tenant_id' => 1,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $authorization['content_digest'] = $this->digest($authorization);
        return $authorization;
    }

    /** @return array<string,mixed> */
    private function scope(): array
    {
        return [
            'tenant_id' => 1,
            'hotel_id' => 80,
            'data_source_id' => 25,
            'task_id' => 3096,
            'row_id' => 501,
            'platform' => 'ctrip',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanScope(): array
    {
        return [
            'tenant_id' => 1,
            'hotel_id' => 80,
            'data_source_id' => 68,
            'task_id' => 3093,
            'row_id' => 601,
            'platform' => 'meituan',
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanAuthorization(): array
    {
        $authorization = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => 'hotel80_meituan_daily_goal_019fe32a_v1',
            'tenant_id' => 1,
            'hotel_id' => 80,
            'platform' => 'meituan',
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => '2026-08-09T10:00:00+08:00',
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $authorization['content_digest'] = $this->digest($authorization);
        return $authorization;
    }

    /** @return array<string,mixed> */
    private function meituanCollection(bool $multiRow): array
    {
        $collection = $this->collection();
        $collection['source_tasks'][0]['collection_status'] = 'partial';
        $collection['source_tasks'][0]['p0_status'] = 'blocked';
        $collection['source_tasks'][1] = [
            'data_source_id' => 68,
            'sync_task_id' => 3093,
            'platform' => 'meituan',
            'collection_status' => 'success',
            'p0_status' => 'ready',
            'historical_core_contract_status' => 'ready',
            'row_ids' => $multiRow ? [601, 602] : [601],
        ];
        $collection['collection_anchor_hash'] = OtaCollectionAnchorService::hash(
            $collection['source_tasks']
        );
        return $collection;
    }

    /** @return array<string,mixed> */
    private function meituanFinalization(bool $multiRow, ?array $collection = null): array
    {
        $rowIds = $multiRow ? [601, 602] : [601];
        $collection = $collection ?? $this->meituanCollection($multiRow);
        $promotionReceipt = $this->promotionReceipt(
            'meituan',
            $rowIds,
            601,
            (string)$collection['collection_anchor_hash']
        );
        $promotion = $this->promotionResult($promotionReceipt);
        return [
            'status' => 'partial',
            'tenant_id' => 1,
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'promoted_platforms' => ['meituan'],
            'blocked_platforms' => ['ctrip'],
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $collection['collection_anchor_hash'],
            'canonical_history_complete' => false,
            'platform_results' => [
                'ctrip' => [
                    'status' => 'blocked',
                    'promotion' => ['status' => 'blocked', 'readback_verified' => false],
                ],
                'meituan' => ['status' => 'verified', 'promotion' => $promotion],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function bothCollection(): array
    {
        $collection = $this->meituanCollection(true);
        $collection['source_tasks'][0]['collection_status'] = 'success';
        $collection['source_tasks'][0]['p0_status'] = 'ready';
        $collection['collection_anchor_hash'] = OtaCollectionAnchorService::hash(
            $collection['source_tasks']
        );
        return $collection;
    }

    /** @return array<string,mixed> */
    private function bothFinalization(): array
    {
        $collection = $this->bothCollection();
        $meituan = $this->meituanFinalization(true, $collection);
        $ctrip = $this->finalization($collection);
        $ctrip['promoted_platforms'] = ['ctrip', 'meituan'];
        $ctrip['blocked_platforms'] = [];
        $ctrip['canonical_history_complete'] = true;
        $ctrip['platform_results']['meituan'] = $meituan['platform_results']['meituan'];
        $ctrip['collection_anchor_contract_version'] = OtaCollectionAnchorService::CONTRACT_VERSION;
        $ctrip['collection_anchor_hash'] = $collection['collection_anchor_hash'];
        return $ctrip;
    }

    /** @param array<int,int> $rowIds @return array<string,mixed> */
    private function promotionReceipt(
        string $platform,
        array $rowIds,
        int $selectedRowId,
        string $anchorHash
    ): array {
        $sourceId = $platform === 'ctrip' ? 25 : 68;
        $taskId = $platform === 'ctrip' ? 3096 : 3093;
        $metricDigest = hash('sha256', 'metrics:' . $platform . ':equivalent');
        $metricDigests = [];
        $factDigests = [];
        $identityDigests = [];
        foreach ($rowIds as $rowId) {
            $metricDigests[$rowId] = $metricDigest;
            $factDigests[$rowId] = hash('sha256', 'facts:' . $platform . ':' . $rowId);
            $identityDigests[$rowId] = hash('sha256', 'identity:' . $platform . ':' . $rowId);
        }
        $selection = [
            'version' => 'ota_operation_row_selection.v1',
            'status' => 'ready',
            'policy' => 'singleton_or_equivalent_required_metrics_min_row_id.v1',
            'platform' => $platform,
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'candidate_row_ids' => $rowIds,
            'selected_row_id' => $selectedRowId,
            'row_metric_digests' => $metricDigests,
        ];
        $receipt = [
            'version' => 'ota_canonical_history_promotion.v3',
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'row_ids' => $rowIds,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchorHash,
            'verifier_report_hash' => hash('sha256', 'verifier:' . $platform),
            'authoritative_fact_digest' => hash('sha256', 'facts:' . $platform),
            'authoritative_row_fact_digests' => $factDigests,
            'platform_hotel_identity_digest' => hash('sha256', 'hotel:' . $platform),
            'authoritative_row_platform_hotel_identity_digests' => $identityDigests,
            'operation_row_selection_version' => $selection['version'],
            'operation_row_selection_status' => $selection['status'],
            'operation_row_selection_policy' => $selection['policy'],
            'operation_row_candidate_ids' => $selection['candidate_row_ids'],
            'selected_operation_row_id' => $selection['selected_row_id'],
            'operation_row_metric_digests' => $selection['row_metric_digests'],
            'operation_row_selection_digest' => $this->digest($selection),
            'nonzero_required_metric_rows' => count($rowIds),
            'explicit_zero_confirmed_rows' => 0,
            'observed_traffic_metric_provenance_status' => 'ready',
            'synthetic_normalization_provenance_missing_rows' => 0,
            'verified_at' => '2026-08-09 10:00:00',
            'sensitive_values_exposed' => false,
        ];
        $receipt['content_digest'] = $this->promotionReceiptDigest($receipt);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function promotionResult(array $receipt): array
    {
        $result = [
            'status' => 'verified',
            'readback_verified' => true,
            'history_status' => 'success',
            'tenant_id' => (int)$receipt['tenant_id'],
            'system_hotel_id' => (int)$receipt['system_hotel_id'],
            'platform' => (string)$receipt['platform'],
            'target_date' => (string)$receipt['target_date'],
            'data_source_id' => (int)$receipt['data_source_id'],
            'sync_task_id' => (int)$receipt['sync_task_id'],
            'row_ids' => $receipt['row_ids'],
            'promotion_receipt_digest' => (string)$receipt['content_digest'],
            'sensitive_values_exposed' => false,
        ];
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $field) {
            $result[$field] = $receipt[$field];
        }
        return $result;
    }

    /** @param array<string,mixed> $finalization @return array<string,mixed> */
    private function withForgedCtripOperationRow(
        array $finalization,
        int $rowId,
        string $promotionDigest
    ): array {
        $promotion = &$finalization['platform_results']['ctrip']['promotion'];
        $metricDigest = hash('sha256', 'forged-metrics:' . $rowId);
        $selection = [
            'version' => 'ota_operation_row_selection.v1',
            'status' => 'ready',
            'policy' => 'singleton_or_equivalent_required_metrics_min_row_id.v1',
            'platform' => 'ctrip',
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3096,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'candidate_row_ids' => [$rowId],
            'selected_row_id' => $rowId,
            'row_metric_digests' => [$rowId => $metricDigest],
        ];
        $promotion['row_ids'] = [$rowId];
        $promotion['promotion_receipt_digest'] = $promotionDigest;
        $promotion['operation_row_selection_version'] = $selection['version'];
        $promotion['operation_row_selection_status'] = $selection['status'];
        $promotion['operation_row_selection_policy'] = $selection['policy'];
        $promotion['operation_row_candidate_ids'] = $selection['candidate_row_ids'];
        $promotion['selected_operation_row_id'] = $selection['selected_row_id'];
        $promotion['operation_row_metric_digests'] = $selection['row_metric_digests'];
        $promotion['operation_row_selection_digest'] = $this->digest($selection);
        unset($promotion);
        return $finalization;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function draftResult(array $scope, bool $idempotent): array
    {
        return [
            'status' => 'saved',
            'execute' => true,
            'readback_verified' => true,
            'draft_count' => 4,
            'idempotent' => $idempotent,
            'content_digest' => str_repeat('c', 64),
            'scope' => $scope,
        ];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function actionResult(array $scope, bool $idempotent): array
    {
        $records = [];
        foreach (CanonicalOtaInvestigationActionService::actionTypesForPlatform(
            (string)$scope['platform']
        ) as $index => $type) {
            $records[] = [
                'idempotent' => $idempotent,
                'intent_id' => 100 + $index,
                'task_id' => 200 + $index,
                'evidence_id' => 300 + $index,
                'action_type' => $type,
            ];
        }
        $actionSetDigest = str_repeat('d', 64);
        return [
            'status' => 'completed',
            'execute' => true,
            'scope' => $scope,
            'idempotent' => $idempotent,
            'action_set_digest' => $actionSetDigest,
            'trusted_operational_check_count' => 4,
            'trusted_external_operation_count' => 0,
            'db_readback_verified' => true,
            'operation_flow_readback_verified' => true,
            'effect_review_written' => false,
            'action_track_written' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'records' => $records,
            'daily_platform_selection' => $this->dailySelectionReceipt(
                $scope,
                $records,
                $actionSetDigest
            ),
        ];
    }

    /** @param array<string,mixed> $scope @param array<int,array<string,mixed>> $records */
    private function dailySelectionReceipt(array $scope, array $records, string $actionSetDigest): array
    {
        $ownerScope = [
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'target_date' => (string)$scope['target_date'],
            'data_period' => (string)$scope['data_period'],
        ];
        $policy = [
            'name' => \app\service\CanonicalOtaDailyPlatformSelectionService::POLICY,
            'version' => \app\service\CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION,
            'preference' => ['ctrip', 'meituan'],
            'sticky_after_claim' => true,
        ];
        $intentIds = array_map('intval', array_column($records, 'intent_id'));
        sort($intentIds, SORT_NUMERIC);
        $receipt = [
            'schema_version' => \app\service\CanonicalOtaDailyPlatformSelectionService::SCHEMA_VERSION,
            'status' => 'selected',
            'selection_policy' => \app\service\CanonicalOtaDailyPlatformSelectionService::POLICY,
            'selection_policy_version' => \app\service\CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION,
            'selection_policy_digest' => $this->digest($policy),
            'owner_scope' => $ownerScope,
            'owner_scope_digest' => $this->digest($ownerScope),
            'selected_platform' => (string)$scope['platform'],
            'scope' => $scope,
            'intent_ids' => $intentIds,
            'action_set_digest' => $actionSetDigest,
            'owner_source' => 'intent_evidence',
            'legacy_owner_inferred' => false,
            'readback_verified' => true,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    private function neverCalledService(
        bool &$called,
        ?callable $promotionReceiptResolver = null
    ): CanonicalOtaDailyOperationFinalizer
    {
        return new CanonicalOtaDailyOperationFinalizer(
            static function (array $scope) use (&$called): array {
                $called = true;
                return [];
            },
            static function (array $scope, array $authorization) use (&$called): array {
                $called = true;
                return [];
            },
            $this->grantResolver(),
            $this->noOwnerResolver(),
            $promotionReceiptResolver ?? $this->promotionReceiptResolver()
        );
    }

    private function promotionReceiptResolver(): callable
    {
        $collection = $this->collection();
        return $this->promotionReceiptMapResolver([
            'ctrip' => $this->promotionReceipt(
                'ctrip',
                [501],
                501,
                (string)$collection['collection_anchor_hash']
            ),
        ]);
    }

    private function meituanPromotionReceiptResolver(bool $multiRow): callable
    {
        $collection = $this->meituanCollection($multiRow);
        $rowIds = $multiRow ? [601, 602] : [601];
        return $this->promotionReceiptMapResolver([
            'meituan' => $this->promotionReceipt(
                'meituan',
                $rowIds,
                601,
                (string)$collection['collection_anchor_hash']
            ),
        ]);
    }

    private function bothPromotionReceiptResolver(): callable
    {
        $collection = $this->bothCollection();
        $anchorHash = (string)$collection['collection_anchor_hash'];
        return $this->promotionReceiptMapResolver([
            'ctrip' => $this->promotionReceipt('ctrip', [501], 501, $anchorHash),
            'meituan' => $this->promotionReceipt('meituan', [601, 602], 601, $anchorHash),
        ]);
    }

    /** @param array<string,array<string,mixed>> $stored */
    private function promotionReceiptMapResolver(array $stored): callable
    {
        return static function (
            int $tenantId,
            int $hotelId,
            int $sourceId,
            int $taskId,
            string $platform,
            string $targetDate
        ) use ($stored): array {
            $receipt = is_array($stored[$platform] ?? null) ? $stored[$platform] : [];
            if ((int)($receipt['tenant_id'] ?? 0) !== $tenantId
                || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($receipt['data_source_id'] ?? 0) !== $sourceId
                || (int)($receipt['sync_task_id'] ?? 0) !== $taskId
                || (string)($receipt['platform'] ?? '') !== $platform
                || (string)($receipt['target_date'] ?? '') !== $targetDate
            ) {
                return [];
            }
            return $receipt;
        };
    }

    private function noOwnerResolver(): callable
    {
        return static fn(int $tenantId, int $hotelId, string $targetDate, string $period): array => [
            'status' => 'none',
            'selected' => false,
            'scope' => null,
            'owner_scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'target_date' => $targetDate,
                'data_period' => $period,
            ],
            'selection_receipt' => null,
        ];
    }

    /** @param array<string,mixed> $scope */
    private function selectedOwnerResolver(array $scope): callable
    {
        $records = [];
        foreach (CanonicalOtaInvestigationActionService::actionTypesForPlatform(
            (string)$scope['platform']
        ) as $index => $actionType) {
            $records[] = [
                'intent_id' => 100 + $index,
                'task_id' => 200 + $index,
                'evidence_id' => 300 + $index,
                'action_type' => $actionType,
            ];
        }
        $receipt = $this->dailySelectionReceipt($scope, $records, str_repeat('d', 64));
        return static fn(int $tenantId, int $hotelId, string $targetDate, string $period): array => [
            'status' => 'selected',
            'selected' => true,
            'platform' => $scope['platform'],
            'scope' => $scope,
            'owner_scope' => $receipt['owner_scope'],
            'selection_receipt' => $receipt,
        ];
    }

    /** @param array<string,mixed>|null $storedGrant */
    private function grantResolver(?array $storedGrant = null): callable
    {
        $stored = $storedGrant ?? $this->authorization();
        return static function (
            array $authorization,
            int $tenantId,
            int $hotelId,
            string $platform
        ) use ($stored): array {
            if ($tenantId !== (int)$stored['tenant_id']
                || $hotelId !== (int)$stored['hotel_id']
                || $platform !== (string)$stored['platform']
                || $authorization !== $stored
            ) {
                throw new RuntimeException('canonical_scheduled_analysis_grant_mismatch');
            }
            return $stored;
        };
    }

    /** @param array<string,array<string,mixed>> $grants */
    private function grantMapResolver(array $grants): callable
    {
        return static function (
            array $authorization,
            int $tenantId,
            int $hotelId,
            string $platform
        ) use ($grants): array {
            $stored = is_array($grants[$platform] ?? null) ? $grants[$platform] : [];
            if ($stored === []
                || $tenantId !== (int)$stored['tenant_id']
                || $hotelId !== (int)$stored['hotel_id']
                || $authorization !== $stored
            ) {
                throw new RuntimeException('canonical_scheduled_analysis_grant_mismatch');
            }
            return $stored;
        };
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $value */
    private function promotionReceiptDigest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
