<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/**
 * Deterministic plan for the already-implemented Ctrip and Meituan collectors.
 *
 * This class does not define or call a new OTA endpoint. It only orders the
 * existing collector sections and records which existing response rules are
 * expected to produce the yesterday facts needed by the downstream gate.
 */
final class OtaOrderedCollectionPlanner
{
    public const CONTRACT_VERSION = 'ota_ordered_collection.v1';

    /** @var array<string, array<int, string>> */
    private const REQUIRED_FIELD_KEYS = [
        'ctrip' => [
            'order_amount',
            'room_nights',
            'order_count',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ],
        'meituan' => [
            'order_amount',
            'room_nights',
            'order_count',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
        ],
    ];

    /** @var array<string, array<int, string>> */
    private const REVENUE_FIELD_KEYS = [
        'ctrip' => ['order_amount', 'room_nights', 'order_count'],
        'meituan' => ['order_amount', 'room_nights', 'order_count'],
    ];

    /** @var array<string, array<int, string>> */
    private const TRAFFIC_FIELD_KEYS = [
        'ctrip' => [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ],
        'meituan' => ['list_exposure', 'detail_exposure', 'flow_rate'],
    ];

    /** @var array<string,string> */
    private const STORAGE_FIELD_COLUMNS = [
        'order_amount' => 'amount',
        'room_nights' => 'quantity',
        'order_count' => 'book_order_num',
        'list_exposure' => 'list_exposure',
        'detail_exposure' => 'detail_exposure',
        'flow_rate' => 'flow_rate',
        'order_filling_num' => 'order_filling_num',
        'order_submit_num' => 'order_submit_num',
    ];

    /**
     * Only interfaces already present in the current collector/catalog are
     * listed. Supporting example capabilities are deliberately absent.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function inventory(): array
    {
        return [
            'ctrip' => [
                'section_order' => ['business_overview', 'traffic_report'],
                'date_capability' => 'target_date_context_plus_response_or_page_date_evidence',
                'interfaces' => [
                    [
                        'id' => 'business_market_overview',
                        'section' => 'business_overview',
                        'matchers' => ['fetchMarketOverViewV2'],
                        'field_keys' => ['order_amount', 'room_nights', 'avg_price'],
                        'role' => 'yesterday_core',
                    ],
                    [
                        'id' => 'business_capacity',
                        'section' => 'business_overview',
                        'matchers' => ['fetchCapacityOverViewV4'],
                        'field_keys' => ['room_nights', 'order_count', 'occupancy_rate'],
                        'role' => 'yesterday_core',
                    ],
                    [
                        'id' => 'business_flow_transform',
                        'section' => 'business_overview',
                        'matchers' => [
                            'queryFlowTransformNewV1',
                            'queryFlowTransforNewV1',
                            'queryFlowTransferNewV1',
                        ],
                        'field_keys' => self::TRAFFIC_FIELD_KEYS['ctrip'],
                        'role' => 'yesterday_core',
                    ],
                    [
                        'id' => 'traffic_flow_transform',
                        'section' => 'traffic_report',
                        'matchers' => [
                            'queryFlowTransformNewV1',
                            'queryFlowTransforNewV1',
                            'queryFlowTransferNewV1',
                        ],
                        'field_keys' => self::TRAFFIC_FIELD_KEYS['ctrip'],
                        'role' => 'targeted_gap',
                    ],
                    [
                        'id' => 'traffic_scan_flow',
                        'section' => 'traffic_report',
                        'matchers' => ['queryScanFlowDetailsV2'],
                        'field_keys' => self::TRAFFIC_FIELD_KEYS['ctrip'],
                        'role' => 'necessary_support',
                    ],
                    [
                        'id' => 'traffic_order_overview',
                        'section' => 'traffic_report',
                        'matchers' => ['fetchOrderOverView'],
                        'field_keys' => [
                            'order_amount',
                            'room_nights',
                            'order_count',
                            ...self::TRAFFIC_FIELD_KEYS['ctrip'],
                        ],
                        'role' => 'necessary_support',
                    ],
                    [
                        'id' => 'traffic_order_trend',
                        'section' => 'traffic_report',
                        'matchers' => ['queryOrderTrendV1'],
                        'field_keys' => ['order_amount', 'room_nights', 'order_count'],
                        'role' => 'necessary_support',
                    ],
                ],
                'required_field_keys' => self::REQUIRED_FIELD_KEYS['ctrip'],
            ],
            'meituan' => [
                // Revenue facts come first. A successful traffic capture is
                // not enough to open the downstream revenue/AI gate, and the
                // orders page has its own exact-date query proof.
                'section_order' => ['orders', 'traffic'],
                'date_capability' => 'traffic_response_date_evidence_plus_orders_query_date_readback',
                'interfaces' => [
                    [
                        'id' => 'traffic_cards',
                        'section' => 'traffic',
                        'matchers' => ['businessdata', 'weighttraffic', 'traffic'],
                        'field_keys' => self::TRAFFIC_FIELD_KEYS['meituan'],
                        'role' => 'yesterday_core',
                    ],
                    [
                        'id' => 'flow_conversion',
                        'section' => 'traffic',
                        'matchers' => ['flowconversion', 'flowtrend', 'flowtrenddetail'],
                        'field_keys' => self::TRAFFIC_FIELD_KEYS['meituan'],
                        'role' => 'targeted_gap',
                    ],
                    [
                        'id' => 'orders_daily_summary',
                        'section' => 'orders',
                        'matchers' => ['/api/v1/ebooking/orders'],
                        'field_keys' => self::REVENUE_FIELD_KEYS['meituan'],
                        'role' => 'yesterday_core',
                    ],
                ],
                'required_field_keys' => self::REQUIRED_FIELD_KEYS['meituan'],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function requiredFieldKeys(string $platform): array
    {
        $platform = self::platform($platform);
        return self::REQUIRED_FIELD_KEYS[$platform];
    }

    /** @return array<int,string> */
    public static function requiredStorageColumns(string $platform): array
    {
        return array_values(array_map(
            static fn(string $fieldKey): string => self::STORAGE_FIELD_COLUMNS[$fieldKey],
            self::requiredFieldKeys($platform)
        ));
    }

