<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

class OpeningService
{
    private const STATUS_TODO = 'todo';
    private const STATUS_DOING = 'doing';
    private const STATUS_DONE = 'done';
    private const STATUS_BLOCKED = 'blocked';

    private const RISK_LOW = 'low';
    private const RISK_MEDIUM = 'medium';
    private const RISK_HIGH = 'high';

    private const SCORE_WEIGHTS = [
        'PMS系统配置' => 20,
        'OTA上线配置' => 15,
        '房型房价库存' => 15,
        '员工培训演练' => 20,
        '工程物资验收' => 15,
        '财务收银风控' => 10,
        '开业营销推广' => 5,
    ];

    public function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function createProject(array $input, int $userId, array $hotelIds): int
    {
        $now = $this->now();
        $hotelId = (int)($input['hotel_id'] ?? 0);
        if ($hotelId <= 0 && count($hotelIds) === 1) {
            $hotelId = (int)$hotelIds[0];
        }
        if ($hotelId > 0 && !empty($hotelIds) && !in_array($hotelId, $hotelIds, true)) {
            throw new \RuntimeException('无权创建该酒店的开业项目');
        }

        return (int)Db::name('opening_projects')->insertGetId([
            'hotel_id' => $hotelId,
            'project_name' => trim((string)$input['project_name']),
            'hotel_name' => trim((string)$input['hotel_name']),
            'city' => trim((string)($input['city'] ?? '')),
            'brand' => trim((string)($input['brand'] ?? '')),
            'positioning' => trim((string)($input['positioning'] ?? '')),
            'room_count' => max(0, (int)($input['room_count'] ?? 0)),
            'opening_date' => $this->normalizeDate((string)$input['opening_date']),
            'manager_name' => trim((string)($input['manager_name'] ?? '')),
            'status' => (string)($input['status'] ?? 'preparing'),
            'overall_score' => 0,
            'risk_level' => self::RISK_LOW,
            'ai_penetration_rate' => 0,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function projects(array $hotelIds): array
    {
        $query = Db::name('opening_projects')
            ->where('status', '<>', 'archived')
            ->order('id', 'desc');
        if (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        return array_map([$this, 'normalizeProject'], $query->select()->toArray());
    }

    public function updateProject(int $projectId, array $input, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds);
        $data = [];

        foreach (['project_name', 'hotel_name', 'city', 'brand', 'positioning', 'manager_name'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = trim((string)$input[$field]);
            }
        }
        if (array_key_exists('room_count', $input)) {
            $data['room_count'] = max(0, (int)$input['room_count']);
        }
        if (array_key_exists('opening_date', $input)) {
            $data['opening_date'] = $this->normalizeDate((string)$input['opening_date']);
        }
        if (array_key_exists('hotel_id', $input)) {
            $hotelId = (int)$input['hotel_id'];
            if ($hotelId <= 0 && count($hotelIds) === 1) {
                $hotelId = (int)$hotelIds[0];
            }
            if ($hotelId > 0 && !empty($hotelIds) && !in_array($hotelId, $hotelIds, true)) {
                throw new \RuntimeException('无权调整该开业项目所属酒店');
            }
            $data['hotel_id'] = max(0, $hotelId);
        }
        if (array_key_exists('status', $input)) {
            $status = trim((string)$input['status']);
            $allowedStatus = ['preparing', 'ready', 'online', 'paused', 'archived'];
            if (!in_array($status, $allowedStatus, true)) {
                throw new \RuntimeException('开业项目状态不正确');
            }
            $data['status'] = $status;
        }

        if (empty($data)) {
            throw new \RuntimeException('没有可更新的开业项目字段');
        }

        $data['updated_at'] = $this->now();
        Db::name('opening_projects')->where('id', $project['id'])->update($data);

        return $this->requireProject($projectId, $hotelIds);
    }

    public function archiveProject(int $projectId, array $hotelIds): bool
    {
        $project = $this->requireProject($projectId, $hotelIds);

        return Db::name('opening_projects')
            ->where('id', $project['id'])
            ->update([
                'status' => 'archived',
                'updated_at' => $this->now(),
            ]) > 0;
    }

    public function overview(int $projectId, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds);
        $tasks = $this->tasks($projectId, $hotelIds);
        $metrics = $this->calculateMetrics($project, $tasks);

        return [
            'project' => $metrics['project'],
            'metrics' => $metrics['metrics'],
            'ai_suggestions' => $metrics['ai_suggestions'],
            'high_risk_tasks' => array_values(array_filter($tasks, static fn(array $task): bool => $task['risk_level'] === self::RISK_HIGH)),
            'overdue_tasks' => array_values(array_filter($tasks, static fn(array $task): bool => !empty($task['is_overdue']))),
            'category_progress' => $metrics['category_progress'],
            'recent_result' => [
                'overall_score' => $metrics['project']['overall_score'],
                'risk_level' => $metrics['project']['risk_level'],
                'updated_at' => $metrics['project']['updated_at'],
            ],
            'history' => $this->recentProjects($hotelIds, $projectId),
        ];
    }

    public function generateTasks(int $projectId, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds);
        $existing = (int)Db::name('opening_tasks')->where('project_id', $projectId)->count();
        if ($existing > 0) {
            return ['generated' => false, 'tasks' => $this->tasks($projectId, $hotelIds), 'overview' => $this->overview($projectId, $hotelIds)];
        }

        $now = $this->now();
        $rows = [];
        foreach ($this->taskTemplates($project) as $index => $task) {
            $rows[] = [
                'project_id' => $projectId,
                'category' => $task['category'],
                'task_name' => $task['task_name'],
                'task_desc' => $task['task_desc'],
                'is_core' => $task['is_core'] ? 1 : 0,
                'owner_name' => $project['manager_name'] ?: '',
                'collaborator_name' => '',
                'deadline' => $task['deadline'],
                'status' => self::STATUS_TODO,
                'risk_level' => self::RISK_LOW,
                'acceptance_standard' => $task['acceptance_standard'],
                'ai_suggestion' => $task['ai_suggestion'],
                'remark' => '',
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Db::name('opening_tasks')->insertAll($rows);
        $this->recalculate($projectId, $hotelIds);

        return ['generated' => true, 'tasks' => $this->tasks($projectId, $hotelIds), 'overview' => $this->overview($projectId, $hotelIds)];
    }

    public function tasks(int $projectId, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds);
        $rows = Db::name('opening_tasks')
            ->where('project_id', $projectId)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(fn(array $row): array => $this->normalizeTask($row, $project), $rows);
    }

    public function updateTask(int $taskId, array $input, array $hotelIds): array
    {
        $task = Db::name('opening_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('检查项不存在');
        }
        $this->requireProject((int)$task['project_id'], $hotelIds);

        $allowedStatus = [self::STATUS_TODO, self::STATUS_DOING, self::STATUS_DONE, self::STATUS_BLOCKED];
        $data = [];
        foreach (['owner_name', 'collaborator_name', 'remark'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = trim((string)$input[$field]);
            }
        }
        if (array_key_exists('deadline', $input)) {
            $data['deadline'] = $input['deadline'] ? $this->normalizeDate((string)$input['deadline']) : null;
        }
        if (array_key_exists('status', $input)) {
            $status = (string)$input['status'];
            if (!in_array($status, $allowedStatus, true)) {
                throw new \RuntimeException('检查项状态不正确');
            }
            $data['status'] = $status;
        }

        if (empty($data)) {
            throw new \RuntimeException('没有可更新的检查项字段');
        }

        $data['updated_at'] = $this->now();
        Db::name('opening_tasks')->where('id', $taskId)->update($data);
        $this->recalculate((int)$task['project_id'], $hotelIds);

        $project = $this->requireProject((int)$task['project_id'], $hotelIds);
        $updated = Db::name('opening_tasks')->where('id', $taskId)->find();
        return $this->normalizeTask($updated, $project);
    }

    public function recalculate(int $projectId, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds);
        $rows = Db::name('opening_tasks')->where('project_id', $projectId)->select()->toArray();
        $tasks = array_map(fn(array $row): array => $this->normalizeTask($row, $project), $rows);

        foreach ($tasks as $task) {
            Db::name('opening_tasks')->where('id', $task['id'])->update([
                'risk_level' => $task['risk_level'],
                'updated_at' => $this->now(),
            ]);
        }

        $metrics = $this->calculateMetrics($project, $tasks);
        Db::name('opening_projects')->where('id', $projectId)->update([
            'overall_score' => $metrics['project']['overall_score'],
            'risk_level' => $metrics['project']['risk_level'],
            'ai_penetration_rate' => $metrics['project']['ai_penetration_rate'],
            'updated_at' => $this->now(),
        ]);

        return $this->overview($projectId, $hotelIds);
    }

    public function requireProject(int $projectId, array $hotelIds): array
    {
        $project = Db::name('opening_projects')->where('id', $projectId)->find();
        if (!$project) {
            throw new \RuntimeException('开业项目不存在');
        }
        if (!empty($hotelIds) && !in_array((int)$project['hotel_id'], $hotelIds, true)) {
            throw new \RuntimeException('无权访问该开业项目');
        }

        return $this->normalizeProject($project);
    }

    private function calculateMetrics(array $project, array $tasks): array
    {
        $total = count($tasks);
        $done = count(array_filter($tasks, static fn(array $task): bool => $task['status'] === self::STATUS_DONE));
        $core = array_values(array_filter($tasks, static fn(array $task): bool => (int)$task['is_core'] === 1));
        $coreDone = count(array_filter($core, static fn(array $task): bool => $task['status'] === self::STATUS_DONE));
        $highRisk = count(array_filter($tasks, static fn(array $task): bool => $task['risk_level'] === self::RISK_HIGH));
        $overdue = count(array_filter($tasks, static fn(array $task): bool => !empty($task['is_overdue'])));
        $aiCovered = count(array_filter($tasks, static fn(array $task): bool => trim((string)$task['ai_suggestion']) !== ''));
        $categoryProgress = $this->categoryProgress($tasks);

        $score = 0.0;
        foreach (self::SCORE_WEIGHTS as $category => $weight) {
            $score += (($categoryProgress[$category]['completion_rate'] ?? 0) * $weight / 100);
        }

        $riskLevel = self::RISK_LOW;
        if ($highRisk > 0 || $overdue > 0) {
            $riskLevel = self::RISK_HIGH;
        } elseif ($total > $done) {
            $riskLevel = self::RISK_MEDIUM;
        }

        $project['overall_score'] = round($score, 1);
        $project['risk_level'] = $riskLevel;
        $project['ai_penetration_rate'] = $total > 0 ? round($aiCovered / $total * 100, 1) : 0;

        return [
            'project' => $project,
            'metrics' => [
                'days_left' => $this->daysLeft((string)$project['opening_date']),
                'total_tasks' => $total,
                'completed_tasks' => $done,
                'completion_rate' => $total > 0 ? round($done / $total * 100, 1) : 0,
                'core_tasks' => count($core),
                'core_completed_tasks' => $coreDone,
                'core_completion_rate' => count($core) > 0 ? round($coreDone / count($core) * 100, 1) : 0,
                'high_risk_count' => $highRisk,
                'overdue_count' => $overdue,
                'ai_penetration_rate' => $project['ai_penetration_rate'],
            ],
            'category_progress' => array_values($categoryProgress),
            'ai_suggestions' => $this->buildOpeningSuggestions($project, $tasks, $highRisk, $overdue),
        ];
    }

    private function categoryProgress(array $tasks): array
    {
        $groups = [];
        foreach (array_keys(self::SCORE_WEIGHTS) as $category) {
            $groups[$category] = ['category' => $category, 'total' => 0, 'done' => 0, 'completion_rate' => 0];
        }

        foreach ($tasks as $task) {
            $category = $this->scoreCategory((string)$task['category']);
            if (!isset($groups[$category])) {
                $groups[$category] = ['category' => $category, 'total' => 0, 'done' => 0, 'completion_rate' => 0];
            }
            $groups[$category]['total']++;
            if ($task['status'] === self::STATUS_DONE) {
                $groups[$category]['done']++;
            }
        }

        foreach ($groups as &$group) {
            $group['completion_rate'] = $group['total'] > 0 ? round($group['done'] / $group['total'] * 100, 1) : 0;
        }
        unset($group);

        return $groups;
    }

    private function scoreCategory(string $category): string
    {
        if (in_array($category, ['客房工程验收', '物资布草备品'], true)) {
            return '工程物资验收';
        }
        return $category;
    }

    private function normalizeTask(array $row, array $project): array
    {
        $row['id'] = (int)$row['id'];
        $row['project_id'] = (int)$row['project_id'];
        $row['is_core'] = (int)($row['is_core'] ?? 0);
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $row['risk_level'] = $this->taskRiskLevel($row, $project);
        $row['is_overdue'] = $this->isOverdue($row);
        return $row;
    }

    private function taskRiskLevel(array $task, array $project): string
    {
        if (($task['status'] ?? '') === self::STATUS_DONE) {
            return self::RISK_LOW;
        }
        if ($this->isOverdue($task)) {
            return self::RISK_HIGH;
        }
        if ((int)($task['is_core'] ?? 0) === 1 && $this->daysLeft((string)$project['opening_date']) < 7) {
            return self::RISK_HIGH;
        }

        $text = ($task['category'] ?? '') . ' ' . ($task['task_name'] ?? '') . ' ' . ($task['task_desc'] ?? '');
        foreach (['PMS', 'OTA', '支付', '消防', '安全', '库存'] as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return self::RISK_HIGH;
            }
        }

        return (int)($task['is_core'] ?? 0) === 1 ? self::RISK_MEDIUM : self::RISK_LOW;
    }

