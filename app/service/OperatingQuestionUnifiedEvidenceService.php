<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;

/**
 * Normalizes knowledge, operating-memory and explicitly selected local-media
 * evidence into one hotel-scoped, provenance-preserving read model.
 */
final class OperatingQuestionUnifiedEvidenceService
{
    public const CONTRACT_VERSION = 'operating_question_unified_evidence.v1';

    private Closure $knowledgeLoader;
    private Closure $memoryLoader;
    private Closure $mediaLoader;

    public function __construct(
        ?callable $knowledgeLoader = null,
        ?callable $memoryLoader = null,
        ?callable $mediaLoader = null
    ) {
        $this->knowledgeLoader = Closure::fromCallable($knowledgeLoader ?? static function (
            array $scope,
            string $question
        ): array {
            return (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
                (int)$scope['hotel_id'],
                (int)$scope['user_id'],
                (string)$scope['platform'],
                $question
            );
        });
        $this->memoryLoader = Closure::fromCallable($memoryLoader ?? static function (
            array $scope,
            string $question
        ): array {
            return (new OperatingMemoryRetrievalService())->retrieve(
                (int)$scope['tenant_id'],
                (int)$scope['hotel_id'],
                (string)$scope['platform'],
                $question,
                (string)$scope['date_start'],
                (string)$scope['date_end']
            );
        });
        $this->mediaLoader = Closure::fromCallable($mediaLoader ?? static function (
            int $id,
            array $scope
        ): array {
            $row = (new LocalMediaExtractionService())->read(
                $id,
                (int)$scope['tenant_id'],
                [(int)$scope['hotel_id']]
            );
            $row['persistence_status'] = 'readback_verified';
            return $row;
        });
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<int> $mediaEvidenceIds
     * @return array<string,mixed>
     */
    public function collectSource(
        string $sourceType,
        array $scope,
        string $question,
        array $mediaEvidenceIds = []
    ): array {
        $scope = $this->scope($scope);
        $question = mb_substr(trim($question), 0, 1000);
        if ($question === '') {
            throw new InvalidArgumentException('统一证据检索缺少经营问题');
        }

        return match ($sourceType) {
            'knowledge' => $this->knowledge($scope, $question),
            'operating_memory' => $this->memory($scope, $question),
            'local_media' => $this->media($scope, $mediaEvidenceIds),
            default => $this->unavailable($sourceType, $scope, 'tool_not_allowed'),
        };
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function knowledge(array $scope, string $question): array
    {
        try {
            $result = ($this->knowledgeLoader)($scope, $question);
        } catch (\Throwable) {
            return $this->unavailable('knowledge', $scope, 'knowledge_retrieval_failed');
        }
        $items = [];
        foreach ((array)($result['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = trim((string)($row['ref'] ?? ''));
            if (preg_match('/^knowledge_chunks#[1-9][0-9]*$/D', $ref) !== 1) {
                continue;
            }
            $usagePolicy = trim((string)($row['usage_policy'] ?? 'reference_only'));
            $items[] = $this->item([
                'source_type' => 'knowledge',
                'ref' => $ref,
                'source_identity' => trim((string)($row['unit_ref'] ?? '')),
                'source_scope' => trim((string)($row['scope'] ?? 'global_reference')),
                'platforms' => $this->strings($row['platforms'] ?? []),
                'business_date' => null,
                'quality_status' => trim((string)($row['gate_status'] ?? 'reference_only')),
                'usage_policy' => $usagePolicy !== '' ? $usagePolicy : 'reference_only',
                'retrieval_method' => trim((string)($row['retrieval_method'] ?? '')),
                'retrieval_score' => is_numeric($row['retrieval_score'] ?? null)
                    ? (float)$row['retrieval_score']
                    : null,
                'title' => mb_substr(trim((string)($row['name'] ?? '')), 0, 240),
                'excerpt' => mb_substr(trim((string)($row['excerpt'] ?? '')), 0, 1600),
                'provenance_refs' => $this->strings($row['source_refs'] ?? []),
                'readback_status' => 'retrieved_from_saved_knowledge',
                'human_confirmation_required' => false,
                'decision_safe' => $usagePolicy === 'decision_support',
            ]);
        }
        return $this->result('knowledge', $scope, $result, $items);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function memory(array $scope, string $question): array
    {
        try {
            $result = ($this->memoryLoader)($scope, $question);
        } catch (\Throwable) {
            return $this->unavailable('operating_memory', $scope, 'operating_memory_retrieval_failed');
        }
        $items = [];
        foreach ((array)($result['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ref = trim((string)($row['ref'] ?? ''));
            if (preg_match('/^hotel_operating_memories#[1-9][0-9]*$/D', $ref) !== 1) {
                continue;
            }
            $usageLevel = trim((string)($row['usage_level'] ?? 'reference'));
            $items[] = $this->item([
                'source_type' => 'operating_memory',
                'ref' => $ref,
                'source_identity' => $ref,
                'source_scope' => 'hotel_scoped_operating_memory',
                'platforms' => $this->strings([(string)($row['platform'] ?? '')]),
                'business_date' => trim((string)($row['business_date'] ?? '')) ?: null,
                'quality_status' => trim((string)($row['quality_status'] ?? 'unverified')),
                'usage_policy' => $usageLevel === 'decision_support' ? 'decision_support' : 'reference_only',
                'retrieval_method' => trim((string)($row['retrieval_method'] ?? '')),
                'retrieval_score' => is_numeric($row['retrieval_score'] ?? null)
                    ? (float)$row['retrieval_score']
                    : null,
                'title' => mb_substr(trim((string)($row['title'] ?? '')), 0, 240),
                'excerpt' => mb_substr(trim((string)($row['summary'] ?? '')), 0, 1600),
                'provenance_refs' => [$ref],
                'readback_status' => 'retrieved_from_saved_operating_memory',
                'human_confirmation_required' => false,
                'decision_safe' => $usageLevel === 'decision_support'
                    && (string)($row['quality_status'] ?? '') === 'verified',
            ]);
        }
        return $this->result('operating_memory', $scope, $result, $items);
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<int> $mediaEvidenceIds
     * @return array<string,mixed>
     */
    private function media(array $scope, array $mediaEvidenceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mediaEvidenceIds))));
        if ($ids === []) {
            return $this->unavailable('local_media', $scope, 'explicit_media_evidence_not_selected', 'no_match');
        }
        $items = [];
        $excluded = 0;
        foreach (array_slice($ids, 0, 10) as $id) {
            try {
                $row = ($this->mediaLoader)($id, $scope);
            } catch (\Throwable) {
                $excluded++;
                continue;
            }
            if (!is_array($row)
                || (int)($row['tenant_id'] ?? 0) !== (int)$scope['tenant_id']
                || (int)($row['hotel_id'] ?? 0) !== (int)$scope['hotel_id']
                || (int)($row['created_by'] ?? 0) !== (int)$scope['user_id']
                || !in_array((string)($row['extraction_status'] ?? ''), ['ready', 'partial'], true)
                || (string)($row['persistence_status'] ?? '') !== 'readback_verified'
                || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($row['source_sha256'] ?? '')))) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($row['content_digest'] ?? '')))) !== 1
            ) {
                $excluded++;
                continue;
            }
            $ref = 'local_media_extractions#' . (int)$row['id'];
            $items[] = $this->item([
                'source_type' => 'local_media',
                'ref' => $ref,
                'source_identity' => strtolower(trim((string)($row['source_sha256'] ?? ''))),
                'source_scope' => 'hotel_scoped_user_selected_local_media',
                'platforms' => [],
                'business_date' => null,
                'quality_status' => (string)$row['extraction_status'],
                'usage_policy' => 'reference_only_until_human_confirmed',
                'retrieval_method' => (string)($row['extraction_method'] ?? ''),
                'retrieval_score' => null,
                'title' => mb_substr(trim((string)($row['original_name'] ?? '')), 0, 240),
                'excerpt' => mb_substr(trim((string)($row['extracted_text'] ?? '')), 0, 1600),
                'provenance_refs' => [
                    $ref,
                    'sha256:' . strtolower(trim((string)($row['source_sha256'] ?? ''))),
                ],
                'readback_status' => 'readback_verified',
                'human_confirmation_required' => true,
                'decision_safe' => false,
                'media_kind' => trim((string)($row['media_kind'] ?? '')),
                'mime_type' => trim((string)($row['mime_type'] ?? '')),
                'source_retention' => trim((string)($row['source_retention'] ?? '')),
                'extractor_version' => trim((string)($row['extractor_version'] ?? '')),
                'confidence' => is_numeric($row['confidence'] ?? null) ? (float)$row['confidence'] : null,
                'stored_content_digest' => strtolower(trim((string)($row['content_digest'] ?? ''))),
            ]);
        }
        return $this->result('local_media', $scope, [
            'status' => $items === [] ? 'no_match' : 'matched',
            'method' => 'explicit_id_exact_readback',
            'matched_count' => count($items),
            'returned_count' => count($items),
            'excluded_count' => $excluded,
            'reason' => $items === [] ? 'selected_media_not_eligible_or_out_of_scope' : null,
        ], $items);
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $raw @param list<array<string,mixed>> $items */
    private function result(string $sourceType, array $scope, array $raw, array $items): array
    {
        $status = trim((string)($raw['status'] ?? ($items === [] ? 'no_match' : 'matched')));
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source_type' => $sourceType,
            'status' => $status !== '' ? $status : ($items === [] ? 'no_match' : 'matched'),
            'method' => mb_substr(trim((string)($raw['method'] ?? '')), 0, 100),
            'matched_count' => max(0, (int)($raw['matched_count'] ?? count($items))),
            'returned_count' => count($items),
            'excluded_count' => max(0, (int)($raw['excluded_count'] ?? 0)),
            'reason' => ($reason = trim((string)($raw['reason'] ?? ''))) !== '' ? $reason : null,
            'scope' => $scope,
            'scope_digest' => $this->digest($scope),
            'items' => $items,
            'evidence_refs' => array_values(array_map(
                static fn(array $item): string => (string)$item['ref'],
                $items
            )),
            'evidence_digest' => $this->digest($items),
            'boundaries' => [
                'read_only' => true,
                'hotel_fact_created' => false,
                'external_write_authorized' => false,
                'automatic_execution' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function unavailable(string $sourceType, array $scope, string $reason, string $status = 'unavailable'): array
    {
        return $this->result($sourceType, $scope, [
            'status' => $status,
            'method' => '',
            'matched_count' => 0,
            'returned_count' => 0,
            'excluded_count' => 0,
            'reason' => $reason,
        ], []);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function item(array $item): array
    {
        $canonical = array_merge([
            'contract_version' => self::CONTRACT_VERSION,
            'source_type' => '',
            'ref' => '',
            'source_identity' => '',
            'source_scope' => '',
            'platforms' => [],
            'business_date' => null,
            'quality_status' => 'unverified',
            'usage_policy' => 'reference_only',
            'retrieval_method' => '',
            'retrieval_score' => null,
            'title' => '',
            'excerpt' => '',
            'provenance_refs' => [],
            'readback_status' => 'unverified',
            'human_confirmation_required' => false,
            'decision_safe' => false,
        ], $item);
        $canonical['content_digest'] = $this->digest($canonical);
        return $canonical;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function scope(array $scope): array
    {
        $normalized = [
            'tenant_id' => max(0, (int)($scope['tenant_id'] ?? 0)),
            'hotel_id' => max(0, (int)($scope['hotel_id'] ?? 0)),
            'user_id' => max(0, (int)($scope['user_id'] ?? 0)),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'date_start' => substr(trim((string)($scope['date_start'] ?? '')), 0, 10),
            'date_end' => substr(trim((string)($scope['date_end'] ?? '')), 0, 10),
            'source_scope' => 'ota_channel',
        ];
        if ($normalized['tenant_id'] <= 0
            || $normalized['hotel_id'] <= 0
            || $normalized['user_id'] <= 0
            || !in_array($normalized['platform'], ['ctrip', 'meituan', 'qunar', 'all_ota'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized['date_start']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $normalized['date_end']) !== 1
            || $normalized['date_end'] < $normalized['date_start']
        ) {
            throw new InvalidArgumentException('统一证据检索范围无效');
        }
        return $normalized;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item) || is_bool($item)) {
                continue;
            }
            $text = mb_substr(trim((string)$item), 0, 300);
            if ($text !== '') {
                $result[$text] = true;
            }
        }
        return array_keys($result);
    }

    private function digest(mixed $value): string
    {
        $encoded = json_encode($this->sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $encoded !== false ? $encoded : 'null');
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sort($item);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
