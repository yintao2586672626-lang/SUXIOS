<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use UnexpectedValueException;

final class MeituanOrderFlowService
{
    public const ENDPOINT = 'https://eb.meituan.com/api/v1/ebooking/peerRank/order/loss/query';

    /** @return array<string, scalar> */
    public static function buildRequestParams(
        string $partnerId,
        string $poiId,
        string $direction,
        string $startDate,
        string $endDate
    ): array {
        if ($partnerId === '' || $poiId === '') {
            throw new InvalidArgumentException('meituan_order_flow_identity_missing');
        }
        if (!in_array($direction, ['loss', 'inflow'], true)) {
            throw new InvalidArgumentException('meituan_order_flow_direction_invalid');
        }

        [$startDate, $endDate] = MeituanManualFetchRequestService::normalizeDateRange($startDate, $endDate);

        $period = self::resolvePeriod($startDate, $endDate);

        return array_merge(MeituanManualFetchRequestService::baseApiParams(), [
            'csecversion' => '4.2.4',
            'partnerId' => $partnerId,
            'poiId' => $poiId,
            'lossType' => $direction === 'loss' ? 0 : 1,
            'startDate' => str_replace('-', '', $startDate),
            'endDate' => str_replace('-', '', $endDate),
            'dateRange' => match ($period) {
                'last_7_days' => 7,
                'last_30_days' => 30,
                default => 1,
            },
        ]);
    }

    /**
     * Normalize the two lossType responses into the existing captured-row contract.
     * All monetary values returned by this service are yuan.
     *
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeResponse(
        array $response,
        string $direction,
        string $startDate,
        string $endDate
    ): array {
        if (!in_array($direction, ['loss', 'inflow'], true)) {
            throw new InvalidArgumentException('meituan_order_flow_direction_invalid');
        }
        [$startDate, $endDate] = MeituanManualFetchRequestService::normalizeDateRange($startDate, $endDate);
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

        foreach (['lossTotalCnt', 'lossTotalPayRoomNight', 'lossTotalPayAmount'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new UnexpectedValueException('meituan_order_flow_summary_missing_' . $key);
            }
        }

        $orderCount = self::number($data['lossTotalCnt']);
        $roomNights = self::number($data['lossTotalPayRoomNight']);
        $amount = self::number($data['lossTotalPayAmount']);
        if ($orderCount === null || $roomNights === null || $amount === null) {
            throw new UnexpectedValueException('meituan_order_flow_summary_value_invalid');
        }

        $period = self::resolvePeriod($startDate, $endDate);
        $base = [
            'dataDate' => $endDate,
            'date_source' => 'request.query.endDate',
            'data_period' => 'historical_daily',
            'order_flow_direction' => $direction,
            'order_flow_period' => $period,
            'period_start' => $startDate,
            'period_end' => $endDate,
        ];
        $rows = [array_merge($base, [
            'order_flow_row_type' => 'summary',
            'dimension' => 'order_flow:' . $period . ':' . $direction . ':summary',
            'order_count' => $orderCount,
            'room_nights' => $roomNights,
            'amount' => $amount,
            'poi_star' => self::text($data['poiStar'] ?? ''),
        ])];

        $details = is_array($data['orderLossPeerDetails'] ?? null) ? $data['orderLossPeerDetails'] : [];
        foreach ($details as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $poiId = self::text($item['poiId'] ?? $item['poi_id'] ?? '');
            $poiName = self::text($item['poiName'] ?? $item['poi_name'] ?? '');
            if ($poiId === '' && $poiName === '') {
                continue;
            }
            $detailOrderCount = self::number($item['lossOrderCount'] ?? null);
            $detailAmount = self::number($item['lossSinglePayAmount'] ?? null);
            $rooms = [];
            foreach (is_array($item['lossRoomList'] ?? null) ? $item['lossRoomList'] : [] as $room) {
                if (!is_array($room)) {
                    continue;
                }
                $roomName = self::text($room['lossRoomName'] ?? $room['roomName'] ?? '');
                if ($roomName === '') {
                    continue;
                }
                $rooms[] = [
                    'lossRoomName' => $roomName,
                    'lossRoomCnt' => self::number($room['lossRoomCnt'] ?? $room['roomCount'] ?? null),
                ];
            }

            $rows[] = array_merge($base, [
                'order_flow_row_type' => 'hotel_detail',
                'dimension' => 'order_flow:' . $period . ':' . $direction . ':hotel:' . ($poiId !== '' ? $poiId : (string)($index + 1)),
                'poiId' => $poiId,
                'poiName' => $poiName,
                'frontImg' => self::text($item['frontImg'] ?? $item['front_img'] ?? ''),
                'lossPoiStar' => self::text($item['lossPoiStar'] ?? $item['loss_poi_star'] ?? ''),
                'circleName' => self::text($item['circleName'] ?? $item['circle_name'] ?? ''),
                'distance' => self::number($item['distance'] ?? null),
                'score' => self::number($item['score'] ?? null),
                'lowestPrice' => self::number($item['lowestPrice'] ?? $item['lowest_price'] ?? null),
                'vipTag' => self::boolean($item['vipTag'] ?? $item['vip_tag'] ?? false),
                'followStatus' => self::number($item['followStatus'] ?? $item['follow_status'] ?? null),
                'lossOrderCount' => $detailOrderCount,
                'lossOrderRatio' => self::number($item['lossOrderRatio'] ?? null),
                'lossSinglePayAmount' => $detailAmount,
                'lossRoomList' => $rooms,
                'order_count' => $detailOrderCount,
                'order_ratio' => self::number($item['lossOrderRatio'] ?? null),
                'amount' => $detailAmount,
                'compare_type' => 'competitor',
            ]);
        }

        return $rows;
    }

    public static function resolvePeriod(string $startDate, string $endDate): string
    {
        [$startDate, $endDate] = MeituanManualFetchRequestService::normalizeDateRange($startDate, $endDate);
        $start = strtotime($startDate . ' 00:00:00');
        $end = strtotime($endDate . ' 00:00:00');
        if ($start === false || $end === false || $end < $start) {
            return 'custom';
        }
        $days = (int)floor(($end - $start) / 86400) + 1;
        return match ($days) {
            1 => 'yesterday',
            7 => 'last_7_days',
            30 => 'last_30_days',
            default => 'custom',
        };
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        $text = trim((string)$value);
        if ($text === '' || in_array($text, ['-', '--'], true) || preg_match('/暂无|无数据|更新中/i', $text) === 1) {
            return null;
        }
        $multiplier = str_contains($text, '亿') ? 100000000 : (str_contains($text, '万') ? 10000 : 1);
        $normalized = str_replace([',', '%', '￥', '¥', '元', '万', '亿', ' '], '', $text);
        if (preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches) !== 1) {
            return null;
        }
        $number = (float)$matches[0] * $multiplier;
        return is_finite($number) ? $number : null;
    }

    private static function text(mixed $value): string
    {
        return trim((string)($value ?? ''));
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || strtolower(trim((string)$value)) === 'true';
    }
}
