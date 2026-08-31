<?php
declare(strict_types=1);

namespace app\service;

final class CtripManualFetchRequestService
{
    private const DEFAULT_BUSINESS_REPORT_URL = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
    private const DEFAULT_NODE_ID = '24588';
    private const BUSINESS_DAY_SETTLEMENT_HOUR = 8;

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

    public static function normalizeDateRange($startDate, $endDate, ?\DateTimeImmutable $now = null): array
    {
        $start = trim((string)$startDate);
        $end = trim((string)$endDate);
        if ($start === '' || $end === '') {
            $defaultBusinessDate = self::defaultBusinessDate($now);
            $start = $defaultBusinessDate;
            $end = $defaultBusinessDate;
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

    public static function defaultBusinessDate(?\DateTimeImmutable $now = null): string
    {
        $timeZone = new \DateTimeZone('Asia/Shanghai');
        $clock = ($now ?? new \DateTimeImmutable('now', $timeZone))->setTimezone($timeZone);
        $daysBack = (int)$clock->format('G') < self::BUSINESS_DAY_SETTLEMENT_HOUR ? 2 : 1;
        return $clock->modify("-{$daysBack} days")->format('Y-m-d');
    }

    /**
     * @return array{status:string,verified:bool,requested_date:string,source_business_date:?string,response_dates:array<int,string>,reason:string}
     */
    public static function verifyResponseBusinessDate(string $requestedDate, array $responseDates): array
    {
        $requestedDate = self::normalizeDate($requestedDate);
        $normalizedDates = [];
        foreach ($responseDates as $responseDate) {
            $normalized = self::normalizeDate((string)$responseDate);
            if ($normalized !== '') {
                $normalizedDates[$normalized] = true;
            }
        }
        $normalizedDates = array_keys($normalizedDates);
        sort($normalizedDates);

        if ($requestedDate === '') {
            return self::dateVerificationResult(
                'target_date_unverified',
                false,
                '',
                null,
                $normalizedDates,
                'requested_business_date_invalid'
            );
        }
        if ($normalizedDates === []) {
            return self::dateVerificationResult(
                'target_date_unverified',
                false,
                $requestedDate,
                null,
                [],
                'response_business_date_missing'
            );
        }
        if (count($normalizedDates) !== 1) {
            return self::dateVerificationResult(
                'target_date_unverified',
                false,
                $requestedDate,
                null,
                $normalizedDates,
                'response_business_date_ambiguous'
            );
        }

        $sourceBusinessDate = $normalizedDates[0];
        if ($sourceBusinessDate !== $requestedDate) {
            return self::dateVerificationResult(
                'target_date_mismatch',
                false,
                $requestedDate,
                $sourceBusinessDate,
                $normalizedDates,
                'response_business_date_mismatch'
            );
        }

        return self::dateVerificationResult(
            'verified',
            true,
            $requestedDate,
            $sourceBusinessDate,
            $normalizedDates,
            ''
        );
    }

    /** @return array<int,string> */
    public static function extractResponseDates(mixed $data): array
    {
        $dates = array_values(array_unique(array_column(self::extractResponseDateEvidence($data), 'date')));
        sort($dates);
        return $dates;
    }

    /** @return array<int,array{path:string,date:string}> */
    public static function extractResponseDateEvidence(mixed $data): array
    {
        $evidence = [];
        self::collectResponseDateEvidence($data, $evidence);
        $unique = [];
        foreach ($evidence as $item) {
            $key = $item['path'] . '|' . $item['date'];
            $unique[$key] = $item;
        }
        ksort($unique);
        return array_values($unique);
    }

    public static function hasRepeatedMultiDayFingerprint(string $startDate, string $endDate, array $dateResults): bool
    {
        if ($startDate === $endDate) {
            return false;
        }
        $fingerprints = array_values(array_unique(array_filter(array_column($dateResults, 'fingerprint'))));
        return count($fingerprints) === 1;
    }

    private static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
            $normalized = "{$matches[1]}-{$matches[2]}-{$matches[3]}";
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized, new \DateTimeZone('Asia/Shanghai'));
            return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $normalized ? $normalized : '';
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return self::normalizeDate("{$matches[1]}-{$matches[2]}-{$matches[3]}");
        }
        return '';
    }

    /** @param array<int,array{path:string,date:string}> $evidence */
    private static function collectResponseDateEvidence(
        mixed $data,
        array &$evidence,
        int $depth = 0,
        string $path = ''
    ): void
    {
        if (!is_array($data)) {
            return;
        }
        $dateKeys = ['dataDate', 'statDate', 'bizDate', 'businessDate', 'reportDate'];
        foreach ($dateKeys as $dateKey) {
            $value = $data[$dateKey] ?? null;
            if (!is_scalar($value)) {
                continue;
            }
            $date = self::normalizeDate((string)$value);
            if ($date !== '') {
                $evidence[] = [
                    'path' => $path !== '' ? $path . '.' . $dateKey : $dateKey,
                    'date' => $date,
                ];
            }
        }
        if ($depth >= 2) {
            return;
        }
        foreach (['data', 'result', 'response', 'payload', 'content'] as $containerKey) {
            if (is_array($data[$containerKey] ?? null)) {
                self::collectResponseDateEvidence(
                    $data[$containerKey],
                    $evidence,
                    $depth + 1,
                    $path !== '' ? $path . '.' . $containerKey : $containerKey
                );
            }
        }
    }

    /**
     * @param array<int,string> $responseDates
     * @return array{status:string,verified:bool,requested_date:string,source_business_date:?string,response_dates:array<int,string>,reason:string}
     */
    private static function dateVerificationResult(
        string $status,
        bool $verified,
        string $requestedDate,
        ?string $sourceBusinessDate,
        array $responseDates,
        string $reason
    ): array {
        return [
            'status' => $status,
            'verified' => $verified,
            'requested_date' => $requestedDate,
            'source_business_date' => $sourceBusinessDate,
            'response_dates' => $responseDates,
            'reason' => $reason,
        ];
    }
}
