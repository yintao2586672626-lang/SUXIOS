<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Read-only, daily-total ledger. No collection, FX conversion, balancing or accounting claims. */
final class RevenueOperatingLedgerService
{
    public const VERSION = 'revenue_operating_ledger.v1';
    private const REASONS = [
        'amount_missing' => '金额缺失', 'invalid_amount' => '金额格式或精度无效',
        'currency_unverified_or_unsupported' => '币种未确认或暂不支持', 'amount_unit_unverified_or_unsupported' => '金额单位未确认或暂不支持',
        'date_basis_unverified' => '日期归属未确认', 'source_reference_missing' => '缺少来源引用',
        'source_not_verified' => '来源尚未通过精确回读', 'origin_business_date_invalid' => '原订单日期无效',
        'negative_deduction_requires_explicit_credit_mapping' => '负费用或退款需明确冲回口径',
        'daily_total_grain_unverified' => '尚未形成可核对的每日总额', 'platform_hotel_identity_missing' => '平台门店身份未确认',
        'order_settlement_cohort_unproven' => '订单与结算是否属于同一批订单尚未证实',
        'cross_period_refund_requires_order_cohort_bridge' => '退款来自其他业务期，需关联原订单后才能解释本期差额',
        'order_or_settlement_incomplete' => '订单或结算金额缺失、未验证或覆盖不全',
        'business_date_basis_differs' => '订单与结算使用不同日期基准',
        'deduction_period_conflict' => '退款或费用的期间口径存在冲突，暂停差额解释汇总',
    ];
    public const DEFINITIONS = [
        'order_amount' => ['label' => '渠道订单额', 'meaning' => '渠道按来源业务日归属的订单金额，不等于房费或结算'],
        'room_revenue' => ['label' => '住宿房费', 'meaning' => '已映射住宿业务日的房费，不等于支付实收'],
        'estimated_room_revenue' => ['label' => '预计房费', 'meaning' => 'PMS 实时预计房费，尚不等于实住房费'],
        'settlement_amount' => ['label' => '结算金额', 'meaning' => '来源结算口径，不能自动替代净收入'],
        'refund_amount' => ['label' => '退款金额', 'meaning' => '退款发生日金额；原订单日单独保留'],
        'fee_amount' => ['label' => '渠道费用', 'meaning' => '来源明确的费用；佣金可能只是费用子集'],
    ];

