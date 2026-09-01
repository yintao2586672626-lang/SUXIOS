<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperatingFinance;
use app\service\OtaSettlementFileParserService;
use app\service\OtaSettlementReconciliationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaSettlementReconciliationServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $databaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'ota_settlement_reconciliation_'
            . getmypid()
            . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('ota_settlement_line_facts')->delete(true);
        Db::name('ota_settlement_import_batches')->delete(true);
    }

    public function testPersistsExactScopedFactsRanksDiscrepanciesAndHashesReferences(): void
    {
        $result = $this->service()->importAndReadback(
            $this->scope('a'),
            $this->availableLines(),
            7
        );

        self::assertFalse($result['reused']);
        self::assertTrue($result['readback_verified']);
        self::assertSame('available', $result['batch_status']);
        self::assertSame([
            'tenant_id' => 8,
            'hotel_id' => 80,
            'source_hotel_id' => 80,
            'platform' => 'ctrip',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $result['scope']);
        self::assertSame(['line_count' => 2, 'available' => 2, 'partial' => 0, 'invalid' => 0], $result['counts']);
        self::assertSame(1500.0, $result['totals']['gross_amount']['value']);
        self::assertSame('complete_source_direct', $result['totals']['gross_amount']['basis']);
        self::assertSame(225.0, $result['totals']['commission_amount']['value']);
        self::assertSame(1225.0, $result['totals']['settlement_amount']['value']);
        self::assertSame(1225.0, $result['totals']['net_revenue']['value']);
        self::assertSame('complete_mixed', $result['totals']['net_revenue']['basis']);
        self::assertNull($result['totals']['subsidy_amount']['value']);
        self::assertSame('partial', $result['totals']['subsidy_amount']['basis']);
        self::assertSame('gross_amount', $result['basis_ledger']['components']['order_gross_amount']['metric_key']);
        self::assertSame(225.0, $result['basis_ledger']['components']['commission_amount']['value']);
        self::assertNull($result['basis_ledger']['components']['refund_amount']['value']);
        self::assertSame('partial', $result['basis_ledger']['components']['refund_amount']['basis']);
        self::assertSame('platform_subsidy_only', $result['basis_ledger']['components']['adjustment']['component_scope']);
        self::assertFalse($result['basis_ledger']['components']['adjustment']['generic_adjustment_amount_claimed']);
        self::assertSame(1225.0, $result['basis_ledger']['components']['settlement_amount']['value']);
        self::assertSame(1225.0, $result['basis_ledger']['components']['net_revenue']['value']);
        self::assertFalse($result['basis_ledger']['boundaries']['settlement_amount_is_net_revenue']);

        self::assertSame('blocked', $result['recovery_blocker']['status']);
        self::assertSame(1, $result['recovery_blocker']['selected_count']);
        $candidate = $result['recovery_blocker']['selected'];
        self::assertIsArray($candidate);
        self::assertSame('settlement_reconciliation_discrepancy', $candidate['reason_code']);
        self::assertSame(1, $candidate['source_line_no']);
        self::assertSame(50.0, $candidate['financial_impact']['value']);
        self::assertSame('derived_absolute_difference:net_revenue', $candidate['financial_impact']['basis']);
        self::assertFalse($candidate['financial_impact']['is_loss_claim']);
        self::assertFalse($candidate['boundaries']['whole_hotel_gop_claimed']);
        self::assertFalse($candidate['execution']['execution_intent_created']);
        self::assertSame($result['batch_fingerprint'], $candidate['evidence_refs']['batch_fingerprint']);

        self::assertCount(1, $result['ranked_discrepancies']);
        self::assertSame(1, $result['ranked_discrepancies'][0]['source_line_no']);
        self::assertSame(50.0, $result['ranked_discrepancies'][0]['discrepancy_amount']);
        self::assertSame(1, $result['ranked_discrepancies'][0]['discrepancy_rank']);
        self::assertSame(1, $result['lines'][0]['discrepancy_rank']);
        self::assertNull($result['lines'][1]['discrepancy_rank']);
        self::assertSame(
            'derived_aligned_gross_minus_commission',
            $result['lines'][1]['net_revenue_basis']
        );
        self::assertSame('gross_amount - commission_amount', $result['lines'][1]['net_revenue_formula']);
        self::assertFalse($result['authorization']['external_write_authorized']);
        self::assertFalse($result['authorization']['ota_write_authorized']);
        self::assertFalse($result['authorization']['pms_write_authorized']);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('CTRIP-ORDER-ALPHA', $serialized);
        self::assertStringNotContainsString('PMS-STAY-ALPHA', $serialized);
        $stored = Db::name('ota_settlement_line_facts')
            ->where('source_line_no', 1)
            ->find();
        self::assertIsArray($stored);
        self::assertSame(hash('sha256', 'ctrip|CTRIP-ORDER-ALPHA'), $stored['ota_order_ref_sha256']);
        self::assertSame(hash('sha256', 'pms|PMS-STAY-ALPHA'), $stored['pms_stay_ref_sha256']);
        self::assertArrayNotHasKey('ota_order_ref', $stored);
        self::assertArrayNotHasKey('pms_stay_ref', $stored);
    }

    public function testManualExportFileParsesPersistsAndReadsBackOneExactScopeWithoutPii(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_settlement_closure_' . bin2hex(random_bytes(6)) . '.csv';
        file_put_contents($file, implode("\n", [
            '行号,业务日期,平台订单号,PMS住宿号,客人姓名,手机号,成交总额,佣金金额,平台补贴,退款金额,结算金额,净收入,匹配状态,OTA对比金额,PMS对比金额,对比口径',
            '1,2026-08-10,CTRIP-FILE-1,PMS-FILE-1,测试客人,13800000000,1000,150,0,0,850,850,matched,850,850,net_revenue',
        ]));

        try {
            $parsed = (new OtaSettlementFileParserService())->parse($file, 'ctrip-settlement.csv');
            $scope = $this->scope('1');
            $scope['file_sha256'] = $parsed['file_sha256'];
            $scope['parser_version'] = $parsed['parser_version'];
            $scope['source_method'] = 'manual_export';
            $scope['source_quality_status'] = 'operator_attested';

            $saved = $this->service()->importAndReadback($scope, $parsed['lines'], 7);
            $readback = $this->service()->latestForScope(
                8,
                80,
                'ctrip',
                '2026-08-01',
                '2026-08-31'
            );

            self::assertSame('partial', $saved['batch_status']);
            self::assertTrue($saved['readback_verified']);
            self::assertSame($saved['batch_id'], $readback['batch_id']);
            self::assertSame($saved['batch_fingerprint'], $readback['batch_fingerprint']);
            self::assertSame(1000.0, $readback['basis_ledger']['components']['order_gross_amount']['value']);
            self::assertSame(150.0, $readback['basis_ledger']['components']['commission_amount']['value']);
            self::assertSame(0.0, $readback['basis_ledger']['components']['refund_amount']['value']);
            self::assertSame(0.0, $readback['basis_ledger']['components']['adjustment']['value']);
            self::assertSame(850.0, $readback['basis_ledger']['components']['settlement_amount']['value']);
            self::assertSame(850.0, $readback['basis_ledger']['components']['net_revenue']['value']);
            self::assertSame(
                'settlement_source_quality_review_required',
                $readback['recovery_blocker']['selected']['reason_code']
            );
            self::assertSame(1, $readback['recovery_blocker']['selected_count']);
            self::assertFalse($readback['recovery_blocker']['selected']['execution']['task_created']);
            $serialized = json_encode($readback, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('测试客人', $serialized);
            self::assertStringNotContainsString('13800000000', $serialized);
            self::assertStringNotContainsString('CTRIP-FILE-1', $serialized);
            self::assertStringNotContainsString('PMS-FILE-1', $serialized);
        } finally {
            @unlink($file);
        }
    }

    public function testChecksumReplayIsIdempotentAndRejectsDifferentParsedFacts(): void
    {
        $scope = $this->scope('b');
        $lines = $this->availableLines();
        $first = $this->service()->importAndReadback($scope, $lines);
        $second = $this->service('2026-08-31 11:30:00')->importAndReadback($scope, $lines, 99);

        self::assertFalse($first['reused']);
        self::assertTrue($second['reused']);
        self::assertSame($first['batch_id'], $second['batch_id']);
        self::assertSame($first['batch_fingerprint'], $second['batch_fingerprint']);
        self::assertSame($first['recovery_blocker'], $second['recovery_blocker']);
        self::assertSame(1, (int)Db::name('ota_settlement_import_batches')->count());
        self::assertSame(2, (int)Db::name('ota_settlement_line_facts')->count());

        $lines[0]['gross_amount'] = 999.0;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ota_settlement_readback_line_mismatch');
        $this->service()->importAndReadback($scope, $lines);
    }

    public function testExactReadbackFailureRollsBackBatchAndLines(): void
    {
        Db::execute(<<<'SQL'
CREATE TRIGGER corrupt_ota_settlement_line_after_insert
AFTER INSERT ON ota_settlement_line_facts
BEGIN
  UPDATE ota_settlement_line_facts
  SET line_fingerprint = '0000000000000000000000000000000000000000000000000000000000000000'
  WHERE id = NEW.id;
END
SQL);

        try {
            try {
                $this->service()->importAndReadback($this->scope('9'), $this->availableLines());
                self::fail('tampered exact readback must roll back the settlement batch');
            } catch (RuntimeException $error) {
                self::assertSame('ota_settlement_readback_line_mismatch', $error->getMessage());
            }

            self::assertSame(0, (int)Db::name('ota_settlement_import_batches')->count());
            self::assertSame(0, (int)Db::name('ota_settlement_line_facts')->count());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS corrupt_ota_settlement_line_after_insert');
        }
    }

    public function testSettlementAmountAloneNeverBecomesNetRevenue(): void
    {
        $result = $this->service()->importAndReadback($this->scope('c'), [[
            'source_line_no' => 1,
            'business_date' => '2026-08-12',
            'amount_scope' => 'settlement',
            'ota_order_ref' => 'CTRIP-ONLY-1',
            'settlement_amount' => 888,
            'settlement_amount_basis' => 'source_direct',
            'match_status' => 'ota_only',
            'discrepancy_amount' => 888,
            'discrepancy_basis' => 'source_direct_settlement',
        ]]);

        self::assertSame('partial', $result['batch_status']);
        self::assertSame(888.0, $result['lines'][0]['settlement_amount']);
        self::assertNull($result['lines'][0]['net_revenue']);
        self::assertSame('missing', $result['lines'][0]['net_revenue_basis']);
        self::assertContains('settlement_amount_not_net_revenue', $result['lines'][0]['gap_codes']);
        self::assertNull($result['totals']['net_revenue']['value']);
        self::assertSame('missing', $result['totals']['net_revenue']['basis']);
        self::assertSame('net_revenue_basis_missing', $result['recovery_blocker']['selected']['reason_code']);
        self::assertNull($result['recovery_blocker']['selected']['financial_impact']['value']);
        self::assertFalse($result['recovery_blocker']['selected']['financial_impact']['is_net_revenue_claim']);
    }

    public function testNegativeDirectDiscrepancyIsRejectedInsteadOfHidden(): void
    {
        $result = $this->service()->importAndReadback($this->scope('7'), [[
            'source_line_no' => 1,
            'business_date' => '2026-08-12',
            'amount_scope' => 'settlement',
            'ota_order_ref' => 'CTRIP-NEGATIVE-DISCREPANCY',
            'settlement_amount' => 888,
            'settlement_amount_basis' => 'source_direct',
            'match_status' => 'ota_only',
            'discrepancy_amount' => -888,
            'discrepancy_basis' => 'source_direct_settlement',
        ]]);

        self::assertSame('invalid', $result['batch_status']);
        self::assertSame('invalid', $result['lines'][0]['quality_status']);
        self::assertNull($result['lines'][0]['discrepancy_amount']);
        self::assertContains('discrepancy_amount_invalid', $result['lines'][0]['gap_codes']);
        self::assertSame([], $result['ranked_discrepancies']);
    }

    public function testFullyReconciledBatchCreatesNoRecoveryCandidate(): void
    {
        $line = $this->availableLines()[1];
        $result = $this->service()->importAndReadback($this->scope('8'), [$line]);

        self::assertSame('available', $result['batch_status']);
        self::assertSame('ready', $result['recovery_blocker']['status']);
        self::assertSame(0, $result['recovery_blocker']['candidate_count']);
        self::assertSame(0, $result['recovery_blocker']['selected_count']);
        self::assertNull($result['recovery_blocker']['selected']);
        self::assertSame(0, $result['recovery_blocker']['boundaries']['external_write_count']);
    }

    public function testDerivedNetRevenueRequiresAlignedScopeAndExplicitNonApplicableAdjustments(): void
    {
        $line = $this->availableLines()[1];
        $ready = $this->service()->importAndReadback($this->scope('d'), [$line]);

        self::assertSame('available', $ready['batch_status']);
        self::assertSame(425.0, $ready['lines'][0]['net_revenue']);
        self::assertSame('complete_derived', $ready['totals']['net_revenue']['basis']);

        unset($line['refund_amount_basis']);
        $blocked = $this->service()->importAndReadback($this->scope('e'), [$line]);
        self::assertSame('partial', $blocked['batch_status']);
        self::assertNull($blocked['lines'][0]['net_revenue']);
        self::assertContains(
            'net_revenue_derivation_prerequisites_missing',
            $blocked['lines'][0]['gap_codes']
        );
        self::assertContains('refund_amount_missing', $blocked['lines'][0]['gap_codes']);
    }

    public function testCommissionDerivedFromUnstoredRateCannotEnterNetRevenue(): void
    {
        $line = $this->availableLines()[1];
        $line['commission_amount_basis'] = 'derived_from_rate';

        $result = $this->service()->importAndReadback($this->scope('2'), [$line]);

        self::assertSame('invalid', $result['batch_status']);
        self::assertSame('invalid', $result['lines'][0]['quality_status']);
        self::assertContains('commission_amount_basis_invalid', $result['lines'][0]['gap_codes']);
        self::assertNull($result['lines'][0]['net_revenue']);
        self::assertNull($result['totals']['net_revenue']['value']);
    }

    public function testInvalidMoneyIsPersistedAsInvalidWithoutInventedZero(): void
    {
        $result = $this->service()->importAndReadback($this->scope('f'), [[
            'source_line_no' => 1,
            'business_date' => '2026-08-15',
            'amount_scope' => 'settlement',
            'ota_order_ref' => 'CTRIP-BAD-1',
            'pms_stay_ref' => 'PMS-BAD-1',
            'gross_amount' => 'not-a-number',
            'gross_amount_basis' => 'source_direct',
            'settlement_amount' => 100,
            'settlement_amount_basis' => 'source_direct',
            'net_revenue' => 100,
            'net_revenue_basis' => 'source_direct',
            'match_status' => 'matched',
            'ota_comparison_amount' => 100,
            'pms_comparison_amount' => 100,
            'comparison_basis' => 'net_revenue',
        ]]);

        self::assertSame('invalid', $result['batch_status']);
        self::assertSame('invalid', $result['lines'][0]['quality_status']);
        self::assertNull($result['lines'][0]['gross_amount']);
        self::assertSame('invalid', $result['lines'][0]['gross_amount_basis']);
        self::assertContains('gross_amount_invalid', $result['lines'][0]['gap_codes']);
        self::assertNull($result['totals']['gross_amount']['value']);
        self::assertSame('partial', $result['totals']['gross_amount']['basis']);
    }

    public function testControllerSeparatesSavedRequestFromSettlementBusinessResult(): void
    {
        $controller = (new \ReflectionClass(OperatingFinance::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(OperatingFinance::class, 'settlementImportResponse');
        $method->setAccessible(true);

        $invalid = $this->responsePayload($method->invoke($controller, [
            'readback_verified' => true,
            'batch_status' => 'invalid',
            'totals' => ['net_revenue' => ['value' => null]],
            'lines' => [['gap_codes' => ['commission_amount_basis_invalid']]],
        ], 'available'));
        self::assertSame('saved_and_readback_verified', $invalid['data']['request_status']);
        self::assertSame('invalid', $invalid['data']['business_result_status']);
        self::assertFalse($invalid['data']['business_success']);
        self::assertFalse($invalid['data']['usable_net_revenue_fact_created']);
        self::assertSame('settlement_attempt_invalid_no_usable_fact', $invalid['data']['warning_code']);
        self::assertStringContainsString('未形成可用净收入事实', $invalid['message']);

        $partial = $this->responsePayload($method->invoke($controller, [
            'readback_verified' => true,
            'batch_status' => 'partial',
            'totals' => ['net_revenue' => ['value' => 850]],
            'lines' => [],
        ], 'available'));
        self::assertFalse($partial['data']['business_success']);
        self::assertTrue($partial['data']['usable_net_revenue_fact_created']);
        self::assertSame('settlement_batch_partial_review_required', $partial['data']['warning_code']);

        $available = $this->responsePayload($method->invoke($controller, [
            'readback_verified' => true,
            'batch_status' => 'available',
            'totals' => ['net_revenue' => ['value' => 850]],
            'lines' => [],
        ], 'available'));
        self::assertTrue($available['data']['business_success']);
        self::assertTrue($available['data']['usable_net_revenue_fact_created']);
        self::assertNull($available['data']['warning_code']);
        self::assertSame('available', $available['message']);
    }

    public function testSubmittedLinesImportDerivesServerOwnedPayloadIdentity(): void
    {
        $service = $this->service();
        $lines = $this->availableLines();
        $scope = $this->scope('f');
        $expected = $service->submittedLinesSha256($lines);
        $reorderedKeys = array_map(
            static fn(array $line): array => array_reverse($line, true),
            $lines
        );

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $expected);
        self::assertSame($expected, $service->submittedLinesSha256($reorderedKeys));

        $first = $service->importSubmittedLinesAndReadback($scope, $lines, 7);
        self::assertSame($expected, $first['source']['file_sha256']);
        self::assertNotSame($scope['file_sha256'], $first['source']['file_sha256']);

        $changed = $lines;
        $changed[0]['net_revenue'] = 799;
        $second = $service->importSubmittedLinesAndReadback($scope, $changed, 7);
        self::assertNotSame($first['source']['file_sha256'], $second['source']['file_sha256']);
        self::assertNotSame($first['batch_id'], $second['batch_id']);
    }

    public function testInvalidRowsCannotPolluteCompleteTotalsOrDiscrepancyRanking(): void
    {
        $lines = [$this->availableLines()[0], [
            'source_line_no' => 9,
            'business_date' => '2026-09-01',
            'amount_scope' => 'settlement',
            'ota_order_ref' => 'OUT-OF-PERIOD',
            'net_revenue' => 999,
            'net_revenue_basis' => 'source_direct',
            'match_status' => 'ota_only',
            'discrepancy_amount' => 999,
            'discrepancy_basis' => 'source_direct_net_revenue',
        ]];

        $result = $this->service()->importAndReadback($this->scope('0'), $lines);

        self::assertSame('partial', $result['batch_status']);
        self::assertSame('invalid', $result['lines'][1]['quality_status']);
        self::assertNull($result['totals']['net_revenue']['value']);
        self::assertSame('partial', $result['totals']['net_revenue']['basis']);
        self::assertCount(1, $result['ranked_discrepancies']);
        self::assertSame(1, $result['ranked_discrepancies'][0]['source_line_no']);
    }

    public function testMissingBusinessDateAndAmountScopeCannotCreateCompletePeriodRevenue(): void
    {
        $result = $this->service()->importAndReadback($this->scope('4'), [[
            'source_line_no' => 1,
            'ota_order_ref' => 'OTA-1',
            'pms_stay_ref' => 'PMS-1',
            'net_revenue' => 80,
            'net_revenue_basis' => 'source_direct',
            'match_status' => 'matched',
        ]]);

        self::assertSame('invalid', $result['batch_status']);
        self::assertSame('invalid', $result['lines'][0]['quality_status']);
        self::assertContains('business_date_missing', $result['lines'][0]['gap_codes']);
        self::assertContains('amount_scope_missing', $result['lines'][0]['gap_codes']);
        self::assertNull($result['totals']['net_revenue']['value']);
        self::assertSame('partial', $result['totals']['net_revenue']['basis']);
    }

    public function testManualExportCannotSelfPromoteToVerifiedSourceIdentity(): void
    {
        $scope = $this->scope('6');
        $scope['source_method'] = 'manual_export';
        $scope['source_quality_status'] = 'verified_export';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ota_settlement_manual_export_cannot_self_verify_source_identity');
        $this->service()->importAndReadback($scope, $this->availableLines());
    }

    public function testOperatorAttestedManualExportRemainsPartial(): void
    {
        $scope = $this->scope('5');
        $scope['source_method'] = 'manual_export';
        $scope['source_quality_status'] = 'operator_attested';

        $result = $this->service()->importAndReadback($scope, $this->availableLines());

        self::assertSame('partial', $result['batch_status']);
        self::assertTrue($result['readback_verified']);
    }

    public function testSameFileCanAppendAParserOrSourceQualitySuccessorWithoutOverwriting(): void
    {
        $firstScope = $this->scope('3');
        $firstScope['source_method'] = 'manual_export';
        $firstScope['source_quality_status'] = 'unverified';
        $first = $this->service('2026-08-30 10:00:00')->importAndReadback(
            $firstScope,
            $this->availableLines()
        );
        $secondScope = $firstScope;
        $secondScope['source_quality_status'] = 'operator_attested';
        $second = $this->service('2026-08-30 11:00:00')->importAndReadback(
            $secondScope,
            $this->availableLines()
        );

        self::assertNotSame($first['batch_id'], $second['batch_id']);
        self::assertSame($first['batch_id'], $second['supersedes_batch_id']);
        self::assertSame('source_quality_revision', $second['supersession_reason']);
        self::assertSame(2, (int)Db::name('ota_settlement_import_batches')->count());
        self::assertSame(4, (int)Db::name('ota_settlement_line_facts')->count());
    }

    public function testSameFileChecksumRemainsIsolatedByTenantHotelPlatformAndPeriod(): void
    {
        $lines = $this->availableLines();
        $checksum = str_repeat('9', 64);
        $firstScope = $this->scope('1');
        $firstScope['file_sha256'] = $checksum;
        $hotelScope = $firstScope;
        $hotelScope['hotel_id'] = 81;
        $platformScope = $firstScope;
        $platformScope['platform'] = 'meituan';
        $periodScope = $firstScope;
        $periodScope['period_start'] = '2026-07-01';
        $periodScope['period_end'] = '2026-07-31';
        foreach ([$firstScope, $hotelScope, $platformScope, $periodScope] as $scope) {
            $this->service()->importAndReadback($scope, $lines);
        }

        self::assertSame(4, (int)Db::name('ota_settlement_import_batches')->count());
        self::assertSame(8, (int)Db::name('ota_settlement_line_facts')->count());
        self::assertSame(
            1,
            (int)Db::name('ota_settlement_import_batches')
                ->where('tenant_id', 8)
                ->where('hotel_id', 80)
                ->where('platform', 'ctrip')
                ->where('period_start', '2026-08-01')
                ->where('period_end', '2026-08-31')
                ->count()
        );
    }

    public function testCanonicalHotelIdCanMigrateWithoutBreakingImmutableSourceFingerprint(): void
    {
        $saved = $this->service()->importAndReadback($this->scope('d'), $this->availableLines());
        Db::name('ota_settlement_import_batches')->where('id', $saved['batch_id'])->update(['hotel_id' => 90]);

        $readback = $this->service()->latestForScope(8, 90, 'ctrip', '2026-08-01', '2026-08-31');

        self::assertSame(90, $readback['scope']['hotel_id']);
        self::assertSame(80, $readback['scope']['source_hotel_id']);
        self::assertSame($saved['batch_fingerprint'], $readback['batch_fingerprint']);
    }

    public function testLatestForScopeReturnsExplicitMissingThenExactLatestReadback(): void
    {
        $service = $this->service();
        $missing = $service->latestForScope(8, 80, 'ctrip', '2026-08-01', '2026-08-31');
        self::assertSame('missing', $missing['read_status']);
        self::assertSame('missing', $missing['batch_status']);
        self::assertNull($missing['batch_id']);
        self::assertFalse($missing['readback_verified']);
        self::assertSame([], $missing['lines']);
        self::assertNull($missing['totals']['net_revenue']['value']);
        self::assertFalse($missing['authorization']['external_write_authorized']);
        self::assertSame('blocked', $missing['recovery_blocker']['status']);
        self::assertSame('settlement_export_missing', $missing['recovery_blocker']['selected']['reason_code']);
        self::assertSame([
            'tenant_id' => 8,
            'hotel_id' => 80,
            'source_hotel_id' => 80,
            'platform' => 'ctrip',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
        ], $missing['recovery_blocker']['selected']['scope']);

        $saved = $service->importAndReadback($this->scope('7'), $this->availableLines());
        $latest = $service->latestForScope(8, 80, 'CTRIP', '2026-08-01', '2026-08-31');
        self::assertSame('available', $latest['read_status']);
        self::assertTrue($latest['readback_verified']);
        self::assertSame($saved['batch_id'], $latest['batch_id']);
        self::assertSame($saved['batch_fingerprint'], $latest['batch_fingerprint']);
        self::assertSame($saved['lines'], $latest['lines']);
        self::assertSame($saved['ranked_discrepancies'], $latest['ranked_discrepancies']);
        self::assertSame($saved['recovery_blocker'], $latest['recovery_blocker']);

        $otherHotel = $service->latestForScope(8, 81, 'ctrip', '2026-08-01', '2026-08-31');
        self::assertSame('missing', $otherHotel['read_status']);
    }

    public function testLatestInvalidAttemptDoesNotHideLastNonInvalidFacts(): void
    {
        $available = $this->service('2026-08-30 10:00:00')->importAndReadback(
            $this->scope('a'),
            $this->availableLines()
        );
        $invalidLines = [[
            'source_line_no' => 1,
            'business_date' => '2026-09-01',
            'amount_scope' => 'settlement',
            'net_revenue' => 999,
            'net_revenue_basis' => 'source_direct',
        ]];
        $invalid = $this->service('2026-08-30 11:00:00')->importAndReadback(
            $this->scope('b'),
            $invalidLines
        );

        $latest = $this->service('2026-08-30 12:00:00')->latestForScope(
            8,
            80,
            'ctrip',
            '2026-08-01',
            '2026-08-31'
        );

        self::assertSame('invalid', $invalid['batch_status']);
        self::assertSame($available['batch_id'], $latest['batch_id']);
        self::assertSame('latest_non_invalid_with_newer_invalid_attempt', $latest['projection_status']);
        self::assertSame($invalid['batch_id'], $latest['latest_attempt']['batch_id']);
        self::assertSame('invalid', $latest['latest_attempt']['batch_status']);
        self::assertSame(1225.0, $latest['totals']['net_revenue']['value']);
    }

    public function testMigrationContainsScopedIdempotencyAndNoRawOrderIdentityColumns(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__)
            . '/database/migrations/20260830_create_ota_settlement_reconciliation.sql'
        );
        self::assertIsString($sql);
        self::assertStringContainsString('uk_ota_settlement_scope_file_version', $sql);
        self::assertStringContainsString('`supersedes_batch_id`', $sql);
        self::assertStringContainsString('`ota_order_ref_sha256` CHAR(64)', $sql);
        self::assertStringContainsString('`pms_stay_ref_sha256` CHAR(64)', $sql);
        self::assertStringContainsString('`net_revenue_basis` VARCHAR(80)', $sql);
        self::assertStringContainsString('`external_write_authorized`', $sql);
        self::assertStringContainsString('`source_hotel_id`', $sql);
        self::assertStringNotContainsString('`ota_order_ref` ', $sql);
        self::assertStringNotContainsString('`pms_stay_ref` ', $sql);
        self::assertStringNotContainsString('guest_name', strtolower($sql));
        self::assertStringNotContainsString('phone', strtolower($sql));
        self::assertStringContainsString('trg_ota_settlement_batch_no_update', $sql);
        self::assertStringContainsString('trg_ota_settlement_batch_no_delete', $sql);
        self::assertStringContainsString('trg_ota_settlement_line_no_update', $sql);
        self::assertStringContainsString('trg_ota_settlement_line_no_delete', $sql);
    }

    private function service(string $now = '2026-08-30 10:00:00'): OtaSettlementReconciliationService
    {
        return new OtaSettlementReconciliationService(
            static fn(): DateTimeImmutable => new DateTimeImmutable($now)
        );
    }

    /** @return array<string,mixed> */
    private function responsePayload(mixed $response): array
    {
        self::assertInstanceOf(\think\Response::class, $response);
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    /** @return array<string,mixed> */
    private function scope(string $hashCharacter): array
    {
        return [
            'tenant_id' => 8,
            'hotel_id' => 80,
            'platform' => 'ctrip',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'file_sha256' => str_repeat($hashCharacter, 64),
            'source_evidence_sha256' => str_repeat('8', 64),
            'source_method' => 'authorized_api_export',
            'source_quality_status' => 'verified_export',
            'parser_version' => 'settlement-fixture.v1',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function availableLines(): array
    {
        return [
            [
                'source_line_no' => 1,
                'business_date' => '2026-08-10',
                'amount_scope' => 'settlement',
                'ota_order_ref' => 'CTRIP-ORDER-ALPHA',
                'pms_stay_ref' => 'PMS-STAY-ALPHA',
                'gross_amount' => 1000,
                'gross_amount_basis' => 'source_direct',
                'commission_amount' => 150,
                'commission_amount_basis' => 'source_direct',
                'subsidy_amount' => 0,
                'subsidy_amount_basis' => 'source_direct',
                'refund_amount' => 50,
                'refund_amount_basis' => 'source_direct',
                'settlement_amount' => 800,
                'settlement_amount_basis' => 'source_direct',
                'net_revenue' => 800,
                'net_revenue_basis' => 'source_direct',
                'match_status' => 'amount_mismatch',
                'ota_comparison_amount' => 850,
                'pms_comparison_amount' => 800,
                'comparison_basis' => 'net_revenue',
            ],
            [
                'source_line_no' => 2,
                'business_date' => '2026-08-11',
                'amount_scope' => 'settlement',
                'ota_order_ref' => 'CTRIP-ORDER-BETA',
                'pms_stay_ref' => 'PMS-STAY-BETA',
                'gross_amount' => 500,
                'gross_amount_basis' => 'source_direct',
                'commission_amount' => 75,
                'commission_amount_basis' => 'source_direct',
                'subsidy_amount_basis' => 'not_applicable',
                'refund_amount_basis' => 'not_applicable',
                'settlement_amount' => 425,
                'settlement_amount_basis' => 'source_direct',
                'net_revenue_derivation' => 'gross_minus_commission',
                'match_status' => 'matched',
                'ota_comparison_amount' => 425,
                'pms_comparison_amount' => 425,
                'comparison_basis' => 'net_revenue',
            ],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE ota_settlement_import_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  contract_version TEXT NOT NULL,
  tenant_id INTEGER NOT NULL,
  hotel_id INTEGER NOT NULL,
  source_hotel_id INTEGER NOT NULL,
  platform TEXT NOT NULL,
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  file_sha256 TEXT NOT NULL,
  source_evidence_sha256 TEXT NULL,
  source_method TEXT NOT NULL,
  source_quality_status TEXT NOT NULL,
  parser_version TEXT NOT NULL,
  supersedes_batch_id INTEGER NULL,
  supersession_reason TEXT NULL,
  batch_fingerprint TEXT NOT NULL,
  batch_status TEXT NOT NULL,
  line_count INTEGER NOT NULL,
  available_line_count INTEGER NOT NULL,
  partial_line_count INTEGER NOT NULL,
  invalid_line_count INTEGER NOT NULL,
  gross_amount_total NUMERIC NULL,
  gross_amount_total_basis TEXT NOT NULL,
  commission_amount_total NUMERIC NULL,
  commission_amount_total_basis TEXT NOT NULL,
  subsidy_amount_total NUMERIC NULL,
  subsidy_amount_total_basis TEXT NOT NULL,
  refund_amount_total NUMERIC NULL,
  refund_amount_total_basis TEXT NOT NULL,
  settlement_amount_total NUMERIC NULL,
  settlement_amount_total_basis TEXT NOT NULL,
  net_revenue_total NUMERIC NULL,
  net_revenue_total_basis TEXT NOT NULL,
  external_write_authorized INTEGER NOT NULL DEFAULT 0,
  imported_by INTEGER NOT NULL DEFAULT 0,
  imported_at TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tenant_id, hotel_id, platform, period_start, period_end, file_sha256, parser_version, source_quality_status)
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE ota_settlement_line_facts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL,
  source_line_no INTEGER NOT NULL,
  source_line_sha256 TEXT NOT NULL,
  line_fingerprint TEXT NOT NULL,
  business_date TEXT NULL,
  amount_scope TEXT NULL,
  ota_order_ref_sha256 TEXT NULL,
  pms_stay_ref_sha256 TEXT NULL,
  gross_amount NUMERIC NULL,
  gross_amount_basis TEXT NOT NULL,
  commission_amount NUMERIC NULL,
  commission_amount_basis TEXT NOT NULL,
  subsidy_amount NUMERIC NULL,
  subsidy_amount_basis TEXT NOT NULL,
  refund_amount NUMERIC NULL,
  refund_amount_basis TEXT NOT NULL,
  settlement_amount NUMERIC NULL,
  settlement_amount_basis TEXT NOT NULL,
  net_revenue NUMERIC NULL,
  net_revenue_basis TEXT NOT NULL,
  net_revenue_formula TEXT NULL,
  match_status TEXT NOT NULL,
  ota_comparison_amount NUMERIC NULL,
  pms_comparison_amount NUMERIC NULL,
  comparison_basis TEXT NULL,
  discrepancy_amount NUMERIC NULL,
  discrepancy_basis TEXT NOT NULL,
  quality_status TEXT NOT NULL,
  gap_codes_json TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(batch_id, source_line_no),
  UNIQUE(batch_id, source_line_sha256)
)
SQL);
    }
}
