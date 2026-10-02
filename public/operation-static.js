window.SUXI_OPERATION_STATIC = (() => {
    const buildOperatingTargetForm = (record, form) => {
        const facts = record.facts || {};
        return {
            ...form,
            hotel_id: String(record.hotel_id || form.hotel_id),
            target_date: record.target_date || form.target_date,
            target_revenue: facts.target_revenue ?? '',
            target_occupancy_rate_percent: '',
            target_revpar: '',
            actual_revenue: facts.actual_revenue ?? '',
            sold_room_nights: facts.sold_room_nights ?? '',
            sellable_room_nights: facts.sellable_room_nights ?? '',
            fact_scope: facts.fact_scope || '',
            source_type: facts.source_type || 'manual',
            source_reference: facts.source_reference || '',
            quality_status: facts.quality_status || 'unverified',
            quality_reason: facts.quality_reason || '',
            fact_captured_at: facts.fact_captured_at || '',
        };
    };
    const loadOperatingTargetPrefill = async (provider, context, request) => {
        const dailyReport = provider === 'daily-report';
        const label = dailyReport ? '经营日报' : provider === 'dingdandao' ? '订单来了' : '美团云 PMS';
        const res = await request(
            '/operating-targets/prefill/' + provider
            + '?hotel_id=' + encodeURIComponent(context.hotelId)
            + '&target_date=' + encodeURIComponent(context.targetDate)
        );
        if (res.code !== 200) throw new Error(res.message || label + '事实预填失败');
        const data = res.data || {};
        if (data.prefill && (data.prefill.target_date !== context.targetDate
            || (!dailyReport && (String(data.capture?.hotel_id || '') !== context.hotelId
                || data.capture?.business_date !== context.targetDate)))) {
            return { prefill: null, capture: null, error: 'PMS 回读范围不匹配，请核对同店当天事实。' };
        }
        return {
            prefill: data.prefill ? (dailyReport ? { ...data.prefill, quality_status: 'unverified' } : data.prefill) : null,
            capture: data.capture || null,
            keepTarget: !dailyReport || !Object.prototype.hasOwnProperty.call(data.prefill || {}, 'target_revenue'),
            error: (data.gaps || []).map(item => item.message).find(Boolean)
                || label + '事实尚未通过身份、日期、对账和回读门禁',
            message: dailyReport
                ? '已从同酒店同日期日报预填。未验证数据不能直接作为完整经营结论。'
                : '已核对' + label + '同店当天房费事实；目标金额也必须使用住宿房费口径。',
        };
    };
    const saveOperatingTargetRecord = async (form, context, request, savedRecord) => {
        const payload = {
            ...form,
            hotel_id: context.hotelId,
            target_date: context.targetDate,
            target_occupancy_rate_percent: null,
            target_revpar: null,
            fact_scope: form.fact_scope || 'accommodation_room_fee',
        };
        if (String(payload.source_type || '') !== 'pms') {
            Object.assign(payload, {
                actual_revenue: null,
                sold_room_nights: null,
                sellable_room_nights: null,
                source_type: 'manual',
                source_reference: '',
                quality_status: 'unverified',
            });
            const facts = savedRecord?.facts;
            if (Number(savedRecord?.id) > 0
                && String(savedRecord.hotel_id) === context.hotelId
                && savedRecord.target_date === context.targetDate
                && facts?.fact_scope === payload.fact_scope
                && facts.source_type === form.source_type) {
                // Preserve only the saved read-only facts, never edited manual values.
                for (const key of ['actual_revenue', 'sold_room_nights', 'sellable_room_nights',
                    'source_type', 'source_reference', 'quality_status', 'quality_reason', 'fact_captured_at']) {
                    payload[key] = facts[key] ?? null;
                }
            }
        }
        const res = await request('/operating-targets', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        if (res.code !== 200) throw new Error(res.message || '经营目标保存失败');
        const record = res.data?.record;
        if (String(record?.hotel_id || '') !== context.hotelId
            || record?.target_date !== context.targetDate) {
            throw new Error('保存回读未返回同店当天记录，请重新读取核对。');
        }
        return res.data;
    };
    const readOperatingTargetSnapshots = async (context, request) => {
        const res = await request(`/operating-targets/snapshots?hotel_id=${encodeURIComponent(context.hotelId)}&target_date=${encodeURIComponent(context.targetDate)}&limit=20`);
        if (res.code !== 200) throw new Error(res.message || '经营目标历史版本读取失败');
        const data = res.data;
        if (!Array.isArray(data?.list)
            || (data.target_date && data.target_date !== context.targetDate)
            || data.list.some(item => !item || String(item.hotel_id) !== String(context.hotelId)
                || item.target_date !== context.targetDate)) {
            throw new Error('历史版本未返回有效的同门店、同日期列表，请重试。');
        }
        return data;
    };
    const buildOperatingTargetMetricRows = (record, format) => {
        const facts = record?.facts || {};
        const metrics = record?.calculation?.metrics || {};
        const scope = facts.fact_scope;
        return [
            { key: 'target_revenue', label: scope === 'whole_hotel' ? '全酒店营收总目标' : scope === 'accommodation_room_fee' ? '住宿房费总目标' : '营收目标（口径未说明）', value: format(facts.target_revenue, 'money') },
            { key: 'actual_revenue', label: scope === 'whole_hotel' ? '全酒店实际营收' : scope === 'accommodation_room_fee' ? '实际住宿房费' : '实际营收（口径未说明）', value: format(facts.actual_revenue, 'money') },
            { key: 'completion_rate_percent', label: '营收完成率', value: format(metrics.completion_rate_percent, 'percent') },
            { key: 'remaining_revenue', label: '距离目标还差', value: format(metrics.remaining_revenue, 'money') },
        ];
    };
    const buildOperatingTargetPmsFactRows = (facts, format) => {
        const number = value => value === null || value === undefined || String(value).trim() === '' || !Number.isFinite(Number(value)) ? null : Number(value);
        const pms = facts.source_type === 'pms';
        const actual = pms ? number(facts.actual_revenue) : null;
        const sold = pms ? number(facts.sold_room_nights) : null;
        const sellable = pms ? number(facts.sellable_room_nights) : null;
        const roomFee = facts.fact_scope === 'accommodation_room_fee';
        return [
            { key: 'actual_revenue', label: facts.fact_scope === 'whole_hotel' ? '全酒店实际营收' : roomFee ? '实际住宿房费' : '实际营收（口径未说明）', value: actual, kind: 'money' },
            { key: 'sold_room_nights', label: '已售间夜', value: sold, kind: 'number' },
            { key: 'sellable_room_nights', label: '可售房夜', value: sellable, kind: 'number' },
            { key: 'occupancy_rate', label: '入住率', value: sold !== null && sellable > 0 ? sold / sellable * 100 : null, kind: 'percent' },
            { key: 'adr', label: 'ADR', value: roomFee && actual !== null && sold > 0 ? actual / sold : null, kind: 'moneyRate' },
            { key: 'revpar', label: 'RevPAR', value: roomFee && actual !== null && sellable > 0 ? actual / sellable : null, kind: 'money' },
        ].map(item => ({ ...item, available: item.value !== null, displayValue: item.value === null ? '未取得' : format(item.value, item.kind) }));
    };
    const operatingTargetTaskNavigation = (draft, form, isPermitted) => {
        const intent = draft?.execution_intent;
        const intentId = Number(intent?.id);
        const hotelId = String(intent?.hotel_id || '');
        if (!Number.isSafeInteger(intentId) || intentId <= 0 || !isPermitted(hotelId)
            || hotelId !== String(form.hotel_id || '').trim()
            || draft?.target?.target_date !== String(form.target_date || '').trim()
            || intent.date_start !== draft.target.target_date) return null;
        return { hotelId, intentId };
    };
    const openOperatingTargetTaskDraft = ([draft, form, filters, focus, page, error, stage], isPermitted, load, suppress) => {
        const target = operatingTargetTaskNavigation(draft.value, form.value, isPermitted);
        if (!target) { error.value = '请重新读取同店当天的目标任务。'; return; }
        const { hotelId, intentId } = target;
        filters.value.hotel_id = hotelId;
        stage.value = '';
        focus.value = { intentId };
        if (page.value !== 'ops-track') suppress();
        page.value = 'ops-track';
        return load({ focusIntentId: intentId });
    };
    const createOperatingTargetTaskDraft = async (context, record, loading, request) => {
        if (loading.current || loading.save || loading.prefill) {
            throw new Error('请等待当前经营目标读取、保存或PMS核对完成后再生成任务。');
        }
        if (!record || String(record.hotel_id) !== context.hotelId || record.target_date !== context.targetDate) {
            throw new Error('请先读取或保存同店当天经营目标，再生成任务。');
        }
        const res = await request('/operating-targets/task-draft', {
            method: 'POST',
            body: JSON.stringify({ hotel_id: context.hotelId, target_date: context.targetDate }),
        });
        if (res.code !== 200) throw new Error(res.message || '经营目标待审批任务创建失败');
        const data = res.data, target = data?.target, intent = data?.execution_intent;
        if (data?.status !== 'task_draft_ready' || !(Number(intent?.id) > 0)
            || String(intent.hotel_id) !== context.hotelId || Number(intent.tenant_id) !== Number(record.tenant_id)
            || target?.target_date !== context.targetDate || Number(target.record_id) !== Number(record.id)
            || Number(target.revision_no) !== Number(record.revision_no)
            || intent.source_module !== 'operating_target' || Number(intent.source_record_id) !== Number(record.id)
            || intent.date_start !== context.targetDate || intent.date_end !== context.targetDate) {
            throw new Error('任务回执与已读目标版本不一致，请重新读取核对；后台任务可能已生成。');
        }
        if (intent.status === 'blocked') {
            throw new Error('任务已保存但处于阻塞：' + (intent.blocked_reason || '阻塞原因未返回') + '。请核对后重试。');
        }
        const message = intent.status === 'pending_approval'
            ? (data.reused_existing_intent ? '已回读同一目标版本的待审批任务' : '待审批任务已生成')
            : '已回读目标任务，请进入任务执行与复盘核对当前状态';
        return { data: { ...data, status_message: message }, message };
    };
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

        const flags = Array.isArray(data.abnormal_flags) ? data.abnormal_flags : [];
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
                summary: `当前缺少或尚未核验：${missingModules.join('、')}。可先查看已有来源记录，补齐后再排查可能影响因素。`,
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
    const operationIsProtectedSystemAnalysis = (item) => item?.recommendation?.source_module === 'canonical_ota_investigation'
        || item?.execution?.mode === 'analysis_only'
        || item?.approval?.status === 'system_authorized_analysis';
    const operationIsManagedAction = (item) => ['operation_action_card.v1', 'operation_action_card.v2']
        .includes(String(item?.action_management?.contract_version || ''));
    const operationUsesIndependentAiReview = (item) => operationIsManagedAction(item)
        && String(item?.action_management?.action_card?.approval?.mode || '').trim().toLowerCase()
            === 'ai_independent_review';
    const operationCanApproveExecution = (item) => !operationIsProtectedSystemAnalysis(item)
        && !operationUsesIndependentAiReview(item)
        && item?.approval?.status === 'pending_approval';
    const operationCanExecuteWithEvidence = (item) => {
        if (operationIsProtectedSystemAnalysis(item)) return false;
        const status = item?.execution?.status || '';
        const isManagedAction = operationIsManagedAction(item);
        const canStartExecution = status === 'executing' || (status === 'pending_execute' && !isManagedAction);
        const canSupplementManualEvidence = status === 'executed'
            && item?.recommendation?.object_type !== 'price'
            && item?.next_action?.key === 'record_evidence';
        return (canStartExecution || canSupplementManualEvidence) && Number(item?.execution?.task_id || 0) > 0;
    };
    const operationCanRecordNodeCheck = (item) => !operationIsProtectedSystemAnalysis(item)
        && item?.recommendation?.source_module !== 'daily_one_thing'
        && ['pending_execute', 'executing', 'executed'].includes(item?.execution?.status || '')
        && Number(item?.execution?.task_id || 0) > 0;
    const operationHasTerminalReview = (item) => ['success', 'near_success', 'failed'].includes(item?.review?.reported_status || item?.review?.status || '');
    const operationCanReviewExecution = (item) => !operationIsProtectedSystemAnalysis(item) && item?.execution?.status === 'executed' && item?.review?.is_available !== false && !operationHasTerminalReview(item) && Number(item?.execution?.task_id || 0) > 0;
    const operationCanReconcileExecution = (item) => !operationIsProtectedSystemAnalysis(item)
        && item?.execution?.status === 'executed'
        && item?.review?.is_available === true
        && item?.evidence_truth?.source_verified !== true
        && ['ota_diagnosis_saved', 'operating_question', 'revenue_cockpit_action', 'daily_one_thing'].includes(item?.recommendation?.source_module)
        && !operationHasTerminalReview(item)
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
        if (!operationHasDisplayValue(summary.total)) return '流程尚未读取，暂不能判断';
        if (Number(summary.total) === 0) return '当前范围暂无流程，暂不能判断';
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
    const operationAiDailySourceTarget = (item) => {
        const positive = value => (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)))
            && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
        const recommendation = item?.recommendation;
        if (recommendation?.source_module !== 'ai_daily_report' || (item?.identity?.status && item.identity.status !== 'consistent')) return null;
        const reportId = positive(recommendation.source_record_id), hotelId = positive(item?.hotel_id), intentId = positive(item?.id);
        if (!reportId || !hotelId || !intentId) return null;
        const evidenceId = recommendation.evidence?.ai_daily_report_id;
        if (evidenceId !== undefined && positive(evidenceId) !== reportId) return null;
        return Object.freeze({ reportId, hotelId, intentId });
    };
    const createOperationAiDailySourceNavigation = (ctx) => {
        let active = null, pending = null;
        const owns = owner => active === owner && ctx.isReadCurrent(owner.readSeq);
        const rowCurrent = owner => ctx.items().some(item => {
            const target = operationAiDailySourceTarget(item);
            return target && target.intentId === owner.target.intentId && target.hotelId === owner.target.hotelId && target.reportId === owner.target.reportId;
        });
        const current = owner => owns(owner) && ctx.isAuthSessionCurrent(owner.session) && ctx.currentPage.value === 'ai-daily-report'
            && String(ctx.operationFilters.value.hotel_id || '') === owner.operationHotel
            && String(ctx.aiDailyReportForm.value.hotel_id || '') === owner.formHotel
            && ctx.isOperationHotelPermitted(owner.target.hotelId) && rowCurrent(owner);
        const clear = () => {
            if (active && owns(active)) ctx.operationLoading.value.aiDailyReport = false;
            active = null; pending = null; ctx.notice.value = null;
        };
        const open = item => {
            const target = operationAiDailySourceTarget(item), operationHotel = String(ctx.operationFilters.value.hotel_id || '');
            if (!target || ctx.currentPage.value !== 'ops-track' || !ctx.isOperationHotelPermitted(target.hotelId)
                || (operationHotel && Number(operationHotel) !== target.hotelId) || ctx.operationLoading.value.actions
                || ctx.operationLoading.value.aiDailyReport || ctx.aiDailyReportGenerationTaskPolling.value) return false;
            const owner = { target, operationHotel, session: ctx.captureAuthSession(), formHotel: String(ctx.aiDailyReportForm.value.hotel_id || ''), readSeq: 0 };
            if (!ctx.isAuthSessionCurrent(owner.session) || !rowCurrent(owner)) return false;
            owner.readSeq = ctx.acquireRead(); active = owner; pending = owner;
            ctx.operationLoading.value.aiDailyReport = true; ctx.operationError.value.aiDailyReport = '';
            ctx.notice.value = { status: 'loading', displayedReport: ctx.aiDailyReport.value,
                message: `正在读取来源日报 #${target.reportId}（酒店 #${target.hotelId}），当前显示尚未替换。` };
            ctx.currentPage.value = 'ai-daily-report';
            return true;
        };
        const read = async owner => {
            try {
                if (!current(owner)) return false;
                await ctx.prepare();
                if (!current(owner)) return false;
                const { reportId, hotelId } = owner.target;
                const response = await ctx.request(`/ai-daily-reports/${reportId}?hotel_id=${hotelId}`);
                if (!current(owner)) return false;
                if (response?.code !== 200) throw new Error(response?.message || '来源日报读取失败');
                const report = response.data?.report && typeof response.data.report === 'object' ? response.data.report : response.data;
                const positive = value => (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)))
                    && Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
                if (!report || Array.isArray(report) || positive(report.id) !== reportId || positive(report.hotel_id) !== hotelId) throw new Error('来源日报ID或酒店回读不一致');
                const date = report.report_date;
                if (typeof date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isFinite(Date.parse(date))
                    || new Date(date).toISOString().slice(0, 10) !== date) throw new Error('来源日报业务日期未正确返回');
                // The stored source ID identifies the report; its own date is authoritative.
                // An action's planned execution date is deliberately not used as an expected report date.
                ctx.aiDailyReport.value = report;
                ctx.aiDailyReportForm.value = { ...ctx.aiDailyReportForm.value, hotel_id: String(hotelId), report_date: date };
                ctx.aiDailyReportGenerationTask.value = null; ctx.operationError.value.aiDailyReport = '';
                ctx.notice.value = { status: 'ready', displayedReport: ctx.aiDailyReport.value,
                    message: `已读取来源日报 #${reportId} · 酒店 #${hotelId} · 日报日期 ${date}；来源质量以报告原标记为准。` };
                return true;
            } catch (error) {
                if (current(owner)) {
                    const message = `来源日报 #${owner.target.reportId} 未能读取，当前显示未替换：${error?.message || '请返回原行动重试'}`;
                    ctx.notice.value = { status: 'error', displayedReport: ctx.aiDailyReport.value, message };
                }
                return false;
            } finally {
                if (owns(owner)) {
                    ctx.operationLoading.value.aiDailyReport = false;
                    if (ctx.notice.value?.status === 'loading') {
                        ctx.notice.value = ctx.currentPage.value === 'ai-daily-report' && ctx.isAuthSessionCurrent(owner.session)
                            ? { ...ctx.notice.value, status: 'error', message: '来源范围已变化，当前显示未替换，请返回原行动重试。' } : null;
                    }
                    active = null;
                }
            }
        };
        const consume = () => { const owner = pending; pending = null; return owner ? read(owner) : null; };
        return Object.freeze({ open, consume, clear });
    };
    const operationExecutionSourceText = (item) => {
        const source = item?.recommendation?.source || '';
        const resolved = item?.recommendation?.source_module || (source && !source.endsWith('#0') ? source : '');
        const sourceKey = String(resolved).toLowerCase();
        if (sourceKey === 'manual') return '人工创建';
        if (sourceKey === 'operating_target' || sourceKey.startsWith('operating_target#')) return '经营目标差距任务';
        if (sourceKey.startsWith('canonical_ota_investigation')) {
            const sourceRecordId = Number(item?.recommendation?.source_record_id || 0);
            const platform = String(item?.recommendation?.platform || '').trim().toLowerCase();
            const platformText = platform === 'meituan' ? '美团' : '携程';
            return sourceRecordId > 0 ? `${platformText}权威数据核查 · 源行 #${sourceRecordId}` : `${platformText}权威数据核查`;
        }
        if (sourceKey.startsWith('ota_diagnosis_saved')) return 'OTA诊断行动';
        if (sourceKey.startsWith('operating_question')) return '真实经营问题行动';
        if (sourceKey.startsWith('daily_one_thing')) return '每日一件事';
        if (sourceKey.startsWith('daily_workbench_patrol')) return '巡检补证任务';
        if (sourceKey.startsWith('ota_diagnosis')) return '历史OTA诊断行动';
        if (sourceKey.startsWith('temporal_forecast_recommendation')) return '预测运营建议';
        return resolved || '来源未返回';
    };
    const operationExecutionActionText = (item, helpers = {}) => {
        const actionCard = item?.action_management?.action_card || {};
        const cardTitle = String(actionCard?.action?.title || '').trim();
        if (cardTitle) return cardTitle;
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
            list_detail_math_check: '列表到详情转化数学核查',
            detail_fill_breakpoint_check: '详情到填单断点核查',
            fill_submit_chain_check: '填单到提交链路核查',
            meituan_list_detail_count_order_check: '列表与详情数量次序核查',
            meituan_list_detail_rate_check: '列表到详情率核查',
            meituan_observed_flow_rate_alignment_check: '观测流量率一致性核查',
            same_scope_recollection_eligibility_check: '同范围重采与准入核查',
        }[actionType] || strategyTypeLabel(actionType));
        return `${objectText} · ${actionText}`;
    };
    const operationExecutionReviewText = (item, helpers = {}) => {
        const managedReview = item?.action_management?.latest_review;
        if (managedReview && typeof managedReview === 'object') {
            const sufficiency = ({ sufficient: '证据充分', insufficient: '证据不足', mismatched: '口径不匹配' }[
                String(managedReview.evidence_sufficiency || '')
            ] || '证据待核验');
            const change = ({ increased: '指标上升', decreased: '指标下降', unchanged: '指标未变', unknown: '变化未知' }[
                String(managedReview.metric_change_status || '')
            ] || '变化未知');
            const recommendation = ({ continue: '建议继续', adjust: '建议调整', stop: '建议停止' }[
                String(managedReview.recommendation || '')
            ] || '建议待定');
            const outcome = item?.outcome_truth || {};
            const outcomeStatus = String(outcome.status || 'unverified');
            const outcomeLabel = ({
                met: '达到目标（met）',
                near: '接近目标（near）',
                missed: '未达目标（missed）',
                adverse: '反向变化（adverse）',
            }[outcomeStatus] || `未核验（${outcomeStatus}）`);
            const effectReviewId = Number(managedReview.effect_review_id || 0);
            const actualDelta = outcome.actual_delta ?? managedReview.delta_value ?? '—';
            const causality = managedReview.causality_claimed === false
                ? '否（仅观察）'
                : '不满足复盘边界';
            return [
                `${sufficiency} · ${change} · ${recommendation}`,
                `严格复盘 ${effectReviewId > 0 ? `#${effectReviewId}` : '未形成'} · 管理复盘 #${managedReview.id || '—'}`,
                `审批冻结指标：${managedReview.metric_key || '未取得'} · ${managedReview.metric_unit || '单位缺失'}`,
                `前值 ${managedReview.before_value ?? '—'} → 后值 ${managedReview.after_value ?? '—'} · 实际变化 ${actualDelta}`,
                `确定性结果：${outcomeLabel} · 因果归因：${causality}`,
            ].join('\n');
            return `${sufficiency} · ${change} · ${recommendation}`;
        }
        const review = item?.review || {};
        const statusLabel = typeof helpers.statusLabel === 'function' ? helpers.statusLabel : (status => status || '-');
        const label = statusLabel(review.status);
        return review.summary ? `${label} · ${review.summary}` : label;
    };
    const operationUniqueTextValues = (values = []) => {
        const seen = new Set();
        return values.reduce((result, value) => {
            const text = String(value ?? '').trim();
            if (!text || seen.has(text)) return result;
            seen.add(text);
            result.push(text);
            return result;
        }, []);
    };
    const operationParseCsvValues = (value) => operationUniqueTextValues(
        Array.isArray(value) ? value : String(value ?? '').split(/[,，]/)
    );
    const operationParseLineValues = (value) => operationUniqueTextValues(
        Array.isArray(value) ? value : String(value ?? '').split(/\r?\n/)
    );
    const buildOperatingGoalContractPayload = (form = {}) => {
        const fieldValue = (...names) => {
            for (const name of names) {
                if (Object.prototype.hasOwnProperty.call(form, name)) return form[name];
            }
            return undefined;
        };
        const requiredText = (label, ...names) => {
            const value = String(fieldValue(...names) ?? '').trim();
            if (!value) throw new Error(`请填写${label}`);
            return value;
        };
        const requiredNumber = (label, names, { min = null, max = null, integer = false, exclusiveMin = false } = {}) => {
            const raw = fieldValue(...names);
            if (raw === null || raw === undefined || String(raw).trim() === '') {
                throw new Error(`请填写${label}`);
            }
            const value = Number(raw);
            const belowMinimum = min !== null && (exclusiveMin ? value <= min : value < min);
            if (!Number.isFinite(value) || belowMinimum || (max !== null && value > max) || (integer && !Number.isInteger(value))) {
                throw new Error(`${label}范围无效`);
            }
            return value;
        };
        const requiredDate = (label, name) => {
            const value = requiredText(label, name);
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
            if (!match) throw new Error(`${label}格式无效`);
            const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
            if (date.getUTCFullYear() !== Number(match[1])
                || date.getUTCMonth() !== Number(match[2]) - 1
                || date.getUTCDate() !== Number(match[3])) {
                throw new Error(`${label}格式无效`);
            }
            return value;
        };

        const primaryObjective = requiredText('首要目标', 'primary_objective').toLowerCase();
        const objectiveMetricMap = { revenue: 'revenue', profit: 'profit', cash_flow: 'cash_flow' };
        if (!Object.prototype.hasOwnProperty.call(objectiveMetricMap, primaryObjective)) {
            throw new Error('首要目标无效');
        }
        const primaryMetricKey = String(fieldValue('primary_metric_key') ?? '').trim()
            || objectiveMetricMap[primaryObjective];
        const objectiveDirection = requiredText('目标方向', 'objective_direction').toLowerCase();
        if (!['increase', 'preserve'].includes(objectiveDirection)) throw new Error('目标方向无效');
        const riskPreference = requiredText('风险偏好', 'risk_preference').toLowerCase();
        if (!['conservative', 'balanced', 'aggressive'].includes(riskPreference)) throw new Error('风险偏好无效');

        const adrMinimum = requiredNumber('ADR保护阈值', ['adr_min', 'adr_guard_min', 'guard_adr_min'], { min: 0, exclusiveMin: true });
        const occupancyMinimum = requiredNumber('入住率保护阈值', ['occupancy_rate_min', 'occupancy_min', 'guard_occupancy_rate_min'], { min: 0, max: 100 });
        const ratingMinimum = requiredNumber('评分保护阈值', ['rating_min', 'score_min', 'guard_rating_min'], { min: 0, max: 5, exclusiveMin: true });
        const cancellationMaximum = requiredNumber('取消率保护阈值', ['cancellation_rate_max', 'cancel_rate_max', 'guard_cancellation_rate_max'], { min: 0, max: 100 });
        const minimumRoomRate = requiredNumber('最低价', ['minimum_room_rate', 'minimum_price', 'min_price'], { min: 0, exclusiveMin: true });
        const availableRoomCount = requiredNumber('保底可售库存', ['available_room_count', 'minimum_inventory', 'min_inventory'], { min: 0, integer: true });
        const roomTypes = operationParseCsvValues(fieldValue('room_types_csv', 'room_types'));
        if (!roomTypes.length) throw new Error('请填写房型范围');
        const channels = operationParseCsvValues(fieldValue('channels_csv', 'channels'));
        if (!channels.length) throw new Error('请填写渠道范围');
        const stopConditions = operationParseLineValues(fieldValue('stop_conditions_text', 'stop_conditions'));
        if (!stopConditions.length) throw new Error('请填写停止条件');
        const effectiveFrom = requiredDate('生效日期', 'effective_from');
        const effectiveTo = requiredDate('截止日期', 'effective_to');
        if (effectiveTo < effectiveFrom) throw new Error('截止日期不能早于生效日期');

        return {
            primary_objective: primaryObjective,
            primary_metric_key: primaryMetricKey,
            objective_direction: objectiveDirection,
            guard_metrics: [
                { metric_key: 'adr', operator: '>=', threshold: adrMinimum },
                { metric_key: 'occupancy_rate', operator: '>=', threshold: occupancyMinimum },
                { metric_key: 'rating', operator: '>=', threshold: ratingMinimum },
                { metric_key: 'cancellation_rate', operator: '<=', threshold: cancellationMaximum },
            ],
            operating_constraints: [
                { constraint_key: 'minimum_room_rate', operator: '>=', value: minimumRoomRate },
                { constraint_key: 'available_room_count', operator: '>=', value: availableRoomCount },
                { constraint_key: 'room_types', operator: 'in', value: roomTypes },
                { constraint_key: 'channels', operator: 'in', value: channels },
            ],
            risk_preference: riskPreference,
            operating_phase: requiredText('经营阶段', 'operating_phase'),
            phase_note: String(fieldValue('phase_note') ?? '').trim(),
            stop_conditions: stopConditions,
            rollback_plan: requiredText('回滚方案', 'rollback_plan'),
            effective_from: effectiveFrom,
            effective_to: effectiveTo,
            version_note: String(fieldValue('version_note') ?? '').trim(),
        };
    };
    const operatingGoalContractText = (contract = null) => {
        if (!contract || typeof contract !== 'object') return '未设置经营目标合同';
        const version = Number(contract.version_no || contract.version || 0);
        const objective = String(contract.primary_objective || '').trim();
        const objectiveText = ({ revenue: '收入', profit: '利润', cash_flow: '现金流' }[objective] || objective || '首要目标未返回');
        const phase = String(contract.operating_phase || '').trim() || '阶段未返回';
        const from = String(contract.effective_from || '').trim();
        const to = String(contract.effective_to || '').trim();
        const effectivePeriod = from && to ? `${from}~${to}` : (from || to || '有效期未返回');
        return `${version > 0 ? `v${version}` : 'v-'} · ${objectiveText} · ${phase} · ${effectivePeriod}`;
    };
    const operatingGoalMonitorStatusModel = (overview = {}, selectedHotelId = 0) => {
        const hotelId = Number(selectedHotelId || 0);
        if (!hotelId) {
            return {
                state: 'inactive',
                label: '选择酒店后监控',
                detail: '请选择单店，系统才会锁定酒店身份与经营日。',
                className: 'border-slate-200 bg-slate-50 text-slate-600',
                iconClass: 'fas fa-pause-circle',
            };
        }
        const responseHotelId = Number(overview?.hotel_id || 0);
        if (responseHotelId > 0 && responseHotelId !== hotelId) {
            return {
                state: 'unknown',
                label: '监控身份不一致',
                detail: '返回结果不属于当前酒店，已拒绝显示为运行中。',
                className: 'border-rose-200 bg-rose-50 text-rose-700',
                iconClass: 'fas fa-exclamation-triangle',
            };
        }
        const monitor = overview?.monitor && typeof overview.monitor === 'object'
            ? overview.monitor
            : {};
        const status = String(monitor.status || '').trim().toLowerCase();
        const state = String(monitor.monitor_state || '').trim().toLowerCase();
        const observedAt = String(monitor.last_observed_at || '').trim();
        const businessDate = String(monitor.business_date || '').trim();
        const gaps = Array.isArray(monitor.data_gaps) ? monitor.data_gaps.filter(Boolean) : [];
        if (overview?.migration_required === true || status === 'migration_required') {
            return {
                state: 'inactive',
                label: '智能监控待启用',
                detail: '监控账本迁移尚未应用，当前没有后台心跳。',
                className: 'border-amber-200 bg-amber-50 text-amber-800',
                iconClass: 'fas fa-tools',
            };
        }
        if (status === 'not_run' || !status) {
            return {
                state: 'inactive',
                label: '智能监控未运行',
                detail: '目标可保存，但尚未回读到后台定时监控心跳。',
                className: 'border-slate-200 bg-slate-50 text-slate-600',
                iconClass: 'fas fa-clock',
            };
        }
        if (status !== 'ready') {
            return {
                state: 'unknown',
                label: '监控状态未取得',
                detail: '没有足够回读证明监控正在工作。',
                className: 'border-slate-200 bg-slate-50 text-slate-600',
                iconClass: 'fas fa-question-circle',
            };
        }
        const heartbeat = [businessDate ? `经营日 ${businessDate}` : '', observedAt ? `最近核验 ${observedAt}` : '']
            .filter(Boolean)
            .join(' · ');
        if (state === 'attention') {
            return {
                state,
                label: '智能监控 · 有待处理',
                detail: `${heartbeat || '已有监控心跳'}${gaps.length ? ` · ${gaps.length} 个数据缺口` : ''}`,
                className: 'border-rose-200 bg-rose-50 text-rose-700',
                iconClass: 'fas fa-exclamation-circle',
            };
        }
        if (state === 'monitoring') {
            return {
                state,
                label: '智能监控运行中',
                detail: heartbeat || '后台已持续读取目标、保护指标与干预观察窗。',
                className: 'border-blue-200 bg-blue-50 text-blue-700',
                iconClass: 'fas fa-satellite-dish',
            };
        }
        return {
            state: 'inactive',
            label: '智能监控未激活',
            detail: heartbeat || '当前没有有效目标合同或监控边界。',
            className: 'border-slate-200 bg-slate-50 text-slate-600',
            iconClass: 'fas fa-pause-circle',
        };
    };
    const operationLearningVerdictLabel = (verdict) => ({
        supported: '证据支持',
        contradicted: '证据反驳',
        indeterminate: '证据不足',
    }[String(verdict || '').trim().toLowerCase()] || '未判定');
    const operationLearningVerdictClass = (verdict) => ({
        supported: 'bg-green-50 text-green-700',
        contradicted: 'bg-red-50 text-red-700',
        indeterminate: 'bg-amber-50 text-amber-700',
    }[String(verdict || '').trim().toLowerCase()] || 'bg-gray-50 text-gray-600');
    const operationInterventionLearningModel = (item = {}, overview = {}) => {
        const intentId = Number(item?.id || item?.intent_id || 0);
        const matches = (Array.isArray(overview?.interventions) ? overview.interventions : [])
            .filter(intervention => intentId > 0 && Number(intervention?.intent_id || 0) === intentId)
            .sort((left, right) => {
                const versionDelta = Number(right?.version_no || 0) - Number(left?.version_no || 0);
                if (versionDelta !== 0) return versionDelta;
                const idDelta = Number(right?.id || 0) - Number(left?.id || 0);
                if (idDelta !== 0) return idDelta;
                return String(right?.created_at || '').localeCompare(String(left?.created_at || ''));
            });
        const intervention = matches[0] || null;
        const assessment = intervention?.latest_assessment && typeof intervention.latest_assessment === 'object'
            ? intervention.latest_assessment
            : null;
        const verdict = String(assessment?.verdict || intervention?.verdict || '').trim().toLowerCase();
        const summary = String(assessment?.result_summary || intervention?.result_summary || '').trim()
            || (!intervention
                ? '尚未登记经营干预'
                : '干预已登记，系统正在读取观察窗并等待自动判定');
        return {
            contract_status: String(intervention?.contract_status || intervention?.design_timing || '').trim(),
            verdict,
            label: operationLearningVerdictLabel(verdict),
            className: operationLearningVerdictClass(verdict),
            summary,
        };
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
        const evidenceSummary = item?.evidence_summary || {};
        const evidenceCount = Number(evidenceSummary.count ?? (Array.isArray(item?.evidence) ? item.evidence.length : 0));
        const evidenceTypes = Array.isArray(evidenceSummary.types)
            ? evidenceSummary.types.map(value => String(value || '').trim()).filter(Boolean)
            : [];
        const evidenceParts = [];
        if (Number.isInteger(evidenceCount) && evidenceCount > 0) {
            evidenceParts.push(`执行证据 ${evidenceCount} 条`);
        }
        if (evidenceTypes.length) {
            evidenceParts.push(`类型：${evidenceTypes.join('、')}`);
        }
        if (item?.evidence_truth?.source_verified === true) {
            evidenceParts.push('同口径来源事实已核验');
        }
        const node = item?.evidence_summary?.node_record || {};
        if (node.status === 'identity_mismatch') {
            return [...evidenceParts, '节点检查身份不一致，已拒绝回填'].join('；');
        }
        if (node.status !== 'available') {
            return [...evidenceParts, '节点检查未记录'].join('；');
        }
        const period = ({ weekday: '周内', weekend: '周末', holiday: '节假日', special_event: '特殊事件' }[node.operating_period] || '周期未回读');
        const alignment = ({ operator_confirmed: '房态人工确认一致', mismatch: '房态不一致', unverified: '房态未核验' }[node.room_status_alignment] || '房态状态未回读');
        const progress = ({ normal: '进度正常', too_fast: '进度过快', too_slow: '进度过慢', insufficient_evidence: '证据不足' }[node.progress_status] || '进度未判断');
        return [...evidenceParts, `${period} · ${alignment} · ${progress}`].join('；');
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
                label: '行动评审',
                value: total ? `${approved}/${total}` : '待评审',
                className: approved ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                detail: '所有受管行动都由用户主动二次确认；AI 只能提供建议，不能批准、建任务或执行外部操作。',
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
    const buildOperationStrategyEvidence = (baseline = {}, form = {}, hotels = []) => {
        const has = value => value !== null && value !== undefined && String(value).trim() !== '';
        const hotelId = String(form?.hotel_id || '').trim();
        const hotelName = (hotels || []).find(item => String(item?.id || '') === hotelId)?.name;
        const date = value => has(value) ? String(value) : '日期未返回';
        const scope = identity => {
            const platform = { ctrip: '携程', meituan: '美团', qunar: '去哪儿' }[identity.platform] || identity.platform || '平台未返回';
            const name = identity.scope === 'whole_hotel_daily_report' ? '全店日报'
                : identity.scope === 'ota_channel' ? `${platform}渠道` : `范围${identity.scope || '未返回'}`;
            const source = `来源${identity.source || '未返回'}`;
            const grain = identity.measurement_grain === 'daily_average' ? '日均'
                : `口径${identity.measurement_grain || '未返回'}`;
            return `${name} · ${source} · ${grain}`;
        };
        const names = { orders: '订单', revenue: '收入', room_nights: '间夜', conversion: '转化率' };
        const identities = baseline?.metric_identities || {};
        const sampleDays = baseline?.metric_sample_days || {};
        const requestedDays = baseline?.days;
        const dataGaps = Array.isArray(baseline?.data_gaps) ? baseline.data_gaps : [];
        return {
            hotel: hotelId ? `${hotelName || `酒店 ID ${hotelId}（名称未返回）`}（ID ${hotelId}）` : '酒店未确认',
            planDates: `${date(form?.start_date)} 至 ${date(form?.end_date)}`,
            baselineDates: `${date(baseline?.window_start_date)} 至 ${date(baseline?.window_end_date)}`,
            coverage: has(baseline?.actual_days) && has(baseline?.days)
                ? `${baseline.actual_days}/${baseline.days} 天` : '覆盖未返回',
            status: { ok: '完整', partial: '部分', missing: '缺失', read_failed: '读取失败', migration_required: '待初始化' }[baseline?.data_status]
                || baseline?.data_status || '状态未返回',
            metrics: Object.entries(names).map(([key, label]) => ({
                label,
                detail: `${Array.isArray(identities[key]) && identities[key].length
                    ? identities[key].map(scope).join('；') : '范围与来源未返回'} · ${has(sampleDays[key]) && has(requestedDays)
                    ? `样本 ${sampleDays[key]}/${requestedDays} 天` : '样本未返回'}`,
            })),
            gaps: dataGaps.length
                ? dataGaps.map(gap => String(gap?.message || gap?.code || '缺口详情未返回'))
                : (baseline?.data_status && baseline.data_status !== 'ok' ? ['缺口详情未返回'] : []),
        };
    };
    const createOperationActionFinishController = (context) => {
        const { actions, loading, request, load, captureAuthSession, isAuthSessionCurrent,
            currentHotel, currentPage, showToast, errorMessage } = context;
        const inFlight = new Set();
        const finish = async (action) => {
            const id = Number(action?.id || 0), hotelId = Number(action?.hotel_id || 0);
            const selectedHotel = String(currentHotel() || '').trim();
            const visible = actions.value.find(row => Number(row?.id || 0) === id);
            if (!Number.isInteger(id) || id <= 0 || !Number.isInteger(hotelId) || hotelId <= 0
                || !visible || Number(visible.hotel_id || 0) !== hotelId || visible.status === 'finished'
                || (selectedHotel && selectedHotel !== String(hotelId)) || currentPage() !== 'ops-track') {
                showToast('策略动作与当前门店或已读记录不一致，请刷新后核实', 'warning');
                return false;
            }
            if (loading.value.actions || inFlight.has(id)) return false;
            const session = captureAuthSession(), page = currentPage();
            const isCurrent = () => isAuthSessionCurrent(session) && currentPage() === page
                && String(currentHotel() || '').trim() === selectedHotel;
            inFlight.add(id);
            loading.value.actions = true;
            let writeAccepted = false;
            try {
                const res = await request(`/operation/actions/${id}/finish`, { method: 'POST', body: JSON.stringify({}) });
                if (res?.code !== 200) throw new Error(res?.message || '结束策略动作失败');
                writeAccepted = true;
                if (Number(res.data?.id || 0) !== id) throw new Error('结束回执与原策略动作不一致');
                if (!isCurrent()) return false;
                const loaded = await load();
                if (!isCurrent()) return false;
                const saved = actions.value.find(row => Number(row?.id || 0) === id
                    && Number(row?.hotel_id || 0) === hotelId && row.status === 'finished');
                if (loaded !== true || !saved) {
                    showToast('结束请求已提交，但当前门店未能精确回读；请刷新核实', 'warning');
                    return false;
                }
                showToast('策略动作已结束');
                return true;
            } catch (error) {
                if (isCurrent()) {
                    if (writeAccepted) showToast('结束请求已提交，但回执或回读未确认；请刷新核实', 'warning');
                    else showToast(errorMessage(error, '结束策略动作失败'), 'error');
                }
                return false;
            } finally {
                inFlight.delete(id);
                if (isCurrent()) loading.value.actions = false;
            }
        };
        return { finish };
    };
    const createOperationStrategyController = (context) => {
        const { strategyForm, operationStrategyResult, operationLoading, operationError,
            normalizeHotel, request, captureAuthSession, isAuthSessionCurrent, showToast, errorMessage } = context;
        let sequence = 0;
        const invalidate = () => {
            sequence += 1;
            operationStrategyResult.value = null;
            operationError.value.strategy = '';
            operationLoading.value.strategy = false;
        };
        const fail = (message) => {
            operationError.value.strategy = message;
            showToast(message, 'error');
            return null;
        };
        const isBlank = value => value === null || value === undefined || String(value).trim() === '';
        const buildPayload = hotelId => {
            const form = strategyForm.value || {};
            const startDate = String(form.start_date || '').trim();
            const endDate = String(form.end_date || '').trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(startDate) || !/^\d{4}-\d{2}-\d{2}$/.test(endDate)) {
                return fail('请选择有效的开始日期和结束日期');
            }
            if (startDate > endDate) return fail('结束日期不能早于开始日期');
            const payload = { hotel_id: hotelId, strategy_type: form.strategy_type, start_date: startDate, end_date: endDate };
            if (form.strategy_type === 'price_adjust') {
                const amount = Number(form.adjust_amount);
                if (isBlank(form.adjust_amount) || !Number.isFinite(amount) || amount === 0) return fail('调价金额必填且不能为 0');
                payload.adjust_amount = amount;
            }
            if (form.strategy_type === 'promotion') {
                const discountRate = Number(form.discount_rate);
                if (isBlank(form.discount_rate) || !Number.isFinite(discountRate) || discountRate <= 0 || discountRate > 100) return fail('折扣比例必填，范围为 0-100');
                payload.discount_rate = discountRate;
            }
            return payload;
        };
        const simulate = async () => {
            invalidate();
            const hotelId = normalizeHotel();
            if (hotelId === null) return;
            const payload = buildPayload(hotelId);
            if (!payload) return;
            const owner = ++sequence, session = captureAuthSession();
            const current = () => owner === sequence && isAuthSessionCurrent(session);
            operationLoading.value.strategy = true;
            try {
                const res = await request('/operation/strategy-simulation', { method: 'POST', body: JSON.stringify(payload) });
                if (!current()) return;
                if (res.code !== 200) throw new Error(res.message || '策略模拟失败');
                operationStrategyResult.value = res.data || null;
                if (res.data?.simulated === false) showToast('未生成规则情景，请查看数据缺口', 'warning');
                else showToast('策略模拟已完成');
            } catch (error) {
                if (!current()) return;
                operationError.value.strategy = errorMessage(error, '策略模拟失败');
                showToast(operationError.value.strategy, 'error');
            } finally {
                if (owner === sequence) operationLoading.value.strategy = false;
            }
        };
        return { invalidate, simulate };
    };
    const createOpeningProjectDataController = (context) => {
        const { selectedOpeningProjectId, openingOverview, openingTasks, openingLoading,
            request, captureAuthSession, isAuthSessionCurrent, showToast,
            clearSelectedOpeningTasks, pruneSelectedOpeningTaskIds } = context;
        let revision = 0;
        let busyOwner = null;
        const requests = {};
        const capture = () => ({ projectId: String(selectedOpeningProjectId.value || ''), revision, session: captureAuthSession() });
        const isCurrent = (owner) => !!owner && owner.revision === revision
            && owner.projectId === String(selectedOpeningProjectId.value || '') && isAuthSessionCurrent(owner.session)
            && (!owner.channel || requests[owner.channel] === owner.sequence);
        const invalidate = () => { revision += 1; };
        const start = (channel) => ({ ...capture(), channel, sequence: requests[channel] = (requests[channel] || 0) + 1 });
        const startBusy = (channel) => {
            const owner = start(channel);
            busyOwner = owner;
            openingLoading.value = true;
            return owner;
        };
        const finishBusy = (owner) => {
            if (busyOwner !== owner) return;
            busyOwner = null;
            openingLoading.value = false;
        };
        const invalidateReads = () => { start('overview'); start('tasks'); };
        const read = async (kind) => {
            const owner = start(kind);
            if (!owner.projectId) return false;
            const label = kind === 'tasks' ? '开业检查清单加载失败' : '开业总览加载失败';
            try {
                const res = await request(`/opening/projects/${owner.projectId}/${kind}`);
                if (!isCurrent(owner)) return false;
                if (res.code !== 200) throw new Error(res.message || label);
                if (kind === 'tasks') {
                    openingTasks.value = res.data?.list || [];
                    pruneSelectedOpeningTaskIds();
                } else openingOverview.value = res.data;
                return true;
            } catch (error) {
                if (isCurrent(owner)) showToast(label + '：' + (error.message || '网络错误'), 'error');
                return false;
            }
        };
        const loadOverview = () => read('overview');
        const loadTasks = () => read('tasks');
        const updateTask = async (task, options = {}) => {
            const owner = options.owner || capture();
            if (!isAuthSessionCurrent(owner.session)) return false;
            try {
                const payload = buildOpeningTaskUpdatePayload(task);
                const res = await request(`/opening/tasks/${task.id}`, { method: 'PUT', body: JSON.stringify(payload) });
                if (res.code !== 200) throw new Error(res.message || '检查项更新失败');
                if (!isCurrent(owner)) return true;
                Object.assign(task, res.data || {});
                if (options.refreshOverview) await (context.loadOpeningOverview || loadOverview)();
                return true;
            } catch (error) {
                if (isCurrent(owner)) showToast('检查项更新失败：' + (error.message || '网络错误'), 'error');
                return false;
            }
        };
        const saveTaskProgress = async (task) => {
            if (!task) return;
            if (openingTaskProgressPercent(task) === null) {
                showToast('请先选择进度百分比', 'warning');
                return;
            }
            const owner = capture();
            syncOpeningTaskStatusByProgress(task);
            const saved = await updateTask(task, { refreshOverview: true, owner });
            if (saved && isCurrent(owner)) showToast('进度已保存');
        };
        const generateTasks = async (options = {}) => {
            const silent = !!options.silent;
            if (!selectedOpeningProjectId.value) {
                if (!silent) showToast('请先选择开业项目', 'error');
                return false;
            }
            const owner = startBusy('generate');
            invalidateReads();
            try {
                const res = await request(`/opening/projects/${owner.projectId}/generate-tasks`, { method: 'POST' });
                if (!isCurrent(owner)) return false;
                if (res.code !== 200) throw new Error(res.message || '生成开业检查清单失败');
                invalidateReads();
                openingTasks.value = res.data?.tasks || [];
                openingOverview.value = res.data?.overview || openingOverview.value;
                clearSelectedOpeningTasks();
                if (!silent) showToast(res.message || '开业检查清单已生成');
                return true;
            } catch (error) {
                if (isCurrent(owner) && !silent) showToast('生成开业检查清单失败：' + (error.message || '网络错误'), 'error');
                return false;
            } finally { finishBusy(owner); }
        };
        const recalculate = async () => {
            if (!selectedOpeningProjectId.value) { showToast('请先选择开业项目', 'error'); return; }
            const owner = startBusy('recalculate');
            invalidateReads();
            try {
                const res = await request(`/opening/projects/${owner.projectId}/recalculate`, { method: 'POST' });
                if (!isCurrent(owner)) return;
                if (res.code !== 200) throw new Error(res.message || '评分刷新失败');
                invalidateReads();
                openingOverview.value = res.data;
                await loadTasks();
                if (isCurrent(owner)) showToast('开业准备评分已刷新');
            } catch (error) {
                if (isCurrent(owner)) showToast('评分刷新失败：' + (error.message || '网络错误'), 'error');
            } finally { finishBusy(owner); }
        };
        return { capture, isCurrent, invalidate, start, startBusy, finishBusy, loadOverview, loadTasks, updateTask, saveTaskProgress, generateTasks, recalculate };
    };
    const buildOpeningTaskProgressCards = (stats = {}) => [
        {
            label: '任务进度均值',
            value: stats.averageProgress == null ? (stats.total ? '待补齐' : '—') : `${stats.averageProgress}%`,
            hint: stats.total > 0 ? `${stats.progressRecorded}/${stats.total} 项已填报${stats.recordedAverageProgress == null ? '' : `，已填报均值 ${stats.recordedAverageProgress}%`}` : '暂无检查项',
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
            { label: '未填报', count: stats.progressMissing, percent: Math.round(stats.progressMissing / total * 100), className: 'text-gray-700', barClass: 'bg-gray-400' },
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
        if (value == null || (typeof value !== 'number' && typeof value !== 'string') || String(value).trim() === '') return null;
        const number = Number(value);
        if (!Number.isFinite(number)) return null;
        return Math.max(0, Math.min(100, Math.round(number)));
    };
    const openingTaskProgressPercent = (task) => clampOpeningTaskProgress(task?.progress_percent);
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
        if (progress === null) return '未填报';
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
        if (task.progress_percent === null) return;
        if (task.progress_percent >= 100) {
            task.status = 'done';
        } else if (task.progress_percent > 0 && (!task.status || task.status === 'todo' || task.status === 'done')) {
            task.status = 'doing';
        } else if (task.progress_percent === 0 && task.status !== 'blocked') {
            task.status = 'todo';
        }
    };
    const buildOpeningTaskUpdatePayload = (task = {}) => {
        const progress = openingTaskProgressPercent(task);
        const payload = {
            owner_name: task.owner_name || '',
            collaborator_name: task.collaborator_name || '',
            deadline: task.deadline || '',
            status: task.status || 'todo',
            remark: task.remark || '',
        };
        if (progress !== null) payload.progress_percent = progress;
        // Sending done is an explicit completion request; ordinary edits must preserve legacy missing progress.
        else if (payload.status === 'done') delete payload.status;
        return payload;
    };
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
        const recorded = rows.map(openingTaskProgressPercent).filter(progress => progress !== null);
        const progressRecorded = recorded.length;
        const progressMissing = total - progressRecorded;
        const recordedAverageProgress = progressRecorded ? Math.round(recorded.reduce((sum, progress) => sum + progress, 0) / progressRecorded) : null;
        const averageProgress = progressMissing ? null : recordedAverageProgress;
        const progressEmpty = count(task => openingTaskProgressPercent(task) === 0);
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
        return { total, done, doing, todo, blocked, highRisk, overdue, dueSoon, core, noOwner, completionRate, averageProgress, recordedAverageProgress, progressRecorded, progressMissing, progressEmpty, progressLow, progressHigh, progressDone };
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
        return openingTaskProgressPercent(task);
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
        const progress = openingAiTaskProgressPercent(task, helpers);
        if (progress !== null) score += Math.max(0, 100 - progress) / 10;
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

    const operationExecutionHotelId = (item) => Number(
        item?.hotel_id
        || item?.system_hotel_id
        || item?.execution?.hotel_id
        || item?.execution?.system_hotel_id
        || item?.action_management?.action_card?.hotel?.hotel_id
        || item?.target_value?.action_card?.hotel?.hotel_id
        || item?.action_management?.action_card?.scope?.hotel_id
        || item?.target_value?.action_card?.scope?.hotel_id
        || 0
    );
    const operationExecutionActionCard = (item) => [
        item?.action_management?.action_card,
        item?.execution?.action_management?.action_card,
        item?.target_value?.action_card,
        item?.evidence?.action_card,
        item?.recommendation?.target_value?.action_card,
        item?.recommendation?.evidence?.action_card,
    ].find(candidate => candidate && typeof candidate === 'object') || {};
    const operationExecutionMutationDigest = (item) => String(
        operationExecutionActionCard(item)?.content_digest
        || item?.target_value?.approval_target_digest
        || item?.evidence?.approval_target_digest
        || item?.recommendation?.target_value?.approval_target_digest
        || item?.recommendation?.evidence?.approval_target?.content_digest
        || ''
    ).trim().toLowerCase();
    const assertOperationExecutionMutationContextCurrent = (context, item, selectedHotelId) => {
        if (!context || Number(selectedHotelId || 0) !== Number(context.hotelId || 0)) {
            throw new Error('当前筛选酒店已变化，已拒绝提交运营写入');
        }
        if (Number(item?.id || item?.intent_id || 0) !== Number(context.intentId || 0)
            || operationExecutionHotelId(item) !== Number(context.hotelId || 0)
            || (Number(context.taskId || 0) > 0
                && Number(item?.execution?.task_id || item?.id || 0) !== Number(context.taskId))
            || (String(context.actionDigest || '') !== ''
                && operationExecutionMutationDigest(item) !== String(context.actionDigest))
        ) throw new Error('运营行动身份已变化，请刷新后重试');
    };
    const captureOperationExecutionMutationContext = (item, selectedHotelId, options = {}) => {
        const requireTask = options?.requireTask === true;
        const context = Object.freeze({
            intentId: Number(item?.id || item?.intent_id || 0),
            taskId: Number(item?.execution?.task_id || (requireTask ? item?.id : 0) || 0),
            hotelId: operationExecutionHotelId(item),
            actionDigest: operationExecutionMutationDigest(item),
        });
        if (!Number.isInteger(context.intentId) || context.intentId <= 0) throw new Error('运营行动意图身份无效，请刷新后重试');
        if (!Number.isInteger(context.hotelId) || context.hotelId <= 0) throw new Error('运营行动缺少酒店身份，已拒绝写入');
        if (requireTask && (!Number.isInteger(context.taskId) || context.taskId <= 0)) throw new Error('运营任务身份无效，请刷新后重试');
        if (options?.requireDigest === true && !/^[a-f0-9]{64}$/.test(context.actionDigest)) throw new Error('运营行动摘要无效，请刷新后重试');
        assertOperationExecutionMutationContextCurrent(context, item, selectedHotelId);
        return context;
    };
    const assertOperationExecutionMutationDigestReadback = (entity, context, allowPreviousCardDigest = false) => {
        const expectedDigest = String(context?.actionDigest || '');
        if (!expectedDigest) return;
        const card = operationExecutionActionCard(entity);
        const actualDigest = operationExecutionMutationDigest(entity);
        const previousDigest = String(card?.previous_card_digest || '').trim().toLowerCase();
        if (actualDigest !== expectedDigest && (!allowPreviousCardDigest || previousDigest !== expectedDigest)) {
            throw new Error('运营行动摘要回读不一致');
        }
    };
    const operationExecutionEvidenceWriteConfirmed = (task, evidenceWrite, evidenceType) => {
        const evidenceId = Number(evidenceWrite?.evidence_id || 0);
        const exactReadback = Number.isInteger(evidenceId) && evidenceId > 0
            && Array.isArray(task?.evidence)
            && task.evidence.some(evidence => Number(evidence?.id || 0) === evidenceId
                && String(evidence?.evidence_type || '') === String(evidenceType || ''));
        return exactReadback && (
            evidenceWrite?.created === true && evidenceWrite?.replayed === false
            || evidenceWrite?.created === false && evidenceWrite?.replayed === true
        );
    };
    const readOperationExecutionIntent = async (request, intentId, expectedHotelId = 0) => {
        const normalizedId = Number(intentId || 0);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) throw new Error('执行意图回读ID无效');
        const normalizedHotelId = Number(expectedHotelId || 0);
        const params = new URLSearchParams();
        if (normalizedHotelId > 0) {
            params.set('hotel_id', String(normalizedHotelId));
            params.set('system_hotel_id', String(normalizedHotelId));
        }
        const query = params.toString() ? `?${params.toString()}` : '';
        const res = await request(`/operation/execution-intents/${normalizedId}${query}`, normalizedHotelId > 0 ? { businessContext: { hotelId: normalizedHotelId } } : {});
        if (res.code !== 200) throw new Error(res.message || '执行意图回读失败');
        const intent = res.data || {};
        if (Number(intent.id || 0) !== normalizedId) throw new Error('执行意图回读资源不一致');
        if (normalizedHotelId > 0 && operationExecutionHotelId(intent) !== normalizedHotelId) throw new Error('执行意图回读酒店身份不一致');
        return intent;
    };
    const readOperationExecutionTask = async (request, taskId, expectedHotelId = 0, evidenceWrite = null) => {
        const normalizedId = Number(taskId || 0);
        if (!Number.isInteger(normalizedId) || normalizedId <= 0) throw new Error('执行任务回读ID无效');
        const normalizedHotelId = Number(expectedHotelId || 0);
        const params = new URLSearchParams();
        if (normalizedHotelId > 0) {
            params.set('hotel_id', String(normalizedHotelId));
            params.set('system_hotel_id', String(normalizedHotelId));
        }
        const query = params.toString() ? `?${params.toString()}` : '';
        const res = await request(`/operation/execution-tasks/${normalizedId}${query}`, normalizedHotelId > 0 ? { businessContext: { hotelId: normalizedHotelId } } : {});
        if (res.code !== 200) throw new Error(res.message || '执行任务回读失败');
        const task = res.data || {};
        if (Number(task.id || 0) !== normalizedId) throw new Error('执行任务回读资源不一致');
        if (normalizedHotelId > 0 && operationExecutionHotelId(task) !== normalizedHotelId) throw new Error('执行任务回读酒店身份不一致');
        if (evidenceWrite !== null && !operationExecutionEvidenceWriteConfirmed(task, evidenceWrite, 'manual_operation_execution')) {
            throw new Error('运营任务补充证据未在回读中精确确认');
        }
        return task;
    };
    const cancelOperationExecutionMutation = async (item, ctx) => {
        if (!ctx.canCancel(item) || ctx.loading.value.actions) return;
        let mutationContext;
        try {
            mutationContext = captureOperationExecutionMutationContext(item, ctx.selectedHotelId(), { requireDigest: true });
        } catch (error) {
            ctx.toast(ctx.errorMessage(error, '运营行动身份校验失败'), 'error');
            return;
        }
        const values = await ctx.openDialog({
            title: '取消运营行动',
            description: '取消原因会追加到行动历史；已保存的审批、任务和证据不会被改写。',
            submitText: '确认取消',
            fields: [{ name: 'reason', label: '取消原因', type: 'textarea', required: true, value: '' }],
        });
        if (values === null) return;
        const reason = String(values.reason || '').trim();
        if (!reason) return;
        try {
            assertOperationExecutionMutationContextCurrent(mutationContext, item, ctx.selectedHotelId());
        } catch (error) {
            ctx.toast(ctx.errorMessage(error, '运营行动身份校验失败'), 'error');
            return;
        }
        ctx.loading.value.actions = true;
        try {
            const res = await ctx.request(`/operation/execution-intents/${mutationContext.intentId}/cancel`, {
                method: 'POST',
                businessContext: { hotelId: mutationContext.hotelId },
                body: JSON.stringify({ reason, hotel_id: mutationContext.hotelId, system_hotel_id: mutationContext.hotelId }),
            });
            if (res.code !== 200 || Number(res.data?.id || 0) !== mutationContext.intentId) throw new Error(res.message || '运营行动取消失败');
            const intent = await readOperationExecutionIntent(ctx.request, mutationContext.intentId, mutationContext.hotelId);
            assertOperationExecutionMutationDigestReadback(intent, mutationContext);
            const isDailyV2 = String(item?.action_management?.contract_version || '') === 'operation_action_card.v2';
            const expectedIntentStatus = isDailyV2 ? 'blocked' : 'cancelled';
            if (String(intent?.status || '') !== expectedIntentStatus
                || String(intent?.action_management?.lifecycle?.status || '') !== expectedIntentStatus) throw new Error('运营行动未按 ID 回读到已取消状态');
            ctx.toast('运营行动已取消，历史版本仍完整保留');
            await ctx.loadActions({ focusIntentId: mutationContext.intentId });
        } catch (error) {
            ctx.toast(ctx.errorMessage(error, '运营行动取消失败'), 'error');
        } finally {
            ctx.loading.value.actions = false;
        }
    };
    const reconcileOperationExecutionReviewMutation = async (item, ctx) => {
        let mutationContext;
        try {
            mutationContext = captureOperationExecutionMutationContext(item, ctx.selectedHotelId(), {
                requireTask: true,
                requireDigest: ctx.isManagedAction(item),
            });
            assertOperationExecutionMutationContextCurrent(mutationContext, item, ctx.selectedHotelId());
        } catch (error) {
            ctx.toast(ctx.errorMessage(error, '运营复盘身份校验失败'), 'error');
            return;
        }
        ctx.loading.value.actions = true;
        try {
            const res = await ctx.request(ctx.reconcilePath(mutationContext.taskId), {
                method: 'POST',
                businessContext: { hotelId: mutationContext.hotelId },
                body: JSON.stringify({ hotel_id: mutationContext.hotelId, system_hotel_id: mutationContext.hotelId }),
            });
            if (res.code !== 200) throw new Error(res.message || '到期复盘事实读取失败');
            const result = res.data || {};
            if (Number(result.task_id || 0) !== mutationContext.taskId) throw new Error('到期复盘事实返回的任务ID不一致');
            const task = await readOperationExecutionTask(ctx.request, mutationContext.taskId, mutationContext.hotelId);
            assertOperationExecutionMutationDigestReadback(task, mutationContext);
            if (result.status === 'source_readback_verified') {
                if (task?.evidence_truth?.source_verified !== true || !ctx.hasEvidenceType(task, 'source_verified_metric_readback')) {
                    throw new Error('来源核验复盘事实严格回读失败');
                }
                ctx.toast('同酒店、同渠道、同指标复盘事实已读取；请人工确认复盘结论', 'success');
            } else if (result.status === 'source_readback_missing') {
                if (task?.evidence_truth?.source_verified === true) throw new Error('复盘事实缺失状态与任务回读不一致');
                ctx.toast('约定窗口暂无同口径可信事实，任务继续观察', 'warning');
            } else if (result.status === 'already_reviewed') {
                ctx.toast('该任务已完成复盘，无需重复读取', 'info');
            } else {
                throw new Error('到期复盘事实返回未知状态');
            }
            await ctx.loadActions({ focusIntentId: mutationContext.intentId });
        } catch (error) {
            ctx.toast(ctx.errorMessage(error, error.message || '到期复盘事实读取失败'), 'error');
        } finally {
            ctx.loading.value.actions = false;
        }
    };

    const runOperatingNetworkReplicationRestoreFlow = async ({
        replication, hotelId, busy = false, currentHotelId, request, setAction, setError,
        assertBoundaries, setReplication, clearIntent, loadReviews, toast,
    } = {}) => {
        const replicationId = Number(replication?.id || 0);
        if (Number(hotelId) <= 0 || replicationId <= 0 || busy) return null;
        setAction('replication_readback');
        setError('');
        try {
            const response = await request(`/operation/operating-sop-replications/${replicationId}`);
            if (Number(currentHotelId()) !== Number(hotelId)) return null;
            if (response.code !== 200 || !response.data) throw new Error(response.msg || '复制草稿独立回读失败');
            const actual = response.data;
            if (Number(actual.id || 0) !== replicationId || Number(actual.target_hotel_id || 0) !== Number(hotelId)
                || String(actual.content_digest || '') !== String(replication?.content_digest || '')) {
                throw new Error('复制草稿恢复身份或摘要不一致');
            }
            assertBoundaries(actual.draft?.boundaries);
            setReplication(actual);
            clearIntent();
            await loadReviews(replicationId);
            return actual;
        } catch (error) {
            const message = error.message || '复制草稿恢复失败';
            setError(message);
            toast(message, 'error');
            return null;
        } finally {
            setAction('');
        }
    };

    const operatingNetworkReplicationLabel = (replication) => `继续草稿 #${replication?.id || '-'} · SOP #${replication?.source_sop_version_id || '-'} · ${replication?.status || '-'}`;

    const applyOperationExecutionViewMode = async ({ mode, currentMode, loading = false, setMode, clearFilter, loadActions } = {}) => {
        const nextMode = mode === 'mine' ? 'mine' : 'all';
        if (currentMode === nextMode && !loading) return false;
        setMode(nextMode);
        clearFilter();
        await loadActions();
        return true;
    };

    // Interactive workflows run after the operation page has loaded this module.
    const runOpenOperatingInterventionForm = async ({ selectedOperatingGoalHotelId, currentOperatingGoalContract, showToast, operatingGoalGuardMap, openWorkflowFormDialog, operationExecutionActionText, operatingInterventionDefaultDate, operatingLearningNumber, operatingLearningList, operatingGoalInterventionLoading, operatingGoalInterventionError, apiRequest, loadOperationActions, operationErrorMessage }, item = null) => {
    const hotelId = selectedOperatingGoalHotelId();
    if (!hotelId) return;
    if (!currentOperatingGoalContract.value) {
        showToast('请先建立当前酒店的目标合同', 'warning');
        return;
    }
    const recommendation = item?.recommendation || {};
    const baselineDate = String(recommendation.date_start || '').slice(0, 10);
    const guards = operatingGoalGuardMap(currentOperatingGoalContract.value);
    const targetMetric = String(
        recommendation.target_metric
            || currentOperatingGoalContract.value.primary_metric_key
            || ''
    ).trim();
    const otaMetricKeys = new Set([
        'ota_revenue', 'ota_adr', 'orders', 'ota_orders', 'room_nights',
        'ota_room_nights', 'cancellation_rate', 'cancellation_rate_percent',
        'ota_cancellation_rate_percent',
    ]);
    const defaultFactScope = otaMetricKeys.has(targetMetric.toLowerCase())
        ? 'ota_channel'
        : 'whole_hotel_accommodation';
    const values = await openWorkflowFormDialog({
        title: item?.id ? `为任务 #${item.id} 定义经营干预` : '发起经营干预',
        description: '只填写经营动作和观察窗口。系统会从已验证事实自动冻结执行前基线、来源与证据；取不到真值就拒绝建立，不会让你手抄数据，也不会直接修改 OTA。',
        submitText: item?.id ? '绑定干预合同' : '创建待审批干预',
        fields: [
            { name: 'platform', label: '执行渠道 / 来源', type: 'select', required: true, value: recommendation.platform || 'manual', options: [{ value: 'ctrip', label: '携程' }, { value: 'meituan', label: '美团' }, { value: 'pms', label: 'PMS / 全酒店' }, { value: 'manual', label: '线下人工' }] },
            { name: 'action_type', label: '动作类型', required: true, value: recommendation.action_type || recommendation.object_type || 'operation_checklist' },
            { name: 'action_text', label: '准备执行的动作', type: 'textarea', required: true, value: item ? operationExecutionActionText(item) : '' },
            { name: 'rationale', label: '为什么做', type: 'textarea', required: true, value: recommendation.reason || recommendation.reasoning || '' },
            { name: 'target_metric_key', label: '想改变的指标键', required: true, value: targetMetric },
            { name: 'expected_direction', label: '预期方向', type: 'select', required: true, value: 'increase', options: [{ value: 'increase', label: '提升' }, { value: 'decrease', label: '降低' }] },
            { name: 'expected_delta', label: '最小有效变化', type: 'number', required: true, value: '' },
            { name: 'expected_delta_unit', label: '变化单位', type: 'select', required: true, value: 'percent', options: [{ value: 'percent', label: '百分比' }, { value: 'absolute', label: '绝对值' }] },
            { name: 'risk_metric_keys', label: '可能受伤指标（逗号分隔）', required: true, value: Object.keys(guards).join('，') },
            { name: 'baseline_fact_scope', label: '系统取数口径', type: 'select', required: true, value: defaultFactScope, options: [{ value: 'whole_hotel_accommodation', label: 'PMS 全酒店经营' }, { value: 'ota_channel', label: 'OTA 渠道经营' }] },
            { name: 'baseline_platform', label: 'OTA 渠道范围（全酒店口径会忽略）', type: 'select', required: true, value: ['ctrip', 'meituan'].includes(String(recommendation.platform || '').toLowerCase()) ? String(recommendation.platform).toLowerCase() : 'combined', options: [{ value: 'combined', label: '携程 + 美团' }, { value: 'ctrip', label: '仅携程' }, { value: 'meituan', label: '仅美团' }] },
            { name: 'observation_window_start', label: '观察开始日', type: 'date', required: true, value: operatingInterventionDefaultDate(baselineDate, 1) },
            { name: 'observation_window_end', label: '观察结束日', type: 'date', required: true, value: operatingInterventionDefaultDate(baselineDate, 7) },
            { name: 'stop_condition', label: '本次动作额外停止条件', type: 'textarea', required: true, value: '目标合同任一保护指标或停止条件触发时，立即停止并按回滚方案处理。' },
        ],
    });
    if (values === null) return;
    const payload = {
        hotel_id: hotelId,
        platform: String(values.platform || '').trim(),
        action_type: String(values.action_type || '').trim(),
        action_text: String(values.action_text || '').trim(),
        rationale: String(values.rationale || '').trim(),
        target_metric_key: String(values.target_metric_key || '').trim(),
        expected_direction: String(values.expected_direction || '').trim(),
        expected_delta: operatingLearningNumber(values.expected_delta),
        expected_delta_unit: String(values.expected_delta_unit || '').trim(),
        risk_metric_keys: operatingLearningList(values.risk_metric_keys),
        baseline_mode: 'automatic',
        baseline_fact_scope: String(values.baseline_fact_scope || '').trim(),
        baseline_platform: String(values.baseline_fact_scope || '').trim() === 'ota_channel'
            ? String(values.baseline_platform || 'combined').trim()
            : '',
        observation_window: {
            start: String(values.observation_window_start || '').trim(),
            end: String(values.observation_window_end || '').trim(),
        },
        comparison: {
            mode: 'same_length_period',
            reference: '',
        },
        stop_condition: String(values.stop_condition || '').trim(),
    };
    operatingGoalInterventionLoading.value = true;
    operatingGoalInterventionError.value = '';
    try {
        const endpoint = item?.id
            ? `/operation/execution-intents/${Number(item.id)}/intervention`
            : '/operation/interventions';
        const res = await apiRequest(endpoint, {
            method: 'POST',
            businessContext: { hotelId },
            body: JSON.stringify(payload),
        });
        if (res.code !== 200) throw new Error(res.message || '经营干预保存失败');
        const intervention = res.data?.intervention || res.data?.intervention_contract || res.data;
        if (Number(intervention?.hotel_id || 0) !== hotelId || !/^[a-f0-9]{64}$/i.test(String(intervention?.content_digest || ''))) {
            throw new Error('经营干预保存后未按酒店与摘要精确回读');
        }
        await loadOperationActions({ focusIntentId: Number(intervention?.execution_intent_id || item?.id || 0) });
        showToast('经营干预已保存，系统基线已自动冻结并进入监控', 'success');
    } catch (error) {
        operatingGoalInterventionError.value = operationErrorMessage(error, '经营干预保存失败');
        showToast(operatingGoalInterventionError.value, 'error');
    } finally {
        operatingGoalInterventionLoading.value = false;
    }
};

    const runAssessOperatingIntervention = async ({ selectedOperatingGoalHotelId, operationCanAssessIntervention, operationInterventionLearningModelForItem, openWorkflowFormDialog, operatingLearningList, operatingLearningNumber, parseGuardObservations, operationEvidenceLocalTimestamp, operatingGoalInterventionLoading, operatingGoalInterventionError, apiRequest, loadOperationActions, showToast, operationLearningVerdictLabel, operationErrorMessage }, item) => {
    const hotelId = selectedOperatingGoalHotelId();
    const taskId = Number(item?.execution?.task_id || 0);
    if (!hotelId || !taskId || !operationCanAssessIntervention(item)) return;
    const model = operationInterventionLearningModelForItem(item);
    const intervention = model?.intervention || {};
    const baseline = intervention?.baseline || intervention?.baseline_snapshot || {};
    const values = await openWorkflowFormDialog({
        title: `任务 #${taskId} 学习判定`,
        description: '允许在证据不足时保存 indeterminate。系统只会输出 supported、contradicted 或 indeterminate，不宣称因果。',
        submitText: '保存三态判定',
        fields: [
            { name: 'followup_value', label: '观察后目标指标值（缺失可留空）', type: 'number', value: '' },
            { name: 'followup_unit', label: '观察值单位', value: baseline.unit || '' },
            { name: 'followup_period_start', label: '观察开始日', type: 'date', value: intervention.observation_window_start || '' },
            { name: 'followup_period_end', label: '观察结束日', type: 'date', value: intervention.observation_window_end || '' },
            { name: 'followup_captured_at', label: '观察采集时间', type: 'datetime-local', value: '' },
            { name: 'followup_source_method', label: '观察来源方法', value: baseline.source_method || '' },
            { name: 'followup_fact_scope', label: '观察指标口径', type: 'select', value: baseline.fact_scope || 'ota_channel', options: [{ value: 'ota_channel', label: 'OTA 渠道' }, { value: 'whole_hotel', label: '全酒店' }, { value: 'user_input', label: '人工输入' }] },
            { name: 'followup_evidence_refs', label: '观察证据引用（逗号分隔）', value: '' },
            { name: 'followup_quality_status', label: '观察质量', type: 'select', value: 'unverified', options: [{ value: 'verified', label: '已验证' }, { value: 'manual_confirmed', label: '人工确认' }, { value: 'unverified', label: '未验证' }] },
            { name: 'followup_readback_status', label: '观察回读', type: 'select', value: 'unverified', options: [{ value: 'readback_verified', label: '已回读' }, { value: 'unverified', label: '未回读' }] },
            { name: 'followup_sample_size', label: '观察样本数', type: 'number', value: intervention.minimum_sample_size || '' },
            { name: 'guard_observations', label: '保护指标观察（每行 metric=value|quality|readback|evidence_ref）', type: 'textarea', value: '' },
            { name: 'external_interference_status', label: '外部干扰核对', type: 'select', required: true, value: 'unknown', options: [{ value: 'unknown', label: '尚未核对' }, { value: 'none', label: '已核对，无干扰' }, { value: 'present', label: '存在干扰' }] },
            { name: 'external_interferences', label: '外部干扰说明（每行一条）', type: 'textarea', value: '' },
            { name: 'stop_triggered', label: '是否触发停止条件', type: 'select', required: true, value: 'false', options: [{ value: 'false', label: '否' }, { value: 'true', label: '是' }] },
            { name: 'stop_evidence_refs', label: '停止条件证据引用', value: '' },
            { name: 'notes', label: '判定备注', type: 'textarea', value: model?.summary || '' },
        ],
    });
    if (values === null) return;
    const followupSnapshot = {
        system_hotel_id: hotelId,
        platform: String(baseline.platform || item?.recommendation?.platform || 'manual').trim(),
        platform_hotel_id: String(baseline.platform_hotel_id || hotelId).trim(),
        business_module: String(baseline.business_module || 'operations').trim(),
        subject: String(baseline.subject || 'hotel').trim(),
        metric_key: String(intervention.target_metric_key || baseline.metric_key || '').trim(),
        unit: String(values.followup_unit || baseline.unit || '').trim(),
        source_method: String(values.followup_source_method || '').trim(),
        date_role: String(baseline.date_role || 'business_date').trim(),
        fact_scope: String(values.followup_fact_scope || '').trim(),
        period_start: String(values.followup_period_start || '').trim(),
        period_end: String(values.followup_period_end || '').trim(),
        captured_at: String(values.followup_captured_at || '').trim(),
        evidence_refs: operatingLearningList(values.followup_evidence_refs),
        quality_status: String(values.followup_quality_status || '').trim(),
        readback_status: String(values.followup_readback_status || '').trim(),
        value: operatingLearningNumber(values.followup_value),
        sample_size: operatingLearningNumber(values.followup_sample_size),
    };
    const interferenceStatus = String(values.external_interference_status || 'unknown');
    const declaredInterferences = operatingLearningList(values.external_interferences);
    const payload = {
        hotel_id: hotelId,
        followup_snapshot: followupSnapshot,
        guard_observations: parseGuardObservations(values.guard_observations, followupSnapshot),
        ...(interferenceStatus === 'unknown' ? {} : {
            external_interferences: interferenceStatus === 'none'
                ? []
                : (declaredInterferences.length ? declaredInterferences : ['operator_declared_interference']),
        }),
        stop_triggered: String(values.stop_triggered || '') === 'true',
        stop_evidence_refs: operatingLearningList(values.stop_evidence_refs),
        assessed_at: operationEvidenceLocalTimestamp(),
        notes: String(values.notes || '').trim(),
    };
    operatingGoalInterventionLoading.value = true;
    operatingGoalInterventionError.value = '';
    try {
        const res = await apiRequest(`/operation/execution-tasks/${taskId}/intervention-assessments`, {
            method: 'POST',
            businessContext: { hotelId },
            body: JSON.stringify(payload),
        });
        if (res.code !== 200) throw new Error(res.message || '学习判定保存失败');
        const assessment = res.data?.assessment || res.data;
        const verdict = String(assessment?.verdict || '');
        if (!['supported', 'contradicted', 'indeterminate'].includes(verdict)
            || Number(assessment?.hotel_id || 0) !== hotelId
            || !/^[a-f0-9]{64}$/i.test(String(assessment?.content_digest || ''))) {
            throw new Error('学习判定未按三态、酒店与摘要精确回读');
        }
        await loadOperationActions({ focusIntentId: Number(item?.id || 0) });
        showToast(`学习结论：${operationLearningVerdictLabel(verdict)}`, verdict === 'contradicted' ? 'warning' : 'success');
    } catch (error) {
        operatingGoalInterventionError.value = operationErrorMessage(error, '学习判定保存失败');
        showToast(operatingGoalInterventionError.value, 'error');
    } finally {
        operatingGoalInterventionLoading.value = false;
    }
};

    const runApproveOperationExecutionIntent = async ({ operationLoading, operationAiDailyApprovalDateWarning, operationApprovalConfirming, operationApprovalConfirmingIntentId, showToast, captureOperationExecutionMutationContext, operationIsManagedAction, operationErrorMessage, formatDate, openWorkflowFormDialog, user, normalizeOperationEvidenceDateTime, assertOperationExecutionMutationContextCurrent, apiRequest, readOperationExecutionIntent, assertOperationExecutionMutationDigestReadback, loadOperationActions }, item, approved = true) => {
    if (!item?.id) return;
    approved = approved === true || approved === 1;
    if (operationLoading.value.actions) return;
    if (approved) {
        const dateWarning = operationAiDailyApprovalDateWarning(item);
        if (dateWarning) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast(dateWarning, 'warning');
            return;
        }
    }
    if (approved && !operationApprovalConfirming(item)) {
        operationApprovalConfirmingIntentId.value = Number(item.id);
        showToast('请再次点击“确认审批”', 'warning');
        return;
    }
    let mutationContext;
    try {
        mutationContext = captureOperationExecutionMutationContext(item, {
            requireDigest: operationIsManagedAction(item),
        });
    } catch (error) {
        operationApprovalConfirmingIntentId.value = 0;
        showToast(operationErrorMessage(error, '执行意图审批身份校验失败'), 'error');
        return;
    }
    let remark = '';
    let approvalTarget = {};
    const approvalSourceModule = String(item?.recommendation?.source_module || '').trim().toLowerCase();
    const isManagedRevenueAction = operationIsManagedAction(item)
        && ['operating_question', 'revenue_cockpit_action', 'daily_one_thing'].includes(approvalSourceModule);
    const isManagedOperatingQuestion = approvalSourceModule === 'operating_question'
        && isManagedRevenueAction;
    if (approved && ['ota_diagnosis_saved', 'operating_network_replication', 'operating_question', 'revenue_cockpit_action', 'daily_one_thing'].includes(approvalSourceModule)) {
        const recommendation = item?.recommendation || {};
        const targetValue = recommendation?.target_value || {};
        const workflowSchedule = targetValue?.workflow_schedule || {};
        const actionCard = targetValue?.action_card || recommendation?.action_management?.action_card || {};
        const metricContract = actionCard?.metric_contract || {};
        const expectedMetric = String(recommendation?.expected_metric || targetValue?.target_metric || '').trim().toLowerCase();
        const expectedEffect = recommendation?.evidence?.decision_recommendation?.expected_effect || {};
        const isVerificationOnlyOperatingQuestion = isManagedRevenueAction
            && ((String(metricContract?.target_type || '').trim().toLowerCase() === 'observation'
                    && String(metricContract?.expected_direction || '').trim().toLowerCase() === 'observe')
                || (isManagedOperatingQuestion
                    && String(expectedEffect?.status || '').trim().toLowerCase() === 'verification_target'
                    && String(expectedEffect?.direction || '').trim().toLowerCase() === 'verify'
                    && String(expectedEffect?.metric || '').trim().toLowerCase() === expectedMetric));
        const baselineDate = String(recommendation?.date_end || recommendation?.date_start || '').slice(0, 10);
        const scheduledReviewDate = String(workflowSchedule?.review_at || '').slice(0, 10);
        const minimumReviewDate = isManagedRevenueAction && /^\d{4}-\d{2}-\d{2}$/.test(scheduledReviewDate)
            ? scheduledReviewDate
            : /^\d{4}-\d{2}-\d{2}$/.test(baselineDate)
            ? formatDate(new Date(new Date(`${baselineDate}T12:00:00`).getTime() + 86400000))
            : formatDate(new Date());
        if (!expectedMetric) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('执行意图缺少目标指标，不能审批', 'error');
            return;
        }
        const formValues = await openWorkflowFormDialog({
            title: isVerificationOnlyOperatingQuestion ? '确认观察口径并批准' : '确认成功标准并批准',
            description: isVerificationOnlyOperatingQuestion
                ? `指标 ${expectedMetric} 来自真实经营问题与严格回读事实。本行动只观察同口径变化，不承诺提升幅度；批准时会再次读取原始事实，批准后才生成运营任务。`
                : `指标 ${expectedMetric} 来自${approvalSourceModule === 'operating_network_replication' ? '已保存的复制验证草稿' : (isManagedRevenueAction ? '真实收益事实与严格回读证据' : '已保存诊断')}。批准时会再次读取原始事实；批准后才生成运营任务，不会自动修改 OTA。`,
            submitText: isVerificationOnlyOperatingQuestion ? '冻结观察口径并批准' : '冻结标准并批准',
            fields: [
                {
                    name: 'expected_direction',
                    label: '期望方向',
                    type: 'select',
                    required: true,
                    value: isVerificationOnlyOperatingQuestion
                        ? 'observe'
                        : String(targetValue?.expected_direction || 'increase'),
                    options: isVerificationOnlyOperatingQuestion
                        ? [{ value: 'observe', label: '仅观察变化（不承诺提升）' }]
                        : [
                            { value: 'increase', label: '提升' },
                            { value: 'decrease', label: '降低' },
                        ],
                },
                {
                    name: 'target_type',
                    label: '目标口径',
                    type: 'select',
                    required: true,
                    value: isVerificationOnlyOperatingQuestion ? 'observation' : 'delta',
                    options: isVerificationOnlyOperatingQuestion
                        ? [{ value: 'observation', label: '同口径前后观察' }]
                        : [
                            { value: 'delta', label: '相对基准变化量' },
                            { value: 'absolute', label: '绝对目标值' },
                        ],
                },
                ...(isVerificationOnlyOperatingQuestion ? [] : [
                    { name: 'expected_delta', label: '目标变化量（delta 时必填）', type: 'number', value: '' },
                    { name: 'target_value', label: '绝对目标值（absolute 时必填）', type: 'number', value: '' },
                ]),
                { name: 'review_business_date', label: isManagedRevenueAction ? '效果复盘经营日' : '次日效果复盘经营日', type: 'date', required: true, value: minimumReviewDate, min: minimumReviewDate, max: minimumReviewDate },
                ...(isManagedRevenueAction ? [
                    { name: 'assignee_id', label: '负责人用户 ID', type: 'number', required: true, value: Number(workflowSchedule?.assignee_id || user.value?.id || 0) },
                    { name: 'due_at', label: '任务截止时间', type: 'datetime-local', required: true, value: String(workflowSchedule?.due_at || '').replace(' ', 'T').slice(0, 16) },
                    { name: 'review_at', label: '效果复盘时间', type: 'datetime-local', required: true, value: String(workflowSchedule?.review_at || '').replace(' ', 'T').slice(0, 16) },
                ] : []),
                { name: 'remark', label: '审批备注', type: 'textarea', value: '' },
            ],
        });
        if (formValues === null) {
            operationApprovalConfirmingIntentId.value = 0;
            return;
        }
        const targetType = String(formValues.target_type || '').trim();
        const expectedDeltaText = String(formValues.expected_delta ?? '').replace(/[,，]/g, '').trim();
        const absoluteTargetText = String(formValues.target_value ?? '').replace(/[,，]/g, '').trim();
        const expectedDelta = Number(expectedDeltaText);
        const absoluteTarget = Number(absoluteTargetText);
        if (isVerificationOnlyOperatingQuestion
            && (String(formValues.expected_direction || '').trim() !== 'observe'
                || targetType !== 'observation')
        ) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('核验型行动只能按“仅观察变化”口径审批', 'error');
            return;
        }
        if (targetType === 'delta' && (expectedDeltaText === '' || !Number.isFinite(expectedDelta) || expectedDelta <= 0)) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('变化量目标必须填写大于 0 的数值', 'error');
            return;
        }
        if (targetType === 'absolute' && (absoluteTargetText === '' || !Number.isFinite(absoluteTarget) || absoluteTarget < 0)) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('绝对目标必须填写不小于 0 的数值', 'error');
            return;
        }
        if (String(formValues.review_business_date || '').trim() !== minimumReviewDate) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast(isManagedRevenueAction
                ? `效果复盘经营日必须与任务计划一致：${minimumReviewDate}`
                : `效果复盘经营日必须是执行基准日的次日：${minimumReviewDate}`, 'error');
            return;
        }
        approvalTarget = {
            expected_metric: expectedMetric,
            expected_direction: String(formValues.expected_direction || '').trim(),
            target_type: targetType,
            review_business_date: String(formValues.review_business_date || '').trim(),
            ...(isManagedRevenueAction ? {
                assignee_id: Number(formValues.assignee_id || 0),
                due_at: normalizeOperationEvidenceDateTime(formValues.due_at),
                review_at: normalizeOperationEvidenceDateTime(formValues.review_at),
            } : {}),
            ...(targetType === 'delta'
                ? { expected_delta: expectedDelta }
                : (targetType === 'absolute' ? { target_value: absoluteTarget } : {})),
        };
        if (isManagedRevenueAction
            && (!Number.isInteger(approvalTarget.assignee_id) || approvalTarget.assignee_id <= 0)
        ) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('负责人用户 ID 必须是正整数', 'error');
            return;
        }
        if (isManagedRevenueAction
            && approvalTarget.review_at.slice(0, 10) !== approvalTarget.review_business_date
        ) {
            operationApprovalConfirmingIntentId.value = 0;
            showToast('复盘时间与复盘经营日必须一致', 'error');
            return;
        }
        if (isManagedRevenueAction) {
            const actionDigest = String(actionCard?.content_digest || '').trim().toLowerCase();
            if (!/^[a-f0-9]{64}$/.test(actionDigest)) {
                operationApprovalConfirmingIntentId.value = 0;
                showToast('行动卡摘要缺失，请刷新后重新审批', 'error');
                return;
            }
            approvalTarget.confirmed = true;
            approvalTarget.confirmation_version = 'operation_action_approval_confirmation.v1';
            approvalTarget.confirmed_intent_id = mutationContext.intentId;
            approvalTarget.confirmed_action_digest = actionDigest;
        }
        remark = String(formValues.remark || '').trim();
    } else if (!approved) {
        const formValues = await openWorkflowFormDialog({
            title: '驳回执行意图',
            description: '驳回原因会写入本地审批记录，供后续复核。',
            submitText: '确认驳回',
            fields: [{ name: 'remark', label: '驳回原因', type: 'textarea', required: true, value: '' }],
        });
        if (formValues === null) return;
        remark = String(formValues.remark || '').trim();
    }
    operationApprovalConfirmingIntentId.value = 0;
    operationLoading.value.actions = true;
    try {
        assertOperationExecutionMutationContextCurrent(mutationContext, item);
        if (approved) {
            const dateWarning = operationAiDailyApprovalDateWarning(item);
            if (dateWarning) throw new Error(dateWarning);
        }
        const res = await apiRequest(`/operation/execution-intents/${mutationContext.intentId}/approve`, {
            method: 'POST',
            businessContext: { hotelId: mutationContext.hotelId },
            body: JSON.stringify({
                approved,
                remark,
                ...approvalTarget,
                hotel_id: mutationContext.hotelId,
                system_hotel_id: mutationContext.hotelId,
            }),
        });
        if (res.code !== 200) throw new Error(res.message || '执行意图审批失败');
        const responseIntentId = Number(res.data?.id || 0);
        if (!Number.isInteger(responseIntentId) || responseIntentId !== mutationContext.intentId) {
            throw new Error('执行意图审批返回的资源ID不一致');
        }
        const persistedIntent = await readOperationExecutionIntent(responseIntentId, mutationContext.hotelId);
        assertOperationExecutionMutationDigestReadback(persistedIntent, mutationContext, true);
        const expectedStatus = approved
            ? 'approved'
            : (operationIsManagedAction(item) ? 'cancelled' : 'rejected');
        if (persistedIntent.status !== expectedStatus) {
            throw new Error(`执行意图回读状态不一致：应为 ${expectedStatus}`);
        }
        if (approved && (!Array.isArray(persistedIntent.tasks)
            || persistedIntent.tasks.length !== 1
            || Number(persistedIntent.tasks[0]?.id || 0) <= 0
        )) {
            throw new Error('执行意图审批后必须精确回读到唯一执行任务');
        }
        if (approved && Object.keys(approvalTarget).length > 0) {
            const persistedContract = persistedIntent?.evidence?.approval_target || {};
            const persistedTask = persistedIntent.tasks.find(task => Number(task?.id || 0) > 0) || {};
            if (String(persistedContract.expected_metric || '') !== approvalTarget.expected_metric
                || String(persistedContract.expected_direction || '') !== approvalTarget.expected_direction
                || String(persistedContract.target_type || '') !== approvalTarget.target_type
                || String(persistedContract.review_business_date || '') !== approvalTarget.review_business_date
                || !persistedContract.metric_definition
                || !/^[a-f0-9]{64}$/.test(String(persistedContract.metric_definition_digest || ''))
                || !/^[a-f0-9]{64}$/.test(String(persistedContract.content_digest || ''))
                || String(persistedTask?.target_value?.approval_target_digest || '') !== String(persistedContract.content_digest || '')
            ) {
                throw new Error('人工成功标准未按审批内容精确回读');
            }
            if (isManagedRevenueAction
                && (String(persistedIntent?.action_management?.lifecycle?.status || '') !== 'approved'
                    || Number(persistedIntent?.action_management?.action_card?.responsibility?.owner_id || 0) !== approvalTarget.assignee_id)
            ) {
                throw new Error('统一行动卡负责人或生命周期未精确回读');
            }
        }
        showToast(approved
            ? (Object.keys(approvalTarget).length > 0 ? '成功标准已冻结，运营任务已生成并回读' : '执行意图已审批')
            : '执行意图已驳回');
        await loadOperationActions();
    } catch (error) {
        showToast(operationErrorMessage(error, '执行意图审批失败'), 'error');
    } finally {
        operationLoading.value.actions = false;
    }
};

    const runRecordOperationExecutionEvidence = async ({ operationEvidenceSaving, operationExecutionHotelId, showToast, currentPage, operationFilters, nextTick, loadOperationActions, operationEvidenceFirstText, openWorkflowFormDialog, user, operationEvidenceLocalTimestamp, parseOptionalOperationEvidenceNumber, parseOperationEvidenceNumber, normalizeOperationEvidenceDateTime, operationLoading, apiRequest, operationEvidenceCleanObject, readOperationExecutionTask, operationExecutionHasEvidenceType, operationErrorMessage, operationEvidenceModalItem, operationEvidenceForm, formatDate, operationEvidenceModalOpen }, item) => {
    if (operationEvidenceSaving.value) return;
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
            description: '仅保存本地人工执行证据，不会向携程或美团自动改价。收入、成本和指标效果在次日复盘中另行保存。',
            submitText: '保存执行证据',
            fields: [
                { name: 'before_price', label: '执行前公开价 / 原价', type: 'number', value: beforePriceDefault },
                { name: 'after_price', label: '执行后公开价 / 实际执行价', type: 'number', required: true, value: afterPriceDefault },
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
        const platformText = formValues.platform;
        const roomTypeText = formValues.room_type;
        const receiptPathText = formValues.receipt_path;
        const operatorText = formValues.executed_by;
        const executedAtText = formValues.executed_at;
        const remarkText = formValues.remark;

        try {
            const beforePrice = parseOptionalOperationEvidenceNumber(beforePriceText, '执行前公开价');
            const afterPrice = parseOperationEvidenceNumber(afterPriceText, '执行后公开价');
            const platform = String(platformText || '').trim();
            const roomType = String(roomTypeText || '').trim();
            const receiptPath = String(receiptPathText || '').trim();
            const executedBy = String(operatorText || '').trim();
            const executedAt = normalizeOperationEvidenceDateTime(executedAtText);
            const remark = String(remarkText || '').trim();
            const before = {};
            if (beforePrice !== null) before.price = beforePrice;
            const after = { price: afterPrice };
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
            showToast('调价执行证据已保存；指标效果需在次日同口径复盘中确认');
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
        supplementing_executed: item?.execution?.status === 'executed' && item?.next_action?.key === 'record_evidence',
        execution_status: 'executed',
        completed_action: '',
        receipt_path: '',
        platform_receipt: '',
        formal_record_ref: '',
        failure_reason: '',
        executed_by: user.value?.realname || user.value?.username || '',
        executed_at: operationEvidenceLocalTimestamp(),
        next_review_date: formatDate(new Date(Date.now() + 24 * 60 * 60 * 1000)),
    };
    operationEvidenceModalOpen.value = true;
};

    const runSubmitOperationExecutionReview = async ({ operationReviewModalItem, operationReviewMutationContext, showToast, assertOperationExecutionMutationContextCurrent, normalizeOperationReviewStatus, operationReviewForm, closeOperationReviewModal, operationLoading, apiRequest, readOperationExecutionTask, assertOperationExecutionMutationDigestReadback, operationIsManagedAction, operationReviewModalOpen, loadOperationActions, operationErrorMessage, requestState }) => {
    const item = operationReviewModalItem.value;
    const taskId = Number(item?.execution?.task_id || 0);
    const mutationContext = operationReviewMutationContext.value;
    if (!taskId || !mutationContext || Number(mutationContext.taskId || 0) !== taskId) {
        showToast('运营复盘任务身份已变化，请刷新后重试', 'error');
        return;
    }
    const actionsRequestSeq = requestState.operationActionsRequestSeq;
    let reviewRequestSeq = 0;
    try {
        assertOperationExecutionMutationContextCurrent(mutationContext, item);
        const resultStatus = normalizeOperationReviewStatus(operationReviewForm.value?.status);
        const resultSummary = String(operationReviewForm.value?.summary || '').trim();
        const initialSummary = String(mutationContext.initialReviewSummary ?? '').trim();
        if (initialSummary && resultStatus === mutationContext.initialReviewStatus && resultSummary === initialSummary) {
            closeOperationReviewModal();
            showToast('复盘内容未修改', 'info');
            return;
        }
        const actionCard = item?.action_management?.action_card || {};
        const observationOnly = String(actionCard?.metric_contract?.target_type || '') === 'observation';
        if (observationOnly && resultStatus !== 'observing') {
            throw new Error('观察目标只能保存继续观察结论，不能改写为达成或未达成');
        }
        if (['success', 'near_success', 'failed'].includes(resultStatus) && !resultSummary) {
            throw new Error('复盘结论为达成/接近达成/未达成时必须填写说明');
        }
        const submittedSummary = resultSummary || '继续观察，等待次日收益或ROI证据';
        reviewRequestSeq = ++requestState.operationReviewRequestSeq;
        operationLoading.value.actions = true;
        const res = await apiRequest(`/operation/execution-tasks/${taskId}/review`, {
            method: 'POST',
            businessContext: { hotelId: mutationContext.hotelId },
            body: JSON.stringify({
                hotel_id: mutationContext.hotelId,
                system_hotel_id: mutationContext.hotelId,
                result_status: resultStatus,
                result_summary: submittedSummary,
            }),
        });
        if (res.code !== 200) throw new Error(res.message || '执行复盘失败');
        const responseTaskId = Number(res.data?.id || 0);
        if (!Number.isInteger(responseTaskId) || responseTaskId !== taskId) {
            throw new Error('执行复盘返回的任务ID不一致');
        }
        const persistedTask = await readOperationExecutionTask(responseTaskId, mutationContext.hotelId);
        assertOperationExecutionMutationDigestReadback(persistedTask, mutationContext, true);
        if (String(persistedTask.result_status || '') !== resultStatus) {
            throw new Error('执行复盘 result_status 严格回读不一致');
        }
        if (Object.prototype.hasOwnProperty.call(persistedTask, 'result_summary_sha256')
            || String(persistedTask.result_summary ?? '') !== submittedSummary) {
            const summaryDigest = typeof persistedTask.result_summary_sha256 === 'string'
                ? persistedTask.result_summary_sha256.toLowerCase() : '';
            let summaryVerified = false;
            if (typeof persistedTask.result_summary === 'string' && /^[a-f0-9]{64}$/.test(summaryDigest)
                && globalThis.crypto?.subtle && typeof TextEncoder === 'function') {
                try {
                    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(submittedSummary));
                    summaryVerified = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('') === summaryDigest;
                } catch (_) { /* Unavailable verification must not acknowledge another summary. */ }
            }
            if (!summaryVerified) throw new Error('复盘说明回读不一致，内容可能已变化，请刷新确认');
        }
        try {
            if (operationReviewMutationContext.value !== mutationContext || operationReviewModalItem.value !== item) throw new Error();
            assertOperationExecutionMutationContextCurrent(mutationContext, operationReviewModalItem.value);
        } catch (_) {
            throw new Error('复盘回读范围已变化，请刷新确认');
        }
        const requiresSeparateEffectReview = ['ota_diagnosis_saved', 'operating_question', 'revenue_cockpit_action', 'daily_one_thing'].includes(
            String(item?.recommendation?.source_module || '').trim().toLowerCase()
        )
            && ['success', 'near_success', 'failed'].includes(resultStatus);
        if (requiresSeparateEffectReview
            && (Number(persistedTask?.effect_review_summary?.verified_count || 0) < 1
                || String(persistedTask?.effect_review_summary?.persistence_status || '') !== 'readback_verified')
        ) {
            throw new Error('独立效果复盘记录未完成严格回读');
        }
        if (operationIsManagedAction(item)) {
            const managedReview = persistedTask?.action_management?.latest_review || null;
            if (!managedReview
                || !['sufficient', 'insufficient', 'mismatched'].includes(String(managedReview.evidence_sufficiency || ''))
                || !['continue', 'adjust', 'stop'].includes(String(managedReview.recommendation || ''))
                || managedReview.causality_claimed !== false
            ) {
                throw new Error('运营行动复盘报告未按证据充分度、建议和非归因边界精确回读');
            }
        }
        operationReviewModalOpen.value = false;
        operationReviewModalItem.value = null;
        operationReviewMutationContext.value = null;
        showToast(resultStatus === 'observing'
            ? '执行复盘已记录为继续观察'
            : (requiresSeparateEffectReview ? '效果复盘已独立保存并严格回读' : '执行复盘结论已保存'));
        await loadOperationActions();
    } catch (error) {
        showToast(operationErrorMessage(error, error.message || '执行复盘失败'), 'error');
    } finally {
        if (reviewRequestSeq > 0 && reviewRequestSeq === requestState.operationReviewRequestSeq
            && actionsRequestSeq === requestState.operationActionsRequestSeq) operationLoading.value.actions = false;
    }
};

    return {
        buildOperatingTargetPmsFactRows,
        buildOperatingTargetMetricRows,
        openOperatingTargetTaskDraft,
        createOperatingTargetTaskDraft,
        saveOperatingTargetRecord,
        readOperatingTargetSnapshots,
        loadOperatingTargetPrefill,
        buildOperatingTargetForm,
        runOpenOperatingInterventionForm,
        runAssessOperatingIntervention,
        runApproveOperationExecutionIntent,
        runRecordOperationExecutionEvidence,
        runSubmitOperationExecutionReview,
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
        operationAiDailySourceTarget,
        createOperationAiDailySourceNavigation,
        operationExecutionSourceText,
        operationExecutionActionText,
        operationExecutionReviewText,
        operationParseCsvValues,
        operationParseLineValues,
        buildOperatingGoalContractPayload,
        operatingGoalContractText,
        operatingGoalMonitorStatusModel,
        operationInterventionLearningModel,
        operationLearningVerdictLabel,
        operationLearningVerdictClass,
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
        buildOperationStrategyEvidence,
        createOperationActionFinishController,
        createOperationStrategyController,
        createOpeningProjectDataController,
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
        operationExecutionHotelId,
        operationExecutionMutationDigest,
        captureOperationExecutionMutationContext,
        assertOperationExecutionMutationContextCurrent,
        assertOperationExecutionMutationDigestReadback,
        operationExecutionEvidenceWriteConfirmed,
        readOperationExecutionIntent,
        readOperationExecutionTask,
        cancelOperationExecutionMutation,
        reconcileOperationExecutionReviewMutation,
        runOperatingNetworkReplicationRestoreFlow,
        operatingNetworkReplicationLabel,
        applyOperationExecutionViewMode,
        openingCategories,
        openingStatusOptions,
        openingProgressQuickValues,
        buildOpeningProjectFormDefaults,
        normalizeOpeningProjectFormForSubmit,
        buildOpeningProjectFormFromProject,
    };
})();
