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
    const operationCanExecuteWithEvidence = (item) => {
        const status = item?.execution?.status || '';
        const canStartExecution = ['pending_execute', 'executing'].includes(status);
        const canSupplementManualEvidence = status === 'executed'
            && item?.recommendation?.object_type !== 'price'
            && item?.next_action?.key === 'record_evidence';
        return (canStartExecution || canSupplementManualEvidence) && Number(item?.execution?.task_id || 0) > 0;
    };
    const operationCanRecordNodeCheck = (item) => ['pending_execute', 'executing', 'executed'].includes(item?.execution?.status || '')
        && Number(item?.execution?.task_id || 0) > 0;
    const operationCanReviewExecution = (item) => item?.execution?.status === 'executed' && item?.review?.is_available !== false && !['success', 'near_success', 'failed'].includes(item?.review?.status || '') && Number(item?.execution?.task_id || 0) > 0;
    const operationCanReconcileExecution = (item) => item?.execution?.status === 'executed'
        && item?.review?.is_available === true
        && item?.evidence_truth?.source_verified !== true
        && item?.recommendation?.source_module === 'ota_diagnosis_saved'
        && !['success', 'near_success', 'failed'].includes(item?.review?.status || '')
        && Number(item?.execution?.task_id || 0) > 0;
    const operationExecutionActionAvailable = (item) => operationCanApproveExecution(item)
        || operationCanExecuteWithEvidence(item)
        || operationCanRecordNodeCheck(item)
        || operationCanReconcileExecution(item)
        || operationCanReviewExecution(item);
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
        const sourceKey = String(resolved).toLowerCase();
        if (sourceKey === 'manual') return '人工创建';
        if (sourceKey.startsWith('ota_diagnosis_saved')) return 'OTA诊断行动';
        if (sourceKey.startsWith('daily_workbench_patrol')) return '巡检补证任务';
        if (sourceKey.startsWith('ota_diagnosis')) return '历史OTA诊断行动';
        if (sourceKey.startsWith('temporal_forecast_recommendation')) return '预测运营建议';
        return resolved || '来源未返回';
    };
    const operationExecutionActionText = (item, helpers = {}) => {
        const recommendation = item?.recommendation || {};
        const actionType = String(recommendation.action_type || '');
        const legacyOperationCheckTypes = [
            'booking_conversion_optimization',
            'listing_conversion_optimization',
            'service_quality_improvement',
        ];
        const objectType = recommendation.object_type === 'campaign' && legacyOperationCheckTypes.includes(actionType)
            ? 'operation_checklist'
            : recommendation.object_type;
        const objectText = ({ price: '价格', inventory: '房态', campaign: '活动', data_collection: '证据采集', operation_checklist: '运营核查' }[objectType] || objectType || '动作');
        const strategyTypeLabel = typeof helpers.strategyTypeLabel === 'function' ? helpers.strategyTypeLabel : (type => type || '未知策略');
        const actionText = ({
            complete_public_page_evidence: '补齐公开页证据',
            review_public_page_evidence: '复核公开页证据',
            manual_forecast_review: '预测复核',
            booking_conversion_optimization: '下单转化核查',
            listing_conversion_optimization: '列表转化核查',
            service_quality_improvement: '服务质量核查',
            advertising_optimization: '广告优化',
            ota_operation_follow_up: 'OTA运营跟进',
        }[actionType] || strategyTypeLabel(actionType));
        return `${objectText} · ${actionText}`;
    };
    const operationExecutionReviewText = (item, helpers = {}) => {
        const review = item?.review || {};
        const statusLabel = typeof helpers.statusLabel === 'function' ? helpers.statusLabel : (status => status || '-');
        const label = statusLabel(review.status);
        return review.summary ? `${label} · ${review.summary}` : label;
    };
    const operationRevenueNodeDialogFields = [
        { name: 'operating_period', label: '经营周期', type: 'select', required: true, value: '', options: [{ value: 'weekday', label: '周内' }, { value: 'weekend', label: '周末' }, { value: 'holiday', label: '节假日' }, { value: 'special_event', label: '特殊事件' }] },
        { name: 'special_event', label: '特殊事件（无则留空）', value: '', placeholder: '如考试、会展' },
        { name: 'source_scope', label: '数据范围', type: 'select', required: true, value: '', options: [{ value: 'pms_ota_cross_check', label: 'PMS + OTA 交叉核对' }, { value: 'pms', label: '仅 PMS' }, { value: 'ctrip', label: '仅携程' }, { value: 'meituan', label: '仅美团' }, { value: 'manual_other', label: '人工盘点 / 其他' }] },
        { name: 'room_status_alignment', label: 'PMS 与 OTA 房态', type: 'select', required: true, value: '', options: [{ value: 'operator_confirmed', label: '人工确认一致' }, { value: 'mismatch', label: '不一致' }, { value: 'unverified', label: '未核验' }] },
        { name: 'data_quality_status', label: '节点数据质量', type: 'select', required: true, value: '', options: [{ value: 'manual_confirmed', label: '人工确认' }, { value: 'unverified', label: '未验证' }, { value: 'mismatch', label: '来源不一致' }] },
        { name: 'metric_definition', label: '指标口径', type: 'textarea', required: true, value: '', placeholder: '统计时间、分子、分母及取消/维修房处理' },
        { name: 'comparison_basis', label: '同节点比较基准', type: 'textarea', required: true, value: '', placeholder: '例如最近 4 个周末 16:00，同房型与同渠道范围' },
        { name: 'metric_snapshot', label: '五率 / ADR / RevPAR / 流量快照（缺失留空）', type: 'textarea', value: '' },
        { name: 'progress_status', label: '当前进度', type: 'select', required: true, value: '', options: [{ value: 'normal', label: '正常' }, { value: 'too_fast', label: '过快' }, { value: 'too_slow', label: '过慢' }, { value: 'insufficient_evidence', label: '证据不足' }] },
        { name: 'judgment_basis', label: '判断依据', type: 'textarea', required: true, value: '' },
        { name: 'primary_risk', label: '主要风险（未知可留空）', type: 'textarea', value: '' },
        { name: 'success_criteria', label: '成功标准', type: 'textarea', required: true, value: '' },
        { name: 'stop_condition', label: '停止条件', type: 'textarea', required: true, value: '' },
    ];
    const operationRevenueNodeFieldsForItem = (item = {}) => {
        const node = item?.evidence_summary?.node_record || {};
        return operationRevenueNodeDialogFields.map(field => ({
            ...field,
            options: Array.isArray(field.options) ? field.options.map(option => ({ ...option })) : field.options,
            value: node.status === 'available' ? String(node[field.name] || '') : String(field.value || ''),
        }));
    };
    const buildOperationRevenueNodeRecord = (form = {}, recordedAt = '', identity = {}) => {
        const requiredText = (field, label) => {
            const value = String(form[field] || '').trim();
            if (!value) throw new Error(`请填写${label}`);
            return value;
        };
        const systemHotelId = Number(identity.system_hotel_id || 0);
        if (!Number.isInteger(systemHotelId) || systemHotelId <= 0) throw new Error('节点检查缺少酒店身份');
        const businessDate = String(identity.business_date || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) throw new Error('节点检查缺少业务日期');
        return {
            contract_version: 'operation_revenue_node.v2',
            system_hotel_id: String(systemHotelId),
            business_date: businessDate,
            recorded_at: String(recordedAt || '').trim(),
            operating_period: requiredText('operating_period', '经营周期'),
            special_event: String(form.special_event || '').trim(),
            source_scope: requiredText('source_scope', '数据范围'),
            room_status_alignment: requiredText('room_status_alignment', 'PMS与OTA房态核对结果'),
            data_quality_status: requiredText('data_quality_status', '节点数据质量'),
            metric_definition: requiredText('metric_definition', '指标口径'),
            comparison_basis: requiredText('comparison_basis', '同节点比较基准'),
            metric_snapshot: String(form.metric_snapshot || '').trim(),
            progress_status: requiredText('progress_status', '当前进度判断'),
            judgment_basis: requiredText('judgment_basis', '判断依据'),
            primary_risk: String(form.primary_risk || '').trim(),
            success_criteria: requiredText('success_criteria', '成功标准'),
            stop_condition: requiredText('stop_condition', '停止条件'),
        };
    };
    const operationExecutionNodeRecordText = (item = {}) => {
        const node = item?.evidence_summary?.node_record || {};
        if (node.status !== 'available') return '节点检查未记录';
        const period = ({ weekday: '周内', weekend: '周末', holiday: '节假日', special_event: '特殊事件' }[node.operating_period] || '周期未回读');
        const alignment = ({ operator_confirmed: '房态人工确认一致', mismatch: '房态不一致', unverified: '房态未核验' }[node.room_status_alignment] || '房态状态未回读');
        const progress = ({ normal: '进度正常', too_fast: '进度过快', too_slow: '进度过慢', insufficient_evidence: '证据不足' }[node.progress_status] || '进度未判断');
        return `${period} · ${alignment} · ${progress}`;
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

    const createOperationWorkflowController = (dependencies = {}) => {
        const {
            actionForm, aiDailyFactGateLoading, aiDailyFactGateState, aiDailyReport,
            aiDailyReportForm, aiDailyReportGenerationTask, aiDailyReportGenerationTaskPolling, aiDailyReportModelIsLimited,
            aiDailyReportTaskPositiveInteger, apiRequest, buildOperationRevenueNodeRecord, currentPage,
            ensureOperationStaticReady, ensureRevenueAiStaticReady, filterReportHotel, formatDate,
            homeOperatingScheduleError, homeOperatingScheduleFlow, homeOperatingScheduleLastReadAt, homeOperatingScheduleLoading,
            homeOperatingScheduleScopeHotelId, isAiDailyReportGenerationRequestCurrent, isOperationAlertTaskLoading, nextAiDailyReportGenerationRequestSeq,
            nextTick, normalizeAiDailyReportGenerationTask, normalizeOperationHotelSelection, openOnlineDataEntryTab,
            openWorkflowFormDialog, operatingMemories, operatingMemoryError, operatingMemoryLoading,
            operatingMemorySavingTaskId, operationActions, operationAlertTaskLoadingIds, operationAlerts,
            operationCanRecordNodeCheck, operationCanSaveOperatingMemory, operationClosureOverview, operationEffectValidation,
            operationError, operationErrorMessage, operationEvidenceForm, operationEvidenceModalItem,
            operationEvidenceModalOpen, operationExecutionFlow, operationExecutionItems, operationExecutionStageFilter,
            operationFilters, operationFullData, operationLoading, operationParams,
            operationRevenueNodeFieldsForItem, operationReviewForm, operationReviewModalItem, operationReviewModalOpen,
            operationRootCause, operationStrategyAmountRequired, operationStrategyDiscountRequired, operationStrategyResult,
            operationYesterday, pollAiDailyReportGenerationTask, ref, resolveAiDailyReportGenerationOutcome,
            revenueAiDailyReportActionExecutionReady, revenueAiExecutionFocus, revenueAiOverview, showToast,
            strategyForm, today, user, manualNotificationForm,
            manualNotificationHasUnsavedChanges, manualNotificationTestAllowed, manualNotificationLoading, loadManualNotificationHistory,
            loadManualNotificationDispatchHistory, manualNotificationHistory, applyManualNotificationRecord, manualNotificationWorkspaceTab,
            manualNotificationError, manualNotificationDispatchCanRetry,
        } = dependencies;

const testManualNotification = async (item) => {
        if (Number(item?.id || 0) === Number(manualNotificationForm.value?.id || 0)
            && manualNotificationHasUnsavedChanges.value
        ) {
            showToast('计划有未保存更改，请先保存再测试。', 'warning');
            return;
        }
        if (!manualNotificationTestAllowed(item)) {
            showToast('请选择当前酒店有权使用且已启用的目标机器人。', 'warning');
            return;
        }
        const targetRobotId = Number(item?.target_robot_id || item?.test_robot_id || 0);
        const targetRobotName = String(item?.target_robot_name || item?.test_robot_name || '').trim();
        if (!window.confirm(`将向“${targetRobotName}”发送一次真实测试消息；测试成功后，未改动的已启用计划会进入定时调度。确认继续吗？`)) {
            return;
        }
        manualNotificationLoading.value.testId = Number(item.id || 0);
        manualNotificationError.value = '';
        try {
            const idempotencyKey = typeof crypto?.randomUUID === 'function'
                ? `manual-test:${crypto.randomUUID()}`
                : `manual-test:${Date.now()}:${Math.random().toString(16).slice(2)}`;
            const res = await apiRequest(`/manual-notifications/${encodeURIComponent(item.id)}/test-push`, {
                method: 'POST',
                body: JSON.stringify({
                    hotel_id: Number(item.hotel_id),
                    confirmed: true,
                    target_robot_id: targetRobotId,
                    target_robot_name: targetRobotName,
                    idempotency_key: idempotencyKey,
                }),
            });
            if (res.code !== 200) throw new Error(res.message || '测试推送失败');
            const status = String(res.data?.delivery_status || '');
            showToast(
                status === 'sent' ? `测试消息已送达“${targetRobotName}”。` : (res.data?.message || '测试消息未送达。'),
                status === 'sent' ? 'success' : 'warning'
            );
            if (Number(manualNotificationForm.value.id || 0) === Number(item.id || 0)) {
                manualNotificationForm.value.schedule_status = res.data?.schedule_status
                    || manualNotificationForm.value.schedule_status;
            }
            await loadManualNotificationHistory();
            await loadManualNotificationDispatchHistory();
            const refreshed = manualNotificationHistory.value.list.find(
                record => Number(record.id || 0) === Number(item.id || 0)
            );
            if (refreshed && Number(manualNotificationForm.value.id || 0) === Number(item.id || 0)) {
                applyManualNotificationRecord(refreshed);
            }
            if (status === 'sent') {
                manualNotificationWorkspaceTab.value = 'records';
            }
        } catch (error) {
            manualNotificationError.value = operationErrorMessage(error, '测试推送失败；正式群未触发。');
            await Promise.all([
                loadManualNotificationHistory(),
                loadManualNotificationDispatchHistory(),
            ]);
        } finally {
            manualNotificationLoading.value.testId = 0;
        }
    };
    const retryManualNotificationDispatch = async (item) => {
        if (!item || !manualNotificationDispatchCanRetry(item)) return;
        const status = String(item.status || '').toLowerCase();
        const outcomeUnknown = status === 'outcome_unknown';
        const modeLabel = String(item.delivery_mode || '') === 'formal' ? '正式群' : '测试群';
        const confirmation = outcomeUnknown
            ? `这条${modeLabel}消息的送达结果不明确，可能已经到群。再次发送可能产生重复消息；仅在你已确认接受重复风险时继续。确认重试吗？`
            : `仅重试这条明确失败的${modeLabel}发送；已送达消息不会重复发送。确认继续吗？`;
        if (!window.confirm(confirmation)) {
            return;
        }
        manualNotificationLoading.value.testId = Number(item.notification_id || 0);
        manualNotificationError.value = '';
        try {
            const res = await apiRequest(`/manual-notifications/dispatches/${encodeURIComponent(item.id)}/retry`, {
                method: 'POST',
                body: JSON.stringify({
                    hotel_id: Number(item.hotel_id || manualNotificationForm.value.hotel_id || 0),
                    confirmed: true,
                }),
            });
            if (res.code !== 200) throw new Error(res.message || '失败发送重试未送达');
            showToast(
                `${outcomeUnknown ? '风险确认后的重试' : '失败发送重试'}已送达“${item.robot_name || '目标机器人'}”。`,
                'success'
            );
        } catch (error) {
            manualNotificationError.value = operationErrorMessage(
                error,
                outcomeUnknown
                    ? '风险确认后的重试失败或结果仍不明确。'
                    : '明确失败消息重试未送达。'
            );
        } finally {
            manualNotificationLoading.value.testId = 0;
            await Promise.all([
                loadManualNotificationHistory(),
                loadManualNotificationDispatchHistory(),
            ]);
        }
    };

    const loadOperationFullData = async () => {
        await ensureOperationStaticReady();
        operationLoading.value.fullData = true;
        operationError.value.fullData = '';
        try {
            const query = operationParams();
            if (query === null) return;
            const res = await apiRequest(`/operation/full-data${query ? '?' + query : ''}`);
            if (res.code !== 200) throw new Error(res.message || '运营数据汇总加载失败');
            operationFullData.value = res.data || null;
        } catch (error) {
            operationError.value.fullData = operationErrorMessage(error, '运营数据汇总加载失败');
            showToast(operationError.value.fullData, 'error');
        } finally {
            operationLoading.value.fullData = false;
        }
    };

    const analyzeOperationRootCause = async () => {
        await ensureOperationStaticReady();
        operationLoading.value.rootCause = true;
        operationError.value.rootCause = '';
        try {
            const hotelId = normalizeOperationHotelSelection(operationFilters, {
                errorKey: 'rootCause',
                fallbackMessage: '请选择有权限的酒店',
            });
            if (hotelId === null) return;
            const res = await apiRequest('/operation/root-cause', {
                method: 'POST',
                body: JSON.stringify({
                    hotel_id: hotelId,
                    date: operationFilters.value.date,
                    problem_type: 'operation',
                }),
            });
            if (res.code !== 200) throw new Error(res.message || '可能影响因素分析失败');
            operationRootCause.value = res.data || null;
        } catch (error) {
            operationError.value.rootCause = operationErrorMessage(error, '可能影响因素分析失败');
            showToast(operationError.value.rootCause, 'error');
        } finally {
            operationLoading.value.rootCause = false;
        }
    };

    const loadOperationAlerts = async () => {
        await ensureOperationStaticReady();
        operationLoading.value.alerts = true;
        operationError.value.alerts = '';
        try {
            const params = new URLSearchParams();
            const hotelId = normalizeOperationHotelSelection(operationFilters, {
                requireHotel: true,
                errorKey: 'alerts',
                fallbackMessage: '请选择有权限的酒店',
            });
            if (hotelId === null) return;
            if (hotelId) params.append('hotel_id', hotelId);
            const res = await apiRequest(`/operation/alerts${params.toString() ? '?' + params.toString() : ''}`);
            if (res.code !== 200) throw new Error(res.message || '预警加载失败');
            operationAlerts.value = res.data || { list: [], unread_count: 0 };
        } catch (error) {
            operationError.value.alerts = operationErrorMessage(error, '预警加载失败');
            showToast(operationError.value.alerts, 'error');
        } finally {
            operationLoading.value.alerts = false;
        }
    };

    const markOperationAlertsRead = async (ids) => {
        const targetIds = (ids || []).filter(Boolean);
        if (!targetIds.length) {
            showToast('暂无需要标记的预警', 'info');
            return;
        }
        operationLoading.value.alerts = true;
        try {
            const res = await apiRequest('/operation/alerts/read', {
                method: 'POST',
                body: JSON.stringify({ ids: targetIds }),
            });
            if (res.code !== 200) throw new Error(res.message || '标记已读失败');
            operationAlerts.value = {
                ...operationAlerts.value,
                list: (operationAlerts.value.list || []).map(item => targetIds.includes(item.id) ? { ...item, status: 'read' } : item),
                unread_count: Math.max(0, (operationAlerts.value.unread_count || 0) - targetIds.length),
            };
            showToast('预警已标记为已读');
        } catch (error) {
            showToast(operationErrorMessage(error, '标记已读失败'), 'error');
        } finally {
            operationLoading.value.alerts = false;
        }
    };

    const openOperationAlertTask = async (alert) => {
        const intentId = Number(alert?.task_bridge?.intent_id || 0);
        if (!Number.isInteger(intentId) || intentId <= 0) {
            showToast('该预警尚未关联可跟踪任务', 'warning');
            return;
        }
        const hotelId = String(alert?.hotel_id || '').trim();
        if (hotelId) operationFilters.value.hotel_id = hotelId;
        revenueAiExecutionFocus.value = { intentId };
        currentPage.value = 'ops-track';
        await loadOperationActions({ focusIntentId: intentId });
        await nextTick();
        const row = document.querySelector(`[data-operation-execution-intent-id="${intentId}"]`);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            showToast('任务已关联，但执行池未返回对应记录，请刷新后重试', 'warning');
        }
    };

    const createOperationAlertTask = async (alert) => {
        const alertId = Number(alert?.id || 0);
        if (!Number.isInteger(alertId) || alertId <= 0) {
            showToast('预警尚未持久化，不能创建可跟踪任务', 'warning');
            return;
        }
        if (alert?.task_bridge?.linked) {
            await openOperationAlertTask(alert);
            return;
        }
        if (alert?.task_bridge?.can_convert !== true) {
            showToast(alert?.task_bridge?.unavailable_reason || '当前预警不能转任务', 'warning');
            return;
        }
        if (isOperationAlertTaskLoading(alert)) return;

        operationAlertTaskLoadingIds.value = [...operationAlertTaskLoadingIds.value, alertId];
        try {
            const res = await apiRequest(`/operation/alerts/${alertId}/execution-intent`, { method: 'POST' });
            if (res.code !== 200) throw new Error(res.message || '预警转任务失败');
            const intent = res.data?.execution_intent || {};
            const intentId = Number(intent.id || 0);
            if (!Number.isInteger(intentId) || intentId <= 0) {
                throw new Error('预警转任务结果缺少有效任务ID');
            }
            const wasUnread = alert.status !== 'read';
            operationAlerts.value = {
                ...operationAlerts.value,
                list: (operationAlerts.value.list || []).map(item => Number(item.id) === alertId ? {
                    ...item,
                    status: 'read',
                    task_bridge: {
                        can_convert: false,
                        linked: true,
                        intent_id: intentId,
                        intent_status: intent.status || 'pending_approval',
                        blocked_reason: intent.blocked_reason || '',
                        unavailable_reason: '',
                    },
                } : item),
                unread_count: wasUnread
                    ? Math.max(0, Number(operationAlerts.value.unread_count || 0) - 1)
                    : Number(operationAlerts.value.unread_count || 0),
            };
            showToast(res.data?.reused_existing_intent ? '已关联现有待审批任务' : '已转为待审批运营任务');
            await openOperationAlertTask({
                ...alert,
                status: 'read',
                task_bridge: {
                    linked: true,
                    intent_id: intentId,
                    intent_status: intent.status || 'pending_approval',
                },
            });
        } catch (error) {
            showToast(operationErrorMessage(error, '预警转任务失败'), 'error');
        } finally {
            operationAlertTaskLoadingIds.value = operationAlertTaskLoadingIds.value.filter(id => id !== alertId);
        }
    };

    const isBlankStrategyValue = (value) => value === null || value === undefined || String(value).trim() === '';
    const isValidStrategyDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
    const failOperationStrategyValidation = (message) => {
        operationStrategyResult.value = null;
        operationError.value.strategy = message;
        showToast(message, 'error');
        return null;
    };
    const buildOperationStrategyPayload = (hotelId) => {
        const form = strategyForm.value || {};
        const startDate = String(form.start_date || '').trim();
        const endDate = String(form.end_date || '').trim();
        if (!isValidStrategyDate(startDate) || !isValidStrategyDate(endDate)) {
            return failOperationStrategyValidation('请选择有效的开始日期和结束日期');
        }
        if (startDate > endDate) {
            return failOperationStrategyValidation('结束日期不能早于开始日期');
        }

        const payload = {
            hotel_id: hotelId,
            strategy_type: form.strategy_type,
            start_date: startDate,
            end_date: endDate,
        };

        if (operationStrategyAmountRequired.value) {
            const amount = Number(form.adjust_amount);
            if (isBlankStrategyValue(form.adjust_amount) || !Number.isFinite(amount) || amount === 0) {
                return failOperationStrategyValidation('调价金额必填且不能为 0');
            }
            payload.adjust_amount = amount;
        }

        if (operationStrategyDiscountRequired.value) {
            const discountRate = Number(form.discount_rate);
            if (isBlankStrategyValue(form.discount_rate) || !Number.isFinite(discountRate) || discountRate <= 0 || discountRate > 100) {
                return failOperationStrategyValidation('折扣比例必填，范围为 0-100');
            }
            payload.discount_rate = discountRate;
        }

        return payload;
    };

    const simulateOperationStrategy = async () => {
        await ensureOperationStaticReady();
        operationError.value.strategy = '';
        const hotelId = normalizeOperationHotelSelection(strategyForm, {
            requireHotel: true,
            errorKey: 'strategy',
            fallbackMessage: '请选择有权限的酒店后再模拟',
        });
        if (hotelId === null) {
            operationStrategyResult.value = null;
            return;
        }
        const payload = buildOperationStrategyPayload(hotelId);
        if (!payload) return;

        operationLoading.value.strategy = true;
        try {
            const res = await apiRequest('/operation/strategy-simulation', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            if (res.code !== 200) throw new Error(res.message || '策略模拟失败');
            operationStrategyResult.value = res.data || null;
            showToast('策略模拟已完成');
        } catch (error) {
            operationError.value.strategy = operationErrorMessage(error, '策略模拟失败');
            showToast(operationError.value.strategy, 'error');
        } finally {
            operationLoading.value.strategy = false;
        }
    };

    const createOperationAction = async () => {
        await ensureOperationStaticReady();
        if (!actionForm.value.action_title || !actionForm.value.action_type || !actionForm.value.start_date) {
            showToast('策略标题、类型和开始日期不能为空', 'error');
            return;
        }
        operationLoading.value.actions = true;
        operationError.value.actions = '';
        try {
            const hotelId = normalizeOperationHotelSelection(actionForm, {
                requireHotel: true,
                errorKey: 'actions',
                fallbackMessage: '请选择有权限的酒店后再创建',
            });
            if (hotelId === null) return;
            const res = await apiRequest('/operation/actions', {
                method: 'POST',
                body: JSON.stringify({ ...actionForm.value, hotel_id: hotelId }),
            });
            if (res.code !== 200) throw new Error(res.message || '创建策略动作失败');
            showToast('策略动作已创建');
            actionForm.value.action_title = '';
            actionForm.value.remark = '';
            await loadOperationActions();
        } catch (error) {
            operationError.value.actions = operationErrorMessage(error, '创建策略动作失败');
            showToast(operationError.value.actions, 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    let aiDailyFactGateRequestSeq = 0;
    const loadAiDailyFactGate = async (options = {}) => {
        await ensureRevenueAiStaticReady();
        const requestSeq = ++aiDailyFactGateRequestSeq;
        const explicitHotelId = String(options?.hotelId || '').trim();
        const hotelId = explicitHotelId || normalizeOperationHotelSelection(aiDailyReportForm, {
            requireHotel: true,
            fallbackMessage: '请选择有权限的酒店',
        });
        const targetDate = String(options?.targetDate || aiDailyReportForm.value.report_date || operationYesterday).trim();
        if (hotelId === null || !hotelId) {
            aiDailyFactGateState.value = {
                hotelId: '',
                targetDate,
                collectionStatus: null,
                profileStatus: null,
                errors: ['hotel_not_selected'],
            };
            aiDailyFactGateLoading.value = false;
            return;
        }

        aiDailyFactGateLoading.value = true;
        try {
            const collectionParams = new URLSearchParams({
                system_hotel_id: String(hotelId),
                platform: 'all',
                target_date: targetDate,
            });
            const profileParams = new URLSearchParams({ system_hotel_id: String(hotelId) });
            const [collectionResult, profileResult] = await Promise.allSettled([
                apiRequest(`/online-data/collection-status?${collectionParams.toString()}`),
                apiRequest(`/online-data/platform-profile-status?${profileParams.toString()}`),
            ]);
            if (requestSeq !== aiDailyFactGateRequestSeq) return;

            const errors = [];
            let collectionStatus = null;
            let profileStatus = null;
            if (collectionResult.status === 'fulfilled' && collectionResult.value?.code === 200) {
                collectionStatus = collectionResult.value.data || null;
            } else {
                const message = collectionResult.status === 'fulfilled'
                    ? collectionResult.value?.message
                    : collectionResult.reason?.message;
                errors.push(`collection_status_failed${message ? `: ${message}` : ''}`);
            }
            if (profileResult.status === 'fulfilled' && profileResult.value?.code === 200) {
                profileStatus = profileResult.value.data || null;
            } else {
                const message = profileResult.status === 'fulfilled'
                    ? profileResult.value?.message
                    : profileResult.reason?.message;
                errors.push(`platform_profile_status_failed${message ? `: ${message}` : ''}`);
            }
            aiDailyFactGateState.value = {
                hotelId: String(hotelId),
                targetDate,
                collectionStatus,
                profileStatus,
                errors,
            };
        } finally {
            if (requestSeq === aiDailyFactGateRequestSeq) {
                aiDailyFactGateLoading.value = false;
            }
        }
    };

    let aiDailyReportRequestSeq = 0;
    const loadAiDailyReport = async () => {
        if (aiDailyReportGenerationTaskPolling.value) return;
        await Promise.all([
            ensureOperationStaticReady(),
            ensureRevenueAiStaticReady(),
        ]);
        const requestSeq = ++aiDailyReportRequestSeq;
        operationLoading.value.aiDailyReport = true;
        operationError.value.aiDailyReport = '';
        try {
            const params = new URLSearchParams();
            const hotelId = normalizeOperationHotelSelection(aiDailyReportForm, {
                requireHotel: true,
                errorKey: 'aiDailyReport',
                fallbackMessage: '请选择有权限的酒店',
            });
            if (hotelId === null) return;
            operationFilters.value.hotel_id = String(hotelId);
            void loadAiDailyFactGate({ hotelId, targetDate: aiDailyReportForm.value.report_date || operationYesterday });
            if (hotelId) params.append('hotel_id', hotelId);
            const query = params.toString() ? '?' + params.toString() : '';
            const res = await apiRequest(`/ai-daily-reports/latest${query}`);
            if (requestSeq !== aiDailyReportRequestSeq) return;
            if (res.code !== 200) throw new Error(res.message || 'AI经营日报加载失败');
            if (res.data?.data_status === 'missing_table') {
                aiDailyReport.value = null;
                const gap = Array.isArray(res.data?.data_gaps) ? res.data.data_gaps[0] : null;
                operationError.value.aiDailyReport = gap?.message || 'AI经营日报表未初始化，请先执行数据库迁移。';
                return;
            }
            aiDailyReport.value = res.data?.report || null;
        } catch (error) {
            if (requestSeq !== aiDailyReportRequestSeq) return;
            operationError.value.aiDailyReport = operationErrorMessage(error, 'AI经营日报加载失败');
        } finally {
            if (requestSeq === aiDailyReportRequestSeq) {
                operationLoading.value.aiDailyReport = false;
            }
        }
    };

    const validateAiDailyReportReadback = (payload, expectedReportId, expectedHotelId) => {
        const report = payload?.report && typeof payload.report === 'object' ? payload.report : payload;
        if (!report || typeof report !== 'object' || Array.isArray(report)) {
            throw new Error('AI经营日报回读响应格式无效');
        }
        const reportId = aiDailyReportTaskPositiveInteger(report.id);
        const hotelId = aiDailyReportTaskPositiveInteger(report.hotel_id);
        const normalizedExpectedReportId = aiDailyReportTaskPositiveInteger(expectedReportId);
        const normalizedExpectedHotelId = aiDailyReportTaskPositiveInteger(expectedHotelId);
        if (!reportId || (normalizedExpectedReportId && reportId !== normalizedExpectedReportId)) {
            throw new Error('AI经营日报回读资源ID不一致');
        }
        if (!hotelId || (normalizedExpectedHotelId && hotelId !== normalizedExpectedHotelId)) {
            throw new Error('AI经营日报回读酒店范围不一致');
        }
        return report;
    };

    const readAiDailyReportById = async (reportId, expectedHotelId) => {
        const normalizedReportId = aiDailyReportTaskPositiveInteger(reportId);
        if (!normalizedReportId) throw new Error('AI经营日报任务未返回有效报告ID');
        const res = await apiRequest(`/ai-daily-reports/${normalizedReportId}`);
        if (res.code !== 200) throw new Error(res.message || 'AI经营日报精确回读失败');
        return validateAiDailyReportReadback(res.data, normalizedReportId, expectedHotelId);
    };

    const generateAiDailyReport = async () => {
        const requestSeq = nextAiDailyReportGenerationRequestSeq();
        operationLoading.value.aiDailyReport = true;
        operationError.value.aiDailyReport = '';
        aiDailyReportGenerationTaskPolling.value = false;
        aiDailyReportGenerationTask.value = null;
        try {
            await Promise.all([
                ensureOperationStaticReady(),
                ensureRevenueAiStaticReady(),
            ]);
            const hotelId = normalizeOperationHotelSelection(aiDailyReportForm, {
                requireHotel: true,
                errorKey: 'aiDailyReport',
                fallbackMessage: '请选择有权限的酒店',
            });
            if (hotelId === null) return;
            const expectedHotelId = aiDailyReportTaskPositiveInteger(hotelId);
            if (!expectedHotelId) throw new Error('请选择有权限的酒店');
            const reportDate = aiDailyReportForm.value.report_date || operationYesterday;
            operationFilters.value.hotel_id = String(hotelId);
            aiDailyReport.value = null;
            void loadAiDailyFactGate({ hotelId, targetDate: reportDate });
            const res = await apiRequest('/ai-daily-reports/generate', {
                method: 'POST',
                body: JSON.stringify({
                    hotel_id: hotelId,
                    report_date: reportDate,
                    edition: aiDailyReportForm.value.edition || 'lite',
                    use_llm: aiDailyReportForm.value.use_llm,
                    background: true,
                }),
            });
            if (res.code !== 200) throw new Error(res.message || 'AI经营日报生成失败');
            if (!isAiDailyReportGenerationRequestCurrent(requestSeq)) return;

            const responseData = res.data || null;
            const responseTaskId = String(responseData?.task_id || '').trim();
            if (!responseTaskId) {
                const directReport = validateAiDailyReportReadback(
                    responseData?.report || responseData,
                    responseData?.report?.id || responseData?.id,
                    expectedHotelId
                );
                aiDailyReport.value = directReport;
                if (aiDailyReportModelIsLimited(directReport.model_status)) {
                    showToast('经营日报规则报告已生成；AI增强受数据质量限制，请查看缺口。', 'warning');
                } else {
                    showToast('AI经营日报已生成并完成酒店范围校验', 'success');
                }
                void loadOperationActions();
                return;
            }

            const initialTask = normalizeAiDailyReportGenerationTask(responseData, expectedHotelId, responseTaskId);
            const initialDeduplicated = initialTask.deduplicated;
            const updateGenerationTask = (task, patch = {}) => {
                const previous = aiDailyReportGenerationTask.value;
                aiDailyReportGenerationTask.value = {
                    ...task,
                    cacheHit: task.cacheHit || previous?.cacheHit === true,
                    deduplicated: task.deduplicated || previous?.deduplicated === true || initialDeduplicated,
                    readbackStatus: previous?.readbackStatus || '',
                    ...patch,
                };
            };
            updateGenerationTask(initialTask);
            aiDailyReportGenerationTaskPolling.value = true;

            const pollResult = await pollAiDailyReportGenerationTask({
                taskId: responseTaskId,
                expectedHotelId,
                initialTask,
                requestTask: (taskId) => apiRequest(`/ai-daily-reports/tasks/${encodeURIComponent(taskId)}`),
                wait: (delay) => new Promise(resolve => setTimeout(resolve, delay)),
                onProgress: (task) => {
                    if (isAiDailyReportGenerationRequestCurrent(requestSeq)) updateGenerationTask(task);
                },
                isCurrent: () => isAiDailyReportGenerationRequestCurrent(requestSeq),
            });
            if (!isAiDailyReportGenerationRequestCurrent(requestSeq) || pollResult.outcome.kind === 'cancelled') return;
            updateGenerationTask(pollResult.task);
            if (pollResult.outcome.kind === 'failed') throw new Error(pollResult.outcome.message);

            updateGenerationTask(pollResult.task, { readbackStatus: 'reading' });
            const report = await readAiDailyReportById(pollResult.task.resultReportId, expectedHotelId);
            if (!isAiDailyReportGenerationRequestCurrent(requestSeq)) return;
            const verifiedTask = {
                ...pollResult.task,
                modelStatus: pollResult.task.modelStatus || String(report.model_status || '').trim().toLowerCase(),
            };
            const verifiedOutcome = resolveAiDailyReportGenerationOutcome(verifiedTask);
            updateGenerationTask(verifiedTask, { readbackStatus: 'verified' });
            aiDailyReport.value = report;

            if (verifiedOutcome.kind === 'limited') {
                showToast(verifiedOutcome.message || '规则报告已生成并回读，但AI增强受限。', 'warning');
            } else if (verifiedOutcome.kind === 'succeeded') {
                showToast('AI经营日报已生成并完成精确回读验证', 'success');
            } else {
                throw new Error(verifiedOutcome.message || 'AI日报任务终态无法确认');
            }
            void loadOperationActions();
        } catch (error) {
            if (!isAiDailyReportGenerationRequestCurrent(requestSeq)) return;
            operationError.value.aiDailyReport = operationErrorMessage(error, 'AI经营日报生成失败');
            if (aiDailyReportGenerationTask.value) {
                const currentTask = aiDailyReportGenerationTask.value;
                const currentOutcome = resolveAiDailyReportGenerationOutcome(currentTask);
                aiDailyReportGenerationTask.value = {
                    ...currentTask,
                    ...(currentOutcome.kind === 'succeeded' || currentOutcome.kind === 'limited'
                        ? { readbackStatus: 'failed' }
                        : { clientError: operationError.value.aiDailyReport }),
                };
            }
            showToast(operationError.value.aiDailyReport, 'error');
        } finally {
            if (isAiDailyReportGenerationRequestCurrent(requestSeq)) {
                operationLoading.value.aiDailyReport = false;
                aiDailyReportGenerationTaskPolling.value = false;
            }
        }
    };

    const readOperationExecutionIntent = async (intentId) => {
        const normalizedId = Number(intentId || 0);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) {
            throw new Error('执行意图回读ID无效');
        }
        const res = await apiRequest(`/operation/execution-intents/${intentId}`);
        if (res.code !== 200) throw new Error(res.message || '执行意图回读失败');
        const intent = res.data || {};
        if (Number(intent.id || 0) !== normalizedId) {
            throw new Error('执行意图回读资源不一致');
        }
        return intent;
    };

    const operationExecutionHotelId = (item) => Number(
        item?.hotel_id
        || item?.system_hotel_id
        || item?.execution?.hotel_id
        || item?.execution?.system_hotel_id
        || 0
    );

    const readOperationExecutionTask = async (taskId, expectedHotelId = 0) => {
        const normalizedId = Number(taskId || 0);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) {
            throw new Error('执行任务回读ID无效');
        }
        const normalizedHotelId = Number(expectedHotelId || 0);
        const params = new URLSearchParams();
        if (normalizedHotelId > 0) {
            params.set('hotel_id', String(normalizedHotelId));
            params.set('system_hotel_id', String(normalizedHotelId));
        }
        const query = params.toString() ? `?${params.toString()}` : '';
        const res = await apiRequest(`/operation/execution-tasks/${taskId}${query}`, normalizedHotelId > 0
            ? { businessContext: { hotelId: normalizedHotelId } }
            : {});
        if (res.code !== 200) throw new Error(res.message || '执行任务回读失败');
        const task = res.data || {};
        if (Number(task.id || 0) !== normalizedId) {
            throw new Error('执行任务回读资源不一致');
        }
        if (normalizedHotelId > 0 && operationExecutionHotelId(task) !== normalizedHotelId) {
            throw new Error('执行任务回读酒店身份不一致');
        }
        return task;
    };

    const operationExecutionEvidenceCount = (task = {}) => Math.max(
        Array.isArray(task.evidence) ? task.evidence.length : 0,
        Number(task?.evidence_summary?.count || 0)
    );

    const operationExecutionHasEvidenceType = (task = {}, evidenceType = '') => {
        const expected = String(evidenceType || '').trim();
        if (!expected) return operationExecutionEvidenceCount(task) > 0;
        const directTypes = Array.isArray(task.evidence)
            ? task.evidence.map(row => String(row?.evidence_type || '').trim())
            : [];
        const summaryTypes = Array.isArray(task?.evidence_summary?.types)
            ? task.evidence_summary.types.map(value => String(value || '').trim())
            : [];
        return [...directTypes, ...summaryTypes].includes(expected);
    };

    const collectPriceExecutionIntentFields = async (item = {}) => {
        const firstText = (keys = []) => {
            for (const key of keys) {
                const value = item?.[key];
                if (value !== undefined && value !== null && String(value).trim() !== '') return String(value).trim();
            }
            return '';
        };
        const defaultPlatform = firstText(['platform', 'channel', 'source_channel']).toLowerCase();
        const today = formatDate(new Date());
        const configuredExecutionDate = firstText(['execution_date', 'date_start']);
        const defaultExecutionDate = configuredExecutionDate >= today ? configuredExecutionDate : today;
        const formValues = await openWorkflowFormDialog({
            title: '创建 OTA 调价执行意图',
            description: '这里只创建待审批执行单，不会直接向 OTA 写入价格。',
            submitText: '创建待审批执行单',
            fields: [
                {
                    name: 'platform',
                    label: '执行平台',
                    type: 'select',
                    required: true,
                    value: ['ctrip', 'meituan'].includes(defaultPlatform) ? defaultPlatform : '',
                    options: [
                        { value: 'ctrip', label: '携程' },
                        { value: 'meituan', label: '美团' },
                    ],
                },
                {
                    name: 'execution_date',
                    label: '计划执行日期',
                    type: 'date',
                    required: true,
                    value: defaultExecutionDate,
                    min: today,
                },
                { name: 'room_type_key', label: 'OTA 房型标识 room_type_key', required: true, value: firstText(['room_type_key', 'roomTypeKey']) },
                { name: 'rate_plan_key', label: 'OTA 价型标识 rate_plan_key', required: true, value: firstText(['rate_plan_key', 'ratePlanKey']) },
            ],
        });
        if (formValues === null) return null;
        const fields = {
            platform: String(formValues.platform || '').trim().toLowerCase(),
            execution_date: String(formValues.execution_date || '').trim(),
            room_type_key: String(formValues.room_type_key || '').trim(),
            rate_plan_key: String(formValues.rate_plan_key || '').trim(),
        };
        const missing = Object.entries(fields).find(([, value]) => !value);
        if (missing) {
            showToast(`${missing[0]} 必填，未创建执行意图`, 'error');
            return null;
        }
        if (fields.execution_date < today) {
            showToast('计划执行日期不能早于今天，未创建执行意图', 'error');
            return null;
        }
        return fields;
    };

    const createAiDailyExecutionIntent = async (action, index) => {
        if (!aiDailyReport.value?.id || !action) return;
        if (!revenueAiDailyReportActionExecutionReady(action)) {
            showToast(action.blocked_reason || '该建议不能转执行单', 'warning');
            return;
        }
        operationLoading.value.aiDailyReport = true;
        try {
            const res = await apiRequest(`/ai-daily-reports/${aiDailyReport.value.id}/actions/${index}/execution-intent`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            if (res.code !== 200) throw new Error(res.message || '执行单创建失败');
            const responseIntent = res.data?.execution_intent || {};
            const intentId = Number(responseIntent.id || 0);
            if (!Number.isInteger(intentId) || intentId <= 0) throw new Error('执行单创建结果缺少有效ID');
            const persistedIntent = await readOperationExecutionIntent(intentId);
            if (persistedIntent.status !== 'pending_approval'
                || String(persistedIntent.blocked_reason || '').trim() !== ''
            ) {
                throw new Error('执行意图未通过待审批且未阻塞的严格回读');
            }
            showToast('已生成执行意图，进入审批流程');
            const reportHotelId = String(aiDailyReport.value?.hotel_id || aiDailyReportForm.value.hotel_id || '').trim();
            if (reportHotelId) operationFilters.value.hotel_id = reportHotelId;
            await loadAiDailyReport();
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, '执行单创建失败'), 'error');
        } finally {
            operationLoading.value.aiDailyReport = false;
        }
    };


    let operatingMemoryRequestSeq = 0;
    const loadOperatingMemories = async (options = {}) => {
        const requestSeq = ++operatingMemoryRequestSeq;
        const requestedHotelId = String(
            options?.hotelId !== undefined ? options.hotelId : operationFilters.value.hotel_id || ''
        ).trim();
        operatingMemoryLoading.value = true;
        operatingMemoryError.value = '';
        try {
            const params = new URLSearchParams();
            if (requestedHotelId) {
                params.set('hotel_id', requestedHotelId);
                params.set('system_hotel_id', requestedHotelId);
            }
            const query = params.toString() ? `?${params.toString()}` : '';
            const res = await apiRequest(`/operation/operating-memories${query}`);
            if (requestSeq !== operatingMemoryRequestSeq) return null;
            if (res.code !== 200) throw new Error(res.message || '经营记忆加载失败');
            const payload = res.data && typeof res.data === 'object' ? res.data : null;
            if (!payload || !Array.isArray(payload.list) || !Array.isArray(payload.data_gaps)) {
                throw new Error('经营记忆回读结构不完整');
            }
            operatingMemories.value = payload;
            return payload;
        } catch (error) {
            if (requestSeq !== operatingMemoryRequestSeq) return null;
            operatingMemories.value = { data_status: 'readback_failed', list: [], count: 0, data_gaps: [] };
            operatingMemoryError.value = operationErrorMessage(error, '经营记忆加载失败');
            return null;
        } finally {
            if (requestSeq === operatingMemoryRequestSeq) operatingMemoryLoading.value = false;
        }
    };

    const saveOperationExecutionMemory = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        if (!Number.isInteger(taskId) || taskId <= 0) {
            showToast('执行任务ID无效，不能沉淀经营记忆', 'error');
            return;
        }
        if (!operationCanSaveOperatingMemory(item)) {
            showToast('请先保存执行结果和复盘说明，再沉淀经营记忆', 'warning');
            return;
        }
        operatingMemorySavingTaskId.value = taskId;
        try {
            const res = await apiRequest(`/operation/execution-tasks/${taskId}/operating-memory`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            if (res.code !== 200) throw new Error(res.message || '经营记忆保存失败');
            const saved = res.data?.memory || {};
            const memoryId = Number(saved.id || 0);
            if (!Number.isInteger(memoryId) || memoryId <= 0
                || String(res.data?.persistence_status || '') !== 'readback_verified'
                || res.data?.write_boundaries?.ota_write !== false
                || res.data?.write_boundaries?.external_message !== false
            ) {
                throw new Error('经营记忆保存结果未通过边界与回读校验');
            }
            const readbackRes = await apiRequest(`/operation/operating-memories/${memoryId}`);
            if (readbackRes.code !== 200) throw new Error(readbackRes.message || '经营记忆严格回读失败');
            const readback = readbackRes.data || {};
            if (Number(readback.id || 0) !== memoryId
                || Number(readback.hotel_id || 0) !== Number(item?.hotel_id || 0)
                || Number(readback.source_record_id || 0) !== taskId
                || String(readback.memory_layer || '') !== 'execution_review'
                || String(readback.content_digest || '') !== String(saved.content_digest || '')
            ) {
                throw new Error('经营记忆严格回读身份不一致');
            }
            showToast(res.data?.created === false ? '相同复盘记忆已存在，已完成回读' : '经营记忆已保存并完成严格回读');
            await loadOperatingMemories();
        } catch (error) {
            showToast(operationErrorMessage(error, '经营记忆保存失败'), 'error');
        } finally {
            operatingMemorySavingTaskId.value = 0;
        }
    };

    let homeOperatingScheduleRequestSeq = 0;
    const applyHomeOperatingScheduleFlow = (flow, hotelId = '') => {
        const scopedHotelId = String(hotelId || '').trim();
        if (scopedHotelId) {
            const responseHotelId = Number(flow?.capabilities?.hotel_id || 0);
            if (!responseHotelId || responseHotelId !== Number(scopedHotelId)) {
                throw new Error('今日经营编排返回的酒店身份不一致');
            }
            const crossHotelItem = Array.isArray(flow?.list)
                ? flow.list.find(item => Number(item?.hotel_id || 0) !== Number(scopedHotelId))
                : null;
            if (crossHotelItem) {
                throw new Error('今日经营编排包含其他酒店任务，已拒绝展示');
            }
        }
        homeOperatingScheduleFlow.value = flow && typeof flow === 'object'
            ? flow
            : { summary: {}, stages: [], list: [], data_gaps: [], data_status: '' };
        homeOperatingScheduleScopeHotelId.value = scopedHotelId;
        homeOperatingScheduleError.value = '';
        homeOperatingScheduleLastReadAt.value = new Date().toLocaleString('zh-CN', { hour12: false });
    };
    const loadHomeOperatingSchedule = async (options = {}) => {
        const requestSeq = ++homeOperatingScheduleRequestSeq;
        const hotelId = String(
            Object.prototype.hasOwnProperty.call(options, 'hotelId')
                ? options.hotelId
                : filterReportHotel.value
        ).trim();
        const isCurrentRequest = () => requestSeq === homeOperatingScheduleRequestSeq
            && hotelId === String(filterReportHotel.value || '').trim();
        if (hotelId !== homeOperatingScheduleScopeHotelId.value) {
            homeOperatingScheduleFlow.value = null;
            homeOperatingScheduleScopeHotelId.value = hotelId;
            homeOperatingScheduleLastReadAt.value = '';
        }
        homeOperatingScheduleLoading.value = true;
        homeOperatingScheduleError.value = '';
        try {
            await ensureOperationStaticReady();
            if (!isCurrentRequest()) return false;
            const params = new URLSearchParams({ limit: '100' });
            if (hotelId) {
                params.set('hotel_id', hotelId);
                params.set('system_hotel_id', hotelId);
            }
            const res = await apiRequest(`/operation/execution-flow?${params.toString()}`);
            if (!isCurrentRequest()) return false;
            if (res.code !== 200) throw new Error(res.message || '今日经营编排读取失败');
            const flow = res.data && typeof res.data === 'object' ? res.data : null;
            if (!flow || !Array.isArray(flow.list)) {
                throw new Error('今日经营编排未返回任务列表');
            }
            applyHomeOperatingScheduleFlow(flow, hotelId);
            return true;
        } catch (error) {
            if (!isCurrentRequest()) return false;
            homeOperatingScheduleError.value = operationErrorMessage(error, '今日经营编排读取失败');
            return false;
        } finally {
            if (requestSeq === homeOperatingScheduleRequestSeq) {
                homeOperatingScheduleLoading.value = false;
            }
        }
    };
    const openHomeOperatingScheduleItem = async (item = {}) => {
        if (item.kind === 'fact') {
            openOnlineDataEntryTab('data-health', { force: true });
            return;
        }
        const intentId = Number(item.intentId || 0);
        const hotelId = Number(item.hotelId || 0);
        if (!intentId || !hotelId) {
            showToast('任务缺少酒店或执行意图身份，无法打开', 'warning');
            return;
        }
        operationFilters.value.hotel_id = String(hotelId);
        operationExecutionStageFilter.value = '';
        currentPage.value = 'ops-track';
        await nextTick();
        await loadOperationActions({ focusIntentId: intentId });
        if (!operationExecutionItems.value.some(row => Number(row?.id || 0) === intentId)) {
            showToast('对应任务未能按当前酒店权限回读', 'error');
            return;
        }
        showToast(`已打开 ${item.hotelName || `酒店 #${hotelId}`} 的对应任务`);
    };
    const openHomeOperatingScheduleAll = async () => {
        operationFilters.value.hotel_id = String(filterReportHotel.value || '').trim();
        operationExecutionStageFilter.value = '';
        currentPage.value = 'ops-track';
        await nextTick();
        await loadOperationActions();
    };

    let operationActionsRequestSeq = 0;
    const loadOperationActions = async (options = {}) => {
        const requestSeq = ++operationActionsRequestSeq;
        const focusIntentId = Number(options?.focusIntentId || 0);
        await ensureOperationStaticReady();
        if (requestSeq !== operationActionsRequestSeq) return;
        operationLoading.value.actions = true;
        operationError.value.actions = '';
        let requestHotelId = '';
        const isCurrentRequest = () => (
            requestSeq === operationActionsRequestSeq
            && requestHotelId === String(operationFilters.value.hotel_id || '').trim()
        );
        try {
            const params = new URLSearchParams();
            const hotelId = normalizeOperationHotelSelection(operationFilters, {
                errorKey: 'actions',
                fallbackMessage: '请选择有权限的酒店',
            });
            if (hotelId === null) return;
            requestHotelId = String(hotelId || '').trim();
            if (requestHotelId) {
                params.append('hotel_id', requestHotelId);
                params.append('system_hotel_id', requestHotelId);
            }
            const query = params.toString() ? '?' + params.toString() : '';
            const flowParams = new URLSearchParams(params);
            if (Number.isInteger(focusIntentId) && focusIntentId > 0) {
                flowParams.set('intent_id', String(focusIntentId));
            }
            const flowQuery = flowParams.toString() ? '?' + flowParams.toString() : '';
            const [res, flowRes, closureRes] = await Promise.all([
                apiRequest(`/operation/action-tracking${query}`),
                apiRequest(`/operation/execution-flow${flowQuery}`),
                apiRequest(`/operation/closure-overview${query}`),
                loadOperatingMemories({ hotelId: requestHotelId }),
            ]);
            if (!isCurrentRequest()) return;
            if (res.code !== 200) throw new Error(res.message || '策略追踪加载失败');
            if (flowRes.code !== 200) throw new Error(flowRes.message || '执行闭环加载失败');
            if (closureRes.code !== 200) throw new Error(closureRes.message || '闭环总览加载失败');
            operationActions.value = res.data?.actions || [];
            operationExecutionFlow.value = flowRes.data || { summary: {}, stages: [], list: [], data_gaps: [], data_status: '' };
            if (focusIntentId === 0
                && requestHotelId === String(filterReportHotel.value || '').trim()
            ) {
                applyHomeOperatingScheduleFlow(operationExecutionFlow.value, requestHotelId);
            }
            operationClosureOverview.value = closureRes.data || { summary: {}, modules: [], weak_modules: [], data_gaps: [], data_status: '' };
            operationEffectValidation.value = res.data?.effect_validation || { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} };
        } catch (error) {
            if (!isCurrentRequest()) return;
            operationError.value.actions = operationErrorMessage(error, '策略追踪加载失败');
            if (focusIntentId === 0
                && requestHotelId === String(filterReportHotel.value || '').trim()
            ) {
                homeOperatingScheduleError.value = operationError.value.actions;
            }
            showToast(operationError.value.actions, 'error');
        } finally {
            if (requestSeq === operationActionsRequestSeq) {
                operationLoading.value.actions = false;
            }
        }
    };

    const parseOperationEvidenceNumber = (value, label) => {
        const text = String(value ?? '').trim();
        if (!text) throw new Error(`${label}不能为空`);
        const number = Number(text.replace(/[,，]/g, ''));
        if (!Number.isFinite(number)) throw new Error(`${label}必须是数字`);
        return number;
    };
    const parseOptionalOperationEvidenceNumber = (value, label) => {
        const text = String(value ?? '').trim();
        if (!text) return null;
        return parseOperationEvidenceNumber(text, label);
    };
    const operationEvidenceFirstText = (sources = [], keys = []) => {
        const list = Array.isArray(sources) ? sources : [sources];
        for (const source of list) {
            if (!source || typeof source !== 'object') continue;
            for (const key of keys) {
                const value = source[key];
                if (value !== undefined && value !== null && String(value).trim() !== '') {
                    return String(value);
                }
            }
        }
        return '';
    };
    const operationEvidenceCleanObject = (value = {}) => Object.fromEntries(
        Object.entries(value).filter(([, entry]) => entry !== undefined && entry !== null && String(entry).trim() !== '')
    );
    const operationEvidenceLocalTimestamp = () => {
        const date = new Date();
        const pad = (number) => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    };
    const normalizeOperationEvidenceDateTime = (value) => {
        const text = String(value ?? '').trim().replace('T', ' ');
        if (!text) return operationEvidenceLocalTimestamp();
        if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(text)) {
            throw new Error('执行时间格式需为 YYYY-MM-DD HH:mm:ss');
        }
        return text.length === 16 ? `${text}:00` : text;
    };
    const normalizeOperationReviewStatus = (value) => {
        const text = String(value ?? '').trim().toLowerCase();
        const map = {
            '1': 'success',
            '2': 'near_success',
            '3': 'failed',
            '4': 'observing',
            ok: 'success',
            success: 'success',
            near: 'near_success',
            near_success: 'near_success',
            failed: 'failed',
            fail: 'failed',
            observing: 'observing',
            wait: 'observing',
            '达成': 'success',
            '成功': 'success',
            '接近达成': 'near_success',
            '接近': 'near_success',
            '未达成': 'failed',
            '失败': 'failed',
            '观察': 'observing',
            '继续观察': 'observing',
        };
        const status = map[text] || '';
        if (!status) {
            throw new Error('复盘结论必须是 success、near_success、failed 或 observing');
        }
        return status;
    };

    const operationApprovalConfirming = ref(false);
    const operationApprovalConfirmingIntentId = ref(0);
    const clearOperationApprovalConfirmation = () => {
        operationApprovalConfirming.value = false;
        operationApprovalConfirmingIntentId.value = 0;
    };
    const operationApprovalText = (item) => (
        operationApprovalConfirming.value
        && operationApprovalConfirmingIntentId.value === Number(item?.id || 0)
            ? '确认审批'
            : '审批'
    );
    const operationRejectText = (item) => (
        operationApprovalConfirming.value
        && operationApprovalConfirmingIntentId.value === Number(item?.id || 0)
            ? '取消确认'
            : '驳回'
    );
    const rejectOrCancelOperationApproval = async (item) => {
        if (operationApprovalConfirming.value
            && operationApprovalConfirmingIntentId.value === Number(item?.id || 0)
        ) {
            clearOperationApprovalConfirmation();
            showToast('已取消审批确认', 'info');
            return;
        }
        await approveOperationExecutionIntent(item, false);
    };
    const approveOperationExecutionIntent = async (item, approved = true) => {
        if (!item?.id) return;
        let remark = '';
        if (!approved) {
            clearOperationApprovalConfirmation();
            const formValues = await openWorkflowFormDialog({
                title: '驳回执行意图',
                description: '驳回原因会写入本地审批记录，供后续复核。',
                submitText: '确认驳回',
                fields: [{ name: 'remark', label: '驳回原因', type: 'textarea', required: true, value: '' }],
            });
            if (formValues === null) return;
            remark = String(formValues.remark || '').trim();
        }
        if (approved && (
            !operationApprovalConfirming.value
            || operationApprovalConfirmingIntentId.value !== Number(item.id)
        )) {
            operationApprovalConfirming.value = true;
            operationApprovalConfirmingIntentId.value = Number(item.id);
            showToast('请再次点击“确认审批”完成操作', 'info');
            return;
        }
        if (approved) clearOperationApprovalConfirmation();
        operationLoading.value.actions = true;
        try {
            const res = await apiRequest(`/operation/execution-intents/${item.id}/approve`, {
                method: 'POST',
                body: JSON.stringify({ approved, remark }),
            });
            if (res.code !== 200) throw new Error(res.message || '执行意图审批失败');
            const responseIntentId = Number(res.data?.id || 0);
            if (!Number.isInteger(responseIntentId) || responseIntentId !== Number(item.id)) {
                throw new Error('执行意图审批返回的资源ID不一致');
            }
            const persistedIntent = await readOperationExecutionIntent(responseIntentId);
            const expectedStatus = approved ? 'approved' : 'rejected';
            if (persistedIntent.status !== expectedStatus) {
                throw new Error(`执行意图回读状态不一致：应为 ${expectedStatus}`);
            }
            if (approved && (!Array.isArray(persistedIntent.tasks)
                || !persistedIntent.tasks.some(task => Number(task?.id || 0) > 0)
            )) {
                throw new Error('执行意图已审批但未回读到执行任务');
            }
            showToast(approved ? '执行意图已审批' : '执行意图已驳回');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, '执行意图审批失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const recordOperationRevenueNodeCheck = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        const executionHotelId = operationExecutionHotelId(item);
        const businessDate = String(item?.recommendation?.date_start || '').slice(0, 10);
        if (!operationCanRecordNodeCheck(item) || !taskId) return;
        if (!executionHotelId || Number(operationFilters.value.hotel_id || 0) !== executionHotelId) {
            showToast('节点检查与当前酒店身份不一致', 'error');
            return;
        }
        if (!/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) {
            showToast('执行任务缺少业务日期，不能记录节点检查', 'error');
            return;
        }
        const previousNode = item?.evidence_summary?.node_record || {};
        const values = await openWorkflowFormDialog({
            title: previousNode.status === 'available' ? '更新收益节点检查' : '记录收益节点检查',
            description: `酒店 #${executionHotelId} · 业务日期 ${businessDate}。仅保存人工节点口径，不会补写PMS/OTA指标或自动执行动作。`,
            submitText: previousNode.status === 'available' ? '保存更新' : '保存节点检查',
            fields: operationRevenueNodeFieldsForItem(item),
        });
        if (values === null) return;

        try {
            const recordedAt = operationEvidenceLocalTimestamp();
            const nodeRecord = buildOperationRevenueNodeRecord(values, recordedAt, {
                system_hotel_id: executionHotelId,
                business_date: businessDate,
            });
            operationLoading.value.actions = true;
            const res = await apiRequest(`/operation/execution-tasks/${taskId}/evidence`, {
                method: 'POST',
                businessContext: { hotelId: executionHotelId },
                body: JSON.stringify({
                    evidence_type: 'revenue_node_check',
                    evidence: {
                        before: {},
                        after: {},
                        platform_response: {
                            mode: 'revenue_node_check',
                            node_record: nodeRecord,
                            evidence_boundary: 'operator_recorded_scope_not_pms_or_ota_verified',
                        },
                        remark: values.judgment_basis,
                    },
                }),
            });
            if (res.code !== 200) throw new Error(res.message || '节点检查保存失败');
            const responseTaskId = Number(res.data?.id || 0);
            if (responseTaskId !== taskId) throw new Error('节点检查返回的任务ID不一致');
            const persistedTask = await readOperationExecutionTask(taskId, executionHotelId);
            const persistedNode = persistedTask?.evidence_summary?.node_record || {};
            const revenueNodeV2PersistedStringFields = [
                'business_date',
                'recorded_at',
                'operating_period',
                'special_event',
                'source_scope',
                'room_status_alignment',
                'data_quality_status',
                'metric_definition',
                'comparison_basis',
                'metric_snapshot',
                'progress_status',
                'judgment_basis',
                'primary_risk',
                'success_criteria',
                'stop_condition',
            ];
            const persistedNodeHasMismatch = revenueNodeV2PersistedStringFields.some(field =>
                String(persistedNode[field] ?? '') !== String(nodeRecord[field] ?? '')
            );
            if (persistedNode.status !== 'available'
                || persistedNode.contract_version !== 'operation_revenue_node.v2'
                || Number(persistedNode.system_hotel_id || 0) !== executionHotelId
                || persistedNodeHasMismatch
            ) {
                throw new Error('节点检查未按完整口径精确回读');
            }
            showToast(previousNode.status === 'available' ? '节点检查已更新并精确回读' : '节点检查已保存并精确回读');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, error.message || '节点检查保存失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const recordOperationExecutionEvidence = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        const executionHotelId = operationExecutionHotelId(item);
        if (!executionHotelId) {
            showToast('执行任务未返回酒店身份，无法保存证据', 'error');
            return;
        }
        if (currentPage.value !== 'ops-track') {
            operationFilters.value.hotel_id = String(executionHotelId);
            currentPage.value = 'ops-track';
            await nextTick();
            await loadOperationActions({ focusIntentId: Number(item?.id || 0) });
            showToast('已打开对应酒店任务，请再次确认执行证据', 'info');
            return;
        }
        if (Number(operationFilters.value.hotel_id || 0) !== executionHotelId) {
            showToast('执行任务与当前酒店身份不一致', 'error');
            return;
        }
        const recommendation = item?.recommendation && typeof item.recommendation === 'object' ? item.recommendation : {};
        const isPriceExecution = recommendation.object_type === 'price';
        if (isPriceExecution) {
            const currentValue = recommendation.current_value && typeof recommendation.current_value === 'object' ? recommendation.current_value : {};
            const targetValue = recommendation.target_value && typeof recommendation.target_value === 'object' ? recommendation.target_value : {};
            const beforePriceDefault = operationEvidenceFirstText([currentValue, targetValue], ['current_price', 'before_price', 'price', 'public_price']);
            const afterPriceDefault = operationEvidenceFirstText([targetValue, currentValue], ['approved_price', 'target_price', 'suggested_price', 'after_price', 'price']);
            const platformDefault = operationEvidenceFirstText([recommendation, targetValue, currentValue], ['platform', 'source_channel', 'channel']);
            const roomTypeDefault = operationEvidenceFirstText([targetValue, currentValue], ['room_type_key', 'room_type_id', 'room_type', 'product_id', 'rate_plan_key']);
            const formValues = await openWorkflowFormDialog({
                title: '登记人工调价执行证据',
                description: '仅保存本地人工证据，不会向携程或美团自动改价。执行前后收入必须同时填写或同时留空。',
                submitText: '保存执行证据',
                fields: [
                    { name: 'before_price', label: '执行前公开价 / 原价', type: 'number', value: beforePriceDefault },
                    { name: 'after_price', label: '执行后公开价 / 实际执行价', type: 'number', required: true, value: afterPriceDefault },
                    { name: 'before_revenue', label: '执行前收入（用于 ROI 复盘）', type: 'number', value: '' },
                    { name: 'after_revenue', label: '执行后收入（用于 ROI 复盘）', type: 'number', value: '' },
                    { name: 'platform', label: '执行平台', value: platformDefault, placeholder: 'ctrip / meituan / 手工' },
                    { name: 'room_type', label: '房型 / 产品标识', value: roomTypeDefault },
                    { name: 'receipt_path', label: '截图 / 回执路径或备注编号', value: '' },
                    { name: 'executed_by', label: '执行人', value: user.value?.realname || user.value?.username || '' },
                    { name: 'executed_at', label: '执行时间', value: operationEvidenceLocalTimestamp(), placeholder: 'YYYY-MM-DD HH:mm:ss' },
                    { name: 'remark', label: '执行证据备注', type: 'textarea', value: '' },
                ],
            });
            if (formValues === null) return;
            const beforePriceText = formValues.before_price;
            const afterPriceText = formValues.after_price;
            const beforeRevenueText = formValues.before_revenue;
            const afterRevenueText = formValues.after_revenue;
            const platformText = formValues.platform;
            const roomTypeText = formValues.room_type;
            const receiptPathText = formValues.receipt_path;
            const operatorText = formValues.executed_by;
            const executedAtText = formValues.executed_at;
            const remarkText = formValues.remark;

            try {
                const beforePrice = parseOptionalOperationEvidenceNumber(beforePriceText, '执行前公开价');
                const afterPrice = parseOperationEvidenceNumber(afterPriceText, '执行后公开价');
                const beforeRevenue = parseOptionalOperationEvidenceNumber(beforeRevenueText, '执行前收入');
                const afterRevenue = parseOptionalOperationEvidenceNumber(afterRevenueText, '执行后收入');
                if ((beforeRevenue === null) !== (afterRevenue === null)) {
                    throw new Error('执行前后收入需同时填写或都留空');
                }
                const platform = String(platformText || '').trim();
                const roomType = String(roomTypeText || '').trim();
                const receiptPath = String(receiptPathText || '').trim();
                const executedBy = String(operatorText || '').trim();
                const executedAt = normalizeOperationEvidenceDateTime(executedAtText);
                const remark = String(remarkText || '').trim();
                const before = {};
                if (beforePrice !== null) before.price = beforePrice;
                if (beforeRevenue !== null) before.revenue = beforeRevenue;
                const after = { price: afterPrice };
                if (afterRevenue !== null) after.revenue = afterRevenue;
                operationLoading.value.actions = true;
                const res = await apiRequest(`/operation/execution-tasks/${taskId}/execute`, {
                    method: 'POST',
                    businessContext: { hotelId: executionHotelId },
                    body: JSON.stringify({
                        status: 'executed',
                        evidence_type: 'manual_price_execution',
                        current_value: operationEvidenceCleanObject({ ...currentValue, executed_before_price: beforePrice }),
                        target_value: operationEvidenceCleanObject({ ...targetValue, executed_after_price: afterPrice }),
                        evidence: {
                            before,
                            after,
                            attachment_path: receiptPath,
                            platform_response: operationEvidenceCleanObject({
                                mode: 'manual',
                                scope: 'ota_channel_manual_execution',
                                platform,
                                room_type: roomType,
                                executed_by: executedBy,
                                executed_at: executedAt,
                                receipt_path: receiptPath,
                                evidence_boundary: 'local_manual_evidence_no_ota_write',
                            }),
                            remark,
                        },
                    }),
                });
                if (res.code !== 200) throw new Error(res.message || '执行证据保存失败');
                const responseTaskId = Number(res.data?.id || 0);
                if (!Number.isInteger(responseTaskId) || responseTaskId !== taskId) {
                    throw new Error('调价执行返回的任务ID不一致');
                }
                const persistedTask = await readOperationExecutionTask(responseTaskId, executionHotelId);
                if (persistedTask.status !== 'executed'
                    || !operationExecutionHasEvidenceType(persistedTask, 'manual_price_execution')
                ) {
                    throw new Error('调价任务未回读到 executed 状态及对应 evidence');
                }
                showToast('调价执行证据已保存；收入未填写时仍需后续补 ROI 验证');
                await loadOperationActions();
            } catch (error) {
                showToast(operationErrorMessage(error, error.message || '执行证据保存失败'), 'error');
            } finally {
                operationLoading.value.actions = false;
            }
            return;
        }
        operationEvidenceModalItem.value = item;
        operationEvidenceForm.value = {
            mode: '1',
            completed_action: '',
            receipt_path: '',
            executed_by: user.value?.realname || user.value?.username || '',
            executed_at: operationEvidenceLocalTimestamp(),
            next_review_date: formatDate(new Date(Date.now() + 24 * 60 * 60 * 1000)),
            before_revenue: '',
            after_revenue: '',
            cost: '',
            remark: '',
        };
        operationEvidenceModalOpen.value = true;
    };

    const closeOperationEvidenceModal = () => {
        if (operationLoading.value.actions) return;
        operationEvidenceModalOpen.value = false;
        operationEvidenceModalItem.value = null;
    };

    const submitOperationExecutionEvidence = async () => {
        const item = operationEvidenceModalItem.value;
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        const executionHotelId = operationExecutionHotelId(item);
        if (!executionHotelId || Number(operationFilters.value.hotel_id || 0) !== executionHotelId) {
            showToast('执行任务与当前酒店身份不一致', 'error');
            return;
        }
        const recommendation = item?.recommendation && typeof item.recommendation === 'object' ? item.recommendation : {};
        const form = operationEvidenceForm.value || {};
        const evidenceMode = String(form.mode || '1').trim();
        const supplementingExecutedTask = item?.execution?.status === 'executed'
            && item?.next_action?.key === 'record_evidence';
        const previousEvidenceCount = operationExecutionEvidenceCount(item);
        try {
            let payload;
            if (evidenceMode === '1') {
                const completedAction = String(form.completed_action || '').trim();
                if (!completedAction) throw new Error('请填写已实际完成的运营动作');
                const executedAt = normalizeOperationEvidenceDateTime(form.executed_at);
                const nextReviewDate = String(form.next_review_date || '').trim();
                if (nextReviewDate && !/^\d{4}-\d{2}-\d{2}$/.test(nextReviewDate)) {
                    throw new Error('效果复盘日期格式需为 YYYY-MM-DD');
                }
                const receiptPath = String(form.receipt_path || '').trim();
                payload = {
                    status: 'executed',
                    evidence_type: 'manual_operation_execution',
                    evidence: {
                        before: {},
                        after: {},
                        attachment_path: receiptPath,
                        platform_response: operationEvidenceCleanObject({
                            mode: 'manual_operation_execution',
                            scope: 'ota_channel_operation',
                            completed_action: completedAction,
                            expected_metric: recommendation.expected_metric || item?.expected_metric || '',
                            executed_by: String(form.executed_by || '').trim(),
                            executed_at: executedAt,
                            next_review_date: nextReviewDate,
                            effect_status: 'pending_observation',
                            evidence_boundary: 'local_manual_evidence_no_ota_write',
                        }),
                        remark: completedAction,
                    },
                };
            } else if (evidenceMode === '2') {
                const beforeRevenue = parseOperationEvidenceNumber(form.before_revenue, '执行前收入');
                const afterRevenue = parseOperationEvidenceNumber(form.after_revenue, '执行后收入');
                const cost = parseOperationEvidenceNumber(form.cost, '执行成本');
                payload = {
                    status: 'executed',
                    evidence_type: 'manual_finance',
                    evidence: {
                        before: { revenue: beforeRevenue },
                        after: { revenue: afterRevenue, cost },
                        platform_response: { mode: 'manual' },
                        remark: String(form.remark || '').trim(),
                    },
                };
            } else {
                throw new Error('执行证据模式无效');
            }
            operationLoading.value.actions = true;
            const evidenceEndpoint = supplementingExecutedTask
                ? `/operation/execution-tasks/${taskId}/evidence`
                : `/operation/execution-tasks/${taskId}/execute`;
            const res = await apiRequest(evidenceEndpoint, {
                method: 'POST',
                businessContext: { hotelId: executionHotelId },
                body: JSON.stringify(payload),
            });
            if (res.code !== 200) throw new Error(res.message || '执行证据保存失败');
            const responseTaskId = Number(res.data?.id || 0);
            if (!Number.isInteger(responseTaskId) || responseTaskId !== taskId) {
                throw new Error('运营执行返回的任务ID不一致');
            }
            const persistedTask = await readOperationExecutionTask(responseTaskId, executionHotelId);
            const expectedEvidenceType = evidenceMode === '1'
                ? 'manual_operation_execution'
                : 'manual_finance';
            if (persistedTask.status !== 'executed'
                || !operationExecutionHasEvidenceType(persistedTask, expectedEvidenceType)
            ) {
                throw new Error('运营任务未回读到 executed 状态及对应 evidence');
            }
            if (supplementingExecutedTask
                && operationExecutionEvidenceCount(persistedTask) <= previousEvidenceCount
            ) {
                throw new Error('运营任务补充证据未在回读中增加');
            }
            operationEvidenceModalOpen.value = false;
            operationEvidenceModalItem.value = null;
            showToast(evidenceMode === '1'
                ? '已保存运营动作证据；效果保持待观察，不自动生成收入或ROI'
                : '执行收入/成本证据已保存');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, error.message || '执行证据保存失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const recordOperationRoiEvidence = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        const recommendation = item?.recommendation && typeof item.recommendation === 'object' ? item.recommendation : {};
        const isPriceExecution = recommendation.object_type === 'price';
        const formValues = await openWorkflowFormDialog({
            title: '登记 ROI / 增量收入证据',
            description: '数据由人工录入并保留本地来源边界；保存后仍需按证据来源复核。',
            submitText: '保存 ROI 证据',
            fields: [
                { name: 'before_revenue', label: '执行前收入', type: 'number', required: true, value: '' },
                { name: 'after_revenue', label: '执行后收入', type: 'number', required: true, value: '' },
                { name: 'cost', label: isPriceExecution ? '执行成本（价格调整可留空）' : '执行成本或投放成本', type: 'number', required: !isPriceExecution, value: '' },
                { name: 'attachment_path', label: '截图 / 回执路径或数据来源说明', value: '' },
                { name: 'remark', label: 'ROI 证据备注', type: 'textarea', value: '', placeholder: '日期口径、数据来源或缺口说明' },
            ],
        });
        if (formValues === null) return;
        const beforeText = formValues.before_revenue;
        const afterText = formValues.after_revenue;
        const costText = formValues.cost;
        const attachmentPathText = formValues.attachment_path;
        const remarkText = formValues.remark;

        try {
            const beforeRevenue = parseOperationEvidenceNumber(beforeText, '执行前收入');
            const afterRevenue = parseOperationEvidenceNumber(afterText, '执行后收入');
            const cost = isPriceExecution
                ? parseOptionalOperationEvidenceNumber(costText, '执行成本')
                : parseOperationEvidenceNumber(costText, '执行成本');
            const attachmentPath = String(attachmentPathText || '').trim();
            const remark = String(remarkText || '').trim();
            const after = { revenue: afterRevenue };
            if (cost !== null) after.cost = cost;
            operationLoading.value.actions = true;
            const res = await apiRequest(`/operation/execution-tasks/${taskId}/evidence`, {
                method: 'POST',
                body: JSON.stringify({
                    evidence_type: 'manual_roi_evidence',
                    evidence: {
                        before: { revenue: beforeRevenue },
                        after,
                        attachment_path: attachmentPath,
                        platform_response: operationEvidenceCleanObject({
                            mode: 'manual_roi_evidence',
                            scope: isPriceExecution ? 'price_execution_incremental_revenue' : 'operation_execution_roi',
                            source: 'revenue_ai_effect_review_input',
                            business_date: revenueAiOverview.value?.business_date || '',
                            evidence_boundary: 'local_manual_roi_evidence_no_ota_write',
                        }),
                        remark,
                    },
                }),
            });
            if (res.code !== 200) throw new Error(res.message || 'ROI证据保存失败');
            const responseTaskId = Number(res.data?.id || 0);
            if (!Number.isInteger(responseTaskId) || responseTaskId !== taskId) {
                throw new Error('ROI证据返回的任务ID不一致');
            }
            const persistedTask = await readOperationExecutionTask(responseTaskId);
            if (!operationExecutionHasEvidenceType(persistedTask, 'manual_roi_evidence')) {
                throw new Error('ROI evidence 严格回读失败');
            }
            showToast('人工录入的 ROI 数据已保存；Revenue AI 将按所填收入/成本重新判断，来源仍需复核');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, error.message || 'ROI证据保存失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const reviewOperationExecutionTask = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        const defaultStatus = ['success', 'near_success', 'failed', 'observing'].includes(String(item?.review?.status || ''))
            ? String(item.review.status)
            : 'observing';
        operationReviewModalItem.value = item;
        operationReviewForm.value = {
            status: defaultStatus,
            summary: '',
            operator_attested: false,
            source_ref: '',
            operator_attested_at: operationEvidenceLocalTimestamp(),
        };
        operationReviewModalOpen.value = true;
    };

    const reconcileOperationExecutionReview = async (item) => {
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        operationLoading.value.actions = true;
        try {
            const res = await apiRequest(`/operation/execution-tasks/${taskId}/reconcile-review`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            if (res.code !== 200) throw new Error(res.message || '到期复盘事实读取失败');
            const result = res.data || {};
            if (Number(result.task_id || 0) !== taskId) {
                throw new Error('到期复盘事实返回的任务ID不一致');
            }
            const persistedTask = await readOperationExecutionTask(taskId);
            if (result.status === 'source_readback_verified') {
                if (persistedTask?.evidence_truth?.source_verified !== true
                    || !operationExecutionHasEvidenceType(persistedTask, 'source_verified_metric_readback')
                ) {
                    throw new Error('来源核验复盘事实严格回读失败');
                }
                showToast('同酒店、同渠道、同指标复盘事实已读取；请人工确认复盘结论', 'success');
            } else if (result.status === 'source_readback_missing') {
                if (persistedTask?.evidence_truth?.source_verified === true) {
                    throw new Error('复盘事实缺失状态与任务回读不一致');
                }
                showToast('约定窗口暂无同口径可信事实，任务继续观察', 'warning');
            } else if (result.status === 'already_reviewed') {
                showToast('该任务已完成复盘，无需重复读取', 'info');
            } else {
                throw new Error('到期复盘事实返回未知状态');
            }
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, error.message || '到期复盘事实读取失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const closeOperationReviewModal = () => {
        if (operationLoading.value.actions) return;
        operationReviewModalOpen.value = false;
        operationReviewModalItem.value = null;
    };

    const submitOperationExecutionReview = async () => {
        const item = operationReviewModalItem.value;
        const taskId = Number(item?.execution?.task_id || 0);
        if (!taskId) return;
        try {
            const resultStatus = normalizeOperationReviewStatus(operationReviewForm.value?.status);
            const resultSummary = String(operationReviewForm.value?.summary || '').trim();
            if (['success', 'near_success', 'failed'].includes(resultStatus) && !resultSummary) {
                throw new Error('复盘结论为达成/接近达成/未达成时必须填写说明');
            }
            const positiveResult = ['success', 'near_success'].includes(resultStatus);
            const operatorAttested = operationReviewForm.value?.operator_attested === true;
            const sourceRef = String(operationReviewForm.value?.source_ref || '').trim();
            const operatorAttestedAt = String(operationReviewForm.value?.operator_attested_at || '').trim();
            if (positiveResult && (!operatorAttested || !sourceRef || !operatorAttestedAt)) {
                throw new Error('判定达成或接近达成前，必须提交人工平台复查声明、来源记录和声明时间');
            }
            operationLoading.value.actions = true;
            const res = await apiRequest(`/operation/execution-tasks/${taskId}/review`, {
                method: 'POST',
                body: JSON.stringify({
                    result_status: resultStatus,
                    result_summary: resultSummary || '继续观察，等待次日收益或ROI证据',
                    ...(positiveResult ? {
                        readback_evidence: {
                            operator_attested: true,
                            operator_attested_at: operatorAttestedAt,
                            source_ref: sourceRef,
                            verification_status: 'operator_attested',
                            remark: '操作者声明已在 OTA 平台人工复查；该声明不等于来源已验证',
                        },
                    } : {}),
                }),
            });
            if (res.code !== 200) throw new Error(res.message || '执行复盘失败');
            const responseTaskId = Number(res.data?.id || 0);
            if (!Number.isInteger(responseTaskId) || responseTaskId !== taskId) {
                throw new Error('执行复盘返回的任务ID不一致');
            }
            const persistedTask = await readOperationExecutionTask(responseTaskId);
            if (String(persistedTask.result_status || '') !== resultStatus) {
                throw new Error('执行复盘 result_status 严格回读不一致');
            }
            operationReviewModalOpen.value = false;
            operationReviewModalItem.value = null;
            showToast(resultStatus === 'observing' ? '执行复盘已记录为继续观察' : '执行复盘结论已保存');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, error.message || '执行复盘失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

    const finishOperationAction = async (id) => {
        if (!id) return;
        operationLoading.value.actions = true;
        try {
            const res = await apiRequest(`/operation/actions/${id}/finish`, { method: 'POST', body: JSON.stringify({}) });
            if (res.code !== 200) throw new Error(res.message || '结束策略动作失败');
            showToast('策略动作已结束');
            await loadOperationActions();
        } catch (error) {
            showToast(operationErrorMessage(error, '结束策略动作失败'), 'error');
        } finally {
            operationLoading.value.actions = false;
        }
    };

        const invalidateRequests = () => {
            operationActionsRequestSeq += 1;
            homeOperatingScheduleRequestSeq += 1;
            operatingMemoryRequestSeq += 1;
            aiDailyFactGateRequestSeq += 1;
            aiDailyReportRequestSeq += 1;
            clearOperationApprovalConfirmation();
        };

        return {
            testManualNotification, retryManualNotificationDispatch, loadOperationFullData, analyzeOperationRootCause,
            loadOperationAlerts, markOperationAlertsRead, openOperationAlertTask, createOperationAlertTask,
            simulateOperationStrategy, createOperationAction, loadAiDailyFactGate, loadAiDailyReport,
            generateAiDailyReport, readOperationExecutionIntent, collectPriceExecutionIntentFields, createAiDailyExecutionIntent,
            loadOperatingMemories, saveOperationExecutionMemory, loadHomeOperatingSchedule, openHomeOperatingScheduleItem,
            openHomeOperatingScheduleAll, loadOperationActions, operationApprovalText, operationRejectText,
            rejectOrCancelOperationApproval, approveOperationExecutionIntent, recordOperationRevenueNodeCheck, recordOperationExecutionEvidence,
            closeOperationEvidenceModal, submitOperationExecutionEvidence, recordOperationRoiEvidence, reviewOperationExecutionTask,
            reconcileOperationExecutionReview, closeOperationReviewModal, submitOperationExecutionReview, finishOperationAction,
            invalidateRequests,
        };
    };

    return {
        createOperationWorkflowController,
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
        operationCanRecordNodeCheck,
        operationCanReconcileExecution,
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
        operationRevenueNodeDialogFields,
        operationRevenueNodeFieldsForItem,
        buildOperationRevenueNodeRecord,
        operationExecutionNodeRecordText,
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
