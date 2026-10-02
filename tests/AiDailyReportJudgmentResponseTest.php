<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiDailyReport;
use app\service\AiDailyReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiDailyReportJudgmentResponseTest extends TestCase
{
    public static function failedReadbackStates(): array
    {
        return [['read_failed'], ['missing_table']];
    }

    #[DataProvider('failedReadbackStates')]
    public function testCompletedWriteWithFailedReadbackDoesNotClaimSaveSuccess(string $dataStatus): void
    {
        $readback = [
            'status' => 'blocked',
            'data_status' => $dataStatus,
            'reason_code' => 'ai_daily_reports_read_failed',
            'stage' => 'read',
            'report_id' => 301,
        ];
        [$response, $savedCalls] = $this->saveWithReadback($readback);

        self::assertCount(1, $savedCalls, 'The synthetic write occurred before readback failed; the controller must not retry it.');
        self::assertSame(503, $response->getCode());
        $body = $response->getData();
        self::assertSame(503, $body['code']);
        self::assertSame($readback, $body['data']);
        self::assertStringContainsString('可能已保存，先刷新核对再决定是否重试', $body['message']);
    }

    public static function successfulStorageModes(): array
    {
        return [
            'append only' => ['append_only_persisted', 'review-41', 41, 'exact_readback_verified'],
            'legacy snapshot' => ['snapshot_compatibility_migration_required', '0123456789abcdef', null, 'legacy_unverified'],
        ];
    }

    #[DataProvider('successfulStorageModes')]
    public function testReadableHistoricalJudgmentKeepsSuccessAndStorageIdentity(
        string $storageStatus,
        string $judgmentId,
        ?int $reviewRecordId,
        string $evidenceStatus
    ): void {
        $report = [
            'id' => 301,
            'hotel_id' => 7,
            'report_date' => '2026-09-14',
            'evidence_readback_status' => $evidenceStatus,
            'human_judgments' => [[
                'id' => $judgmentId,
                'review_record_id' => $reviewRecordId,
                'storage_status' => $storageStatus,
                'target_type' => 'overall',
                'decision' => 'accepted',
                'comment' => 'SYNTHETIC judgment; no operating evidence',
            ]],
        ];
        [$response, $savedCalls] = $this->saveWithReadback($report);

        self::assertCount(1, $savedCalls);
        self::assertSame(200, $response->getCode());
        self::assertSame(200, $response->getData()['code']);
        self::assertSame($report, $response->getData()['data']);
    }

    private function saveWithReadback(array $readback): array
    {
        $input = ['target_type' => 'overall', 'decision' => 'accepted', 'comment' => 'SYNTHETIC judgment; no operating evidence'];
        $savedCalls = [];
        $service = $this->createMock(AiDailyReportService::class);
        $service->expects($this->once())
            ->method('recordHumanJudgment')
            ->with(301, [7], 99, $input, 'SYNTHETIC reviewer')
            ->willReturnCallback(static function (...$arguments) use (&$savedCalls, $readback): array {
                // Test-only write receipt: no database, network, or real account is involved.
                $savedCalls[] = $arguments;
                return $readback;
            });

        $reflection = new ReflectionClass(AiDailyReport::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('service')->setValue($controller, $service);
        $reflection->getProperty('currentUser')->setValue($controller, new class {
            public int $id = 99;
            public string $username = 'SYNTHETIC reviewer';

            public function getPermittedHotelIds(): array
            {
                return [7];
            }
        });
        $reflection->getProperty('request')->setValue($controller, new class($input) {
            public function __construct(private array $input) {}

            public function param(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function post(): array
            {
                return $this->input;
            }
        });

        $response = $controller->recordHumanJudgment(301);
        return [$response, $savedCalls];
    }
}
