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
    const formatOpeningDate = (date) => {
        const value = date instanceof Date ? date : new Date(date);
        if (Number.isNaN(value.getTime())) return '';
        const year = value.getFullYear();
        const month = String(value.getMonth() + 1).padStart(2, '0');
        const day = String(value.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    const buildOpeningProjectFormDefaults = (now = new Date()) => {
        const baseDate = now instanceof Date ? now : new Date(now);
        return {
            hotel_id: '',
            project_name: '',
            hotel_name: '',
            city: '',
            brand: '',
            positioning: '',
            room_count: '',
            opening_date: formatOpeningDate(new Date(baseDate.getTime() + 45 * 24 * 60 * 60 * 1000)),
            manager_name: '',
        };
    };
    const normalizeOpeningProjectFormForSubmit = (form = {}, hotelOptions = []) => {
        const normalized = { ...form };
        const options = Array.isArray(hotelOptions) ? hotelOptions : [];
        if (!normalized.hotel_id && options.length === 1) {
            normalized.hotel_id = String(options[0].id);
        }
        if (!normalized.project_name && normalized.hotel_name) {
            normalized.project_name = `${normalized.hotel_name}开业项目`;
        }
        normalized.room_count = Math.max(0, Number(normalized.room_count || 0));
        return normalized;
    };
    const buildOpeningProjectFormFromProject = (project = null) => {
        const defaults = buildOpeningProjectFormDefaults();
        if (!project) return defaults;
        return {
            ...defaults,
            hotel_id: project.hotel_id ? String(project.hotel_id) : '',
            project_name: project.project_name || '',
            hotel_name: project.hotel_name || '',
            city: project.city || '',
            brand: project.brand || '',
            positioning: project.positioning || '',
            room_count: project.room_count || '',
            opening_date: project.opening_date || defaults.opening_date,
            manager_name: project.manager_name || '',
        };
    };
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
            { label: '填单人数', value: formatter.value(ota.order_filling) },
            { label: '提交人数', value: formatter.value(ota.order_submit) },
            { label: '曝光→详情', value: formatter.value(ota.flow_rate, '%') },
            { label: '填单→提交', value: formatter.value(ota.fill_submit_rate, '%') },
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
            summary: '当前来源记录覆盖结果、流量、竞对和口碑，可进入可能影响因素排查；各因素仍需分别取证，不视为已证明根因。',
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
    const operationCanApproveExecution = (item) => item?.approval?.status === 'pending_approval';
    const operationCanExecuteWithEvidence = (item) => ['pending_execute', 'executing'].includes(item?.execution?.status || '') && Number(item?.execution?.task_id || 0) > 0;
    const operationCanReviewExecution = (item) => item?.execution?.status === 'executed' && item?.review?.is_available !== false && !['success', 'near_success', 'failed'].includes(item?.review?.status || '') && Number(item?.execution?.task_id || 0) > 0;
    const operationExecutionActionAvailable = (item) => operationCanApproveExecution(item) || operationCanExecuteWithEvidence(item) || operationCanReviewExecution(item);
    const operationHasDisplayValue = (value) => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value));
    const operationExecutionRateText = (value) => operationHasDisplayValue(value) ? `${Number(value).toFixed(0)}%` : '-';
    const buildOperationExecutionSummaryCards = (summary = {}, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        const numberText = (value) => operationHasDisplayValue(value) ? formatter.value(value) : '-';
        const moneyText = (value) => operationHasDisplayValue(value) ? formatter.money(value) : '-';
        const countHint = (label, value) => operationHasDisplayValue(value) ? `${label} ${value}` : `${label}数量未返回`;
        return [
            { label: '执行单', value: numberText(summary.total), hint: '建议转执行意图总数' },
            { label: '审批率', value: operationExecutionRateText(summary.approval_rate), hint: countHint('已审批', summary.approved) },
            { label: '执行率', value: operationExecutionRateText(summary.execution_rate), hint: countHint('已执行', summary.executed) },
            { label: '证据率', value: operationExecutionRateText(summary.evidence_rate), hint: countHint('证据齐备', summary.evidence_ready) },
            { label: '净收益', value: moneyText(summary.total_profit), hint: operationHasDisplayValue(summary.total_incremental_revenue) ? `增量收入 ${moneyText(summary.total_incremental_revenue)}` : '增量收入未返回' },
            { label: '平均 ROI', value: operationHasDisplayValue(summary.avg_roi) ? `${summary.avg_roi}%` : '-', hint: countHint('百分比样本', summary.roi_percent_ready) },
            { label: '价格 Lift', value: moneyText(summary.avg_revenue_lift), hint: countHint('金额样本', summary.revenue_lift_ready) },
        ];
    };
    const operationExecutionBottleneckText = (summary = {}, helpers = {}) => {
        const bottleneck = summary?.bottleneck || {};
        if (!bottleneck.stage || !bottleneck.count) return '暂无明显瓶颈';
        const statusLabel = typeof helpers.statusLabel === 'function' ? helpers.statusLabel : (status => status || '-');
        return `${bottleneck.label || statusLabel(bottleneck.stage)} ${bottleneck.count} 单`;
    };
    const operationExecutionMoneyStatusText = (status) => ({
        profit_positive: '已验证赚钱',
        profit_negative: '已验证亏损',
        break_even: '收益持平',
        no_roi: '缺少 ROI 证据',
    }[String(status || '')] || '待判断');
    const operationExecutionMoneyStatusClass = (status) => ({
        profit_positive: 'border-green-100 bg-green-50 text-green-700',
        profit_negative: 'border-red-100 bg-red-50 text-red-700',
        break_even: 'border-blue-100 bg-blue-50 text-blue-700',
        no_roi: 'border-gray-100 bg-gray-50 text-gray-600',
    }[String(status || '')] || 'border-gray-100 bg-gray-50 text-gray-600');
    const operationExecutionSourceText = (item) => {
        const source = item?.recommendation?.source || '';
        const resolved = source && !source.endsWith('#0') ? source : (item?.recommendation?.source_module || '');
        if (String(resolved).toLowerCase() === 'manual') return '人工创建';
        return resolved || '来源未返回';
    };
    const operationExecutionActionText = (item, helpers = {}) => {
        const recommendation = item?.recommendation || {};
        const objectText = ({ price: '价格', inventory: '房态', campaign: '活动' }[recommendation.object_type] || recommendation.object_type || '动作');
        const strategyTypeLabel = typeof helpers.strategyTypeLabel === 'function' ? helpers.strategyTypeLabel : (type => type || '未知策略');
        return `${objectText} · ${strategyTypeLabel(recommendation.action_type)}`;
    };
    const operationExecutionReviewText = (item, helpers = {}) => {
        const review = item?.review || {};
        const statusLabel = typeof helpers.statusLabel === 'function' ? helpers.statusLabel : (status => status || '-');
        const label = statusLabel(review.status);
        return review.summary ? `${label} · ${review.summary}` : label;
    };
    const operationExecutionRoiText = (roi, formatters = {}) => {
        const formatter = operationFormatters(formatters);
        if (!roi || roi.status !== 'ready') return roi?.message || '待计算';
        if (roi.unit === 'amount') return `收入${formatter.money(roi.incremental_revenue || roi.value || 0)} / 利润${formatter.money(roi.profit)}`;
        return `${roi.value}% / 利润${formatter.money(roi.profit)}`;
    };
    const buildOperationExecutionTraceRows = (summary = {}) => {
        const total = Number(summary.total || 0);
        const approved = Number(summary.approved || 0);
        const executed = Number(summary.executed || 0);
        const evidenceReady = Number(summary.evidence_ready || 0);
        const roiReady = Number(summary.roi_ready || 0);
        return [
            {
                key: 'source',
                label: '建议来源',
                value: total ? `${total}条` : '待生成',
                className: total ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                detail: '来源可以是 AI策略、运营预警或人工创建，进入执行池前不视为已执行动作。',
            },
            {
                key: 'approval',
                label: '人工审批',
                value: total ? `${approved}/${total}` : '待审批',
                className: approved ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                detail: '涉及价格、房态、活动的动作必须先确认，驳回原因应保留在记录中。',
            },
            {
                key: 'evidence',
                label: '执行证据',
                value: executed ? `${evidenceReady}/${executed}` : '待执行',
                className: evidenceReady ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                detail: '执行后需记录平台、截图路径或操作说明；没有证据时不计算最终收益结论。',
            },
            {
                key: 'roi',
                label: 'ROI复盘',
                value: roiReady ? `${roiReady}个样本` : '待计算',
                className: roiReady ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                detail: '活动等投入动作计算 ROI 百分比；价格调整记录收入 lift，缺执行前后样本时显示待计算。',
            },
        ];
    };
    const buildOperationClosureSummaryBadge = (summary = {}) => {
        if (String(summary?.status || '') === 'blocked_by_p0_ota_gate') {
            return { text: 'P0未就绪', className: 'bg-red-50 text-red-700 border-red-100' };
        }
        const hasClosureStatus = [summary?.status, summary?.process_status, summary?.roi_status]
            .some(value => value !== null && value !== undefined && String(value).trim() !== '');
        if (!hasClosureStatus) {
            return { text: '闭环状态未返回', className: 'bg-gray-50 text-gray-600 border-gray-200' };
        }
        const processClosed = String(summary?.process_status || '') === 'closed';
        const roiClosed = String(summary?.roi_status || '') === 'closed';
        if (processClosed && roiClosed) {
            return { text: '过程与ROI已闭环', className: 'bg-emerald-50 text-emerald-700 border-emerald-100' };
        }
        if (processClosed) {
            return { text: '过程已闭环，ROI待补', className: 'bg-blue-50 text-blue-700 border-blue-100' };
        }
        return { text: '过程未闭环', className: 'bg-amber-50 text-amber-700 border-amber-100' };
    };
    const buildOperationClosureSummaryCards = (summary = {}) => {
        const displayCount = (value) => operationHasDisplayValue(value) ? Number(value) : '-';
        return [
            { label: '板块数', value: displayCount(summary.module_count), hint: '收益分析之后的业务板块' },
            { label: '过程闭环', value: displayCount(summary.process_closed_count), hint: '已形成复盘或执行结果判断' },
            { label: 'ROI就绪', value: displayCount(summary.roi_ready_module_count), hint: '具备收入/成本或增量收益证据' },
            { label: '未过程闭环', value: displayCount(summary.not_process_closed_count), hint: '仍停在建议/审批/执行/证据阶段' },
        ];
    };
    const operationClosureGapText = (module = {}) => {
        const gaps = Array.isArray(module?.data_gaps) ? module.data_gaps : [];
        if (!gaps.length) return '暂无显式缺口';
        const first = gaps[0] || {};
        return first.message || first.code || '存在未说明缺口';
    };
    const openingRiskTextFallback = (risk) => ({ high: '高风险', medium: '中风险', low: '低风险' }[risk] || '待评估');
    const openingRiskTextClassFallback = (risk) => ({ high: 'text-red-600', medium: 'text-yellow-600', low: 'text-green-600' }[risk] || 'text-gray-500');
    const nullableOpeningOverviewNumber = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };
    const safeOpeningOverviewNumber = (value) => {
        const number = nullableOpeningOverviewNumber(value);
        return number === null ? 0 : number;
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
        const truthContext = data.truth_context && typeof data.truth_context === 'object' ? data.truth_context : {};
        const metricTruth = metrics.metric_truth && typeof metrics.metric_truth === 'object' ? metrics.metric_truth : {};
        const truthFor = (metricKey, value) => {
            const observed = metricKey === 'risk_level'
                ? String(value || '').trim() !== ''
                : nullableOpeningOverviewNumber(value) !== null;
            return metricTruth[metricKey] || {
                ...truthContext,
                metric_key: metricKey,
                calculation_status: observed ? 'calculated' : 'missing',
                value_observed: observed,
            };
        };
        const daysLeftValue = nullableOpeningOverviewNumber(metrics.days_left);
        const completionRateValue = nullableOpeningOverviewNumber(metrics.completion_rate);
        const coreCompletionRateValue = nullableOpeningOverviewNumber(metrics.core_completion_rate);
        const aiRateValue = nullableOpeningOverviewNumber(metrics.ai_penetration_rate);
        const completedTasks = nullableOpeningOverviewNumber(metrics.completed_tasks);
        const totalTasks = nullableOpeningOverviewNumber(metrics.total_tasks);
        const coreCompletedTasks = nullableOpeningOverviewNumber(metrics.core_completed_tasks);
        const coreTasks = nullableOpeningOverviewNumber(metrics.core_tasks);
        const daysLeft = daysLeftValue;
        const completionRate = completionRateValue === null ? null : clampOpeningOverviewPercent(completionRateValue);
        const coreCompletionRate = coreCompletionRateValue === null ? null : clampOpeningOverviewPercent(coreCompletionRateValue);
        const aiRate = aiRateValue === null ? null : clampOpeningOverviewPercent(aiRateValue);
        return [
            {
                metricKey: 'days_left',
                label: '开业倒计时',
                value: daysLeftValue === null ? '—' : `${daysLeft}天`,
                hint: project.opening_date ? `计划开业 ${project.opening_date}` : '未设置开业日期',
                icon: 'fas fa-calendar-day',
                iconClass: daysLeftValue === null ? 'bg-gray-50 text-gray-500' : (daysLeft < 0 ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-blue-600'),
                valueClass: daysLeftValue === null ? 'text-gray-500' : (daysLeft < 0 ? 'text-red-600' : 'text-gray-900'),
            },
            {
                metricKey: 'overall_score',
                label: '总评分',
                value: project.overall_score ?? '—',
                hint: '规则引擎评分 / 100',
                icon: 'fas fa-chart-line',
                iconClass: 'bg-slate-50 text-slate-600',
            },
            {
                metricKey: 'risk_level',
                label: '风险等级',
                value: openingRiskText(project.risk_level),
                hint: '高风险与逾期自动识别',
                icon: 'fas fa-exclamation-triangle',
                iconClass: project.risk_level === 'high' ? 'bg-red-50 text-red-600' : (project.risk_level === 'medium' ? 'bg-yellow-50 text-yellow-600' : (project.risk_level === 'low' ? 'bg-green-50 text-green-600' : 'bg-gray-50 text-gray-500')),
                valueClass: openingRiskTextClass(project.risk_level),
            },
            {
                metricKey: 'completion_rate',
                label: '检查项完成率',
                value: completionRateValue === null ? '—' : `${completionRate}%`,
                hint: totalTasks === null ? '检查项数量未返回' : (totalTasks > 0 ? `已完成 ${completedTasks ?? '-'} 项，共 ${totalTasks} 项` : '暂无检查项'),
                icon: 'fas fa-tasks',
                iconClass: 'bg-blue-50 text-blue-600',
                progress: completionRate,
                progressClass: 'bg-blue-600',
                countLabel: totalTasks === null ? '数量未返回' : (totalTasks > 0 ? `${completedTasks ?? '-'}/${totalTasks} 项` : '暂无检查项'),
            },
            {
                metricKey: 'core_completion_rate',
                label: '核心完成率',
                value: coreCompletionRateValue === null ? '—' : `${coreCompletionRate}%`,
                hint: coreTasks === null ? '核心检查项数量未返回' : (coreTasks > 0 ? `核心项 ${coreCompletedTasks ?? '-'}/${coreTasks} 项` : '暂无核心检查项'),
                icon: 'fas fa-clipboard-check',
                iconClass: 'bg-green-50 text-green-600',
                progress: coreCompletionRate,
                progressClass: 'bg-green-600',
                countLabel: coreTasks === null ? '数量未返回' : (coreTasks > 0 ? `${coreCompletedTasks ?? '-'}/${coreTasks} 项` : '暂无核心项'),
            },
            {
                metricKey: 'high_risk_count',
                label: '高风险事项',
                value: metrics.high_risk_count ?? '—',
                hint: '核心阻断优先处理',
                icon: 'fas fa-fire',
                iconClass: 'bg-red-50 text-red-600',
                valueClass: nullableOpeningOverviewNumber(metrics.high_risk_count) === null ? 'text-gray-500' : (Number(metrics.high_risk_count) > 0 ? 'text-red-600' : 'text-gray-900'),
            },
            {
                metricKey: 'overdue_count',
                label: '逾期事项',
                value: metrics.overdue_count ?? '—',
                hint: '未完成且超过截止时间',
                icon: 'fas fa-clock',
                iconClass: 'bg-yellow-50 text-yellow-600',
                valueClass: nullableOpeningOverviewNumber(metrics.overdue_count) === null ? 'text-gray-500' : (Number(metrics.overdue_count) > 0 ? 'text-yellow-600' : 'text-gray-900'),
            },
            {
                metricKey: 'ai_penetration_rate',
                label: 'AI建议推进率',
                value: aiRateValue === null ? '—' : `${aiRate}%`,
                hint: '带AI建议事项平均进度',
                icon: 'fas fa-robot',
                iconClass: 'bg-blue-50 text-blue-600',
                progress: aiRate,
                progressClass: 'bg-blue-600',
                countLabel: totalTasks === null
                    ? '数量未返回'
                    : (totalTasks > 0 ? `${nullableOpeningOverviewNumber(metrics.ai_covered_tasks) ?? '-'}/${totalTasks} 项带AI建议` : '暂无检查项'),
            },
        ].map(card => ({
            ...card,
            truth: truthFor(
                card.metricKey,
                card.metricKey === 'overall_score'
                    ? project.overall_score
                    : (card.metricKey === 'risk_level' ? project.risk_level : metrics[card.metricKey])
            ),
        }));
    };
    const buildOpeningCategoryProgressCards = (categoryProgress = []) => {
        const list = Array.isArray(categoryProgress) ? categoryProgress : [];
        return list.map((item) => {
            const totalValue = nullableOpeningOverviewNumber(item.total);
            const doneValue = nullableOpeningOverviewNumber(item.done);
            const progressValue = nullableOpeningOverviewNumber(item.completion_rate);
            const total = totalValue ?? 0;
            const done = doneValue ?? 0;
            const progress = progressValue === null ? null : clampOpeningOverviewPercent(progressValue);
            const truth = item?.truth && typeof item.truth === 'object' ? item.truth : {
                status: 'unverified',
                status_label: '未验证',
                metric_scope: 'opening_project',
                scope_label: '开业准备项目口径，不代表OTA已上线或全酒店经营实绩',
                failure_reason: '分类指标真值证据未返回',
            };
            if (totalValue === null) {
                return {
                    category: item.category || '未分类',
                    progress,
                    countLabel: '数量未返回',
                    progressHint: '进度未返回',
                    status: '数据未返回',
                    statusClass: 'bg-gray-100 text-gray-600',
                    progressClass: 'bg-gray-300',
                    truth,
                };
            }
            if (total <= 0) {
                return {
                    category: item.category || '未分类',
                    progress,
                    countLabel: '暂无检查项',
                    progressHint: '待生成',
                    status: '待生成',
                    statusClass: 'bg-gray-100 text-gray-600',
                    progressClass: 'bg-gray-300',
                    truth,
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
                    truth,
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
                    truth,
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
                truth,
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
    const openingTaskDaysUntil = (deadline, now = new Date()) => {
        const dateText = String(deadline || '').slice(0, 10);
        if (!dateText) return null;
        const dueDate = new Date(`${dateText}T00:00:00`);
        if (Number.isNaN(dueDate.getTime())) return null;
        const today = new Date(now);
        today.setHours(0, 0, 0, 0);
        return Math.ceil((dueDate.getTime() - today.getTime()) / (24 * 60 * 60 * 1000));
    };
    const openingTaskIsDone = (task) => (task?.status || 'todo') === 'done';
    const openingTaskIsOverdue = (task, now = new Date()) => {
        if (!task || openingTaskIsDone(task)) return false;
        if (Number(task.is_overdue) === 1) return true;
        const days = openingTaskDaysUntil(task.deadline, now);
        return days !== null && days < 0;
    };
    const openingTaskIsDueSoon = (task, now = new Date()) => {
        if (!task || openingTaskIsDone(task)) return false;
        const days = openingTaskDaysUntil(task.deadline, now);
        return days !== null && days >= 0 && days <= 7;
    };
    const openingTaskHasOwner = (task) => String(task?.owner_name || '').trim().length > 0;
    const clampOpeningTaskProgress = (value) => {
        const number = Number(value);
        if (!Number.isFinite(number)) return 0;
        return Math.max(0, Math.min(100, Math.round(number)));
    };
    const openingTaskProgressPercent = (task) => clampOpeningTaskProgress(task?.progress_percent ?? (task?.status === 'done' ? 100 : 0));
    const openingTaskDueLabel = (task, now = new Date()) => {
        if (!task?.deadline) return '未设截止';
        if (openingTaskIsDone(task)) return '已完成';
        const days = openingTaskDaysUntil(task.deadline, now);
        if (days === null) return '截止时间待确认';
        if (days < 0) return `逾期 ${Math.abs(days)} 天`;
        if (days === 0) return '今日截止';
        return `${days} 天后截止`;
    };
    const openingTaskDueClass = (task, now = new Date()) => {
        if (openingTaskIsOverdue(task, now)) return 'text-red-600';
        if (openingTaskIsDueSoon(task, now)) return 'text-yellow-700';
        if (openingTaskIsDone(task)) return 'text-green-600';
        return 'text-gray-500';
    };
    const openingTaskProgressStage = (task) => {
        if ((task?.status || '') === 'blocked') return '受阻';
        const progress = openingTaskProgressPercent(task);
        if (progress >= 100) return '已完成';
        if (progress >= 50) return '推进过半';
        if (progress > 0) return '已启动';
        return '未开始';
    };
    const openingTaskProgressTextClass = (task) => {
        if ((task?.status || '') === 'blocked') return 'text-yellow-700';
        const progress = openingTaskProgressPercent(task);
        if (progress >= 100) return 'text-green-600';
        if (progress >= 50) return 'text-blue-600';
        if (progress > 0) return 'text-yellow-700';
        return 'text-gray-600';
    };
    const syncOpeningTaskProgressByStatus = (task) => {
        if (!task) return;
        const progress = openingTaskProgressPercent(task);
        if (task.status === 'done') {
            task.progress_percent = 100;
        } else if (task.status === 'todo') {
            task.progress_percent = 0;
        } else {
            task.progress_percent = progress;
        }
    };
    const syncOpeningTaskStatusByProgress = (task) => {
        if (!task) return;
        task.progress_percent = openingTaskProgressPercent(task);
        if (task.progress_percent >= 100) {
            task.status = 'done';
        } else if (task.progress_percent > 0 && (!task.status || task.status === 'todo')) {
            task.status = 'doing';
        } else if (task.progress_percent === 0 && task.status !== 'blocked') {
            task.status = 'todo';
        }
    };
    const buildOpeningTaskUpdatePayload = (task = {}) => ({
        owner_name: task.owner_name || '',
        collaborator_name: task.collaborator_name || '',
        deadline: task.deadline || '',
        status: task.status || 'todo',
        progress_percent: openingTaskProgressPercent(task),
        remark: task.remark || '',
    });
    const snapshotOpeningTaskForRollback = (task = {}) => ({
        owner_name: task.owner_name,
        collaborator_name: task.collaborator_name,
        deadline: task.deadline,
        status: task.status,
        progress_percent: task.progress_percent,
        remark: task.remark,
    });
    const openingTaskPatchHasChanges = (patch = {}) => (
        Object.prototype.hasOwnProperty.call(patch, 'status')
        || Object.prototype.hasOwnProperty.call(patch, 'progress_percent')
    );
    const applyOpeningTaskPatch = (task, patch = {}) => {
        if (!task) return task;
        if (Object.prototype.hasOwnProperty.call(patch, 'status')) {
            task.status = patch.status;
            syncOpeningTaskProgressByStatus(task);
        }
        if (Object.prototype.hasOwnProperty.call(patch, 'progress_percent')) {
            task.progress_percent = clampOpeningTaskProgress(patch.progress_percent);
            syncOpeningTaskStatusByProgress(task);
        }
        return task;
    };
    const openingRiskText = (risk) => ({ high: '高风险', medium: '中风险', low: '低风险' }[risk] || '待评估');
    const openingRiskTextClass = (risk) => ({ high: 'text-red-600', medium: 'text-yellow-600', low: 'text-green-600' }[risk] || 'text-gray-500');
    const openingRiskClass = (risk) => ({
        high: 'bg-red-50 text-red-700 border border-red-100',
        medium: 'bg-yellow-50 text-yellow-700 border border-yellow-100',
        low: 'bg-green-50 text-green-700 border border-green-100',
    }[risk] || 'bg-gray-50 text-gray-600 border border-gray-200');
    const buildOpeningTaskStats = (tasks = [], now = new Date()) => {
        const rows = Array.isArray(tasks) ? tasks : [];
        const count = (predicate) => rows.filter(predicate).length;
        const total = rows.length;
        const done = count(task => task.status === 'done');
        const doing = count(task => task.status === 'doing');
        const todo = count(task => !task.status || task.status === 'todo');
        const blocked = count(task => task.status === 'blocked');
        const highRisk = count(task => task.risk_level === 'high');
        const overdue = count(task => openingTaskIsOverdue(task, now));
        const dueSoon = count(task => openingTaskIsDueSoon(task, now));
        const core = count(task => Number(task.is_core) === 1);
        const noOwner = count(task => !openingTaskHasOwner(task));
        const progressSum = rows.reduce((sum, task) => sum + openingTaskProgressPercent(task), 0);
        const averageProgress = total > 0 ? Math.round(progressSum / total) : 0;
        const progressEmpty = count(task => openingTaskProgressPercent(task) <= 0);
        const progressLow = count(task => {
            const progress = openingTaskProgressPercent(task);
            return progress > 0 && progress < 50;
        });
        const progressHigh = count(task => {
            const progress = openingTaskProgressPercent(task);
            return progress >= 50 && progress < 100;
        });
        const progressDone = count(task => openingTaskProgressPercent(task) >= 100);
        const completionRate = total > 0 ? Math.round((done / total) * 100) : 0;
        return { total, done, doing, todo, blocked, highRisk, overdue, dueSoon, core, noOwner, completionRate, averageProgress, progressEmpty, progressLow, progressHigh, progressDone };
    };
    const matchesOpeningAttention = (task, attention, now = new Date()) => {
        if (!attention) return true;
        if (attention === 'overdue') return openingTaskIsOverdue(task, now);
        if (attention === 'dueSoon') return openingTaskIsDueSoon(task, now);
        if (attention === 'high') return task?.risk_level === 'high';
        if (attention === 'blocked') return task?.status === 'blocked';
        if (attention === 'noOwner') return !openingTaskHasOwner(task);
        if (attention === 'core') return Number(task?.is_core) === 1;
        return true;
    };
    const filterOpeningTasks = (tasks = [], filter = {}, now = new Date()) => (
        (Array.isArray(tasks) ? tasks : []).filter(task => {
            if (filter.category && task.category !== filter.category) return false;
            if (filter.status && task.status !== filter.status) return false;
            if (filter.risk && task.risk_level !== filter.risk) return false;
            if (!matchesOpeningAttention(task, filter.attention, now)) return false;
            return true;
        })
    );
    const normalizeOpeningTaskId = (taskOrId) => String(typeof taskOrId === 'object' ? taskOrId?.id : taskOrId || '');
    const selectOpeningTasks = (tasks = [], selectedTaskIds = []) => {
        const selectedIds = new Set((Array.isArray(selectedTaskIds) ? selectedTaskIds : []).map(normalizeOpeningTaskId).filter(Boolean));
        return (Array.isArray(tasks) ? tasks : []).filter(task => selectedIds.has(normalizeOpeningTaskId(task)));
    };
    const areAllFilteredOpeningTasksSelected = (filteredTasks = [], selectedTaskIds = []) => {
        const visibleIds = (Array.isArray(filteredTasks) ? filteredTasks : []).map(normalizeOpeningTaskId).filter(Boolean);
        if (!visibleIds.length) return false;
        const selectedIds = new Set((Array.isArray(selectedTaskIds) ? selectedTaskIds : []).map(normalizeOpeningTaskId).filter(Boolean));
        return visibleIds.every(id => selectedIds.has(id));
    };
    const pruneOpeningTaskIds = (tasks = [], selectedTaskIds = []) => {
        const validIds = new Set((Array.isArray(tasks) ? tasks : []).map(normalizeOpeningTaskId).filter(Boolean));
        return (Array.isArray(selectedTaskIds) ? selectedTaskIds : [])
            .map(normalizeOpeningTaskId)
            .filter(id => validIds.has(id));
    };
    const mergeOpeningTaskSelection = (filteredTasks = [], selectedTaskIds = [], checked = true) => {
        const visibleIds = (Array.isArray(filteredTasks) ? filteredTasks : []).map(normalizeOpeningTaskId).filter(Boolean);
        const selectedIds = new Set((Array.isArray(selectedTaskIds) ? selectedTaskIds : []).map(normalizeOpeningTaskId).filter(Boolean));
        visibleIds.forEach(id => {
            if (checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
        });
        return Array.from(selectedIds);
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
        const allTaskOutputs = taskRows
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
            .sort((a, b) => b.priorityScore - a.priorityScore);
        const taskOutputs = allTaskOutputs.slice(0, 6);
        const total = Math.max(0, Number(stats.total || 0));
        const aiCovered = allTaskOutputs.length;
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
        operationCanApproveExecution,
        operationCanExecuteWithEvidence,
        operationCanReviewExecution,
        operationExecutionActionAvailable,
        operationExecutionRateText,
        buildOperationExecutionSummaryCards,
        operationExecutionBottleneckText,
        operationExecutionMoneyStatusText,
        operationExecutionMoneyStatusClass,
        operationExecutionSourceText,
        operationExecutionActionText,
        operationExecutionReviewText,
        operationExecutionRoiText,
        buildOperationExecutionTraceRows,
        buildOperationClosureSummaryBadge,
        buildOperationClosureSummaryCards,
        operationClosureGapText,
        buildOpeningOverviewCards,
        buildOpeningCategoryProgressCards,
        buildOpeningPositioningImpact,
        buildOpeningTaskProgressCards,
        buildOpeningTaskProgressStages,
        buildOpeningStatusFilterChips,
        buildOpeningAttentionFilterChips,
        openingTaskDaysUntil,
        openingTaskIsDone,
        openingTaskIsOverdue,
        openingTaskIsDueSoon,
        openingTaskHasOwner,
        clampOpeningTaskProgress,
        openingTaskProgressPercent,
        openingTaskDueLabel,
        openingTaskDueClass,
        openingTaskProgressStage,
        openingTaskProgressTextClass,
        syncOpeningTaskProgressByStatus,
        syncOpeningTaskStatusByProgress,
        buildOpeningTaskUpdatePayload,
        snapshotOpeningTaskForRollback,
        openingTaskPatchHasChanges,
        applyOpeningTaskPatch,
        openingRiskText,
        openingRiskTextClass,
        openingRiskClass,
        buildOpeningTaskStats,
        matchesOpeningAttention,
        filterOpeningTasks,
        normalizeOpeningTaskId,
        selectOpeningTasks,
        areAllFilteredOpeningTasksSelected,
        pruneOpeningTaskIds,
        mergeOpeningTaskSelection,
        buildOpeningAiOutputResult,
        openingCategories,
        openingStatusOptions,
        openingProgressQuickValues,
        buildOpeningProjectFormDefaults,
        normalizeOpeningProjectFormForSubmit,
        buildOpeningProjectFormFromProject,
    };
})();
