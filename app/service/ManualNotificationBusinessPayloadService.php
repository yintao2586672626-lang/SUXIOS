<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Converts verified business-preview facts into a bounded notification payload.
 *
 * Dingdandao PMS facts, Ctrip OTA facts and Meituan OTA facts remain separate.
 * A missing OTA source is reported in the message and never replaced by PMS or
 * another OTA source. The three business templates require a verified
 * Dingdandao readback because their operational room/revenue scope is PMS-led.
 */
final class ManualNotificationBusinessPayloadService
{
    public const CONTRACT_VERSION = 'manual_notification_business_payload.v2';
    public const FACT_ENVELOPE_VERSION = 'revenue_message_fact_envelope.v2';
    public const RENDER_CONTRACT_VERSIONS = [
        'today_revenue_management' =>
            'manual_business.today_revenue_management.v2',
        'future_room_status' =>
            'manual_business.future_room_status.v2',
        'daily_review' =>
            'manual_business.daily_review.v2',
    ];

    private const TYPES = [
        'today_revenue_management',
        'future_room_status',
        'daily_review',
    ];

    /** @var callable|null */
    private $sectionLoader;

    public function __construct(?callable $sectionLoader = null)
    {
        $this->sectionLoader = $sectionLoader;
    }

    /** @return array<string,mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $templateType
    ): array {
        return $this->build(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $templateType,
            'page_preview'
        );
    }

    /** @return array<string,mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $templateType,
        string $deliveryMode
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('business_message_scope_invalid');
        }
        $businessDate = self::normalizeDate($businessDate);
        $templateType = trim($templateType);
        if (!in_array($templateType, self::TYPES, true)) {
            throw new InvalidArgumentException('business_message_type_invalid');
        }

        try {
            $preview = $this->loadSection($templateType, $hotelId, $businessDate);
        } catch (\Throwable $error) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $templateType,
                'business_message_preview_unavailable',
                '业务事实预览读取失败；消息未生成。',
                [
                    'source_error' => self::safeText($error->getMessage(), 160),
                ]
            );
        }

        $hotel = is_array($preview['hotel'] ?? null) ? $preview['hotel'] : [];
        $section = is_array($preview['section'] ?? null) ? $preview['section'] : [];
        if ((int)($hotel['id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (string)($preview['business_date'] ?? '') !== $businessDate
            || (string)($section['key'] ?? '') !== $templateType
        ) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $templateType,
                'business_message_identity_mismatch',
                '业务事实的租户、酒店、日期或消息类型不匹配；消息未生成。',
                $preview
            );
        }

        $messageData = is_array($section['message_data'] ?? null)
            ? $section['message_data']
            : [];
        $blocker = $this->messageDataBlocker($templateType, $messageData);
        $envelope = $this->factEnvelope(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $templateType,
            $section,
            $messageData,
            $blocker === null ? 'ready' : 'blocked'
        );
        if ($blocker !== null) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $templateType,
                $blocker['code'],
                $blocker['message'],
                $preview,
                $envelope
            );
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => $this->renderContent(
                    $templateType,
                    $hotelName,
                    $businessDate,
                    $deliveryMode,
                    $section,
                    $messageData
                ),
            ],
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'render_contract_version' =>
                self::RENDER_CONTRACT_VERSIONS[$templateType],
            'status' => 'ready',
            'reason_code' => 'business_message_ready',
            'business_date' => $businessDate,
            'template_type' => $templateType,
            'payload_fingerprint' => hash('sha256', self::json($payload)),
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => [
                'allowed' => true,
                'status' => 'formal_send_allowed',
                'blockers' => [],
            ],
            'payload' => $payload,
            'business_preview' => $preview,
            'fact_envelope' => $envelope,
        ];
    }

    /** @return array<string,mixed> */
    private function loadSection(
        string $templateType,
        int $hotelId,
        string $businessDate
    ): array {
        $value = $this->sectionLoader === null
            ? (new ManualNotificationBusinessPreviewService())->section(
                $templateType,
                $hotelId,
                $businessDate
            )
            : call_user_func(
                $this->sectionLoader,
                $templateType,
                $hotelId,
                $businessDate
            );
        if (!is_array($value)) {
            throw new \RuntimeException('business_message_preview_invalid');
        }
        return $value;
    }

