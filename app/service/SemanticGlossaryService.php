<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * Read-only runtime projection of the versioned semantic glossary pack.
 *
 * The pack is recognition, routing and metric-contract data. It never turns a
 * term or a document into a hotel fact and it never grants an external write.
 */
final class SemanticGlossaryService
{
    public const CONTRACT_VERSION = 'suxios.semantic_glossary.runtime.v1';

    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    public function __construct(private ?string $packPath = null)
    {
        $this->packPath ??= dirname(__DIR__, 2)
            . '/docs/knowledge/semantic-glossary/semantic-glossary-pack.json';
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        $index = $this->index();
        $pack = $index['pack'];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'available',
            'pack_key' => (string)$pack['pack_key'],
            'glossary_version' => (string)$pack['glossary_version'],
            'source_term_count' => (int)$pack['summary']['source_term_count'],
            'recognition_term_count' => (int)$pack['summary']['recognition_term_count'],
            'concept_count' => (int)$pack['summary']['concept_count'],
            'source_sha256' => (string)$pack['source']['current_csv_sha256'],
            'pack_sha256' => (string)$index['pack_sha256'],
            'category_counts' => (array)$pack['summary']['category_counts'],
            'boundary' => (array)$pack['boundary'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function concepts(): array
    {
        return array_values($this->index()['concepts']);
    }

    /**
     * Resolve one natural-language query to the pack's canonical concepts.
     * Explicit platform input always wins; a contradictory platform mention
     * fails closed instead of silently switching the fact scope.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $query, string $platform = ''): array
    {
        $query = mb_substr(trim($query), 0, 1000);
        $requestedPlatform = $this->normalizePlatform($platform);
        if ($query === '') {
            return $this->resolution('no_match', $query, $requestedPlatform, '', [], 'empty_query');
        }

        $index = $this->index();
        $normalizedQuery = self::normalize($query);
        $detectedPlatforms = $this->detectedPlatforms($query, $index['concepts']);
        $detectedPlatform = count($detectedPlatforms) === 1 ? $detectedPlatforms[0] : '';
        if ($requestedPlatform !== ''
            && $requestedPlatform !== 'all_ota'
            && $detectedPlatforms !== []
            && !in_array($requestedPlatform, $detectedPlatforms, true)
        ) {
            return $this->resolution(
                'scope_conflict',
                $query,
                $requestedPlatform,
                $detectedPlatform,
                [],
                'query_platform_conflicts_with_explicit_scope'
            );
        }
        $effectivePlatform = $requestedPlatform !== '' ? $requestedPlatform : $detectedPlatform;

        $matches = [];
        foreach ((array)($index['exact'][$normalizedQuery] ?? []) as $entry) {
            $matches[] = $entry;
        }
        if ($matches === []) {
            foreach ($index['search_entries'] as $entry) {
                $needle = (string)$entry['normalized_term'];
                if ($needle === '' || !$this->queryContains($query, $normalizedQuery, $needle, (string)$entry['term'])) {
                    continue;
                }
                $matches[] = $entry;
            }
        }
        if ($matches === []) {
            return $this->resolution('no_match', $query, $requestedPlatform, $detectedPlatform, [], 'term_not_found');
        }

        $maxLength = max(array_map(static fn(array $item): int => (int)$item['length'], $matches));
        $matches = array_values(array_filter(
            $matches,
            static fn(array $item): bool => (int)$item['length'] === $maxLength
        ));
        $candidateKeys = [];
        foreach ($matches as $entry) {
            $concept = $index['concepts'][(string)$entry['concept_key']] ?? null;
            if (!is_array($concept) || !$this->conceptMatchesPlatform($concept, $effectivePlatform)) {
                continue;
            }
            $key = (string)$concept['concept_key'];
            if (!isset($candidateKeys[$key])) {
                $candidateKeys[$key] = $this->publicConcept($concept, $entry, $effectivePlatform);
            }
        }
        $candidates = array_values($candidateKeys);
        if ($candidates === []) {
            return $this->resolution(
                'platform_mismatch',
                $query,
                $requestedPlatform,
                $detectedPlatform,
                [],
                'matched_term_not_applicable_to_platform'
            );
        }
        if (count($candidates) > 1) {
            $metricCandidates = array_values(array_filter(
                $candidates,
                static fn(array $item): bool => ($item['is_business_metric'] ?? false) === true
            ));
            if (count($metricCandidates) === 1) {
                $candidates = $metricCandidates;
            }
        }
        if (count($candidates) > 1) {
            $platformSets = array_values(array_unique(array_map(
                static fn(array $item): string => implode(',', (array)$item['platforms']),
                $candidates
            )));
            $status = $effectivePlatform === '' && count($platformSets) > 1
                ? 'ambiguous_platform'
                : 'ambiguous_concept';
            return $this->resolution($status, $query, $requestedPlatform, $detectedPlatform, $candidates, 'multiple_semantic_candidates');
        }

        return $this->resolution('matched', $query, $requestedPlatform, $detectedPlatform, $candidates, '');
    }

    /**
     * Resolve every non-overlapping business metric mentioned in one query.
     * Platform words determine scope and are not returned as competing metric
     * concepts. A short alias nested only inside a longer metric term is also
     * suppressed (for example 曝光 inside 曝光到访率).
     *
     * @return array<string,mixed>
     */
    public function resolveMetrics(string $query, string $platform = ''): array
    {
        $query = mb_substr(trim($query), 0, 1000);
        $requestedPlatform = $this->normalizePlatform($platform);
        if ($query === '') {
            return $this->metricResolution('no_match', $query, $requestedPlatform, '', [], 'empty_query');
        }

        $index = $this->index();
        $normalizedQuery = self::normalize($query);
        $detectedPlatforms = $this->detectedPlatforms($query, $index['concepts']);
        $detectedPlatform = count($detectedPlatforms) === 1 ? $detectedPlatforms[0] : '';
        if ($requestedPlatform !== ''
            && $requestedPlatform !== 'all_ota'
            && $detectedPlatforms !== []
            && !in_array($requestedPlatform, $detectedPlatforms, true)
        ) {
            return $this->metricResolution(
                'scope_conflict',
                $query,
                $requestedPlatform,
                $detectedPlatform,
                [],
                'query_platform_conflicts_with_explicit_scope'
            );
        }
        $effectivePlatform = $requestedPlatform !== '' ? $requestedPlatform : $detectedPlatform;

        $matchedByConcept = [];
        foreach ($index['search_entries'] as $entry) {
            $concept = $index['concepts'][(string)$entry['concept_key']] ?? null;
            if (!is_array($concept)
                || ($concept['is_business_metric'] ?? false) !== true
                || !$this->conceptMatchesPlatform($concept, $effectivePlatform)
            ) {
                continue;
            }
            $needle = (string)$entry['normalized_term'];
            if ($needle === '') {
                continue;
            }
            $positions = $this->allPositions($normalizedQuery, $needle);
            if ($positions === []) {
                continue;
            }
            $key = (string)$concept['concept_key'];
            $length = (int)$entry['length'];
            $currentLength = (int)($matchedByConcept[$key]['length'] ?? 0);
            if ($length < $currentLength) {
                continue;
            }
            $spans = array_map(
                static fn(int $start): array => ['start' => $start, 'end' => $start + $length, 'length' => $length],
                $positions
            );
            if ($length > $currentLength) {
                $matchedByConcept[$key] = [
                    'length' => $length,
                    'entry' => $entry,
                    'concept' => $concept,
                    'spans' => $spans,
                ];
            } else {
                $matchedByConcept[$key]['spans'] = array_merge(
                    (array)($matchedByConcept[$key]['spans'] ?? []),
                    $spans
                );
            }
        }

        if ($matchedByConcept === []) {
            return $this->metricResolution('no_match', $query, $requestedPlatform, $detectedPlatform, [], 'metric_term_not_found');
        }

        $matches = array_values($matchedByConcept);
        $matches = array_values(array_filter($matches, static function (array $candidate) use ($matches): bool {
            foreach ((array)$candidate['spans'] as $span) {
                $contained = false;
                foreach ($matches as $other) {
                    if ((string)$other['concept']['concept_key'] === (string)$candidate['concept']['concept_key']) {
                        continue;
                    }
                    foreach ((array)$other['spans'] as $otherSpan) {
                        if ((int)$otherSpan['length'] > (int)$span['length']
                            && (int)$otherSpan['start'] <= (int)$span['start']
                            && (int)$otherSpan['end'] >= (int)$span['end']
                        ) {
                            $contained = true;
                            break 2;
                        }
                    }
                }
                if (!$contained) {
                    return true;
                }
            }
            return false;
        }));
        usort($matches, static function (array $left, array $right): int {
            $leftStart = min(array_column((array)$left['spans'], 'start'));
            $rightStart = min(array_column((array)$right['spans'], 'start'));
            return $leftStart <=> $rightStart ?: (int)$right['length'] <=> (int)$left['length'];
        });

        $metrics = [];
        foreach ($matches as $match) {
            $public = $this->publicConcept(
                (array)$match['concept'],
                (array)$match['entry'],
                $effectivePlatform
            );
            $public['match_spans'] = array_values((array)$match['spans']);
            $metrics[] = $public;
        }
        if ($effectivePlatform === '' && $metrics !== []) {
            return $this->metricResolution(
                'ambiguous_platform',
                $query,
                $requestedPlatform,
                $detectedPlatform,
                $metrics,
                'platform_required_for_metric_readback'
            );
        }
        return $this->metricResolution(
            count($metrics) > 1 ? 'matched_multi' : 'matched',
            $query,
            $requestedPlatform,
            $detectedPlatform,
            $metrics,
            ''
        );
    }

    /**
     * Resolve every requested metric independently against the same strict
     * fact packet. One missing metric yields partial, not a fabricated value
     * and not a blanket failure for the other requested metrics.
     *
     * @param array<string,mixed> $resolution
     * @param list<array<string,mixed>> $facts
     * @return array<string,mixed>
     */
    public function metricReadbacks(array $resolution, array $facts): array
    {
        $metrics = array_values(array_filter(
            is_array($resolution['metrics'] ?? null) ? $resolution['metrics'] : [],
            'is_array'
        ));
        $base = [
            'contract_version' => 'suxios.semantic_metric_readback_set.v1',
            'status' => 'blocked_by_semantic_resolution',
            'items' => [],
            'used_evidence_refs' => [],
            'data_gaps' => [],
            'decision_safe' => false,
            'external_write_authorized' => false,
        ];
        if (!in_array((string)($resolution['status'] ?? ''), ['matched', 'matched_multi'], true) || $metrics === []) {
            return $base;
        }

        $successCount = 0;
        foreach ($metrics as $metric) {
            $single = [
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'matched',
                'query' => (string)($resolution['query'] ?? ''),
                'normalized_query' => (string)($resolution['normalized_query'] ?? ''),
                'requested_platform' => $resolution['requested_platform'] ?? null,
                'detected_platform' => $resolution['detected_platform'] ?? null,
                'effective_platform' => $resolution['effective_platform'] ?? null,
                'primary' => $metric,
                'candidates' => [$metric],
                'reason' => '',
                'decision_safe' => false,
                'external_write_authorized' => false,
            ];
            $readback = $this->metricReadback($single, $facts);
            if (in_array((string)($readback['status'] ?? ''), ['readback_verified', 'calculated_from_same_fact_scope'], true)) {
                $successCount++;
            }
            $base['items'][] = [
                'semantic' => $metric,
                'readback' => $readback,
            ];
            $base['used_evidence_refs'] = array_merge(
                $base['used_evidence_refs'],
                array_map('strval', (array)($readback['used_evidence_refs'] ?? []))
            );
            foreach ((array)($readback['data_gaps'] ?? []) as $gap) {
                if (is_array($gap)) {
                    $base['data_gaps'][] = $gap + [
                        'metric_key' => $metric['metric_key'] ?? null,
                        'canonical_term' => $metric['canonical_term'] ?? null,
                    ];
                }
            }
        }
        $base['used_evidence_refs'] = array_values(array_unique($base['used_evidence_refs']));
        $base['status'] = $successCount === count($metrics)
            ? 'readback_verified'
            : ($successCount > 0 ? 'partial' : 'blocked_by_missing_metrics');
        return $base;
    }

    /**
     * Add only curated, server-owned aliases to the existing feature catalog.
     * Page and action values continue to come from the catalog, never the pack.
     *
     * @param array<string,array<string,mixed>> $catalog
     * @return array<string,array<string,mixed>>
     */
    public function augmentFeatureCatalog(array $catalog): array
    {
        foreach ($this->index()['concepts'] as $concept) {
            $topicKey = trim((string)($concept['assistant_topic_key'] ?? ''));
            if ($topicKey === '' || !isset($catalog[$topicKey])) {
                continue;
            }
            $terms = array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                [
                    $concept['canonical_term'] ?? '',
                    ...(array)($concept['aliases'] ?? []),
                    ...(array)($concept['voice_aliases'] ?? []),
                    ...(array)($concept['navigation_terms'] ?? []),
                ]
            ))));
            $keywords = array_values(array_unique(array_merge(
                array_map('strval', (array)($catalog[$topicKey]['keywords'] ?? [])),
                $terms
            )));
            $catalog[$topicKey]['keywords'] = array_slice($keywords, 0, 80);
        }
        return $catalog;
    }

    /**
     * Read only the already accepted strict fact packet. No database access is
     * performed here; references cannot escape the caller-provided packet.
     *
     * @param array<string,mixed> $resolution
     * @param list<array<string,mixed>> $facts
     * @return array<string,mixed>
     */
    public function metricReadback(array $resolution, array $facts): array
    {
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $metricKey = trim((string)($primary['metric_key'] ?? ''));
        $platform = $this->normalizePlatform((string)($resolution['effective_platform'] ?? ''));
        $base = [
            'contract_version' => 'suxios.semantic_metric_readback.v1',
            'status' => 'not_applicable',
            'canonical_term' => (string)($primary['canonical_term'] ?? ''),
            'metric_key' => $metricKey !== '' ? $metricKey : null,
            'platform' => $platform !== '' ? $platform : null,
            'values' => [],
            'used_evidence_refs' => [],
            'data_gaps' => [],
            'decision_safe' => false,
            'external_write_authorized' => false,
        ];
        if (($resolution['status'] ?? '') !== 'matched' || $metricKey === '') {
            $base['status'] = ($resolution['status'] ?? '') === 'matched' ? 'not_a_metric' : 'blocked_by_semantic_resolution';
            return $base;
        }
        if ($platform === '' || $platform === 'all_ota') {
            $base['status'] = 'blocked_by_platform_scope';
            $base['data_gaps'][] = ['code' => 'single_platform_required_for_precise_metric'];
            return $base;
        }

        $concept = $this->index()['concepts'][(string)($primary['concept_key'] ?? '')] ?? [];
        if (!is_array($concept)) {
            $base['status'] = 'blocked_by_semantic_resolution';
            return $base;
        }
        $calculation = is_array($concept['calculation_contract'] ?? null) ? $concept['calculation_contract'] : [];
        if ($metricKey === 'adr') {
            return $this->adrReadback($base, $calculation, $facts, $platform);
        }
        if ($metricKey === 'exposure_to_visit_rate') {
            return $this->exposureToVisitReadback($base, $concept, $facts, $platform);
        }

        $mappings = is_array($concept['platform_metric_mappings'] ?? null)
            ? $concept['platform_metric_mappings']
            : [];
        $mapping = is_array($mappings[$platform] ?? null) ? $mappings[$platform] : [];
        if (($mapping['status'] ?? '') !== 'mapped_with_same_scope_fact_required') {
            $base['status'] = 'blocked_by_source_contract';
            $base['data_gaps'][] = [
                'code' => 'platform_metric_source_contract_required',
                'reason' => (string)($mapping['reason'] ?? 'metric_mapping_not_verified_for_platform'),
            ];
            return $base;
        }
        $storageFields = array_values(array_filter(array_map('strval', (array)($mapping['storage_fields'] ?? []))));
        $dataTypes = array_values(array_filter(array_map('strval', (array)($mapping['data_types'] ?? []))));
        foreach ($facts as $fact) {
            if ($this->factPlatform($fact) !== $platform || !$this->factIsStrict($fact)) {
                continue;
            }
            if ($dataTypes !== [] && !in_array((string)($fact['data_type'] ?? ''), $dataTypes, true)) {
                continue;
            }
            $metricValues = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            foreach ($storageFields as $field) {
                $value = $metricValues[$field] ?? null;
                if (!is_numeric($value) || !$this->factMetricProvenanceMatches($fact, $field, $mapping)) {
                    continue;
                }
                $ref = trim((string)($fact['ref'] ?? ''));
                $base['values'][] = [
                    'date' => (string)($fact['data_date'] ?? ''),
                    'value' => $value + 0,
                    'unit' => (string)($mapping['unit'] ?? ($fact['metric_units'][$field] ?? 'source_defined_value')),
                    'storage_field' => $field,
                    'source_paths' => $this->metricSourcePaths($fact, $field),
                    'evidence_ref' => $ref,
                    'collected_at' => $fact['collected_at'] ?? null,
                    'verification_status' => $this->factQualityStatus($fact),
                    'readback_status' => (string)($fact['readback_status'] ?? 'not_verified'),
                    'source_trace_id' => (string)($fact['source_trace_id'] ?? ''),
                ];
                if ($ref !== '') {
                    $base['used_evidence_refs'][] = $ref;
                }
            }
        }
        $base['used_evidence_refs'] = array_values(array_unique($base['used_evidence_refs']));
        if ($base['values'] === []) {
            $base['status'] = 'blocked_by_missing_metric';
            $base['data_gaps'][] = ['code' => 'same_scope_verified_metric_missing'];
            return $base;
        }
        $base['status'] = 'readback_verified';
        return $base;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $concept @param list<array<string,mixed>> $facts @return array<string,mixed> */
    private function exposureToVisitReadback(array $base, array $concept, array $facts, string $platform): array
    {
        $mappings = is_array($concept['platform_metric_mappings'] ?? null)
            ? $concept['platform_metric_mappings']
            : [];
        $mapping = is_array($mappings[$platform] ?? null) ? $mappings[$platform] : [];
        if (($mapping['status'] ?? '') !== 'mapped_with_same_scope_fact_required') {
            $base['status'] = 'blocked_by_source_contract';
            $base['data_gaps'][] = ['code' => 'platform_metric_source_contract_required'];
            return $base;
        }
        $exposureMapping = is_array($mapping['inputs']['exposure_users'] ?? null)
            ? $mapping['inputs']['exposure_users']
            : [];
        $visitorMapping = is_array($mapping['inputs']['detail_visitors'] ?? null)
            ? $mapping['inputs']['detail_visitors']
            : [];
        foreach ($facts as $fact) {
            if ($this->factPlatform($fact) !== $platform || !$this->factIsStrict($fact)) {
                continue;
            }
            $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $exposureField = (string)($exposureMapping['storage_field'] ?? '');
            $visitorField = (string)($visitorMapping['storage_field'] ?? '');
            $exposure = $values[$exposureField] ?? null;
            $visitors = $values[$visitorField] ?? null;
            if ($exposureField === '' || $visitorField === ''
                || !is_numeric($exposure) || !is_numeric($visitors) || (float)$exposure <= 0.0
                || !$this->factMetricProvenanceMatches($fact, $exposureField, $exposureMapping)
                || !$this->factMetricProvenanceMatches($fact, $visitorField, $visitorMapping)
            ) {
                continue;
            }
            $value = round((float)$visitors / (float)$exposure * 100, 2);
            $ref = trim((string)($fact['ref'] ?? ''));
            $base['values'][] = [
                'date' => (string)($fact['data_date'] ?? ''),
                'value' => $value,
                'unit' => 'percent',
                'formula' => 'detail_visitors / exposure_users * 100',
                'inputs' => [
                    'detail_visitors' => 0 + $visitors,
                    'exposure_users' => 0 + $exposure,
                ],
                'source_paths' => array_values(array_unique(array_merge(
                    $this->metricSourcePaths($fact, $exposureField),
                    $this->metricSourcePaths($fact, $visitorField)
                ))),
                'evidence_ref' => $ref,
                'collected_at' => $fact['collected_at'] ?? null,
                'verification_status' => $this->factQualityStatus($fact),
                'readback_status' => (string)($fact['readback_status'] ?? 'not_verified'),
                'source_trace_id' => (string)($fact['source_trace_id'] ?? ''),
            ];
            if ($ref !== '') {
                $base['used_evidence_refs'][] = $ref;
            }
        }
        $base['used_evidence_refs'] = array_values(array_unique($base['used_evidence_refs']));
        if ($base['values'] === []) {
            $base['status'] = 'not_computable';
            $base['data_gaps'][] = [
                'code' => 'aligned_exposure_users_or_detail_visitors_missing',
                'required_inputs' => ['exposure_users', 'detail_visitors'],
            ];
            return $base;
        }
        $base['status'] = 'calculated_from_same_fact_scope';
        return $base;
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $contract @param list<array<string,mixed>> $facts @return array<string,mixed> */
    private function adrReadback(array $base, array $contract, array $facts, string $platform): array
    {
        // ADR requires explicit room_revenue. Generic amount/GMV/settlement is
        // intentionally rejected even if an older source described it loosely.
        $revenueFields = ['room_revenue'];
        $roomNightFields = array_values(array_filter(array_map(
            'strval',
            (array)($contract['accepted_room_night_fields'] ?? ['quantity'])
        )));
        foreach ($facts as $fact) {
            if ($this->factPlatform($fact) !== $platform || !$this->factIsStrict($fact)) {
                continue;
            }
            $values = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            $revenue = $this->firstNumeric($values, $revenueFields);
            $roomNights = $this->firstNumeric($values, $roomNightFields);
            if ($revenue === null || $roomNights === null || $roomNights <= 0.0) {
                continue;
            }
            $ref = trim((string)($fact['ref'] ?? ''));
            $base['values'][] = [
                'date' => (string)($fact['data_date'] ?? ''),
                'value' => round($revenue / $roomNights, 2),
                'unit' => 'currency_per_room_night_currency_unspecified',
                'formula' => 'room_revenue / room_nights',
                'inputs' => ['room_revenue' => $revenue, 'room_nights' => $roomNights],
                'evidence_ref' => $ref,
                'collected_at' => $fact['collected_at'] ?? null,
                'verification_status' => $this->factQualityStatus($fact),
                'readback_status' => (string)($fact['readback_status'] ?? 'not_verified'),
                'source_trace_id' => (string)($fact['source_trace_id'] ?? ''),
            ];
            if ($ref !== '') {
                $base['used_evidence_refs'][] = $ref;
            }
        }
        $base['used_evidence_refs'] = array_values(array_unique($base['used_evidence_refs']));
        if ($base['values'] === []) {
            $base['status'] = 'not_computable';
            $base['data_gaps'][] = [
                'code' => 'adr_aligned_room_revenue_or_room_nights_missing',
                'required_inputs' => ['room_revenue', 'room_nights'],
            ];
            return $base;
        }
        $base['status'] = 'calculated_from_same_fact_scope';
        return $base;
    }

    /** @param array<string,mixed> $values @param list<string> $keys */
    private function firstNumeric(array $values, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && is_numeric($values[$key])) {
                return (float)$values[$key];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $fact */
    private function factIsStrict(array $fact): bool
    {
        return (string)($fact['history_status'] ?? '') === 'success'
            && $this->factQualityStatus($fact) === 'verified'
            && (string)($fact['readback_status'] ?? '') === 'readback_verified'
            && trim((string)($fact['ref'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $fact */
    private function factQualityStatus(array $fact): string
    {
        return strtolower(trim((string)($fact['quality_status'] ?? $fact['validation_status'] ?? '')));
    }

    /** @param array<string,mixed> $fact */
    private function factPlatform(array $fact): string
    {
        $platform = (string)($fact['platform'] ?? '');
        if ($platform === '') {
            $platform = (string)($fact['source'] ?? '');
        }
        return $this->normalizePlatform($platform);
    }

    /** @param array<string,mixed> $fact @param array<string,mixed> $mapping */
    private function factMetricProvenanceMatches(array $fact, string $field, array $mapping): bool
    {
        $requiredPaths = array_values(array_filter(array_map('strval', (array)($mapping['required_source_paths'] ?? []))));
        $requiredMetricKeys = array_values(array_filter(array_map('strval', (array)($mapping['required_metric_keys'] ?? []))));
        if ($requiredPaths === [] && $requiredMetricKeys === []) {
            return true;
        }
        $provenance = is_array($fact['metric_provenance'][$field] ?? null)
            ? $fact['metric_provenance'][$field]
            : [];
        foreach ($provenance as $item) {
            if (!is_array($item)
                || (string)($item['status'] ?? '') !== 'captured'
                || ($item['stored_value_present'] ?? false) !== true
            ) {
                continue;
            }
            $path = (string)($item['source_path'] ?? '');
            $metricKey = (string)($item['metric_key'] ?? '');
            $pathMatches = $requiredPaths === [] || in_array($path, $requiredPaths, true);
            $keyMatches = $requiredMetricKeys === [] || in_array($metricKey, $requiredMetricKeys, true);
            if ($pathMatches && $keyMatches) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $fact @return list<string> */
    private function metricSourcePaths(array $fact, string $field): array
    {
        $paths = [];
        foreach ((array)($fact['metric_provenance'][$field] ?? []) as $item) {
            if (is_array($item) && trim((string)($item['source_path'] ?? '')) !== '') {
                $paths[] = trim((string)$item['source_path']);
            }
        }
        return array_values(array_unique($paths));
    }

    /** @param array<string,array<string,mixed>> $concepts @return list<string> */
    private function detectedPlatforms(string $query, array $concepts): array
    {
        $normalized = self::normalize($query);
        $platforms = [];
        foreach ($concepts as $concept) {
            $conceptPlatforms = array_values(array_intersect(
                ['ctrip', 'meituan', 'qunar'],
                array_map('strval', (array)($concept['platforms'] ?? []))
            ));
            if (count($conceptPlatforms) !== 1 || ($concept['is_business_metric'] ?? false) === true) {
                continue;
            }
            foreach ([
                $concept['canonical_term'] ?? '',
                ...(array)($concept['aliases'] ?? []),
                ...(array)($concept['voice_aliases'] ?? []),
            ] as $term) {
                $needle = self::normalize((string)$term);
                if ($needle !== '' && str_contains($normalized, $needle)) {
                    $platforms[$conceptPlatforms[0]] = true;
                    break;
                }
            }
        }
        return array_keys($platforms);
    }

    /** @param array<string,mixed> $concept */
    private function conceptMatchesPlatform(array $concept, string $platform): bool
    {
        if ($platform === '' || $platform === 'all_ota') {
            return true;
        }
        $platforms = array_map('strval', (array)($concept['platforms'] ?? []));
        return $platforms === []
            || in_array($platform, $platforms, true)
            || in_array('suxios_internal', $platforms, true);
    }

    private function queryContains(string $query, string $normalizedQuery, string $needle, string $displayTerm): bool
    {
        if (preg_match('/^[a-z0-9_+.-]+$/i', $displayTerm) === 1) {
            if (mb_strlen($needle) < 3) {
                return preg_match('/(?<![a-z0-9_])' . preg_quote($displayTerm, '/') . '(?![a-z0-9_])/iu', $query) === 1;
            }
        }
        return str_contains($normalizedQuery, $needle);
    }

    /** @param array<string,mixed> $concept @param array<string,mixed> $entry @return array<string,mixed> */
    private function publicConcept(array $concept, array $entry, string $effectivePlatform): array
    {
        $metricKey = $concept['metric_key'] ?? null;
        $mapping = [];
        if ($effectivePlatform !== '' && is_array($concept['platform_metric_mappings'] ?? null)) {
            $mapping = is_array($concept['platform_metric_mappings'][$effectivePlatform] ?? null)
                ? $concept['platform_metric_mappings'][$effectivePlatform]
                : [];
        }
        return [
            'concept_key' => (string)$concept['concept_key'],
            'canonical_term' => (string)$concept['canonical_term'],
            'matched_term' => (string)$entry['term'],
            'match_type' => (string)$entry['match_type'],
            'aliases' => array_values((array)$concept['aliases']),
            'category' => (string)$concept['category'],
            'domain_category' => $concept['domain_category'] ?? null,
            'definition' => (string)$concept['definition'],
            'platforms' => array_values((array)$concept['platforms']),
            'modules' => array_values((array)$concept['modules']),
            'is_personal' => ($concept['is_personal'] ?? false) === true,
            'is_business_metric' => ($concept['is_business_metric'] ?? false) === true,
            'metric_key' => $metricKey,
            'platform_metric_mapping' => $mapping,
            'route_key' => $concept['route_key'] ?? null,
            'assistant_topic_key' => $concept['assistant_topic_key'] ?? null,
            'source_file' => (string)$concept['source_file'],
            'source_fingerprint' => (string)$concept['source_fingerprint'],
            'risk_boundary' => (array)$concept['risk_boundary'],
            'updated_at' => (string)$concept['updated_at'],
        ];
    }

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    private function resolution(
        string $status,
        string $query,
        string $requestedPlatform,
        string $detectedPlatform,
        array $candidates,
        string $reason
    ): array {
        $effectivePlatform = $requestedPlatform !== '' ? $requestedPlatform : $detectedPlatform;
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'query' => $query,
            'normalized_query' => self::normalize($query),
            'requested_platform' => $requestedPlatform !== '' ? $requestedPlatform : null,
            'detected_platform' => $detectedPlatform !== '' ? $detectedPlatform : null,
            'effective_platform' => $effectivePlatform !== '' ? $effectivePlatform : null,
            'primary' => count($candidates) === 1 ? $candidates[0] : null,
            'candidates' => $candidates,
            'reason' => $reason,
            'decision_safe' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @param list<array<string,mixed>> $metrics @return array<string,mixed> */
    private function metricResolution(
        string $status,
        string $query,
        string $requestedPlatform,
        string $detectedPlatform,
        array $metrics,
        string $reason
    ): array {
        $effectivePlatform = $requestedPlatform !== '' ? $requestedPlatform : $detectedPlatform;
        return [
            'contract_version' => 'suxios.semantic_glossary.metric_resolution.v1',
            'status' => $status,
            'query' => $query,
            'normalized_query' => self::normalize($query),
            'requested_platform' => $requestedPlatform !== '' ? $requestedPlatform : null,
            'detected_platform' => $detectedPlatform !== '' ? $detectedPlatform : null,
            'effective_platform' => $effectivePlatform !== '' ? $effectivePlatform : null,
            'primary' => count($metrics) === 1 ? $metrics[0] : null,
            'metrics' => array_values($metrics),
            'metric_count' => count($metrics),
            'reason' => $reason,
            'decision_safe' => false,
            'external_write_authorized' => false,
        ];
    }

    /** @return list<int> */
    private function allPositions(string $haystack, string $needle): array
    {
        $positions = [];
        $offset = 0;
        while (($position = mb_strpos($haystack, $needle, $offset)) !== false) {
            $positions[] = $position;
            $offset = $position + max(1, mb_strlen($needle));
        }
        return $positions;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        } elseif (function_exists('mb_convert_kana')) {
            $value = mb_convert_kana($value, 'asKV', 'UTF-8');
        }
        $value = mb_strtolower($value);
        return preg_replace('/[\s，。！？、,.!?：:；;（）()【】\[\]《》<>“”\'"`]+/u', '', $value) ?? $value;
    }

    private function normalizePlatform(string $value): string
    {
        $value = self::normalize($value);
        return match ($value) {
            '携程', 'ctrip', 'xc', 'xiecheng', 'ebooking', '生意通', '携成' => 'ctrip',
            '美团', 'meituan', 'mt', '美团hms' => 'meituan',
            '去哪儿', 'qunar', 'qunar.com' => 'qunar',
            'all', 'ota', 'allota', '全部ota', '全ota', '携程美团', '携程和美团' => 'all_ota',
            'pms', '全酒店' => 'pms',
            default => $value,
        };
    }

    /** @return array<string,mixed> */
    private function index(): array
    {
        $path = (string)$this->packPath;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('semantic_glossary_pack_missing');
        }
        $mtime = (int)filemtime($path);
        $size = (int)filesize($path);
        $cacheKey = $path . ':' . $mtime . ':' . $size;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('semantic_glossary_pack_unreadable');
        }
        $pack = json_decode($bytes, true);
        if (!is_array($pack)
            || (int)($pack['schema_version'] ?? 0) !== 1
            || (string)($pack['pack_key'] ?? '') !== 'suxios.semantic_glossary.v1'
            || !is_array($pack['concepts'] ?? null)
            || (int)($pack['summary']['concept_count'] ?? -1) !== count($pack['concepts'])
            || ($pack['boundary']['external_write_authorized'] ?? null) !== false
        ) {
            throw new RuntimeException('semantic_glossary_pack_invalid');
        }
        $concepts = [];
        $exact = [];
        $searchEntries = [];
        foreach ($pack['concepts'] as $concept) {
            if (!is_array($concept)) {
                throw new RuntimeException('semantic_glossary_concept_invalid');
            }
            $key = trim((string)($concept['concept_key'] ?? ''));
            if ($key === '' || isset($concepts[$key])) {
                throw new RuntimeException('semantic_glossary_concept_key_invalid');
            }
            foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $boundary) {
                if (($concept['risk_boundary'][$boundary] ?? null) !== false) {
                    throw new RuntimeException('semantic_glossary_unsafe_concept:' . $key);
                }
            }
            $concepts[$key] = $concept;
            $termGroups = [
                'canonical' => [(string)$concept['canonical_term']],
                'alias' => (array)($concept['aliases'] ?? []),
                'voice_alias' => (array)($concept['voice_aliases'] ?? []),
                'navigation_term' => (array)($concept['navigation_terms'] ?? []),
            ];
            foreach ($termGroups as $matchType => $terms) {
                foreach ($terms as $term) {
                    $term = trim((string)$term);
                    $normalized = self::normalize($term);
                    if ($normalized === '') {
                        continue;
                    }
                    $entry = [
                        'concept_key' => $key,
                        'term' => $term,
                        'normalized_term' => $normalized,
                        'match_type' => $matchType,
                        'length' => mb_strlen($normalized),
                    ];
                    $exact[$normalized][] = $entry;
                    $searchEntries[] = $entry;
                }
            }
        }
        usort($searchEntries, static function (array $left, array $right): int {
            $length = (int)$right['length'] <=> (int)$left['length'];
            if ($length !== 0) {
                return $length;
            }
            $priority = ['canonical' => 0, 'alias' => 1, 'voice_alias' => 2, 'navigation_term' => 3];
            return ($priority[(string)$left['match_type']] ?? 9) <=> ($priority[(string)$right['match_type']] ?? 9);
        });
        $index = [
            'pack' => $pack,
            'pack_sha256' => hash('sha256', $bytes),
            'concepts' => $concepts,
            'exact' => $exact,
            'search_entries' => $searchEntries,
        ];
        self::$cache = [$cacheKey => $index];
        return $index;
    }
}
