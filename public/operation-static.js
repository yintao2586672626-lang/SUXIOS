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
    const buildOpeningCategoryProgressCards = (categoryProgress = []) => {
        const list = Array.isArray(categoryProgress) ? categoryProgress : [];
        return list.map((item) => {
            const total = safeOpeningOverviewNumber(item.total);
            const done = safeOpeningOverviewNumber(item.done);
            const progress = clampOpeningOverviewPercent(item.completion_rate);
            if (total <= 0) {
                return {
                    category: item.category || '未分类',
                    progress,
                    countLabel: '暂无检查项',
                    progressHint: '待生成',
                    status: '待生成',
                    statusClass: 'bg-gray-100 text-gray-600',
                    progressClass: 'bg-gray-300',
                };
            }
            if (progress >= 100) {
                return {
                    category: item.category || '未分类',
                    progress,
                    countLabel: `${done}/${total} 项完成`,
                    progressHint: '已完成',
                    status: '已完成',
                    statusClass: 'bg-green-50 text-green-700',
                    progressClass: 'bg-green-600',
                };
            }
            if (done > 0) {
                return {
                    category: item.category || '未分类',
                    progress,
                    countLabel: `${done}/${total} 项完成`,
                    progressHint: '推进中',
                    status: '推进中',
                    statusClass: 'bg-blue-50 text-blue-700',
                    progressClass: 'bg-blue-600',
                };
            }
            return {
                category: item.category || '未分类',
                progress,
                countLabel: `${done}/${total} 项完成`,
                progressHint: '未开始',
                status: '未开始',
                statusClass: 'bg-yellow-50 text-yellow-700',
                progressClass: 'bg-yellow-500',
            };
        });
    };
    const buildOpeningPositioningImpact = (value = '') => {
        const positioning = String(value || '').trim();
        const includesAny = (keywords) => keywords.some(keyword => positioning.includes(keyword));
        if (!positioning) {
            return {
                summary: '用于确定房型房价、OTA卖点、物资标准、培训话术和开业营销口径；保存后会进入AI建议和新生成清单。',
                items: ['房价体系', 'OTA卖点', '物资标准', '培训话术'],
            };
        }
        if (includesAny(['高端', '高档', '豪华', '精品', '奢', '高奢'])) {
            return {
                summary: `${positioning}定位会提高品质体验、服务SOP、布草客用品和OTA图片卖点的准备优先级。`,
                items: ['品质验收', '服务SOP', '高质感物资', '溢价卖点'],
            };
        }
        if (includesAny(['商务', '商旅', '中端', '中档', '精选'])) {
            return {
                summary: `${positioning}定位会重点影响商务设施、发票支付、早餐效率、WiFi和前台高频流程演练。`,
                items: ['商务设施', '支付发票', '早餐效率', '前台演练'],
            };
        }
        if (includesAny(['亲子', '家庭', '度假'])) {
            return {
                summary: `${positioning}定位会强化安全巡检、亲子设施、房型组合、场景素材和本地渠道营销准备。`,
                items: ['安全巡检', '亲子设施', '场景素材', '本地营销'],
            };
        }
        if (includesAny(['经济', '快捷', '轻居', '性价比'])) {
            return {
                summary: `${positioning}定位会更关注成本控制、清洁效率、基础物资、价格带和渠道转化效率。`,
                items: ['成本控制', '清洁效率', '基础物资', '渠道转化'],
            };
        }
        return {
            summary: `${positioning}定位会同步影响产品卖点、房价库存、物资配置、员工培训和开业营销口径。`,
            items: ['产品卖点', '房价库存', '物资配置', '营销口径'],
        };
    };
    const buildOpeningTaskProgressCards = (stats = {}) => [
        {
            label: '任务进度均值',
            value: `${stats.averageProgress}%`,
            hint: stats.total > 0 ? `${stats.total} 项检查项已纳入进度` : '暂无检查项',
            icon: 'fas fa-clipboard-check',
            iconClass: 'bg-blue-50 text-blue-600',
            progress: stats.averageProgress,
            progressClass: 'bg-blue-600',
        },
        {
            label: '整体完成率',
            value: `${stats.completionRate}%`,
            hint: `${stats.done}/${stats.total} 项已完成，推进中 ${stats.doing} 项`,
            icon: 'fas fa-check-circle',
            iconClass: 'bg-green-50 text-green-600',
            progress: stats.completionRate,
            progressClass: 'bg-green-600',
        },
        {
            label: '逾期未完成',
            value: stats.overdue,
            hint: stats.overdue > 0 ? '需要今日复盘截止时间' : '暂无逾期事项',
            icon: 'fas fa-clock',
            iconClass: 'bg-red-50 text-red-600',
            valueClass: stats.overdue > 0 ? 'text-red-600' : 'text-gray-900',
            progress: null,
        },
        {
            label: '7天内到期',
            value: stats.dueSoon,
            hint: '临近开业节点优先推进',
            icon: 'fas fa-hourglass-half',
            iconClass: 'bg-yellow-50 text-yellow-700',
            valueClass: stats.dueSoon > 0 ? 'text-yellow-700' : 'text-gray-900',
            progress: null,
        },
        {
            label: '未分配负责人',
            value: stats.noOwner,
            hint: stats.noOwner > 0 ? '建议补齐责任人' : '责任人已覆盖',
            icon: 'fas fa-user-check',
            iconClass: 'bg-gray-100 text-gray-600',
            valueClass: stats.noOwner > 0 ? 'text-yellow-700' : 'text-gray-900',
            progress: null,
        },
    ];
    const buildOpeningTaskProgressStages = (stats = {}) => {
        const total = Math.max(1, stats.total);
        return [
            { label: '未开始', count: stats.progressEmpty, percent: Math.round(stats.progressEmpty / total * 100), className: 'text-gray-700', barClass: 'bg-gray-400' },
            { label: '1%-49%', count: stats.progressLow, percent: Math.round(stats.progressLow / total * 100), className: 'text-yellow-700', barClass: 'bg-yellow-500' },
            { label: '50%-99%', count: stats.progressHigh, percent: Math.round(stats.progressHigh / total * 100), className: 'text-blue-700', barClass: 'bg-blue-600' },
            { label: '100%', count: stats.progressDone, percent: Math.round(stats.progressDone / total * 100), className: 'text-green-700', barClass: 'bg-green-600' },
        ];
    };
    const buildOpeningStatusFilterChips = (stats = {}) => [
        { value: '', label: '全部', count: stats.total, activeClass: 'bg-gray-900 text-white border-gray-900' },
        { value: 'todo', label: '未开始', count: stats.todo, activeClass: 'bg-gray-600 text-white border-gray-600' },
        { value: 'doing', label: '进行中', count: stats.doing, activeClass: 'bg-blue-600 text-white border-blue-600' },
        { value: 'done', label: '已完成', count: stats.done, activeClass: 'bg-green-600 text-white border-green-600' },
        { value: 'blocked', label: '受阻', count: stats.blocked, activeClass: 'bg-yellow-500 text-white border-yellow-500' },
    ];
    const buildOpeningAttentionFilterChips = (stats = {}) => [
        { value: 'overdue', label: '逾期', count: stats.overdue, activeClass: 'bg-red-600 text-white border-red-600' },
        { value: 'dueSoon', label: '7天内到期', count: stats.dueSoon, activeClass: 'bg-yellow-500 text-white border-yellow-500' },
        { value: 'high', label: '高风险', count: stats.highRisk, activeClass: 'bg-red-600 text-white border-red-600' },
        { value: 'blocked', label: '受阻', count: stats.blocked, activeClass: 'bg-yellow-500 text-white border-yellow-500' },
        { value: 'noOwner', label: '未分配', count: stats.noOwner, activeClass: 'bg-gray-700 text-white border-gray-700' },
        { value: 'core', label: '核心项', count: stats.core, activeClass: 'bg-blue-600 text-white border-blue-600' },
    ];
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
        buildOpeningCategoryProgressCards,
        buildOpeningPositioningImpact,
        buildOpeningTaskProgressCards,
        buildOpeningTaskProgressStages,
        buildOpeningStatusFilterChips,
        buildOpeningAttentionFilterChips,
        buildOpeningAiOutputResult,
        openingCategories,
        openingStatusOptions,
        openingProgressQuickValues,
    };
})();