    /** @return array<int, string> */
    public static function defaultSections(string $platform): array
    {
        $platform = self::platform($platform);
        return self::inventory()[$platform]['section_order'];
    }

    /**
     * Legacy setup may contain separate business/traffic source rows for one
     * browser Profile. They are one account scope, so an unscoped schedule and
     * its status view must select the same deterministic source.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    public static function oneSourcePerBrowserProfileAccount(array $sources): array
    {
        $selected = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $key = self::browserProfileAccountScopeKey($source)
                . ':hotel:' . max(0, (int)($source['system_hotel_id'] ?? 0));
            $current = $selected[$key] ?? null;
            if (!is_array($current)
                || self::browserProfileSourcePreference($source)
                    > self::browserProfileSourcePreference($current)
            ) {
                $selected[$key] = $source;
            }
        }
        return array_values($selected);
    }

    /** @param array<string, mixed> $source */
    public static function browserProfileAccountScopeKey(array $source): string
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $config = self::decodeArray($source['config_json'] ?? []);
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['stable_profile_id', 'stableProfileId', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            $value = strtolower(trim((string)($config[$key] ?? '')));
            if ($value !== '') {
                return $platform . ':' . hash('sha256', $value);
            }
        }
        return $platform . ':source:' . max(0, (int)($source['id'] ?? 0));
    }

    /**
     * @param array<int, mixed> $missingFieldKeys
     * @return array<int, string>
     */
    public static function sectionsForMissing(string $platform, array $missingFieldKeys): array
    {
        $platform = self::platform($platform);
        $missing = array_values(array_unique(array_intersect(
            self::normalizeKeys($missingFieldKeys),
            self::REQUIRED_FIELD_KEYS[$platform]
        )));
        if ($missing === []) {
            return self::defaultSections($platform);
        }

        $sections = [];
        if (array_intersect($missing, self::REVENUE_FIELD_KEYS[$platform]) !== []) {
            $sections[] = $platform === 'ctrip' ? 'business_overview' : 'orders';
        }
        if (array_intersect($missing, self::TRAFFIC_FIELD_KEYS[$platform]) !== []) {
            $sections[] = $platform === 'ctrip' ? 'traffic_report' : 'traffic';
        }

        $order = self::defaultSections($platform);
        usort($sections, static fn(string $left, string $right): int =>
            array_search($left, $order, true) <=> array_search($right, $order, true)
        );
        return array_values(array_unique($sections));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function capturedFieldKeys(string $platform, array $rows): array
    {
        $platform = self::platform($platform);
        $aliases = [
            'order_amount' => ['order_amount', 'orderAmount', 'amount', 'bookAmount', 'saleAmount', 'totalAmount'],
            'room_nights' => ['room_nights', 'roomNights', 'quantity', 'bookQuantity', 'nightNum'],
            'order_count' => ['order_count', 'orderCount', 'orders', 'book_order_num', 'bookOrderNum', 'orderQuantity'],
            'list_exposure' => ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount'],
            'detail_exposure' => ['detail_exposure', 'detailExposure', 'detail_visitor', 'detailVisitor'],
            'flow_rate' => ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate', 'closeRate'],
            'order_filling_num' => ['order_filling_num', 'orderFillingNum', 'orderVisitors'],
            'order_submit_num' => ['order_submit_num', 'orderSubmitNum', 'submitUsers'],
        ];

        $captured = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            $eligibleFieldKeys = match (true) {
                $dataType === '' => self::REQUIRED_FIELD_KEYS[$platform],
                in_array($dataType, ['traffic', 'flow', 'conversion'], true) =>
                    self::TRAFFIC_FIELD_KEYS[$platform],
                in_array($dataType, ['business', 'business_overview', 'revenue', 'order', 'orders'], true) =>
                    self::REVENUE_FIELD_KEYS[$platform],
                default => [],
            };
            foreach (self::REQUIRED_FIELD_KEYS[$platform] as $fieldKey) {
                if (!in_array($fieldKey, $eligibleFieldKeys, true)) {
                    continue;
                }
                foreach ($aliases[$fieldKey] ?? [$fieldKey] as $alias) {
                    if (array_key_exists($alias, $row) && self::hasFactValue($row[$alias])) {
                        $captured[$fieldKey] = true;
                        break;
                    }
                }
            }
            // raw_data.field_facts is provenance only. Completeness must be
            // established by a persisted top-level canonical value above.
        }

        return array_values(array_filter(
            self::REQUIRED_FIELD_KEYS[$platform],
            static fn(string $fieldKey): bool => isset($captured[$fieldKey])
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    public static function missingFieldKeys(string $platform, array $rows): array
    {
        $platform = self::platform($platform);
        return array_values(array_diff(
            self::REQUIRED_FIELD_KEYS[$platform],
            self::capturedFieldKeys($platform, $rows)
        ));
    }

    /**
     * Keep only exact-date, read-back OTA facts that may satisfy the yesterday
     * core contract. Forecast, realtime, peer/competitor and invalid rows stay
     * visible in their own modules but never suppress a required recollection.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function storedCoreRows(string $platform, array $rows): array
    {
        $platform = self::platform($platform);
        $allowedDataTypes = [
            'business',
            'business_overview',
            'revenue',
            'order',
            'orders',
            'traffic',
            'flow',
            'conversion',
        ];
        $blockedPeriods = [
            'realtime_snapshot',
            'next_7_days',
            'next_30_days',
            'forecast',
            'future_forecast',
        ];
        $blockedValidationStatuses = [
            'blocked',
            'failed',
            'invalid',
            'rejected',
            'unverified',
        ];

        return array_values(array_filter($rows, static function ($row) use (
            $platform,
            $allowedDataTypes,
            $blockedPeriods,
            $blockedValidationStatuses
        ): bool {
            if (!is_array($row)) {
                return false;
            }
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($rowPlatform === '') {
                $rowPlatform = strtolower(trim((string)($row['source'] ?? '')));
            }
            if ($rowPlatform !== $platform) {
                return false;
            }
            if (array_key_exists('readback_verified', $row)
                && (int)$row['readback_verified'] !== 1
            ) {
                return false;
            }
            $period = strtolower(trim((string)($row['data_period'] ?? '')));
            if (in_array($period, $blockedPeriods, true)) {
                return false;
            }
            $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
            if (in_array($validationStatus, $blockedValidationStatuses, true)) {
                return false;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            if (!in_array($dataType, $allowedDataTypes, true)) {
                return false;
            }
            if (!self::storedRowIsOwnOperating($row)) {
                return false;
            }
            if ($platform === 'ctrip'
                && in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                && !self::ctripTrafficRowIsAuthoritative($row)
            ) {
                return false;
            }
            return true;
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function requestPlanFromStoredRows(
        string $platform,
        string $targetDate,
        array $rows,
        bool $sourceRecoveryRequired = false,
        string $reason = ''
    ): array {
        $platform = self::platform($platform);
        $eligibleRows = self::storedCoreRows($platform, $rows);
        $captured = self::capturedFieldKeys($platform, $eligibleRows);
        $missing = $sourceRecoveryRequired
            ? self::requiredFieldKeys($platform)
            : array_values(array_diff(self::requiredFieldKeys($platform), $captured));

        if (!$sourceRecoveryRequired && $eligibleRows !== [] && $missing === []) {
            $plan = self::requestPlan(
                $platform,
                $targetDate,
                [],
                $reason !== '' ? $reason : 'target_date_core_already_verified'
            );
            $plan['stage'] = 'verified_complete';
            $plan['sections'] = [];
            $plan['interface_ids'] = [];
        } else {
            $defaultReason = $sourceRecoveryRequired
                ? 'source_state_requires_recovery'
                : ($eligibleRows === [] ? 'target_date_core_absent' : 'target_date_field_gap');
            $plan = self::requestPlan(
                $platform,
                $targetDate,
                $missing,
                $reason !== '' ? $reason : $defaultReason
            );
            if ($sourceRecoveryRequired) {
                $plan['stage'] = 'conflict_recovery';
            } elseif ($eligibleRows === []) {
                $plan['stage'] = 'yesterday_core';
            }
        }

        $plan['source_recovery_required'] = $sourceRecoveryRequired;
        $plan['eligible_row_count'] = count($eligibleRows);
        $plan['captured_field_keys'] = $captured;
        $plan['missing_field_keys'] = $missing;
        return $plan;
    }

    /**
     * @param array<int, mixed> $missingFieldKeys
     * @return array<string, mixed>
     */
    public static function requestPlan(
        string $platform,
        string $targetDate,
        array $missingFieldKeys = [],
        string $reason = ''
    ): array {
        $platform = self::platform($platform);
        $missing = array_values(array_unique(array_intersect(
            self::normalizeKeys($missingFieldKeys),
            self::REQUIRED_FIELD_KEYS[$platform]
        )));
        $sections = self::sectionsForMissing($platform, $missing);
        $interfaceIds = [];
        foreach (self::inventory()[$platform]['interfaces'] as $interface) {
            if (in_array((string)$interface['section'], $sections, true)) {
                $interfaceIds[] = (string)$interface['id'];
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'ordered_yesterday_gap_only',
            'scope' => 'ota_yesterday_core',
            'platform' => $platform,
            'target_date' => $targetDate,
            'stage' => $missing === [] ? 'yesterday_core' : 'targeted_gap',
            'reason' => trim($reason),
            'sections' => $sections,
            'interface_ids' => array_values(array_unique($interfaceIds)),
            'required_field_keys' => self::REQUIRED_FIELD_KEYS[$platform],
            'missing_field_keys' => $missing,
            'excluded_example_capabilities' => [
                'comments',
                'realtime',
                'ads',
                'subchannels',
            ],
        ];
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private static function normalizeKeys(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            $values
        ))));
    }

    /** @param array<string, mixed> $source @return array<int, int> */
    private static function browserProfileSourcePreference(array $source): array
    {
        $statusRank = [
            'success' => 5,
            'ready' => 4,
            'partial_success' => 3,
            'failed' => 2,
            'waiting_config' => 1,
        ];
        $config = self::decodeArray($source['config_json'] ?? []);
        // A generated source projection may expose one data type (for example
        // traffic), but it shares the same browser Profile as its owning source.
        // Scheduling the projection separately duplicates browser work and can
        // leave a second task running for the same account. Keep projections in
        // the field map, but prefer the owning Profile source for execution.
        $isProjection = array_values(array_filter(
            (array)($config['source_projection_ids'] ?? []),
            static fn(mixed $id): bool => (int)$id > 0
        )) !== [];
        $dataType = strtolower(trim((string)($source['data_type'] ?? '')));
        $dataTypeRank = in_array($dataType, ['traffic', 'flow', 'conversion'], true)
            ? 2
            : (in_array($dataType, ['business', 'business_overview', 'order', 'orders'], true) ? 1 : 0);
        $lastSyncTimestamp = strtotime((string)($source['last_sync_time'] ?? ''));
        return [
            $statusRank[strtolower(trim((string)($source['status'] ?? '')))] ?? 0,
            $isProjection ? 0 : 1,
            $dataTypeRank,
            $lastSyncTimestamp === false ? 0 : $lastSyncTimestamp,
            max(0, (int)($source['id'] ?? 0)),
        ];
    }

    private static function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!array_key_exists($platform, self::REQUIRED_FIELD_KEYS)) {
            throw new InvalidArgumentException('Unsupported OTA platform: ' . $platform);
        }
        return $platform;
    }

    private static function hasFactValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return is_scalar($value);
    }

    /** @param array<string, mixed> $row */
    private static function ctripTrafficRowIsAuthoritative(array $row): bool
    {
        $dimension = trim((string)($row['dimension'] ?? ''));
        $endpointId = '';
        if (preg_match('/^catalog:[^:]+:([^:]+)/', $dimension, $matches) === 1) {
            $endpointId = strtolower(trim((string)($matches[1] ?? '')));
        }
        if ($endpointId === '') {
            $raw = self::decodeArray($row['raw_data'] ?? []);
            foreach ([
                $raw['endpoint_id'] ?? null,
                $raw['endpointId'] ?? null,
                $raw['capture']['endpoint_id'] ?? null,
                $raw['capture']['endpointId'] ?? null,
            ] as $candidate) {
                $endpointId = strtolower(trim((string)$candidate));
                if ($endpointId !== '') {
                    break;
                }
            }
        }
        return $endpointId === ''
            || in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true);
    }

    /** @param array<string, mixed> $row */
    private static function storedRowIsOwnOperating(array $row): bool
    {
        $raw = self::decodeArray($row['raw_data'] ?? []);
        $evidence = is_array($raw['row'] ?? null)
            ? array_replace($raw['row'], $raw)
            : $raw;
        $compareType = strtolower(trim((string)(
            $row['compare_type']
            ?? $evidence['compare_type']
            ?? $evidence['compareType']
            ?? ''
        )));
        if (in_array($compareType, [
            'competitor',
            'competitor_avg',
            'competition',
            'peer',
            'peer_avg',
            'peer_rank',
            'compete',
            'rival',
        ], true)) {
            return false;
        }
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        foreach (['competitor', 'competition_circle', 'peer_hotel', 'peer_rank'] as $fragment) {
            if ($dimension !== '' && str_contains($dimension, $fragment)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