    /** @return array{code:string,message:string}|null */
    private function messageDataBlocker(
        string $templateType,
        array $messageData
    ): ?array {
        $pmsSelection = $templateType === 'future_room_status'
            ? null
            : (new RevenuePmsFactSelectorService())->select($messageData);
        $pms = $pmsSelection === null
            ? $messageData
            : (array)$pmsSelection['source'];
        if ($templateType === 'future_room_status') {
            $source = is_array($messageData['source'] ?? null)
                ? $messageData['source']
                : [];
            if ((string)($source['provider'] ?? '') !== 'dingdandao_pms'
                || (string)($source['table'] ?? '')
                    !== 'dingdandao_operating_target_captures'
            ) {
                return [
                    'code' => 'business_message_forward_provider_unsupported',
                    'message' => '远期房态仅接受已验真的订单来了来源；当前 PMS 来源不匹配。',
                ];
            }
        }
        $pmsStatus = $pmsSelection === null
            ? (string)($pms['data_status'] ?? 'missing')
            : (string)$pmsSelection['data_status'];
        if ($pmsStatus !== 'readback_verified') {
            $pmsSourceKey = $pmsSelection === null
                ? 'dingdandao_pms'
                : (string)$pmsSelection['source_key'];
            return [
                'code' => 'business_message_' . $pmsSourceKey
                    . '_not_verified',
                'message' => ($pmsSelection['label'] ?? '订单来了 PMS')
                    . '同酒店、同日期事实尚未通过保存回读门禁。',
            ];
        }

        if ($templateType === 'future_room_status') {
            $sourceDayCount = (int)($messageData['source_day_count'] ?? 0);
            $sourceCoverageStatus = (string)(
                $messageData['source_coverage_status'] ?? ''
            );
            $sourceGapCodes = array_values((array)(
                $messageData['source_gap_codes'] ?? []
            ));
            if (($messageData['contract_version'] ?? '')
                    !== 'dingdandao_forward_message_facts.v1'
                || (array)($messageData['display_horizons'] ?? [])
                    !== [3, 7, 14, 21]
                || count((array)($messageData['horizons'] ?? [])) !== 4
                || count((array)($messageData['daily_rows'] ?? [])) !== 21
                || (int)($messageData['display_day_count'] ?? 0) !== 21
                || $sourceDayCount < 22
                || $sourceDayCount > 31
                || !(
                    $sourceCoverageStatus === 'complete'
                    && $sourceDayCount === 31
                    && $sourceGapCodes === []
                )
                && !(
                    $sourceCoverageStatus === 'partial'
                    && $sourceDayCount < 31
                    && $sourceGapCodes === [
                        'dingdandao_forward_trailing_coverage_partial',
                    ]
                )
            ) {
                return [
                    'code' => 'business_message_forward_contract_incomplete',
                    'message' => '订单来了远期3/7/14/21天消息事实不完整。',
                ];
            }
            return null;
        }

        $facts = is_array($pmsSelection['facts'] ?? null)
            ? $pmsSelection['facts']
            : [];
        $facts['room_fee'] ??= $facts['room_revenue'] ?? null;
        foreach ([
            'room_fee',
            'sold_room_nights',
            'sellable_room_nights',
            'remaining_sellable_room_nights',
            'occupancy_rate_percent',
            'adr',
            'revpar',
        ] as $key) {
            if (self::numeric($facts[$key] ?? null) === null) {
                return [
                    'code' => 'business_message_today_fact_incomplete',
                    'message' => (string)$pmsSelection['label']
                        . '当天住宿消息字段不完整。',
                ];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function factEnvelope(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $templateType,
        array $section,
        array $messageData,
        string $status
    ): array {
        $sources = is_array($messageData['sources'] ?? null)
            ? $messageData['sources']
            : [];
        if ($templateType === 'future_room_status') {
            $actualProvider = (string)(
                $messageData['source']['provider'] ?? 'pms'
            );
            if ($actualProvider !== 'dingdandao_pms') {
                unset($sources['dingdandao_pms']);
                $actualProvider = $actualProvider === 'meituan_cloud_pms'
                    ? $actualProvider
                    : 'pms';
                $sources[$actualProvider] = [
                    'data_status' => (string)(
                        $messageData['data_status'] ?? 'missing'
                    ),
                    'business_scope' => 'whole_hotel_forward_room_status',
                    'source' => $messageData['source'] ?? null,
                ];
            }
        }
        if ($templateType === 'future_room_status'
            && (string)($messageData['source']['provider'] ?? '')
                === 'dingdandao_pms'
            && !isset($sources['dingdandao_pms'])
        ) {
            $sources['dingdandao_pms'] = [
                'data_status' => (string)($messageData['data_status'] ?? 'missing'),
                'business_scope' => 'whole_hotel_forward_room_status',
                'source' => $messageData['source'] ?? null,
            ];
        }
        $pmsSelection = (new RevenuePmsFactSelectorService())->select(
            array_replace($messageData, ['sources' => $sources])
        );
        $pmsSourceKey = (string)$pmsSelection['source_key'];
        if ($templateType !== 'future_room_status') {
            foreach (['dingdandao_pms', 'meituan_cloud_pms'] as $candidate) {
                if ($candidate !== $pmsSourceKey) {
                    unset($sources[$candidate]);
                }
            }
        }
        $pmsSource = is_array($pmsSelection['source'] ?? null)
            ? $pmsSelection['source']
            : [];
        $pmsFacts = $templateType === 'future_room_status'
            ? [
                'display_horizons' => $messageData['display_horizons'] ?? [],
                'source_day_count' => $messageData['source_day_count'] ?? 0,
                'display_day_count' => $messageData['display_day_count'] ?? 0,
                'source_coverage_status' =>
                    $messageData['source_coverage_status'] ?? 'missing',
                'source_gap_codes' => $messageData['source_gap_codes'] ?? [],
                'quality_status' => $messageData['quality_status'] ?? 'missing',
                'horizons' => $messageData['horizons'] ?? [],
                'daily_rows' => $messageData['daily_rows'] ?? [],
                'room_types' => $messageData['room_types'] ?? [],
                'alerts' => $messageData['alerts'] ?? [],
            ]
            : (
                is_array($pmsSelection['facts'] ?? null)
                    ? [
                        ...$pmsSelection['facts'],
                        'revenue_overview' =>
                            $pmsSource['revenue_overview'] ?? null,
                        'temporal_context' =>
                            $pmsSource['temporal_context'] ?? null,
                        'snapshot_delta' =>
                            $pmsSource['snapshot_delta'] ?? null,
                        'alerts' => $pmsSource['alerts'] ?? [],
                    ]
                    : []
            );
        $sourceCompleteness = [];
        $incompleteSources = [];
        foreach ([$pmsSourceKey, 'ctrip_ota', 'meituan_ota'] as $sourceKey) {
            $sourceStatus = $sourceKey === $pmsSourceKey
                ? (string)$pmsSelection['data_status']
                : (string)($sources[$sourceKey]['data_status'] ?? 'missing');
            $sourceCompleteness[$sourceKey] = $sourceStatus;
            if ($sourceStatus !== 'readback_verified') {
                $incompleteSources[] = $sourceKey;
            }
        }
        $factCompleteness = $sourceCompleteness[$pmsSourceKey]
            !== 'readback_verified' || $status !== 'ready'
            ? 'blocked'
            : (
                $incompleteSources === []
                && (array)($section['gaps'] ?? []) === []
                    ? 'complete'
                    : 'partial'
            );
        $allowedUses = $status === 'ready'
            ? [
                'notification_payload',
                'source_status_monitoring',
                $factCompleteness === 'complete'
                    ? 'revenue_agent_input_three_source_complete'
                    : 'revenue_agent_input_partial_with_explicit_gaps',
            ]
            : ['source_status_monitoring'];
        return [
            'contract_version' => self::FACT_ENVELOPE_VERSION,
            'status' => $status,
            'message_delivery_status' => $status,
            'fact_completeness_status' => $factCompleteness,
            'all_three_sources_readback_verified' => $incompleteSources === [],
            'source_completeness' => $sourceCompleteness,
            'incomplete_sources' => $incompleteSources,
            'message_type' => $templateType,
            'pms_binding' => $pmsSelection['binding'],
            'hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'name' => self::safeText($hotelName, 120),
            ],
            'business_date' => $businessDate,
            'date_role' => $templateType === 'future_room_status'
                ? 'capture_as_of_date_with_future_stay_dates'
                : 'operating_business_date',
            'date_alignment' => is_array($messageData['date_alignment'] ?? null)
                ? $messageData['date_alignment']
                : [],
            'sources' => $sources,
            'facts' => [$pmsSourceKey => $pmsFacts],
            'gaps' => array_values(array_map(
                static fn(array $gap): array => [
                    'code' => self::safeText((string)($gap['code'] ?? ''), 100),
                    'status' => self::safeText((string)($gap['status'] ?? ''), 40),
                    'message' => self::safeText((string)($gap['message'] ?? ''), 180),
                ],
                array_values(array_filter(
                    (array)($section['gaps'] ?? []),
                    'is_array'
                ))
            )),
            'aggregation_policy' => is_array($messageData['aggregation_policy'] ?? null)
                ? $messageData['aggregation_policy']
                : [
                    'pms_plus_ota_revenue_addition_allowed' => false,
                    'missing_source_value' => null,
                ],
            'allowed_uses' => $allowedUses,
        ];
    }

    private function renderContent(
        string $templateType,
        string $hotelName,
        string $businessDate,
        string $deliveryMode,
        array $section,
        array $messageData
    ): string {
        return match ($templateType) {
            'future_room_status' => $this->renderFuture(
                $hotelName,
                $businessDate,
                $deliveryMode,
                $section,
                $messageData
            ),
            'daily_review' => $this->renderTodayOrReview(
                true,
                $hotelName,
                $businessDate,
                $deliveryMode,
                $section,
                $messageData
            ),
            default => $this->renderTodayOrReview(
                false,
                $hotelName,
                $businessDate,
                $deliveryMode,
                $section,
                $messageData
            ),
        };
    }

    private function renderTodayOrReview(
        bool $review,
        string $hotelName,
        string $businessDate,
        string $deliveryMode,
        array $section,
        array $messageData
    ): string {
        $sources = (array)($messageData['sources'] ?? []);
        $pmsSelection = (new RevenuePmsFactSelectorService())
            ->select($messageData);
        $pms = (array)$pmsSelection['source'];
        $facts = (array)$pmsSelection['facts'];
        $pmsLabel = $this->pmsDisplayLabel(
            (string)$pmsSelection['source_key']
        );
        $estimatedRoomRevenue = (string)($pms['business_scope'] ?? '')
            === 'estimated_accommodation_room_fee';
        $revenueOverview = is_array($pms['revenue_overview'] ?? null)
            ? $pms['revenue_overview']
            : (
                is_array($facts['revenue_overview'] ?? null)
                    ? $facts['revenue_overview']
                    : []
            );
        $temporalContext = is_array($pms['temporal_context'] ?? null)
            ? $pms['temporal_context']
            : (
                is_array($facts['temporal_context'] ?? null)
                    ? $facts['temporal_context']
                    : []
            );
        $snapshotDelta = is_array($pms['snapshot_delta'] ?? null)
            ? $pms['snapshot_delta']
            : (
                is_array($facts['snapshot_delta'] ?? null)
                    ? $facts['snapshot_delta']
                    : []
            );
        $alerts = array_values(array_filter(
            (array)($pms['alerts'] ?? $facts['alerts'] ?? []),
            'is_array'
        ));
        $capturedAt = self::safeText(
            (string)($pms['source']['captured_at'] ?? ''),
            32
        );
        $lines = [
            '# 宿析OS｜' . self::safeText($hotelName, 80)
                . '｜' . ($review ? '今日复盘' : '今日收益管理'),
            '> 当前模式：' . self::modeLabel($deliveryMode),
            '> 经营日期：' . $businessDate,
            '> 统计时间：' . ($capturedAt !== '' ? $capturedAt : '未取得'),
            '> 口径：' . $pmsLabel
                . '为住宿客房事实；携程、美团为OTA渠道，三源不相加。',
            '',
            '**' . $pmsLabel . '住宿经营**',
            ($estimatedRoomRevenue ? '预计房费' : '房费') . '｜¥'
                . self::number(
                    $facts['room_fee'] ?? $facts['room_revenue'] ?? null,
                    2
                ),
            '已售/可售｜' . self::number($facts['sold_room_nights'] ?? null, 0)
                . '/' . self::number($facts['sellable_room_nights'] ?? null, 0)
                . '间夜',
            '剩余可售｜' . self::number(
                $facts['remaining_sellable_room_nights'] ?? null,
                0
            ) . '间夜',
            'OCC｜' . self::number($facts['occupancy_rate_percent'] ?? null, 2)
                . '%',
            'ADR｜¥' . self::number($facts['adr'] ?? null, 2),
            'RevPAR｜¥' . self::number($facts['revpar'] ?? null, 2),
            '',
        ];
        $lines[] = '**住宿营业额汇总**';
        if (($revenueOverview['data_status'] ?? '') === 'readback_verified') {
            $lines[] = '住宿总营业额｜'
                . self::money($revenueOverview['total_accommodation_turnover'] ?? null);
            $subjects = array_values(array_filter(
                (array)($revenueOverview['subjects'] ?? []),
                static fn(mixed $subject): bool =>
                    is_array($subject)
                    && (int)($subject['provider_subject_type'] ?? -1) !== -1
            ));
            foreach (array_slice($subjects, 0, 6) as $subject) {
                $line = self::safeText(
                    (string)($subject['subject_name'] ?? '未命名项目'),
                    32
                ) . '｜' . self::money($subject['single_day_total'] ?? null);
                if (self::numeric($subject['percent'] ?? null) !== null) {
                    $line .= '｜' . self::number($subject['percent'], 2) . '%';
                }
                $lines[] = $line;
            }
            if (count($subjects) > 6) {
                $lines[] = '其余' . (count($subjects) - 6)
                    . '项｜已保存在事实明细';
            }
            $trendParts = [];
            foreach (array_slice(
                (array)($revenueOverview['total_trend'] ?? []),
                -3
            ) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $date = self::safeText(
                    (string)($point['observation_date'] ?? ''),
                    10
                );
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                    || self::numeric($point['amount'] ?? null) === null
                ) {
                    continue;
                }
                $trendParts[] = substr($date, 5) . ' '
                    . self::money($point['amount']);
            }
            if ($trendParts !== []) {
                $lines[] = '营业额趋势｜' . implode(' → ', $trendParts);
            }
        } else {
            $lines[] = '状态｜未取得或未通过回读（不补0）';
        }
        $lines[] = '';
        $lines[] = '**过去 / 当前 / 未来口径**';
        if (($temporalContext['data_status'] ?? '') !== 'missing') {
            $past = is_array($temporalContext['past'] ?? null)
                ? $temporalContext['past']
                : [];
            $current = is_array($temporalContext['current'] ?? null)
                ? $temporalContext['current']
                : [];
            $future = is_array($temporalContext['future'] ?? null)
                ? $temporalContext['future']
                : [];
            $lines[] = '过去｜'
                . self::dateRange($past['date_from'] ?? null, $past['date_to'] ?? null)
                . '｜' . self::temporalStatusLabel($past['status'] ?? null);
            $currentDate = self::safeText(
                (string)($current['business_date'] ?? ''),
                10
            );
            $lines[] = '当前｜'
                . (
                    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $currentDate) === 1
                        ? $currentDate
                        : '日期未取得'
                )
                . '｜' . self::settlementLabel($current['settlement_status'] ?? null)
                . '｜' . self::temporalStatusLabel($current['status'] ?? null);
            $lines[] = '未来｜'
                . self::dateRange(
                    $future['stay_date_from'] ?? null,
                    $future['stay_date_to'] ?? null
                )
                . '｜3/7/14/21天｜'
                . self::temporalStatusLabel($future['status'] ?? null);
        } else {
            $lines[] = '状态｜时间口径未取得，不混用其他快照';
        }
        $lines[] = '';
        $lines[] = '**本次变化**';
        if (($snapshotDelta['data_status'] ?? '') === 'comparable') {
            $deltas = is_array($snapshotDelta['deltas'] ?? null)
                ? $snapshotDelta['deltas']
                : [];
            $lines[] = '时段｜'
                . self::timeLabel($snapshotDelta['captured_from'] ?? null)
                . ' → ' . self::timeLabel($snapshotDelta['captured_to'] ?? null);
            $lines[] = '房费｜' . self::signedMetric($deltas['room_fee'] ?? null, 2, '¥')
                . '｜已售 ' . self::signedMetric(
                    $deltas['sold_room_nights'] ?? null,
                    0,
                    '',
                    '间'
                );
            $lines[] = 'OCC｜'
                . self::signedMetric($deltas['occupancy_rate_percent'] ?? null, 2, '', '点')
                . '｜ADR ' . self::signedMetric($deltas['adr'] ?? null, 2, '¥');
        } else {
            $lines[] = '状态｜等待下一次同门店、同日期的独立快照';
        }
        if ($alerts !== []) {
            $lines[] = '';
            $lines[] = '**经营提醒**';
            foreach (array_slice($alerts, 0, 3) as $alert) {
                if (($alert['code'] ?? '') === 'pms_today_sold_out') {
                    $lines[] = '⚠ 今日可售已归零｜已售'
                        . self::number($alert['sold_room_nights'] ?? null, 0)
                        . '/' . self::number($alert['sellable_room_nights'] ?? null, 0)
                        . '间｜OCC '
                        . self::number($alert['occupancy_rate_percent'] ?? null, 2)
                        . '%';
                }
            }
        }
        $lines[] = '';
        $lines[] = '**三源状态**';
        array_push($lines, ...$this->sourceStatusLines($messageData));
        if ($review) {
            $lines[] = '';
            $lines[] = '> 当前为最近一次已保存快照，并非日终最终定稿标记。';
        }
        $gapLines = $this->gapLines($section);
        if ($gapLines !== []) {
            $lines[] = '';
            $lines[] = '**数据缺口**';
            array_push($lines, ...$gapLines);
        }
        $lines[] = '';
        $lines[] = '> 缺失来源保持空缺；未使用0、旧日或其他酒店数据补齐。';
        return implode("\n", $lines);
    }

