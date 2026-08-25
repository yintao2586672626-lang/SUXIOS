<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingOpportunityLabService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperatingOpportunityLabServiceTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../database/migrations/20260822_zzz_create_operating_opportunity_runs.sql';

    public function testCatalogExposesFiveUserVisibleFeaturesWithoutExternalWriteAuthority(): void
    {
        $catalog = (new OperatingOpportunityLabService())->catalog();

        self::assertCount(5, $catalog);
        self::assertSame([
            'daily_one_thing',
            'service_promise_risk',
            'promotion_incrementality',
            'bookability_gap',
            'ai_guest_acquisition',
        ], array_column($catalog, 'key'));
        foreach ($catalog as $item) {
            self::assertFalse($item['external_write_allowed']);
            self::assertNotSame('', $item['question']);
            self::assertSame(OperatingOpportunityLabService::CONTRACT_VERSION, $item['contract_version']);
        }
    }

    public function testMigrationIsScopedAppendOnlyAndSupportsExactReadback(): void
    {
        $sql = (string)file_get_contents(self::MIGRATION);
        foreach ([
            'operating_opportunity_runs',
            '`tenant_id`',
            '`system_hotel_id`',
            '`feature_key`',
            '`business_date`',
            '`source_quality_status`',
            '`input_digest`',
            '`result_digest`',
            '`idempotency_key`',
            'uniq_operating_opportunity_idempotency',
        ] as $marker) self::assertStringContainsString($marker, $sql);

        self::assertStringNotContainsString('UPDATE ', strtoupper($sql));
        self::assertStringNotContainsString('DELETE ', strtoupper($sql));
        self::assertStringNotContainsString('operation_execution_intents', $sql);
    }

    public function testUnknownTopLevelSourceQualityFailsClosed(): void
    {
        $method = new \ReflectionMethod(OperatingOpportunityLabService::class, 'sourceQuality');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('数据状态不在允许范围内');
        $method->invoke(new OperatingOpportunityLabService(), 'totally_made_up_quality');
    }

    public function testNestedObservationQualityMustMatchTheSavedTopLevelQuality(): void
    {
        $method = new \ReflectionMethod(
            OperatingOpportunityLabService::class,
            'assertObservationSourceQualityMatches'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('观察证据的数据状态必须与本次数据状态一致');
        $method->invoke(
            new OperatingOpportunityLabService(),
            'ai_guest_acquisition',
            'verified',
            ['observations' => [['source_quality' => 'manual_unverified']]]
        );
    }

    public function testWriteConflictClassifierCoversDuplicateDeadlockAndLockTimeout(): void
    {
        $service = new OperatingOpportunityLabService();
        $duplicate = new \ReflectionMethod(OperatingOpportunityLabService::class, 'isDuplicateKeyConflict');
        $retryable = new \ReflectionMethod(OperatingOpportunityLabService::class, 'isRetryableWriteConflict');

        self::assertTrue($duplicate->invoke($service, new \RuntimeException('Duplicate entry', 23000)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Deadlock found', 1213)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Lock wait timeout exceeded', 1205)));
        self::assertTrue($retryable->invoke($service, new \RuntimeException('Serialization failure', 40001)));
        self::assertFalse($retryable->invoke($service, new \RuntimeException('ordinary failure', 500)));
    }
}
