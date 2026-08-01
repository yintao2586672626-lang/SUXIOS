<?php
declare(strict_types=1);

namespace app\service;

/**
 * One evidence policy for persisted Ctrip and Meituan rows.
 *
 * Platform metrics remain independent. This service only decides whether a
 * saved row proves a structured platform response, or is merely DOM/unverified
 * evidence. Historical XHR rows can pass only when their full desensitized
 * lineage still matches the exact saved row.
 */
final class OtaStructuredCaptureEvidenceService
{
    public const STATUS_STRUCTURED = 'structured_response_verified';
    public const STATUS_LEGACY_STRUCTURED = 'legacy_structured_verified';
    public const STATUS_DOM = 'dom_visible_only';
    public const STATUS_UNVERIFIED = 'unverified';

    private const TRUSTED_INGESTION_METHODS = [
        'browser_profile',
        'profile_browser',
        'local_collector',
    ];

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $source
     * @return array{
     *   allowed:bool,
     *   status:string,
     *   reason_codes:list<string>,
     *   capture_strategy:string,
     *   response_evidence_type:?string,
     *   source_trace_id:?string,
     *   source_url_hash:?string,
     *   capture_source:?string,
     *   source_path:?string
     * }
     */
    public function classifyRow(array $row, array $source = []): array
    {
        $raw = $this->decodeArray($row['raw_data'] ?? []);
        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $rawEvidence = is_array($raw['capture_evidence'] ?? null)
            ? $raw['capture_evidence']
            : [];
        $sourceEvidence = is_array($sourceRow['capture_evidence'] ?? null)
            ? $sourceRow['capture_evidence']
            : [];
        $platform = strtolower(trim((string)(
            $row['platform']
                ?? $row['source']
                ?? $source['platform']
                ?? ''
        )));
        $ingestionMethod = strtolower(trim((string)(
            $row['ingestion_method']
                ?? $source['ingestion_method']
                ?? ''
        )));
        $reasonCodes = [];

        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            $reasonCodes[] = 'platform_unverified';
        }
        if (!in_array(
            $ingestionMethod,
            self::TRUSTED_INGESTION_METHODS,
            true
        )) {
            $reasonCodes[] = 'ingestion_method_untrusted';
        }
        if ((int)($row['system_hotel_id'] ?? 0) <= 0) {
            $reasonCodes[] = 'system_hotel_scope_missing';
        }
        if ((int)($row['data_source_id'] ?? 0) <= 0) {
            $reasonCodes[] = 'data_source_scope_missing';
        }
        if ((int)($row['sync_task_id'] ?? 0) <= 0) {
            $reasonCodes[] = 'sync_task_scope_missing';
        }
        if (!$this->isDate((string)($row['data_date'] ?? ''))) {
            $reasonCodes[] = 'data_date_unverified';
        }
        if (!in_array(
            $row['readback_verified'] ?? null,
            [true, 1, '1'],
            true
        )) {
            $reasonCodes[] = 'readback_unverified';
        }

        $traceCandidates = $this->nonEmptyStrings([
            $row['source_trace_id'] ?? null,
            $raw['source_trace_id'] ?? null,
            $rawEvidence['source_trace_id'] ?? null,
        ]);
        $sourceTraceId = $traceCandidates[0] ?? null;
        if ($sourceTraceId === null
            || preg_match(
                '/^[A-Za-z0-9._:-]{1,160}$/D',
                $sourceTraceId
            ) !== 1
        ) {
            $reasonCodes[] = 'source_trace_missing';
        } elseif (!$this->allEqual($traceCandidates)) {
            $reasonCodes[] = 'source_trace_mismatch';
        }
        $upstreamTraceCandidates = $this->nonEmptyStrings([
            $sourceRow['source_trace_id'] ?? null,
            $sourceEvidence['source_trace_id'] ?? null,
        ]);
        foreach ($upstreamTraceCandidates as $upstreamTraceId) {
            if (preg_match(
                '/^[A-Za-z0-9._:-]{1,160}$/D',
                $upstreamTraceId
            ) !== 1) {
                $reasonCodes[] = 'upstream_source_trace_invalid';
            }
        }
        if (!$this->allEqual($upstreamTraceCandidates)) {
            $reasonCodes[] = 'upstream_source_trace_mismatch';
        }

