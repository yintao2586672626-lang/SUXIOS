<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataHistoryConcern;
use app\controller\concern\OnlineDataQualityConcern;
use PHPUnit\Framework\TestCase;
use think\Response;

final class OtaHistoryDateInputTest extends TestCase
{
    private function controller(array $params): object
    {
        return new class($params) {
            use OnlineDataHistoryConcern;
            use OnlineDataQualityConcern;
            public object $request;
            public object $currentUser;
            public function __construct(array $params)
            {
                $this->currentUser = new class {
                    public function isSuperAdmin(): bool { return true; }
                };
                $this->request = new class($params, $this->currentUser) {
                    public function __construct(private array $params, public object $user) {}
                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->params[$key] ?? $default;
                    }
                };
            }
            private function error(string $message, int $code = 500): Response
            {
                return Response::create(['code' => $code, 'message' => $message], 'json', $code);
            }
        };
    }

    public function testInvalidCtripDateIsRejectedBeforeAnyDatabaseRead(): void
    {
        foreach (['2026-02-30', '2026-13-01', '2026-7-1', 'last_900_days', '2026-07-01/2026-07-03'] as $range) {
            $response = $this->controller(['range' => $range])->ctripLatest();
            self::assertSame(422, $response->getData()['code'], $range);
        }
    }

    public function testHistoryEndpointsRejectInvalidOrReversedDateRanges(): void
    {
        foreach (['history', 'ctripHistory', 'dailyDataList'] as $endpoint) {
            foreach ([
                ['start_date' => '2026-02-30'],
                ['end_date' => 'not-a-date'],
                ['start_date' => '2026-08-02', 'end_date' => '2026-08-01'],
            ] as $params) {
                self::assertSame(422, $this->controller($params)->$endpoint()->getData()['code'], $endpoint);
            }
        }
    }

    public function testUnknownPlatformCannotBroadenHistoryToAllPlatforms(): void
    {
        self::assertSame(422, $this->controller(['platform' => 'meituann'])->history()->getData()['code']);
    }

    public function testCaptureDatesAreValidatedSeparatelyAndOpenEndedBusinessBoundsRemainOpen(): void
    {
        self::assertSame(422, $this->controller(['create_start' => '2026-02-30'])->dailyDataList()->getData()['code']);
        self::assertSame(['2026-08-01', ''], \app\service\OtaReadDateRangeService::normalize('2026-08-01', ''));
        self::assertSame(['', '2026-08-02'], \app\service\OtaReadDateRangeService::normalize('', '2026-08-02'));
    }
}
