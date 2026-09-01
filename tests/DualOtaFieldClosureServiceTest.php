<?php
declare(strict_types=1);

namespace tests;

use app\service\DualOtaFieldClosureService;
use PHPUnit\Framework\TestCase;

final class DualOtaFieldClosureServiceTest extends TestCase
{
    public function testRealShapeFieldClosureKeepsExactReadbackSemanticConflictAndFinalitySeparate(): void
    {
        $rows = [
            $this->row(101519, 'ctrip', 'business', [
                'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
                'amount' => 5921.18,
                'quantity' => 8,
                'validation_status' => 'partial',
                'validation_flags' => '["field_missing:order_count"]',
            ]),
            $this->row(101810, 'ctrip', 'business', [
                'dimension' => 'semantic:ctrip_business_market_overview:booking_order_count',
                'book_order_num' => 4,
            ]),
            $this->row(101518, 'ctrip', 'traffic', [
                'dimension' => 'catalog:business_overview:business_visitor_title:visitor_count+visitor_rank:visitorTotal',
                'detail_exposure' => 43,
                'validation_status' => 'partial',
                'validation_flags' => '["field_missing:list_exposure","field_missing:flow_rate"]',
            ]),
            $this->row(101874, 'meituan', 'business', [
                'amount' => 7895.43,
                'quantity' => 12,
                'book_order_num' => 8,
                'raw_data' => json_encode(['row' => [
                    '_capture_source' => 'xhr:traffic:business_data',
                ]], JSON_THROW_ON_ERROR),
            ]),
            $this->row(101919, 'meituan', 'order', [
                'amount' => 7025.14,
                'quantity' => 12,
                'book_order_num' => 8,
                'raw_data' => json_encode(['row' => [
                    '_capture_source' => 'xhr:orders:daily_summary',
                ]], JSON_THROW_ON_ERROR),
            ]),
            $this->row(101917, 'meituan', 'traffic', [
                'list_exposure' => 1083,
                'detail_exposure' => 206,
                'flow_rate' => 3.88,
                'history_status' => 'success',
                'validation_status' => 'verified',
                'raw_data' => json_encode(['row' => [
                    '_capture_source' => 'xhr:traffic:traffic',
                    'exposure_to_browse_rate' => 19.02,
                ]], JSON_THROW_ON_ERROR),
            ]),
            $this->row(101931, 'meituan', 'traffic', [
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'flow_rate' => 0,
                'validation_status' => 'quarantined',
                'validation_flags' => '["same_run_zero_traffic_conflicts_with_nonzero_orders"]',
                'snapshot_time' => '2026-08-24 00:25:56',
                'raw_data' => json_encode(['row' => [
                    '_capture_source' => 'xhr:traffic:traffic',
                    'exposure_to_browse_rate' => 0,
                ]], JSON_THROW_ON_ERROR),
            ]),
        ];

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            $rows,
            $this->trust()
        );

        $ctrip = $this->fields($closure['platforms']['ctrip']['fields']);
        self::assertNull($ctrip['revenue']['value']);
        self::assertSame('caliber_uncertain', $ctrip['revenue']['status']);
        self::assertSame(5921.18, $ctrip['revenue']['observed_values'][0]['value']);
        self::assertSame([101519], $ctrip['revenue']['source_record_ids']);
        self::assertTrue($ctrip['revenue']['current_receipt_binding_verified']);
        self::assertTrue($ctrip['revenue']['exact_run_scope_verified']);
        self::assertNull($ctrip['order_count']['value']);
        self::assertSame('caliber_uncertain', $ctrip['order_count']['status']);
        self::assertSame([101810], $ctrip['order_count']['source_record_ids']);
        self::assertNull($ctrip['room_nights']['value']);
        self::assertNull($ctrip['adr']['value']);
        self::assertSame('caliber_uncertain', $ctrip['adr']['status']);
        self::assertNull($ctrip['visits']['value']);
        self::assertSame('caliber_uncertain', $ctrip['visits']['status']);
        self::assertSame('field_unavailable', $ctrip['exposure']['status']);
        self::assertFalse($ctrip['revenue']['revenue_analysis_consumable']);
        self::assertSame('verified', $closure['platforms']['ctrip']['identity_status']);

        $meituan = $this->fields($closure['platforms']['meituan']['fields']);
        self::assertNull($meituan['revenue']['value']);
        self::assertSame('caliber_uncertain', $meituan['revenue']['status']);
        self::assertSame([101874, 101919], $meituan['revenue']['source_record_ids']);
        self::assertCount(2, $meituan['revenue']['observed_values']);
        self::assertNull($meituan['order_count']['value']);
        self::assertSame('caliber_uncertain', $meituan['order_count']['status']);
        self::assertNull($meituan['room_nights']['value']);
        self::assertSame('caliber_uncertain', $meituan['room_nights']['status']);
        self::assertNull($meituan['adr']['value']);
        self::assertSame('caliber_uncertain', $meituan['adr']['status']);
        self::assertSame(1083.0, $meituan['exposure']['value']);
        self::assertSame(206.0, $meituan['visits']['value']);
        self::assertSame(19.02, $meituan['conversion']['value']);
        self::assertSame('verified_calculation', $meituan['conversion']['status']);
        self::assertContains(
            'legacy_stored_flow_rate_semantic_mismatch',
            $meituan['conversion']['quality_flags']
        );
        self::assertSame([101917], $meituan['conversion']['source_record_ids']);
        self::assertSame('verified', $meituan['exposure']['validation_status']);
        self::assertSame('readback_verified', $meituan['exposure']['readback_status']);
        self::assertSame('platform_data_sync_task#4352', $meituan['exposure']['capture_ref']);
        self::assertSame(['list_exposure'], $meituan['exposure']['consumer_metric_keys']);
        self::assertSame('trusted_ota_daily_fact_consumer.v1', $closure['consumer_contract']['contract_version']);
        self::assertFalse($closure['consumer_contract']['metric_values_duplicated']);
        self::assertContains(101931, $closure['platforms']['meituan']['formal_record_ids']);
        self::assertContains(101931, $closure['platforms']['meituan']['current_receipt_all_record_ids']);
        self::assertContains(101931, $closure['platforms']['meituan']['current_receipt_non_eligible_record_ids']);
        self::assertNotContains(
            'online_daily_data#101931',
            $closure['platforms']['meituan']['semantic_veto_record_refs']
        );
        self::assertSame('blocked', $closure['platforms']['meituan']['revenue_analysis']['status']);
        self::assertSame('partial', $closure['status']);
        self::assertFalse($closure['sensitive_values_exposed']);

