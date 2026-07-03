<?php
declare(strict_types=1);

namespace app\service;

final class MeituanManualFetchRequestService
{
    public static function missingRankResourceFields(string $partnerId, string $poiId): array
    {
        $missing = [];
        if ($partnerId === '') {
            $missing[] = 'Partner ID';
        }
        if ($poiId === '') {
            $missing[] = 'POI ID';
        }
        return $missing;
    }

    public static function resolveResourceIds(array $params, string $partnerId, string $poiId, string $shopId = ''): array
    {
        $resolvedPartnerId = $partnerId !== ''
            ? $partnerId
            : trim((string)($params['partnerId'] ?? $params['partner_id'] ?? ''));
        $resolvedPoiId = $poiId !== ''
            ? $poiId
            : trim((string)($params['poiId'] ?? $params['poi_id'] ?? ''));
        $resolvedShopId = $shopId !== ''
            ? $shopId
            : trim((string)($params['shopId'] ?? $params['shop_id'] ?? $resolvedPoiId));

        return [$resolvedPartnerId, $resolvedPoiId, $resolvedShopId];
    }

    public static function buildRankRequestParams(
        string $dataScope,
        string $partnerId,
        string $poiId,
        string $rankType,
        $dateRange,
        string $startDate,
        string $endDate
    ): array {
        $params = [
            'dataScope' => $dataScope,
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
        ];

        if ($partnerId !== '') {
            $params['partnerId'] = $partnerId;
        }
        if ($poiId !== '') {
            $params['poiId'] = $poiId;
        }
        if ($rankType !== '') {
            $params['rankType'] = $rankType;
        }

        $normalizedRange = (int)$dateRange;
        if ($startDate !== '' && $endDate !== '') {
            [$startDate, $endDate] = self::normalizeRankExplicitDateRange($startDate, $endDate);
            $params['startDate'] = str_replace('-', '', $startDate);
            $params['endDate'] = str_replace('-', '', $endDate);
            $params['dateRange'] = 1;
        } else {
            self::applyRelativeRankDateRange($params, $normalizedRange, $startDate);
        }

        $endDate = self::deriveEndDateFromParams($endDate, $params);

        return [
            'params' => $params,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'date_range' => (int)($params['dateRange'] ?? $normalizedRange),
        ];
    }

    public static function buildTrafficRequestParams(array $extraParams, string $partnerId, string $poiId, string $startDate, string $endDate): array
    {
        $params = array_merge(self::baseApiParams(), $extraParams);
        $params['partnerId'] = $partnerId;
        $params['poiId'] = $poiId;

        if ($startDate !== '' && $endDate !== '') {
            $params['startDate'] = str_replace('-', '', $startDate);
            $params['endDate'] = str_replace('-', '', $endDate);
            $params['dateRange'] = 1;
        } else {
            $yesterday = date('Ymd', strtotime('-1 day'));
            $params['startDate'] = $yesterday;
            $params['endDate'] = $yesterday;
            $params['dateRange'] = 1;
            $startDate = date('Y-m-d', strtotime('-1 day'));
        }

        $endDate = self::deriveEndDateFromParams($endDate, $params);

        return [
            'params' => $params,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    public static function normalizeDateRange(string $startDate, string $endDate): array
    {
        $start = self::normalizeDate($startDate);
        $end = self::normalizeDate($endDate);
        if ($start === '' && $end === '') {
            $start = date('Y-m-d', strtotime('-1 day'));
            $end = $start;
        } elseif ($start === '') {
            $start = $end;
        } elseif ($end === '') {
            $end = $start;
        }
        if (strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$start, $end];
    }

    public static function baseApiParams(): array
    {
        return [
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
        ];
    }

    private static function applyRelativeRankDateRange(array &$params, int $dateRange, string &$startDate): void
    {
        switch ($dateRange) {
            case 0:
                $today = date('Ymd');
                $params['startDate'] = $today;
                $params['endDate'] = $today;
                $params['dateRange'] = 0;
                $startDate = date('Y-m-d');
                break;
            case 7:
                $params['startDate'] = date('Ymd', strtotime('-7 days'));
                $params['endDate'] = date('Ymd');
                $params['dateRange'] = 7;
                $startDate = date('Y-m-d', strtotime('-7 days'));
                break;
            case 30:
                $params['startDate'] = date('Ymd', strtotime('-30 days'));
                $params['endDate'] = date('Ymd');
                $params['dateRange'] = 30;
                $startDate = date('Y-m-d', strtotime('-30 days'));
                break;
            case 1:
            default:
                $yesterday = date('Ymd', strtotime('-1 day'));
                $params['startDate'] = $yesterday;
                $params['endDate'] = $yesterday;
                $params['dateRange'] = 1;
                $startDate = date('Y-m-d', strtotime('-1 day'));
                break;
        }
    }

    private static function deriveEndDateFromParams(string $endDate, array $params): string
    {
        if ($endDate !== '' || empty($params['endDate']) || !preg_match('/^\d{8}$/', (string)$params['endDate'])) {
            return $endDate;
        }
        $value = (string)$params['endDate'];
        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }

    private static function normalizeRankExplicitDateRange(string $startDate, string $endDate): array
    {
        $start = self::normalizeDate($startDate);
        $end = self::normalizeDate($endDate);
        if ($start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            throw new \InvalidArgumentException('日期范围无效');
        }

        $today = date('Y-m-d');
        if ($start > $today || $end > $today) {
            throw new \InvalidArgumentException('美团竞对榜单接口不支持未来日期，请选择今日实时、昨日、近7天、近30天或历史自定义日期');
        }

        return [$start, $end];
    }

    private static function normalizeDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $rawText = trim((string)$value);
        if (preg_match('/^(19|20)\d{6}$/', $rawText)) {
            $year = (int)substr($rawText, 0, 4);
            $month = (int)substr($rawText, 4, 2);
            $day = (int)substr($rawText, 6, 2);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $rawText, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($rawText);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }
}
