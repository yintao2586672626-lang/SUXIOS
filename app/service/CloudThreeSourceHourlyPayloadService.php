<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Builds one same-hotel, same-business-date hourly WeCom payload from the
 * latest cloud captures that have already been formally saved and read back.
 * Collection runs before the hour; this service never opens a browser or
 * reuses old/default values while the message is being dispatched.
 */
final class CloudThreeSourceHourlyPayloadService
{
    public const RENDER_CONTRACT_VERSION = 'cloud_three_source_hourly.v1';
    public const MAX_SOURCE_AGE_SECONDS = 2700;

    private const TIMEZONE = 'Asia/Shanghai';
    private const TRUSTED_PROFILE_METHODS = ['browser_profile', 'profile_browser'];

    /** @return array<string, mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        int $actorId,
        ?DateTimeImmutable $observedAt = null
    ): array {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $now = ($observedAt ?? new DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone);
        $midnightCloseout = $now->format('H') === '00'
            && $businessDate === $now->modify('-1 day')->format('Y-m-d');
        if ($tenantId <= 0
            || $hotelId <= 0
            || $actorId <= 0
            || trim($hotelName) === ''
            || ($businessDate !== $now->format('Y-m-d') && !$midnightCloseout)
        ) {
            return $this->blocked(
                'cloud_three_source_scope_or_date_invalid',
                $businessDate,
                '三源整点推送仅使用当前酒店当天数据；00:00 仅允许前一天的最新收口回读。'
            );
        }

        try {
            $plan = $this->activeCollectionPlan($tenantId, $hotelId, $actorId);
            if (!is_array($plan)) {
                return $this->blocked(
                    'cloud_three_source_active_plan_actor_mismatch',
                    $businessDate,
                    'The active three-source collection plan does not belong to the resolved execution actor.'
                );
            }
            $sourcePlan = $this->decodeArray($plan['source_plan_json'] ?? null);
            $ctripSourceId = (int)($sourcePlan['ctrip']['data_source_id'] ?? 0);
            $meituanSourceId = (int)($sourcePlan['meituan']['data_source_id'] ?? 0);
            if ($ctripSourceId <= 0 || $meituanSourceId <= 0) {
                return $this->blocked(
                    'cloud_three_source_active_plan_sources_missing',
                    $businessDate,
                    'The active collection plan has no exact Ctrip and Meituan source identities.'
                );
            }

            $pms = (new DingdandaoOperatingTargetCaptureService())->latestForActor(
                $tenantId,
                $hotelId,
                $actorId,
                $businessDate,
                $now
            );
            $pmsReady = $this->verifiedPms(
                $pms,
                $hotelId,
                $actorId,
                $businessDate,
                $now
            );
            if (($pmsReady['allowed'] ?? false) !== true) {
                return $this->blocked(
                    (string)($pmsReady['reason_code']
                        ?? 'dingdandao_recent_readback_not_verified'),
                    $businessDate,
                    '订单来了未取得发送前 45 分钟内的同店同日保存回读，本轮不发送。'
                );
            }

            $ctripSource = $this->profileSource(
                $tenantId,
                $hotelId,
                $actorId,
                $ctripSourceId,
                'ctrip'
            );
            if (!is_array($ctripSource)) {
                return $this->blocked(
                    'ctrip_profile_source_binding_missing',
                    $businessDate,
                    '携程云端 Profile 数据源未绑定到当前酒店。'
                );
            }
            $meituanSource = $this->profileSource(
                $tenantId,
                $hotelId,
                $actorId,
                $meituanSourceId,
                'meituan'
            );
            if (!is_array($meituanSource)) {
                return $this->blocked(
                    'meituan_profile_source_binding_missing',
                    $businessDate,
                    '美团云端 Profile 数据源未绑定到当前酒店。'
                );
            }

            $ctripTaskResult = $this->recentTask(
                $tenantId,
                $hotelId,
                $actorId,
                (int)$ctripSource['id'],
                'ctrip',
                $now
            );
            $ctripTask = $ctripTaskResult['task'] ?? null;
            if (($ctripTaskResult['allowed'] ?? false) !== true || !is_array($ctripTask)) {
                return $this->blocked(
                    (string)($ctripTaskResult['reason_code']
                        ?? 'ctrip_recent_readback_task_missing'),
                    $businessDate,
                    '携程未取得发送前 45 分钟内已完成的云端采集任务。'
                );
            }
            $meituanTaskResult = $this->recentTask(
                $tenantId,
                $hotelId,
                $actorId,
                (int)$meituanSource['id'],
                'meituan',
                $now
            );
            $meituanTask = $meituanTaskResult['task'] ?? null;
            if (($meituanTaskResult['allowed'] ?? false) !== true || !is_array($meituanTask)) {
                return $this->blocked(
                    (string)($meituanTaskResult['reason_code']
                        ?? 'meituan_recent_readback_task_missing'),
                    $businessDate,
                    '美团未取得发送前 45 分钟内已完成的云端采集任务。'
                );
            }

            $ctripRows = $this->taskRows(
                $tenantId,
                $hotelId,
                (int)$ctripSource['id'],
                (int)$ctripTask['id'],
                'ctrip',
                $businessDate,
                $now
            );
            if ($ctripRows === []) {
                return $this->blocked(
                    'ctrip_formal_readback_rows_missing_or_stale',
                    $businessDate,
                    '携程当次任务没有可追溯的正式保存回读行。'
                );
            }
            $meituanRows = $this->taskRows(
                $tenantId,
                $hotelId,
                (int)$meituanSource['id'],
                (int)$meituanTask['id'],
                'meituan',
                $businessDate,
                $now
            );
            if ($meituanRows === []) {
                return $this->blocked(
                    'meituan_formal_readback_rows_missing_or_stale',
                    $businessDate,
                    '美团当次任务没有可追溯的正式保存回读行。'
                );
            }

            $ctrip = (new CtripTemporalBroadcastService())->buildFromStoredRows(
                $ctripRows,
                $hotelId,
                trim($hotelName),
                $businessDate,
                'realtime',
                '',
                false,
                $now
            );
            $ctripPresent = is_array($ctrip['segments']['present'] ?? null)
                ? $ctrip['segments']['present']
                : [];
            if (!in_array((string)($ctrip['status'] ?? ''), ['available', 'partial'], true)
                || !in_array(
                    (string)($ctripPresent['status'] ?? ''),
                    ['available', 'partial'],
                    true
                )
                || (int)($ctrip['fact_source']['trusted_row_count'] ?? 0)
                    !== count($ctripRows)
                || !$this->recentTimestamp(
                    (string)($ctripPresent['captured_at'] ?? ''),
                    $now
                )
            ) {
                return $this->blocked(
                    'ctrip_recent_verified_subset_not_ready',
                    $businessDate,
                    '携程最新保存行无法形成同店同日的可信字段子集。'
                );
            }
            $ctripFacts = $this->ctripFacts(
                is_array($ctripPresent['metrics'] ?? null)
                    ? $ctripPresent['metrics']
                    : []
            );
            if ($ctripFacts['facts'] === []) {
                return $this->blocked(
                    'ctrip_recent_present_metrics_missing',
                    $businessDate,
                    '携程最新快照没有可发送的已采集字段。'
                );
            }

            $meituan = (new MeituanTemporalService())->buildSummaryFromRows(
                $meituanRows,
                $hotelId,
                $businessDate,
                $now,
                [
                    'status' => 'ready',
                    'reason_code' => 'cloud_task_saved_and_readback_verified',
                    'data_source_id' => (int)$meituanSource['id'],
                ]
            );
            $meituanToday = is_array($meituan['today'] ?? null)
                ? $meituan['today']
                : [];
            if (!in_array(
                (string)($meituanToday['status'] ?? ''),
                ['ready', 'partial'],
                true
            ) || !$this->recentTimestamp(
                (string)($meituanToday['captured_at'] ?? ''),
                $now
            )) {
                return $this->blocked(
                    'meituan_recent_verified_subset_not_ready',
                    $businessDate,
                    '美团最新保存行无法形成同店同日的可信字段子集。'
                );
            }
            $meituanFacts = $this->meituanFacts(
                is_array($meituanToday['metrics'] ?? null)
                    ? $meituanToday['metrics']
                    : []
            );
            if ($meituanFacts['facts'] === []) {
                return $this->blocked(
                    'meituan_recent_present_metrics_missing',
                    $businessDate,
                    '美团最新快照没有可发送的已验证字段。'
                );
            }

            $summary = is_array($pms['summary'] ?? null) ? $pms['summary'] : [];
            $pmsValues = [];
            foreach ([
                'total_room_fee',
                'adr',
                'occupancy_rate_percent',
                'revpar',
                'sold_room_nights',
                'derived_sellable_room_nights',
            ] as $field) {
                if (!is_numeric($summary[$field] ?? null)) {
                    return $this->blocked(
                        'dingdandao_required_metric_missing:' . $field,
                        $businessDate,
                        '订单来了核心经营指标不完整，本轮不以 0 补齐。'
                    );
                }
                $pmsValues[$field] = (float)$summary[$field];
            }

            $lines = [
                '# ' . trim($hotelName) . '｜三源实时经营快报',
                '> 数据日期：' . $businessDate . '｜发送时刻：' . $now->format('H:i:s'),
                '> 口径：订单来了为全店住宿经营事实；携程/美团为各自渠道事实，不能相加。',
                '',
                '**订单来了 PMS**（' . $this->clock((string)$pms['captured_at'])
                    . '，已保存并回读）',
                $this->joinedFacts([
                    '房费 ¥' . $this->money($pmsValues['total_room_fee']),
                    '售出 ' . $this->integer($pmsValues['sold_room_nights'])
                        . '/' . $this->integer($pmsValues['derived_sellable_room_nights'])
                        . ' 间夜',
                ]),
                $this->joinedFacts([
                    'ADR ¥' . $this->money($pmsValues['adr']),
                    '入住率 ' . number_format($pmsValues['occupancy_rate_percent'], 2) . '%',
                    'RevPAR ¥' . $this->money($pmsValues['revpar']),
                ]),
                '',
                '**携程**（' . $this->clock((string)$ctripPresent['captured_at'])
                    . '，' . count($ctripRows) . '项已保存回读，字段部分可用）',
                $this->joinedFacts($ctripFacts['facts']),
                '- 缺口：' . ($ctripFacts['missing'] === []
                    ? '本轮展示字段均已返回。'
                    : implode('、', $ctripFacts['missing']) . '未返回，未补 0。'),
                '',
                '**美团**（' . $this->clock((string)$meituanToday['captured_at'])
                    . '，' . count($meituanRows) . '项已保存回读，字段部分可用）',
                $this->joinedFacts($meituanFacts['facts']),
                '- 缺口：' . ($meituanFacts['missing'] === []
                    ? '本轮展示字段均已返回。'
                    : implode('、', $meituanFacts['missing']) . '未返回，未补 0。'),
                '',
                '> 三源均为当前酒店、发送前 45 分钟内的云端新采集；缺失字段未沿用旧数据。',
            ];
            $payload = [
                'msgtype' => 'markdown',
                'markdown' => ['content' => implode("\n", $lines)],
            ];
            $refs = $this->sourceReferences(
                $pms,
                $ctripRows,
                $meituanRows,
                $businessDate
            );

            return [
                'status' => 'ready',
                'reason_code' => 'cloud_three_source_recent_readbacks_ready',
                'business_date' => $businessDate,
                'render_contract_version' => self::RENDER_CONTRACT_VERSION,
                'payload_fingerprint' => hash('sha256', $this->json($payload)),
                'formal_send_gate' => [
                    'allowed' => true,
                    'status' => 'formal_send_allowed',
                    'blockers' => [],
                ],
                'facts' => [
                    'pms_occupancy' => $pmsValues['occupancy_rate_percent'],
                    'pms_sellable_room_nights' =>
                        $pmsValues['derived_sellable_room_nights'],
                    'pms_sold_room_nights' => $pmsValues['sold_room_nights'],
                ],
                'source_snapshot_refs' => $refs,
                'source_snapshot_ids' => [
                    'pms_capture_id' => (int)($pms['id'] ?? 0),
                    'ctrip_sync_task_id' => (int)$ctripTask['id'],
                    'meituan_sync_task_id' => (int)$meituanTask['id'],
                ],
                'operating_target_record_id' => 0,
                'snapshot_revision_no' => 0,
                'payload' => $payload,
            ];
        } catch (\Throwable) {
            return $this->blocked(
                'cloud_three_source_payload_read_failed',
                $businessDate,
                '三源最新回读暂时无法读取，本轮未调用企业微信。'
            );
        }
    }

    /** @param array<string, mixed> $pms @return array{allowed:bool,reason_code:string} */
    private function verifiedPms(
        array $pms,
        int $hotelId,
        int $actorId,
        string $businessDate,
        DateTimeImmutable $now
    ): array {
        $allowed = (int)($pms['id'] ?? 0) > 0
            && (int)($pms['hotel_id'] ?? 0) === $hotelId
            && (int)($pms['captured_by'] ?? 0) === $actorId
            && (string)($pms['business_date'] ?? '') === $businessDate
            && (string)($pms['identity_status'] ?? '') === 'matched'
            && (string)($pms['reconciliation_status'] ?? '') === 'matched'
            && (string)($pms['capture_status'] ?? '') === 'verified'
            && (string)($pms['quality_status'] ?? '') === 'verified'
            && (string)($pms['readback_status'] ?? '') === 'readback_verified'
            && $this->recentTimestamp((string)($pms['captured_at'] ?? ''), $now);
        return [
            'allowed' => $allowed,
            'reason_code' => $allowed
                ? 'dingdandao_recent_readback_verified'
                : 'dingdandao_recent_readback_not_verified',
        ];
    }

