<?php
declare(strict_types=1);

namespace app\service;

final class CtripManualFetchRequestService
{
    private const DEFAULT_BUSINESS_REPORT_URL = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
    private const DEFAULT_NODE_ID = '24588';

    public static function normalizeBusinessReportUrl(string $url): string
    {
        $value = trim($url);
        return $value === '' ? self::DEFAULT_BUSINESS_REPORT_URL : $value;
    }

    public static function normalizeNodeId(string $nodeId): string
    {
        $value = trim($nodeId);
        return $value === '' ? self::DEFAULT_NODE_ID : $value;
    }

    public static function normalizeDateRange($startDate, $endDate): array
    {
        $start = trim((string)$startDate);
        $end = trim((string)$endDate);
        if ($start === '' || $end === '') {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $start = $yesterday;
            $end = $yesterday;
        }

        $startTimestamp = strtotime($start);
        $endTimestamp = strtotime($end);
        if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
            throw new \InvalidArgumentException('日期范围无效');
        }

        return [
            'start_date' => date('Y-m-d', $startTimestamp),
            'end_date' => date('Y-m-d', $endTimestamp),
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp,
        ];
    }

    public static function buildDailyPostData(string $nodeId, string $date): array
    {
        return [
            'nodeId' => $nodeId,
            'startDate' => $date,
            'endDate' => $date,
        ];
    }

    public static function hasRepeatedMultiDayFingerprint(string $startDate, string $endDate, array $dateResults): bool
    {
        if ($startDate === $endDate) {
            return false;
        }
        $fingerprints = array_values(array_unique(array_filter(array_column($dateResults, 'fingerprint'))));
        return count($fingerprints) === 1;
    }
}
