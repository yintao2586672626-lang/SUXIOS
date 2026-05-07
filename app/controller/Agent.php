<?php
declare(strict_types=1);

namespace app\controller;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\AgentTask;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\EnergyConsumption;
use app\model\Device;
use app\model\DeviceCategory;
use app\model\DeviceMaintenance;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\AgentWorkOrder;
use app\model\AgentConversation;
use app\model\EnergyBenchmark;
use app\model\EnergySavingSuggestion;
use app\model\MaintenancePlan;
use think\Response;

/**
 * Agent控制器
 * 管理三个AI Agent的功能：智能员工、收益管理、资产运维
 */
class Agent extends Base
{
    /**
     * 检查管理员权限
     */
    protected function checkAdmin(): void
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            abort(403, '只有超级管理员可以访问Agent功能');
        }
    }

    // ==================== Agent概览 ====================

    /**
     * 获取Agent概览数据
     */
    public function overview(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 获取三个Agent的状态
        $agentConfigs = AgentConfig::where('hotel_id', $hotelId)
            ->column('agent_type, is_enabled', 'agent_type');
        
        // 获取今日任务统计
        $todayTasks = AgentTask::where('hotel_id', $hotelId)
            ->whereDay('create_time', date('Y-m-d'))
            ->field('agent_type, status, COUNT(*) as count')
            ->group('agent_type, status')
            ->select();
        
        $taskStats = [];
        foreach ($todayTasks as $task) {
            $type = $task['agent_type'];
            $status = $task['status'];
            if (!isset($taskStats[$type])) {
                $taskStats[$type] = [
                    'total' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'completed' => 0,
                    'failed' => 0,
                ];
            }
            $taskStats[$type]['total'] += $task['count'];
            if ($status == AgentTask::STATUS_PENDING) {
                $taskStats[$type]['pending'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_RUNNING) {
                $taskStats[$type]['running'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_COMPLETED) {
                $taskStats[$type]['completed'] = $task['count'];
            } elseif ($status == AgentTask::STATUS_FAILED) {
                $taskStats[$type]['failed'] = $task['count'];
            }
        }
        
        // 获取最近日志
        $recentLogs = AgentLog::where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(10)
            ->select();
        
        return $this->success([
            'agents' => [
                'staff' => [
                    'name' => '智能员工Agent',
                    'type' => AgentConfig::AGENT_TYPE_STAFF,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_STAFF]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_STAFF] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '👥',
                    'description' => '前台客服、工单处理、知识库问答',
                ],
                'revenue' => [
                    'name' => '收益管理Agent',
                    'type' => AgentConfig::AGENT_TYPE_REVENUE,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_REVENUE]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_REVENUE] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '💰',
                    'description' => '竞对价格监控、定价建议、需求预测',
                ],
                'asset' => [
                    'name' => '资产运维Agent',
                    'type' => AgentConfig::AGENT_TYPE_ASSET,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_ASSET]['is_enabled'] ?? 0) == 1,
                    'tasks' => $taskStats[AgentConfig::AGENT_TYPE_ASSET] ?? ['total' => 0, 'pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0],
                    'icon' => '🔧',
                    'description' => '能耗监控、设备维护预警',
                ],
            ],
            'recent_logs' => $recentLogs,
        ]);
    }

    // ==================== Agent配置 ====================

    /**
     * 获取Agent配置
     */
    public function getConfig(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        
        $config = AgentConfig::where('hotel_id', $hotelId)
            ->where('agent_type', $agentType)
            ->find();
        
        if (!$config) {
            // 返回默认配置
            $defaultConfigs = [
                AgentConfig::AGENT_TYPE_STAFF => [
                    'auto_reply' => true,
                    'work_order_auto_create' => true,
                    'knowledge_base_enabled' => true,
                    'max_response_time' => 30,
                    'notification_channels' => ['wechat', 'sms'],
                ],
                AgentConfig::AGENT_TYPE_REVENUE => [
                    'price_monitor_interval' => 60,
                    'auto_pricing_enabled' => false,
                    'pricing_strategy' => 'balanced',
                    'min_profit_margin' => 15,
                    'max_price_adjustment' => 20,
                    'notification_channels' => ['wechat'],
                ],
                AgentConfig::AGENT_TYPE_ASSET => [
                    'energy_monitor_enabled' => true,
                    'anomaly_detection_enabled' => true,
                    'maintenance_reminder_days' => 7,
                    'energy_alert_threshold' => 20,
                    'notification_channels' => ['wechat'],
                ],
            ];
            
            return $this->success([
                'agent_type' => $agentType,
                'is_enabled' => false,
                'config_data' => $defaultConfigs[$agentType] ?? [],
            ]);
        }
        
        return $this->success($config);
    }

    /**
     * 保存Agent配置
     */
    public function saveConfig(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'is_enabled' => 'require|integer|in:0,1',
        ]);
        
        $config = AgentConfig::where('hotel_id', $data['hotel_id'])
            ->where('agent_type', $data['agent_type'])
            ->find();
        
        if (!$config) {
            $config = new AgentConfig();
            $config->hotel_id = $data['hotel_id'];
            $config->agent_type = $data['agent_type'];
        }
        
        $config->is_enabled = $data['is_enabled'];
        $config->config_data = $data['config_data'] ?? [];
        $config->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'config_update',
            'Agent配置已更新',
            AgentLog::LEVEL_INFO,
            ['is_enabled' => $data['is_enabled']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '配置保存成功');
    }

    // ==================== 智能员工Agent ====================

    /**
     * 获取知识库列表
     */
    public function knowledgeList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $query = KnowledgeBase::where('hotel_id', $hotelId);
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        if ($keyword) {
            $query->searchKeyword($keyword);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('sort_order', 'asc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 保存知识库条目
     */
    public function saveKnowledge(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        if (!empty($data['id'])) {
            $knowledge = KnowledgeBase::find($data['id']);
            if (!$knowledge) {
                return $this->error('知识库条目不存在');
            }
        } else {
            $knowledge = new KnowledgeBase();
            $knowledge->hotel_id = $data['hotel_id'];
        }
        
        $knowledge->category_id = $data['category_id'] ?? 0;
        $knowledge->title = $data['title'];
        $knowledge->content = $data['content'];
        $knowledge->keywords = $data['keywords'] ?? '';
        $knowledge->tags = $data['tags'] ?? [];
        $knowledge->sort_order = $data['sort_order'] ?? 0;
        $knowledge->is_enabled = $data['is_enabled'] ?? 1;
        $knowledge->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_update',
            '知识库条目已保存: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['knowledge_id' => $knowledge->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $knowledge->id], '保存成功');
    }

    /**
     * 删除知识库条目
     */
    public function deleteKnowledge(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $knowledge = KnowledgeBase::find($id);
        
        if (!$knowledge) {
            return $this->error('知识库条目不存在');
        }
        
        $hotelId = $knowledge->hotel_id;
        $title = $knowledge->title;
        $knowledge->delete();
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_delete',
            '知识库条目已删除: ' . $title,
            AgentLog::LEVEL_WARNING,
            [],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '删除成功');
    }

    /**
     * 获取知识库分类
     */
    public function knowledgeCategories(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $tree = KnowledgeCategory::getTree($hotelId);
        
        return $this->success($tree);
    }

    // ==================== 智能员工Agent - 增强功能 ====================

    /**
     * 获取工单列表
     */
    public function workOrders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $priority = (int) $this->request->param('priority', 0);
        $type = (int) $this->request->param('type', 0);
        
        $query = AgentWorkOrder::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        if ($priority > 0) {
            $query->where('priority', $priority);
        }
        if ($type > 0) {
            $query->where('order_type', $type);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['assignee', 'room'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建工单
     */
    public function createWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        $order = AgentWorkOrder::createOrder($data['hotel_id'], [
            'source_type' => $data['source_type'] ?? AgentWorkOrder::SOURCE_MANUAL,
            'order_type' => $data['order_type'] ?? AgentWorkOrder::TYPE_OTHER,
            'priority' => $data['priority'] ?? AgentWorkOrder::PRIORITY_NORMAL,
            'title' => $data['title'],
            'content' => $data['content'],
            'guest_name' => $data['guest_name'] ?? '',
            'guest_phone' => $data['guest_phone'] ?? '',
            'room_id' => $data['room_id'] ?? 0,
            'room_number' => $data['room_number'] ?? '',
            'emotion_score' => $data['emotion_score'] ?? 0,
            'tags' => $data['tags'] ?? [],
            'created_by' => $this->currentUser->id ?? 0,
            'assigned_to' => $data['assigned_to'] ?? 0,
        ]);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_create',
            '工单已创建: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['order_id' => $order->id, 'priority' => $order->priority],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $order->id], '工单创建成功');
    }

    /**
     * 分配工单
     */
    public function assignWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $userId = (int) $this->request->param('user_id', 0);
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->assign($userId);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_assign',
            '工单已分配给: ' . ($order->assignee->realname ?? '未知'),
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单分配成功');
    }

    /**
     * 解决工单
     */
    public function resolveWorkOrder(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $solution = (string) $this->request->param('solution', '');
        
        $order = AgentWorkOrder::find($id);
        if (!$order) {
            return $this->error('工单不存在');
        }
        
        $order->resolve($solution);
        
        // 记录日志
        AgentLog::record(
            $order->hotel_id,
            AgentLog::AGENT_TYPE_STAFF,
            'work_order_resolve',
            '工单已解决: ' . $order->title,
            AgentLog::LEVEL_INFO,
            ['order_id' => $id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '工单已解决');
    }

    /**
     * 获取工单统计
     */
    public function workOrderStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $pending = AgentWorkOrder::getPendingStats($hotelId);
        $today = AgentWorkOrder::getTodayStats($hotelId);
        
        return $this->success([
            'pending' => $pending,
            'today' => $today,
        ]);
    }

    /**
     * 获取对话记录
     */
    public function conversations(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $channel = (int) $this->request->param('channel', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $pagination = $this->getPagination();
        $result = AgentConversation::search($hotelId, $keyword, $channel, $pagination['page'], $pagination['page_size']);
        
        return $this->paginate($result['list'], $result['total'], $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取对话统计
     */
    public function conversationStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $days = (int) $this->request->param('days', 7);
        
        $today = AgentConversation::getTodayStats($hotelId);
        $intents = AgentConversation::getIntentStats($hotelId, $days);
        $emotions = AgentConversation::getEmotionStats($hotelId, $days);
        
        return $this->success([
            'today' => $today,
            'intent_distribution' => $intents,
            'emotion_analysis' => $emotions,
        ]);
    }

    /**
     * 获取智能员工Agent综合仪表板
     */
    public function staffDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 工单统计
        $workOrderStats = AgentWorkOrder::getPendingStats($hotelId);
        
        // 对话统计
        $todayConversations = AgentConversation::getTodayStats($hotelId);
        
        // 知识库统计
        $knowledgeStats = [
            'total' => KnowledgeBase::where('hotel_id', $hotelId)->count(),
            'enabled' => KnowledgeBase::where('hotel_id', $hotelId)->where('is_enabled', 1)->count(),
            'hot' => KnowledgeBase::getHotKnowledge($hotelId, 5),
        ];
        
        // 高优先级工单
        $urgentOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->whereIn('status', [AgentWorkOrder::STATUS_PENDING, AgentWorkOrder::STATUS_PROCESSING])
            ->where('priority', '>=', AgentWorkOrder::PRIORITY_HIGH)
            ->order('priority', 'desc')
            ->limit(5)
            ->select();
        
        // 需要转人工的工单
        $needTransferOrders = AgentWorkOrder::where('hotel_id', $hotelId)
            ->where('status', AgentWorkOrder::STATUS_PENDING)
            ->where('emotion_score', '>=', 0.4)
            ->order('emotion_score', 'desc')
            ->limit(5)
            ->select();
        
        return $this->success([
            'work_orders' => $workOrderStats,
            'conversations' => $todayConversations,
            'knowledge_base' => $knowledgeStats,
            'urgent_orders' => $urgentOrders,
            'need_transfer_orders' => $needTransferOrders,
        ]);
    }

    // ==================== 收益管理Agent - 增强功能 ====================

    /**
     * 获取需求预测
     */
    public function demandForecasts(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d'));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d', strtotime('+30 days')));
        
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        
        // 获取准确率统计
        $accuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        return $this->success([
            'forecasts' => $forecasts,
            'accuracy' => $accuracy,
            'high_demand_dates' => DemandForecast::getHighDemandDates($hotelId, 80),
        ]);
    }

    /**
     * 创建需求预测
     */
    public function createForecast(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'forecast_date' => 'require|date',
            'room_type_id' => 'require|integer',
            'predicted_occupancy' => 'require|float',
        ]);
        
        $forecast = DemandForecast::createForecast($data['hotel_id'], $data['forecast_date'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_create',
            '需求预测已创建: ' . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            ['forecast_id' => $forecast->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $forecast->id], '预测创建成功');
    }

    /**
     * 获取竞对分析
     */
    public function competitorAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        // 获取价格矩阵
        $priceMatrix = CompetitorAnalysis::getPriceMatrix($hotelId, $date);
        
        // 获取价格波动预警
        $alerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 20);
        
        // 获取价格趋势
        $competitors = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->group('competitor_hotel_id')
            ->column('competitor_hotel_id');
        
        $trends = [];
        foreach ($competitors as $competitorId) {
            $trends[$competitorId] = CompetitorAnalysis::getPriceTrend($hotelId, $competitorId);
        }
        
        return $this->success([
            'price_matrix' => $priceMatrix,
            'alerts' => $alerts,
            'trends' => $trends,
            'date' => $date,
        ]);
    }

    /**
     * 记录竞对价格
     */
    public function recordCompetitorPrice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'competitor_hotel_id' => 'require|integer',
            'our_price' => 'require|float',
            'competitor_price' => 'require|float',
        ]);
        
        $analysis = CompetitorAnalysis::recordAnalysis(
            $data['hotel_id'],
            $data['competitor_hotel_id'],
            $data
        );
        
        return $this->success(['id' => $analysis->id], '记录成功');
    }

    /**
     * 获取定价建议列表
     */
    public function priceSuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        $query = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 审批定价建议
     */
    public function approvePrice(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', 'approve'); // approve/reject
        $remark = (string) $this->request->param('remark', '');
        
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('定价建议不存在');
        }
        
        if ($action === 'approve') {
            $suggestion->approve($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已批准';
        } else {
            $suggestion->reject($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已拒绝';
        }
        
        // 记录日志
        AgentLog::record(
            $suggestion->hotel_id,
            AgentLog::AGENT_TYPE_REVENUE,
            'price_' . $action,
            $message . ': ' . $suggestion->room_type_name,
            AgentLog::LEVEL_INFO,
            ['suggestion_id' => $id, 'suggested_price' => $suggestion->suggested_price],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, $message);
    }

    /**
     * 获取收益分析数据（增强版 - 含RevPAR分析）
     */
    public function revenueAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        
        // 获取需求预测统计
        $forecastStats = DemandForecast::getAccuracyStats($hotelId, 30);
        $highDemandDates = DemandForecast::getHighDemandDates($hotelId, 80);
        
        // 计算RevPAR趋势（基于预测和历史数据）
        $revparTrend = [];
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        foreach ($forecasts as $forecast) {
            $revparTrend[] = [
                'date' => $forecast->forecast_date,
                'predicted_revpar' => $forecast->predicted_revpar,
                'predicted_occupancy' => $forecast->predicted_occupancy,
                'confidence' => $forecast->confidence_score,
            ];
        }
        
        // 获取定价策略建议
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates);
        
        return $this->success([
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 生成定价策略建议
     */
    private function generatePricingStrategies(int $hotelId, array $highDemandDates): array
    {
        $strategies = [];
        
        if (count($highDemandDates) > 0) {
            $strategies[] = [
                'type' => 'high_demand',
                'title' => '高需求日期动态提价',
                'description' => '检测到 ' . count($highDemandDates) . ' 个高需求日期，建议在这些日期实施动态溢价策略',
                'suggested_action' => '在高需求日期将基础房价提高10-20%',
                'expected_impact' => '预计RevPAR提升 8-15%',
            ];
        }
        
        // 检查竞对价格差距
        $recentAnalysis = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->where('analysis_date', date('Y-m-d'))
            ->select();
        
        $higherCount = 0;
        $lowerCount = 0;
        foreach ($recentAnalysis as $item) {
            if ($item->price_difference > 0) {
                $higherCount++;
            } elseif ($item->price_difference < 0) {
                $lowerCount++;
            }
        }
        
        if ($higherCount > $lowerCount) {
            $strategies[] = [
                'type' => 'competitor_price',
                'title' => '竞对价格跟进',
                'description' => '我方价格高于竞对的情况较多，可能导致客源流失',
                'suggested_action' => '针对部分房型适当降价，保持价格竞争力',
                'expected_impact' => '预计提升入住率 3-5%',
            ];
        }
        
        return $strategies;
    }

    /**
     * 获取收益管理Agent综合仪表板
     */
    public function revenueDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 今日定价建议
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', date('Y-m-d'))
            ->with('roomType')
            ->select();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15);
        
        // 本周RevPAR预测
        $weekForecasts = DemandForecast::getForecastRange(
            $hotelId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+7 days'))
        );
        
        $avgPredictedRevpar = 0;
        if (count($weekForecasts) > 0) {
            $totalRevpar = array_sum(array_column($weekForecasts->toArray(), 'predicted_revpar'));
            $avgPredictedRevpar = round($totalRevpar / count($weekForecasts), 2);
        }
        
        return $this->success([
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'high_demand_count' => count(DemandForecast::getHighDemandDates($hotelId, 80)),
        ]);
    }

    // ==================== 资产运维Agent - 增强功能 ====================

    /**
     * 获取能耗数据
     */
    public function energyData(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取趋势数据
        $trend = [];
        if ($energyType > 0) {
            $trend = EnergyConsumption::getTrend($hotelId, $energyType, $startDate, $endDate);
        }
        
        // 获取今日数据
        $todayData = [];
        $types = [
            EnergyConsumption::TYPE_ELECTRICITY,
            EnergyConsumption::TYPE_WATER,
            EnergyConsumption::TYPE_GAS,
        ];
        foreach ($types as $type) {
            $todayData[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 获取异常记录
        $anomalies = EnergyConsumption::getAnomalies($hotelId, $startDate, $endDate, 10);
        
        // 获取能耗基准对比
        $benchmarkComparison = EnergyBenchmark::getComparisonReport($hotelId, date('Y-m-d'));
        
        return $this->success([
            'trend' => $trend,
            'today' => $todayData,
            'anomalies' => $anomalies,
            'benchmark_comparison' => $benchmarkComparison,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 获取能耗基准列表
     */
    public function energyBenchmarks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        
        $query = EnergyBenchmark::where('hotel_id', $hotelId);
        
        if ($energyType > 0) {
            $query->where('energy_type', $energyType);
        }
        
        $list = $query->with('device')
            ->where('is_active', 1)
            ->order('id', 'desc')
            ->select();
        
        return $this->success($list);
    }

    /**
     * 设置能耗基准
     */
    public function saveEnergyBenchmark(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'energy_type' => 'require|integer',
            'benchmark_value' => 'require|float',
        ]);
        
        $benchmark = EnergyBenchmark::setBenchmark($data['hotel_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'benchmark_update',
            '能耗基准已更新: ' . $benchmark->energy_type_name,
            AgentLog::LEVEL_INFO,
            ['benchmark_id' => $benchmark->id, 'value' => $benchmark->benchmark_value],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $benchmark->id], '基准设置成功');
    }

    /**
     * 自动计算基准
     */
    public function autoCalculateBenchmark(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $energyType = (int) $this->request->param('energy_type', 0);
        $days = (int) $this->request->param('days', 30);
        
        $benchmark = EnergyBenchmark::autoCalculateBenchmark($hotelId, $energyType, $days);
        
        return $this->success(['benchmark_value' => $benchmark], '计算完成');
    }

    /**
     * 获取节能建议
     */
    public function energySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = EnergySavingSuggestion::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('implementer')
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 生成节能建议
     */
    public function generateEnergySuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $suggestions = EnergySavingSuggestion::autoGenerate($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'suggestion_generate',
            '自动生成 ' . count($suggestions) . ' 条节能建议',
            AgentLog::LEVEL_INFO,
            ['count' => count($suggestions)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($suggestions), 'suggestions' => $suggestions], '生成成功');
    }

    /**
     * 更新节能建议状态
     */
    public function updateEnergySuggestion(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', '');
        
        $suggestion = EnergySavingSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('建议不存在');
        }
        
        switch ($action) {
            case 'approve':
                $suggestion->approve();
                $message = '建议已批准';
                break;
            case 'start':
                $suggestion->startImplementation($this->currentUser->id ?? 0);
                $message = '开始实施';
                break;
            case 'complete':
                $actualSaving = (float) $this->request->param('actual_saving', 0);
                $suggestion->complete($actualSaving);
                $message = '实施完成';
                break;
            default:
                return $this->error('未知操作');
        }
        
        return $this->success(null, $message);
    }

    /**
     * 获取维护计划
     */
    public function maintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $deviceId = (int) $this->request->param('device_id', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = MaintenancePlan::where('hotel_id', $hotelId);
        
        if ($deviceId > 0) {
            $query->where('device_id', $deviceId);
        }
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with(['device', 'category'])
            ->order('priority', 'desc')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建设备维护计划
     */
    public function createMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'device_id' => 'require|integer',
            'plan_name' => 'require|max:200',
        ]);
        
        $plan = MaintenancePlan::createForDevice($data['hotel_id'], $data['device_id'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'plan_create',
            '维护计划已创建: ' . $data['plan_name'],
            AgentLog::LEVEL_INFO,
            ['plan_id' => $plan->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $plan->id], '计划创建成功');
    }

    /**
     * 执行维护计划
     */
    public function executeMaintenancePlan(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $result = (string) $this->request->param('result', '');
        $actualCost = (float) $this->request->param('actual_cost', 0);
        
        $plan = MaintenancePlan::find($id);
        if (!$plan) {
            return $this->error('计划不存在');
        }
        
        $maintenance = $plan->execute(date('Y-m-d'), $this->currentUser->id ?? 0, $result, $actualCost);
        
        return $this->success(['maintenance_id' => $maintenance->id], '维护记录已创建');
    }

    /**
     * 获取维护提醒
     */
    public function maintenanceReminders(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $upcoming = MaintenancePlan::getUpcomingPlans($hotelId, 7);
        $overdue = MaintenancePlan::getOverduePlans($hotelId);
        
        return $this->success([
            'upcoming' => $upcoming,
            'overdue' => $overdue,
        ]);
    }

    /**
     * 自动生成默认维护计划
     */
    public function autoGenerateMaintenancePlans(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        $plans = MaintenancePlan::autoGenerateDefaultPlans($hotelId);
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_ASSET,
            'plan_auto_generate',
            '自动生成 ' . count($plans) . ' 个维护计划',
            AgentLog::LEVEL_INFO,
            ['count' => count($plans)],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['count' => count($plans)], '生成成功');
    }

    /**
     * 获取资产运维Agent综合仪表板
     */
    public function assetDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $deviceStats = Device::getStatistics($hotelId);
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 能耗统计
        $todayEnergy = [];
        foreach ([EnergyConsumption::TYPE_ELECTRICITY, EnergyConsumption::TYPE_WATER, EnergyConsumption::TYPE_GAS] as $type) {
            $todayEnergy[$type] = EnergyConsumption::getTodayTotal($hotelId, $type);
        }
        
        // 维护统计
        $maintenanceStats = MaintenancePlan::getExecutionStats($hotelId);
        
        // 节能建议统计
        $savingStats = EnergySavingSuggestion::getImplementationStats($hotelId);
        $highPrioritySuggestions = EnergySavingSuggestion::getHighPriority($hotelId, 5);
        
        // 异常告警
        $anomalies = EnergyConsumption::getAnomalies($hotelId, date('Y-m-d', strtotime('-7 days')), date('Y-m-d'), 5);
        
        return $this->success([
            'devices' => array_merge($deviceStats, ['faulty' => $faultyDevices]),
            'energy' => $todayEnergy,
            'maintenance' => $maintenanceStats,
            'saving_suggestions' => array_merge($savingStats, ['high_priority' => $highPrioritySuggestions]),
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * 获取设备列表
     */
    public function deviceList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        
        $query = Device::where('hotel_id', $hotelId);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取设备统计
     */
    public function deviceStats(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 设备统计
        $stats = Device::getStatistics($hotelId);
        
        // 待维护设备
        $pendingMaintenance = Device::getPendingMaintenance($hotelId);
        
        // 故障设备
        $faultyDevices = Device::getFaultyDevices($hotelId);
        
        // 今日维护任务
        $todayTasks = DeviceMaintenance::getTodayTasks($hotelId);
        
        return $this->success([
            'statistics' => $stats,
            'pending_maintenance' => $pendingMaintenance,
            'faulty_devices' => $faultyDevices,
            'today_tasks' => $todayTasks,
        ]);
    }

    /**
     * 创建设备
     */
    public function saveDevice(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'name' => 'require|max:100',
            'category_id' => 'require|integer',
        ]);
        
        if (!empty($data['id'])) {
            $device = Device::find($data['id']);
            if (!$device) {
                return $this->error('设备不存在');
            }
        } else {
            $device = new Device();
            $device->hotel_id = $data['hotel_id'];
            $device->status = Device::STATUS_NORMAL;
        }
        
        $device->name = $data['name'];
        $device->category_id = $data['category_id'];
        $device->model = $data['model'] ?? '';
        $device->location = $data['location'] ?? '';
        $device->install_date = $data['install_date'] ?? null;
        $device->warranty_expire = $data['warranty_expire'] ?? null;
        $device->maintenance_cycle = $data['maintenance_cycle'] ?? 90;
        $device->purchase_cost = $data['purchase_cost'] ?? 0;
        $device->is_monitored = $data['is_monitored'] ?? 1;
        $device->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_ASSET,
            'device_update',
            '设备已保存: ' . $data['name'],
            AgentLog::LEVEL_INFO,
            ['device_id' => $device->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $device->id], '保存成功');
    }

    // ==================== Agent日志 ====================

    /**
     * 获取Agent日志
     */
    public function logs(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $logLevel = (int) $this->request->param('log_level', 0);
        
        $query = AgentLog::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($logLevel > 0) {
            $query->where('log_level', $logLevel);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('user')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取Agent任务
     */
    public function tasks(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $status = (int) $this->request->param('status', 0);
        
        $query = AgentTask::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 创建Agent任务
     */
    public function createTask(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:1,2,3',
            'task_type' => 'require|integer',
            'task_name' => 'require|max:200',
        ]);
        
        $task = AgentTask::createTask(
            $data['hotel_id'],
            $data['agent_type'],
            $data['task_type'],
            $data['task_name'],
            $data['params'] ?? [],
            $data['priority'] ?? AgentTask::PRIORITY_NORMAL
        );
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'task_create',
            'Agent任务已创建: ' . $data['task_name'],
            AgentLog::LEVEL_INFO,
            ['task_id' => $task->id, 'task_type' => $data['task_type']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $task->id], '任务创建成功');
    }
}