    /** Entries are daily totals. Distinct transactions must be aggregated by their source adapter first. */
    public function build(array $scope, array $entries): array
    {
        $scope = $this->scope($scope);
        $dates = $this->dates($scope['start_date'], $scope['end_date']);
        $groups = $rejected = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) { $rejected[] = ['index' => $index, 'reason' => 'invalid_entry']; continue; }
            $row = $this->entry($scope, $entry);
            if (isset($row['rejected'])) {
                // Do not return any foreign-scope value or reference.
                $rejected[] = ['index' => $index, 'reason' => $row['rejected']];
                continue;
            }
            $groups[$row['platform']][$row['metric_key']][$row['business_date']][] = $row;
        }
        $metrics = $conflicts = [];
        foreach ($scope['platforms'] as $platform) {
            $keys = in_array($platform, ['ctrip', 'meituan'], true)
                ? ['order_amount', 'room_revenue', 'settlement_amount', 'refund_amount', 'fee_amount']
                : ['room_revenue', 'estimated_room_revenue', 'settlement_amount', 'refund_amount', 'fee_amount'];
            foreach ($keys as $key) {
                $days = $covered = $missing = $bases = $refs = $stores = $semanticVariants = [];
                $sum = 0; $duplicates = 0;
                foreach ($dates as $date) {
                    $rows = $groups[$platform][$key][$date] ?? [];
                    $unique = [];
                    foreach ($rows as $row) {
                        $signature = $this->digest(array_intersect_key($row, array_flip([
                            'value_minor', 'date_basis', 'currency', 'unit', 'status',
                            'reconciliation_group', 'origin_business_date', 'definition', 'evidence_mode',
                            'platform_hotel_id', 'source_grain',
                        ])));
                        $unique[$signature][] = $row;
                        $refs = array_merge($refs, $row['source_refs']);
                    }
                    $duplicates += count($rows) - count($unique);
                    $representative = $rows[0] ?? null;
                    $status = count($unique) > 1 ? 'conflict' : ($representative['status'] ?? 'missing');
                    $day = ['business_date' => $date, 'status' => $status,
                        'value' => $status === 'ready' ? $representative['value_minor'] / 100 : null,
                        'entries' => $rows, 'duplicate_snapshots' => count($rows) - count($unique)];
                    if ($status === 'conflict') {
                        $conflicts[] = ['platform' => $platform, 'metric_key' => $key,
                            'business_date' => $date, 'reason' => 'same_metric_conflicting_snapshots', 'entries' => $rows];
                    }
                    if ($status === 'ready') {
                        $covered[] = $date;
                        $sum += $representative['value_minor'];
                        $bases[$representative['date_basis']] = true;
                        $stores[$representative['platform_hotel_id']] = true;
                        $semanticKey = $this->digest([$representative['definition'], $representative['source_grain']]);
                        $semanticVariants[$semanticKey] ??= [
                            'definition' => $representative['definition'], 'source_grain' => $representative['source_grain'],
                            'business_dates' => [], 'source_refs' => [],
                        ];
                        $semanticVariants[$semanticKey]['business_dates'][] = $date;
                        $semanticVariants[$semanticKey]['source_refs'] = array_values(array_unique(array_merge(
                            $semanticVariants[$semanticKey]['source_refs'], ...array_column($rows, 'source_refs')
                        )));
                    } else { $missing[] = $date; }
                    $days[] = $day;
                }
                $basisConflict = count($bases) > 1 || count($stores) > 1;
                $semanticVariants = array_values($semanticVariants);
                $semanticConflict = count($semanticVariants) > 1;
                if ($semanticConflict) {
                    $conflicts[] = ['platform' => $platform, 'metric_key' => $key,
                        'start_date' => $scope['start_date'], 'end_date' => $scope['end_date'],
                        'reason' => 'period_metric_semantics_conflict', 'semantic_variants' => $semanticVariants];
                }
                $complete = $missing === [] && !$basisConflict && !$semanticConflict;
                $hasConflict = $basisConflict || $semanticConflict || in_array('conflict', array_column($days, 'status'), true);
                $metrics[] = [
                    'key' => $platform . ':' . $key, 'platform' => $platform, 'metric_key' => $key,
                    'label' => self::DEFINITIONS[$key]['label'], 'definition' => self::DEFINITIONS[$key]['meaning'],
                    'formula' => 'SUM(同范围、同日期基准的每日唯一已验证金额)',
                    'unit' => 'yuan', 'currency' => 'CNY',
                    'scope' => in_array($platform, ['ctrip', 'meituan'], true) ? 'ota_channel' : 'whole_hotel_accommodation',
                    'status' => $hasConflict ? 'conflict' : ($complete ? 'ready' : ($covered === [] ? 'missing' : 'partial')),
                    'value' => $complete ? $sum / 100 : null,
                    'partial_value' => !$complete && !$basisConflict && !$semanticConflict && $covered !== [] ? $sum / 100 : null,
                    'partial_label' => !$complete && !$semanticConflict && $covered !== [] ? '非全期间金额' : null,
                    'covered_dates' => $covered, 'missing_dates' => $missing,
                    'date_bases' => array_keys($bases), 'date_basis_conflict' => $basisConflict,
                    'platform_hotel_ids' => array_keys($stores),
                    'period_semantic_conflict' => $semanticConflict, 'semantic_variants' => $semanticVariants,
                    'source_refs' => array_values(array_unique($refs)), 'days' => $days,
                    'duplicate_snapshots' => $duplicates,
                ];
            }
        }
        $differences = [];
        foreach ($scope['platforms'] as $platform) {
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $differences[] = $this->difference($platform, $metrics);
            }
        }
        $ready = count(array_filter($metrics, static fn(array $m): bool => $m['status'] === 'ready'));
        $result = ['contract_version' => self::VERSION, 'scope' => $scope,
            'status' => $ready === count($metrics) && $rejected === [] ? 'ready' : ($ready > 0 ? 'partial' : 'blocked'),
            'metrics' => $metrics, 'differences' => $differences, 'conflicts' => $conflicts, 'rejected_entries' => $rejected,
            'policy' => ['missing_value' => null, 'forced_balance' => false, 'ota_is_whole_hotel' => false,
                'gop_calculable' => false, 'snapshot_grain' => 'daily_total', 'evidence_mode' => $scope['evidence_mode']]];
        $result['version'] = $this->digest($result);
        return $result;
    }

    /** Reuses already scoped facts; optional financial rows come from the standard dataset, never raw responses. */
    public function fromFactLayer(array $layer, array $entries = []): array
    {
        $tenantId = (int)($layer['hotel']['tenant_id'] ?? 0);
        $hotelId = (int)($layer['hotel']['system_hotel_id'] ?? 0);
        $date = (string)($layer['business_date'] ?? '');
        $pms = (new RevenuePmsFactSelectorService())->select($layer);
        $platforms = ['ctrip', 'meituan'];
        if ($pms['provider'] !== null) $platforms[] = $pms['provider'];
        foreach ($platforms as $platform) {
            $isOta = in_array($platform, ['ctrip', 'meituan'], true);
            $envelope = $isOta ? ($layer['sources'][$platform . '_ota'] ?? []) : $pms['source'];
            $source = $envelope['source'] ?? [];
            $factKey = $isOta ? 'revenue' : 'room_revenue';
            $key = $isOta ? 'order_amount' : ($platform === 'meituan_cloud_pms' ? 'estimated_room_revenue' : 'room_revenue');
            if (array_filter($entries, static fn(array $e): bool => ($e['platform'] ?? '') === $platform && ($e['metric_key'] ?? '') === $key)) continue;
            $status = $envelope['fact_statuses'][$factKey] ?? [];
            $ids = $isOta ? ($source['row_ids'] ?? []) : [$source['record_id'] ?? 0];
            $refs = array_map(static fn($id): string => ($source['table'] ?? 'online_daily_data') . '#' . $id,
                array_values(array_filter($ids, static fn($id): bool => (int)$id > 0)));
            $entries[] = ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'platform' => $platform,
                'business_date' => $date, 'metric_key' => $key, 'value' => $envelope['facts'][$factKey] ?? null,
                'date_basis' => $isOta ? 'source_business_date' : 'pms_business_date',
                'currency' => 'CNY', 'unit' => 'yuan', 'unit_basis' => 'existing_fact_layer_cny_contract',
                'source_refs' => $refs, 'source_field' => $factKey,
                'definition' => (string)($status['caliber'] ?? self::DEFINITIONS[$key]['meaning']),
                'quality_status' => $status['status'] ?? $envelope['data_status'] ?? 'missing',
                'readback_verified' => in_array($status['status'] ?? '', ['readback_verified', 'derived_verified'], true),
                'source_version' => $source['source_fingerprint'] ?? null,
                'collected_at' => $source['captured_at'] ?? null,
                'platform_hotel_id' => $source['provider_hotel_id'] ?? null];
        }
        return $this->build(['tenant_id' => $tenantId, 'hotel_id' => $hotelId,
            'start_date' => $date, 'end_date' => $date, 'platforms' => $platforms], $entries);
    }

    /** Restrict platform before issuing/saving a model. A foreign identity is rejected, never silently relabelled. */
    public function forOverview(array $overview, int $tenantId, int $hotelId, string $date, string $platform): ?array
    {
        $ledger = $overview['three_source_fact_layer']['operating_ledger'] ?? null;
        if (!is_array($ledger)) return null; // legacy snapshots remain readable
        $scope = $ledger['scope'] ?? [];
        if (($ledger['contract_version'] ?? '') !== self::VERSION || (int)($scope['tenant_id'] ?? 0) !== $tenantId
            || (int)($scope['hotel_id'] ?? 0) !== $hotelId || ($scope['start_date'] ?? '') !== $date
            || ($scope['end_date'] ?? '') !== $date || !in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_operating_ledger_scope_mismatch');
        }
        $expected = $ledger['version'] ?? ''; unset($ledger['version']);
        if (!is_string($expected) || !hash_equals($expected, $this->digest($ledger))) {
            throw new InvalidArgumentException('revenue_operating_ledger_version_mismatch');
        }
        $allowed = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        // PMS remains an explicitly separate source, as in the existing cockpit.
        $allowed = array_values(array_filter($scope['platforms'], static fn(string $p): bool => !in_array($p, ['ctrip', 'meituan'], true) || in_array($p, $allowed, true)));
        $ledger['scope']['platforms'] = $allowed;
        foreach (['metrics', 'differences', 'conflicts'] as $field) {
            $ledger[$field] = array_values(array_filter($ledger[$field], static fn(array $item): bool => in_array($item['platform'], $allowed, true)));
        }
        $ready = count(array_filter($ledger['metrics'], static fn(array $item): bool => $item['status'] === 'ready'));
        $ledger['status'] = $ready === count($ledger['metrics']) && $ledger['rejected_entries'] === [] ? 'ready' : ($ready > 0 ? 'partial' : 'blocked');
        $ledger['version'] = $this->digest($ledger);
        return $ledger;
    }

    private function difference(string $platform, array $metrics): array
    {
        $byKey = [];
        foreach ($metrics as $metric) if ($metric['platform'] === $platform) $byKey[$metric['metric_key']] = $metric;
        $order = $byKey['order_amount']; $settlement = $byKey['settlement_amount'];
        $available = $order['value'] !== null && $settlement['value'] !== null;
        $raw = $available ? (int)round(($order['value'] - $settlement['value']) * 100) : null;
        $components = $reasons = [];
        $explained = 0;
        $deductionPeriodConflict = false;
        $dateBridgeAllowed = $available;
        foreach ($order['days'] as $index => $day) {
            $o = $day['entries'][0] ?? []; $s = $settlement['days'][$index]['entries'][0] ?? [];
            $group = $o['reconciliation_group'] ?? '';
            if ($group === '' || $group !== ($s['reconciliation_group'] ?? '')
                || ($o['platform_hotel_id'] ?? '') === '' || $o['platform_hotel_id'] !== ($s['platform_hotel_id'] ?? '')) {
                $dateBridgeAllowed = false; $reasons[] = 'order_settlement_cohort_unproven';
            }
        }
        foreach (['refund_amount', 'fee_amount'] as $key) {
            $metric = $byKey[$key];
            $periodConflict = $metric['date_basis_conflict'] || $metric['period_semantic_conflict'];
            if ($periodConflict) {
                $deductionPeriodConflict = true;
                $reasons[] = 'deduction_period_conflict';
            }
            foreach ($metric['days'] as $index => $day) {
                $row = $day['entries'][0] ?? [];
                $orderRow = $order['days'][$index]['entries'][0] ?? [];
                $linked = !$periodConflict && $dateBridgeAllowed && $day['status'] === 'ready'
                    && ($row['reconciliation_group'] ?? '') !== ''
                    && $row['reconciliation_group'] === ($orderRow['reconciliation_group'] ?? '')
                    && $row['platform_hotel_id'] === ($orderRow['platform_hotel_id'] ?? '')
                    && ($row['date_basis'] ?? '') !== 'source_business_date';
                $crossPeriod = ($row['origin_business_date'] ?? null) !== null
                    && $row['origin_business_date'] !== $day['business_date'];
                if ($crossPeriod) { $linked = false; $reasons[] = 'cross_period_refund_requires_order_cohort_bridge'; }
                if ($linked) $explained += $row['value_minor'];
                if ($day['entries'] !== []) $components[] = [
                    'metric_key' => $key, 'label' => $metric['label'], 'business_date' => $day['business_date'],
                    'origin_business_date' => $row['origin_business_date'] ?? null,
                    'observed_value' => $day['value'], 'explained_value' => $linked ? $row['value_minor'] / 100 : null,
                    'status' => $linked ? 'linked_evidence' : ($crossPeriod ? 'cross_period' : 'unlinked'),
                    'source_refs' => $row['source_refs'] ?? []];
            }
        }
        if (!$available) $reasons[] = 'order_or_settlement_incomplete';
        if ($order['date_bases'] !== $settlement['date_bases']) $reasons[] = 'business_date_basis_differs';
        // Daily observations remain inspectable, but cannot bypass the metric's period gate.
        $explanationAvailable = $available && !$deductionPeriodConflict;
        $unexplained = $explanationAvailable ? $raw - $explained : null;
        return ['key' => $platform . ':order_minus_settlement', 'platform' => $platform,
            'label' => '订单额与结算差额', 'formula' => '订单额 − 结算金额 = 有关联证据的退款/费用 + 未解释差额',
            'status' => !$explanationAvailable ? 'blocked' : ($unexplained === 0 && $dateBridgeAllowed ? 'explained' : 'unexplained'),
            'observed_difference' => $raw !== null ? $raw / 100 : null,
            'explained_difference' => $explanationAvailable ? $explained / 100 : null,
            'unexplained_difference' => $unexplained !== null ? $unexplained / 100 : null,
            'cohort_comparable' => $dateBridgeAllowed, 'components' => $components,
            'reason_codes' => array_values(array_unique($reasons)), 'currency' => 'CNY',
            'reason_texts' => $this->reasonTexts($reasons),
            'boundary' => '同日金额差只是观察值；日期基准或订单归属未闭合时，不代表漏款、利润或全酒店收入差额。'];
    }

    private function entry(array $scope, array $entry): array
    {
        if ((int)($entry['tenant_id'] ?? 0) !== $scope['tenant_id'] || (int)($entry['hotel_id'] ?? 0) !== $scope['hotel_id']
            || !in_array($entry['platform'] ?? '', $scope['platforms'], true)) return ['rejected' => 'scope_mismatch'];
        $date = (string)($entry['business_date'] ?? '');
        if (!$this->validDate($date) || $date < $scope['start_date'] || $date > $scope['end_date']) return ['rejected' => 'date_outside_scope'];
        $key = (string)($entry['metric_key'] ?? '');
        if (!isset(self::DEFINITIONS[$key])) return ['rejected' => 'unknown_metric'];
        $mode = (string)($entry['evidence_mode'] ?? 'production');
        if ($mode !== $scope['evidence_mode']) return ['rejected' => 'evidence_mode_mismatch'];
        $refs = array_values(array_unique(array_filter((array)($entry['source_refs'] ?? []),
            static fn($ref): bool => is_string($ref) && preg_match('/^[a-z][a-z0-9_]*#[1-9][0-9]*$/D', $ref) === 1)));
        $value = $entry['value'] ?? null;
        $numeric = (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $value)))
            && is_numeric($value) && is_finite((float)$value) && abs((float)$value) <= 1e12
            && abs((float)$value * 100 - round((float)$value * 100)) < 0.001;
        $reasons = [];
        if (!$numeric) $reasons[] = $value === null ? 'amount_missing' : 'invalid_amount';
        if ($numeric && in_array($key, ['refund_amount', 'fee_amount'], true) && (float)$value < 0) $reasons[] = 'negative_deduction_requires_explicit_credit_mapping';
        if (strtoupper((string)($entry['currency'] ?? '')) !== 'CNY') $reasons[] = 'currency_unverified_or_unsupported';
        if (($entry['unit'] ?? '') !== 'yuan') $reasons[] = 'amount_unit_unverified_or_unsupported';
        $basis = (string)($entry['date_basis'] ?? '');
        if (!in_array($basis, ['source_business_date', 'booking_date', 'stay_date', 'pms_business_date', 'settlement_date', 'refund_date', 'fee_date'], true)) $reasons[] = 'date_basis_unverified';
        if ($refs === []) $reasons[] = 'source_reference_missing';
        if (($entry['grain'] ?? 'daily_total') !== 'daily_total') $reasons[] = 'daily_total_grain_unverified';
        if (in_array($entry['platform'], ['ctrip', 'meituan'], true) && trim((string)($entry['platform_hotel_id'] ?? '')) === '') $reasons[] = 'platform_hotel_identity_missing';
        if (($entry['readback_verified'] ?? false) !== true || !in_array($entry['quality_status'] ?? '', ['ready', 'readback_verified', 'derived_verified', 'verified', 'strict_readback'], true)) $reasons[] = 'source_not_verified';
        $origin = $entry['origin_business_date'] ?? null;
        if ($origin !== null && !$this->validDate((string)$origin)) $reasons[] = 'origin_business_date_invalid';
        return ['tenant_id' => $scope['tenant_id'], 'hotel_id' => $scope['hotel_id'], 'platform' => $entry['platform'],
            'business_date' => $date, 'metric_key' => $key, 'value' => $numeric ? (float)$value : null,
            'value_minor' => $numeric ? (int)round((float)$value * 100) : null,
            'currency' => $entry['currency'] ?? null, 'unit' => $entry['unit'] ?? null,
            'date_basis' => $basis, 'status' => $reasons === [] ? 'ready' : 'unverified',
            'reason_codes' => $reasons, 'reason_texts' => $this->reasonTexts($reasons), 'source_refs' => $refs,
            'source_field' => $this->text($entry['source_field'] ?? ''),
            'source_version' => $this->text($entry['source_version'] ?? ''),
            'collected_at' => $this->text($entry['collected_at'] ?? ''),
            'platform_hotel_id' => $this->text($entry['platform_hotel_id'] ?? ''),
            'source_grain' => $this->text($entry['source_grain'] ?? 'daily_total'),
            'definition' => $this->text($entry['definition'] ?? self::DEFINITIONS[$key]['meaning']),
            'unit_basis' => $this->text($entry['unit_basis'] ?? ''),
            'reconciliation_group' => $this->text($entry['reconciliation_group'] ?? ''),
            'origin_business_date' => $origin, 'evidence_mode' => $mode,
            'fact_identity' => $this->digest([$scope['tenant_id'], $scope['hotel_id'], $entry['platform'], $date, $key, $basis,
                $refs, $entry['source_version'] ?? '', $numeric ? (int)round((float)$value * 100) : null,
                $entry['platform_hotel_id'] ?? '', $entry['source_grain'] ?? 'daily_total',
                $entry['currency'] ?? null, $entry['unit'] ?? null, $reasons])];
    }

    private function scope(array $scope): array
    {
        $start = (string)($scope['start_date'] ?? ''); $end = (string)($scope['end_date'] ?? '');
        $platformInput = (array)($scope['platforms'] ?? []);
        if (array_filter($platformInput, static fn($p): bool => !is_string($p)) !== []) throw new InvalidArgumentException('revenue_operating_ledger_scope_invalid');
        $platforms = array_values(array_unique($platformInput)); sort($platforms);
        $mode = $scope['evidence_mode'] ?? 'production';
        if ((int)($scope['tenant_id'] ?? 0) <= 0 || (int)($scope['hotel_id'] ?? 0) <= 0 || !$this->validDate($start)
            || !$this->validDate($end) || $start > $end || $platforms === []
            || array_diff($platforms, ['ctrip', 'meituan', 'dingdandao_pms', 'meituan_cloud_pms']) !== []
            || !in_array($mode, ['production', 'synthetic'], true)
            || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 366) {
            throw new InvalidArgumentException('revenue_operating_ledger_scope_invalid');
        }
        return ['tenant_id' => (int)$scope['tenant_id'], 'hotel_id' => (int)$scope['hotel_id'], 'start_date' => $start,
            'end_date' => $end, 'platforms' => $platforms, 'timezone' => 'Asia/Shanghai', 'evidence_mode' => $mode];
    }
    private function dates(string $start, string $end): array
    {
        $dates = []; for ($date = new DateTimeImmutable($start); $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) $dates[] = $date->format('Y-m-d');
        return $dates;
    }
    private function validDate(string $value): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $d && $d->format('Y-m-d') === $value; }
    private function text(mixed $value): string { return is_scalar($value) ? mb_substr((string)$value, 0, 240) : ''; }
    private function reasonTexts(array $codes): array { return array_values(array_map(static fn(string $code): string => self::REASONS[$code] ?? $code, array_unique($codes))); }
    private function digest(array $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
