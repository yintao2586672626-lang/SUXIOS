<?php
declare(strict_types=1);

use app\service\CanonicalOtaDailyNaturalAcceptanceService;
use app\service\CanonicalOtaInvestigationActionService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;

final class CanonicalOtaDailyNaturalAcceptanceServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxios-natural-acceptance-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testExactNaturalDualOtaAndFourChecksProduceVerifiedReceipt(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('verified', $result['status']);
        self::assertSame([], $result['reason_codes']);
        self::assertSame('verified', $result['natural_dispatch']['status']);
        self::assertSame('verified', $result['collection']['status']);
        self::assertSame('verified', $result['continuous_trust']['status']);
        self::assertSame('verified', $result['operations']['status']);
        self::assertSame(4, $result['operations']['trusted_analysis_check_count']);
        self::assertSame(0, $result['operations']['trusted_external_operation_count']);
        self::assertTrue($result['operations']['analysis_only']);
        self::assertFalse($result['external_action_triggered']);
        self::assertSame(1, $result['stability']['consecutive_verified_natural_days']);
        self::assertFalse($result['stability']['stable']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['content_digest']);
    }

    public function testLatestStoredStatusPublishesOnlyDigestVerifiedHotelScopedReceipt(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $service = $this->service();
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        file_put_contents(
            $fixture['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertTrue($status['receipt_available']);
        self::assertTrue($status['receipt_readback_verified']);
        self::assertSame('verified', $status['status']);
        self::assertSame('stability', $status['stage']);
        self::assertSame('2026-08-09', $status['target_date']);
        self::assertSame(['ctrip', 'meituan'], $status['expected_platforms']);
        self::assertSame('ctrip', $status['selected_platform']);
        self::assertSame(80, $status['operation_scope']['hotel_id']);
        self::assertSame('2026-08-09', $status['operation_scope']['target_date']);
        self::assertCount(4, $status['action_types']);
        self::assertSame(
            CanonicalOtaInvestigationActionService::actionTypesForPlatform('ctrip'),
            $status['action_types']
        );
        self::assertSame(4, $status['trusted_analysis_check_count']);
        self::assertSame(0, $status['trusted_external_operation_count']);
        self::assertTrue($status['analysis_only']);
        self::assertTrue($status['operation_readback_verified']);
        self::assertSame(1, $status['stability']['consecutive_verified_natural_days']);
        self::assertSame(3, $status['stability']['required_days']);
        self::assertFalse($status['stability']['stable']);
        self::assertFalse($status['sensitive_values_exposed']);
    }

    public function testLatestStoredStatusDoesNotHideNewestTamperedReceiptBehindOlderSuccess(): void
    {
        $service = $this->service();
        $older = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $olderReceipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $newer = $this->writeRun(
            '2026-08-09',
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            [],
            null,
            100
        );
        $tampered = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $newer['path'],
            $this->directory
        );
        $tampered['service_date'] = '2099-01-01';
        file_put_contents(
            $newer['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($tampered, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertTrue($status['receipt_available']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame('blocked', $status['status']);
        self::assertSame('receipt_validation', $status['stage']);
        self::assertSame(['daily_acceptance_digest_invalid'], $status['reason_codes']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
    }

    public function testLatestStoredStatusDoesNotFallBackWhenNewestDailyAttemptHasNoAcceptance(): void
    {
        $service = $this->service();
        $older = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $olderReceipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        $this->writeRun(
            '2026-08-09',
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            [],
            null,
            100
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(
            ['daily_acceptance_receipt_missing_for_latest_attempt'],
            $status['reason_codes']
        );
    }

    public function testLatestStoredStatusDoesNotTreatNaturalDatabaseFailureAsPreflightOnly(): void
    {
        $service = $this->service();
        $older = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $olderReceipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $newer = $this->writeRun(
            '2026-08-09',
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            1,
            false,
            [
                'child_receipt_present' => false,
                'child_receipt_count' => 0,
                'child_receipt_sha256' => '',
            ],
            null,
            100
        );
        $lines = array_values(array_filter(
            file($newer['path'], FILE_IGNORE_NEW_LINES) ?: [],
            static fn(string $line): bool => !str_starts_with($line, 'SUXIOS_AUTO_FETCH_RECEIPT=')
        ));
        $lines[] = 'dispatcher_database_preflight=blocked;reason=database_runtime_unavailable;initial_exit_code=1;verified_exit_code=1';
        $lines[] = 'dispatcher_preflight_result=blocked;reason=database_runtime_unavailable;ota_collection_started=false';
        $lines[] = '';
        file_put_contents($newer['path'], implode(PHP_EOL, $lines));

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['dispatcher_database_preflight_blocked'], $status['reason_codes']);
        self::assertSame('', $status['natural_run_id']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertSame(0, $status['trusted_analysis_check_count']);
    }

    public function testLatestStoredStatusRequiresExactRunnerReadbackMarker(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '77777777-7777-4777-8777-777777777777',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        file_put_contents(
            $fixture['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['daily_acceptance_readback_unverified'], $status['reason_codes']);
    }

    public function testLatestStoredStatusBlocksMalformedOrAmbiguousAcceptanceLines(): void
    {
        $service = $this->service();
        $malformed = $this->writeRun(
            '2026-08-09',
            '44444444-4444-4444-8444-444444444444',
            0,
            true
        );
        file_put_contents(
            $malformed['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX . '{invalid-json' . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        $malformedStatus = $service->latestStoredStatus(80, $this->directory);
        self::assertSame('blocked', $malformedStatus['status']);
        self::assertSame(['daily_acceptance_receipt_invalid'], $malformedStatus['reason_codes']);

        unlink($malformed['path']);
        $ambiguous = $this->writeRun(
            '2026-08-09',
            '44444444-4444-4444-8444-444444444444',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $ambiguous['path'],
            $this->directory
        );
        $otherHotel = $receipt;
        $otherHotel['hotel_id'] = 81;
        file_put_contents(
            $ambiguous['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($otherHotel, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        $ambiguousStatus = $service->latestStoredStatus(80, $this->directory);
        self::assertSame('blocked', $ambiguousStatus['status']);
        self::assertSame(['daily_acceptance_receipt_ambiguous'], $ambiguousStatus['reason_codes']);
    }

    public function testLatestStoredStatusBlocksMalformedStartForCurrentContractLog(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '33333333-3333-4333-8333-333333333333',
            0,
            true
        );
        $contents = (string)file_get_contents($fixture['path']);
        $contents = (string)preg_replace(
            '/^SUXIOS_OTA_DISPATCHER_PROVENANCE=\{.*\}\R/m',
            'SUXIOS_OTA_DISPATCHER_PROVENANCE={invalid-json' . PHP_EOL,
            $contents,
            1
        );
        file_put_contents($fixture['path'], $contents);

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(['dispatcher_start_receipt_invalid'], $status['reason_codes']);
    }

    public function testLatestStoredStatusRejectsValidOldReceiptCopiedIntoNewRunLog(): void
    {
        $service = $this->service();
        $old = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $oldReceipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $old['path'],
            $this->directory
        );
        $new = $this->writeRun(
            '2026-08-09',
            '22222222-2222-4222-8222-222222222222',
            0,
            true,
            [],
            null,
            100
        );
        file_put_contents(
            $new['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($oldReceipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['daily_acceptance_dispatcher_run_mismatch'], $status['reason_codes']);
    }

    public function testLatestStoredStatusRequiresReceiptSourcesToMatchDispatcherScope(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '22222222-2222-4222-8222-222222222222',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        $lines = file($fixture['path'], FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as &$line) {
            if (!str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                continue;
            }
            $provenance = json_decode(substr(
                $line,
                strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
            ), true);
            if (!is_array($provenance)) {
                continue;
            }
            $provenance['scope']['source_ids'] = [99, 100];
            $line = 'SUXIOS_OTA_DISPATCHER_PROVENANCE=' . json_encode(
                $provenance,
                JSON_UNESCAPED_SLASHES
            );
        }
        unset($line);
        $lines[] = CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
            . json_encode($receipt, JSON_UNESCAPED_SLASHES);
        $lines[] = 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1';
        $lines[] = '';
        file_put_contents($fixture['path'], implode(PHP_EOL, $lines));

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['daily_acceptance_receipt_scope_mismatch'], $status['reason_codes']);
    }

    public function testLatestStoredStatusDoesNotFallBackPastDuplicateStartWithoutScopeMarker(): void
    {
        $service = $this->service();
        $older = $this->writeRun(
            '2026-08-09',
            '11111111-1111-4111-8111-111111111111',
            0,
            true
        );
        $olderReceipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $newer = $this->writeRun(
            '2026-08-09',
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            [],
            null,
            100
        );
        $lines = file($newer['path'], FILE_IGNORE_NEW_LINES) ?: [];
        $startLine = '';
        $lines = array_values(array_filter($lines, static function (string $line) use (&$startLine): bool {
            if (str_starts_with($line, 'dispatcher_scope=')) {
                return false;
            }
            if (str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                $provenance = json_decode(substr(
                    $line,
                    strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
                ), true);
                if (is_array($provenance)
                    && strtolower(trim((string)($provenance['phase'] ?? ''))) === 'start'
                ) {
                    $startLine = $line;
                }
            }
            return true;
        }));
        self::assertNotSame('', $startLine);
        $lines[] = $startLine;
        $lines[] = '';
        file_put_contents($newer['path'], implode(PHP_EOL, $lines));

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['dispatcher_provenance_ambiguous'], $status['reason_codes']);
    }

    public function testLatestStoredStatusRequiresUniqueFinishFromSameRun(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '22222222-2222-4222-8222-222222222222',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        $lines = file($fixture['path'], FILE_IGNORE_NEW_LINES) ?: [];
        $lines = array_values(array_filter($lines, static function (string $line): bool {
            if (!str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                return true;
            }
            $decoded = json_decode(substr(
                $line,
                strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
            ), true);
            return !is_array($decoded)
                || strtolower(trim((string)($decoded['phase'] ?? ''))) !== 'finish';
        }));
        $lines[] = CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
            . json_encode($receipt, JSON_UNESCAPED_SLASHES);
        $lines[] = 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1';
        $lines[] = '';
        file_put_contents($fixture['path'], implode(PHP_EOL, $lines));

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame(['dispatcher_finish_receipt_missing'], $status['reason_codes']);
    }

    public function testLatestStoredStatusRejectsSelfConsistentReceiptFromOldPipelineContract(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '66666666-6666-4666-8666-666666666666',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        $receipt['pipeline_contract_digest'] = str_repeat('f', 64);
        unset($receipt['content_digest']);
        $digest = new ReflectionMethod($service, 'digest');
        $receipt['content_digest'] = $digest->invoke($service, $receipt);
        file_put_contents(
            $fixture['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(['pipeline_contract_changed'], $status['reason_codes']);
    }

    public function testLatestStoredStatusNeverPromotesNonConsecutiveDatesToStable(): void
    {
        $service = $this->service();
        $fixture = $this->writeRun(
            '2026-08-09',
            '55555555-5555-4555-8555-555555555555',
            0,
            true
        );
        $receipt = $service->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );
        $receipt['stability'] = [
            'status' => 'verified',
            'consecutive_verified_natural_days' => 3,
            'required_days' => 3,
            'stable' => true,
            'dates' => ['2026-08-05', '2026-08-07', '2026-08-09'],
            'reason' => '',
        ];
        unset($receipt['content_digest']);
        $digest = new ReflectionMethod($service, 'digest');
        $receipt['content_digest'] = $digest->invoke($service, $receipt);
        file_put_contents(
            $fixture['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_SLASHES) . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('verified', $status['status']);
        self::assertSame('stability', $status['stage']);
        self::assertFalse($status['stability']['stable']);
        self::assertSame(1, $status['stability']['consecutive_verified_natural_days']);
        self::assertSame('collecting_evidence', $status['stability']['status']);
    }

    public function testLatestStoredStatusIsHotelScopedAndTruthfulBeforeFirstNaturalReceipt(): void
    {
        $service = $this->service();
        $empty = $service->latestStoredStatus(80, $this->directory);
        self::assertFalse($empty['receipt_available']);
        self::assertSame('no_evidence', $empty['status']);
        self::assertSame(['natural_dispatch_receipt_missing'], $empty['reason_codes']);

        $fixture = $this->writeRun(
            '2026-08-09',
            '88888888-8888-4888-8888-888888888888',
            0,
            true
        );
        file_put_contents(
            $fixture['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode([
                    'schema_version' => CanonicalOtaDailyNaturalAcceptanceService::SCHEMA_VERSION,
                    'hotel_id' => 81,
                    'target_date' => '2026-08-09',
                    'content_digest' => str_repeat('a', 64),
                ], JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertTrue($status['receipt_available']);
        self::assertFalse($status['receipt_readback_verified']);
        self::assertSame('blocked', $status['status']);
        self::assertSame(['daily_acceptance_receipt_scope_mismatch'], $status['reason_codes']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertSame(3, $status['stability']['required_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testChildHashMismatchIsBlockedEvenWhenBusinessFixturesAreReady(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '22222222-2222-4222-8222-222222222222',
            0,
            true,
            ['child_receipt_sha256' => str_repeat('f', 64)]
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('child_receipt_hash_mismatch', $result['reason_codes']);
        self::assertSame('blocked', $result['natural_dispatch']['status']);
        self::assertSame('blocked', $result['operations']['status']);
        self::assertSame(4, $result['operations']['trusted_analysis_check_count']);
        self::assertContains('daily_platform_owner_readback_invalid', $result['reason_codes']);
    }

    public function testManualOrUnprovenSourceTasksCannotBorrowCurrentNaturalRun(): void
    {
        $unproven = '33333333-3333-4333-8333-333333333333';
        $fixture = $this->writeRun(
            '2026-08-09',
            '44444444-4444-4444-8444-444444444444',
            0,
            true,
            [],
            $unproven
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_source_task_not_natural', $result['reason_codes']);
        self::assertContains('meituan_source_task_not_natural', $result['reason_codes']);
        self::assertSame('verified', $result['natural_dispatch']['status']);
    }

    public function testPartialNaturalRunKeepsVerifiedOperationsSeparateFromDailyStability(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '55555555-5555-4555-8555-555555555555',
            1,
            false
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['natural_dispatch']['status']);
        self::assertSame('verified', $result['collection']['status']);
        self::assertSame('verified', $result['operations']['status']);
        self::assertSame(4, $result['operations']['trusted_analysis_check_count']);
        self::assertContains('dispatcher_child_exit_nonzero', $result['reason_codes']);
        self::assertSame(0, $result['stability']['consecutive_verified_natural_days']);
    }

    public function testThreeDistinctConsecutiveBusinessDatesAreRequiredForStable(): void
    {
        $dates = ['2026-08-07', '2026-08-08', '2026-08-09'];
        $runIds = [
            '66666666-6666-4666-8666-666666666661',
            '66666666-6666-4666-8666-666666666662',
            '66666666-6666-4666-8666-666666666663',
        ];
        $service = $this->service();
        $results = [];
        foreach ($dates as $index => $date) {
            $fixture = $this->writeRun($date, $runIds[$index], 0, true);
            $results[] = $service->inspect(
                80,
                $date,
                [25, 68],
                ['ctrip', 'meituan'],
                $fixture['path'],
                $this->directory
            );
            file_put_contents(
                $fixture['path'],
                CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                    . json_encode(end($results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . PHP_EOL
                    . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                    . PHP_EOL,
                FILE_APPEND
            );
        }

        self::assertSame(1, $results[0]['stability']['consecutive_verified_natural_days']);
        self::assertSame(2, $results[1]['stability']['consecutive_verified_natural_days']);
        self::assertFalse($results[1]['stability']['stable']);
        self::assertSame(3, $results[2]['stability']['consecutive_verified_natural_days']);
        self::assertTrue($results[2]['stability']['stable']);
        self::assertSame('verified', $results[2]['stability']['status']);
        self::assertSame($dates, $results[2]['stability']['dates']);
    }

    public function testLatestStoredStatusRejectsOldStableReceiptWhenExpectedDailyLogIsAbsent(): void
    {
        $dates = ['2026-08-07', '2026-08-08', '2026-08-09'];
        $runIds = [
            '67676767-6767-4767-8767-676767676761',
            '67676767-6767-4767-8767-676767676762',
            '67676767-6767-4767-8767-676767676763',
        ];
        $service = $this->service();
        foreach ($dates as $index => $date) {
            $fixture = $this->writeRun($date, $runIds[$index], 0, true);
            $receipt = $service->inspect(
                80,
                $date,
                [25, 68],
                ['ctrip', 'meituan'],
                $fixture['path'],
                $this->directory
            );
            file_put_contents(
                $fixture['path'],
                CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                    . json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . PHP_EOL
                    . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                    . PHP_EOL,
                FILE_APPEND
            );
        }

        $status = $this->service(
            [],
            [],
            [],
            ['ctrip', 'meituan'],
            [],
            '2026-08-11 10:40:00'
        )->latestStoredStatus(80, $this->directory);

        self::assertSame('no_evidence', $status['status']);
        self::assertSame('freshness', $status['stage']);
        self::assertSame(['latest_natural_business_date_missing'], $status['reason_codes']);
        self::assertSame('2026-08-10', $status['target_date']);
        self::assertSame('2026-08-10', $status['expected_target_date']);
        self::assertSame('2026-08-09', $status['latest_observed_target_date']);
        self::assertSame('stale', $status['freshness_status']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testLatestStoredStatusUsesProvenanceTimeBeforeRandomFilenameSuffix(): void
    {
        $date = '2026-08-09';
        $olderRunId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $newerRunId = '11111111-1111-4111-8111-111111111111';
        $olderStartedAt = $date . 'T08:30:00.1000000+08:00';
        $newerStartedAt = $date . 'T08:30:00.9000000+08:00';
        $service = $this->service();

        $older = $this->writeRun(
            $date,
            $olderRunId,
            0,
            true,
            ['started_at' => $olderStartedAt],
            null,
            0,
            [],
            [],
            ['started_at' => $olderStartedAt]
        );
        $receipt = $service->inspect(
            80,
            $date,
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $this->writeRun(
            $date,
            $newerRunId,
            0,
            true,
            ['started_at' => $newerStartedAt],
            null,
            10,
            [],
            [],
            ['started_at' => $newerStartedAt]
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(
            ['daily_acceptance_receipt_missing_for_latest_attempt'],
            $status['reason_codes']
        );
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testLatestStoredStatusFailsClosedWhenStartTimesAreIndistinguishable(): void
    {
        $date = '2026-08-09';
        $startedAt = $date . 'T08:30:00.5000000+08:00';
        $service = $this->service();
        $older = $this->writeRun(
            $date,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            ['started_at' => $startedAt],
            null,
            0,
            [],
            [],
            ['started_at' => $startedAt]
        );
        $receipt = $service->inspect(
            80,
            $date,
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        $this->writeRun(
            $date,
            '11111111-1111-4111-8111-111111111111',
            0,
            true,
            ['started_at' => $startedAt],
            null,
            10,
            [],
            [],
            ['started_at' => $startedAt]
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(['dispatcher_latest_attempt_ambiguous'], $status['reason_codes']);
        self::assertSame('2026-08-09', $status['expected_target_date']);
        self::assertSame('current', $status['freshness_status']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testInvalidSameSecondStartTimeCannotFallBehindOlderStableReceipt(): void
    {
        $date = '2026-08-09';
        $olderStartedAt = $date . 'T08:30:00.1000000+08:00';
        $invalidStartedAt = '2026-02-30T08:30:00.9000000+08:00';
        $service = $this->service();
        $older = $this->writeRun(
            $date,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            ['started_at' => $olderStartedAt],
            null,
            0,
            [],
            [],
            ['started_at' => $olderStartedAt]
        );
        $olderReceipt = $service->inspect(
            80,
            $date,
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        $newer = $this->writeRun(
            $date,
            '11111111-1111-4111-8111-111111111111',
            0,
            true,
            ['started_at' => $invalidStartedAt],
            null,
            10,
            [],
            [],
            ['started_at' => $invalidStartedAt]
        );

        $invalidReceipt = $service->inspect(
            80,
            $date,
            [25, 68],
            ['ctrip', 'meituan'],
            $newer['path'],
            $this->directory
        );
        self::assertSame('blocked', $invalidReceipt['status']);
        self::assertContains(
            'dispatcher_provenance_time_invalid',
            $invalidReceipt['reason_codes']
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(['dispatcher_latest_attempt_ambiguous'], $status['reason_codes']);
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testSameSecondCandidateLimitCannotHideNewestHotelAttempt(): void
    {
        $date = '2026-08-09';
        $olderStartedAt = $date . 'T08:30:00.1000000+08:00';
        $newerStartedAt = $date . 'T08:30:00.9000000+08:00';
        $service = $this->service();
        $older = $this->writeRun(
            $date,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            0,
            true,
            ['started_at' => $olderStartedAt],
            null,
            0,
            [],
            [],
            ['started_at' => $olderStartedAt]
        );
        $olderReceipt = $service->inspect(
            80,
            $date,
            [25, 68],
            ['ctrip', 'meituan'],
            $older['path'],
            $this->directory
        );
        file_put_contents(
            $older['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($olderReceipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );
        for ($index = 1; $index <= 239; $index++) {
            $suffix = '8' . str_pad(dechex($index), 31, '0', STR_PAD_LEFT);
            file_put_contents(
                $this->directory . DIRECTORY_SEPARATOR
                    . 'ota_dispatcher_20260809_083000_' . $suffix . '.log',
                'dispatcher_scope=hotel:81;platforms:ctrip,meituan;source_count:2'
                    . PHP_EOL
            );
        }
        $this->writeRun(
            $date,
            '00000000-0000-4000-8000-000000000000',
            0,
            true,
            ['started_at' => $newerStartedAt],
            null,
            10,
            [],
            [],
            ['started_at' => $newerStartedAt]
        );

        $status = $service->latestStoredStatus(80, $this->directory);

        self::assertSame('blocked', $status['status']);
        self::assertSame(
            ['daily_acceptance_receipt_missing_for_latest_attempt'],
            $status['reason_codes']
        );
        self::assertSame(0, $status['stability']['consecutive_verified_natural_days']);
        self::assertFalse($status['stability']['stable']);
    }

    public function testPriorNaturalRetryMayContributeExactTaskToLaterSuccessfulAttempt(): void
    {
        $priorRunId = '77777777-7777-4777-8777-777777777777';
        $this->writeRun('2026-08-09', $priorRunId, 1, false);
        $current = $this->writeRun(
            '2026-08-09',
            '88888888-8888-4888-8888-888888888888',
            0,
            true,
            [],
            $priorRunId
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $current['path'],
            $this->directory
        );

        self::assertSame('verified', $result['status']);
        self::assertSame($priorRunId, $result['collection']['source_tasks']['ctrip']['dispatcher_run_id']);
        self::assertSame($priorRunId, $result['collection']['source_tasks']['meituan']['dispatcher_run_id']);
    }

    public function testHistoricalTaskReadbackDriftResetsTheNaturalStreak(): void
    {
        $prior = $this->writeRun(
            '2026-08-08',
            '99999999-1111-4111-8111-999999999999',
            0,
            true
        );
        $priorReceipt = $this->service()->inspect(
            80,
            '2026-08-08',
            [25, 68],
            ['ctrip', 'meituan'],
            $prior['path'],
            $this->directory
        );
        file_put_contents(
            $prior['path'],
            CanonicalOtaDailyNaturalAcceptanceService::LINE_PREFIX
                . json_encode($priorReceipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . PHP_EOL
                . 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
                . PHP_EOL,
            FILE_APPEND
        );

        $current = $this->writeRun(
            '2026-08-09',
            '99999999-2222-4222-8222-999999999999',
            0,
            true
        );
        $result = $this->service(['2026-08-08'])->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $current['path'],
            $this->directory
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(1, $result['stability']['consecutive_verified_natural_days']);
        self::assertSame(['2026-08-09'], $result['stability']['dates']);
        self::assertFalse($result['stability']['stable']);
    }

    public function testExtraOrDuplicateSourceTaskBlocksCollectionEvenWhenExpectedTasksAreValid(): void
    {
        $runId = '99999999-3333-4333-8333-999999999999';
        $extraTask = $this->sourceTask(25, 1999, 'ctrip', [99], $runId, '2026-08-09');
        $fixture = $this->writeRun(
            '2026-08-09',
            $runId,
            0,
            true,
            [],
            null,
            0,
            [$extraTask]
        );

        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('source_task_scope_ambiguous', $result['reason_codes']);
    }

    public function testCurrentFailedSourceTaskReasonSurvivesWithoutBecomingATrustedAnchor(): void
    {
        $runId = '99999999-3434-4343-8343-999999999999';
        $fixture = $this->writeRun(
            '2026-08-09',
            $runId,
            0,
            true,
            [],
            null,
            0,
            [],
            [
                'collection_complete' => false,
                'exportable_snapshot_complete' => false,
                'authority_scope_complete' => false,
                'source_tasks' => [[
                    'data_source_id' => 0,
                    'sync_task_id' => 0,
                ]],
                'failed_source_tasks' => [[
                    'data_source_id' => 25,
                    'sync_task_id' => 3271,
                    'platform' => 'ctrip',
                    'target_date' => '2026-08-09',
                    'status' => 'failed',
                    'failure_reason' => 'credential_execution_failed',
                    'readback_count' => 0,
                    'readback_verified' => false,
                    'historical_core_contract_status' => 'blocked',
                    'dispatcher_run_id' => $runId,
                ]],
            ]
        );

        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_credential_execution_failed', $result['reason_codes']);
        self::assertContains('ctrip_source_task_missing', $result['reason_codes']);
        self::assertSame(1, $result['collection']['failed_source_task_count']);
        self::assertSame(3271, $result['collection']['failed_source_tasks'][0]['sync_task_id']);
        self::assertSame(
            'credential_execution_failed',
            $result['collection']['failed_source_tasks'][0]['failure_reason']
        );
        self::assertFalse($result['collection']['failed_source_tasks'][0]['readback_verified']);
        self::assertFalse($result['collection']['failed_source_tasks'][0]['sensitive_values_exposed']);
    }

    public function testTaskStatsAndRunReadbackMustCarryTheSameDispatcherRunId(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '99999999-4444-4444-8444-999999999999',
            0,
            true
        );
        $result = $this->service([], ['2026-08-09'])->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_source_task_scope_or_readback_invalid', $result['reason_codes']);
        self::assertContains('meituan_source_task_scope_or_readback_invalid', $result['reason_codes']);
    }

    public function testAcceptanceRejectsUnanchoredPriorRevenueTask(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '99999999-4545-4545-8545-999999999999',
            0,
            true
        );
        $result = $this->service([], [], [], ['ctrip', 'meituan'], ['2026-08-09'])->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_source_task_core_contract_incomplete', $result['reason_codes']);
        self::assertContains('meituan_source_task_core_contract_incomplete', $result['reason_codes']);
    }

    public function testChildOperationTripletsMustMatchTheCurrentDatabaseOwner(): void
    {
        $finalization = $this->operationFinalization('2026-08-09');
        $finalization['records'][0]['task_id'] = 9999;
        $fixture = $this->writeRun(
            '2026-08-09',
            '99999999-5555-4555-8555-999999999999',
            0,
            true,
            [],
            null,
            0,
            [],
            ['canonical_operation_finalization' => $finalization]
        );

        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['operations']['status']);
        self::assertContains('daily_platform_owner_readback_invalid', $result['reason_codes']);
    }

    public function testAdditionalDatabaseRowOutsideChildMembershipBlocksExactReadback(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '99999999-6666-4666-8666-999999999999',
            0,
            true
        );
        $result = $this->service([], [], ['2026-08-09'])->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_source_task_scope_or_readback_invalid', $result['reason_codes']);
        self::assertContains('meituan_source_task_scope_or_readback_invalid', $result['reason_codes']);
    }

    public function testHistoricalReceiptCannotDisableTheExternalP0VerifierRequirement(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            '99999999-7777-4777-8777-999999999999',
            0,
            true,
            [],
            null,
            0,
            [],
            ['authority_verifier_required' => false]
        );
        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('daily_trust_receipt_not_ready', $result['reason_codes']);
    }

    public function testRetryTaskCannotBorrowAnOriginWhoseStartAndFinishRunIdsDiffer(): void
    {
        $priorRunId = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        $this->writeRun(
            '2026-08-09',
            $priorRunId,
            1,
            false,
            [],
            null,
            0,
            [],
            [],
            ['run_id' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb']
        );
        $current = $this->writeRun(
            '2026-08-09',
            'cccccccc-3333-4333-8333-cccccccccccc',
            0,
            true,
            [],
            $priorRunId
        );

        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $current['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('ctrip_source_task_not_natural', $result['reason_codes']);
        self::assertContains('meituan_source_task_not_natural', $result['reason_codes']);
    }

    public function testVerifierAndReceiptCannotShareAnAnchorThatDoesNotDescribeSourceTasks(): void
    {
        $foreignAnchor = str_repeat('f', 64);
        $fixture = $this->writeRun(
            '2026-08-09',
            'dddddddd-4444-4444-8444-dddddddddddd',
            0,
            true,
            [],
            null,
            0,
            [],
            [
                'collection_anchor_hash' => $foreignAnchor,
                'authority_verifier' => ['collection_anchor_hash' => $foreignAnchor],
            ]
        );

        $result = $this->service()->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['collection']['status']);
        self::assertContains('collection_anchor_mismatch', $result['reason_codes']);
    }

    public function testContinuousTrustMustCoverTheExactDualPlatformScope(): void
    {
        $fixture = $this->writeRun(
            '2026-08-09',
            'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
            0,
            true
        );
        $result = $this->service([], [], [], ['ctrip'])->inspect(
            80,
            '2026-08-09',
            [25, 68],
            ['ctrip', 'meituan'],
            $fixture['path'],
            $this->directory
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('blocked', $result['continuous_trust']['status']);
        self::assertContains('dual_ota_continuous_trust_not_ready', $result['reason_codes']);
    }

    /**
     * @param array<int,string> $staleTaskDates
     * @param array<int,string> $mismatchedStatsDates
     * @param array<int,string> $extraFactRowDates
     * @param array<int,string> $continuousPlatforms
     * @param array<int,string> $incompleteCoreTaskDates
     */
    private function service(
        array $staleTaskDates = [],
        array $mismatchedStatsDates = [],
        array $extraFactRowDates = [],
        array $continuousPlatforms = ['ctrip', 'meituan'],
        array $incompleteCoreTaskDates = [],
        string $now = '2026-08-10 10:40:00'
    ): CanonicalOtaDailyNaturalAcceptanceService
    {
        return new CanonicalOtaDailyNaturalAcceptanceService(
            static fn(int $hotelId): int => $hotelId === 80 ? 80 : 0,
            static fn(array $receipt, string $date, int $hotelId, array $sources, array $platforms): bool =>
                (int)($receipt['hotel_id'] ?? 0) === $hotelId
                && (string)($receipt['target_date'] ?? '') === $date
                && $sources === [25, 68]
                && $platforms === ['ctrip', 'meituan']
                && ($receipt['collection_complete'] ?? false) === true,
            static fn(int $hotelId, string $startDate, string $endDate): array => [
                'status' => 'verified',
                'acceptance_status' => 'verified',
                'verified_days' => 1,
                'accepted_days' => 1,
                'required_platforms' => $continuousPlatforms,
                'days' => [[
                    'date' => $startDate,
                    'status' => 'verified',
                    'acceptance_status' => 'verified',
                ]],
            ],
            static function (int $tenantId, int $hotelId, string $date, string $period): array {
                $scope = self::operationScope($date);
                $intentIds = [101, 102, 103, 104];
                $actionSetDigest = str_repeat('b', 64);
                $actionTypes = CanonicalOtaInvestigationActionService::actionTypesForPlatform('ctrip');
                $triplets = [];
                foreach ($actionTypes as $index => $actionType) {
                    $triplets[] = [
                        'intent_id' => 101 + $index,
                        'task_id' => 201 + $index,
                        'evidence_id' => 301 + $index,
                        'action_type' => $actionType,
                    ];
                }
                return [
                    'status' => 'selected',
                    'selected' => true,
                    'platform' => 'ctrip',
                    'scope' => $scope,
                    'selection_receipt' => [
                        'readback_verified' => true,
                        'intent_ids' => $intentIds,
                        'triplets' => $triplets,
                        'action_set_digest' => $actionSetDigest,
                        'owner_scope_digest' => str_repeat('c', 64),
                        'content_digest' => str_repeat('d', 64),
                    ],
                ];
            },
            static function (int $tenantId, int $hotelId, string $date, array $task) use (
                $staleTaskDates,
                $mismatchedStatsDates,
                $extraFactRowDates,
                $incompleteCoreTaskDates
            ): array {
                $rowIds = array_values(array_map('intval', $task['row_ids'] ?? []));
                $factRowIds = $rowIds;
                if (in_array($date, $extraFactRowDates, true)) {
                    $factRowIds[] = 9999;
                }
                $runId = (string)($task['dispatcher_run_id'] ?? '');
                $statsRunId = in_array($date, $mismatchedStatsDates, true)
                    ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
                    : $runId;
                $platform = (string)$task['platform'];
                $requiredCoreMetricKeys = \app\service\OtaOrderedCollectionPlanner::requiredFieldKeys($platform);
                $coreIncomplete = in_array($date, $incompleteCoreTaskDates, true);
                $completeCoreMetricKeys = $coreIncomplete
                    ? array_values(array_intersect(
                        $requiredCoreMetricKeys,
                        ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num']
                    ))
                    : $requiredCoreMetricKeys;
                $missingCoreMetricKeys = array_values(array_diff(
                    $requiredCoreMetricKeys,
                    $completeCoreMetricKeys
                ));
                return [
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'data_source_id' => (int)$task['data_source_id'],
                    'sync_task_id' => (int)$task['sync_task_id'],
                    'platform' => $platform,
                    'target_date' => $date,
                    'data_period' => 'historical_daily',
                    'task_status' => 'success',
                    'trigger_type' => 'daily_profile_reuse',
                    'dispatcher_run_id' => $runId,
                    'stats_dispatcher_run_id' => $statsRunId,
                    'run_readback_dispatcher_run_id' => $runId,
                    'task_started_at' => $date . ' 08:30:10',
                    'row_ids' => $factRowIds,
                    'saved_count' => count($factRowIds),
                    'readback_count' => count($factRowIds),
                    'readback_verified' => !in_array($date, $staleTaskDates, true),
                    'readback_digest' => hash('sha256', json_encode($rowIds)),
                    'historical_core_contract_status' => $coreIncomplete ? 'blocked' : 'ready',
                    'required_core_metric_keys' => $requiredCoreMetricKeys,
                    'complete_core_metric_keys' => $completeCoreMetricKeys,
                    'missing_core_metric_keys' => $missingCoreMetricKeys,
                ];
            },
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(
                $now,
                new \DateTimeZone('Asia/Shanghai')
            )
        );
    }

    /**
     * @param array<string,mixed> $finishOverrides
     * @return array{path:string,child:array<string,mixed>}
     */
    private function writeRun(
        string $date,
        string $runId,
        int $exitCode,
        bool $naturalReady,
        array $finishOverrides = [],
        ?string $sourceTaskRunId = null,
        int $idOffset = 0,
        array $additionalSourceTasks = [],
        array $childOverrides = [],
        array $startOverrides = []
    ): array {
        $sourceTaskRunId ??= $runId;
        $sourceTasks = [
            $this->sourceTask(25, 1001 + $idOffset, 'ctrip', [11 + $idOffset], $sourceTaskRunId, $date),
            $this->sourceTask(68, 1002 + $idOffset, 'meituan', [12 + $idOffset], $sourceTaskRunId, $date),
        ];
        $sourceTasks = array_merge($sourceTasks, $additionalSourceTasks);
        $anchor = OtaCollectionAnchorService::hash($sourceTasks);
        $child = [
            'schema_version' => 3,
            'dispatcher_run_id' => $runId,
            'hotel_id' => 80,
            'target_date' => $date,
            'data_period' => 'historical_daily',
            'source_ids' => [25, 68],
            'required_platforms' => ['ctrip', 'meituan'],
            'status' => 'success',
            'collection_complete' => true,
            'exportable_snapshot_complete' => true,
            'authority_verifier_required' => true,
            'authority_scope_complete' => true,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchor,
            'source_tasks' => $sourceTasks,
            'authority_verifier' => [
                'verification_source' => 'external_p0_verifier',
                'status' => 'passed',
                'exit_code' => 0,
                'authority_ready' => true,
                'target_date' => $date,
                'hotel_id' => 80,
                'verified_platforms' => ['ctrip', 'meituan'],
                'p0_platforms_ready' => 2,
                'traffic_gates_ready' => 2,
                'observed_traffic_metric_provenance_status' => 'ready',
                'synthetic_normalization_provenance_missing_rows' => 0,
                'continuous_trust_status' => 'verified',
                'continuous_trust_missing_steps' => [],
                'collection_anchor_hash' => $anchor,
            ],
            'canonical_operation_finalization' => $this->operationFinalization($date),
        ];
        $child = array_replace_recursive($child, $childOverrides);
        $childLine = 'SUXIOS_AUTO_FETCH_RECEIPT=' . json_encode(
            $child,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $childHash = hash('sha256', json_encode(
            [$childLine],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $startedAt = sprintf(
            '%sT08:30:00.%07d+08:00',
            $date,
            min(9999999, max(0, $idOffset))
        );
        $finishedAt = $date . 'T08:31:00+08:00';
        $scope = ['hotel_id' => 80, 'source_ids' => [25, 68], 'platforms' => ['ctrip', 'meituan']];
        $correlation = [
            'task_name' => 'SUXIOS OTA Dispatcher H80',
            'task_path' => '\\',
            'state' => 'running',
            'last_run_time' => $startedAt,
            'last_run_delta_seconds' => 0,
            'task_instance_id' => '99999999-9999-4999-8999-999999999999',
            'engine_process_id' => 1234,
            'event_ids' => [100, 107, 129, 200],
            'manual_run_event_absent' => true,
            'reason' => 'exact_task_instance_events',
            'status' => 'correlated',
        ];
        $base = [
            'schema_version' => 1,
            'receipt_type' => 'suxios_ota_dispatcher_provenance',
            'run_id' => $runId,
            'started_at' => $startedAt,
            'mode' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'target_date' => $date,
            'scope' => $scope,
            'scope_sha256' => str_repeat('1', 64),
            'runner_sha256' => str_repeat('2', 64),
            'code_manifest' => ['algorithm' => 'sha256', 'sha256' => str_repeat('3', 64), 'file_count' => 10],
            'effective_config_sha256' => str_repeat('4', 64),
            'task_contract_sha256' => str_repeat('5', 64),
            'scheduler_correlation' => $correlation,
            'sensitive_values_exposed' => false,
        ];
        $start = [
            ...$base,
            'phase' => 'start',
            'provenance_status' => 'started',
            ...$startOverrides,
        ];
        $finish = [
            ...$base,
            'phase' => 'finish',
            'provenance_status' => 'verified',
            'finished_at' => $finishedAt,
            'child_receipt_present' => true,
            'child_receipt_count' => 1,
            'child_receipt_sha256' => $childHash,
            'child_exit_code' => $exitCode,
            'code_stable_during_run' => true,
            'natural_run_ready' => $naturalReady,
            'natural_run_reason' => $naturalReady ? 'verified' : 'child_exit_nonzero',
            ...$finishOverrides,
        ];
        $path = $this->directory . DIRECTORY_SEPARATOR
            . 'ota_dispatcher_' . str_replace('-', '', $date) . '_083000_'
            . str_replace('-', '', $runId) . '.log';
        file_put_contents($path, implode(PHP_EOL, [
            'dispatcher_target_date=' . $date . ';timezone=Asia/Shanghai',
            'dispatcher_scope=hotel:80;platforms:ctrip,meituan;source_count:2',
            'SUXIOS_OTA_DISPATCHER_PROVENANCE=' . json_encode($start, JSON_UNESCAPED_SLASHES),
            $childLine,
            'SUXIOS_OTA_DISPATCHER_PROVENANCE=' . json_encode($finish, JSON_UNESCAPED_SLASHES),
            'dispatcher_terminal_status=finished;exit_code=' . $exitCode,
            '',
        ]));
        return ['path' => $path, 'child' => $child];
    }

    /** @return array<string,mixed> */
    private function sourceTask(
        int $sourceId,
        int $taskId,
        string $platform,
        array $rowIds,
        string $runId,
        string $date
    ): array {
        return [
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'platform' => $platform,
            'collection_status' => 'success',
            'p0_status' => 'ready',
            'historical_core_contract_status' => 'ready',
            'dispatcher_run_id' => $runId,
            'trigger_type' => 'daily_profile_reuse',
            'started_at' => $date . ' 08:30:10',
            'row_ids' => $rowIds,
        ];
    }

    /** @return array<string,mixed> */
    private function operationFinalization(string $date): array
    {
        $scope = self::operationScope($date);
        $actionTypes = CanonicalOtaInvestigationActionService::actionTypesForPlatform('ctrip');
        $records = [];
        foreach ($actionTypes as $index => $actionType) {
            $records[] = [
                'intent_id' => 101 + $index,
                'task_id' => 201 + $index,
                'evidence_id' => 301 + $index,
                'action_type' => $actionType,
            ];
        }
        return [
            'schema_version' => 'canonical_ota_daily_operation_finalization.v2',
            'status' => 'verified',
            'analysis_status' => 'verified',
            'scope' => $scope,
            'selected_platform' => 'ctrip',
            'analysis_only' => true,
            'draft_count' => 4,
            'trusted_operational_check_count' => 4,
            'trusted_external_operation_count' => 0,
            'draft_readback_verified' => true,
            'db_readback_verified' => true,
            'operation_flow_readback_verified' => true,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'sensitive_values_exposed' => false,
            'action_set_digest' => str_repeat('b', 64),
            'records' => $records,
        ];
    }

    /** @return array<string,mixed> */
    private static function operationScope(string $date): array
    {
        return [
            'tenant_id' => 80,
            'hotel_id' => 80,
            'data_source_id' => 25,
            'task_id' => 1001,
            'row_id' => 11,
            'platform' => 'ctrip',
            'target_date' => $date,
            'data_period' => 'historical_daily',
        ];
    }
}
