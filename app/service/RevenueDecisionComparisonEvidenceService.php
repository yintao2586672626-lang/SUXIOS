<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;

/** Binds comparison inputs, including unavailable inputs, without changing saved history. */
final class RevenueDecisionComparisonEvidenceService
{
    public function freeze(array $comparisons, int $tenantId, int $hotelId, string $date, string $platform): array
    {
        if (($comparisons['current_read_failed'] ?? false) === true) {
            throw new RuntimeException('revenue_comparison_current_read_failed', 409);
        }
        $baselines = [];
        foreach (['previous', 'same_weekday'] as $basis) {
            $baselineDate = (string)($comparisons[$basis . '_date'] ?? '');
            $overview = (array)($comparisons[$basis . '_overview'] ?? []);
            if ($baselineDate === '' && $overview === []) {
                $baselines[$basis] = ['business_date' => '', 'status' => 'unavailable'];
                continue;
            }
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $baselineDate);
            if (!$parsedDate || $parsedDate->format('Y-m-d') !== $baselineDate || $baselineDate >= $date
                || ($basis === 'same_weekday' && (new \DateTimeImmutable($baselineDate))->format('w')
                    !== (new \DateTimeImmutable($date))->format('w'))) {
                throw new RuntimeException('revenue_comparison_baseline_scope_invalid', 409);
            }
            if ($overview === []) {
                $baselines[$basis] = ['business_date' => $baselineDate, 'status' => 'unavailable'];
                continue;
            }
            $layer = (array)($overview['three_source_fact_layer'] ?? []);
            $hotel = (array)($layer['hotel'] ?? []);
            $strict = (array)($overview['cockpit_strict_evidence'] ?? []);
            $closure = (array)($overview['dual_ota_field_closure'] ?? []);
            if ((int)($hotel['tenant_id'] ?? 0) !== $tenantId
                || (int)($hotel['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($overview['hotel_id'] ?? 0) !== $hotelId
                || (string)($layer['business_date'] ?? '') !== $baselineDate
                || (string)($overview['business_date'] ?? '') !== $baselineDate
                || (string)($strict['contract_version'] ?? '') !== 'revenue_cockpit_strict_evidence.v1'
                || (int)($strict['tenant_id'] ?? 0) !== $tenantId
                || (int)($strict['hotel_id'] ?? 0) !== $hotelId
                || (string)($strict['business_date'] ?? '') !== $baselineDate
                || (isset($strict['platform']) && (string)$strict['platform'] !== $platform)) {
                throw new RuntimeException('revenue_comparison_baseline_identity_invalid', 409);
            }
            $platforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
            $sources = [];
            $pmsSelection = (new RevenuePmsFactSelectorService())->select($layer);
            $pmsKey = (string)$pmsSelection['source_key'];
            foreach (array_merge([$pmsKey], array_map(static fn($p) => $p . '_ota', $platforms)) as $key) {
                $source = $key === $pmsKey ? (array)$pmsSelection['source'] : (array)($layer['sources'][$key] ?? []);
                if ($key === $pmsKey) {
                    if ((array)$pmsSelection['facts'] !== []) $source['facts'] = (array)$pmsSelection['facts'];
                    if ((string)$pmsSelection['data_status'] !== (string)($source['data_status'] ?? 'missing')) {
                        $source['data_status'] = (string)$pmsSelection['data_status'];
                    }
                }
                // Only inputs consumed by the original metric/comparison cards; never raw captures.
                $sources[$key] = array_intersect_key($source, array_flip([
                    'facts', 'fact_statuses', 'provenance', 'source', 'data_status', 'schema_status',
                    'business_date', 'actual_business_date', 'source_kind',
                ]));
            }
            $identity = ['sources' => $sources];
            if ($pmsKey !== 'dingdandao_pms' || (array)$pmsSelection['binding'] !== []) {
                $identity['pms_selection'] = ['source_key' => $pmsKey, 'binding' => (array)$pmsSelection['binding']];
            }
            foreach ($platforms as $p) {
                $identity['strict'][$p] = (array)($strict['platforms'][$p] ?? []);
                $identity['receipts'][$p] = (array)($closure['platforms'][$p] ?? []);
            }
            $baselines[$basis] = [
                'business_date' => $baselineDate, 'status' => 'present',
                'input_digest' => $this->digest($identity),
            ];
        }
        return ['contract_version' => 'revenue_comparison_evidence.v1',
            'scope' => ['tenant_id' => $tenantId, 'hotel_id' => $hotelId, 'business_date' => $date, 'platform' => $platform],
            'baselines' => $baselines];
    }

    /** A null result means unchanged; unavailable current inputs never attest themselves. */
    public function currentnessStatus(array $snapshot, ?array $comparisons = null, ?callable $reader = null): ?string
    {
        $model = (array)($snapshot['visible_model'] ?? []);
        $saved = $snapshot['evidence_summary']['comparison_evidence'] ?? null;
        $hasBaseline = (string)($model['previousDate'] ?? '') !== '' || (string)($model['sameWeekdayDate'] ?? '') !== '';
        if (!is_array($saved)) {
            if ($hasBaseline) return 'comparison_currentness_not_verified';
            if ($comparisons === null) return null; // Compatibility: old snapshots with no baseline.
        }
        try {
            $tenant = (int)$snapshot['tenant_id']; $hotel = (int)$snapshot['system_hotel_id'];
            $date = (string)$snapshot['business_date']; $platform = (string)$snapshot['platform'];
            if ($comparisons === null) {
                $comparisons = (new RevenueCockpitIntentProvenanceService($reader))
                    ->comparisonContextsForModel($model, $tenant, $hotel, $platform);
            }
            $current = $this->freeze($comparisons, $tenant, $hotel, $date, $platform);
            $saved ??= $this->freeze([], $tenant, $hotel, $date, $platform);
            return hash_equals($this->digest($saved), $this->digest($current))
                ? null : 'stale_current_comparison';
        } catch (Throwable) {
            return 'comparison_currentness_not_verified';
        }
    }

    private function digest(array $value): string
    {
        return (new RevenueDecisionViewModelAttestationService())->issuedDigest($value);
    }
}
