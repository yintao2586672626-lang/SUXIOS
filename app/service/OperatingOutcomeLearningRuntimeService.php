<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;

/**
 * Read-only runtime bridge from verified execution-flow evidence to the
 * longitudinal learning contracts used by Daily One Thing and weekly plans.
 */
final class OperatingOutcomeLearningRuntimeService
{
    public const CONTRACT_VERSION = 'operating_outcome_learning_runtime.v1';

    /** @var null|callable(int,int):array<string,mixed> */
    private $flowReader;

    public function __construct(?callable $flowReader = null)
    {
        $this->flowReader = $flowReader;
    }

    /** @return array<string,mixed> */
    public function load(int $tenantId, int $hotelId): array
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('operating_outcome_learning_scope_invalid');
        }
        try {
            if ($this->flowReader === null) {
                $hotelTenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
                if ($hotelTenantId !== $tenantId) {
                    throw new \RuntimeException('operating_outcome_learning_hotel_tenant_mismatch');
                }
                $flow = (new OperationManagementService())->executionFlow(
                    [$hotelId],
                    $hotelId,
                    ['limit' => 500]
                );
            } else {
                $flow = call_user_func($this->flowReader, $tenantId, $hotelId);
            }
        } catch (\Throwable $error) {
            return $this->envelope('blocked', [], ['execution_flow_unavailable']);
        }
        if (($flow['truncated'] ?? false) === true) {
            return $this->envelope('blocked', [], ['execution_flow_truncated']);
        }

        $reviews = [];
        $refs = [];
        foreach ((array)($flow['list'] ?? []) as $item) {
            if (!is_array($item) || (int)($item['hotel_id'] ?? 0) !== $hotelId) continue;
            $review = is_array($item['evidence']['longitudinal_review'] ?? null)
                ? $item['evidence']['longitudinal_review'] : [];
            if (!$this->reviewMatchesHotel($review, $hotelId)) continue;
            $actionRef = trim((string)($review['action']['action_ref'] ?? ''));
            $followupRefs = array_values(array_filter(array_map(
                'strval',
                (array)($review['followup']['evidence_refs'] ?? [])
            )));
            $identity = hash('sha256', json_encode([
                $actionRef,
                (string)($review['comparison_key'] ?? ''),
                $followupRefs,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $reviews[$identity] = $review;
            if ($actionRef !== '') $refs[] = $actionRef;
            array_push($refs, ...$followupRefs);
        }
        ksort($reviews, SORT_STRING);
        $refs = array_values(array_unique(array_filter($refs)));
        sort($refs, SORT_STRING);
        $status = $reviews === [] ? 'missing' : 'ready';
        $result = $this->envelope($status, array_values($reviews), []);
        $result['evidence_refs'] = $refs;
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @param list<array<string,mixed>> $reviews
     * @return list<array<string,mixed>>
     */
    public function bindDailyCandidates(array $candidates, array $reviews): array
    {
        foreach ($candidates as &$candidate) {
            if (!is_array($candidate)) continue;
            $matches = [];
            foreach ($reviews as $review) {
                if (!is_array($review)) continue;
                $baseline = is_array($review['baseline'] ?? null) ? $review['baseline'] : [];
                $action = is_array($review['action'] ?? null) ? $review['action'] : [];
                if ((int)($baseline['system_hotel_id'] ?? 0) !== (int)($candidate['scope']['hotel_id'] ?? 0)
                    || strtolower(trim((string)($baseline['platform'] ?? ''))) !== strtolower(trim((string)($candidate['scope']['platform'] ?? '')))
                    || strtolower(trim((string)($baseline['metric_key'] ?? ''))) !== strtolower(trim((string)($candidate['expected_observation_metric']['key'] ?? '')))
                    || strtolower(trim((string)($baseline['unit'] ?? ''))) !== strtolower(trim((string)($candidate['expected_observation_metric']['unit'] ?? '')))
                    || strtolower(trim((string)($action['action_type'] ?? ''))) !== strtolower(trim((string)($candidate['recommended_action']['type'] ?? '')))
                ) {
                    continue;
                }
                $comparisonKey = strtolower(trim((string)($review['comparison_key'] ?? '')));
                $actionType = strtolower(trim((string)($action['action_type'] ?? '')));
                $expectedDirection = strtolower(trim((string)($action['expected_direction'] ?? '')));
                if (preg_match('/^longitudinal:[a-f0-9]{64}$/D', $comparisonKey) !== 1
                    || !in_array($expectedDirection, ['increase', 'decrease', 'unchanged'], true)
                ) {
                    continue;
                }
                $matches[$comparisonKey . '|' . $actionType . '|' . $expectedDirection] = [
                    'comparison_key' => $comparisonKey,
                    'action_type' => $actionType,
                    'expected_direction' => $expectedDirection,
                ];
            }
            if (count($matches) === 1) {
                $candidate['outcome_learning_binding'] = array_values($matches)[0];
            }
        }
        unset($candidate);
        return $candidates;
    }

    /** @param array<string,mixed> $review */
    private function reviewMatchesHotel(array $review, int $hotelId): bool
    {
        return ($review['status'] ?? '') === 'verified'
            && ($review['learning_stage'] ?? '') === 'action_reviewed'
            && ($review['causality_claimed'] ?? true) === false
            && (int)($review['baseline']['system_hotel_id'] ?? 0) === $hotelId
            && (int)($review['followup']['system_hotel_id'] ?? 0) === $hotelId
            && trim((string)($review['action']['action_ref'] ?? '')) !== '';
    }

    /** @param list<array<string,mixed>> $reviews @param list<string> $gaps @return array<string,mixed> */
    private function envelope(string $status, array $reviews, array $gaps): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'reviewed_observations' => $reviews,
            'reviewed_observation_count' => count($reviews),
            'evidence_refs' => [],
            'data_gaps' => $gaps,
            'usable_for_tie_break' => $status === 'ready',
            'causality_claimed' => false,
            'automatic_sop_promotion' => false,
            'external_write_count' => 0,
        ];
    }
}