        $urlHashCandidates = $this->nonEmptyStrings([
            $raw['source_url_hash'] ?? null,
            $rawEvidence['source_url_hash'] ?? null,
            $sourceRow['source_url_hash'] ?? null,
            $sourceEvidence['source_url_hash'] ?? null,
        ], true);
        $sourceUrlHash = $urlHashCandidates[0] ?? null;
        if ($sourceUrlHash === null
            || preg_match('/^[a-f0-9]{64}$/D', $sourceUrlHash) !== 1
        ) {
            $reasonCodes[] = 'source_url_hash_missing';
        } elseif (!$this->allEqual($urlHashCandidates)) {
            $reasonCodes[] = 'source_url_hash_mismatch';
        }

        $captureSources = $this->nonEmptyStrings([
            $sourceEvidence['capture_source'] ?? null,
            $sourceRow['_capture_source'] ?? null,
            $sourceRow['capture_source'] ?? null,
            $rawEvidence['capture_source'] ?? null,
            $raw['capture_source'] ?? null,
        ], true);
        $sourcePaths = $this->nonEmptyStrings([
            $sourceEvidence['source_path'] ?? null,
            $sourceRow['_source_path'] ?? null,
            $sourceRow['source_path'] ?? null,
            $rawEvidence['source_path'] ?? null,
            $raw['source_path'] ?? null,
        ]);
        $captureSource = $captureSources[0] ?? null;
        $sourcePath = $sourcePaths[0] ?? null;
        $strategy = strtolower(trim((string)(
            $sourceEvidence['capture_strategy']
                ?? $rawEvidence['capture_strategy']
                ?? $sourceRow['capture_strategy']
                ?? $raw['capture_strategy']
                ?? ''
        )));
        $responseEvidenceType = strtolower(trim((string)(
            $sourceEvidence['response_evidence_type']
                ?? $rawEvidence['response_evidence_type']
                ?? $sourceRow['response_evidence_type']
                ?? $raw['response_evidence_type']
                ?? ''
        )));

        $domEvidence = $strategy === 'dom_fallback'
            || $responseEvidenceType === 'dom_fields'
            || $this->containsDomEvidence($captureSources)
            || $this->containsDomEvidence($sourcePaths);
        if ($domEvidence) {
            return $this->result(
                false,
                self::STATUS_DOM,
                ['dom_evidence_not_claimable'],
                'dom_fallback',
                'dom_fields',
                $sourceTraceId,
                $sourceUrlHash,
                $captureSource,
                $sourcePath
            );
        }

        if ($captureSource === null
            || preg_match('/^(?:xhr|fetch):/D', $captureSource) !== 1
        ) {
            $reasonCodes[] = 'structured_capture_source_missing';
        }
        if ($sourcePath === null || !$this->structuredSourcePath($sourcePath)) {
            $reasonCodes[] = 'structured_source_path_missing';
        }

