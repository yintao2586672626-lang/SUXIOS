<?php
declare(strict_types=1);
namespace Tests;
use PHPUnit\Framework\TestCase;
use think\Response;

final class MissingPatrolResponseTest extends TestCase
{
    public static function setUpBeforeClass(): void { (new \think\App())->initialize(); }
    private function controller(int $hotelId): object
    {
        return new class($hotelId) {
            use \app\controller\concern\OperationWorkbenchConcern;
            public object $request;
            public function __construct(private int $hotelId) { $this->request = new class { public function get($key, $default = '') { return $default; } }; }
            private function checkPermission(): void {}
            private function resolveDashboardHotelId(...$args): int { return $this->hotelId; }
            private function requireOperationHotelCapability($hotelId, $permission): void { if ($hotelId !== 987654321) throw new \think\exception\HttpException(403, 'Hotel scope denied', null, [], 403); }
            private function error($message, $status = 400, $data = null): Response { return json(['code'=>$status,'message'=>$message,'data'=>$data],$status); }
            private function success($data): Response { return json(['code'=>200,'data'=>$data]); }
            private function safeHttpCode($code): int { return $code >= 400 && $code <= 599 ? $code : 500; }
            private function operationWorkbenchInternalError($error, ...$args): Response { throw $error; }
        };
    }
    public function testMissingSnapshotIsAnActionableConflictAndNotAnEmptySuccess(): void
    {
        $response = $this->controller(987654321)->phase3OperationEffectLoop();
        self::assertSame(409, $response->getCode());
        $data = $response->getData()['data'];
        self::assertSame('missing_patrol_snapshot', $data['reason']);
        self::assertSame('blocked', $data['status']);
        self::assertSame(987654321, $data['hotel_id']);
        self::assertSame('generate_hotel_patrol', $data['next_action']);
        self::assertArrayNotHasKey('rows', $data);
    }
    public function testUnauthorizedHotelIsRejectedBeforeSnapshotLookup(): void
    {
        self::assertSame(403, $this->controller(987654320)->phase3OperationEffectLoop()->getCode());
    }
}
