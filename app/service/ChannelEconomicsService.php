<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use think\facade\Db;

/** Same-month channel economics; attributed sales do not establish incremental effects. */
final class ChannelEconomicsService
{
    public function calculate(array $input, array $sources = []): array
    {
        if (is_array($sources['settlement'] ?? null)) $sources['settlement'] = $this->compactSettlementReceipt($sources['settlement']);
        $net = $this->number($input['net_revenue'] ?? null, true);
        $spend = $this->number($input['advertising_spend'] ?? null);
        $attributed = $this->number($input['attributed_order_amount'] ?? null);
        $sourceRefs = $input['source_refs'] ?? [];
        if (!is_array($sourceRefs) || count($sourceRefs) > 100) throw new InvalidArgumentException('channel_source_refs_invalid');
        $sourceRefs = array_values(array_unique(array_filter(
            array_map(fn($ref): string => $this->text($ref, 500), $sourceRefs), static fn(string $ref): bool => $ref !== ''
        )));
        $metricRefs = $input['evidence_refs_by_metric'] ?? [];
        if (!is_array($metricRefs)) throw new InvalidArgumentException('channel_metric_source_refs_invalid');
        $normalizedRefs = [];
        foreach (['effective_order_amount', 'refund_amount', 'net_revenue', 'advertising_spend', 'attributed_order_amount'] as $metric) {
            $refs = $metricRefs[$metric] ?? [];
            if (!is_array($refs) || count($refs) > 100) throw new InvalidArgumentException('channel_metric_source_refs_invalid');
            $normalizedRefs[$metric] = array_values(array_unique(array_filter(array_map(fn($ref): string => $this->text($ref, 500), $refs), static fn(string $ref): bool => $ref !== '')));
        }
        $sourceRefs = array_values(array_unique(array_merge($sourceRefs, ...array_values($normalizedRefs))));
        if (count($sourceRefs) > 100) throw new InvalidArgumentException('channel_source_refs_invalid');
        // Only service-owned receipts supply automatic facts. Browser values remain manual.
        $settlementUsed = false;
        $settlementEligible = ($sources['settlement']['readback_verified'] ?? false) === true
            && ($sources['settlement']['projection_status'] ?? '') === 'latest_attempt'
            && ($sources['settlement']['latest_attempt']['batch_status'] ?? '') !== 'invalid'
            && in_array($sources['settlement']['source']['source_quality_status'] ?? '', ['operator_attested','verified_export'],true);
        if ($settlementEligible) {
            $component = $sources['settlement']['basis_ledger']['components']['net_revenue'] ?? [];
            if (($component['value'] ?? null) !== null) { $net = $this->number($component['value'], true); $settlementUsed = true; }
        }
        if (($sources['marketing']['complete'] ?? false) === true) {
            $spend = $this->number($sources['marketing']['advertising_spend'] ?? null); $attributed = $this->number($sources['marketing']['attributed_order_amount'] ?? null);
        }
        $basis = $this->text($input['attribution_basis'] ?? '', 200);
        if (($sources['marketing']['complete'] ?? false) === true) $basis = $this->text($sources['marketing']['attribution_basis'] ?? '', 200);
        $orders = $this->number($input['effective_order_amount'] ?? null);
        $refund = $this->number($input['refund_amount'] ?? null);
        $costs = $input['costs'] ?? [];
        if (!is_array($costs) || count($costs) > 100) throw new InvalidArgumentException('channel_costs_invalid');
        $rows = []; $knownCosts = 0.0; $missing = []; $costsKnown = true; $hasKnownDeduction = false;
        foreach ($costs as $raw) {
            if (!is_array($raw) || !is_bool($raw['included_in_net_revenue'] ?? null)) throw new InvalidArgumentException('channel_cost_inclusion_required');
            $costType = $raw['cost_type'] ?? 'direct';
            if (!in_array($costType, ['direct', 'advertising'], true)) throw new InvalidArgumentException('channel_cost_type_invalid');
            $row = ['label' => $this->text($raw['label'] ?? '', 160), 'amount' => $this->number($raw['amount'] ?? null),
                'cost_type' => $costType, 'included_in_net_revenue' => $raw['included_in_net_revenue'], 'source_ref' => $this->text($raw['source_ref'] ?? '', 500)];
            if (!$row['included_in_net_revenue']) {
                if ($row['amount'] === null || $row['source_ref'] === '') { $costsKnown = false; $missing[] = 'cost:' . $row['label']; }
                else { $knownCosts += $row['amount']; $hasKnownDeduction = true; }
            }
            $rows[] = $row;
        }
        // Advertising is a separate explicit deduction unless already included or in direct costs.
        $adIncluded = $input['advertising_included_in_net_revenue'] ?? null;
        $adInCosts = $input['advertising_in_direct_costs'] ?? null;
        if (!is_bool($adIncluded) || !is_bool($adInCosts) || ($adIncluded && $adInCosts)) throw new InvalidArgumentException('channel_advertising_deduction_basis_required');
        $advertisingRows = array_values(array_filter($rows, static fn(array $row): bool => $row['cost_type'] === 'advertising'));
        if ($adInCosts) {
            $advertisingCost = array_sum(array_column($advertisingRows, 'amount'));
            $roundingError = $spend === null ? 0.0 : PHP_FLOAT_EPSILON * max(abs($advertisingCost), abs($spend)) * max(1, count($advertisingRows));
            if ($spend === null || $advertisingRows === []
                || count(array_filter($advertisingRows, static fn(array $row): bool => $row['amount'] === null || $row['source_ref'] === '' || $row['included_in_net_revenue'])) > 0
                || abs($advertisingCost - $spend) > $roundingError) {
                throw new InvalidArgumentException('channel_advertising_direct_cost_evidence_required');
            }
        } elseif ($advertisingRows !== [] && (!$adIncluded || count(array_filter($advertisingRows, static fn(array $row): bool => !$row['included_in_net_revenue'])) > 0)) {
            throw new InvalidArgumentException('channel_advertising_cost_classification_conflict');
        }
        if (!$adIncluded && !$adInCosts) {
            if ($spend === null) { $costsKnown = false; $missing[] = 'advertising_spend_missing'; }
            else { $knownCosts += $spend; $hasKnownDeduction = true; }
        }
        $coverage = ($input['cost_coverage_complete'] ?? false) === true;
        if (!$coverage) $missing[] = 'direct_cost_coverage_incomplete';
        if ($net === null) $missing[] = 'net_revenue_missing';
        if ($orders === null) $missing[] = 'effective_order_amount_missing';
        if ($refund === null) $missing[] = 'refund_amount_missing';
        if ($sourceRefs === []) $missing[] = 'manual_source_refs_missing';
        $knownDirectCost = $hasKnownDeduction || ($coverage && $costsKnown) ? round($knownCosts, 2) : null;
        $knownContribution = $net !== null && $knownDirectCost !== null ? round($net - $knownCosts, 2) : null;
        if (!is_finite($knownCosts) || abs($knownCosts) > 1e12 || ($knownContribution !== null && (!is_finite($knownContribution) || abs($knownContribution) > 1e12))) throw new InvalidArgumentException('channel_calculated_amount_out_of_range');
        $roas = $spend !== null && $spend > 0 && $attributed !== null && $basis !== '' ? round($attributed / $spend, 6) : null;
        if ($roas !== null && (!is_finite($roas) || abs($roas) > 1e12)) throw new InvalidArgumentException('channel_calculated_roas_out_of_range');
        $fullContribution = $coverage && $costsKnown && $net !== null && $sourceRefs !== [] ? $knownContribution : null;
        $attested = ($input['operator_attested'] ?? false) === true;
        $inputs = ['net_revenue' => $net, 'advertising_spend' => $spend, 'attributed_order_amount' => $attributed,
            'effective_order_amount' => $orders, 'refund_amount' => $refund, 'attribution_basis' => $basis,
            'advertising_included_in_net_revenue' => $adIncluded, 'advertising_in_direct_costs' => $adInCosts,
            'cost_coverage_complete' => $coverage, 'operator_attested' => $attested, 'source_refs' => $sourceRefs,
            'evidence_refs_by_metric' => $normalizedRefs, 'costs' => $rows];
        $checks = [];
        foreach (['effective_order_amount' => $orders, 'refund_amount' => $refund, 'net_revenue' => $net, 'advertising_spend' => $spend, 'attributed_order_amount' => $attributed] as $metric => $value) {
            $savedSource = ($metric === 'net_revenue' && $settlementUsed)
                || (in_array($metric, ['advertising_spend', 'attributed_order_amount'], true) && ($sources['marketing']['complete'] ?? false) === true);
            $checks[] = ['metric' => $metric, 'status' => $value === null ? 'amount_missing' : ($savedSource ? 'saved_source_readback' : ($normalizedRefs[$metric] === [] ? 'source_missing' : ($attested ? 'manual_attested' : 'manual_unverified'))),
                'source_refs' => $normalizedRefs[$metric], 'origin' => $savedSource ? 'saved_same_scope_source' : 'manual_input'];
        }
        $checks[] = ['metric' => 'direct_costs', 'status' => !$coverage || !$costsKnown ? 'coverage_incomplete'
            : (count(array_filter($rows, static fn(array $row): bool => $row['amount'] === null || $row['source_ref'] === '')) > 0 ? 'source_missing' : ($attested ? 'manual_attested' : 'manual_unverified')),
            'source_refs' => array_values(array_unique(array_filter(array_column($rows, 'source_ref')))), 'origin' => 'manual_input'];
        $checks[] = ['metric' => 'attribution_basis', 'status' => $basis === '' ? 'basis_missing'
            : (($sources['marketing']['complete'] ?? false) === true ? 'saved_source_readback' : ($attested ? 'manual_attested' : 'manual_unverified')),
            'source_refs' => [], 'origin' => ($sources['marketing']['complete'] ?? false) === true ? 'saved_same_scope_source' : 'manual_input'];
        $evidenceComplete = count(array_filter($checks, static fn(array $check): bool => !in_array($check['status'], ['saved_source_readback', 'manual_attested'], true))) === 0;
        $closedPeriod = ($sources['period_closed'] ?? null) === true;
        return ['status' => $fullContribution !== null && $missing === [] ? 'calculated' : 'partial', 'source_quality' => $attested ? 'operator_attested' : 'unverified',
            'inputs' => $inputs, 'currency' => 'CNY', 'net_revenue' => $net, 'known_direct_cost' => $knownDirectCost,
            'channel_net_contribution_amount' => $fullContribution, 'known_costs_contribution_amount' => $knownContribution,
            'attributed_roas' => $roas,
            'order_to_settlement_difference' => $orders !== null && $net !== null ? round($orders - $net, 2) : null,
            'missing_items' => array_values(array_unique($missing)), 'source_receipts' => $sources,
            'evidence_chain' => ['status' => $evidenceComplete && $closedPeriod ? 'ready_for_same_scope_review' : 'incomplete',
                'period_status' => array_key_exists('period_closed', $sources) ? ($closedPeriod ? 'closed' : 'open') : 'unknown',
                'scope' => $sources['scope'] ?? null, 'checks' => $checks,
                'independently_verified' => false, 'accounting_close_verified' => false, 'whole_hotel_profit' => false],
            'input_origins' => ['net_revenue' => $settlementUsed ? 'saved_same_scope_settlement' : 'manual_input', 'marketing' => ($sources['marketing']['complete'] ?? false) === true ? 'saved_complete_same_basis_month' : 'manual_input'],
            'formulas' => ['channel_net_contribution_amount' => 'verified_or_manual_net_revenue - not_already_included_direct_costs',
                'attributed_roas' => 'same_attribution_order_amount / advertising_spend'],
            'boundaries' => ['metric_scope' => 'ota_channel', 'whole_hotel_profit' => false, 'incremental_ad_effect_established' => false,
                'net_channel_contribution_existing_metric_is_share_percent' => true, 'automatic_operating_action' => false]];
    }
    public function sourceReceipts(int $tenant, int $hotel, string $platform, string $month): array
    {
        $start = new DateTimeImmutable($month . '-01', new \DateTimeZone('Asia/Shanghai'));
        $settlement = $this->compactSettlementReceipt((new OtaSettlementReconciliationService())->latestForScope($tenant, $hotel, $platform, $start->format('Y-m-d'), $start->format('Y-m-t')));
        $marketing = ['complete' => false, 'covered_days' => [], 'missing_days' => [], 'advertising_spend' => null, 'attributed_order_amount' => null, 'evidence_refs' => []];
        if ($platform === 'meituan') {
            $spent = 0.0; $amount = 0.0; $basis = null;
            // Restrict the projection before its quality gates: keyword failures are
            // outside monetary advertising evidence; failed advertising stays visible.
            $advertisingProjection = new MeituanMarketingFactProjectionService(static fn(int $tenantId, int $hotelId, string $day): array => Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)->where('system_hotel_id', $hotelId)->where('data_date', $day)
                ->where('data_type', 'advertising')->order('id', 'asc')->select()->toArray());
            $marketing['day_quality'] = [];
            for ($date = $start; $date <= $start->modify('last day of this month'); $date = $date->modify('+1 day')) {
                $day = $date->format('Y-m-d'); $projection = $advertisingProjection->project($tenant, $hotel, $day);
                $projections = $projection['projections'] ?? [];
                $ads = array_values(array_filter($projections, static fn(array $r): bool => ($r['fact_type'] ?? '') === 'advertising' && ($r['scope']['object_type'] ?? '') === 'campaign'));
                if (count($ads) !== count($projections)) $projection['data_quality']['gap_codes'][] = 'advertising_campaign_scope_required';
                $marketing['day_quality'][$day] = $projection['data_quality'];
                // A verified zero amount can cover a day even though its ROAS is undefined.
                $valid = $ads !== [] && in_array($projection['status'] ?? '', ['ready', 'partial'], true)
                    && $this->marketingScopeMatches($projection['scope'] ?? [], $tenant, $hotel, $day)
                    && ($projection['data_quality']['rejected_reason_counts'] ?? []) === []
                    && array_diff($projection['data_quality']['gap_codes'] ?? [], ['spend_not_positive']) === []
                    && count(array_filter($ads, static fn(array $row): bool => ($row['quality_status'] ?? '') !== 'verified')) === 0;
                $daySpent = 0.0; $dayAmount = 0.0; $dayBasis = null; $dayRefs = [];
                foreach ($valid ? $ads : [] as $row) {
                    $m = $row['metrics'];
                    $refs = $row['evidence_refs'] ?? [];
                    if (!$this->marketingScopeMatches($row['scope'] ?? [], $tenant, $hotel, $day)
                        || ($row['evidence_type'] ?? '') !== 'strict_readback_fact' || $refs === []
                        || count(array_filter($refs, static fn($ref): bool => !is_string($ref) || !preg_match('/^online_daily_data#[1-9][0-9]*$/', $ref))) > 0
                        || ($m['currency'] ?? '') !== 'CNY' || ($m['basis_status'] ?? '') !== 'aligned'
                        || !$this->marketingAmountValid($m['spend'] ?? null) || !$this->marketingAmountValid($m['attributed_order_amount'] ?? null)) { $valid = false; break; }
                    $rowBasis = trim((string)($m['spend_basis'] ?? ''));
                    if ($rowBasis === '' || ($dayBasis !== null && $dayBasis !== $rowBasis) || ($basis !== null && $basis !== $rowBasis)) { $valid = false; break; }
                    $dayBasis = $rowBasis; $daySpent += (float)$m['spend']; $dayAmount += (float)$m['attributed_order_amount'];
                    $dayRefs = array_merge($dayRefs, $refs);
                }
                if ($valid) {
                    $basis = $dayBasis; $spent += $daySpent; $amount += $dayAmount;
                    if (!$this->marketingAmountValid($spent) || !$this->marketingAmountValid($amount)) throw new InvalidArgumentException('channel_calculated_amount_out_of_range');
                    $marketing['covered_days'][] = $day;
                    $marketing['evidence_refs'] = array_values(array_unique(array_merge($marketing['evidence_refs'], $dayRefs)));
                }
                else $marketing['missing_days'][] = $day;
            }
            $marketing['complete'] = $marketing['missing_days'] === [];
            $hasCoveredDay = $marketing['covered_days'] !== [];
            $marketing['known_advertising_spend'] = $hasCoveredDay ? round($spent, 2) : null;
            $marketing['known_attributed_order_amount'] = $hasCoveredDay ? round($amount, 2) : null;
            if ($marketing['complete']) { $marketing['advertising_spend'] = round($spent, 2); $marketing['attributed_order_amount'] = round($amount, 2); }
            $marketing['attribution_basis'] = $basis;
        } else $marketing['reason'] = 'ctrip_marketing_period_requires_manual_evidence';
        return ['settlement' => $settlement, 'marketing' => $marketing,
            'scope' => ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'platform' => $platform, 'period_month' => $month],
            'period_closed' => $start->modify('first day of next month') <= new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'))];
    }
    private function marketingScopeMatches(array $scope, int $tenant, int $hotel, string $day): bool
    {
        return ($scope['tenant_id'] ?? null) === $tenant && ($scope['hotel_id'] ?? null) === $hotel
            && ($scope['platform'] ?? '') === 'meituan' && ($scope['business_date'] ?? '') === $day;
    }
    private function compactSettlementReceipt(array $receipt): array
    {
        // The batch fingerprint already binds its immutable lines. A monthly
        // economics snapshot needs that receipt, not a second copy of every line.
        $compact = array_intersect_key($receipt, array_flip(['contract_version', 'batch_id', 'supersedes_batch_id', 'supersession_reason',
            'batch_fingerprint', 'batch_status', 'read_status', 'readback_verified', 'scope', 'source', 'counts', 'totals',
            'basis_ledger', 'projection_status', 'latest_attempt', 'authorization']));
        $batchId = $receipt['batch_id'] ?? null;
        $compact['evidence_refs'] = is_int($batchId) && $batchId > 0 ? ['ota_settlement_import_batches#' . $batchId] : [];
        $compact['receipt_format'] = 'settlement_batch_summary.v1';
        return $compact;
    }
    private function marketingAmountValid(mixed $value): bool
    {
        return !is_bool($value) && is_numeric($value) && is_finite((float)$value) && (float)$value >= 0 && (float)$value <= 1e12;
    }
    private function number(mixed $v, bool $signed = false): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v) || !is_numeric($v) || !is_finite((float)$v) || abs((float)$v) > 1e12 || (!$signed && (float)$v < 0)) throw new InvalidArgumentException('channel_number_invalid');
        return (float)$v;
    }
    private function text(mixed $v, int $limit): string { if (!is_scalar($v) || is_bool($v) || mb_strlen((string)$v) > $limit) throw new InvalidArgumentException('channel_text_invalid'); return trim((string)$v); }
}
