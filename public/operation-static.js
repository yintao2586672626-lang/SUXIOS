window.SUXI_OPERATION_STATIC = (() => {
    const lifecycleMetricLabels = {
        reports: '可研报告',
        latest_grade: '最新评级',
        latest_project: '最新项目',
        projects: '开业项目',
        open_tasks: '未完成任务',
        overdue_tasks: '逾期任务',
        avg_score: '平均评分',
        unread_alerts: '未读预警',
        active_actions: '执行动作',
        ota_rows: 'OTA数据',
        pending_prices: '待审价格',
        applied_prices: '已应用价格',
        future_forecasts: '未来预测',
        strategy_simulations: '推演记录',
        competitor_price_logs: '竞对价格',
    };
    const lifecycleStageTitles = {
        investment: '筹建',
        opening: '开业',
        operation: '运营',
        revenue: '收益',
        transfer: '转让',
    };
    const operationAlertFilters = [
        { key: 'all', label: '全部' },
        { key: 'high', label: '高风险' },
        { key: 'medium', label: '中风险' },
        { key: 'low', label: '低风险' },
        { key: 'unread', label: '未读' },
        { key: 'read', label: '已读' },
    ];
    const operationStrategyTypes = [
        { key: 'price_adjust', label: '调价模拟' },
        { key: 'promotion', label: '促销模拟' },
        { key: 'room_inventory', label: '房量模拟' },
        { key: 'competitor_follow', label: '竞对跟价' },
        { key: 'holiday_strategy', label: '节假日策略' },
    ];
    const openingCategories = [
        '证照合规',
        'PMS系统配置',
        'OTA上线配置',
        '房型房价库存',
        '客房工程验收',
        '物资布草备品',
        '员工招聘排班',
        '员工培训演练',
        '开业营销推广',
        '财务收银风控',
    ];
    const openingStatusOptions = [
        { value: 'todo', label: '未开始' },
        { value: 'doing', label: '进行中' },
        { value: 'done', label: '已完成' },
        { value: 'blocked', label: '受阻' },
    ];
    const openingProgressQuickValues = [0, 25, 50, 75, 100];
    const operationFormatters = (formatters = {}) => ({
        value: typeof formatters.value === 'function'
            ? formatters.value
            : ((value, suffix = '') => value === null || value === undefined || value === '' ? '-' : `${value}${suffix}`),
        money: typeof formatters.money === 'function'
            ? formatters.money
            : ((value) => value === null || value === undefined || value === '' ? '-' : `¥${Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`),
    });
    const buildOperationSummaryCards = (summary = {}, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        return [
            { label: '收入', value: formatter.money(summary.revenue) },
            { label: '订单', value: formatter.value(summary.orders) },
            { label: '间夜', value: formatter.value(summary.room_nights) },
            { label: 'ADR', value: formatter.money(summary.adr) },
            { label: 'OCC', value: formatter.value(summary.occ, '%') },
            { label: 'RevPAR', value: formatter.money(summary.revpar) },
        ];
    };
    const buildOperationOtaCards = (ota = {}, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        return [
            { label: '曝光', value: formatter.value(ota.exposure) },
            { label: '访客', value: formatter.value(ota.visitors) },
            { label: '浏览', value: formatter.value(ota.views) },
            { label: '订单', value: formatter.value(ota.orders) },
            { label: '浏览转化率', value: formatter.value(ota.view_rate, '%') },
            { label: '订单转化率', value: formatter.value(ota.order_rate, '%') },
        ];
    };
    const buildOperationCompetitorCards = (competitors = {}, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        return [
            { label: '竞对均价', value: formatter.money(competitors.avg_price) },
            { label: '本店与竞对价差', value: formatter.money(competitors.price_gap) },
            { label: '竞对评分', value: formatter.value(competitors.avg_score) },
            { label: '本店与竞对评分差', value: formatter.value(competitors.score_gap) },
            { label: '排名', value: formatter.value(competitors.rank_position) },
        ];
    };
    const buildOperationSourceBrief = (data = null) => {
        if (!data) {
            return {
                status: '待加载',
                summary: '选择酒店和日期后，先确认经营结果、渠道漏斗、竞对和口碑数据是否具备判断条件。',
                className: 'bg-gray-50 text-gray-500',
            };
        }

        const flags = data.abnormal_flags || [];
        if (flags.length) {
            return {
                status: '优先复核',
                summary: flags[0],
                className: 'bg-amber-50 text-amber-700',
            };
        }

        const missingModules = [
            ['经营日报', data.summary],
            ['OTA数据', data.ota],
            ['竞对数据', data.competitors],
            ['服务质量数据', data.service_quality],
        ]
            .filter(([, item]) => (item?.data_status || '') !== 'ok')
            .map(([label]) => label);

        if (missingModules.length) {
            return {
                status: '样本不足',
                summary: `先补齐${missingModules.join('、')}，否则只能看到结果，无法判断收入变化的真实原因。`,
                className: 'bg-gray-50 text-gray-500',
            };
        }

        return {
            status: '可分析',
            summary: '数据已覆盖结果、流量、竞对和口碑，可进入根因分析，按优先级拆解流量、转化、价格和评分影响。',
            className: 'bg-green-50 text-green-700',
        };
    };
    const buildOperationDecisionCards = (data = {}, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        const summary = data.summary || {};
        const ota = data.ota || {};
        const competitors = data.competitors || {};
        const holiday = data.holiday || {};
        const holidayValue = holiday.next_holiday
            ? `${holiday.next_holiday} · ${formatter.value(holiday.days_left)}天`
            : '暂无节假日窗口';

        return [
            {
                title: '经营结果',
                value: `收入 ${formatter.money(summary.revenue)} / RevPAR ${formatter.money(summary.revpar)}`,
                desc: '判断问题是否已反映到收入、房价、入住率和间夜。',
            },
            {
                title: '渠道断点',
                value: `曝光 ${formatter.value(ota.exposure)} / 订单转化 ${formatter.value(ota.order_rate, '%')}`,
                desc: '定位流量不足、浏览承接差，还是访客未下单。',
            },
            {
                title: '外部压力',
                value: `价差 ${formatter.money(competitors.price_gap)} / 评分差 ${formatter.value(competitors.score_gap)}`,
                desc: '校准价格和口碑是否弱于同圈层竞对。',
            },
            {
                title: '收益窗口',
                value: holidayValue,
                desc: holiday.suggestion || '用于决定是否提前处理库存、底价和活动节奏。',
            },
        ];
    };
    const openingRiskTextFallback = (risk) => ({ high: '高风险', medium: '中风险', low: '低风险' }[risk] || '低风险');
    const openingRiskTextClassFallback = (risk) => ({ high: 'text-red-600', medium: 'text-yellow-600', low: 'text-green-600' }[risk] || 'text-green-600');
    const safeOpeningOverviewNumber = (value) => {
        const number = Number(value);
        return Number.isFinite(number) ? number : 0;
    };
    const clampOpeningOverviewPercent = (value) => Math.max(0, Math.min(100, safeOpeningOverviewNumber(value)));
    const buildOpeningOverviewCards = (data = null, helpers = {}) => {
        if (!data) return [];
        const openingRiskText = typeof helpers.openingRiskText === 'function'
            ? helpers.openingRiskText
            : openingRiskTextFallback;
        const openingRiskTextClass = typeof helpers.openingRiskTextClass === 'function'
            ? helpers.openingRiskTextClass
            : openingRiskTextClassFallback;
        const metrics = data.metrics || {};
        const project = data.project || {};
        const daysLeft = safeOpeningOverviewNumber(metrics.days_left);
        const completionRate = clampOpeningOverviewPercent(metrics.completion_rate);
        const coreCompletionRate = clampOpeningOverviewPercent(metrics.core_completion_rate);
        const aiRate = clampOpeningOverviewPercent(metrics.ai_penetration_rate);
        const completedTasks = safeOpeningOverviewNumber(metrics.completed_tasks);
        const totalTasks = safeOpeningOverviewNumber(metrics.total_tasks);
        const coreCompletedTasks = safeOpeningOverviewNumber(metrics.core_completed_tasks);
        const coreTasks = safeOpeningOverviewNumber(metrics.core_tasks);
        return [
            {
                label: '开业倒计时',
                value: `${daysLeft}天`,
                hint: project.opening_date ? `计划开业 ${project.opening_date}` : '未设置开业日期',
                icon: 'fas fa-calendar-day',
                iconClass: daysLeft < 0 ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600',
                valueClass: daysLeft < 0 ? 'text-red-600' : 'text-gray-900',
            },
            {
                label: '总评分',
                value: project.overall_score ?? 0,
                hint: '规则引擎评分 / 100',
                icon: 'fas fa-chart-line',
                iconClass: 'bg-slate-50 text-slate-600',
            },
            {
                label: '风险等级',
                value: openingRiskText(project.risk_level),
                hint: '高风险与逾期自动识别',
                icon: 'fas fa-exclamation-triangle',
                iconClass: project.risk_level === 'high' ? 'bg-red-50 text-red-600' : (project.risk_level === 'medium' ? 'bg-yellow-50 text-yellow-600' : 'bg-green-50 text-green-600'),
                valueClass: openingRiskTextClass(project.risk_level),
            },
            {
                label: '检查项完成率',
                value: `${completionRate}%`,
                hint: totalTasks > 0 ? `已完成 ${completedTasks} 项，共 ${totalTasks} 项` : '暂无检查项',
                icon: 'fas fa-tasks',
                iconClass: 'bg-blue-50 text-blue-600',
                progress: completionRate,
                progressClass: 'bg-blue-600',
                countLabel: totalTasks > 0 ? `${completedTasks}/${totalTasks} 项` : '暂无检查项',
            },
            {
                label: '核心完成率',
                value: `${coreCompletionRate}%`,
                hint: coreTasks > 0 ? `核心项 ${coreCompletedTasks}/${coreTasks} 项` : '暂无核心检查项',
                icon: 'fas fa-clipboard-check',
                iconClass: 'bg-green-50 text-green-600',
                progress: coreCompletionRate,
                progressClass: 'bg-green-600',
                countLabel: coreTasks > 0 ? `${coreCompletedTasks}/${coreTasks} 项` : '暂无核心项',
            },
            {
                label: '高风险事项',
                value: metrics.high_risk_count ?? 0,
                hint: '核心阻断优先处理',
                icon: 'fas fa-fire',
                iconClass: 'bg-red-50 text-red-600',
                valueClass: Number(metrics.high_risk_count || 0) > 0 ? 'text-red-600' : 'text-gray-900',
            },
            {
                label: '逾期事项',
                value: metrics.overdue_count ?? 0,
                hint: '未完成且超过截止时间',
                icon: 'fas fa-clock',
                iconClass: 'bg-yellow-50 text-yellow-600',
                valueClass: Number(metrics.overdue_count || 0) > 0 ? 'text-yellow-600' : 'text-gray-900',
            },
            {
                label: 'AI建议推进率',
                value: `${aiRate}%`,
                hint: '带AI建议事项平均进度',
                icon: 'fas fa-robot',
                iconClass: 'bg-blue-50 text-blue-600',
                progress: aiRate,
                progressClass: 'bg-blue-600',
                countLabel: totalTasks > 0 ? `${safeOpeningOverviewNumber(metrics.ai_covered_tasks)}/${totalTasks} 项带AI建议` : '暂无检查项',
            },
        ];
    };
    const openingAiTaskProgressPercent = (task, helpers = {}) => {
        if (typeof helpers.taskProgressPercent === 'function') {
            return helpers.taskProgressPercent(task);
        }
        return clampOpeningOverviewPercent(task?.progress_percent ?? (task?.status === 'done' ? 100 : 0));
    };
    const openingAiTaskReason = (task, helpers = {}) => {
        const taskIsOverdue = typeof helpers.taskIsOverdue === 'function' ? helpers.taskIsOverdue(task) : Number(task?.is_overdue) === 1;
        const taskIsDueSoon = typeof helpers.taskIsDueSoon === 'function' ? helpers.taskIsDueSoon(task) : false;
        const taskHasOwner = typeof helpers.taskHasOwner === 'function'
            ? helpers.taskHasOwner(task)
            : String(task?.owner_name || '').trim().length > 0;
        if (taskIsOverdue) return { text: '逾期', className: 'text-red-600' };
        if ((task?.status || '') === 'blocked') return { text: '受阻', className: 'text-yellow-700' };
        if ((task?.risk_level || '') === 'high') return { text: '高风险', className: 'text-red-600' };
        if (taskIsDueSoon) return { text: '临期', className: 'text-yellow-700' };
        if (!taskHasOwner) return { text: '待分配', className: 'text-gray-700' };
        return { text: '待推进', className: 'text-blue-600' };
    };
    const openingAiTaskPriorityScore = (task, helpers = {}) => {
        const taskIsOverdue = typeof helpers.taskIsOverdue === 'function' ? helpers.taskIsOverdue(task) : Number(task?.is_overdue) === 1;
        const taskIsDueSoon = typeof helpers.taskIsDueSoon === 'function' ? helpers.taskIsDueSoon(task) : false;
        const taskHasOwner = typeof helpers.taskHasOwner === 'function'
            ? helpers.taskHasOwner(task)
            : String(task?.owner_name || '').trim().length > 0;
        let score = 0;
        if (taskIsOverdue) score += 100;
        if ((task?.status || '') === 'blocked') score += 80;
        if ((task?.risk_level || '') === 'high') score += 70;
        if (taskIsDueSoon) score += 45;
        if (Number(task?.is_core) === 1) score += 25;
        if (!taskHasOwner) score += 15;
        score += Math.max(0, 100 - openingAiTaskProgressPercent(task, helpers)) / 10;
        return score;
    };
    const buildOpeningAiOutputResult = ({ tasks = [], stats = {}, overviewSuggestions = [], helpers = {} } = {}) => {
        const taskRows = Array.isArray(tasks) ? tasks : [];
        const overviewOutputs = Array.isArray(overviewSuggestions)
            ? overviewSuggestions.map(item => String(item || '').trim()).filter(Boolean)
            : [];
        const taskOutputs = taskRows
            .filter(task => String(task.ai_suggestion || '').trim())
            .map(task => {
                const reason = openingAiTaskReason(task, helpers);
                return {
                    id: task.id,
                    category: task.category || '未分类',
                    task_name: task.task_name || '未命名检查项',
                    owner_name: task.owner_name || '',
                    suggestion: String(task.ai_suggestion || '').trim(),
                    progress: openingAiTaskProgressPercent(task, helpers),
                    reason: reason.text,
                    className: reason.className,
                    priorityScore: openingAiTaskPriorityScore(task, helpers),
                };
            })
            .sort((a, b) => b.priorityScore - a.priorityScore)
            .slice(0, 6);
        const total = Math.max(0, Number(stats.total || 0));
        const aiCovered = taskOutputs.length;
        const aiCoverage = total > 0 ? Math.round(aiCovered / total * 100) : 0;
        const riskOutputCount = taskRows
            .filter(task => (task.risk_level === 'high') || (typeof helpers.taskIsOverdue === 'function' ? helpers.taskIsOverdue(task) : Number(task?.is_overdue) === 1) || task.status === 'blocked')
            .filter(task => String(task.ai_suggestion || '').trim()).length;
        const missingAi = Math.max(0, total - aiCovered);
        const hasAiOutput = overviewOutputs.length > 0 || taskOutputs.length > 0;
        return {
            badgeText: hasAiOutput ? '已有AI输出' : '暂无AI输出',
            badgeClass: hasAiOutput ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600',
            cards: [
                {
                    label: '总览输出',
                    value: overviewOutputs.length,
                    hint: overviewOutputs.length > 0 ? '来自开业总览AI建议' : '暂无总览AI建议',
                    icon: 'fas fa-comment-dots',
                    iconClass: 'text-blue-600',
                    borderClass: 'border-blue-500',
                    valueClass: 'text-blue-600',
                },
                {
                    label: '检查项输出',
                    value: `${aiCoverage}%`,
                    hint: total > 0 ? `${aiCovered}/${total} 项带AI建议` : '暂无检查项',
                    icon: 'fas fa-robot',
                    iconClass: aiCoverage >= 80 ? 'text-green-600' : 'text-yellow-700',
                    borderClass: aiCoverage >= 80 ? 'border-green-500' : 'border-yellow-500',
                    valueClass: aiCoverage >= 80 ? 'text-green-600' : 'text-yellow-700',
                },
                {
                    label: '风险项AI输出',
                    value: riskOutputCount,
                    hint: `高风险 ${stats.highRisk} · 逾期 ${stats.overdue} · 受阻 ${stats.blocked}`,
                    icon: 'fas fa-shield-alt',
                    iconClass: riskOutputCount > 0 ? 'text-red-600' : 'text-gray-500',
                    borderClass: riskOutputCount > 0 ? 'border-red-500' : 'border-gray-300',
                    valueClass: riskOutputCount > 0 ? 'text-red-600' : 'text-gray-700',
                },
                {
                    label: '待补齐输出',
                    value: missingAi,
                    hint: missingAi > 0 ? '这些检查项还没有AI建议' : '检查项AI建议已覆盖',
                    icon: 'fas fa-exclamation-circle',
                    iconClass: missingAi > 0 ? 'text-yellow-700' : 'text-green-600',
                    borderClass: missingAi > 0 ? 'border-yellow-500' : 'border-green-500',
                    valueClass: missingAi > 0 ? 'text-yellow-700' : 'text-green-600',
                },
            ],
            overviewOutputs,
            taskOutputs,
        };
    };

    return {
        lifecycleMetricLabels,
        lifecycleStageTitles,
        operationAlertFilters,
        operationStrategyTypes,
        buildOperationSummaryCards,
        buildOperationOtaCards,
        buildOperationCompetitorCards,
        buildOperationSourceBrief,
        buildOperationDecisionCards,
        buildOpeningOverviewCards,
        buildOpeningAiOutputResult,
        openingCategories,
        openingStatusOptions,
        openingProgressQuickValues,
    };
})();
