<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataHistoryConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OnlineDataHistoryServerPaginationTest extends TestCase
{
    public function testLightweightPaginationKeepsMergedGroupsIntactAndSummaryUsesGroupScope(): void
    {
        $rows = [
            $this->row(10, '2026-07-16 10:00:00', 'comment', '', 'success'),
            $this->row(9, '2026-07-16 09:59:00', 'comments', '', 'partial'),
            $this->row(8, '2026-07-16 09:00:00', 'traffic', 'traffic', 'failed'),
            $this->row(7, '2026-07-15 08:00:00', 'competitor', 'competition_circle_hotel', 'success'),
            $this->row(6, '2026-07-15 07:00:00', 'competitor', 'competition_circle_hotel', 'success'),
        ];

        $firstPage = $this->paginate($rows, 1, 2);
        $secondPage = $this->paginate($rows, 2, 2);

        self::assertSame(4, $firstPage['total']);
        self::assertSame([10, 9, 8], $firstPage['record_ids']);
        self::assertSame([7, 6], $secondPage['record_ids']);
        self::assertSame([], array_values(array_intersect($firstPage['record_ids'], $secondPage['record_ids'])));
        self::assertSame(4, $firstPage['summary']['total_records']);
        self::assertSame('2026-07-16 10:00:00', $firstPage['summary']['latest_fetch_time']);
        self::assertSame(1, $firstPage['summary']['failed_records']);
    }

    public function testPlatformAndDataTypeAliasesUseTheSameMergeIdentityAsHistoryRows(): void
    {
        $rows = [
            $this->row(3, '2026-07-14 10:00:00', 'ad', 'campaign', 'success', 'Ctrip'),
            $this->row(2, '2026-07-14 09:00:00', 'ads', 'campaign', 'success', 'ctrip'),
            $this->row(1, '2026-07-14 08:00:00', '', 'business', 'empty', '携程'),
        ];

        $result = $this->paginate($rows, 1, 20);

        self::assertSame(2, $result['total']);
        self::assertSame([3, 2, 1], $result['record_ids']);
    }

    public function testPlatformNormalizationMatchesTrimmedDatabaseProjection(): void
    {
        $rows = [
            $this->row(2, '2026-07-14 10:00:00', 'traffic', 'traffic', 'success', ' Ctrip '),
            $this->row(1, '2026-07-14 09:00:00', 'traffic', 'traffic', 'success', 'ctrip'),
        ];

        $result = $this->paginate($rows, 1, 20);

        self::assertSame(1, $result['total']);
        self::assertSame([2, 1], $result['record_ids']);
    }

    public function testUnorderedLightweightScanStillUsesLegacyFetchTupleForPageOrder(): void
    {
        $rows = [
            $this->row(1, '2026-07-14 08:00:00', 'business', 'oldest', 'success'),
            $this->row(3, '2026-07-16 10:00:00', 'business', 'newest', 'success'),
            $this->row(2, '2026-07-15 09:00:00', 'business', 'middle', 'success'),
        ];

        self::assertSame([3], $this->paginate($rows, 1, 1)['record_ids']);
        self::assertSame([2], $this->paginate($rows, 2, 1)['record_ids']);
        self::assertSame([1], $this->paginate($rows, 3, 1)['record_ids']);
    }

    public function testCurrentPageReadbackReappliesTheScopedHistoryQuery(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../app/controller/concern/OnlineDataHistoryConcern.php');
        self::assertStringContainsString(
            'applyOnlineHistoryGroupKeyScope(',
            $source
        );
        self::assertStringContainsString("clone \$query,", $source);
        self::assertStringContainsString("\$paginationPlan['group_keys']", $source);
    }

    public function testGeneratedHistoryProjectionColumnsRemainIndexable(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../app/controller/concern/OnlineDataHistoryConcern.php');

        self::assertStringContainsString("return '`history_group_key`';", $source);
        self::assertStringContainsString("return '`history_fetch_time`';", $source);
        self::assertStringNotContainsString(
            "return \$this->onlineHistorySqlColumnText(\$columns, 'history_group_key');",
            $source
        );
    }

    public function testDateIndexMigrationIsReferencedByTheFullInitializer(): void
    {
        $migrationName = '20260716_add_online_data_history_pagination_index.sql';
        $migration = (string)file_get_contents(__DIR__ . '/../database/migrations/' . $migrationName);
        $initializer = (string)file_get_contents(__DIR__ . '/../database/init_full.sql');

        self::assertStringContainsString('idx_online_daily_history_date_id', $migration);
        self::assertStringContainsString('(`data_date`, `id`)', $migration);
        self::assertStringNotContainsString('idx_online_daily_history_scope_date_fetch', $migration);
        self::assertStringContainsString('SOURCE ./database/migrations/' . $migrationName . ';', $initializer);
    }

    public function testHistoryProjectionMigrationIsReferencedByTheFullInitializer(): void
    {
        $migrationName = '20260717_add_online_data_history_projection.sql';
        $migration = (string)file_get_contents(__DIR__ . '/../database/migrations/' . $migrationName);
        $initializer = (string)file_get_contents(__DIR__ . '/../database/init_full.sql');

        foreach (['history_fetch_time', 'history_status', 'history_group_key'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $migration);
        }
        foreach ([
            'idx_online_daily_history_group_fetch',
            'idx_online_daily_history_fetch',
            'idx_online_daily_history_status_group',
            'idx_online_daily_history_scope_fetch',
            'idx_online_daily_history_date_group',
        ] as $index) {
            self::assertStringContainsString('`' . $index . '`', $migration);
        }
        self::assertStringContainsString('SOURCE ./database/migrations/' . $migrationName . ';', $initializer);
        self::assertGreaterThan(
            strpos($initializer, '20260716_create_ai_report_generation_tasks.sql'),
            strpos($initializer, $migrationName),
            'The projection depends on readback_verified from the AI task migration.'
        );
    }

    private function paginate(array $rows, int $page, int $pageSize): array
    {
        $subject = new class {
            use OnlineDataHistoryConcern;
        };
        $method = new ReflectionMethod($subject, 'buildOnlineHistoryLightweightPagination');
        return $method->invoke($subject, $rows, $page, $pageSize);
    }

    private function row(
        int $id,
        string $fetchTime,
        string $dataType,
        string $dimension,
        string $status,
        string $platform = 'Ctrip'
    ): array {
        return [
            'id' => $id,
            'data_date' => substr($fetchTime, 0, 10),
            'source' => 'ctrip',
            'platform' => $platform,
            'data_type' => $dataType,
            'system_hotel_id' => 7,
            'dimension' => $dimension,
            'compare_type' => '',
            'create_time' => $fetchTime,
            'update_time' => $fetchTime,
            'history_row_status' => $status,
        ];
    }
}
