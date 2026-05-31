<?php
declare(strict_types=1);

namespace Tests;

use app\command\MigrateOnlineData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class MigrateOnlineDataTest extends TestCase
{
    use ReflectionHelper;

    public function testOnlineDailyDataMigrationIncludesCaptureProvenanceColumns(): void
    {
        $reflection = new ReflectionClass(MigrateOnlineData::class);
        self::assertTrue($reflection->hasMethod('onlineDailyDataFieldsToAdd'));

        $fields = $this->invokeNonPublic(new MigrateOnlineData(), 'onlineDailyDataFieldsToAdd');

        self::assertSame("VARCHAR(30) NOT NULL DEFAULT 'legacy'", $fields['ingestion_method']);
        self::assertSame('VARCHAR(80) DEFAULT NULL', $fields['source_trace_id']);
        self::assertSame('INTEGER', $fields['data_source_id']);
        self::assertSame('BIGINT', $fields['sync_task_id']);
    }
}