    private function isOverdue(array $task): bool
    {
        if (($task['status'] ?? '') === self::STATUS_DONE || empty($task['deadline'])) {
            return false;
        }
        return strtotime((string)$task['deadline']) < strtotime(date('Y-m-d'));
    }

    private function taskTemplates(array $project): array
    {
        $openingDate = (string)$project['opening_date'];
        $days = [-45, -40, -35, -30, -25, -20, -18, -15, -12, -10, -7, -5, -3, -2, -1];
        $deadline = fn(int $i): string => date('Y-m-d', strtotime($openingDate . ' ' . $days[$i] . ' days'));

        return [
            ['category' => '证照合规', 'task_name' => '营业证照办理确认', 'task_desc' => '确认营业执照、特种行业许可、卫生许可等开业必要证照状态。', 'is_core' => true, 'deadline' => $deadline(0), 'acceptance_standard' => '证照清单完整，缺口项有责任人和补齐日期。', 'ai_suggestion' => '优先建立证照缺口台账，开业前7天仍未闭环的事项升级到店总。'],
            ['category' => '证照合规', 'task_name' => '消防与安全验收资料归档', 'task_desc' => '整理消防验收、安全巡检与应急预案资料。', 'is_core' => true, 'deadline' => $deadline(1), 'acceptance_standard' => '消防、安全资料可追溯，现场抽查无重大缺陷。', 'ai_suggestion' => '消防、安全关键词属于高优先级风险，建议提前做一次全员应急演练。'],
            ['category' => 'PMS系统配置', 'task_name' => 'PMS基础档案配置', 'task_desc' => '完成酒店、楼栋、楼层、房间、账号、权限和夜审规则配置。', 'is_core' => true, 'deadline' => $deadline(2), 'acceptance_standard' => 'PMS可完成入住、续住、换房、退房、夜审全流程。', 'ai_suggestion' => '先用测试订单跑通前台高频流程，再开放正式账号。'],
            ['category' => 'PMS系统配置', 'task_name' => 'PMS支付与发票联调', 'task_desc' => '确认支付、押金、退款、发票、交班报表配置。', 'is_core' => true, 'deadline' => $deadline(3), 'acceptance_standard' => '支付与账务闭环无阻断，异常交易有处理口径。', 'ai_suggestion' => '支付相关未完成默认高风险，建议安排财务和前台联合验收。'],
            ['category' => 'OTA上线配置', 'task_name' => 'OTA门店资料上线', 'task_desc' => '配置携程、美团等渠道门店资料、图片、政策与设施标签。', 'is_core' => true, 'deadline' => $deadline(4), 'acceptance_standard' => '主流OTA页面可搜索、可浏览、信息一致。', 'ai_suggestion' => '上线前检查首图、卖点、取消政策和到店指引，减少低转化风险。'],
            ['category' => 'OTA上线配置', 'task_name' => 'OTA房价库存联通校验', 'task_desc' => '校验渠道房型、价格、库存、保留房和关房规则。', 'is_core' => true, 'deadline' => $deadline(5), 'acceptance_standard' => '各渠道测试预订成功，库存扣减一致。', 'ai_suggestion' => 'OTA和库存相关事项未完成会直接影响开业订单，应每日复核。'],
            ['category' => '房型房价库存', 'task_name' => '房型标准与价格体系确认', 'task_desc' => '确认房型命名、面积、床型、早餐、会员价和开业价。', 'is_core' => true, 'deadline' => $deadline(6), 'acceptance_standard' => '房型房价在PMS与OTA保持一致。', 'ai_suggestion' => '建议用低中高三档价格带覆盖试营业、平日和周末。'],
            ['category' => '房型房价库存', 'task_name' => '首周库存策略设置', 'task_desc' => '配置首周可售库存、保留房和满房保护规则。', 'is_core' => true, 'deadline' => $deadline(7), 'acceptance_standard' => '首周库存可控，无超售和误关房风险。', 'ai_suggestion' => '库存相关事项未完成优先标记高风险，建议与店长每日确认。'],
            ['category' => '客房工程验收', 'task_name' => '客房工程逐房验收', 'task_desc' => '按房间检查门锁、空调、热水、网络、照明、卫浴和安全设施。', 'is_core' => true, 'deadline' => $deadline(8), 'acceptance_standard' => '可售房达到开业标准，维修遗留项有闭环记录。', 'ai_suggestion' => '先验收可售房，再处理非关键尾项，避免开业房量被动缩水。'],
            ['category' => '物资布草备品', 'task_name' => '布草与客用品盘点', 'task_desc' => '确认布草周转量、客用品、清洁工具和仓库摆放。', 'is_core' => false, 'deadline' => $deadline(9), 'acceptance_standard' => '首周运营物资满足满房周转需求。', 'ai_suggestion' => '按满房率80%测算首周消耗，低于安全库存时提前补货。'],
            ['category' => '员工招聘排班', 'task_name' => '开业班表与岗位补齐', 'task_desc' => '确认前厅、客房、工程、保洁、值班经理排班。', 'is_core' => true, 'deadline' => $deadline(10), 'acceptance_standard' => '关键岗位有人到岗，首周班表已发布。', 'ai_suggestion' => '核心岗位缺口会放大开业期服务风险，建议提前准备机动班。'],
            ['category' => '员工培训演练', 'task_name' => '前台全流程演练', 'task_desc' => '演练预订、入住、换房、投诉、退房、夜审和交班。', 'is_core' => true, 'deadline' => $deadline(11), 'acceptance_standard' => '员工能独立完成关键场景，异常场景有SOP。', 'ai_suggestion' => '用真实订单脚本做演练，记录卡点并在开业前复训。'],
            ['category' => '员工培训演练', 'task_name' => '客房与安全联动演练', 'task_desc' => '演练查房、报修、遗留物、消防、安全和突发事件处理。', 'is_core' => true, 'deadline' => $deadline(12), 'acceptance_standard' => '跨岗位联动流程明确，责任边界清晰。', 'ai_suggestion' => '安全相关演练未完成时建议限制开业房量。'],
            ['category' => '开业营销推广', 'task_name' => '开业营销素材发布', 'task_desc' => '完成开业海报、OTA促销、会员触达和本地渠道宣发。', 'is_core' => false, 'deadline' => $deadline(13), 'acceptance_standard' => '开业促销可见，渠道权益和价格口径一致。', 'ai_suggestion' => '开业前3天集中检查图片、标题和优惠口径，避免转化损失。'],
            ['category' => '财务收银风控', 'task_name' => '收银权限与风控检查', 'task_desc' => '确认收银权限、备用金、退款审批、交班稽核和异常账处理。', 'is_core' => true, 'deadline' => $deadline(14), 'acceptance_standard' => '支付、收银、退款、交班均可追溯。', 'ai_suggestion' => '支付和财务风控事项未完成默认高风险，需在试营业前闭环。'],
        ];
    }