    /** @return array<string, mixed>|null */
    private function activeCollectionPlan(
        int $tenantId,
        int $hotelId,
        int $actorId
    ): ?array {
        $rows = Db::name('hotel_collection_plans')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('execution_owner_user_id', $actorId)
            ->where('enabled', 1)
            ->where('active_slot', 1)
            ->where('plan_status', 'active')
            ->where('validation_status', 'ready')
            ->limit(2)
            ->select()
            ->toArray();
        return count($rows) === 1 && is_array($rows[0] ?? null)
            ? $rows[0]
            : null;
    }

    /** @return array<string, mixed>|null */
    private function profileSource(
        int $tenantId,
        int $hotelId,
        int $actorId,
        int $sourceId,
        string $platform
    ): ?array
    {
        $row = Db::name('platform_data_sources')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('user_id', $actorId)
            ->where('platform', $platform)
            ->where('enabled', 1)
            ->find();
        if (!is_array($row)
            || !in_array(
                strtolower(trim((string)($row['ingestion_method'] ?? ''))),
                self::TRUSTED_PROFILE_METHODS,
                true
            )
            || strtolower(trim((string)($row['status'] ?? ''))) === 'disabled'
        ) {
            return null;
        }

        $profilePublicId = $this->sourceProfilePublicId($row);
        if ($profilePublicId === '') {
            return null;
        }
        $profiles = Db::name('cloud_browser_profiles')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('owner_user_id', $actorId)
            ->where('platform', $platform)
            ->where('profile_public_id', $profilePublicId)
            ->limit(2)
            ->select()
            ->toArray();
        if (count($profiles) !== 1 || !is_array($profiles[0] ?? null)) {
            return null;
        }
        $profile = $profiles[0];
        if (strtolower(trim((string)($profile['authorization_status'] ?? '')))
            !== CloudBrowserProfileService::READY_TO_COLLECT
        ) {
            return null;
        }
        $row['cloud_profile_public_id'] = $profilePublicId;
        return $row;
    }

