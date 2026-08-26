<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ApiExceptionMapper;
use app\service\AiDailyReportBroadcastSnapshotService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

/**
 * Local-only trusted broadcast snapshot API. It intentionally has no WeCom,
 * scheduler, OTA write, PMS write, approval, or execution dependency.
 */
final class AiDailyReportBroadcast extends Base
{
    private const BUSINESS_ERRORS = [
        'not logged in' => 401,
        'no permitted hotel' => 403,
        'hotel_id is not permitted' => 403,
    ];

    private AiDailyReportBroadcastSnapshotService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new AiDailyReportBroadcastSnapshotService();
    }

    public function latest(): Response
    {
        try {
            $hotelId = (int)$this->request->param('hotel_id', 0);
            $businessDate = $this->businessDate((string)$this->request->param('report_date', ''));
            [$hotelIds, $resolvedHotelId] = $this->resolveHotelScope($hotelId);
            if ($resolvedHotelId === null) {
                throw new InvalidArgumentException('请选择一个酒店后读取可信播报');
            }

            return $this->success($this->service->latestOrPreview(
                $resolvedHotelId,
                $businessDate,
                $hotelIds
            ));
        } catch (Throwable $error) {
            return ApiExceptionMapper::response($error, '可信经营播报读取失败', self::BUSINESS_ERRORS);
        }
    }

    public function read(int $snapshotId): Response
    {
        try {
            if ($snapshotId <= 0) {
                throw new InvalidArgumentException('可信经营播报快照编号无效');
            }
            [$hotelIds] = $this->resolveHotelScope();
            $snapshot = $this->service->readExact($snapshotId, $hotelIds);
            if (!is_array($snapshot)) {
                return $this->error('可信经营播报快照不存在或不在当前门店权限范围内', 404);
            }
            return $this->success($snapshot);
        } catch (Throwable $error) {
            return ApiExceptionMapper::response($error, '可信经营播报快照读取失败', self::BUSINESS_ERRORS);
        }
    }

    public function generate(): Response
    {
        try {
            $input = $this->requestData();
            $hotelId = (int)($input['hotel_id'] ?? 0);
            $businessDate = $this->businessDate((string)($input['report_date'] ?? $input['business_date'] ?? ''));
            [, $resolvedHotelId] = $this->resolveHotelScope($hotelId);
            if ($resolvedHotelId === null) {
                throw new InvalidArgumentException('请选择一个酒店后生成可信播报');
            }

            return $this->success($this->service->generateAndReadback(
                $resolvedHotelId,
                $businessDate,
                (int)($this->currentUser->id ?? 0),
                'manual'
            ));
        } catch (Throwable $error) {
            return ApiExceptionMapper::response($error, '可信经营播报生成失败', self::BUSINESS_ERRORS);
        }
    }

    /** @return array{0:list<int>,1:?int} */
    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('not logged in');
        }

        $permitted = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        if ($permitted === []) {
            throw new RuntimeException('no permitted hotel');
        }
        if ($inputHotelId > 0) {
            if (!in_array($inputHotelId, $permitted, true)) {
                throw new RuntimeException('hotel_id is not permitted');
            }
            return [[$inputHotelId], $inputHotelId];
        }
        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function businessDate(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : date('Y-m-d', strtotime('-1 day'));
    }

}
