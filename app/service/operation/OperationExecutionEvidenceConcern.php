<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\OperationActionLifecycleService;
use think\facade\Db;

trait OperationExecutionEvidenceConcern
{
    /** @param array<string,mixed> $task @param array<string,mixed> $intent */
    private function assertExecutionTaskIntentIdentity(array $task, array $intent): void
    {
        if (!$this->executionFlowReadService->taskMatchesIntent($intent, $task)) {
            throw new \InvalidArgumentException(
                'execution task identity does not match its hotel, tenant, or parent intent'
            );
        }
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $intent */
    private function assertExecutionTaskAllowsOperatorMutation(array $task, array $intent): void
    {
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) === 'canonical_ota_investigation'
            || strtolower(trim((string)($task['execution_mode'] ?? ''))) === 'analysis_only'
            || strtolower(trim((string)($intent['status'] ?? ''))) === 'system_authorized_analysis'
        ) {
            throw new \InvalidArgumentException('system-authorized analysis task is immutable');
        }
    }

    /** @param array<string,mixed> $intent */
    private function assertExecutionTaskAssignee(array $intent, int $operatorId): void
    {
        $target = $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $schedule = $this->arrayValue($target['workflow_schedule'] ?? []);
        $assigneeId = (int)($schedule['assignee_id'] ?? $target['assignee_id'] ?? 0);
        if ($assigneeId > 0 && $assigneeId !== $operatorId) {
            throw new \InvalidArgumentException('execution task can only be executed by its assignee');
        }
    }


    /** @param array{task:array<string,mixed>,intent:array<string,mixed>} $context */
    private function addExecutionEvidenceAuthorized(
        int $taskId,
        array $hotelIds,
        array $input,
        int $userId,
        array $context
    ): array {
        $task = $context['task'];
        $intent = $context['intent'];
        $this->assertExecutionTaskAllowsOperatorMutation($task, $intent);
        $this->assertManagedActionSourceCurrent($this->normalizeExecutionIntentRow($intent));
        $evidence = $this->arrayValue($input['evidence'] ?? $input);
        if (empty($evidence)) {
            throw new \InvalidArgumentException('execution evidence is required');
        }
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? $evidence['evidence_type'] ?? 'manual')));
        $this->assertManagedOperationExecutionEvidence(
            $this->normalizeExecutionIntentRow($intent),
            [
                'status' => (string)($task['status'] ?? 'executed'),
                'evidence_type' => $evidenceType,
                'evidence' => $evidence,
            ],
            $userId
        );
        $this->assertOperatorExecutionEvidenceBoundary($evidenceType, $evidence);
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $isRevenueNodeCheck = $evidenceType === 'revenue_node_check';
        if (!$this->executionEvidenceCanBeAddedAtStatus($evidenceType, $taskStatus)) {
            throw new \InvalidArgumentException('execution task must be executed before evidence can be added');
        }

        $payload = [
            'task_id' => $taskId,
            'evidence_type' => $evidenceType,
            'before' => $this->arrayValue($evidence['before'] ?? []),
            'after' => $this->arrayValue($evidence['after'] ?? []),
            'attachment_path' => trim((string)($evidence['attachment_path'] ?? '')),
            'platform_response' => $this->buildExecutionEvidencePlatformResponse($evidence, $task, $intent),
            'remark' => trim((string)($evidence['remark'] ?? '')),
            'created_by' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (!$this->hasNonEmptyValue($this->executionEvidenceContent($payload))) {
            throw new \InvalidArgumentException('execution evidence content is required');
        }
        if ($isRevenueNodeCheck
            && (($payload['platform_response']['node_record']['contract_version'] ?? '') !== 'operation_revenue_node.v2')
        ) {
            throw new \InvalidArgumentException('revenue node check requires operation_revenue_node.v2 identity');
        }
        $fingerprint = $this->executionEvidenceFingerprint($payload);
        $write = (function () use (
            $taskId,
            $task,
            $payload,
            $fingerprint
        ): array {
            $lockedTask = $task;
            $lockedStatus = strtolower(trim((string)($lockedTask['status'] ?? '')));
            if (!$this->executionEvidenceCanBeAddedAtStatus((string)$payload['evidence_type'], $lockedStatus)) {
                throw new \InvalidArgumentException('execution task must be executed before evidence can be added');
            }

            $existingId = $this->matchingExecutionEvidenceId($lockedTask, $fingerprint);
            if ($existingId > 0) {
                return ['id' => $existingId, 'created' => false];
            }
            if ($payload['evidence_type'] === 'compensation_receipt') {
                $this->assertCompensationReceiptIsCurrentAndComplete($lockedTask, $payload['platform_response']);
            }

            return [
                'id' => $this->insertExecutionEvidence($payload, (int)($lockedTask['tenant_id'] ?? 0)),
                'created' => true,
            ];
        })();

        $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
        $lifecycle = new OperationActionLifecycleService();
        if ((bool)$write['created'] && $lifecycle->isManagedIntent($normalizedIntent)) {
            $events = $lifecycle->eventsForIntent(
                (int)$normalizedIntent['tenant_id'],
                (int)$normalizedIntent['hotel_id'],
                (int)$normalizedIntent['id']
            );
            $currentStatus = $lifecycle->currentStatus(array_merge($normalizedIntent, [
                'tasks' => [$this->normalizeExecutionTaskRow($task)],
            ]), $events);
            $toStatus = $lifecycle->isDailyOneThingIntent($normalizedIntent)
                && $currentStatus === 'evidence_recorded'
                    ? 'review_pending'
                    : $currentStatus;
            $lifecycle->appendEvent(
                $normalizedIntent,
                $taskId,
                $currentStatus,
                $toStatus,
                'evidence_attached',
                $userId,
                [
                    'task_ref' => 'operation_execution_tasks#' . $taskId,
                    'evidence_ref' => 'operation_execution_evidence#' . (int)$write['id'],
                    'evidence_fingerprint' => $fingerprint,
                    'evidence_type' => $evidenceType,
                    'external_action_performed_by_system' => false,
                ]
            );
        }

        $detail = $this->executionTaskDetail($taskId, $hotelIds);
        $detail['evidence_write'] = [
            'evidence_id' => (int)$write['id'],
            'created' => (bool)$write['created'],
            'replayed' => !(bool)$write['created'],
            'fingerprint' => $fingerprint,
        ];
        return $detail;
    }

    /** @param array<string,mixed> $evidence */
    private function assertOperatorExecutionEvidenceBoundary(string $evidenceType, array $evidence): void
    {
        if (in_array($evidenceType, [
            'manual_finance',
            'manual_roi_evidence',
            'operator_attested_platform_readback',
            'source_verified_metric_readback',
        ], true)) {
            throw new \InvalidArgumentException(
                'effect evidence must be saved by the effect-review workflow, not as execution evidence'
            );
        }

        $effectMetricKeys = [
            'revenue', 'avg_revenue', 'amount', 'income', 'cost', 'roi',
            'orders', 'avg_orders', 'order_count', 'book_order_num',
            'room_nights', 'quantity', 'adr', 'occupancy', 'occ',
            'conversion', 'conversion_rate', 'order_rate', 'detail_rate', 'flow_rate', 'list_exposure',
        ];
        foreach (['before', 'after'] as $side) {
            $metrics = $this->arrayValue($evidence[$side] ?? []);
            foreach ($effectMetricKeys as $key) {
                if (array_key_exists($key, $metrics)) {
                    throw new \InvalidArgumentException(
                        'business outcome metrics must be saved separately from execution evidence'
                    );
                }
            }
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function executionEvidenceContent(array $payload): array
    {
        return [
            'before' => $payload['before'] ?? [],
            'after' => $payload['after'] ?? [],
            'attachment_path' => trim((string)($payload['attachment_path'] ?? '')),
            'platform_response' => $payload['platform_response'] ?? [],
            'remark' => trim((string)($payload['remark'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function executionEvidenceFingerprint(array $payload): string
    {
        $stable = [
            'evidence_type' => strtolower(trim((string)($payload['evidence_type'] ?? 'manual'))),
            ...$this->executionEvidenceContent($payload),
        ];
        return hash('sha256', json_encode(
            $this->canonicalizeDecisionValue($stable),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '{}');
    }

    /** @param array<string, mixed> $task */
    private function matchingExecutionEvidenceId(array $task, string $fingerprint): int
    {
        $query = Db::name('operation_execution_evidence')
            ->where('task_id', (int)($task['id'] ?? 0))
            ->whereNull('deleted_at');
        if (array_key_exists('tenant_id', $task)) {
            $query->where('tenant_id', (int)$task['tenant_id']);
        }
        $rows = $query->order('id', 'asc')->select()->toArray();
        foreach ($rows as $row) {
            $normalized = $this->normalizeExecutionEvidenceRow($row);
            if (hash_equals($fingerprint, $this->executionEvidenceFingerprint($normalized))) {
                return (int)($normalized['id'] ?? 0);
            }
        }
        return 0;
    }

    private function executionEvidenceCanBeAddedAtStatus(string $evidenceType, string $taskStatus): bool
    {
        if ($evidenceType === 'revenue_node_check') {
            return in_array($taskStatus, ['pending_execute', 'executing', 'executed'], true);
        }

        return $taskStatus === 'executed'
            || ($evidenceType === 'compensation_receipt' && $taskStatus === 'failed');
    }

    /** @param array<string, mixed> $task @param array<string, mixed> $receipt */
    private function assertCompensationReceiptIsCurrentAndComplete(array $task, array $receipt): void
    {
        foreach (['partial', 'applied', 'unapplied', 'affected_scope', 'compensation_status', 'manual_required', 'event_at'] as $field) {
            if (!array_key_exists($field, $receipt)) {
                throw new \InvalidArgumentException('compensation receipt missing required field: ' . $field);
            }
        }

        if ($receipt['partial'] !== true
            || !is_array($receipt['applied'])
            || $receipt['applied'] === []
            || !is_array($receipt['unapplied'])
            || $receipt['unapplied'] === []
            || !is_array($receipt['affected_scope'])
            || !is_bool($receipt['manual_required'])
        ) {
            throw new \InvalidArgumentException('compensation receipt is incomplete');
        }

        $scope = $receipt['affected_scope'];
        foreach (['platform', 'hotel_id', 'business_date'] as $field) {
            if (!array_key_exists($field, $scope) || trim((string)$scope[$field]) === '') {
                throw new \InvalidArgumentException('compensation receipt affected_scope is incomplete');
            }
        }
        if ((int)$scope['hotel_id'] !== (int)($task['hotel_id'] ?? 0)) {
            throw new \InvalidArgumentException('compensation receipt hotel_id is not permitted');
        }
        if (!in_array((string)$receipt['compensation_status'], ['success', 'failure'], true)) {
            throw new \InvalidArgumentException('compensation receipt status is not supported');
        }
        if (($receipt['compensation_status'] === 'success' && $receipt['manual_required'] !== false)
            || ($receipt['compensation_status'] === 'failure' && $receipt['manual_required'] !== true)
        ) {
            throw new \InvalidArgumentException('compensation receipt status and manual_required are inconsistent');
        }

        $receiptIdentity = trim((string)($receipt['receipt_id'] ?? $receipt['case_id'] ?? ''));
        if ($receiptIdentity === '') {
            throw new \InvalidArgumentException('compensation receipt identity is required');
        }

        $intent = Db::name('operation_execution_intents')
            ->where('id', (int)($task['intent_id'] ?? 0))
            ->where('hotel_id', (int)($task['hotel_id'] ?? 0))
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($intent)) {
            throw new \InvalidArgumentException('compensation receipt execution intent is missing');
        }
        if (strtolower(trim((string)$scope['platform'])) !== strtolower(trim((string)($intent['platform'] ?? '')))) {
            throw new \InvalidArgumentException('compensation receipt platform does not match the execution intent');
        }
        $businessDate = substr(trim((string)$scope['business_date']), 0, 10);
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? $dateStart)), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate) !== 1
            || ($dateStart !== '' && $businessDate < $dateStart)
            || ($dateEnd !== '' && $businessDate > $dateEnd)
        ) {
            throw new \InvalidArgumentException('compensation receipt business_date is outside the execution intent');
        }

        $eventText = trim((string)$receipt['event_at']);
        $eventDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $eventText);
        $eventErrors = \DateTimeImmutable::getLastErrors();
        if ($eventDate === false || ($eventErrors !== false && ($eventErrors['warning_count'] > 0 || $eventErrors['error_count'] > 0))) {
            throw new \InvalidArgumentException('compensation receipt event_at is invalid');
        }
        $eventAt = $eventDate->getTimestamp();
        if ($eventAt > time() + 300) {
            throw new \InvalidArgumentException('compensation receipt event_at cannot be in the future');
        }

        $rows = Db::name('operation_execution_evidence')
            ->where('task_id', (int)($task['id'] ?? 0))
            ->where('evidence_type', 'compensation_receipt')
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $existing = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
            $existingIdentity = trim((string)($existing['receipt_id'] ?? $existing['case_id'] ?? ''));
            if ($existingIdentity !== '' && hash_equals($existingIdentity, $receiptIdentity)) {
                throw new \InvalidArgumentException('duplicate compensation receipt');
            }
            $existingEventAt = strtotime(trim((string)($existing['event_at'] ?? '')));
            if ($existingEventAt !== false && $eventAt <= $existingEventAt) {
                throw new \InvalidArgumentException('stale or duplicate compensation receipt');
            }
        }
    }

}
