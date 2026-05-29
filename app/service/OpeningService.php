<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use Throwable;

class OpeningService
{
    private LlmClient $client;
    private int $actorUserId = 0;
    private bool $actorIsSuperAdmin = false;

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

    public function __construct(?LlmClient $client = null)
    {
        $this->client = $client ?: new LlmClient();
    }

    public function forActor(int $userId, bool $isSuperAdmin): self
    {
        $service = clone $this;
        $service->actorUserId = max(0, $userId);
        $service->actorIsSuperAdmin = $isSuperAdmin;

        return $service;
    }

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
        $hotelName = trim((string)$input['hotel_name']);

        return (int)Db::name('opening_projects')->insertGetId([
            'hotel_id' => 0,
            'project_name' => trim((string)$input['project_name']),
            'hotel_name' => $hotelName,
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

    public function projects(array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $query = Db::name('opening_projects')
            ->where('status', '<>', 'archived')
            ->order('id', 'desc');
        $this->applyOwnerScope($query, $userId, $isSuperAdmin);

        return array_map([$this, 'normalizeProject'], $query->select()->toArray());
    }

    public function updateProject(int $projectId, array $input, array $hotelIds): array
    {
        $project = $this->requireProject($projectId, $hotelIds, $this->actorUserId, $this->actorIsSuperAdmin);
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

        return $this->requireProject($projectId, $hotelIds, $this->actorUserId, $this->actorIsSuperAdmin);
    }

    public function archiveProject(int $projectId, array $hotelIds): bool
    {
        $project = $this->requireProject($projectId, $hotelIds, $this->actorUserId, $this->actorIsSuperAdmin);

        return Db::name('opening_projects')
            ->where('id', $project['id'])
            ->update([
                'status' => 'archived',
                'updated_at' => $this->now(),
            ]) > 0;
    }

    public function overview(int $projectId, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $project = $this->requireProject($projectId, $hotelIds, $userId, $isSuperAdmin);
        $tasks = $this->tasks($projectId, $hotelIds, $userId, $isSuperAdmin);
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
            'history' => $this->recentProjects($hotelIds, $projectId, $userId, $isSuperAdmin),
        ];
    }

    public function generateTasks(int $projectId, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $project = $this->requireProject($projectId, $hotelIds, $userId, $isSuperAdmin);
        $existing = (int)Db::name('opening_tasks')->where('project_id', $projectId)->count();
        if ($existing > 0) {
            return ['generated' => false, 'tasks' => $this->tasks($projectId, $hotelIds, $userId, $isSuperAdmin), 'overview' => $this->overview($projectId, $hotelIds, $userId, $isSuperAdmin)];
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
        $this->recalculate($projectId, $hotelIds, $userId, $isSuperAdmin);

        return ['generated' => true, 'tasks' => $this->tasks($projectId, $hotelIds, $userId, $isSuperAdmin), 'overview' => $this->overview($projectId, $hotelIds, $userId, $isSuperAdmin)];
    }

    public function tasks(int $projectId, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $project = $this->requireProject($projectId, $hotelIds, $userId, $isSuperAdmin);
        $rows = Db::name('opening_tasks')
            ->where('project_id', $projectId)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return array_map(fn(array $row): array => $this->normalizeTask($row, $project), $rows);
    }

    public function updateTask(int $taskId, array $input, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $task = Db::name('opening_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('检查项不存在');
        }
        $this->requireProject((int)$task['project_id'], $hotelIds, $userId, $isSuperAdmin);

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
        if (array_key_exists('progress_percent', $input)) {
            $data['progress_percent'] = $this->normalizeProgressPercent($input['progress_percent']);
        }

        if (array_key_exists('status', $data) && $data['status'] === self::STATUS_DONE) {
            $data['progress_percent'] = 100;
        } elseif (array_key_exists('progress_percent', $data)) {
            $currentStatus = (string)($data['status'] ?? $task['status'] ?? self::STATUS_TODO);
            if ($data['progress_percent'] >= 100) {
                $data['status'] = self::STATUS_DONE;
                $data['progress_percent'] = 100;
            } elseif ($data['progress_percent'] > 0 && $currentStatus === self::STATUS_TODO) {
                $data['status'] = self::STATUS_DOING;
            } elseif ($data['progress_percent'] <= 0 && $currentStatus !== self::STATUS_BLOCKED) {
                $data['status'] = self::STATUS_TODO;
            }
        }

        if (empty($data)) {
            throw new \RuntimeException('没有可更新的检查项字段');
        }

        $data['updated_at'] = $this->now();
        Db::name('opening_tasks')->where('id', $taskId)->update($data);
        $this->recalculate((int)$task['project_id'], $hotelIds, $userId, $isSuperAdmin);

        $project = $this->requireProject((int)$task['project_id'], $hotelIds, $userId, $isSuperAdmin);
        $updated = Db::name('opening_tasks')->where('id', $taskId)->find();
        return $this->normalizeTask($updated, $project);
    }

    public function recalculate(int $projectId, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $project = $this->requireProject($projectId, $hotelIds, $userId, $isSuperAdmin);
        $rows = Db::name('opening_tasks')->where('project_id', $projectId)->select()->toArray();
        $tasks = array_map(fn(array $row): array => $this->normalizeTask($row, $project), $rows);

        foreach ($tasks as $task) {
            Db::name('opening_tasks')->where('id', $task['id'])->update([
                'risk_level' => $task['risk_level'],
                'updated_at' => $this->now(),
            ]);
        }

        $metrics = $this->calculateMetrics($project, $tasks, false);
        Db::name('opening_projects')->where('id', $projectId)->update([
            'overall_score' => $metrics['project']['overall_score'],
            'risk_level' => $metrics['project']['risk_level'],
            'ai_penetration_rate' => $metrics['project']['ai_penetration_rate'],
            'updated_at' => $this->now(),
        ]);

        return $this->overview($projectId, $hotelIds, $userId, $isSuperAdmin);
    }

    public function requireProject(int $projectId, array $hotelIds, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $project = Db::name('opening_projects')->where('id', $projectId)->find();
        if (!$project || !$this->canAccessOwnedProject($project, $userId, $isSuperAdmin)) {
            throw new \RuntimeException('开业项目不存在');
        }

        return $this->normalizeProject($project);
    }

    private function calculateMetrics(array $project, array $tasks, bool $withSuggestions = true): array
    {
        $total = count($tasks);
        $done = count(array_filter($tasks, static fn(array $task): bool => $task['status'] === self::STATUS_DONE));
        $progressTotal = array_sum(array_map(fn(array $task): int => $this->normalizeProgressPercent($task['progress_percent'] ?? null, (string)($task['status'] ?? self::STATUS_TODO)), $tasks));
        $core = array_values(array_filter($tasks, static fn(array $task): bool => (int)$task['is_core'] === 1));
        $coreDone = count(array_filter($core, static fn(array $task): bool => $task['status'] === self::STATUS_DONE));
        $highRisk = count(array_filter($tasks, static fn(array $task): bool => $task['risk_level'] === self::RISK_HIGH));
        $overdue = count(array_filter($tasks, static fn(array $task): bool => !empty($task['is_overdue'])));
        $aiTasks = array_values(array_filter($tasks, static fn(array $task): bool => trim((string)$task['ai_suggestion']) !== ''));
        $aiProgressTotal = array_sum(array_map(fn(array $task): int => $this->normalizeProgressPercent($task['progress_percent'] ?? null, (string)($task['status'] ?? self::STATUS_TODO)), $aiTasks));
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
        $project['ai_penetration_rate'] = count($aiTasks) > 0 ? round($aiProgressTotal / count($aiTasks), 1) : 0;

        return [
            'project' => $project,
            'metrics' => [
                'days_left' => $this->daysLeft((string)$project['opening_date']),
                'total_tasks' => $total,
                'completed_tasks' => $done,
                'completion_rate' => $total > 0 ? round($done / $total * 100, 1) : 0,
                'progress_rate' => $total > 0 ? round($progressTotal / $total, 1) : 0,
                'core_tasks' => count($core),
                'core_completed_tasks' => $coreDone,
                'core_completion_rate' => count($core) > 0 ? round($coreDone / count($core) * 100, 1) : 0,
                'high_risk_count' => $highRisk,
                'overdue_count' => $overdue,
                'ai_covered_tasks' => count($aiTasks),
                'ai_penetration_rate' => $project['ai_penetration_rate'],
            ],
            'category_progress' => array_values($categoryProgress),
            'ai_suggestions' => $withSuggestions ? $this->buildOpeningSuggestions($project, $tasks, $highRisk, $overdue) : [],
        ];
    }

    private function categoryProgress(array $tasks): array
    {
        $groups = [];
        foreach (array_keys(self::SCORE_WEIGHTS) as $category) {
            $groups[$category] = ['category' => $category, 'total' => 0, 'done' => 0, 'completion_rate' => 0, 'progress_rate' => 0, 'progress_sum' => 0];
        }

        foreach ($tasks as $task) {
            $category = $this->scoreCategory((string)$task['category']);
            if (!isset($groups[$category])) {
                $groups[$category] = ['category' => $category, 'total' => 0, 'done' => 0, 'completion_rate' => 0, 'progress_rate' => 0, 'progress_sum' => 0];
            }
            $groups[$category]['total']++;
            $groups[$category]['progress_sum'] += $this->normalizeProgressPercent($task['progress_percent'] ?? null, (string)($task['status'] ?? self::STATUS_TODO));
            if ($task['status'] === self::STATUS_DONE) {
                $groups[$category]['done']++;
            }
        }

        foreach ($groups as &$group) {
            $group['completion_rate'] = $group['total'] > 0 ? round($group['done'] / $group['total'] * 100, 1) : 0;
            $group['progress_rate'] = $group['total'] > 0 ? round($group['progress_sum'] / $group['total'], 1) : 0;
            unset($group['progress_sum']);
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
        $row['progress_percent'] = $this->normalizeProgressPercent($row['progress_percent'] ?? null, (string)($row['status'] ?? self::STATUS_TODO));
        $row['risk_level'] = $this->taskRiskLevel($row, $project);
        $row['is_overdue'] = $this->isOverdue($row);
        return $row;
    }

    private function normalizeProgressPercent(mixed $value, string $status = self::STATUS_TODO): int
    {
        if ($value === null || $value === '') {
            return match ($status) {
                self::STATUS_DONE => 100,
                self::STATUS_DOING => 50,
                self::STATUS_BLOCKED => 25,
                default => 0,
            };
        }
        return max(0, min(100, (int)round((float)$value)));
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
        $positioningImpact = $this->positioningPreparationImpact($project);

        return [
            ['category' => '证照合规', 'task_name' => '营业证照办理确认', 'task_desc' => '确认营业执照、特种行业许可、卫生许可等开业必要证照状态。', 'is_core' => true, 'deadline' => $deadline(0), 'acceptance_standard' => '证照清单完整，缺口项有责任人和补齐日期。', 'ai_suggestion' => '优先建立证照缺口台账，开业前7天仍未闭环的事项升级到店总。'],
            ['category' => '证照合规', 'task_name' => '消防与安全验收资料归档', 'task_desc' => '整理消防验收、安全巡检与应急预案资料。', 'is_core' => true, 'deadline' => $deadline(1), 'acceptance_standard' => '消防、安全资料可追溯，现场抽查无重大缺陷。', 'ai_suggestion' => '消防、安全关键词属于高优先级风险，建议提前做一次全员应急演练。'],
            ['category' => 'PMS系统配置', 'task_name' => 'PMS基础档案配置', 'task_desc' => '完成酒店、楼栋、楼层、房间、账号、权限和夜审规则配置。', 'is_core' => true, 'deadline' => $deadline(2), 'acceptance_standard' => 'PMS可完成入住、续住、换房、退房、夜审全流程。', 'ai_suggestion' => '先用测试订单跑通前台高频流程，再开放正式账号。'],
            ['category' => 'PMS系统配置', 'task_name' => 'PMS支付与发票联调', 'task_desc' => '确认支付、押金、退款、发票、交班报表配置。', 'is_core' => true, 'deadline' => $deadline(3), 'acceptance_standard' => '支付与账务闭环无阻断，异常交易有处理口径。', 'ai_suggestion' => '支付相关未完成默认高风险，建议安排财务和前台联合验收。'],
            ['category' => 'OTA上线配置', 'task_name' => 'OTA门店资料上线', 'task_desc' => '配置携程、美团等渠道门店资料、图片、政策与设施标签。', 'is_core' => true, 'deadline' => $deadline(4), 'acceptance_standard' => '主流OTA页面可搜索、可浏览、信息一致。', 'ai_suggestion' => $positioningImpact['ota']],
            ['category' => 'OTA上线配置', 'task_name' => 'OTA房价库存联通校验', 'task_desc' => '校验渠道房型、价格、库存、保留房和关房规则。', 'is_core' => true, 'deadline' => $deadline(5), 'acceptance_standard' => '各渠道测试预订成功，库存扣减一致。', 'ai_suggestion' => 'OTA和库存相关事项未完成会直接影响开业订单，应每日复核。'],
            ['category' => '房型房价库存', 'task_name' => '房型标准与价格体系确认', 'task_desc' => '确认房型命名、面积、床型、早餐、会员价和开业价。', 'is_core' => true, 'deadline' => $deadline(6), 'acceptance_standard' => '房型房价在PMS与OTA保持一致。', 'ai_suggestion' => $positioningImpact['pricing']],
            ['category' => '房型房价库存', 'task_name' => '首周库存策略设置', 'task_desc' => '配置首周可售库存、保留房和满房保护规则。', 'is_core' => true, 'deadline' => $deadline(7), 'acceptance_standard' => '首周库存可控，无超售和误关房风险。', 'ai_suggestion' => '库存相关事项未完成优先标记高风险，建议与店长每日确认。'],
            ['category' => '客房工程验收', 'task_name' => '客房工程逐房验收', 'task_desc' => '按房间检查门锁、空调、热水、网络、照明、卫浴和安全设施。', 'is_core' => true, 'deadline' => $deadline(8), 'acceptance_standard' => '可售房达到开业标准，维修遗留项有闭环记录。', 'ai_suggestion' => '先验收可售房，再处理非关键尾项，避免开业房量被动缩水。'],
            ['category' => '物资布草备品', 'task_name' => '布草与客用品盘点', 'task_desc' => '确认布草周转量、客用品、清洁工具和仓库摆放。', 'is_core' => false, 'deadline' => $deadline(9), 'acceptance_standard' => '首周运营物资满足满房周转需求。', 'ai_suggestion' => $positioningImpact['material']],
            ['category' => '员工招聘排班', 'task_name' => '开业班表与岗位补齐', 'task_desc' => '确认前厅、客房、工程、保洁、值班经理排班。', 'is_core' => true, 'deadline' => $deadline(10), 'acceptance_standard' => '关键岗位有人到岗，首周班表已发布。', 'ai_suggestion' => '核心岗位缺口会放大开业期服务风险，建议提前准备机动班。'],
            ['category' => '员工培训演练', 'task_name' => '前台全流程演练', 'task_desc' => '演练预订、入住、换房、投诉、退房、夜审和交班。', 'is_core' => true, 'deadline' => $deadline(11), 'acceptance_standard' => '员工能独立完成关键场景，异常场景有SOP。', 'ai_suggestion' => $positioningImpact['training']],
            ['category' => '员工培训演练', 'task_name' => '客房与安全联动演练', 'task_desc' => '演练查房、报修、遗留物、消防、安全和突发事件处理。', 'is_core' => true, 'deadline' => $deadline(12), 'acceptance_standard' => '跨岗位联动流程明确，责任边界清晰。', 'ai_suggestion' => '安全相关演练未完成时建议限制开业房量。'],
            ['category' => '开业营销推广', 'task_name' => '开业营销素材发布', 'task_desc' => '完成开业海报、OTA促销、会员触达和本地渠道宣发。', 'is_core' => false, 'deadline' => $deadline(13), 'acceptance_standard' => '开业促销可见，渠道权益和价格口径一致。', 'ai_suggestion' => $positioningImpact['marketing']],
            ['category' => '财务收银风控', 'task_name' => '收银权限与风控检查', 'task_desc' => '确认收银权限、备用金、退款审批、交班稽核和异常账处理。', 'is_core' => true, 'deadline' => $deadline(14), 'acceptance_standard' => '支付、收银、退款、交班均可追溯。', 'ai_suggestion' => '支付和财务风控事项未完成默认高风险，需在试营业前闭环。'],
        ];
    }

    private function positioningPreparationImpact(array $project): array
    {
        $positioning = trim((string)($project['positioning'] ?? ''));
        $hasAny = static function (array $keywords) use ($positioning): bool {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && mb_stripos($positioning, $keyword) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($positioning === '') {
            return [
                'profile' => '未填写定位',
                'summary' => '定位会影响房型房价、OTA卖点、物资标准、培训话术和开业营销口径。',
                'items' => ['房价体系', 'OTA卖点', '物资标准', '培训话术', '营销口径'],
                'ota' => '按项目定位补齐OTA首图、卖点、设施标签和到店指引，确保页面承接目标客群。',
                'pricing' => '围绕项目定位设置房型命名、早餐权益、会员价和开业价，避免价格口径与产品不匹配。',
                'material' => '按项目定位校准布草、客用品和清洁物资标准，先保障首周满房周转。',
                'training' => '围绕项目定位设计前台话术、投诉处理和高频场景演练，确保服务口径一致。',
                'marketing' => '开业素材需对齐项目定位、目标客群和渠道价格权益，避免卖点分散。',
            ];
        }

        if ($hasAny(['高端', '高档', '豪华', '精品', '奢', '高奢'])) {
            return [
                'profile' => $positioning,
                'summary' => "{$positioning}定位会提高品质体验、服务SOP、布草客用品和OTA图片卖点的准备优先级。",
                'items' => ['品质验收', '服务SOP', '高质感物资', '溢价卖点'],
                'ota' => "{$positioning}定位需优先核对高质感首图、核心设施、服务亮点和取消政策，支撑溢价转化。",
                'pricing' => "{$positioning}定位的房价体系要区分基础房、升级房和权益包，避免低价促销稀释定位。",
                'material' => "{$positioning}定位应优先验收布草克重、客用品质感、房间气味和细节陈列。",
                'training' => "{$positioning}定位要强化迎送、投诉补救、会员识别和夜间服务SOP演练。",
                'marketing' => "{$positioning}定位营销素材优先呈现空间质感、服务细节和差异化权益。",
            ];
        }

        if ($hasAny(['商务', '商旅', '中端', '中档', '精选'])) {
            return [
                'profile' => $positioning,
                'summary' => "{$positioning}定位会重点影响商务设施、发票支付、早餐效率、WiFi和前台高频流程演练。",
                'items' => ['商务设施', '支付发票', '早餐效率', '前台演练'],
                'ota' => "{$positioning}定位需突出商务设施、交通、发票、早餐和网络稳定性，提升商旅转化。",
                'pricing' => "{$positioning}定位要区分协议价、会员价、含早价和周中周末价，保证商务客价格口径清晰。",
                'material' => "{$positioning}定位优先保障办公、洗衣、早餐和高频消耗物资，减少试营业投诉点。",
                'training' => "{$positioning}定位要重点演练发票、续住、快速退房、投诉响应和夜审交班。",
                'marketing' => "{$positioning}定位营销素材优先覆盖商务出行、交通效率、会员权益和企业客户触达。",
            ];
        }

        if ($hasAny(['亲子', '家庭', '度假'])) {
            return [
                'profile' => $positioning,
                'summary' => "{$positioning}定位会强化安全巡检、亲子设施、房型组合、场景素材和本地渠道营销准备。",
                'items' => ['安全巡检', '亲子设施', '场景素材', '本地营销'],
                'ota' => "{$positioning}定位需补齐家庭房、亲子设施、安全提示和周边游玩标签，降低咨询成本。",
                'pricing' => "{$positioning}定位要设置家庭房、连住、套餐和节假日价格，避免库存与客群需求错配。",
                'material' => "{$positioning}定位应优先核对亲子备品、安全防护、加床和家庭客高频消耗物资。",
                'training' => "{$positioning}定位要演练儿童安全、家庭客投诉、加床加备品和节假日高峰接待。",
                'marketing' => "{$positioning}定位营销素材优先呈现家庭场景、亲子设施、周边玩法和本地渠道权益。",
            ];
        }

        if ($hasAny(['经济', '快捷', '轻居', '性价比'])) {
            return [
                'profile' => $positioning,
                'summary' => "{$positioning}定位会更关注成本控制、清洁效率、基础物资、价格带和渠道转化效率。",
                'items' => ['成本控制', '清洁效率', '基础物资', '渠道转化'],
                'ota' => "{$positioning}定位需突出性价比、交通、干净安全和基础设施，避免卖点过度承诺。",
                'pricing' => "{$positioning}定位要锁定价格带、会员价和促销底线，避免开业期价格失控。",
                'material' => "{$positioning}定位应优先保障基础物资、安全库存和清洁效率，控制非必要采购。",
                'training' => "{$positioning}定位要重点演练快速入住、客房周转、问题房处理和渠道咨询响应。",
                'marketing' => "{$positioning}定位营销素材优先突出价格优势、位置便利和基础体验稳定性。",
            ];
        }

        return [
            'profile' => $positioning,
            'summary' => "{$positioning}定位会同步影响产品卖点、房价库存、物资配置、员工培训和开业营销口径。",
            'items' => ['产品卖点', '房价库存', '物资配置', '营销口径'],
            'ota' => "{$positioning}定位需映射到OTA首图、卖点、设施标签和到店指引，确保渠道页面承接目标客群。",
            'pricing' => "{$positioning}定位要同步到房型命名、权益配置、开业价和库存策略，避免价格与产品不匹配。",
            'material' => "{$positioning}定位应转成布草、客用品、清洁工具和安全库存标准，支撑首周运营。",
            'training' => "{$positioning}定位要进入前台话术、投诉处理、会员识别和高频场景演练。",
            'marketing' => "{$positioning}定位营销素材需统一目标客群、核心卖点、渠道权益和价格口径。",
        ];
    }

    private function buildOpeningSuggestions(array $project, array $tasks, int $highRisk, int $overdue): array
    {
        if (empty($tasks)) {
            return ['先生成标准开业检查清单，再按负责人和截止时间推进闭环。'];
        }

        try {
            $aiSuggestions = $this->buildAiOpeningSuggestions($project, $tasks, $highRisk, $overdue);
            if (!empty($aiSuggestions)) {
                return $aiSuggestions;
            }
        } catch (Throwable $e) {
            // AI 不可用时继续使用规则建议，避免影响开业总览加载。
        }

        return $this->buildFallbackOpeningSuggestions($project, $tasks, $highRisk, $overdue);
    }

    private function buildAiOpeningSuggestions(array $project, array $tasks, int $highRisk, int $overdue): array
    {
        $categoryProgress = $this->categoryProgress($tasks);
        $blockedTasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['status'] ?? '') === self::STATUS_BLOCKED));
        $highRiskTasks = array_values(array_filter($tasks, static fn(array $task): bool => ($task['risk_level'] ?? '') === self::RISK_HIGH));
        $overdueTasks = array_values(array_filter($tasks, static fn(array $task): bool => !empty($task['is_overdue'])));
        $progressTotal = array_sum(array_map(fn(array $task): int => $this->normalizeProgressPercent($task['progress_percent'] ?? null, (string)($task['status'] ?? self::STATUS_TODO)), $tasks));

        $messages = [
            [
                'role' => 'system',
                'content' => '你是连锁酒店开业项目经理。只输出符合 schema 的 JSON。必须基于用户提供的真实项目、任务、逾期、高风险和分类完成率数据给建议；不得编造未提供的数据、人员、日期或外部市场事实。建议必须可执行、克制、按优先级表达，每条不超过80个中文字符。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'project' => [
                        'project_name' => $project['project_name'] ?? '',
                        'hotel_name' => $project['hotel_name'] ?? '',
                        'city' => $project['city'] ?? '',
                        'brand' => $project['brand'] ?? '',
                        'positioning' => $project['positioning'] ?? '',
                        'room_count' => $project['room_count'] ?? 0,
                        'opening_date' => $project['opening_date'] ?? '',
                        'manager_name' => $project['manager_name'] ?? '',
                        'overall_score' => $project['overall_score'] ?? 0,
                        'risk_level' => $project['risk_level'] ?? '',
                        'days_left' => $this->daysLeft((string)($project['opening_date'] ?? '')),
                    ],
                    'positioning_impact' => $this->positioningPreparationImpact($project),
                    'metrics' => [
                        'total_tasks' => count($tasks),
                        'done_tasks' => count(array_filter($tasks, static fn(array $task): bool => ($task['status'] ?? '') === self::STATUS_DONE)),
                        'progress_rate' => count($tasks) > 0 ? round($progressTotal / count($tasks), 1) : 0,
                        'high_risk_count' => $highRisk,
                        'overdue_count' => $overdue,
                        'blocked_count' => count($blockedTasks),
                    ],
                    'category_progress' => array_values($categoryProgress),
                    'high_risk_tasks' => $this->openingSuggestionTaskSnapshot($highRiskTasks),
                    'overdue_tasks' => $this->openingSuggestionTaskSnapshot($overdueTasks),
                    'blocked_tasks' => $this->openingSuggestionTaskSnapshot($blockedTasks),
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $result = $this->client->createJsonResponse($messages, $this->openingSuggestionSchema(), 'deepseek_v4_default');
        return $this->normalizeOpeningSuggestionList($result['suggestions'] ?? []);
    }

    private function openingSuggestionTaskSnapshot(array $tasks): array
    {
        return array_map(static fn(array $task): array => [
            'category' => (string)($task['category'] ?? ''),
            'task_name' => (string)($task['task_name'] ?? ''),
            'is_core' => (int)($task['is_core'] ?? 0),
            'owner_name' => (string)($task['owner_name'] ?? ''),
            'deadline' => (string)($task['deadline'] ?? ''),
            'status' => (string)($task['status'] ?? ''),
            'progress_percent' => (int)($task['progress_percent'] ?? 0),
            'risk_level' => (string)($task['risk_level'] ?? ''),
            'is_overdue' => !empty($task['is_overdue']),
            'remark' => mb_substr(trim((string)($task['remark'] ?? '')), 0, 80),
        ], array_slice($tasks, 0, 8));
    }

    private function openingSuggestionSchema(): array
    {
        return [
            'x-governance' => [
                'module' => 'opening',
                'scenario' => 'opening_suggestions',
                'prompt_version' => 'opening.suggestions.v1',
                'decision_impact' => 'operational',
                'knowledge_sources' => ['project', 'metrics', 'category_progress', 'high_risk_tasks', 'overdue_tasks'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['suggestions'],
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ];
    }

    private function normalizeOpeningSuggestionList(array $suggestions): array
    {
        $normalized = [];
        foreach ($suggestions as $suggestion) {
            $text = trim((string)$suggestion);
            if ($text === '') {
                continue;
            }
            $normalized[] = mb_substr($text, 0, 120);
            if (count($normalized) >= 5) {
                break;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function buildFallbackOpeningSuggestions(array $project, array $tasks, int $highRisk, int $overdue): array
    {
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
        if (trim((string)($project['positioning'] ?? '')) !== '') {
            $impact = $this->positioningPreparationImpact($project);
            $suggestions[] = $impact['summary'] . ' 请同步校准清单、责任人和验收口径。';
        }
        if (empty($suggestions)) {
            $suggestions[] = '当前开业准备节奏稳定，建议保持每日复盘并锁定开业前3天最终验收。';
        }

        return $suggestions;
    }

    private function recentProjects(array $hotelIds, int $currentProjectId, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $query = Db::name('opening_projects')->where('id', '<>', $currentProjectId)->order('updated_at', 'desc')->limit(10);
        $this->applyOwnerScope($query, $userId, $isSuperAdmin);

        return array_map([$this, 'normalizeProject'], $query->select()->toArray());
    }

    private function applyOwnerScope($query, int $userId, bool $isSuperAdmin): void
    {
        if ($isSuperAdmin) {
            return;
        }

        if ($userId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('created_by', $userId);
    }

    private function canAccessOwnedProject(array $project, int $userId, bool $isSuperAdmin): bool
    {
        return $isSuperAdmin || ($userId > 0 && (int)($project['created_by'] ?? 0) === $userId);
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