        $repeat = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            $rows,
            $this->trust()
        );
        self::assertSame($closure['closure_digest'], $repeat['closure_digest']);
        self::assertSame($closure['page_identity'], $repeat['page_identity']);
    }

    public function testMissingFieldsExposeLoginExpiredInsteadOfZero(): void
    {
        $trust = $this->trust();
        $trust['days'][0]['platforms'][0]['acceptance_receipt']['sync_task_status'] =
            'profile_session_not_ready';
        $trust['days'][0]['platforms'][0]['acceptance_receipt']['reason_codes'] =
            ['login_expired'];

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [],
            $trust
        );
        $ctrip = $this->fields($closure['platforms']['ctrip']['fields']);

        self::assertSame('source_missing', $ctrip['revenue']['status']);
        self::assertNull($ctrip['revenue']['value']);
        self::assertSame([], $ctrip['revenue']['source_record_ids']);
        self::assertSame('source_missing', $ctrip['source_record_id']['status']);
        self::assertSame('source_missing', $ctrip['cancellation']['status']);
        self::assertSame('source_missing', $ctrip['sellable']['status']);
        self::assertSame('source_missing', $ctrip['bookable']['status']);
        self::assertSame('login_expired', $closure['platforms']['ctrip']['current_collection_blocker_status']);
    }

    public function testCurrentFailedReceiptCannotBorrowOlderSameDayRows(): void
    {
        $oldRow = $this->row(101519, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 5921.18,
            'quantity' => 8,
            'history_status' => 'success',
            'validation_status' => 'verified',
            'sync_task_id' => 4351,
        ]);
        $trust = $this->trust();
        $platform = &$trust['days'][0]['platforms'][0];
        $platform['sync_task_id'] = 5000;
        $platform['sync_task_status'] = 'profile_session_not_ready';
        $platform['acceptance_receipt']['status'] = 'blocked';
        $platform['acceptance_receipt']['sync_task_id'] = 5000;
        $platform['acceptance_receipt']['sync_task_status'] = 'profile_session_not_ready';
        $platform['acceptance_receipt']['reason_codes'] = ['login_expired'];
        $platform['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'unverified',
            'receipt_record_ids' => [],
            'accepted_record_ids' => [],
        ];
        unset($platform);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [$oldRow],
            $trust
        );
        $ctrip = $this->fields($closure['platforms']['ctrip']['fields']);

        self::assertSame('source_missing', $ctrip['revenue']['status']);
        self::assertNull($ctrip['revenue']['value']);
        self::assertFalse($ctrip['revenue']['revenue_analysis_consumable']);
        self::assertSame('partial', $closure['platforms']['ctrip']['identity_status']);
        self::assertSame(
            ['online_daily_data#101519'],
            $closure['platforms']['ctrip']['excluded_prior_formal_record_refs']
        );
    }

    public function testStructuredPlatformFailureAndDateMismatchAreExposedWithoutReasonText(): void
    {
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['status'] = 'collection_failed';
        $ctrip['acceptance_receipt']['reason_codes'] = [];
        $ctrip['acceptance_receipt']['sync_task_status'] = 'partial_success';
        unset($ctrip);
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['acceptance_receipt']['reason_codes'] = [];
        $meituan['acceptance_receipt']['target_date_status'] = 'mismatch';
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [],
            $trust
        );
        $ctripFields = $this->fields($closure['platforms']['ctrip']['fields']);
        $meituanFields = $this->fields($closure['platforms']['meituan']['fields']);

        self::assertSame('source_missing', $ctripFields['revenue']['status']);
        self::assertNull($ctripFields['revenue']['value']);
        self::assertSame('source_missing', $ctripFields['cancellation']['status']);
        self::assertSame('source_missing', $meituanFields['revenue']['status']);
        self::assertNull($meituanFields['revenue']['value']);
        self::assertSame('source_missing', $meituanFields['cancellation']['status']);
        self::assertSame('collection_failed', $closure['platforms']['ctrip']['current_collection_blocker_status']);
        self::assertSame('date_mismatch', $closure['platforms']['meituan']['current_collection_blocker_status']);
    }

    public function testStructuredPartialCollectionKeepsAcceptedRowsButMarksAbsentFieldsFailed(): void
    {
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['status'] = 'collection_failed';
        $ctrip['acceptance_receipt']['reason_codes'] = [];
        $ctrip['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [101519],
            'accepted_record_ids' => [101519],
        ];
        unset($ctrip);
        $accepted = $this->row(101519, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 5921.18,
            'quantity' => 8,
        ]);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$accepted],
            $trust
        );
        $fields = $this->fields($closure['platforms']['ctrip']['fields']);

        self::assertSame('caliber_uncertain', $fields['revenue']['status']);
        self::assertNull($fields['revenue']['value']);
        self::assertSame(5921.18, $fields['revenue']['observed_values'][0]['value']);
        self::assertSame('source_missing', $fields['exposure']['status']);
        self::assertNull($fields['exposure']['value']);
    }

    public function testVerifiedRowsRemainNonConsumableWhenProfileOrStoreIdentityIsUnverified(): void
    {
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['steps']['account_profile_binding'] = false;
        $ctrip['acceptance_receipt']['platform_hotel_status'] = 'unverified';
        $ctrip['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [101519],
            'accepted_record_ids' => [101519],
        ];
        unset($ctrip);
        $row = $this->row(101519, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 5921.18,
            'quantity' => 8,
            'history_status' => 'success',
            'validation_status' => 'verified',
        ]);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$row],
            $trust
        );
        $platform = $closure['platforms']['ctrip'];
        $fields = $this->fields($platform['fields']);

        self::assertSame('strict_readback', $fields['revenue']['status']);
        self::assertTrue($fields['revenue']['strict_final_gate']);
        self::assertFalse($fields['revenue']['identity_binding_verified']);
        self::assertFalse($fields['revenue']['revenue_analysis_consumable']);
        self::assertContains('identity_binding_not_verified', $fields['revenue']['revenue_analysis_blockers']);
        self::assertSame('identity_binding_not_verified', $platform['revenue_analysis']['blocked_reason']);
        self::assertSame('partial', $platform['identity_status']);
    }

    public function testReceiptTaskSourceAndTenantAreFailClosed(): void
    {
        $mismatchedTask = $this->row(101519, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 5921.18,
            'quantity' => 8,
            'sync_task_id' => 9999,
        ]);
        $missingTenant = $this->row(101810, 'ctrip', 'business', [
            'tenant_id' => null,
            'dimension' => 'semantic:ctrip_business_market_overview:booking_order_count',
            'book_order_num' => 4,
        ]);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [$mismatchedTask, $missingTenant],
            $this->trust()
        );
        $ctrip = $this->fields($closure['platforms']['ctrip']['fields']);

        self::assertSame('readback_failed', $ctrip['revenue']['status']);
        self::assertNull($ctrip['revenue']['value']);
        self::assertSame('readback_failed', $ctrip['order_count']['status']);
        self::assertSame([], $closure['platforms']['ctrip']['formal_record_ids']);
    }

    public function testDigestIsStableAcrossSetAndMapOrdering(): void
    {
        $rows = [$this->row(101519, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 0,
            'quantity' => 0,
        ])];
        $firstTrust = $this->trust();
        $secondTrust = $this->trust();
        $firstTrust['days'][0]['platforms'][0]['acceptance_receipt']['reason_codes'] = ['b', 'a'];
        $secondTrust['days'][0]['platforms'][0]['acceptance_receipt']['reason_codes'] = ['a', 'b'];
        $firstTrust['days'][0]['platforms'][0]['acceptance_receipt']['counts'] = ['saved' => 1, 'readback' => 1];
        $secondTrust['days'][0]['platforms'][0]['acceptance_receipt']['counts'] = ['readback' => 1, 'saved' => 1];

        $first = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            $rows,
            $firstTrust
        );
        $second = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            $rows,
            $secondTrust
        );

        self::assertSame($first['closure_digest'], $second['closure_digest']);
        self::assertSame($first['page_identity'], $second['page_identity']);
    }

    public function testProjectedReasonsDistinguishVerifiedReceiptFromMissingTrafficEvidence(): void
    {
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0]['acceptance_receipt'];
        $ctrip['reason_codes'] = [
            'hotel_binding_not_ready',
            'target_date_data_missing',
            'organized_save_missing',
            'database_readback_not_verified',
            'saved_readback_count_unverified',
            'exact_run_readback_scope_mismatch',
            'critical_fields_incomplete',
        ];
        $ctrip['counts'] = [
            'saved_readback_match' => true,
            'target_saved_readback_match' => true,
        ];
        $ctrip['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [101518, 101519, 101810],
            'accepted_record_ids' => [101518, 101519, 101810],
            'receipt_row_count' => 3,
            'receipt_current_row_count' => 3,
            'receipt_missing_row_count' => 0,
            'receipt_identity_mismatch_count' => 0,
            'mismatched_row_count' => 0,
        ];
        unset($ctrip);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [],
            $trust
        );
        $reasons = $closure['platforms']['ctrip']['latest_collection']['reason_codes'];

        self::assertNotContains('hotel_binding_not_ready', $reasons);
        self::assertNotContains('target_date_data_missing', $reasons);
        self::assertNotContains('organized_save_missing', $reasons);
        self::assertNotContains('database_readback_not_verified', $reasons);
        self::assertNotContains('saved_readback_count_unverified', $reasons);
        self::assertNotContains('exact_run_readback_scope_mismatch', $reasons);
        self::assertContains('required_traffic_hotel_identity_missing', $reasons);
        self::assertContains('required_traffic_target_date_data_missing', $reasons);
        self::assertContains('required_traffic_formal_row_missing', $reasons);
        self::assertContains('required_traffic_readback_not_verified', $reasons);
        self::assertContains('critical_fields_incomplete', $reasons);
    }

    public function testProjectedReasonsKeepOriginalReadbackGapsWhenExactScopeIsNotProven(): void
    {
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0]['acceptance_receipt'];
        $ctrip['reason_codes'] = [
            'hotel_binding_not_ready',
            'organized_save_scope_conflict',
            'database_readback_not_verified',
            'saved_readback_count_unverified',
            'exact_run_readback_scope_mismatch',
        ];
        $ctrip['counts'] = [
            'saved_readback_match' => true,
            'target_saved_readback_match' => true,
        ];
        $ctrip['run_readback_scope'] = [
            'status' => 'exact_run_readback_scope_mismatch',
            'receipt_record_ids' => [101518],
            'accepted_record_ids' => [],
            'receipt_row_count' => 1,
            'receipt_current_row_count' => 0,
            'receipt_missing_row_count' => 1,
            'receipt_identity_mismatch_count' => 0,
            'mismatched_row_count' => 1,
        ];
        unset($ctrip);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [],
            $trust
        );
        $reasons = $closure['platforms']['ctrip']['latest_collection']['reason_codes'];

        self::assertContains('hotel_binding_not_ready', $reasons);
        self::assertContains('organized_save_scope_conflict', $reasons);
        self::assertContains('database_readback_not_verified', $reasons);
        self::assertContains('saved_readback_count_unverified', $reasons);
        self::assertContains('exact_run_readback_scope_mismatch', $reasons);
    }

    public function testBlockedPriorRecordRefsAndDigestAreStableAcrossInputOrdering(): void
    {
        $rows = [
            $this->row(2, 'ctrip', 'business'),
            $this->row(1, 'ctrip', 'business'),
        ];
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['acceptance_receipt']['sync_task_status'] = 'profile_session_not_ready';
        $ctrip['acceptance_receipt']['reason_codes'] = ['login_expired'];
        $ctrip['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'unverified',
            'receipt_record_ids' => [],
            'accepted_record_ids' => [],
        ];
        unset($ctrip);

        $first = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            $rows,
            $trust
        );
        $second = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            array_reverse($rows),
            $trust
        );

        self::assertSame(
            ['online_daily_data#1', 'online_daily_data#2'],
            $first['platforms']['ctrip']['excluded_prior_formal_record_refs']
        );
        self::assertSame($first['closure_digest'], $second['closure_digest']);
        self::assertSame($first['page_identity'], $second['page_identity']);
    }

    public function testPriorSameDayAmountCanVetoButNeverReplaceCurrentReceipt(): void
    {
        $priorBusiness = $this->row(101874, 'meituan', 'business', [
            'amount' => 7895.43,
            'quantity' => 12,
            'sync_task_id' => 4352,
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:traffic:business_data',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $currentOrder = $this->row(101926, 'meituan', 'order', [
            'amount' => 7025.14,
            'quantity' => 12,
            'book_order_num' => 8,
            'history_status' => 'success',
            'validation_status' => 'verified',
            'sync_task_id' => 4364,
            'data_period' => 'historical_daily',
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:orders:daily_summary',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 4364;
        $meituan['acceptance_receipt']['sync_task_id'] = 4364;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'exact_run_readback_scope_mismatch',
            'receipt_record_ids' => [101926],
            'accepted_record_ids' => [101926],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$priorBusiness, $currentOrder],
            $trust
        );
        $fields = $this->fields($closure['platforms']['meituan']['fields']);

        self::assertSame('caliber_uncertain', $fields['revenue']['status']);
        self::assertNull($fields['revenue']['value']);
        self::assertSame([101874, 101926], $fields['revenue']['source_record_ids']);
        self::assertCount(2, $fields['revenue']['observed_values']);
        self::assertFalse($fields['revenue']['current_receipt_binding_verified']);
        self::assertFalse($fields['revenue']['revenue_analysis_consumable']);
        self::assertSame('caliber_uncertain', $fields['adr']['status']);
        self::assertSame('readback_failed', $fields['exposure']['status']);
        self::assertSame(
            ['online_daily_data#101926'],
            $fields['source_record_id']['value']
        );
        self::assertSame(
            ['online_daily_data#101926'],
            $closure['platforms']['meituan']['current_receipt_record_refs']
        );
        self::assertSame(
            ['online_daily_data#101874'],
            $closure['platforms']['meituan']['semantic_veto_record_refs']
        );
    }

    public function testPriorAmountFromAnotherDataSourceCannotVetoCurrentReceipt(): void
    {
        $priorOtherSource = $this->row(101874, 'meituan', 'business', [
            'amount' => 7895.43,
            'quantity' => 12,
            'data_source_id' => 999,
            'sync_task_id' => 4352,
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:traffic:business_data',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $currentOrder = $this->row(101926, 'meituan', 'order', [
            'amount' => 7025.14,
            'quantity' => 12,
            'book_order_num' => 8,
            'history_status' => 'success',
            'validation_status' => 'verified',
            'sync_task_id' => 4364,
            'data_period' => 'historical_daily',
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:orders:daily_summary',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 4364;
        $meituan['acceptance_receipt']['sync_task_id'] = 4364;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [101926],
            'accepted_record_ids' => [101926],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$priorOtherSource, $currentOrder],
            $trust
        );
        $platform = $closure['platforms']['meituan'];
        $fields = $this->fields($platform['fields']);

        self::assertSame('strict_readback', $fields['revenue']['status']);
        self::assertSame(7025.14, $fields['revenue']['value']);
        self::assertSame([101926], $fields['revenue']['source_record_ids']);
        self::assertSame([], $platform['semantic_veto_record_refs']);
    }

    public function testCurrentReceiptQuarantinedZeroTrafficIsShownAsUncertainEvidenceNotUsableZero(): void
    {
        $currentOrder = $this->row(101926, 'meituan', 'order', [
            'amount' => 7025.14,
            'quantity' => 12,
            'book_order_num' => 8,
            'sync_task_id' => 4399,
            'data_period' => 'historical_daily',
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:orders:daily_summary',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $quarantinedTraffic = $this->row(102432, 'meituan', 'traffic', [
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
            'sync_task_id' => 4399,
            'data_period' => 'historical_daily',
            'validation_status' => 'quarantined',
            'validation_flags' => '["same_run_zero_traffic_conflicts_with_nonzero_orders"]',
            'snapshot_time' => '2026-08-24 02:27:33',
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:traffic:traffic',
                'exposure_to_browse_rate' => 0,
            ]], JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 4399;
        $meituan['acceptance_receipt']['sync_task_id'] = 4399;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [101926, 102432],
            'accepted_record_ids' => [101926, 102432],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$currentOrder, $quarantinedTraffic],
            $trust
        );
        $platform = $closure['platforms']['meituan'];
        $fields = $this->fields($platform['fields']);

        foreach (['exposure', 'visits', 'conversion'] as $fieldKey) {
            self::assertSame('caliber_uncertain', $fields[$fieldKey]['status']);
            self::assertNull($fields[$fieldKey]['value']);
            self::assertSame([102432], $fields[$fieldKey]['source_record_ids']);
            self::assertSame(0.0, $fields[$fieldKey]['observed_values'][0]['value']);
            self::assertContains(
                'same_run_zero_traffic_conflicts_with_nonzero_orders',
                $fields[$fieldKey]['quality_flags']
            );
            self::assertTrue($fields[$fieldKey]['formal_readback_verified']);
            self::assertTrue($fields[$fieldKey]['current_receipt_binding_verified']);
            self::assertTrue($fields[$fieldKey]['exact_run_scope_verified']);
            self::assertFalse($fields[$fieldKey]['strict_final_gate']);
            self::assertFalse($fields[$fieldKey]['revenue_analysis_consumable']);
        }
        self::assertSame(
            ['online_daily_data#101926', 'online_daily_data#102432'],
            $platform['current_receipt_all_record_refs']
        );
        self::assertSame(
            ['online_daily_data#101926'],
            $platform['current_receipt_record_refs']
        );
        self::assertSame(
            ['online_daily_data#102432'],
            $platform['current_receipt_non_eligible_record_refs']
        );
        self::assertSame([], $platform['semantic_veto_record_refs']);
        self::assertContains(102432, $platform['formal_record_ids']);
        self::assertSame('blocked', $platform['revenue_analysis']['status']);
        self::assertSame('field_unavailable', $fields['cancellation']['status']);
        self::assertSame('field_unavailable', $fields['sellable']['status']);
        self::assertSame('field_unavailable', $fields['bookable']['status']);
    }

    public function testCurrentCtripZeroOrderProjectionConflictingWithBusinessSummaryIsUncertain(): void
    {
        $market = $this->row(102231, 'ctrip', 'business', [
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount+order_amount_last_week+amount_rank:data.amount',
            'amount' => 6647.02,
            'quantity' => 11,
            'sync_task_id' => 4398,
            'data_period' => 'historical_daily',
        ]);
        $orders = $this->row(102235, 'ctrip', 'business', [
            'dimension' => 'semantic:ctrip_business_market_overview:booking_order_count',
            'book_order_num' => 0,
            'sync_task_id' => 4398,
            'data_period' => 'historical_daily',
        ]);
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['sync_task_id'] = 4398;
        $ctrip['acceptance_receipt']['sync_task_id'] = 4398;
        $ctrip['acceptance_receipt']['data_period'] = 'historical_daily';
        $ctrip['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [102231, 102235],
            'accepted_record_ids' => [102231, 102235],
        ];
        unset($ctrip);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$market, $orders],
            $trust
        );
        $field = $this->fields($closure['platforms']['ctrip']['fields'])['order_count'];

        self::assertSame('caliber_uncertain', $field['status']);
        self::assertNull($field['value']);
        self::assertSame(0.0, $field['observed_values'][0]['value']);
        self::assertSame(102235, $field['observed_values'][0]['source_record_id']);
        self::assertSame([102231, 102235], $field['source_record_ids']);
        self::assertContains(
            'same_run_zero_order_count_conflicts_with_nonzero_revenue_or_room_nights',
            $field['quality_flags']
        );
        self::assertFalse($field['revenue_analysis_consumable']);
    }

    public function testHotel80VerifiedMeituanTrafficCarriesTheCompleteCanonicalIdentityChain(): void
    {
        $raw = [
            'source_trace_id' => 'meituan:test-trace',
            'source_url_hash' => str_repeat('a', 64),
            'row' => [
                '_capture_source' => 'xhr:traffic:traffic',
                '_source_path' => 'data.myHotel',
                'exposure_to_browse_rate' => 14.49,
            ],
            'field_facts' => [
                [
                    'metric_key' => 'list_exposure',
                    'source_key' => 'exposureUV',
                    'source_path' => 'data.myHotel.exposureUV',
                    'storage_field' => 'online_daily_data.list_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'capture_source' => 'xhr:traffic:traffic',
                        'source_trace_id' => 'meituan:test-trace',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ],
                [
                    'metric_key' => 'detail_exposure',
                    'source_key' => 'intentionUV',
                    'source_path' => 'data.myHotel.intentionUV',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'capture_source' => 'xhr:traffic:traffic',
                        'source_trace_id' => 'meituan:test-trace',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ],
                [
                    'metric_key' => 'flow_rate',
                    'source_key' => 'exposure_to_browse_rate',
                    'source_path' => 'data.myHotel.exposure_to_browse_rate',
                    'storage_field' => 'online_daily_data.flow_rate',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'capture_source' => 'xhr:traffic:traffic',
                        'source_trace_id' => 'meituan:test-trace',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ],
            ],
        ];
        $row = $this->row(102476, 'meituan', 'traffic', [
            'data_period' => 'historical_daily',
            'history_status' => 'success',
            'validation_status' => 'verified',
            'list_exposure' => 1422,
            'detail_exposure' => 206,
            'flow_rate' => 14.49,
            'sync_task_id' => 4427,
            'source_trace_id' => 'meituan:test-trace',
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 4427;
        $meituan['acceptance_receipt']['sync_task_id'] = 4427;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['platform_hotel_id'] = '1029642156589279';
        $meituan['acceptance_receipt']['source_method'] = 'browser_profile';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [102476],
            'accepted_record_ids' => [102476],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [$row],
            $trust
        );
        $fields = $this->fields($closure['platforms']['meituan']['fields']);

        self::assertSame(1422.0, $fields['exposure']['value']);
        self::assertSame(206.0, $fields['visits']['value']);
        self::assertSame(14.49, $fields['conversion']['value']);
        self::assertSame('strict_readback', $fields['exposure']['status']);
        self::assertSame('verified_calculation', $fields['conversion']['status']);
        self::assertSame([102476], $fields['exposure']['source_record_ids']);
        self::assertSame([102476], $fields['visits']['source_record_ids']);
        self::assertSame([102476], $fields['conversion']['source_record_ids']);
        self::assertSame(7, $fields['exposure']['tenant_id']);
        self::assertSame(80, $fields['exposure']['system_hotel_id']);
        self::assertSame('meituan', $fields['exposure']['platform']);
        self::assertSame('1029642156589279', $fields['exposure']['platform_store_id']);
        self::assertSame('verified', $fields['exposure']['store_profile_status']);
        self::assertSame('2026-08-23', $fields['exposure']['business_date']);
        self::assertSame('platform_data_sync_task#4427', $fields['exposure']['capture_ref']);
        self::assertSame(['xhr:traffic:traffic'], $fields['exposure']['endpoint_ids']);
        self::assertSame(['data.myHotel.exposureUV'], $fields['exposure']['source_paths']);
        self::assertSame(['exposureUV'], $fields['exposure']['source_keys']);
        self::assertSame(['list_exposure'], $fields['exposure']['normalized_metric_keys']);
        self::assertSame(['list_exposure'], $fields['exposure']['raw_metric_keys']);
        self::assertSame('exposure', $fields['exposure']['metric_key']);
        self::assertSame('meituan_exposure_users', $fields['exposure']['semantic_metric_key']);
        self::assertSame('source_defined', $fields['exposure']['semantic_metric_status']);
        self::assertSame('曝光人数', $fields['exposure']['semantic_label']);
        self::assertSame('people', $fields['exposure']['semantic_unit']);
        self::assertSame('ota_field_semantics.v1', $fields['exposure']['semantic_contract_version']);
        self::assertSame('曝光人数', $fields['exposure']['label']);
        self::assertSame('people', $fields['exposure']['unit']);
        self::assertSame([
            'normalized_metric_key' => 'list_exposure',
            'source_key' => 'exposureUV',
            'source_path' => 'data.myHotel.exposureUV',
            'storage_field' => 'online_daily_data.list_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
            'semantic_metric_key' => 'meituan_exposure_users',
            'semantic_label' => '曝光人数',
            'semantic_unit' => 'people',
            'semantic_contract_version' => 'ota_field_semantics.v1',
        ], $fields['exposure']['field_fact_identities'][0]);
        self::assertSame('meituan_detail_visitors', $fields['visits']['semantic_metric_key']);
        self::assertSame('商详访客数', $fields['visits']['semantic_label']);
        self::assertSame('people', $fields['visits']['semantic_unit']);
        self::assertSame('exposure_to_visit_rate', $fields['conversion']['semantic_metric_key']);
        self::assertSame('曝光到访率', $fields['conversion']['semantic_label']);
        self::assertSame('percent', $fields['conversion']['semantic_unit']);
        self::assertSame('derived_same_snapshot', $fields['conversion']['semantic_metric_status']);
        $conversionIdentities = array_column(
            $fields['conversion']['field_fact_identities'],
            'semantic_metric_key',
            'source_key'
        );
        self::assertSame('meituan_exposure_users', $conversionIdentities['exposureUV'] ?? null);
        self::assertSame('meituan_detail_visitors', $conversionIdentities['intentionUV'] ?? null);
        self::assertSame('exposure_to_visit_rate', $conversionIdentities['exposure_to_browse_rate'] ?? null);
        self::assertSame('verified', $fields['exposure']['validation_status']);
        self::assertSame('readback_verified', $fields['exposure']['readback_status']);
        self::assertTrue($fields['conversion']['same_snapshot_verified']);
        self::assertSame(
            $closure['page_identity'],
            $closure['consumer_contract']['closure_identity']
        );
    }

    public function testMeituanExposureWithWrongSourcePathDoesNotGainExposureUserSemantics(): void
    {
        $raw = [
            'source_trace_id' => 'meituan:wrong-path-trace',
            'source_url_hash' => str_repeat('c', 64),
            'row' => [
                '_capture_source' => 'xhr:traffic:traffic',
                '_source_path' => 'data.myHotel',
            ],
            'field_facts' => [[
                'metric_key' => 'list_exposure',
                'source_key' => 'exposureUV',
                'source_path' => 'data.myHotel.unknownExposure',
                'storage_field' => 'online_daily_data.list_exposure',
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic:traffic',
                    'source_trace_id' => 'meituan:wrong-path-trace',
                    'source_url_hash' => str_repeat('c', 64),
                ],
            ]],
        ];
        $row = $this->row(102477, 'meituan', 'traffic', [
            'data_period' => 'historical_daily',
            'history_status' => 'success',
            'validation_status' => 'verified',
            'list_exposure' => 1422,
            'detail_exposure' => null,
            'flow_rate' => null,
            'sync_task_id' => 4427,
            'source_trace_id' => 'meituan:wrong-path-trace',
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 4427;
        $meituan['acceptance_receipt']['sync_task_id'] = 4427;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['platform_hotel_id'] = '1029642156589279';
        $meituan['acceptance_receipt']['source_method'] = 'browser_profile';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [102477],
            'accepted_record_ids' => [102477],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [$row],
            $trust
        );
        $exposure = $this->fields($closure['platforms']['meituan']['fields'])['exposure'];

        self::assertNull($exposure['value']);
        self::assertSame('exposure_unspecified', $exposure['semantic_metric_key']);
        self::assertSame('caliber_uncertain', $exposure['semantic_metric_status']);
        self::assertSame('曝光指标（口径待确认）', $exposure['semantic_label']);
        self::assertSame('source_defined', $exposure['semantic_unit']);
        self::assertNotSame('meituan_exposure_users', $exposure['semantic_metric_key']);
    }

    public function testHotel80VerifiedCtripTrafficUsesOneCanonicalSnapshot(): void
    {
        $traceId = 'ctrip:test-trace';
        $urlHash = str_repeat('b', 64);
        $fieldFact = static fn(
            string $metricKey,
            string $sourceKey,
            string $sourcePath,
            string $storageField
        ): array => [
            'metric_key' => $metricKey,
            'source_key' => $sourceKey,
            'source_path' => $sourcePath,
            'storage_field' => $storageField,
            'status' => 'captured',
            'stored_value_present' => true,
            'capture_evidence' => [
                'capture_source' => 'xhr:traffic',
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
        ];
        $raw = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'row' => [
                'hotelId' => '130079194',
                'date' => '2026-08-23',
                'endpoint_id' => 'business_flow_transform',
                '_capture_source' => 'xhr:traffic',
                'listExposure' => 150,
                'detailExposure' => 35,
                'flowRate' => 23.33,
            ],
            'capture_evidence' => [
                'capture_source' => 'xhr:traffic',
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'field_facts' => [
                $fieldFact(
                    'list_exposure',
                    'listExposure',
                    '$[0].listExposure',
                    'online_daily_data.list_exposure'
                ),
                $fieldFact(
                    'detail_exposure',
                    'detailExposure',
                    '$[0].detailExposure',
                    'online_daily_data.detail_exposure'
                ),
                $fieldFact(
                    'flow_rate',
                    'flowRate',
                    '$[0].flowRate',
                    'online_daily_data.flow_rate'
                ),
            ],
        ];
        $row = $this->row(102604, 'ctrip', 'traffic', [
            'hotel_id' => '130079194',
            'data_period' => 'historical_daily',
            'history_status' => 'success',
            'validation_status' => 'verified',
            'list_exposure' => 150,
            'detail_exposure' => 35,
            'flow_rate' => 23.33,
            'sync_task_id' => 4428,
            'source_trace_id' => $traceId,
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]);
        $projectionRaw = $raw;
        $projectionRaw['row']['endpoint_id'] = 'traffic_flow_transform';
        $projection = $this->row(102482, 'ctrip', 'traffic', [
            'hotel_id' => '130079194',
            'data_period' => 'historical_daily',
            'history_status' => 'partial',
            'validation_status' => 'normal',
            'list_exposure' => 150,
            'detail_exposure' => 35,
            'flow_rate' => 23.33,
            'sync_task_id' => 4428,
            'source_trace_id' => $traceId,
            'raw_data' => json_encode($projectionRaw, JSON_THROW_ON_ERROR),
        ]);
        $trust = $this->trust();
        $ctrip = &$trust['days'][0]['platforms'][0];
        $ctrip['sync_task_id'] = 4428;
        $ctrip['acceptance_receipt']['sync_task_id'] = 4428;
        $ctrip['acceptance_receipt']['data_period'] = 'historical_daily';
        $ctrip['acceptance_receipt']['platform_hotel_id'] = '130079194';
        $ctrip['acceptance_receipt']['source_method'] = 'browser_profile';
        $ctrip['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [102482, 102604],
            'accepted_record_ids' => [102482, 102604],
        ];
        unset($ctrip);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7, 'name' => 'Hotel 80'],
            '2026-08-23',
            [$projection, $row],
            $trust
        );
        $fields = $this->fields($closure['platforms']['ctrip']['fields']);

        self::assertSame(150.0, $fields['exposure']['value']);
        self::assertSame(35.0, $fields['visits']['value']);
        self::assertSame(23.33, $fields['conversion']['value']);
        self::assertSame('strict_readback', $fields['exposure']['status']);
        self::assertSame('strict_readback', $fields['visits']['status']);
        self::assertSame('verified_calculation', $fields['conversion']['status']);
        self::assertSame([102604], $fields['exposure']['source_record_ids']);
        self::assertSame([102604], $fields['visits']['source_record_ids']);
        self::assertSame([102604], $fields['conversion']['source_record_ids']);
        self::assertSame(['business_flow_transform'], $fields['exposure']['endpoint_ids']);
        self::assertSame(['$[0].listExposure'], $fields['exposure']['source_paths']);
        self::assertSame(['$[0].detailExposure'], $fields['visits']['source_paths']);
        self::assertTrue($fields['conversion']['same_snapshot_verified']);
        self::assertTrue($fields['exposure']['revenue_analysis_consumable']);
        self::assertTrue($fields['visits']['revenue_analysis_consumable']);
        self::assertTrue($fields['conversion']['revenue_analysis_consumable']);
        self::assertContains(
            'verified_against_stored_flow_rate',
            $fields['conversion']['quality_flags']
        );
    }

    public function testDuplicateCurrentReceiptRecordsAreNeverSummedAndConflictsFailClosed(): void
    {
        $base = [
            'amount' => 7025.14,
            'quantity' => 12,
            'book_order_num' => 8,
            'data_period' => 'historical_daily',
            'history_status' => 'success',
            'validation_status' => 'verified',
            'sync_task_id' => 5001,
            'raw_data' => json_encode(['row' => [
                '_capture_source' => 'xhr:orders:daily_summary',
            ]], JSON_THROW_ON_ERROR),
        ];
        $first = $this->row(201, 'meituan', 'order', $base);
        $same = $this->row(202, 'meituan', 'order', $base + ['snapshot_time' => '2026-08-24 01:00:00']);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['sync_task_id'] = 5001;
        $meituan['acceptance_receipt']['sync_task_id'] = 5001;
        $meituan['acceptance_receipt']['data_period'] = 'historical_daily';
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'verified',
            'receipt_record_ids' => [201, 202],
            'accepted_record_ids' => [201, 202],
        ];
        unset($meituan);

        $deduplicated = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$first, $same],
            $trust
        );
        $deduplicatedRevenue = $this->fields(
            $deduplicated['platforms']['meituan']['fields']
        )['revenue'];
        self::assertSame(7025.14, $deduplicatedRevenue['value']);
        self::assertSame([201, 202], $deduplicatedRevenue['source_record_ids']);
        self::assertContains(
            'duplicate_current_receipt_records_deduplicated',
            $deduplicatedRevenue['quality_flags']
        );

        $conflicting = $same;
        $conflicting['amount'] = 7100.00;
        $conflict = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$first, $conflicting],
            $trust
        );
        $conflictedRevenue = $this->fields($conflict['platforms']['meituan']['fields'])['revenue'];
        self::assertNull($conflictedRevenue['value']);
        self::assertSame('caliber_uncertain', $conflictedRevenue['status']);
        self::assertCount(2, $conflictedRevenue['observed_values']);
        self::assertContains(
            'duplicate_current_receipt_records_conflicted',
            $conflictedRevenue['quality_flags']
        );
    }

    public function testOldDateWrongHotelWrongPlatformAndEmptyResponseCannotEnterCurrentScope(): void
    {
        $wrongDate = $this->row(301, 'meituan', 'traffic', [
            'data_date' => '2026-08-22',
            'list_exposure' => 999,
        ]);
        $wrongHotel = $this->row(302, 'meituan', 'traffic', [
            'system_hotel_id' => 81,
            'list_exposure' => 999,
        ]);
        $wrongPlatform = $this->row(303, 'ctrip-copy', 'traffic', [
            'list_exposure' => 999,
        ]);
        $trust = $this->trust();
        $meituan = &$trust['days'][0]['platforms'][1];
        $meituan['acceptance_receipt']['run_readback_scope'] = [
            'status' => 'exact_run_readback_scope_mismatch',
            'receipt_record_ids' => [9999],
            'accepted_record_ids' => [9999],
        ];
        unset($meituan);

        $closure = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [$wrongDate, $wrongHotel, $wrongPlatform],
            $trust
        );
        $fields = $this->fields($closure['platforms']['meituan']['fields']);
        self::assertNull($fields['exposure']['value']);
        self::assertSame('readback_failed', $fields['exposure']['status']);
        self::assertSame([], $fields['exposure']['source_record_ids']);

        $empty = DualOtaFieldClosureService::evaluate(
            ['id' => 80, 'tenant_id' => 7],
            '2026-08-23',
            [],
            ['days' => []]
        );
        self::assertSame(
            'source_missing',
            $this->fields($empty['platforms']['meituan']['fields'])['exposure']['status']
        );
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(int $id, string $platform, string $dataType, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'source' => $platform,
            'data_type' => $dataType,
            'data_date' => '2026-08-23',
            'data_period' => 'realtime_snapshot',
            'history_status' => 'partial',
            'validation_status' => 'normal',
            'validation_flags' => '[]',
            'readback_verified' => 1,
            'data_source_id' => $platform === 'ctrip' ? 25 : 101,
            'sync_task_id' => $platform === 'ctrip' ? 4351 : 4352,
            'snapshot_time' => '2026-08-23 23:50:54',
            'raw_data' => '{}',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function trust(): array
    {
        $platform = static fn(string $name, int $sourceId, int $taskId, array $recordIds): array => [
            'platform' => $name,
            'acceptance_status' => 'partial',
            'target_date' => '2026-08-23',
            'p0_status' => 'blocked',
            'sync_task_status' => 'partial_success',
            'steps' => [
                'source' => true,
                'account_profile_binding' => true,
                'hotel' => true,
                'date' => true,
            ],
            'acceptance_receipt' => [
                'status' => 'partial',
                'target_date' => '2026-08-23',
                'target_date_status' => 'matched',
                'platform_hotel_status' => 'verified',
                'data_source_id' => $sourceId,
                'sync_task_id' => $taskId,
                'sync_task_status' => 'partial_success',
                'data_period' => 'realtime_snapshot',
                'reason_codes' => ['critical_fields_incomplete'],
                'run_readback_scope' => [
                    'status' => 'verified',
                    'receipt_record_ids' => $recordIds,
                    'accepted_record_ids' => $recordIds,
                ],
                'claim_allowed' => false,
            ],
        ];

        return [
            'days' => [[
                'date' => '2026-08-23',
                'platforms' => [
                    $platform('ctrip', 25, 4351, [101518, 101519, 101810]),
                    $platform('meituan', 101, 4352, [101874, 101917, 101919, 101931]),
                ],
            ]],
        ];
    }

    /** @param array<int,array<string,mixed>> $fields @return array<string,array<string,mixed>> */
    private function fields(array $fields): array
    {
        return array_column($fields, null, 'key');
    }
}
