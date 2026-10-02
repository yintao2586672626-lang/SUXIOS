<?php
declare(strict_types=1);

namespace app\service\operation;

final class OperationMetricUnitCompatibilityService
{
    /** @param array<string,mixed> $intent @param array<string,mixed> $definition */
    public static function matches(array $intent, array $definition, string $sourceUnit): bool
    {
        $expectedUnit = strtolower(trim((string)($definition['unit'] ?? '')));
        $sourceUnit = strtolower(trim($sourceUnit));
        if ($expectedUnit !== '' && $sourceUnit === $expectedUnit) {
            return true;
        }
        // The former question action card called explicitly defined Ctrip UV
        // visitor_count. This single stored alias does not cover impressions,
        // generic visitors, or another platform/metric/definition.
        if ($expectedUnit !== 'unique_users' || $sourceUnit !== 'visitor_count'
            || strtolower(trim((string)($intent['platform'] ?? ''))) !== 'ctrip'
            || strtolower(trim((string)($intent['expected_metric'] ?? ''))) !== 'list_exposure'
            || ($intent['source_module'] ?? '') !== 'operating_question'
            || ($definition['semantic_key'] ?? '') !== 'ctrip_datacenter_list_exposure_uv') {
            return false;
        }
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence']
            : json_decode((string)($intent['evidence_json'] ?? ''), true);
        return is_array($evidence)
            && ($evidence['decision_recommendation']['expected_metric_definition_id'] ?? '') === 'ota_list_exposure_users.v1'
            && ($evidence['action_card']['metric_contract']['unit'] ?? '') === 'visitor_count';
    }
}
