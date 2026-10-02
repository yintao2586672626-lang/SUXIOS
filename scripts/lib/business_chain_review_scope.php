<?php
declare(strict_types=1);

/** Pure review gate; missing identity or action never grants approval. */
function business_chain_manual_review_scope(array $handoff, array $diagnosis, array $action, array $blockers, string $actionReason): array
{
    $platforms = business_chain_list($handoff['source_platforms'] ?? []);
    $date = (string)($handoff['business_date'] ?? '');
    $hotelId = filter_var($diagnosis['system_hotel_id'] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]);
    $ready = $platforms !== []
        && array_diff($platforms, business_chain_list($diagnosis['source_channels'] ?? [])) === []
        && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) === 1
        && checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])
        && ($diagnosis['business_date'] ?? null) === $date
        && $hotelId !== false && $hotelId === ($handoff['system_hotel_id'] ?? null)
        && (string)($diagnosis['status'] ?? '') !== 'blocked'
        && trim((string)($action['key'] ?? '')) !== '';
    if (!$ready) {
        $reason = (string)(($diagnosis['reason'] ?? '') ?: 'same_scope_diagnosis_action_unavailable');
        $existing = array_filter($blockers, static fn(array $row): bool =>
            ($row['key'] ?? '') === 'diagnosis_scope' && ($row['reason'] ?? '') === $reason);
        if ($existing === []) {
            $blockers[] = ['key' => 'diagnosis_scope', 'status' => 'blocked',
                'reason' => $reason, 'category' => 'diagnosis_scope_evidence'];
        }
    }
    return [!$ready ? 'blocked_by_diagnosis_scope'
        : ($blockers === [] && $actionReason === '' ? 'ready_for_manual_review' : 'blocked_ready_for_manual_review'), $blockers];
}

function business_chain_review_resolution_plan(array $inputs, string $scope, bool $blocked): array
{
    $plan = business_chain_ai_decision_resolution_plan($inputs, $scope);
    if ($blocked) {
        $plan['status'] = 'has_pending_evidence';
        $plan['approval_allowed_after_resolution'] = false;
    }
    return $plan;
}