        $facts = is_array($raw['field_facts'] ?? null)
            ? $raw['field_facts']
            : [];
        foreach ($facts as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? '')))
                    !== 'captured'
                || ($fact['stored_value_present'] ?? null) !== true
            ) {
                continue;
            }
            $factEvidence = is_array($fact['capture_evidence'] ?? null)
                ? $fact['capture_evidence']
                : [];
            $factTraceId = trim((string)(
                $factEvidence['source_trace_id']
                    ?? $factEvidence['_source_trace_id']
                    ?? ''
            ));
            $factUrlHash = strtolower(trim((string)(
                $factEvidence['source_url_hash']
                    ?? $factEvidence['_source_url_hash']
                    ?? ''
            )));
            $factCaptureSource = strtolower(trim((string)(
                $factEvidence['capture_source'] ?? ''
            )));
            $factSourcePath = trim((string)(
                $fact['source_path']
                    ?? $factEvidence['source_path']
                    ?? ''
            ));
            if ($sourceTraceId === null
                || $factTraceId === ''
                || !hash_equals($sourceTraceId, $factTraceId)
            ) {
                $reasonCodes[] = 'field_fact_trace_mismatch';
            }
            if ($sourceUrlHash === null
                || preg_match('/^[a-f0-9]{64}$/D', $factUrlHash) !== 1
                || !hash_equals($sourceUrlHash, $factUrlHash)
            ) {
                $reasonCodes[] = 'field_fact_url_hash_mismatch';
            }
            if ($factCaptureSource !== ''
                && preg_match('/^(?:xhr|fetch):/D', $factCaptureSource) !== 1
            ) {
                $reasonCodes[] = 'field_fact_capture_source_unverified';
            }
            if (!$this->structuredSourcePath($factSourcePath)) {
                $reasonCodes[] = 'field_fact_source_path_unverified';
            }
        }
        $explicitStrategy = $strategy !== '' || $responseEvidenceType !== '';
        if ($explicitStrategy
            && (
                !in_array(
                    $strategy,
                    ['browser_response', 'verified_endpoint_recipe'],
                    true
                )
                || $responseEvidenceType !== 'structured_json'
            )
        ) {
            $reasonCodes[] = 'structured_strategy_unverified';
        }
        $reasonCodes = array_values(array_unique($reasonCodes));
        $allowed = $reasonCodes === [];

        return $this->result(
            $allowed,
            $allowed
                ? (
                    $explicitStrategy
                        ? self::STATUS_STRUCTURED
                        : self::STATUS_LEGACY_STRUCTURED
                )
                : self::STATUS_UNVERIFIED,
            $reasonCodes,
            $allowed
                ? ($strategy !== '' ? $strategy : 'browser_response')
                : 'not_recorded',
            $allowed ? 'structured_json' : null,
            $sourceTraceId,
            $sourceUrlHash,
            $captureSource,
            $sourcePath
        );
    }

    /**
     * @param list<string> $reasonCodes
     * @return array{
     *   allowed:bool,status:string,reason_codes:list<string>,
     *   capture_strategy:string,response_evidence_type:?string,
     *   source_trace_id:?string,source_url_hash:?string,
     *   capture_source:?string,source_path:?string
     * }
     */
    private function result(
        bool $allowed,
        string $status,
        array $reasonCodes,
        string $captureStrategy,
        ?string $responseEvidenceType,
        ?string $sourceTraceId,
        ?string $sourceUrlHash,
        ?string $captureSource,
        ?string $sourcePath
    ): array {
        return [
            'allowed' => $allowed,
            'status' => $status,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'capture_strategy' => $captureStrategy,
            'response_evidence_type' => $responseEvidenceType,
            'source_trace_id' => $sourceTraceId,
            'source_url_hash' => $sourceUrlHash,
            'capture_source' => $captureSource,
            'source_path' => $sourcePath,
        ];
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
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<mixed> $values @return list<string> */
    private function nonEmptyStrings(array $values, bool $lower = false): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $result[] = $lower ? strtolower($text) : $text;
        }
        return array_values(array_unique($result));
    }

    /** @param list<string> $values */
    private function allEqual(array $values): bool
    {
        if (count($values) <= 1) {
            return true;
        }
        $first = $values[0];
        foreach (array_slice($values, 1) as $value) {
            if (!hash_equals($first, $value)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $values */
    private function containsDomEvidence(array $values): bool
    {
        foreach ($values as $value) {
            $value = strtolower(trim($value));
            if (str_starts_with($value, 'dom:')
                || str_starts_with($value, 'dom.')
                || str_contains($value, 'dom_visible')
            ) {
                return true;
            }
        }
        return false;
    }

    private function structuredSourcePath(string $value): bool
    {
        $value = strtolower(trim($value));
        if ($value === ''
            || str_starts_with($value, 'dom:')
            || str_starts_with($value, 'dom.')
            || str_contains($value, 'dom_visible')
        ) {
            return false;
        }
        return str_starts_with($value, '$')
            || str_starts_with($value, 'data')
            || preg_match(
                '/^[a-z_][a-z0-9_]*(?:[.\[]|$)/D',
                $value
            ) === 1;
    }

    private function isDate(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
