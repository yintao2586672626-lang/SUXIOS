<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/** Navigation metadata only. Stored rows are never upgraded to verified facts. */
final class StoredOtaHistoryLocator
{
    public function locate(int $tenantId, int $hotelId, string $requestedDate): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $requestedDate) {
            throw new InvalidArgumentException('stored_history_business_date_invalid');
        }
        $result = [
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'requested_business_date' => $requestedDate,
            'status' => 'blocked',
            'evidence_status' => 'stored_only_not_business_validation',
            'platforms' => [],
        ];
        if ($tenantId <= 0 || $hotelId <= 0) {
            return $result;
        }
        try {
            if (!Db::name('hotels')->where('id', $hotelId)->where('tenant_id', $tenantId)->value('id')) {
                return $result;
            }
        } catch (\Throwable) {
            return array_replace($result, ['status' => 'error']);
        }
        $lastCompletedDate = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        // The shortcut points to an earlier business day. A stored but unverified
        // row on the requested day must not hide an older navigable record.
        $cutoff = min($date->modify('-1 day')->format('Y-m-d'), $lastCompletedDate);
        foreach (['ctrip', 'meituan'] as $platform) {
            try {
                $storedDate = Db::name('online_daily_data')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('source', $platform)
                    ->where('data_period', 'historical_daily')
                    ->whereIn('data_type', ['business', 'traffic', 'traffic_analysis', 'order'])
                    ->where('data_date', '<=', $cutoff)
                    ->order('data_date', 'desc')
                    ->value('data_date');
                $result['platforms'][] = [
                    'platform' => $platform,
                    'status' => $storedDate === null ? 'empty' : 'stored',
                    'latest_stored_date' => $storedDate,
                ];
            } catch (\Throwable) {
                $result['platforms'][] = ['platform' => $platform, 'status' => 'error', 'latest_stored_date' => null];
            }
        }
        $states = array_column($result['platforms'], 'status');
        $result['status'] = in_array('error', $states, true)
            ? (in_array('stored', $states, true) ? 'partial' : 'error')
            : (in_array('stored', $states, true) ? 'ready' : 'empty');
        return $result;
    }
}
