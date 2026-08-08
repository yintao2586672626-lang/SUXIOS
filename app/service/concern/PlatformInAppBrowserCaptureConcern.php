<?php
declare(strict_types=1);

namespace app\service\concern;

use RuntimeException;

trait PlatformInAppBrowserCaptureConcern
{
    /**
     * Import one short-lived, same-origin business response captured from the
     * user's already-authenticated Codex in-app browser. This path never reads,
     * copies or stores browser credentials. It accepts fixed Meituan workbench
     * facts only after either response-derived POI/partner hashes or the visible
     * authenticated workbench hotel name match the bound Profile source.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function verifiedInAppBrowserCaptureResult(array $source, array $options): ?array
    {
        $capture = $options['in_app_browser_capture'] ?? null;
        if (!is_array($capture)) {
            return null;
        }
        $contractVersion = trim((string)($capture['contract_version'] ?? ''));
        $responseContract = $contractVersion === 'suxi_iab_meituan_capture.v1';
        $domContract = $contractVersion === 'suxi_iab_meituan_dom_capture.v1';
        if (($options['interactive_browser'] ?? null) !== true || (!$responseContract && !$domContract)) {
            throw new RuntimeException('In-app browser capture contract is invalid.', 422);
        }

        $sourceId = (int)($source['id'] ?? 0);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($sourceId <= 0
            || $hotelId <= 0
            || $platform !== 'meituan'
            || (int)($source['enabled'] ?? 0) !== 1
            || (int)($capture['data_source_id'] ?? 0) !== $sourceId
            || (int)($capture['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($capture['platform'] ?? ''))) !== $platform
        ) {
            throw new RuntimeException('In-app browser capture source scope mismatch.', 409);
        }

        $timezone = new \DateTimeZone('Asia/Shanghai');
        $now = new \DateTimeImmutable('now', $timezone);
        $capturedAtText = trim((string)($capture['captured_at'] ?? ''));
        $capturedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $capturedAtText, $timezone);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        $dataDate = trim((string)($capture['data_date'] ?? ''));
        if (!$capturedAt instanceof \DateTimeImmutable
            || ($dateErrors !== false && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0))
            || $capturedAt->format('Y-m-d H:i:s') !== $capturedAtText
            || $dataDate !== $capturedAt->format('Y-m-d')
            || $dataDate !== $now->format('Y-m-d')
            || $capturedAt->getTimestamp() > $now->getTimestamp() + 60
            || $now->getTimestamp() - $capturedAt->getTimestamp() > 900
        ) {
            throw new RuntimeException('In-app browser capture is stale or outside the current business date.', 422);
        }

        $responseStatuses = [];
        if ($responseContract) {
            if (trim((string)($capture['response_origin'] ?? '')) !== 'https://eb.meituan.com') {
                throw new RuntimeException('In-app browser capture response origin is not allowed.', 422);
            }
            $responseStatuses = is_array($capture['response_statuses'] ?? null)
                ? $capture['response_statuses']
                : [];
            foreach ([
                '/api/v1/ebooking/common/pois',
                '/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/workbench/simple',
                '/api/v1/ebooking/workbench/business/analysis',
            ] as $requiredPath) {
                if ((int)($responseStatuses[$requiredPath] ?? 0) !== 200) {
                    throw new RuntimeException('In-app browser capture is missing a required protected response.', 422);
                }
            }
        }

        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $expectedPoi = $this->firstScalarText($config, ['poi_id', 'poiId', 'store_id', 'storeId']);
        $expectedPartner = $this->firstScalarText($config, ['partner_id', 'partnerId']);
        $expectedPoiName = $this->firstScalarText($config, ['poi_name', 'poiName', 'store_name', 'storeName']);
        $identity = is_array($capture['identity'] ?? null) ? $capture['identity'] : [];
        $poiHash = strtolower(trim((string)($identity['poi_id_sha256'] ?? '')));
        $partnerHash = strtolower(trim((string)($identity['partner_id_sha256'] ?? '')));
        if ($responseContract) {
            if ($expectedPoi === ''
                || !preg_match('/^[a-f0-9]{64}$/D', $poiHash)
                || !hash_equals(hash('sha256', $expectedPoi), $poiHash)
                || ($expectedPartner !== ''
                    && (!preg_match('/^[a-f0-9]{64}$/D', $partnerHash)
                        || !hash_equals(hash('sha256', $expectedPartner), $partnerHash)))
            ) {
                throw new RuntimeException('In-app browser capture hotel identity mismatch.', 409);
            }
        } else {
            $pageOrigin = trim((string)($capture['page_origin'] ?? ''));
            $pagePath = trim((string)($capture['page_path'] ?? ''));
            $visibleSection = trim((string)($capture['visible_section'] ?? ''));
            $displayedHotelName = trim((string)($capture['displayed_hotel_name'] ?? ''));
            $configuredNameCore = preg_replace('/(?:美团)?数据源$/u', '', $expectedPoiName) ?? '';
            $configuredNameCore = rtrim(trim($configuredNameCore), '新');
            $normalizedNameCore = preg_replace('/[^\p{Han}\p{L}\p{N}]+/u', '', $configuredNameCore) ?? '';
            $normalizedDisplayedName = preg_replace('/[^\p{Han}\p{L}\p{N}]+/u', '', $displayedHotelName) ?? '';
            if ($expectedPoi === ''
                || $expectedPoiName === ''
                || $pageOrigin !== 'https://me.meituan.com'
                || !str_starts_with($pagePath, '/ebooking/merchant/ebIframe')
                || $visibleSection !== '实时数据'
                || mb_strlen($normalizedNameCore) < 4
                || !str_contains($normalizedDisplayedName, $normalizedNameCore)
            ) {
                throw new RuntimeException('In-app browser capture visible hotel identity mismatch.', 409);
            }
        }

        $acquisitionMethod = $responseContract ? 'in_app_browser_response' : 'in_app_browser_dom';
        $sourceEndpoint = $responseContract
            ? '/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/workbench/simple'
            : '/ebooking/merchant/ebIframe#实时数据';

        $facts = is_array($capture['facts'] ?? null) ? $capture['facts'] : [];
        $browseUsers = $this->boundedCaptureNumber($facts, 'browse_users', 0, 1_000_000, true);
        $stayRoomNights = $this->boundedCaptureNumber($facts, 'stay_room_nights', 0, 1_000_000, true);
        $salesAmount = $this->boundedCaptureNumber($facts, 'sales_amount', 0, 1_000_000_000, false);
        $fullRoomRate = $this->boundedCaptureNumber($facts, 'full_room_rate', 0, 100, false);
        $lostOrderCount = $this->boundedCaptureNumber($facts, 'lost_order_count', 0, 1_000_000, false);
        if ($browseUsers === null && $stayRoomNights === null && $salesAmount === null
            && $fullRoomRate === null && $lostOrderCount === null
        ) {
            throw new RuntimeException('In-app browser capture contains no verified business facts.', 422);
        }

        $rankings = is_array($capture['rankings'] ?? null) ? $capture['rankings'] : [];
        $rows = [];
        if ($browseUsers !== null) {
            $rows[] = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'data_date' => $dataDate,
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => $capturedAtText,
                'snapshot_bucket' => $capturedAt->format('YmdHi'),
                'system_hotel_id' => $hotelId,
                'dimension' => 'realtime:meituan_workbench',
                'detail_exposure' => $browseUsers,
                'data_value' => $browseUsers,
                'acquisition_method' => $acquisitionMethod,
                'source_contract' => $contractVersion,
                'raw_data' => [
                    'collection_mode' => $acquisitionMethod,
                    'source_contract' => $contractVersion,
                    'source_endpoint' => $sourceEndpoint,
                    'metrics' => [
                        'browse_users' => $browseUsers,
                        'peer_rank' => $this->captureRanking($rankings, 'browse_users'),
                    ],
                    'field_facts' => [
                        $this->inAppBrowserFieldFact('browse_users', 'traffic', 'data.cards[0].value', 'detail_exposure', $browseUsers),
                    ],
                ],
            ];
        }
        if ($stayRoomNights !== null || $salesAmount !== null || $fullRoomRate !== null || $lostOrderCount !== null) {
            $rows[] = [
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'business',
                'data_date' => $dataDate,
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => $capturedAtText,
                'snapshot_bucket' => $capturedAt->format('YmdHi'),
                'system_hotel_id' => $hotelId,
                'dimension' => 'realtime:meituan_workbench',
                'quantity' => $stayRoomNights,
                'amount' => $salesAmount,
                'data_value' => $fullRoomRate,
                'acquisition_method' => $acquisitionMethod,
                'source_contract' => $contractVersion,
                'raw_data' => [
                    'collection_mode' => $acquisitionMethod,
                    'source_contract' => $contractVersion,
                    'source_endpoint' => $sourceEndpoint,
                    'metrics' => [
                        'stay_room_nights' => $stayRoomNights,
                        'sales_amount' => $salesAmount,
                        'full_room_rate' => $fullRoomRate,
                        'lost_order_count' => $lostOrderCount,
                    ],
                    'peer_rankings' => [
                        'stay_room_nights' => $this->captureRanking($rankings, 'stay_room_nights'),
                        'full_room_rate' => $this->captureRanking($rankings, 'full_room_rate'),
                    ],
                    'field_facts' => [
                        $this->inAppBrowserFieldFact('stay_room_nights', 'business', 'data.cards[2].value', 'quantity', $stayRoomNights),
                        $this->inAppBrowserFieldFact('sales_amount', 'business', 'data.cards[1].value', 'amount', $salesAmount),
                        $this->inAppBrowserFieldFact('full_room_rate', 'business', 'data.cards[3].value', 'raw_data.metrics.full_room_rate', $fullRoomRate),
                        $this->inAppBrowserFieldFact('lost_order_count', 'business', 'data.cards[4].value', 'raw_data.metrics.lost_order_count', $lostOrderCount),
                    ],
                ],
            ];
        }

        return [
            'status' => 'success',
            'message' => 'in_app_browser_capture_verified',
            'http_status' => 200,
            'payload' => [
                'rows' => $rows,
                'auth_status' => [
                    'ok' => true,
                    'status' => 'logged_in',
                    'evidence_type' => $responseContract
                        ? 'protected_same_origin_business_json_2xx'
                        : 'authenticated_visible_workbench',
                ],
                'session_probe' => [
                    'performed' => true,
                    'status' => 'verified',
                    'proof_eligible' => true,
                    'evidence_level' => 'strong',
                ],
                'platform_identity_validation' => [
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => $expectedPoi,
                    'evidence_type' => $responseContract
                        ? 'protected_same_origin_identity_json'
                        : 'authenticated_visible_hotel_name',
                    'sensitive_values_exposed' => false,
                ],
                'capture_evidence' => [
                    'contract_version' => $contractVersion,
                    'captured_at' => $capturedAtText,
                    'data_date' => $dataDate,
                    'response_origin' => $responseContract ? 'https://eb.meituan.com' : '',
                    'response_paths' => $responseContract ? array_keys($responseStatuses) : [],
                    'page_origin' => $domContract ? 'https://me.meituan.com' : '',
                    'page_path' => $domContract ? '/ebooking/merchant/ebIframe' : '',
                    'identity_hash_matched' => $responseContract,
                    'visible_identity_matched' => $domContract,
                    'sensitive_values_exposed' => false,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $config @param array<int, string> $keys */
    private function firstScalarText(array $config, array $keys): string
    {
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @param array<string, mixed> $facts */
    private function boundedCaptureNumber(
        array $facts,
        string $key,
        float $minimum,
        float $maximum,
        bool $integer
    ): int|float|null {
        if (!array_key_exists($key, $facts) || $facts[$key] === null || $facts[$key] === '') {
            return null;
        }
        if (!is_int($facts[$key]) && !is_float($facts[$key])) {
            throw new RuntimeException('In-app browser capture fact type is invalid.', 422);
        }
        $value = (float)$facts[$key];
        if (!is_finite($value) || $value < $minimum || $value > $maximum || ($integer && floor($value) !== $value)) {
            throw new RuntimeException('In-app browser capture fact value is invalid.', 422);
        }
        return $integer ? (int)$value : $value;
    }

    /** @param array<string, mixed> $rankings @return array{rank:int,total:int}|null */
    private function captureRanking(array $rankings, string $key): ?array
    {
        $value = $rankings[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        $rank = (int)($value['rank'] ?? 0);
        $total = (int)($value['total'] ?? 0);
        return $rank > 0 && $total >= $rank ? ['rank' => $rank, 'total' => $total] : null;
    }

    /** @return array<string, mixed> */
    private function inAppBrowserFieldFact(
        string $metricKey,
        string $dataType,
        string $sourcePath,
        string $storageField,
        int|float|null $value
    ): array {
        $present = $value !== null;
        return [
            'metric_key' => $metricKey,
            'data_type' => $dataType,
            'source_path' => $sourcePath,
            'storage_table' => 'online_daily_data',
            'storage_field' => $storageField,
            'status' => $present ? 'captured' : 'missing',
            'missing_state' => $present ? '' : 'field_missing',
            'stored_value_present' => $present,
            'value' => $value,
        ];
    }
}