    /** @return array{allowed:bool,reason_code:string,task:?array} */
    private function recentTask(
        int $tenantId,
        int $hotelId,
        int $actorId,
        int $sourceId,
        string $platform,
        DateTimeImmutable $now
    ): array {
        $rows = Db::name('platform_data_sync_tasks')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('requested_by', $actorId)
            ->where('data_source_id', $sourceId)
            ->where('platform', $platform)
            ->where('finished_at', '>=', $now
                ->modify('-' . self::MAX_SOURCE_AGE_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s'))
            ->where('finished_at', '<=', $now->format('Y-m-d H:i:s'))
            ->order('finished_at', 'desc')
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (is_array($row)
                && $this->recentTimestamp((string)($row['finished_at'] ?? ''), $now)
            ) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                $allowedStatuses = $platform === 'ctrip'
                    ? ['success', 'partial_success']
                    : ['success'];
                $allowed = in_array($status, $allowedStatuses, true);
                return [
                    'allowed' => $allowed,
                    'reason_code' => $allowed
                        ? $platform . '_latest_terminal_task_ready'
                        : $platform . '_latest_terminal_task_not_successful:'
                            . ($status ?: 'unknown'),
                    'task' => $row,
                ];
            }
        }
        return [
            'allowed' => false,
            'reason_code' => $platform . '_recent_readback_task_missing',
            'task' => null,
        ];
    }