    private function buildOpeningSuggestions(array $project, array $tasks, int $highRisk, int $overdue): array
    {
        if (empty($tasks)) {
            return ['先生成标准开业检查清单，再按负责人和截止时间推进闭环。'];
        }
        $suggestions = [];
        if ($overdue > 0) {
            $suggestions[] = '存在逾期未完成事项，建议今日完成责任人复盘并重新确认截止时间。';
        }
        if ($highRisk > 0) {
            $suggestions[] = '高风险事项需要进入开业日会，优先处理PMS、OTA、支付、消防、安全和库存相关任务。';
        }
        if ((float)($project['overall_score'] ?? 0) < 70) {
            $suggestions[] = '开业准备评分低于70，建议先收敛到核心事项闭环，再推进普通事项。';
        }
        if (empty($suggestions)) {
            $suggestions[] = '当前开业准备节奏稳定，建议保持每日复盘并锁定开业前3天最终验收。';
        }

        return $suggestions;
    }

    private function recentProjects(array $hotelIds, int $currentProjectId): array
    {
        $query = Db::name('opening_projects')->where('id', '<>', $currentProjectId)->order('updated_at', 'desc')->limit(10);
        if (!empty($hotelIds)) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        return array_map([$this, 'normalizeProject'], $query->select()->toArray());
    }

    private function normalizeProject(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['hotel_id'] = (int)($row['hotel_id'] ?? 0);
        $row['room_count'] = (int)($row['room_count'] ?? 0);
        $row['overall_score'] = (float)($row['overall_score'] ?? 0);
        $row['ai_penetration_rate'] = (float)($row['ai_penetration_rate'] ?? 0);
        $row['created_by'] = (int)($row['created_by'] ?? 0);
        $row['days_left'] = $this->daysLeft((string)($row['opening_date'] ?? date('Y-m-d')));
        return $row;
    }

    private function daysLeft(string $date): int
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if (!$target) {
            return 0;
        }
        $days = (int)$today->diff($target)->format('%r%a');
        return $days;
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('日期格式不正确');
        }
        return date('Y-m-d', $timestamp);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
