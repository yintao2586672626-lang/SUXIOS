<?php
declare(strict_types=1);

namespace app\service;

use app\model\AgentConversation;
use app\model\AgentWorkOrder;
use app\model\EnergySavingSuggestion;
use app\model\KnowledgeBase;
use app\model\MaintenancePlan;

final class AgentClosureReadinessService
{
    public function enrichConversationRows(iterable $rows, array $workOrdersByConversationId = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $data = $this->withConversationDisplayFields($data);
            $conversationId = $this->intValue($data, 'id');
            $linkedOrders = $workOrdersByConversationId[$conversationId] ?? [];
            $data['service_readiness'] = $this->buildConversationReadiness($data, is_array($linkedOrders) ? $linkedOrders : []);
            $result[] = $data;
        }

        return $result;
    }

    public function buildConversationReadiness(array $row, array $linkedOrders = []): array
    {
        $intent = $this->intValue($row, 'intent_type');
        $emotionScore = $this->floatValue($row, 'emotion_score');
        $confidenceScore = $this->floatValue($row, 'confidence_score');
        $needsWorkOrder = in_array($intent, [AgentConversation::INTENT_COMPLAINT, AgentConversation::INTENT_SERVICE], true) || $emotionScore >= 0.4;
        $missing = [];

        if (!empty($linkedOrders)) {
            $best = $this->bestLinkedWorkOrderReadiness($linkedOrders);
            return $this->withNotice([
                'stage' => (string)($best['stage'] ?? 'work_order_linked'),
                'status_label' => (string)($best['status_label'] ?? '已转工单'),
                'score' => (int)($best['score'] ?? 55),
                'closed_loop' => ($best['closed_loop'] ?? false) === true,
                'next_action' => (string)($best['next_action'] ?? '跟进关联工单'),
                'missing_evidence' => array_values(array_filter((array)($best['missing_evidence'] ?? []), 'is_array')),
                'linked_work_order_count' => count($linkedOrders),
                'linked_work_order_ids' => array_values(array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $linkedOrders)),
                'can_create_work_order' => false,
            ]);
        }

        if ($needsWorkOrder) {
            $missing[] = $this->missing('work_order', '服务工单', '转成工单并记录处理结果');
            if ($intent === AgentConversation::INTENT_COMPLAINT || $emotionScore >= 0.6) {
                $missing[] = $this->missing('emotion_followup', '客诉/情绪跟进', '补客诉安抚和责任人');
            }
            return $this->withNotice([
                'stage' => 'conversation_needs_work_order',
                'status_label' => '待转工单',
                'score' => $emotionScore >= 0.6 ? 25 : 35,
                'closed_loop' => false,
                'next_action' => '转成工单并记录处理结果',
                'missing_evidence' => $missing,
                'linked_work_order_count' => 0,
                'linked_work_order_ids' => [],
                'can_create_work_order' => true,
            ]);
        }

        if ($confidenceScore > 0 && $confidenceScore < 0.6) {
            $missing[] = $this->missing('manual_review', '低置信人工复核', '人工复核回复质量或补知识库');
            return $this->withNotice([
                'stage' => 'low_confidence_review',
                'status_label' => '低置信待复核',
                'score' => 45,
                'closed_loop' => false,
                'next_action' => '人工复核回复质量或补知识库',
                'missing_evidence' => $missing,
                'linked_work_order_count' => 0,
                'linked_work_order_ids' => [],
                'can_create_work_order' => false,
            ]);
        }

        return $this->withNotice([
            'stage' => 'conversation_observed',
            'status_label' => '可观察归档',
            'score' => 70,
            'closed_loop' => false,
            'next_action' => '无需转工单；保留对话记录',
            'missing_evidence' => [],
            'linked_work_order_count' => 0,
            'linked_work_order_ids' => [],
            'can_create_work_order' => false,
        ]);
    }

    public function enrichKnowledgeRows(iterable $rows, array $usageByKnowledgeId = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $knowledgeId = $this->intValue($data, 'id');
            $usage = $usageByKnowledgeId[$knowledgeId] ?? [];
            $data['knowledge_readiness'] = $this->buildKnowledgeReadiness($data, is_array($usage) ? $usage : []);
            $result[] = $data;
        }

        return $result;
    }

    public function buildKnowledgeReadiness(array $row, array $usage = []): array
    {
        $isEnabled = $this->intValue($row, 'is_enabled', KnowledgeBase::STATUS_DISABLED) === KnowledgeBase::STATUS_ENABLED;
        $content = $this->stringValue($row, 'content');
        $keywords = $this->stringValue($row, 'keywords');
        $tags = $this->listValue($row['tags'] ?? []);
        $conversationCount = max(
            $this->intValue($usage, 'conversation_count'),
            $this->intValue($row, 'conversation_count')
        );
        $latestUsedAt = $this->stringValue($usage, 'latest_used_at');
        if ($latestUsedAt === '') {
            $latestUsedAt = $this->stringValue($row, 'latest_used_at');
        }

        if (!$isEnabled) {
            $readiness = $this->readiness('knowledge_disabled', '未启用', 20, false, '启用该知识或归档无效条目', [
                $this->missing('enabled_status', '启用状态', '确认知识是否应启用或归档'),
            ]);
        } else {
            $missing = [];
            if ($this->textLength($content) < 20) {
                $missing[] = $this->missing('content', '知识正文', '补充可回答的正文、适用边界和处理口径');
            }
            if ($keywords === '' && empty($tags)) {
                $missing[] = $this->missing('retrieval_keywords', '检索关键词/标签', '补充关键词或标签，保证对话可命中');
            }

            if (!empty($missing)) {
                $stage = $this->textLength($content) < 20 ? 'knowledge_missing_content' : 'knowledge_missing_keywords';
                $readiness = $this->readiness($stage, '待补知识', $stage === 'knowledge_missing_content' ? 30 : 45, false, '补齐正文和检索入口后再观察命中', $missing);
            } elseif ($conversationCount > 0) {
                $readiness = $this->readiness('knowledge_active_used', '已启用被引用', 100, true, '持续复核命中质量和回答效果');
            } else {
                $readiness = $this->readiness('knowledge_active_unused', '已启用未引用', 65, false, '观察对话命中，必要时补关键词或调整内容', [
                    $this->missing('conversation_usage', '对话引用', '等待真实对话命中或补充检索关键词'),
                ]);
            }
        }

        $readiness['conversation_count'] = $conversationCount;
        $readiness['latest_used_at'] = $latestUsedAt;
        $readiness['can_edit_knowledge'] = true;

        return $this->withNotice($readiness);
    }

    public function enrichWorkOrderRows(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $data = $this->withWorkOrderDisplayFields($data);
            $data['closure_readiness'] = $this->buildWorkOrderReadiness($data);
            $result[] = $data;
        }

        return $result;
    }

    public function buildWorkOrderReadiness(array $row): array
    {
        $status = $this->intValue($row, 'status');
        $priority = $this->intValue($row, 'priority');
        $assignedTo = $this->intValue($row, 'assigned_to');
        $emotionScore = $this->floatValue($row, 'emotion_score');
        $missing = [];

        if ($status === AgentWorkOrder::STATUS_CLOSED) {
            $readiness = $this->readiness('service_closed', '服务已关闭', 100, true, '保留服务记录，必要时进入复盘');
        } elseif ($status === AgentWorkOrder::STATUS_RESOLVED) {
            $missing[] = $this->missing('close_review', '关闭确认', '确认服务结果并关闭工单');
            $readiness = $this->readiness('resolved_pending_review', '已解决待确认', 80, false, '确认客诉/服务结果并关闭工单', $missing);
        } elseif ($status === AgentWorkOrder::STATUS_ESCALATED) {
            $missing[] = $this->missing('escalation_review', '升级处理结论', '明确升级原因、责任人和处理结论');
            $readiness = $this->readiness('escalated_blocked', '已升级待处理', 30, false, '处理升级原因并回写结论', $missing);
        } elseif (in_array($status, [AgentWorkOrder::STATUS_PROCESSING, AgentWorkOrder::STATUS_WAITING], true)) {
            $missing[] = $this->missing('resolution', '解决方案', '补充解决方案并标记已解决');
            $readiness = $this->readiness('in_progress', '处理中未闭环', 55, false, '补解决方案并完成工单', $missing);
        } elseif ($status === AgentWorkOrder::STATUS_PENDING && $assignedTo > 0) {
            $missing[] = $this->missing('processing_start', '处理动作', '推进处理并记录解决方案');
            $readiness = $this->readiness('pending_processing', '待处理未闭环', 35, false, '推进处理并记录解决方案', $missing);
        } elseif ($status === AgentWorkOrder::STATUS_PENDING) {
            $missing[] = $this->missing('assignee', '处理人', '分配处理人');
            $missing[] = $this->missing('resolution', '解决方案', '处理后回写解决方案');
            $readiness = $this->readiness('pending_assignment', '待分配未闭环', 20, false, '分配处理人并开始处理', $missing);
        } else {
            $missing[] = $this->missing('status', '有效状态', '核对工单状态枚举');
            $readiness = $this->readiness('unknown', '状态待核验', 10, false, '核对工单状态后再判断闭环', $missing);
        }

        if (!in_array($status, [AgentWorkOrder::STATUS_RESOLVED, AgentWorkOrder::STATUS_CLOSED, AgentWorkOrder::STATUS_ESCALATED], true)) {
            if ($priority === AgentWorkOrder::PRIORITY_URGENT) {
                $readiness = $this->appendMissing($readiness, $this->missing('urgent_response', '紧急响应记录', '补充紧急工单响应记录'));
            }
            if ($emotionScore >= 0.4) {
                $readiness = $this->appendMissing($readiness, $this->missing('emotion_followup', '情绪安抚记录', '补充客诉情绪跟进记录'));
            }
        }

        return $this->withNotice($readiness);
    }

    public function enrichEnergySuggestionRows(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $data = $this->withEnergySuggestionDisplayFields($data);
            $data['closure_readiness'] = $this->buildEnergySuggestionReadiness($data);
            $result[] = $data;
        }

        return $result;
    }

    public function buildEnergySuggestionReadiness(array $row): array
    {
        $status = $this->intValue($row, 'status');
        $actualSaving = $this->floatValue($row, 'actual_saving');
        $missing = [];

        if ($status === EnergySavingSuggestion::STATUS_COMPLETED && $actualSaving > 0) {
            $readiness = $this->readiness('saving_verified', '节省已验证', 100, true, '保留实际节省和复盘证据');
        } elseif ($status === EnergySavingSuggestion::STATUS_COMPLETED) {
            $missing[] = $this->missing('actual_saving', '实际节省', '补实际节省或效果复盘');
            $readiness = $this->readiness('completed_pending_saving', '已完成缺效果', 80, false, '补实际节省/效果复盘证据', $missing);
        } elseif ($status === EnergySavingSuggestion::STATUS_IMPLEMENTING) {
            $missing[] = $this->missing('implementation_result', '实施结果', '完成实施并记录实际节省');
            $readiness = $this->readiness('implementing', '实施中未闭环', 60, false, '完成实施并回写效果', $missing);
        } elseif ($status === EnergySavingSuggestion::STATUS_APPROVED) {
            $missing[] = $this->missing('implementation_start', '实施启动', '启动实施并指定实施人');
            $readiness = $this->readiness('approved_pending_start', '已批准待实施', 45, false, '启动实施并记录负责人', $missing);
        } elseif ($status === EnergySavingSuggestion::STATUS_PENDING) {
            $missing[] = $this->missing('manual_approval', '人工评估/批准', '人工评估后批准或拒绝');
            $readiness = $this->readiness('pending_approval', '待评估未闭环', 25, false, '人工评估后批准或拒绝', $missing);
        } elseif ($status === EnergySavingSuggestion::STATUS_REJECTED) {
            $missing[] = $this->missing('rejection_reason', '拒绝原因', '保留拒绝原因，避免重复建议');
            $readiness = $this->readiness('rejected', '已拒绝未执行', 25, false, '保留拒绝原因或重新评估', $missing);
        } else {
            $missing[] = $this->missing('status', '有效状态', '核对节能建议状态枚举');
            $readiness = $this->readiness('unknown', '状态待核验', 10, false, '核对建议状态后再判断闭环', $missing);
        }

        return $this->withNotice($readiness);
    }

    public function enrichMaintenancePlanRows(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $data = $this->withMaintenancePlanDisplayFields($data);
            $data['closure_readiness'] = $this->buildMaintenancePlanReadiness($data);
            $result[] = $data;
        }

        return $result;
    }

    public function buildMaintenancePlanReadiness(array $row): array
    {
        $status = $this->intValue($row, 'status');
        $executionCount = $this->intValue($row, 'execution_count');
        $lastMaintenanceDate = $this->stringValue($row, 'last_maintenance_date');
        $nextDate = $this->maintenanceNextDate($row);
        $today = date('Y-m-d');
        $missing = [];

        if ($status === MaintenancePlan::STATUS_CANCELLED) {
            $missing[] = $this->missing('cancel_reason', '取消原因', '保留取消原因或重新启用计划');
            return $this->withNotice($this->readiness('cancelled', '已取消', 20, false, '保留取消原因或重新启用', $missing));
        }

        if ($status === MaintenancePlan::STATUS_PAUSED) {
            $missing[] = $this->missing('resume_decision', '恢复/关闭决策', '明确恢复、关闭或替代维护计划');
            return $this->withNotice($this->readiness('paused', '已暂停', 25, false, '明确恢复或关闭计划', $missing));
        }

        if ($status === MaintenancePlan::STATUS_COMPLETED) {
            if ($executionCount > 0 || $lastMaintenanceDate !== '') {
                return $this->withNotice($this->readiness('maintenance_completed', '维护已完成', 100, true, '保留维护记录和成本证据'));
            }
            $missing[] = $this->missing('execution_evidence', '执行记录', '补维护执行记录');
            return $this->withNotice($this->readiness('executed_pending_review', '完成缺执行证据', 70, false, '补维护执行记录', $missing));
        }

        if ($status !== MaintenancePlan::STATUS_ACTIVE) {
            $missing[] = $this->missing('status', '有效状态', '核对维护计划状态枚举');
            return $this->withNotice($this->readiness('unknown', '状态待核验', 10, false, '核对计划状态后再判断闭环', $missing));
        }

        if ($nextDate === '') {
            $missing[] = $this->missing('next_maintenance_date', '下次维护日期', '补维护频率或上次维护日期');
            return $this->withNotice($this->readiness('active_missing_schedule', '启用缺排期', 35, false, '补维护频率/排期', $missing));
        }

        if ($nextDate < $today) {
            $missing[] = $this->missing('execution', '逾期执行记录', '执行本轮维护并生成维护记录');
            return $this->withNotice($this->readiness('overdue', '已逾期未执行', 30, false, '执行本轮维护并生成记录', $missing));
        }

        if ($nextDate <= date('Y-m-d', strtotime('+7 days'))) {
            $missing[] = $this->missing('execution', '即将到期执行记录', '按计划执行并生成维护记录');
            return $this->withNotice($this->readiness('maintenance_due', '即将到期', 45, false, '按计划执行并生成维护记录', $missing));
        }

        if ($executionCount > 0 || $lastMaintenanceDate !== '') {
            return $this->withNotice($this->readiness('maintenance_completed', '本轮已闭环', 100, true, '等待下一维护周期'));
        }

        $missing[] = $this->missing('execution_evidence', '首轮执行记录', '到期后执行并生成维护记录');
        return $this->withNotice($this->readiness('active_pending_execution', '已排期待执行', 55, false, '到期后执行并生成维护记录', $missing));
    }

    private function withWorkOrderDisplayFields(array $row): array
    {
        $row['source_type_name'] = $row['source_type_name'] ?? $this->nameFromMap($this->intValue($row, 'source_type'), [
            AgentWorkOrder::SOURCE_CHAT => '客服对话',
            AgentWorkOrder::SOURCE_VOICE => '语音投诉',
            AgentWorkOrder::SOURCE_SYSTEM => '系统告警',
            AgentWorkOrder::SOURCE_MANUAL => '人工创建',
        ]);
        $row['order_type_name'] = $row['order_type_name'] ?? $this->nameFromMap($this->intValue($row, 'order_type'), [
            AgentWorkOrder::TYPE_COMPLAINT => '客诉处理',
            AgentWorkOrder::TYPE_MAINTENANCE => '维修需求',
            AgentWorkOrder::TYPE_SERVICE => '服务请求',
            AgentWorkOrder::TYPE_CLEANING => '清洁需求',
            AgentWorkOrder::TYPE_OTHER => '其他',
        ]);
        $row['priority_name'] = $row['priority_name'] ?? $this->nameFromMap($this->intValue($row, 'priority'), [
            AgentWorkOrder::PRIORITY_LOW => '低',
            AgentWorkOrder::PRIORITY_NORMAL => '中',
            AgentWorkOrder::PRIORITY_HIGH => '高',
            AgentWorkOrder::PRIORITY_URGENT => '紧急',
        ]);
        $row['status_name'] = $row['status_name'] ?? $this->nameFromMap($this->intValue($row, 'status'), [
            AgentWorkOrder::STATUS_PENDING => '待处理',
            AgentWorkOrder::STATUS_PROCESSING => '处理中',
            AgentWorkOrder::STATUS_WAITING => '等待反馈',
            AgentWorkOrder::STATUS_RESOLVED => '已解决',
            AgentWorkOrder::STATUS_CLOSED => '已关闭',
            AgentWorkOrder::STATUS_ESCALATED => '已升级',
        ]);

        return $row;
    }

    private function withConversationDisplayFields(array $row): array
    {
        $row['channel_name'] = $row['channel_name'] ?? $this->nameFromMap($this->intValue($row, 'channel'), [
            AgentConversation::CHANNEL_WECHAT => '微信',
            AgentConversation::CHANNEL_WORKWECHAT => '企业微信',
            AgentConversation::CHANNEL_IPAD => 'iPad前台',
            AgentConversation::CHANNEL_PHONE => '电话',
            AgentConversation::CHANNEL_APP => 'APP',
        ]);
        $row['message_type_name'] = $row['message_type_name'] ?? $this->nameFromMap($this->intValue($row, 'message_type'), [
            AgentConversation::MSG_TYPE_TEXT => '文本',
            AgentConversation::MSG_TYPE_IMAGE => '图片',
            AgentConversation::MSG_TYPE_VOICE => '语音',
            AgentConversation::MSG_TYPE_RICH => '富文本',
        ]);
        $row['intent_type_name'] = $row['intent_type_name'] ?? $this->nameFromMap($this->intValue($row, 'intent_type'), [
            AgentConversation::INTENT_GREETING => '问候',
            AgentConversation::INTENT_INQUIRY => '咨询',
            AgentConversation::INTENT_COMPLAINT => '投诉',
            AgentConversation::INTENT_BOOKING => '预订',
            AgentConversation::INTENT_SERVICE => '服务请求',
            AgentConversation::INTENT_CHECKOUT => '退房',
            AgentConversation::INTENT_OTHER => '其他',
        ]);

        return $row;
    }

    private function bestLinkedWorkOrderReadiness(array $linkedOrders): array
    {
        $best = [];
        foreach ($linkedOrders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $readiness = $this->buildWorkOrderReadiness($order);
            if (empty($best) || (int)($readiness['score'] ?? 0) >= (int)($best['score'] ?? 0)) {
                $best = $readiness;
            }
        }

        if (empty($best)) {
            return [
                'stage' => 'work_order_linked',
                'status_label' => '已转工单',
                'score' => 55,
                'closed_loop' => false,
                'next_action' => '跟进关联工单',
                'missing_evidence' => [$this->missing('work_order_status', '工单状态', '核对关联工单状态')],
            ];
        }

        if (($best['closed_loop'] ?? false) === true) {
            $best['stage'] = 'conversation_service_closed';
            $best['status_label'] = '服务已闭环';
            $best['next_action'] = '保留对话和工单记录';
            return $best;
        }

        $best['stage'] = 'work_order_linked_' . (string)($best['stage'] ?? 'pending');
        $best['status_label'] = '已转工单';
        $best['next_action'] = (string)($best['next_action'] ?? '跟进关联工单');

        return $best;
    }

    private function withEnergySuggestionDisplayFields(array $row): array
    {
        $row['energy_type_name'] = $row['energy_type_name'] ?? $this->nameFromMap($this->intValue($row, 'energy_type'), [
            EnergySavingSuggestion::TYPE_ELECTRICITY => '电',
            EnergySavingSuggestion::TYPE_WATER => '水',
            EnergySavingSuggestion::TYPE_GAS => '燃气',
            EnergySavingSuggestion::TYPE_ALL => '综合',
        ]);
        $row['suggestion_type_name'] = $row['suggestion_type_name'] ?? $this->nameFromMap($this->intValue($row, 'suggestion_type'), [
            EnergySavingSuggestion::SUGGESTION_EQUIPMENT => '设备优化',
            EnergySavingSuggestion::SUGGESTION_OPERATION => '运营调整',
            EnergySavingSuggestion::SUGGESTION_BEHAVIOR => '行为改变',
            EnergySavingSuggestion::SUGGESTION_UPGRADE => '设备升级',
            EnergySavingSuggestion::SUGGESTION_RENEWABLE => '可再生能源',
        ]);
        $row['priority_name'] = $row['priority_name'] ?? $this->nameFromMap($this->intValue($row, 'priority'), [
            EnergySavingSuggestion::PRIORITY_LOW => '低',
            EnergySavingSuggestion::PRIORITY_MEDIUM => '中',
            EnergySavingSuggestion::PRIORITY_HIGH => '高',
            EnergySavingSuggestion::PRIORITY_CRITICAL => '紧急',
        ]);
        $row['status_name'] = $row['status_name'] ?? $this->nameFromMap($this->intValue($row, 'status'), [
            EnergySavingSuggestion::STATUS_PENDING => '待评估',
            EnergySavingSuggestion::STATUS_APPROVED => '已批准',
            EnergySavingSuggestion::STATUS_IMPLEMENTING => '实施中',
            EnergySavingSuggestion::STATUS_COMPLETED => '已完成',
            EnergySavingSuggestion::STATUS_REJECTED => '已拒绝',
        ]);
        if (!isset($row['expected_saving'])) {
            $row['expected_saving'] = $this->floatValue($row, 'potential_saving');
        }

        return $row;
    }

    private function withMaintenancePlanDisplayFields(array $row): array
    {
        $row['plan_type_name'] = $row['plan_type_name'] ?? $this->nameFromMap($this->intValue($row, 'plan_type'), [
            MaintenancePlan::TYPE_DAILY => '日常保养',
            MaintenancePlan::TYPE_WEEKLY => '周保养',
            MaintenancePlan::TYPE_MONTHLY => '月保养',
            MaintenancePlan::TYPE_QUARTERLY => '季度保养',
            MaintenancePlan::TYPE_YEARLY => '年度保养',
            MaintenancePlan::TYPE_CUSTOM => '自定义',
        ]);
        $row['priority_name'] = $row['priority_name'] ?? $this->nameFromMap($this->intValue($row, 'priority'), [
            MaintenancePlan::PRIORITY_LOW => '低',
            MaintenancePlan::PRIORITY_NORMAL => '中',
            MaintenancePlan::PRIORITY_HIGH => '高',
            MaintenancePlan::PRIORITY_CRITICAL => '紧急',
        ]);
        $row['status_name'] = $row['status_name'] ?? $this->nameFromMap($this->intValue($row, 'status'), [
            MaintenancePlan::STATUS_ACTIVE => '启用',
            MaintenancePlan::STATUS_PAUSED => '暂停',
            MaintenancePlan::STATUS_COMPLETED => '完成',
            MaintenancePlan::STATUS_CANCELLED => '取消',
        ]);
        $row['next_maintenance_date'] = $row['next_maintenance_date'] ?? $this->maintenanceNextDate($row);

        return $row;
    }

    private function readiness(string $stage, string $label, int $score, bool $closedLoop, string $nextAction, array $missingEvidence = []): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => $closedLoop,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
        ];
    }

    private function appendMissing(array $readiness, array $missing): array
    {
        $readiness['missing_evidence'][] = $missing;
        if ($readiness['closed_loop']) {
            $readiness['closed_loop'] = false;
            $readiness['score'] = min((int) $readiness['score'], 85);
        }

        return $readiness;
    }

    private function missing(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    private function withNotice(array $readiness): array
    {
        $missing = $readiness['missing_evidence'] ?? [];
        if (!$missing) {
            $readiness['notice'] = '已具备当前行的闭环证据';
            return $readiness;
        }

        $labels = array_map(static function (array $item): string {
            return (string) ($item['label'] ?? $item['code'] ?? '未命名缺口');
        }, $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }

    private function rowToArray($row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }

        return (array) $row;
    }

    private function maintenanceNextDate(array $row): string
    {
        $explicit = $this->stringValue($row, 'next_maintenance_date');
        if ($explicit !== '') {
            return substr($explicit, 0, 10);
        }

        $nextExecute = $this->stringValue($row, 'next_execute_date');
        if ($nextExecute !== '') {
            return substr($nextExecute, 0, 10);
        }

        $frequencyDays = $this->intValue($row, 'frequency_days');
        if ($frequencyDays <= 0) {
            return '';
        }

        $lastDate = $this->stringValue($row, 'last_maintenance_date');
        if ($lastDate !== '' && strtotime($lastDate) !== false) {
            return date('Y-m-d', strtotime(substr($lastDate, 0, 10) . ' + ' . $frequencyDays . ' days'));
        }

        return date('Y-m-d', strtotime('+ ' . $frequencyDays . ' days'));
    }

    private function nameFromMap(int $value, array $map): string
    {
        return $map[$value] ?? '未知';
    }

    private function intValue(array $row, string $key, int $default = 0): int
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            return $default;
        }

        return (int) $row[$key];
    }

    private function floatValue(array $row, string $key, float $default = 0.0): float
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            return $default;
        }

        return (float) $row[$key];
    }

    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return '';
        }

        return trim((string) $row[$key]);
    }

    private function listValue($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn($item): bool => trim((string) $item) !== ''));
        }

        if (!is_string($value)) {
            return [];
        }

        $text = trim($value);
        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn($item): bool => trim((string) $item) !== ''));
        }

        $items = preg_split('/[,，;；]+/u', $text) ?: [];
        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }
}