    /** @param array<string,mixed> $source */
    private function sourceProfilePublicId(array $source): string
    {
        $config = $this->decodeArray($source['config_json'] ?? null);
        $values = [];
        foreach ([
            'profile_binding_key',
            'stable_profile_id',
            'profile_id',
            'browser_profile_id',
        ] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                $values[$value] = $value;
            }
        }
        if (count($values) !== 1) {
            return '';
        }
        $value = (string)array_values($values)[0];
        return preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $value) === 1
            ? $value
            : '';
    }

    /** @return array<string,mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function taskRows(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        int $taskId,
        string $platform,
        string $businessDate,
        DateTimeImmutable $now
    ): array {
        $rows = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('source', $platform)
            ->where('platform', $platform)
            ->where('sync_task_id', $taskId)
            ->where('data_date', $businessDate)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (!is_array($row)
                || !in_array($row['readback_verified'] ?? null, [1, '1', true, 'true'], true)
                || trim((string)($row['source_trace_id'] ?? '')) === ''
                || !$this->recentTimestamp(
                    $this->storedRowCapturedAt($row),
                    $now
                )
            ) {
                return [];
            }
        }
        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $row */
    private function storedRowCapturedAt(array $row): string
    {
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        foreach ([
            $row['snapshot_time'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['snapshot_time'] ?? null,
        ] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $metrics @return array{facts:list<string>,missing:list<string>} */
    private function ctripFacts(array $metrics): array
    {
        return $this->metricFacts($metrics, [
            'starting_price' => ['实时起价', 'money', ['captured']],
            'realtime_visitors' => ['APP访客', 'integer', ['captured']],
            'competitor_avg_visitor' => ['竞争圈平均', 'integer', ['captured']],
            'traffic_rank' => ['流量排名', 'integer', ['captured']],
            'booking_orders' => ['预订', 'integer', ['captured']],
            'in_house_room_nights' => ['在店间夜', 'integer', ['captured']],
        ]);
    }

    /** @param array<string, mixed> $metrics @return array{facts:list<string>,missing:list<string>} */
    private function meituanFacts(array $metrics): array
    {
        return $this->metricFacts($metrics, [
            'lead_price' => ['实时起价', 'money', ['verified', 'derived']],
            'sales_room_nights' => ['售出间夜', 'integer', ['verified', 'derived']],
            'sales_amount' => ['销售额', 'money', ['verified', 'derived']],
            'sales_avg_price' => ['平均价', 'money', ['verified', 'derived']],
            'exposure_users' => ['曝光', 'integer', ['verified', 'derived']],
            'detail_visitors' => ['详情访问', 'integer', ['verified', 'derived']],
            'paid_order_count' => ['支付订单', 'integer', ['verified', 'derived']],
            'browse_to_pay_rate' => ['浏览到支付转化', 'percent', ['verified', 'derived']],
        ]);
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, array{0:string,1:string,2:list<string>}> $specs
     * @return array{facts:list<string>,missing:list<string>}
     */
    private function metricFacts(array $metrics, array $specs): array
    {
        $facts = [];
        $missing = [];
        foreach ($specs as $key => [$label, $format, $allowedStatuses]) {
            $metric = is_array($metrics[$key] ?? null) ? $metrics[$key] : [];
            if (!in_array((string)($metric['status'] ?? ''), $allowedStatuses, true)
                || !is_numeric($metric['value'] ?? null)
            ) {
                $missing[] = $label;
                continue;
            }
            $value = (float)$metric['value'];
            $formatted = match ($format) {
                'money' => '¥' . $this->money($value),
                'percent' => number_format($value, 2) . '%',
                default => $this->integer($value),
            };
            $facts[] = $label . ' ' . $formatted;
        }
        return ['facts' => $facts, 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $pms
     * @param list<array<string, mixed>> $ctripRows
     * @param list<array<string, mixed>> $meituanRows
     * @return array<string, array<string, int|string>>
     */
    private function sourceReferences(
        array $pms,
        array $ctripRows,
        array $meituanRows,
        string $businessDate
    ): array {
        $refs = [
            'pms' => [
                'source' => 'dingdandao_pms',
                'record_id' => (int)($pms['id'] ?? 0),
                'business_date' => $businessDate,
                'source_scope' => (string)($pms['source_scope'] ?? ''),
                'capture_method' => (string)($pms['capture_method'] ?? ''),
                'source_trace_id' => (string)($pms['source_trace_id'] ?? ''),
                'provider_hotel_id' => (string)($pms['provider_hotel_id'] ?? ''),
                'provider_hotel_name' => (string)($pms['provider_hotel_name'] ?? ''),
            ],
        ];
        foreach ([
            'ctrip' => $ctripRows,
            'meituan' => $meituanRows,
        ] as $source => $rows) {
            foreach (array_slice($rows, 0, 5) as $index => $row) {
                $refs[$source . '_' . ($index + 1)] = [
                    'source' => $source,
                    'record_id' => (int)($row['id'] ?? 0),
                    'business_date' => $businessDate,
                    'data_type' => (string)($row['data_type'] ?? ''),
                    'dimension' => (string)($row['dimension'] ?? ''),
                    'data_source_id' => (int)($row['data_source_id'] ?? 0),
                    'sync_task_id' => (int)($row['sync_task_id'] ?? 0),
                    'source_trace_id' => (string)($row['source_trace_id'] ?? ''),
                ];
            }
        }
        return $refs;
    }

    /** @return array<string, mixed> */
    private function blocked(string $code, string $businessDate, string $message): array
    {
        return [
            'status' => 'blocked',
            'reason_code' => $code,
            'business_date' => $businessDate,
            'render_contract_version' => self::RENDER_CONTRACT_VERSION,
            'payload_fingerprint' => hash('sha256', $code . '|' . $businessDate),
            'formal_send_gate' => [
                'allowed' => false,
                'status' => 'formal_send_blocked',
                'blockers' => [['code' => $code, 'message' => $message]],
            ],
            'source_snapshot_refs' => [],
            'payload' => null,
        ];
    }

    private function recentTimestamp(string $value, DateTimeImmutable $now): bool
    {
        try {
            $at = new DateTimeImmutable($value, $now->getTimezone());
        } catch (\Throwable) {
            return false;
        }
        $age = $now->getTimestamp() - $at->getTimestamp();
        return $age >= 0 && $age <= self::MAX_SOURCE_AGE_SECONDS;
    }

    /** @param list<string> $facts */
    private function joinedFacts(array $facts): string
    {
        return '- ' . implode('｜', array_values(array_filter($facts)));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function integer(float $value): string
    {
        return number_format((int)round($value), 0, '.', ',');
    }

    private function clock(string $value): string
    {
        return strlen($value) >= 19 ? substr($value, 11, 8) : '时间未取得';
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}
