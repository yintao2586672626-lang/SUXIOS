<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Same-month channel economics; attributed sales do not establish incremental effects. */
final class ChannelEconomicsService
{
    public function calculate(array $input, array $sources = []): array
    {
        $net = $this->number($input['net_revenue'] ?? null, true);
        $spend = $this->number($input['advertising_spend'] ?? null);
        $attributed = $this->number($input['attributed_order_amount'] ?? null);
        $sourceRefs = $input['source_refs'] ?? [];
        if (!is_array($sourceRefs) || count($sourceRefs) > 100) throw new InvalidArgumentException('channel_source_refs_invalid');
        $sourceRefs = array_values(array_unique(array_filter(
            array_map(fn($ref): string => $this->text($ref, 500), $sourceRefs), static fn(string $ref): bool => $ref !== ''
        )));
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
        $rows = []; $knownCosts = 0.0; $missing = []; $costsKnown = true;
        foreach ($costs as $raw) {
            if (!is_array($raw) || !is_bool($raw['included_in_net_revenue'] ?? null)) throw new InvalidArgumentException('channel_cost_inclusion_required');
            $row = ['label' => $this->text($raw['label'] ?? '', 160), 'amount' => $this->number($raw['amount'] ?? null),
                'included_in_net_revenue' => $raw['included_in_net_revenue'], 'source_ref' => $this->text($raw['source_ref'] ?? '', 500)];
            if (!$row['included_in_net_revenue']) {
                if ($row['amount'] === null || $row['source_ref'] === '') { $costsKnown = false; $missing[] = 'cost:' . $row['label']; }
                else $knownCosts += $row['amount'];
            }
            $rows[] = $row;
        }
        // Advertising is a separate explicit deduction unless already included or in direct costs.
        $adIncluded = $input['advertising_included_in_net_revenue'] ?? null;
        $adInCosts = $input['advertising_in_direct_costs'] ?? null;
        if (!is_bool($adIncluded) || !is_bool($adInCosts) || ($adIncluded && $adInCosts)) throw new InvalidArgumentException('channel_advertising_deduction_basis_required');
        if (!$adIncluded && !$adInCosts) {
            if ($spend === null) { $costsKnown = false; $missing[] = 'advertising_spend_missing'; }
            else $knownCosts += $spend;
        }
        $coverage = ($input['cost_coverage_complete'] ?? false) === true;
        if (!$coverage) $missing[] = 'direct_cost_coverage_incomplete';
        if ($net === null) $missing[] = 'net_revenue_missing';
        if ($orders === null) $missing[] = 'effective_order_amount_missing';
        if ($refund === null) $missing[] = 'refund_amount_missing';
        if ($sourceRefs === []) $missing[] = 'manual_source_refs_missing';
        $knownContribution = $net !== null ? round($net - $knownCosts, 2) : null;
        if (!is_finite($knownCosts) || abs($knownCosts) > 1e12 || ($knownContribution !== null && (!is_finite($knownContribution) || abs($knownContribution) > 1e12))) throw new InvalidArgumentException('channel_calculated_amount_out_of_range');
        $roas = $spend !== null && $spend > 0 && $attributed !== null && $basis !== '' ? round($attributed / $spend, 6) : null;
        if ($roas !== null && (!is_finite($roas) || abs($roas) > 1e12)) throw new InvalidArgumentException('channel_calculated_roas_out_of_range');
        $fullContribution = $coverage && $costsKnown && $net !== null && $sourceRefs !== [] ? $knownContribution : null;
        $attested = ($input['operator_attested'] ?? false) === true;
        $inputs = ['net_revenue' => $net, 'advertising_spend' => $spend, 'attributed_order_amount' => $attributed,
            'effective_order_amount' => $orders, 'refund_amount' => $refund, 'attribution_basis' => $basis,
            'advertising_included_in_net_revenue' => $adIncluded, 'advertising_in_direct_costs' => $adInCosts,
            'cost_coverage_complete' => $coverage, 'operator_attested' => $attested, 'source_refs' => $sourceRefs, 'costs' => $rows];
        return ['status' => $fullContribution !== null && $missing === [] ? 'calculated' : 'partial', 'source_quality' => $attested ? 'operator_attested' : 'unverified',
            'inputs' => $inputs, 'currency' => 'CNY', 'net_revenue' => $net, 'known_direct_cost' => round($knownCosts, 2),
            'channel_net_contribution_amount' => $fullContribution, 'known_costs_contribution_amount' => $knownContribution,
            'attributed_roas' => $roas,
            'order_to_settlement_difference' => $orders !== null && $net !== null ? round($orders - $net, 2) : null,
            'missing_items' => array_values(array_unique($missing)), 'source_receipts' => $sources,
            'input_origins' => ['net_revenue' => $settlementUsed ? 'saved_same_scope_settlement' : 'manual_input', 'marketing' => ($sources['marketing']['complete'] ?? false) === true ? 'saved_complete_same_basis_month' : 'manual_input'],
            'formulas' => ['channel_net_contribution_amount' => 'verified_or_manual_net_revenue - not_already_included_direct_costs',
                'attributed_roas' => 'same_attribution_order_amount / advertising_spend'],
            'boundaries' => ['metric_scope' => 'ota_channel', 'whole_hotel_profit' => false, 'incremental_ad_effect_established' => false,
                'net_channel_contribution_existing_metric_is_share_percent' => true, 'automatic_operating_action' => false]];
    }
    public function sourceReceipts(int $tenant, int $hotel, string $platform, string $month): array
    {
        $start = new DateTimeImmutable($month . '-01', new \DateTimeZone('Asia/Shanghai'));
        $settlement = (new OtaSettlementReconciliationService())->latestForScope($tenant, $hotel, $platform, $start->format('Y-m-d'), $start->format('Y-m-t'));
        $marketing = ['complete' => false, 'covered_days' => [], 'missing_days' => [], 'advertising_spend' => null, 'attributed_order_amount' => null, 'evidence_refs' => []];
        if ($platform === 'meituan') {
            $spent = 0.0; $amount = 0.0; $basis = null;
            for ($date = $start; $date <= $start->modify('last day of this month'); $date = $date->modify('+1 day')) {
                $day = $date->format('Y-m-d'); $projection = (new MeituanMarketingFactProjectionService())->project($tenant, $hotel, $day);
                $ads = array_values(array_filter($projection['projections'] ?? [], static fn(array $r): bool => ($r['fact_type'] ?? '') === 'advertising' && ($r['scope']['object_type'] ?? '') !== 'keyword'));
                $valid = $ads !== [] && ($projection['status'] ?? '') === 'ready';
                $daySpent = 0.0; $dayAmount = 0.0;
                foreach ($ads as $row) {
                    $m = $row['metrics'];
                    if ($m['spend'] === null || $m['attributed_order_amount'] === null || ($m['basis_status'] ?? '') !== 'aligned') { $valid = false; break; }
                    $rowBasis = (string)$m['spend_basis'];
                    if ($basis !== null && $basis !== $rowBasis) { $valid = false; break; }
                    $basis = $rowBasis; $daySpent += $m['spend']; $dayAmount += $m['attributed_order_amount'];
                    $marketing['evidence_refs'] = array_merge($marketing['evidence_refs'], $row['evidence_refs'] ?? []);
                }
                if ($valid) { $spent += $daySpent; $amount += $dayAmount; $marketing['covered_days'][] = $day; }
                else $marketing['missing_days'][] = $day;
            }
            $marketing['complete'] = $marketing['missing_days'] === [];
            $marketing['known_advertising_spend'] = round($spent, 2); $marketing['known_attributed_order_amount'] = round($amount, 2);
            if ($marketing['complete']) { $marketing['advertising_spend'] = round($spent, 2); $marketing['attributed_order_amount'] = round($amount, 2); }
            $marketing['attribution_basis'] = $basis;
        } else $marketing['reason'] = 'ctrip_marketing_period_requires_manual_evidence';
        return ['settlement' => $settlement, 'marketing' => $marketing];
    }
    private function number(mixed $v, bool $signed = false): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v) || !is_numeric($v) || !is_finite((float)$v) || abs((float)$v) > 1e12 || (!$signed && (float)$v < 0)) throw new InvalidArgumentException('channel_number_invalid');
        return (float)$v;
    }
    private function text(mixed $v, int $limit): string { if (!is_scalar($v) || is_bool($v) || mb_strlen((string)$v) > $limit) throw new InvalidArgumentException('channel_text_invalid'); return trim((string)$v); }
}
