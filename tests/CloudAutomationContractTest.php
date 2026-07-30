<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CloudAutomationContractTest extends TestCase
{
    public function testRetryImplementationCannotCallPatrolOrReportGeneration(): void
    {
        $method = new ReflectionMethod(\app\service\CloudAutomationService::class, 'runRetry');
        $source = (string)file_get_contents($method->getFileName());
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice(
            $lines,
            max(0, $method->getStartLine() - 1),
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('dueDeliveries', $body);
        self::assertStringContainsString('deliverToHotel', $body);
        self::assertStringNotContainsString('triggerPatrol', $body);
        self::assertStringNotContainsString('reportService->generate', $body);
        self::assertStringNotContainsString('inspectHotel', $body);
    }

    public function testSystemdUnitsAreSerialAndResourceBounded(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-automation@.service');
        $retryTimer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-retry.timer');

        self::assertStringContainsString('MemoryMax=512M', $service);
        self::assertStringContainsString('CPUQuota=80%', $service);
        self::assertStringContainsString('cloud-automation:run --mode=%i', $service);
        self::assertStringContainsString('OnUnitActiveSec=15min', $retryTimer);
    }

    public function testRecordedDataGapDoesNotTriggerRepeatedSystemdRestart(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/RunCloudAutomation.php');
        $hotelDailyService = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-cloud-hotel-daily@.service'
        );
        $automation = (string)file_get_contents($root . '/app/service/CloudAutomationService.php');

        self::assertStringContainsString(
            "['succeeded', 'partial', 'blocked']",
            $command,
            'A recorded missing-data outcome must exit successfully instead of entering Restart=on-failure.'
        );
        self::assertStringContainsString("'status' => 'blocked_by_data_health'", $automation);
        self::assertStringContainsString("'issue_codes'", $automation);
        self::assertStringContainsString('$this->stateStore->finishRun(', $automation);
        self::assertStringContainsString('Restart=on-failure', $hotelDailyService);
    }

    public function testCanaryHotelScopeReachesCommandServiceAndPatrolEndpoint(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/RunCloudAutomation.php');
        $service = (string)file_get_contents($root . '/app/service/CloudAutomationService.php');
        $health = (string)file_get_contents($root . '/app/service/CloudDataHealthService.php');
        $patrol = (string)file_get_contents($root . '/app/controller/concern/OperationWorkbenchConcern.php');

        self::assertStringContainsString("addOption('hotel-id'", $command);
        self::assertStringContainsString("addOption('lock-wait-seconds'", $command);
        self::assertStringContainsString("'hotel_id' => \$hotelId", $command);
        self::assertStringContainsString('return 75;', $command);
        self::assertStringContainsString('triggerPatrol($targetDate, $limit, $requestedHotelId)', $service);
        self::assertStringContainsString('enabledHotels($limit, $requestedHotelId)', $service);
        self::assertStringContainsString('public function enabledHotels(int $limit = 30, ?int $hotelId = null)', $health);
        self::assertStringContainsString("\$hotelQuery->where('id', \$hotelId)", $patrol);
    }

    public function testFormalDailyReportRequiresExactExternalP0Receipt(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/CloudAutomationService.php'
        );

        self::assertStringContainsString('P0OtaDownstreamGateService $p0GateService', $source);
        self::assertStringContainsString('$this->p0GateService->resolveRuntime(', $source);
        self::assertStringContainsString("'external_p0_verifier'", $source);
        self::assertStringContainsString("'dual_ota_p0_authority_not_ready'", $source);
        self::assertStringContainsString('$health[\'can_generate_report\'] = false;', $source);
        self::assertStringContainsString("'p0_downstream_gate'", $source);

        $method = new ReflectionMethod(
            \app\service\CloudAutomationService::class,
            'deliverSavedDailyReport'
        );
        $lines = explode("\n", $source);
        $body = implode("\n", array_slice(
            $lines,
            max(0, $method->getStartLine() - 1),
            $method->getEndLine() - $method->getStartLine() + 1
        ));
        self::assertStringContainsString('$this->p0GateService->resolveRuntime(', $body);
        self::assertStringContainsString("'formal_report_requires_exact_external_p0_receipt'", $body);
        self::assertStringContainsString("'blocked_by_p0_ota_gate'", $body);
        self::assertStringContainsString('!$statusOnly', $body);
        self::assertStringContainsString("'daily_report_status'", $body);
        self::assertStringContainsString("'report_edition' => \$reportEdition", $body);
        self::assertStringContainsString("'delivery_mode' => \$deliveryMode", $body);
        self::assertStringContainsString("'artifact_kind' => \$artifactKind", $body);
    }

    public function testHotelScopedSystemdTemplatesCannotFanOutToOtherHotels(): void
    {
        $root = dirname(__DIR__);
        foreach (['daily', 'health'] as $mode) {
            $service = (string)file_get_contents(
                $root . '/deploy/systemd/suxios-cloud-hotel-' . $mode . '@.service'
            );
            $timer = (string)file_get_contents(
                $root . '/deploy/systemd/suxios-cloud-hotel-' . $mode . '@.timer'
            );

            self::assertStringContainsString('--mode=' . $mode, $service);
            self::assertStringContainsString('--hotel-id=%i', $service);
            self::assertStringContainsString('--limit=1', $service);
            self::assertStringContainsString('--lock-wait-seconds=1500', $service);
            self::assertStringContainsString('Restart=on-failure', $service);
            self::assertStringContainsString('RestartSec=2min', $service);
            self::assertStringNotContainsString('Persistent=true', $timer);
            self::assertStringContainsString(
                'Conflicts=suxios-cloud-' . $mode . '.timer',
                $timer
            );
        }
    }
}
