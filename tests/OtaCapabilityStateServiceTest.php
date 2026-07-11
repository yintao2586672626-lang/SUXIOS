<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OtaCapabilityStateServiceTest extends TestCase
{
    public function testCapabilityStateServiceExists(): void
    {
        self::assertTrue(class_exists(\app\service\OtaCapabilityStateService::class));
    }

    public function testCapabilityStateServiceExposesEvaluator(): void
    {
        self::assertTrue(method_exists(\app\service\OtaCapabilityStateService::class, 'evaluate'));
    }

    public function testResourceStatesProduceIndependentCapabilityResults(): void
    {
        $report = (new \app\service\OtaCapabilityStateService())->evaluate('logged_in', [
            [
                'resource' => 'businessData',
                'dataType' => 'business',
                'collectionStatus' => 'ready',
                'etlStatus' => 'stored_displayable',
                'storedRowCount' => 2,
            ],
            [
                'resource' => 'orderData',
                'dataType' => 'order',
                'collectionStatus' => 'permission_denied',
                'etlStatus' => 'not_started',
                'storedRowCount' => 0,
            ],
            [
                'resource' => 'reviewData',
                'dataType' => 'review',
                'collectionStatus' => 'unbound',
                'etlStatus' => 'not_started',
                'storedRowCount' => 0,
            ],
        ]);

        self::assertSame([
            'business' => 'verified',
            'orders' => 'permission_denied',
            'reviews' => 'unverified',
        ], $report);
    }

    public function testGlobalProfilePermissionDenialBlocksEveryCapability(): void
    {
        $report = (new \app\service\OtaCapabilityStateService())->evaluate('permission_denied', [
            [
                'resource' => 'businessData',
                'dataType' => 'business',
                'collectionStatus' => 'ready',
                'etlStatus' => 'stored_displayable',
                'storedRowCount' => 2,
            ],
        ]);

        self::assertSame([
            'business' => 'permission_denied',
            'orders' => 'permission_denied',
            'reviews' => 'permission_denied',
        ], $report);
    }

    public function testUnverifiedProfileCannotReuseHistoricalResourceStateAsCapabilityProof(): void
    {
        $report = (new \app\service\OtaCapabilityStateService())->evaluate('login_expired', [
            [
                'resource' => 'businessData',
                'dataType' => 'business',
                'collectionStatus' => 'ready',
                'etlStatus' => 'stored_displayable',
                'storedRowCount' => 2,
            ],
        ]);

        self::assertSame([
            'business' => 'unverified',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ], $report);
    }

    public function testResourceCapabilityUnavailabilityRemainsDistinctFromUnverified(): void
    {
        $report = (new \app\service\OtaCapabilityStateService())->evaluate('logged_in', [
            [
                'resource' => 'businessData',
                'dataType' => 'business',
                'collectionStatus' => 'ready',
                'etlStatus' => 'stored_displayable',
                'storedRowCount' => 2,
            ],
            [
                'resource' => 'orderData',
                'dataType' => 'order',
                'collectionStatus' => 'capability_unavailable',
                'etlStatus' => 'not_started',
                'storedRowCount' => 0,
            ],
        ]);

        self::assertSame([
            'business' => 'verified',
            'orders' => 'capability_unavailable',
            'reviews' => 'unverified',
        ], $report);
    }

    public function testCapabilityCollectionFailureRemainsVisible(): void
    {
        $report = (new \app\service\OtaCapabilityStateService())->evaluate('logged_in', [
            [
                'resource' => 'orderData',
                'dataType' => 'order',
                'collectionStatus' => 'failed',
                'etlStatus' => 'capture_failed',
                'storedRowCount' => 0,
            ],
        ]);

        self::assertSame([
            'business' => 'unverified',
            'orders' => 'collection_failed',
            'reviews' => 'unverified',
        ], $report);
    }
}
