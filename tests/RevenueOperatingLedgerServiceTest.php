<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperatingLedgerService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use app\service\RevenueCockpitApprovalService;
use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\facade\Config;

final class RevenueOperatingLedgerServiceTest extends TestCase
{
    private function scope(array $changes = []): array
    {
        return array_replace(['tenant_id' => 9, 'hotel_id' => 80, 'platforms' => ['ctrip'],
            'start_date' => '2026-08-20', 'end_date' => '2026-08-20', 'evidence_mode' => 'synthetic'], $changes);
    }
    private function entry(string $key, mixed $value, array $changes = []): array
    {
        return array_replace(['tenant_id' => 9, 'hotel_id' => 80, 'platform' => 'ctrip', 'business_date' => '2026-08-20',
            'metric_key' => $key, 'value' => $value, 'currency' => 'CNY', 'unit' => 'yuan',
            'date_basis' => match ($key) { 'settlement_amount' => 'settlement_date', 'refund_amount' => 'refund_date', 'fee_amount' => 'fee_date', default => 'booking_date' },
            'quality_status' => 'readback_verified', 'readback_verified' => true, 'source_refs' => ['online_daily_data#101'],
            'source_field' => $key, 'source_version' => 'synthetic-v1', 'collected_at' => '2026-08-21T01:00:00+08:00',
            'platform_hotel_id' => 'synthetic-store',
            'reconciliation_group' => 'synthetic-cohort-A', 'evidence_mode' => 'synthetic'], $changes);
    }
    private function metric(array $ledger, string $key): array
    {
        return array_values(array_filter($ledger['metrics'], static fn(array $m): bool => $m['metric_key'] === $key))[0];
    }
    public function testSameDayOrderIsNotSettlementAndUnknownRemainderIsNeverBalanced(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(), [
            $this->entry('order_amount', '1000.00'), $this->entry('settlement_amount', 800),
            $this->entry('fee_amount', 100), $this->entry('refund_amount', 50),
        ]);
        $diff = $ledger['differences'][0];
        self::assertEquals(200, $diff['observed_difference']);
        self::assertEquals(150, $diff['explained_difference']);
        self::assertEquals(50, $diff['unexplained_difference']);
        self::assertSame('unexplained', $diff['status']);
        self::assertFalse($ledger['policy']['gop_calculable']);
        self::assertNull($this->metric($ledger, 'room_revenue')['value']);
    }
    public function testCrossPeriodRefundRemainsVisibleWithoutReattributingItToTodaysOrders(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(), [
            $this->entry('order_amount', 1000), $this->entry('settlement_amount', 800),
            $this->entry('refund_amount', 200, ['origin_business_date' => '2026-07-31']),
        ]);
        $diff = $ledger['differences'][0];
        self::assertEquals(200, $this->metric($ledger, 'refund_amount')['value']);
        self::assertEquals(0, $diff['explained_difference']);
        self::assertEquals(200, $diff['unexplained_difference']);
        self::assertSame('cross_period', $diff['components'][0]['status']);
        self::assertSame('2026-07-31', $diff['components'][0]['origin_business_date']);
    }
    public function testCoincidentalEqualAmountsOrUnlinkedFeesDoNotProveReconciliation(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(), [
            $this->entry('order_amount', 1000, ['reconciliation_group' => '']),
            $this->entry('settlement_amount', 1000), $this->entry('fee_amount', 100),
        ]);
        self::assertSame('unexplained', $ledger['differences'][0]['status']);
        self::assertFalse($ledger['differences'][0]['cohort_comparable']);
        self::assertEquals(0, $ledger['differences'][0]['explained_difference']);
    }
    public function testCompleteLinkedEvidenceExplainsTheGapWhileEstimatedPmsFeeStaysSeparate(): void
    {
        $service = new RevenueOperatingLedgerService();
        $ledger = $service->build($this->scope(), [$this->entry('order_amount', 1000),
            $this->entry('settlement_amount', 800), $this->entry('fee_amount', 200)]);
        self::assertSame('explained', $ledger['differences'][0]['status']);
        self::assertEquals(0, $ledger['differences'][0]['unexplained_difference']);
        $pmsLayer = ['hotel' => ['tenant_id' => 9, 'system_hotel_id' => 80], 'business_date' => '2026-08-20',
            'pms_binding' => ['effective_provider' => 'meituan_cloud_pms'],
            'sources' => ['meituan_cloud_pms' => ['data_status' => 'readback_verified', 'facts' => ['room_revenue' => 500],
                'fact_statuses' => ['room_revenue' => ['status' => 'readback_verified']],
                'source' => ['table' => 'meituan_cloud_pms_captures', 'record_id' => 2]]]];
        $pmsLedger = $service->fromFactLayer($pmsLayer);
        $metrics = array_values(array_filter($pmsLedger['metrics'], static fn(array $m): bool => $m['platform'] === 'meituan_cloud_pms'));
        self::assertEquals(500, array_values(array_filter($metrics, static fn(array $m): bool => $m['metric_key'] === 'estimated_room_revenue'))[0]['value']);
        self::assertNull(array_values(array_filter($metrics, static fn(array $m): bool => $m['metric_key'] === 'room_revenue'))[0]['value']);
        self::assertFalse($pmsLedger['policy']['ota_is_whole_hotel']);
    }

    public static function incompatibleDeductionPeriods(): array
    {
        $cases = [];
        foreach (['fee_amount', 'refund_amount'] as $key) {
            foreach (['definition' => 'Different gross/net deduction basis', 'source_grain' => 'business:other_total',
                'date_basis' => 'stay_date'] as $field => $value) {
                foreach ([200, 0] as $amount) {
                    $cases[$key . ' ' . $field . ' amount ' . $amount] = [$key, [$field => $value], $amount];
                }
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatibleDeductionPeriods')]
    public function testConflictingDeductionPeriodCannotExplainTheGap(string $key, array $change, int $amount): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build(
            $this->scope(['end_date' => '2026-08-21']), $this->deductionPeriodEntries($key, $amount, $change)
        );
        $metric = $this->metric($ledger, $key);
        $difference = $ledger['differences'][0];
        self::assertSame('conflict', $metric['status']);
        self::assertNull($metric['value']);
        self::assertNull($metric['partial_value']);
        self::assertEquals([$amount, $amount], array_column($metric['days'], 'value'));
        self::assertEquals(2 * $amount, $difference['observed_difference']);
        self::assertTrue($difference['cohort_comparable']);
        self::assertSame('blocked', $difference['status']);
        self::assertNull($difference['explained_difference']);
        self::assertNull($difference['unexplained_difference']);
        self::assertContains('deduction_period_conflict', $difference['reason_codes']);
        self::assertCount(2, $difference['components']);
        foreach ($difference['components'] as $component) {
            self::assertSame($key, $component['metric_key']);
            self::assertEquals($amount, $component['observed_value']);
            self::assertNull($component['explained_value']);
            self::assertSame('unlinked', $component['status']);
            self::assertNotEmpty($component['source_refs']);
        }
    }

    public static function comparableDeductionPeriods(): array
    {
        return ['fees' => ['fee_amount', 200], 'refunds' => ['refund_amount', 200],
            'verified zero fees' => ['fee_amount', 0], 'verified zero refunds' => ['refund_amount', 0]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('comparableDeductionPeriods')]
    public function testComparableDeductionPeriodKeepsVerifiedMoneyAndZero(string $key, int $amount): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build(
            $this->scope(['end_date' => '2026-08-21']), $this->deductionPeriodEntries($key, $amount)
        );
        self::assertSame('ready', $this->metric($ledger, $key)['status']);
        self::assertEquals(2 * $amount, $this->metric($ledger, $key)['value']);
        $difference = $ledger['differences'][0];
        self::assertSame('explained', $difference['status']);
        self::assertEquals(2 * $amount, $difference['observed_difference']);
        self::assertEquals(2 * $amount, $difference['explained_difference']);
        self::assertEquals(0, $difference['unexplained_difference']);
        self::assertNotContains('deduction_period_conflict', $difference['reason_codes']);
        foreach ($difference['components'] as $component) {
            self::assertSame('linked_evidence', $component['status']);
            self::assertEquals($amount, $component['explained_value']);
        }
    }

    private function deductionPeriodEntries(string $key, int $amount, array $secondDayChanges = []): array
    {
        $entries = [];
        foreach (['2026-08-20', '2026-08-21'] as $index => $date) {
            $identity = ['business_date' => $date, 'source_refs' => ['online_daily_data#' . (201 + $index)],
                'reconciliation_group' => 'synthetic-cohort-' . $date, 'source_grain' => 'business:daily_total'];
            $entries[] = $this->entry('order_amount', 1000, $identity);
            $entries[] = $this->entry('settlement_amount', 1000 - $amount, $identity);
            $entries[] = $this->entry($key, $amount, array_replace($identity, $index === 1 ? $secondDayChanges : []));
        }
        return $entries;
    }

    public function testMissingDaysAndAllMissingNeverBecomeFullPeriodMoneyOrZero(): void
    {
        $service = new RevenueOperatingLedgerService();
        $scope = $this->scope(['end_date' => '2026-08-22']);
        $metric = $this->metric($service->build($scope, [$this->entry('order_amount', 123.45)]), 'order_amount');
        self::assertNull($metric['value']); self::assertEquals(123.45, $metric['partial_value']);
        self::assertSame(['2026-08-21', '2026-08-22'], $metric['missing_dates']);
        self::assertSame('非全期间金额', $metric['partial_label']);
        $empty = $service->build($scope, []);
        self::assertSame('blocked', $empty['status']);
        self::assertNull($this->metric($empty, 'order_amount')['value']);
        self::assertNull($this->metric($empty, 'order_amount')['partial_value']);
    }
    public function testKnownZeroAndCompleteRecoveryArePreserved(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(['end_date' => '2026-08-21']), [
            $this->entry('order_amount', 0), $this->entry('order_amount', 25, ['business_date' => '2026-08-21']),
        ]);
        $metric = $this->metric($ledger, 'order_amount');
        self::assertEquals(25, $metric['value']); self::assertSame('ready', $metric['status']);
        self::assertEquals(0, $metric['days'][0]['value']); self::assertNull($metric['partial_value']);
    }
    public function testCurrencyUnitMalformedNumbersAndUnverifiedSourceAreBlocked(): void
    {
        foreach ([['currency' => 'USD'], ['unit' => 'fen'], ['currency' => null], ['unit' => null],
            ['value' => '1,2'], ['value' => INF], ['value' => 1.123], ['readback_verified' => false], ['source_refs' => []]] as $change) {
            $ledger = (new RevenueOperatingLedgerService())->build($this->scope(), [$this->entry('order_amount', 100, $change)]);
            $metric = $this->metric($ledger, 'order_amount');
            self::assertNull($metric['value']); self::assertNull($metric['partial_value']);
            self::assertNotEmpty($metric['days'][0]['entries'][0]['reason_codes']);
        }
    }
    public function testDuplicateSnapshotsAreNotAddedAndConflictDoesNotChooseTheNewest(): void
    {
        $row = $this->entry('order_amount', 100);
        $service = new RevenueOperatingLedgerService();
        $copy = array_replace($row, ['source_refs' => ['online_daily_data#102'], 'source_version' => 'synthetic-v2']);
        $ledger = $service->build($this->scope(), [$row, $copy]);
        self::assertEquals(100, $this->metric($ledger, 'order_amount')['value']);
        self::assertSame(1, $this->metric($ledger, 'order_amount')['duplicate_snapshots']);
        self::assertCount(2, $this->metric($ledger, 'order_amount')['source_refs']);
        $conflict = $service->build($this->scope(), [$row, array_replace($copy, ['value' => 120])]);
        self::assertNull($this->metric($conflict, 'order_amount')['value']);
        self::assertSame('conflict', $this->metric($conflict, 'order_amount')['status']);
        self::assertCount(1, $conflict['conflicts']);
    }
    public function testTenantHotelPlatformDateAndSyntheticIsolationDoNotLeakRejectedEvidence(): void
    {
        $rows = [];
        foreach ([['tenant_id' => 10], ['hotel_id' => 81], ['platform' => 'meituan'], ['business_date' => '2026-08-19'],
            ['business_date' => '2026-02-30'], ['evidence_mode' => 'production']] as $change) {
            $rows[] = $this->entry('order_amount', 999999, $change + ['source_refs' => ['private_table#999']]);
        }
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(), $rows);
        self::assertCount(6, $ledger['rejected_entries']);
        self::assertStringNotContainsString('private_table', json_encode($ledger));
        self::assertStringNotContainsString('999999', json_encode($ledger));
        self::assertNull($this->metric($ledger, 'order_amount')['value']);
    }
    public function testDateBasisChangesInsidePeriodBlockAggregation(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build($this->scope(['end_date' => '2026-08-21']), [
            $this->entry('order_amount', 100),
            $this->entry('order_amount', 200, ['business_date' => '2026-08-21', 'date_basis' => 'stay_date']),
        ]);
        self::assertSame('conflict', $this->metric($ledger, 'order_amount')['status']);
        self::assertNull($this->metric($ledger, 'order_amount')['value']);
        self::assertNull($this->metric($ledger, 'order_amount')['partial_value']);
    }

    public static function incompatiblePeriodSemantics(): array
    {
        return [
            'gross versus net with different source grain' => ['Paid amount, excluding cancelled orders', 'order:paid_net'],
            'same grain does not make gross and net comparable' => ['Paid amount, excluding cancelled orders', 'business:booked_gross'],
            'same description does not make different source grain comparable' => ['Gross booked amount, including cancelled orders', 'order:paid_net'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompatiblePeriodSemantics')]
    public function testCrossDateDefinitionOrSourceGrainConflictBlocksPeriodMoney(string $secondDefinition, string $secondGrain): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build(
            $this->scope(['tenant_id' => 9002, 'hotel_id' => 902, 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']),
            $this->periodSemanticsEntries($secondDefinition, $secondGrain)
        );
        $metric = $this->metric($ledger, 'order_amount');
        self::assertSame('conflict', $metric['status']);
        self::assertNull($metric['value']);
        self::assertNull($metric['partial_value']);
        self::assertNull($metric['partial_label']);
        self::assertFalse($metric['date_basis_conflict'], 'A semantic conflict must not be mislabelled as a date basis conflict.');
        self::assertTrue($metric['period_semantic_conflict']);
        self::assertSame(['2026-09-01', '2026-09-02'], $metric['covered_dates']);
        self::assertSame([], $metric['missing_dates']);
        self::assertSame(['online_daily_data#901', 'online_daily_data#902'], $metric['source_refs']);
        self::assertSame([100, 200], array_map('intval', array_column($metric['days'], 'value')));
        self::assertCount(2, $metric['semantic_variants']);
        self::assertSame(['definition' => 'Gross booked amount, including cancelled orders', 'source_grain' => 'business:booked_gross',
            'business_dates' => ['2026-09-01'], 'source_refs' => ['online_daily_data#901']], $metric['semantic_variants'][0]);
        self::assertSame(['definition' => $secondDefinition, 'source_grain' => $secondGrain,
            'business_dates' => ['2026-09-02'], 'source_refs' => ['online_daily_data#902']], $metric['semantic_variants'][1]);
        self::assertSame('period_metric_semantics_conflict', $ledger['conflicts'][0]['reason']);
        self::assertSame($metric['semantic_variants'], $ledger['conflicts'][0]['semantic_variants']);
        self::assertNull($ledger['differences'][0]['observed_difference']);
    }

    public function testCrossDateSemanticConflictCannotBecomeAPartialSubtotalWhenAnotherDayIsMissing(): void
    {
        $ledger = (new RevenueOperatingLedgerService())->build(
            $this->scope(['tenant_id' => 9002, 'hotel_id' => 902, 'start_date' => '2026-09-01', 'end_date' => '2026-09-03']),
            $this->periodSemanticsEntries('Paid amount, excluding cancelled orders', 'order:paid_net')
        );
        $metric = $this->metric($ledger, 'order_amount');
        self::assertSame('conflict', $metric['status']);
        self::assertNull($metric['value']);
        self::assertNull($metric['partial_value']);
        self::assertNull($metric['partial_label']);
        self::assertSame(['2026-09-03'], $metric['missing_dates']);
    }

    public function testSameDefinitionAndSourceGrainRemainSummableAcrossDates(): void
    {
        $service = new RevenueOperatingLedgerService();
        $entries = $this->periodSemanticsEntries('Gross booked amount, including cancelled orders', 'business:booked_gross');
        $scope = $this->scope(['tenant_id' => 9002, 'hotel_id' => 902, 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']);
        $metric = $this->metric($service->build($scope, $entries), 'order_amount');
        self::assertSame('ready', $metric['status']);
        self::assertEquals(300, $metric['value']);
        self::assertFalse($metric['period_semantic_conflict']);
        self::assertCount(1, $metric['semantic_variants']);
        self::assertSame(['2026-09-01', '2026-09-02'], $metric['semantic_variants'][0]['business_dates']);
        self::assertSame(['online_daily_data#901', 'online_daily_data#902'], $metric['semantic_variants'][0]['source_refs']);
        self::assertNull($metric['partial_value']);
        $scope['end_date'] = '2026-09-03';
        $partial = $this->metric($service->build($scope, $entries), 'order_amount');
        self::assertSame('partial', $partial['status']);
        self::assertNull($partial['value']);
        self::assertEquals(300, $partial['partial_value']);
        self::assertSame('非全期间金额', $partial['partial_label']);
    }

    private function periodSemanticsEntries(string $secondDefinition, string $secondGrain): array
    {
        $common = ['tenant_id' => 9002, 'hotel_id' => 902, 'platform_hotel_id' => 'synthetic-period-store', 'date_basis' => 'booking_date'];
        return [
            $this->entry('order_amount', 100, $common + ['business_date' => '2026-09-01',
                'definition' => 'Gross booked amount, including cancelled orders', 'source_grain' => 'business:booked_gross',
                'source_refs' => ['online_daily_data#901']]),
            $this->entry('order_amount', 200, $common + ['business_date' => '2026-09-02',
                'definition' => $secondDefinition, 'source_grain' => $secondGrain,
                'source_refs' => ['online_daily_data#902']]),
        ];
    }
    public function testEqualValuesFromDifferentPlatformStoresOrUnprovenDetailGrainCannotBeDeduplicatedAsDailyTotals(): void
    {
        $service = new RevenueOperatingLedgerService();
        $ledger = $service->build($this->scope(), [$this->entry('order_amount', 100),
            $this->entry('order_amount', 100, ['platform_hotel_id' => 'different-store'])]);
        self::assertSame('conflict', $this->metric($ledger, 'order_amount')['status']);
        self::assertNull($this->metric($ledger, 'order_amount')['value']);
        $detail = $service->build($this->scope(), [$this->entry('order_amount', 100, ['grain' => 'order_detail'])]);
        self::assertNull($this->metric($detail, 'order_amount')['value']);
        self::assertContains('daily_total_grain_unverified', $this->metric($detail, 'order_amount')['days'][0]['entries'][0]['reason_codes']);
        $negative = $service->build($this->scope(), [$this->entry('fee_amount', -100)]);
        self::assertNull($this->metric($negative, 'fee_amount')['value']);
    }
    public function testStandardEtlKeepsFinancialDatesAndSourceWithoutGuessingLegacyUnits(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 101, 'system_hotel_id' => 80, 'hotel_id' => 'synthetic-store', 'source' => 'ctrip',
            'data_date' => '2026-08-20', 'data_type' => 'business', 'amount' => 1000,
            'history_status' => 'success', 'validation_status' => 'verified', 'readback_verified' => 1,
            'raw_data' => json_encode(['settlement_amount' => 800, 'settlement_date' => '2026-08-20',
                'refund_amount' => 200, 'refund_date' => '2026-08-20', 'original_order_date' => '2026-07-31',
                'currency' => 'CNY', 'amount_storage_unit' => 'yuan']),
        ]]);
        $entries = (new OtaRevenueMetricService())->ledgerEntries($dataset, 9, 80, 'ctrip');
        self::assertNotEmpty($entries);
        $refund = array_values(array_filter($entries, static fn(array $e): bool => $e['metric_key'] === 'refund_amount'))[0];
        self::assertSame('2026-07-31', $refund['origin_business_date']);
        self::assertSame('refund_date', $refund['date_basis']);
        self::assertSame(['online_daily_data#101'], $refund['source_refs']);
        self::assertArrayNotHasKey('raw_data', $refund);
        foreach ($dataset['fact_ota_daily'] as &$row) $row['monetary_unit_evidence'] = [];
        unset($row);
        $legacy = (new OtaRevenueMetricService())->ledgerEntries($dataset, 9, 80, 'ctrip');
        self::assertNull($legacy[0]['currency']); self::assertNull($legacy[0]['unit']);
    }
    private function overview(array $ledger): array
    {
        $date = '2026-08-20';
        return ['hotel_id' => 80, 'business_date' => $date, 'as_of_date' => RevenueOverviewDateContract::serverAsOfDate(),
            'as_of_date_contract_version' => RevenueOverviewDateContract::VERSION,
            'three_source_fact_layer' => ['hotel' => ['tenant_id' => 9, 'system_hotel_id' => 80, 'name' => 'synthetic L02'],
                'business_date' => $date, 'operating_ledger' => $ledger,
                'sources' => ['ctrip_ota' => ['data_status' => 'readback_verified', 'business_date' => $date, 'actual_business_date' => $date,
                    'facts' => ['revenue' => 1000], 'fact_statuses' => ['revenue' => ['status' => 'readback_verified']],
                    'source' => ['table' => 'online_daily_data', 'data_date' => $date, 'platform' => 'ctrip',
                        'row_ids' => [101], 'readback_status' => 'readback_verified']]]],
            'cockpit_strict_evidence' => ['contract_version' => 'revenue_cockpit_strict_evidence.v1', 'tenant_id' => 9,
                'hotel_id' => 80, 'business_date' => $date,
                'platforms' => ['ctrip' => ['accepted_row_ids' => [101], 'source_strict_readback' => true]]],
            'dual_ota_field_closure' => ['contract_version' => 'dual_ota_field_closure.v1', 'tenant_id' => 9,
                'hotel_id' => 80, 'business_date' => $date, 'closure_digest' => str_repeat('a', 64),
                'platforms' => ['ctrip' => ['status' => 'ready', 'revenue_analysis' => ['status' => 'ready'],
                    'current_collection_blocker_status' => null, 'current_receipt_record_ids' => [101]]]]];
    }
    public function testServerAttestationAndSqliteSnapshotSaveDuplicateExactVersionRecoveryAndOldData(): void
    {
        $service = new RevenueOperatingLedgerService();
        $ledger = $service->build($this->scope(), [$this->entry('order_amount', 1000),
            $this->entry('settlement_amount', 800), $this->entry('fee_amount', 100),
            $this->entry('refund_amount', 50, ['origin_business_date' => '2026-07-31'])]);
        $overview = $this->overview($ledger);
        $context = (new RevenueCockpitApprovalService())->evidenceContext($overview, 9, 80, '2026-08-20', 'ctrip');
        $attester = new RevenueDecisionViewModelAttestationService();
        $model = $attester->issue($overview, [], $context, 9, 80, '2026-08-20', 'ctrip');
        self::assertSame($ledger['version'], $model['operatingLedger']['version']);
        // Configure only synthetic storage; do not initialize the app or load an environment file.
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => getenv('SUXIOS_CACHE_PATH') . '/log']]], 'log');
        $original = Config::get('database', []);
        Config::set(['default' => 'l02_synthetic', 'connections' => ['l02_synthetic' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false]]], 'database');
        try {
            $columns = ['platform', 'business_date', 'contract_version', 'source_refs_json', 'metric_definitions_json',
                'visible_model_json', 'missing_items_json', 'evidence_summary_json', 'visible_model_digest', 'evidence_digest',
                'content_digest', 'idempotency_key', 'created_at'];
            Db::execute('CREATE TABLE revenue_decision_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, created_by INTEGER, '
                . implode(', ', array_map(static fn(string $c): string => $c . ' TEXT NOT NULL', $columns))
                . ', UNIQUE(tenant_id, system_hotel_id, created_by, content_digest), UNIQUE(tenant_id, idempotency_key))');
            $snapshots = new RevenueDecisionSnapshotService();
            $saved = $snapshots->saveFromOverview($overview, 9, 80, '2026-08-20', 'ctrip', 1, $model);
            $duplicate = $snapshots->saveFromOverview($overview, 9, 80, '2026-08-20', 'ctrip', 1, $model);
            self::assertSame($saved['id'], $duplicate['id']);
            self::assertSame('readback_verified', $saved['persistence_status']);
            self::assertNull($snapshots->readExact($saved['id'], 10, 80));
            self::assertNull($snapshots->readExact($saved['id'], 9, 81));
            $newLedger = $service->build($this->scope(), [$this->entry('order_amount', 1200, ['source_version' => 'synthetic-v2'])]);
            $newOverview = $this->overview($newLedger);
            $newContext = (new RevenueCockpitApprovalService())->evidenceContext($newOverview, 9, 80, '2026-08-20', 'ctrip');
            $newModel = $attester->issue($newOverview, [], $newContext, 9, 80, '2026-08-20', 'ctrip');
            $second = $snapshots->saveFromOverview($newOverview, 9, 80, '2026-08-20', 'ctrip', 1, $newModel);
            self::assertNotSame($saved['id'], $second['id']);
            $exact = $snapshots->readExact($saved['id'], 9, 80);
            self::assertSame($saved['visible_model'], $exact['visible_model']);
            self::assertSame($ledger['version'], $exact['visible_model']['operatingLedger']['version']);
            self::assertSame('matched_current', $snapshots->readExact($saved['id'], 9, 80, $overview)['evidence_identity_status']);
            self::assertSame('stale_current_evidence', $snapshots->readExact($saved['id'], 9, 80, $newOverview)['evidence_identity_status']);
            self::assertSame($second['id'], $snapshots->readLatest(9, 80, '2026-08-20', 'ctrip')['id']);
            $oldOverview = $overview; unset($oldOverview['three_source_fact_layer']['operating_ledger']);
            $oldModel = $attester->issue($oldOverview, [], $context, 9, 80, '2026-08-20', 'ctrip');
            $oldSaved = $snapshots->saveFromOverview($oldOverview, 9, 80, '2026-08-20', 'ctrip', 1, $oldModel);
            self::assertArrayNotHasKey('operatingLedger', $snapshots->readExact($oldSaved['id'], 9, 80)['visible_model']);
            $forged = $model; $forged['operatingLedger']['metrics'][0]['value'] = 999999;
            try { $snapshots->saveFromOverview($overview, 9, 80, '2026-08-20', 'ctrip', 1, $forged); self::fail('Forged money saved'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('unattested', $e->getMessage()); }
            self::assertSame(3, Db::name('revenue_decision_snapshots')->count());
            // Inject corruption only into this in-memory synthetic DB and verify failure + exact recovery.
            $storedJson = Db::name('revenue_decision_snapshots')->where('id', $saved['id'])->value('visible_model_json');
            Db::name('revenue_decision_snapshots')->where('id', $saved['id'])->update(['visible_model_json' => '{broken']);
            try { $snapshots->readExact($saved['id'], 9, 80); self::fail('Corrupt stored JSON accepted'); }
            catch (\RuntimeException $e) { self::assertSame('revenue_decision_snapshot_readback_json_invalid', $e->getMessage()); }
            Db::name('revenue_decision_snapshots')->where('id', $saved['id'])->update(['visible_model_json' => $storedJson]);
            self::assertSame($model, $snapshots->readExact($saved['id'], 9, 80)['visible_model']);
            file_put_contents(__DIR__ . '/../output/long-goal/synthetic-model.json', json_encode($model, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally { Db::connect('l02_synthetic')->close(); Config::set($original, 'database'); }
    }
    public function testProjectionRejectsTamperedOrForeignLedgerAndDoesNotIncludeOtherOta(): void
    {
        $service = new RevenueOperatingLedgerService();
        $ledger = $service->build($this->scope(['platforms' => ['ctrip', 'meituan']]), [
            $this->entry('order_amount', 100), $this->entry('order_amount', 900, ['platform' => 'meituan'])]);
        $overview = $this->overview($ledger);
        $projected = $service->forOverview($overview, 9, 80, '2026-08-20', 'ctrip');
        self::assertSame(['ctrip'], $projected['scope']['platforms']);
        self::assertStringNotContainsString('meituan', json_encode($projected));
        $overview['three_source_fact_layer']['operating_ledger']['metrics'][0]['value'] = 999;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version_mismatch');
        $service->forOverview($overview, 9, 80, '2026-08-20', 'ctrip');
    }
}
