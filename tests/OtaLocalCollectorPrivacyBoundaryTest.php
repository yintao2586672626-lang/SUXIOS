<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaLocalCollectorService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OtaLocalCollectorPrivacyBoundaryTest extends TestCase
{
    use ReflectionHelper;

    public function testLeasedDeviceTaskContainsOnlyCurrentSections(): void
    {
        $service = new OtaLocalCollectorService();
        $request = [
            'reason' => 'targeted_gap_recovery',
            'sections' => ['competitor_overview', 'competitor_rank'],
            'ordered_collection' => [
                'interface_ids' => ['private_overview', 'private_rank'],
                'required_field_keys' => ['competitor_rank'],
                'missing_field_keys' => ['competitor_rank'],
            ],
            'resume_collections' => [['private' => true]],
        ];

        $leased = $this->invokeNonPublic($service, 'leasedTaskRequest', [$request]);

        self::assertSame([
            'sections' => ['competitor_overview', 'competitor_rank'],
        ], $leased);
    }

    public function testOrdinaryTaskSummaryDoesNotExposeInterfaceOrFieldPlan(): void
    {
        $service = new OtaLocalCollectorService();
        $summary = $this->invokeNonPublic($service, 'publicTaskRequest', [[
            'reason' => 'targeted_gap_recovery',
            'sections' => ['competitor_overview'],
            'ordered_collection' => [
                'contract_version' => 'v1',
                'scope' => 'ota_yesterday_core',
                'stage' => 'targeted_gap',
                'target_date' => '2026-07-26',
                'sections' => ['competitor_overview'],
                'interface_ids' => ['private_overview'],
                'required_field_keys' => ['competitor_rank'],
                'missing_field_keys' => ['competitor_rank'],
            ],
        ]]);

        self::assertSame(['competitor_overview'], $summary['sections']);
        self::assertArrayNotHasKey('interface_ids', $summary['ordered_collection']);
        self::assertArrayNotHasKey('required_field_keys', $summary['ordered_collection']);
        self::assertArrayNotHasKey('missing_field_keys', $summary['ordered_collection']);
    }

    public function testOrdinaryResultSummaryHidesImplementationEvidence(): void
    {
        $service = new OtaLocalCollectorService();
        $summary = $this->invokeNonPublic($service, 'publicTaskResultSummary', [[
            'saved_count' => 6,
            'capture_summary' => [
                'requested_sections' => ['competitor_overview'],
                'expected_interface_ids' => ['private_overview'],
                'captured_field_keys' => ['competitor_rank'],
            ],
            'ordered_collection' => [
                'missing_field_keys' => ['competitor_rank'],
            ],
        ], false]);

        self::assertSame(6, $summary['saved_count']);
        self::assertSame('redacted', $summary['implementation_visibility']);
        self::assertArrayNotHasKey('expected_interface_ids', $summary['capture_summary']);
        self::assertArrayNotHasKey('captured_field_keys', $summary['capture_summary']);
        self::assertArrayNotHasKey('missing_field_keys', $summary['ordered_collection']);
    }
}