    private function renderFuture(
        string $hotelName,
        string $businessDate,
        string $deliveryMode,
        array $section,
        array $messageData
    ): string {
        $lines = [
            '# 宿析OS｜' . self::safeText($hotelName, 80) . '｜远期房态',
            '> 当前模式：' . self::modeLabel($deliveryMode),
            '> 快照日期：' . $businessDate,
            '> 口径：3/7/14/21天为从次日起计算的累计窗口，彼此包含、不可相加。',
            '',
            '**累计窗口**',
        ];
        foreach ((array)($messageData['horizons'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = self::number($row['horizon_days'] ?? null, 0) . '天｜'
                . self::number($row['booked_room_nights'] ?? null, 0) . '间夜已订｜'
                . self::number($row['remaining_sellable_room_nights'] ?? null, 0)
                . '间夜剩余｜OCC '
                . self::number($row['occupancy_rate_percent'] ?? null, 2)
                . '%｜ADR ¥' . self::number($row['adr'] ?? null, 2);
        }
        $lines[] = '';
        $lines[] = '逐日/房型明细｜已保存'
            . self::number($messageData['display_day_count'] ?? null, 0)
            . '天；日常消息仅展示累计窗口，异常时展开。';
        $alerts = array_values(array_filter(
            (array)($messageData['alerts'] ?? []),
            'is_array'
        ));
        if ($alerts !== []) {
            $oversold = array_values(array_filter(
                $alerts,
                static fn(array $alert): bool =>
                    ($alert['code'] ?? '') === 'pms_forward_oversold'
            ));
            $soldOut = array_values(array_filter(
                $alerts,
                static fn(array $alert): bool =>
                    ($alert['code'] ?? '') === 'pms_forward_sold_out'
            ));
            $lines[] = '';
            $lines[] = '**房态提醒**';
            if ($oversold !== []) {
                $lines[] = '超售摘要｜'
                    . count(array_unique(array_column($oversold, 'stay_date')))
                    . '天｜'
                    . count(array_unique(array_column($oversold, 'room_type_name')))
                    . '个房型｜合计'
                    . array_sum(array_column($oversold, 'oversold_rooms'))
                    . '间';
            }
            if ($soldOut !== []) {
                $lines[] = '售罄摘要｜'
                    . count(array_unique(array_column($soldOut, 'stay_date')))
                    . '天可售归零';
            }
            foreach (array_slice($alerts, 0, 3) as $alert) {
                $date = self::safeText((string)($alert['stay_date'] ?? ''), 10);
                if (($alert['code'] ?? '') === 'pms_forward_oversold') {
                    $lines[] = substr($date, 5) . '｜'
                        . self::safeText(
                            (string)($alert['room_type_name'] ?? '未知房型'),
                            40
                        )
                        . '｜超售'
                        . self::number($alert['oversold_rooms'] ?? null, 0)
                        . '间';
                } elseif (($alert['code'] ?? '') === 'pms_forward_sold_out') {
                    $lines[] = substr($date, 5)
                        . '｜可售归零｜已订'
                        . self::number($alert['booked_rooms'] ?? null, 0)
                        . '间';
                }
            }
            if (count($alerts) > 3) {
                $lines[] = '其余' . (count($alerts) - 3)
                    . '条｜已保存在事实明细';
            }
        }
        if (($messageData['source_coverage_status'] ?? '') === 'partial') {
            $lines[] = '';
            $lines[] = '> 第22天以后源数据覆盖不完整；本消息3/7/14/21天窗口仍已完整核验。';
        }
        $sources = (array)($messageData['sources'] ?? []);
        if ($sources !== []) {
            $lines[] = '';
            $lines[] = '**三源状态**';
            array_push($lines, ...$this->sourceStatusLines($messageData));
        }
        $gapLines = $this->gapLines($section);
        if ($gapLines !== []) {
            $lines[] = '';
            $lines[] = '**数据缺口**';
            array_push($lines, ...$gapLines);
        }
        $lines[] = '';
        $lines[] = '> 房型日明细已保留在消息事实信封中；本消息未把OTA预测当作全酒店房态。';
        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function sourceStatusLines(array $messageData): array
    {
        $sources = is_array($messageData['sources'] ?? null)
            ? $messageData['sources']
            : [];
        $pmsSelection = (new RevenuePmsFactSelectorService())
            ->select($messageData);
        $labels = [
            (string)$pmsSelection['source_key'] => $this->pmsDisplayLabel(
                (string)$pmsSelection['source_key']
            ),
            'ctrip_ota' => '携程',
            'meituan_ota' => '美团',
        ];
        $lines = [];
        foreach ($labels as $key => $label) {
            $source = is_array($sources[$key] ?? null) ? $sources[$key] : [];
            $status = (string)($source['data_status'] ?? 'missing');
            $lines[] = $label . '｜' . self::statusLabel($status);
        }
        return $lines;
    }

    private function pmsDisplayLabel(string $sourceKey): string
    {
        return match ($sourceKey) {
            'dingdandao_pms' => '订单来了',
            'meituan_cloud_pms' => '美团云 PMS',
            default => 'PMS',
        };
    }

    /** @return list<string> */
    private function gapLines(array $section): array
    {
        $lines = [];
        foreach ((array)($section['gaps'] ?? []) as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $message = self::safeText((string)($gap['message'] ?? ''), 160);
            if ($message !== '') {
                $lines[] = '· ' . $message;
            }
            if (count($lines) >= 3) {
                break;
            }
        }
        return $lines;
    }

    /** @return array<string,mixed> */
    private function blocked(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $templateType,
        string $code,
        string $message,
        array $preview = [],
        ?array $envelope = null
    ): array {
        $envelope ??= [
            'contract_version' => self::FACT_ENVELOPE_VERSION,
            'status' => 'blocked',
            'message_delivery_status' => 'blocked',
            'fact_completeness_status' => 'blocked',
            'all_three_sources_readback_verified' => false,
            'source_completeness' => [
                'pms' => 'missing',
                'ctrip_ota' => 'missing',
                'meituan_ota' => 'missing',
            ],
            'incomplete_sources' => [
                'pms',
                'ctrip_ota',
                'meituan_ota',
            ],
            'message_type' => $templateType,
            'hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'name' => self::safeText($hotelName, 120),
            ],
            'business_date' => $businessDate,
            'sources' => [],
            'facts' => [],
            'gaps' => [['code' => $code, 'status' => 'blocked', 'message' => $message]],
            'aggregation_policy' => [
                'pms_plus_ota_revenue_addition_allowed' => false,
                'missing_source_value' => null,
            ],
            'allowed_uses' => ['source_status_monitoring'],
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'render_contract_version' =>
                self::RENDER_CONTRACT_VERSIONS[$templateType],
            'status' => 'blocked',
            'reason_code' => $code,
            'business_date' => $businessDate,
            'template_type' => $templateType,
            'payload_fingerprint' => hash(
                'sha256',
                $hotelId . '|' . $businessDate . '|' . $templateType . '|' . $code
            ),
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => [
                'allowed' => false,
                'status' => 'formal_send_blocked',
                'blockers' => [['code' => $code, 'message' => $message]],
            ],
            'payload' => null,
            'business_preview' => $preview,
            'fact_envelope' => $envelope,
        ];
    }

    private static function modeLabel(string $deliveryMode): string
    {
        return match (trim($deliveryMode)) {
            'immediate_test' => '明确点击的测试推送',
            'scheduled_test' => '企业微信测试群定时真实投递',
            'page_preview' => '页面实时预览（未发送）',
            default => '后端消息预览（未发送）',
        };
    }

    private static function money(mixed $value): string
    {
        $number = self::numeric($value);
        if ($number === null) {
            return '未取得';
        }
        return ($number < 0 ? '-¥' : '¥')
            . number_format(abs((float)$number), 2, '.', '');
    }

    private static function dateRange(mixed $from, mixed $to): string
    {
        $from = self::safeText((string)$from, 10);
        $to = self::safeText((string)$to, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1
        ) {
            return '日期未取得';
        }
        return substr($from, 5) . '至' . substr($to, 5);
    }

    private static function temporalStatusLabel(mixed $status): string
    {
        return match (trim((string)$status)) {
            'verified' => '已验证',
            'verified_with_anomalies' => '已验证（有提醒）',
            'partial' => '部分可用',
            'not_requested' => '本次未采集',
            default => '未取得',
        };
    }

    private static function settlementLabel(mixed $status): string
    {
        return match (trim((string)$status)) {
            'provisional' => '实时未结算',
            'historical_observed' => '历史已观测',
            default => '结算状态未取得',
        };
    }

    private static function timeLabel(mixed $value): string
    {
        $text = self::safeText((string)$value, 32);
        if (preg_match('/(?:^|T| )(\d{2}:\d{2})(?::\d{2})?/', $text, $matches) !== 1) {
            return '时间未取得';
        }
        return $matches[1];
    }

    private static function signedMetric(
        mixed $value,
        int $decimals,
        string $prefix = '',
        string $suffix = ''
    ): string {
        $number = self::numeric($value);
        if ($number === null) {
            return '未取得';
        }
        $sign = $number > 0 ? '+' : ($number < 0 ? '-' : '±');
        return $sign . $prefix
            . number_format(abs((float)$number), $decimals, '.', '')
            . $suffix;
    }

    private static function statusLabel(string $status): string
    {
        return match (trim($status)) {
            'readback_verified' => '已保存并回读',
            'collecting' => '采集中',
            'pending_readback' => '等待保存回读',
            'collection_failed' => '采集失败',
            'partial', 'partial_readback_verified' => '部分可用',
            'blocked' => '数据门禁阻断',
            default => '未取得',
        };
    }

    private static function number(mixed $value, int $decimals): string
    {
        $number = self::numeric($value);
        if ($number === null) {
            return '未取得';
        }
        return number_format((float)$number, $decimals, '.', '');
    }

    private static function numeric(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return is_int($value) ? $value : (float)$value;
    }

    private static function normalizeDate(string $date): string
    {
        $date = trim($date);
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('business_message_date_invalid');
        }
        return $date;
    }

    private static function safeText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
