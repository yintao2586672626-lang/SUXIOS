<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineTrafficDataExtractionService;
use think\exception\HttpException;

trait OnlineDailyDataPersistenceConcern
{
    private function buildCtripTrafficDateRange(string $dateRange, string $startDate, string $endDate, ?int $now = null): array
    {
        $baseTime = $now ?? time();
        $today = date('Y-m-d', $baseTime);
        $settledEndDate = date('Y-m-d', strtotime('-1 day', $baseTime));
        switch ($dateRange) {
            case 'today_realtime':
            case 'today':
            case '0':
                return [$today, $today];
            case 'last_7_days':
            case '7':
                return [date('Y-m-d', strtotime($settledEndDate . ' -6 days')), $settledEndDate];
            case 'last_30_days':
            case '30':
                return [date('Y-m-d', strtotime($settledEndDate . ' -29 days')), $settledEndDate];
            case 'custom':
                if ($startDate === '' || $endDate === '') {
                    throw new \InvalidArgumentException('请选择自定义开始日期和结束日期');
                }
                break;
            case 'yesterday':
            case '1':
            default:
                if ($startDate === '' || $endDate === '') {
                    return [$settledEndDate, $settledEndDate];
                }
                break;
        }

        if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$startDate, $endDate];
    }

    private function extractCtripTrafficRows($responseData): array
    {
        return OnlineTrafficDataExtractionService::extractCtripTrafficRows($responseData);
    }

    private function getOnlineDailyDataColumns(): array
    {
        return OnlineDailyDataPersistenceService::getColumns();
    }

    private function filterOnlineDailyDataFields(array $data): array
    {
        return OnlineDailyDataPersistenceService::filterFields($data);
    }

    private function buildOnlineDailyDataValidationFields(array $data): array
    {
        return OnlineDailyDataPersistenceService::buildValidationFields($data);
    }

    private function applyOnlineDailyDataValidationFields(array $data, ?array $columns = null): array
    {
        return OnlineDailyDataPersistenceService::applyValidationFields($data, $columns);
    }

    private function applyOnlineDailyDataPeriodFields(array $data, ?array $columns = null, array $sourceRow = []): array
    {
        return OnlineDailyDataPersistenceService::applyPeriodFields($data, $columns, $sourceRow);
    }

    private function applyOnlineDailyDataPeriodQuery($query, array $data, array $columns): void
    {
        OnlineDailyDataPersistenceService::applyPeriodQuery($query, $data, $columns);
    }

    private function normalizeOnlineDailyDataPeriod($value): string
    {
        return OnlineDailyDataPersistenceService::normalizePeriod($value);
    }

    private function normalizeOnlineDailyDateTime($value): ?string
    {
        return OnlineDailyDataPersistenceService::normalizeDateTime($value);
    }

    private function resolveOnlineDataSystemHotelId($input): ?int
    {
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                throw new HttpException(403, '无可访问酒店');
            }

            if ($input !== null && $input !== '' && is_numeric($input) && (int)$input > 0) {
                $hotelId = (int)$input;
                if (!in_array($hotelId, $permittedHotelIds, true)) {
                    throw new HttpException(403, '无权访问该酒店');
                }
                return $hotelId;
            }

            if (count($permittedHotelIds) === 1) {
                return $permittedHotelIds[0];
            }

            throw new HttpException(400, '请选择酒店');
        }

        if ($input !== null && $input !== '' && is_numeric($input) && (int)$input > 0) {
            return (int)$input;
        }

        return null;
    }
}
