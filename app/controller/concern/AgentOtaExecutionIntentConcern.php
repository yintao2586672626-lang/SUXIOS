<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\OperationLog;
use app\model\SystemConfig;
use app\model\AiModelConfig;
use app\model\User as UserModel;
use app\service\AgentClosureReadinessService;
use app\service\AiDecisionQualityService;
use app\service\AiModelRoutingService;
use app\service\CompetitorPriceReadinessService;
use app\service\FeasibilityReportService;
use app\service\KnowledgeDecisionGateService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\RevenueAiOverviewService;
use app\service\RevenueForecastReadinessService;
use app\service\RevenuePricingRecommendationService;
use think\Response;
use think\facade\Db;

trait AgentOtaExecutionIntentConcern
{
    private function otaDiagnosisActionIdempotencyKey(int $recordId, int $actionIndex, array $action, array $input): string
    {
        $identity = [
            'record_id' => $recordId,
            'action_index' => $actionIndex,
            'action_item_id' => trim((string)($action['id'] ?? '')),
            'action_type' => trim((string)($input['action_type'] ?? '')),
            'platform' => trim((string)($input['platform'] ?? '')),
            'workflow_schedule' => (array)($input['target_value']['workflow_schedule'] ?? []),
        ];
        return 'ota_diagnosis_action_' . substr(hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        ), 0, 32);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function findOtaDiagnosisActionIntent(
        int $recordId,
        int $hotelId,
        int $actionIndex,
        string $idempotencyKey,
        array $action,
        string $actionType,
        array $workflowSchedule
    ): ?array {
        if (!$this->tableExists('operation_execution_intents')) {
            return null;
        }
        $linkedId = (int)($action['execution_intent_id'] ?? 0);
        $query = Db::name('operation_execution_intents')
            ->where('source_module', 'ota_diagnosis_saved')
            ->where('source_record_id', $recordId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at');
        if ($linkedId > 0) {
            $linked = (clone $query)->where('id', $linkedId)->find();
            if (is_array($linked) && $this->otaDiagnosisIntentMatchesIdentity(
                $linked,
                $idempotencyKey,
                $actionIndex,
                $action,
                $workflowSchedule
            )) {
                return $linked;
            }
        }

        foreach ($query->where('action_type', $actionType)->order('id', 'desc')->select()->toArray() as $row) {
            if (is_array($row) && $this->otaDiagnosisIntentMatchesIdentity(
                $row,
                $idempotencyKey,
                $actionIndex,
                $action,
                $workflowSchedule
            )) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $intent @param array<string, mixed> $action */
    private function otaDiagnosisIntentMatchesIdentity(
        array $intent,
        string $idempotencyKey,
        int $actionIndex,
        array $action,
        array $workflowSchedule
    ): bool {
        $evidence = json_decode((string)($intent['evidence_json'] ?? ''), true);
        $evidence = is_array($evidence) ? $evidence : [];
        $storedKey = trim((string)($evidence['action_idempotency_key'] ?? ''));
        if ($storedKey !== '') {
            return hash_equals($idempotencyKey, $storedKey);
        }

        $actionItemId = trim((string)($action['id'] ?? ''));
        $storedActionItemId = trim((string)($evidence['action_item_id'] ?? ''));
        $legacyActionMatches = (int)($evidence['action_index'] ?? -1) === $actionIndex
            || ($actionItemId !== '' && $storedActionItemId !== '' && hash_equals($actionItemId, $storedActionItemId));

        return $legacyActionMatches
            && $this->otaDiagnosisIntentWorkflowSchedule($intent) === $workflowSchedule;
    }

    /** @param array<string, mixed> $intent @return array<string, mixed> */
    private function otaDiagnosisIntentWorkflowSchedule(array $intent): array
    {
        $targetValue = $intent['target_value'] ?? null;
        if (!is_array($targetValue)) {
            $targetValue = json_decode((string)($intent['target_value_json'] ?? ''), true);
        }
        $targetValue = is_array($targetValue) ? $targetValue : [];
        $schedule = is_array($targetValue['workflow_schedule'] ?? null)
            ? $targetValue['workflow_schedule']
            : [];
        if ($schedule === [] && (int)($targetValue['assignee_id'] ?? 0) > 0) {
            $schedule = [
                'assignee_id' => (int)$targetValue['assignee_id'],
                'due_at' => trim((string)($targetValue['due_at'] ?? '')),
                'review_at' => trim((string)($targetValue['review_at'] ?? '')),
                'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
            ];
        }

        return $schedule;
    }

    private function isRetryableOtaDiagnosisIntentTerminal(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['failed', 'failure', 'rejected', 'cancelled', 'canceled'], true);
    }

    /** @param array<string, mixed> $intent */
    private function otaDiagnosisIntentAttempt(array $intent): int
    {
        $evidence = json_decode((string)($intent['evidence_json'] ?? ''), true);
        return max(1, (int)(is_array($evidence) ? ($evidence['intent_attempt'] ?? 1) : 1));
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $snapshot @param array<string, mixed> $input */
    private function otaDiagnosisIntentSummary(array $existing, int $hotelId, array $snapshot, array $input): array
    {
        $targetValue = json_decode((string)($existing['target_value_json'] ?? ''), true);
        return [
            'id' => (int)($existing['id'] ?? 0),
            'status' => (string)($existing['status'] ?? ''),
            'blocked_reason' => (string)($existing['blocked_reason'] ?? ''),
            'hotel_id' => (int)($existing['hotel_id'] ?? $hotelId),
            'platform' => (string)($existing['platform'] ?? $snapshot['platform'] ?? ''),
            'source_module' => (string)($existing['source_module'] ?? $input['source_module']),
            'source_record_id' => (int)($existing['source_record_id'] ?? 0),
            'target_value' => is_array($targetValue) ? $targetValue : [],
            'workflow_schedule' => $this->otaDiagnosisIntentWorkflowSchedule($existing),
        ];
    }

}
