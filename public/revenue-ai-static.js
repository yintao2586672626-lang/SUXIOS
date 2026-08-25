(function () {
    'use strict';

    const revenueAiStatusTone = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ok', 'success', 'ready', 'reviewed', 'ready_for_manual_generation', 'pricing_generation_candidates_ready'].includes(value)) return 'ok';
        if (['partial', 'warning', 'stale', 'not_calculable', 'missing', 'unverified', 'pending_review', 'pending_review_exists', 'pending_approval', 'in_progress', 'evidence_needed', 'evidence_ready', 'review_needed', 'reviewed_no_roi', 'investment_precheck_waiting_decision_record', 'waiting_decision_record_readiness', 'operation_intake_waiting_human_approval', 'operation_intake_ready_for_human_create', 'operation_intake_in_operation_flow', 'operation_intake_waiting_operation_progress'].includes(value)) return 'warning';
        if (['failed', 'unauthorized', 'blocked', 'error', 'skipped_by_operator_policy', 'investment_precheck_blocked_by_operation_roi', 'blocked_by_operation_roi', 'blocked_by_p0_ota_gate', 'operation_intake_blocked_by_manual_review', 'operation_intake_blocked_by_operation_execution'].includes(value)) return 'blocked';
        return 'unknown';
    };

    const revenueAiStatusClass = (status) => ({
        ok: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        warning: 'bg-amber-50 text-amber-700 border-amber-100',
        unknown: 'bg-gray-50 text-gray-500 border-gray-200',
        blocked: 'bg-slate-50 text-slate-600 border-slate-200',
    }[revenueAiStatusTone(status)] || 'bg-gray-50 text-gray-500 border-gray-200');

    const revenueAiStatusLabel = (status) => ({
        ok: '正常',
        partial: '部分可用',
        stale: '数据过期',
        failed: '失败',
        unauthorized: '未授权',
        warning: '需复核',
        empty_confirmed: '确认无数据',
        pending_review: '待人工审核',
        pending_approval: '待审批',
        in_progress: '执行中',
        evidence_needed: '待补证据',
        evidence_ready: '证据已具备',
        review_needed: '待复盘',
        reviewed_no_roi: '已复盘待补ROI',
        investment_precheck_waiting_decision_record: '待投决记录',
        waiting_decision_record_readiness: '待投决记录',
        ready: '可作为输入',
        reviewed: '已处理',
        unknown: '状态未知',
        empty: '无数据',
        missing: '缺失',
        unverified: '未验证',
        skipped_by_operator_policy: '缺口仍阻断',
        not_loaded: '未加载',
        not_calculable: '不可计算',
        blocked: '待补数据',
        blocked_by_p0_ota_gate: 'P0门禁未过',
        operation_intake_blocked_by_manual_review: '待人工审核',
        operation_intake_waiting_human_approval: '待人工创建执行',
        operation_intake_ready_for_human_create: '可人工创建',
        operation_intake_in_operation_flow: '已进入执行流',
        operation_intake_waiting_operation_progress: '等待执行进展',
        operation_intake_blocked_by_operation_execution: '执行闭环阻断',
        investment_precheck_blocked_by_operation_roi: 'ROI门禁未过',
        blocked_by_operation_roi: 'ROI门禁未过',
    }[String(status || '').toLowerCase()] || '状态未知');

    const revenueAiReasonText = (reason) => ({
        '': '数据已命中当前口径。',
        online_daily_data_empty: '目标经营日期没有可用 OTA 入库数据。',
        source_not_loaded: '未找到对应渠道的数据源或入库状态。',
        metric_scope_mismatch: '指标事实与当前酒店、平台或业务日期不一致。',
        metric_truth_unverified: '指标有值，但尚未完成来源事实和精确回读验证。',
        metric_truth_partial: '指标只有部分来源事实通过验证。',
        metric_truth_collection_failed: '指标所需的平台事实采集失败。',
        overview_scope_mismatch: 'Revenue AI 总览与请求的酒店或业务日期不一致。',
        room_revenue_missing: '暂缺已验证房费收入；订单 GMV、结算金额和参考底价不能替代。',
        room_revenue_partial: '只有部分 OTA 事实具备已验证房费收入。',
        room_nights_missing: '暂缺已验证间夜，不能用订单数、物理房间数或默认值替代。',
        order_count_missing: '暂缺语义明确且已回读的订单数。',
        available_room_nights_missing: '暂缺可信 OTA 渠道可售房晚分母，不能计算或外推全酒店 RevPAR。',
        available_room_nights_partial: '只有部分 OTA 事实具备可售房晚。',
        adr_denominator_zero: 'OTA 间夜为 0，ADR 不可计算。',
        commission_fields_missing: '暂缺同一成交口径的佣金金额或佣金率。',
        commission_fields_partial: '只有部分 OTA 事实具备同口径佣金字段。',
        net_revenue_fields_missing: '暂缺平台净收入，且没有同口径佣金事实可安全派生。',
        net_revenue_fields_partial: '只有部分 OTA 事实具备净收入。',
        cancellation_fields_missing: '暂缺平台取消订单数或取消率。',
        cancellation_fields_partial: '只有部分 OTA 事实具备取消字段。',
        cancellation_order_base_missing: '已有取消字段，但缺少同口径订单基数。',
        cancel_room_nights_missing: '暂缺取消订单对应的真实取消间夜。',
        cancel_room_nights_partial: '只有部分 OTA 事实具备取消间夜。',
        competitor_price_fields_missing: '暂缺竞对价格字段。',
        competitor_price_fields_partial: '只有部分 OTA 事实具备条件对齐的本店价与竞对价。',
        source_status_missing: '未找到平台数据源状态。',
        source_status_unknown: '未命中明确同步状态。',
        waiting_config: '平台数据源仍待授权或配置。',
        source_disabled: '平台数据源已禁用。',
        sync_failed: '平台同步失败。',
        AUTH_EXPIRED: '登录或授权已失效。',
        CAPTCHA_REQUIRED: '需要验证码或人工登录确认。',
        PAGE_CHANGED: '平台页面结构变化，采集解析需复核。',
        FIELD_MISSING: '关键字段缺失。',
        PARSER_MISMATCH: '解析器与平台返回结构不匹配。',
        NETWORK_ERROR: '平台请求网络异常。',
        RATE_LIMITED: '平台请求被限流。',
        DATE_NOT_AVAILABLE: '目标经营日期未命中可用入库数据。',
        DATA_STALE: '平台数据过期，目标经营日期没有新入库证据。',
        phase1a_calendar_signal_not_connected: 'Phase 1A 未接入节假日/事件影响模型。',
        phase1a_demand_forecast_not_connected: 'Phase 1A 未接入未来 7 天需求预测。',
        phase1a_readonly_no_pricing_model: 'Phase 1A 只读总览，未生成调价建议。',
        holiday_signal_not_loaded: '节假日/事件信号尚未读取。',
        holiday_calendar_missing: '暂缺目标年份节假日日历。',
        holiday_event_in_window: '当前处于节假日窗口。',
        holiday_event_nearby: '近期存在节假日窗口。',
        holiday_event_upcoming: '30 天内存在节假日窗口。',
        holiday_event_none_nearby: '30 天内暂无节假日窗口。',
        demand_forecasts_not_loaded: '未来 7 天需求预测尚未读取。',
        demand_forecasts_missing: '需求预测表不存在。',
        demand_forecasts_required_fields_missing: '需求预测表缺少必要字段。',
        demand_forecasts_metric_fields_missing: '需求预测表缺少预测指标字段。',
        demand_forecasts_read_failed: '未来 7 天需求预测读取失败。',
        demand_forecasts_empty: '未来 7 天暂无需求预测记录。',
        demand_forecasts_metric_missing: '需求预测记录缺少可计算指标。',
        demand_forecasts_low_confidence: '未来 7 天需求预测置信度偏低。',
        demand_forecasts_high_demand: '未来 7 天存在高需求日期。',
        demand_forecasts_available: '已读取未来 7 天需求预测。',
        competitor_price_rows_present_review_required: '存在竞对价格样本，但仍需人工复核口径。',
        competitor_price_above_competitor: '本店均价高于竞对均价，需人工复核是否存在价格倒挂或竞争力风险。',
        competitor_price_below_competitor_review_required: '本店均价低于竞对均价，需复核是否低于保护价后再判断调价。',
        competitor_price_aligned: '本店均价与竞对均价接近。',
        lead_time_fields_missing: '暂缺预订日或入住日，不能计算提前预订天数。',
        lead_time_fields_partial: '只有部分 OTA 事实具备可核验提前期。',
        booking_window_adr_fields_missing: '已有提前期，但缺少同一事实上的已验证房费收入或正数间夜，不能计算提前期房费结构。',
        booking_window_adr_fields_partial: '只有部分提前期事实具备已验证房费收入和正数间夜，当前结构仅使用已对齐记录。',
        booking_window_adr_single_bucket: '当前只有一个提前期分组，能够展示该组 ADR，但不足以比较早订与临近入住差异。',
        booking_window_adr_structure_available: '已按提前期分组计算 OTA 房费加权 ADR；仅作历史结构观察。',
        channel_booking_window_month_fields_missing: '缺少真实入住日期、提前期、OTA渠道或正数订单量，不能生成渠道预售窗口。',
        channel_booking_window_month_fields_partial: '只有部分记录具备入住月、提前期、渠道和订单量，当前仅展示已对齐记录。',
        channel_booking_window_month_sparse_cells: '部分交叉格子的订单量不足，稀疏格子保留在明细中但不生成决策信号。',
        channel_booking_window_month_structure_available: '已形成渠道、入住月和提前期的订单结构；仅用于观察预售窗口。',
        floor_price_missing: '暂缺最低保护价。',
        missing_pricing_inputs_skipped_by_operator_policy: '旧记录缺少可核验的操作者、确认时间和持久化记录，按定价输入缺口阻断。',
        manual_review_workflow_not_connected: '暂未接入人工审核工作流。',
        price_suggestions_missing: '定价建议表不存在。',
        price_suggestions_required_fields_missing: '定价建议表缺少必要字段。',
        price_suggestions_read_failed: '定价建议审核队列读取失败。',
        price_suggestions_empty: '目标经营日期暂无存量调价建议。',
        price_suggestions_pending_review: '存在待人工审核调价建议。',
        price_suggestions_reviewed: '目标经营日期调价建议已处理。',
        expected_revpar_impact_missing: '暂缺可信预计 RevPAR 影响数据。',
        agent_logs_not_loaded: '收益管理 Agent 日志尚未读取。',
        agent_logs_missing: 'Agent 日志表不存在。',
        agent_logs_required_fields_missing: 'Agent 日志表缺少必要字段。',
        agent_logs_read_failed: '收益管理 Agent 日志读取失败。',
        agent_logs_empty: '目标经营日期暂无收益管理 Agent 操作日志。',
        agent_logs_available: '已读取收益管理 Agent 操作日志。',
        agent_logs_warning_present: '收益管理 Agent 存在警告日志。',
        agent_logs_error_present: '收益管理 Agent 存在错误日志。',
        operation_execution_not_loaded: '运营执行闭环尚未读取。',
        allowed_with_governance: '已通过可信度门禁，可作为 AI 输入但仍需保留治理边界。',
        blocked_scope: '当前仅为 OTA 渠道口径，不能进入全酒店投决。',
        operation_execution_intents_missing: '执行意图表不存在。',
        operation_execution_tasks_missing: '执行任务表不存在。',
        operation_execution_evidence_missing: '执行证据表不存在或缺少执行证据。',
        operation_execution_read_failed: '运营执行闭环读取失败。',
        operation_execution_empty: '目标经营日期暂无调价执行记录。',
        operation_execution_pending_approval: '存在待审批的调价执行意图。',
        operation_execution_in_progress: '存在待执行或执行中的调价任务。',
        operation_execution_evidence_needed: '调价任务已执行但缺少执行前后证据。',
        operation_execution_review_needed: '调价执行已具备证据，等待效果复盘。',
        operation_execution_reviewed: '目标经营日期调价执行已完成复盘。',
        operation_execution_blocked: '调价执行存在阻塞、拒绝或失败记录。',
        operation_execution_partial: '调价执行闭环尚未形成完整进度。',
        operation_execution_not_executed: '调价任务尚未记录实际执行完成。',
        operation_effect_review_pending: '调价效果复盘待处理。',
        operation_effect_review_ready: '调价效果已有复盘和 ROI 证据。',
        operation_roi_missing: '调价复盘缺少 ROI 或增量收入证据。',
        closed_operating_roi_missing: '运营闭环尚未形成可用 ROI 证据。',
        operation_process_closure_missing: '运营执行过程闭环尚未完成。',
        operation_intake_not_approved: 'AI 建议尚未进入人工批准的运营执行接收。',
        'operation_execution.roi_ready': '需要运营执行 ROI ready 证据。',
        'decision_record.readiness_ready': '需要投资决策记录 ready 证据。',
        overview_not_loaded: 'Revenue AI 总览接口尚未返回。',
        overview_request_failed: 'Revenue AI 总览接口请求失败。',
        blocked_by_data_credibility: 'OTA 数据可信门未通过，收益计算被阻断。',
        source_rows_missing: '缺少可追溯的 OTA 来源行。',
        source_update_time_missing: '缺少 OTA 来源更新时间。',
        metric_value_missing: '指标值缺失。',
        whole_hotel_scope_not_proved: '尚未证明全酒店口径，只能保留 OTA 渠道边界。',
        dingdandao_pms_not_readback_verified: 'PMS 全酒店住宿事实尚未完成同店同日回读验证。',
        three_source_ota_facts_partial: '携程或美团目标日渠道事实尚未完成回读验证。',
        cross_source_denominator_or_ota_facts_missing: 'PMS 全酒店可售间夜或 OTA 渠道分子缺失，跨源指标不可计算。',
        source_fact_missing: '对应来源事实缺失，保持为空。',
        revenue_positive_orders_zero: 'OTA 收入大于 0 但订单数为 0，需复核来源字段。',
        revenue_positive_room_nights_zero: 'OTA 收入大于 0 但间夜为 0，需复核来源字段。',
        data_not_complete: '当前数据未达到完整口径。',
        ZERO_CONFIRMED: '渠道明确确认目标经营日期无数据。',
    }[String(reason || '')] || String(reason || '数据缺口待确认。'));

    const revenueAiScopeLabel = (scope) => ({
        ota: 'OTA渠道口径',
        ota_channel: 'OTA渠道口径',
        hotel: '全酒店口径',
        hotel_required: '需全酒店口径',
        whole_hotel_accommodation: 'PMS全酒店住宿口径',
        cross_source_comparison: '跨源分层指标',
        three_source_layered: '三源分层口径',
    }[String(scope || '')] || '口径待确认');

    const revenueAiDateBasisLabel = (dateBasis) => ({
        data_date: 'data_date',
        stay_date: 'stay_date',
        booking_date: 'booking_date',
        settlement_date: 'settlement_date',
        create_time: 'create_time',
        forecast_date: 'forecast_date',
        calendar_date: 'calendar_date',
        pms_business_date: 'PMS经营日',
        same_date_key_distinct_source_semantics: '同日键·分来源语义',
        'operation_execution_intents.date_start/date_end': '执行意图日期',
    }[String(dateBasis || '')] || 'date_basis待确认');

    const revenueAiChannelLabel = (channel) => ({
        ctrip: '携程',
        meituan: '美团',
        dingdandao_pms: 'PMS（订单来了）',
        pricing_guard: '价格底线',
        hotel: '全酒店',
        ota: 'OTA',
    }[String(channel || '').toLowerCase()] || 'OTA');

    const revenueAiSeverityLabel = (severity) => ({
        high: '高优先级',
        medium: '中优先级',
        low: '低优先级',
    }[String(severity || '').toLowerCase()] || '中优先级');

    const revenueAiSeverityClass = (severity) => ({
        high: 'bg-red-50 text-red-700 border-red-100',
        medium: 'bg-amber-50 text-amber-700 border-amber-100',
        low: 'bg-slate-50 text-slate-600 border-slate-200',
    }[String(severity || '').toLowerCase()] || 'bg-amber-50 text-amber-700 border-amber-100');

    const revenueAiMetricDefinitions = Object.freeze([
        { key: 'ota_room_revenue', label: '目标日OTA房费收入', scope: 'ota_channel', dateBasis: 'data_date' },
        { key: 'ota_room_nights', label: '目标日OTA间夜', scope: 'ota_channel', dateBasis: 'data_date' },
        { key: 'ota_adr', label: '目标日OTA ADR', scope: 'ota_channel', dateBasis: 'data_date' },
        { key: 'whole_hotel_room_revenue', label: '全酒店住宿房费', scope: 'whole_hotel_accommodation', dateBasis: 'pms_business_date' },
        { key: 'whole_hotel_sellable_room_nights', label: '全酒店可售间夜', scope: 'whole_hotel_accommodation', dateBasis: 'pms_business_date' },
        { key: 'whole_hotel_revpar', label: '全酒店住宿 RevPAR', scope: 'whole_hotel_accommodation', dateBasis: 'pms_business_date' },
        { key: 'ota_contribution_revpar', label: 'OTA收入/全酒店可售间夜', scope: 'cross_source_comparison', dateBasis: 'same_date_key_distinct_source_semantics' },
        { key: 'data_completeness', label: '数据完整度', scope: 'ota_channel', dateBasis: 'data_date' },
    ]);

    const buildRevenueAiOverviewQuery = ({ businessDate = '', hotelId = '', platform = 'ctrip' } = {}) => {
        const params = new URLSearchParams();
        const dateText = String(businessDate || '').trim();
        const hotelIdText = String(hotelId || '').trim();
        const platformText = String(platform || '').trim().toLowerCase();
        if (dateText) params.set('business_date', dateText);
        if (hotelIdText) params.set('hotel_id', hotelIdText);
        if (platformText) params.set('platform', platformText);
        return params.toString();
    };

    const buildRevenueAiOverviewEndpoint = (options = {}) => {
        const query = buildRevenueAiOverviewQuery(options);
        return `/revenue-ai/overview${query ? `?${query}` : ''}`;
    };

    const formatRevenueAiDate = (date) => {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    const resolveRevenueAiBusinessDate = ({ overview = null, selectedDate = '', now = new Date() } = {}) => {
        const overviewDate = String(overview?.business_date || '').trim();
        if (overviewDate) return overviewDate;
        const explicitDate = String(selectedDate || '').trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(explicitDate)) return explicitDate;
        const current = now instanceof Date ? new Date(now.getTime()) : new Date(now);
        if (Number.isNaN(current.getTime())) return '';
        current.setDate(current.getDate() - 1);
        return formatRevenueAiDate(current);
    };

    const resolveRevenueAiOverviewRequest = ({ hasToken = false, currentPage = '', businessDate = '', hotelId = '', platform = 'ctrip' } = {}) => {
        if (hasToken !== true) {
            return { shouldLoad: false, endpoint: '', reason: 'token_missing' };
        }
        if (!['compass', 'agent-center'].includes(String(currentPage || ''))) {
            return { shouldLoad: false, endpoint: '', reason: 'not_revenue_ai_surface' };
        }
        return {
            shouldLoad: true,
            endpoint: buildRevenueAiOverviewEndpoint({ businessDate, hotelId, platform }),
            reason: '',
        };
    };

    const resolveRevenueAiOverviewResponse = ({ response = null, error = null } = {}) => {
        if (error) {
            return {
                overview: null,
                errorMessage: error.message || 'Revenue AI 总览接口请求失败',
                ok: false,
            };
        }
        if (response && Number(response.code) === 200) {
            return {
                overview: response.data || null,
                errorMessage: '',
                ok: true,
            };
        }
        return {
            overview: null,
            errorMessage: response?.message || 'Revenue AI 总览接口返回失败',
            ok: false,
        };
    };

    const metricDisplayText = (metric) => {
        if (metric && metric.display !== undefined && metric.display !== null && metric.display !== '') {
            return String(metric.display);
        }
        if (metric && metric.value !== undefined && metric.value !== null && metric.value !== '') {
            return String(metric.value);
        }
        return '--';
    };

    const revenueAiClosureValueText = (metric = {}) => {
        const value = metric?.value;
        if (value === undefined || value === null || value === '') return '--';
        const unit = String(metric?.unit || '').toLowerCase();
        const number = Number(value);
        if (!Number.isFinite(number)) return String(value);
        if (unit === 'cny') return `¥${number.toFixed(2)}`;
        if (unit === '%') return `${number.toFixed(2)}%`;
        if (unit === 'orders') return `${number.toFixed(0)}单`;
        if (unit === 'room_nights') return `${number.toFixed(2)}间夜`;
        return String(value);
    };

    const revenueAiClosureMetric = (closure = {}, path = []) => {
        let current = closure;
        for (const key of path) {
            if (!current || typeof current !== 'object') return {};
            current = current[key];
        }
        return current && typeof current === 'object' ? current : {};
    };

    const revenueAiTruthStatusLabel = (status) => ({
        verified: '已验证',
        partial: '部分数据',
        unverified: '未验证',
        collection_failed: '采集失败',
    }[String(status || '').toLowerCase()] || '未验证');

    const revenueAiTruthStatusTone = (status) => ({
        verified: 'ok',
        partial: 'partial',
        unverified: 'unverified',
        collection_failed: 'failed',
    }[String(status || '').toLowerCase()] || 'unverified');

    const revenueAiTruthRangeText = (range = {}, fallback = '-') => {
        const start = String(range?.start || '').trim();
        const end = String(range?.end || '').trim();
        if (!start && !end) return fallback;
        if (!start || !end || start === end) return start || end;
        return `${start} 至 ${end}`;
    };

    const revenueAiMetricTruthLines = (truth = {}) => {
        const hotels = Array.isArray(truth?.hotels) ? truth.hotels : [];
        const hotelText = hotels.map((hotel) => {
            const name = String(hotel?.name || '').trim();
            const id = Number(hotel?.system_hotel_id || 0);
            return name || (id > 0 ? `门店#${id}` : '');
        }).filter(Boolean).join('、') || '-';
        const platforms = Array.isArray(truth?.platforms) ? truth.platforms : [];
        const platformText = platforms.map(revenueAiChannelLabel).filter(Boolean).join('、') || '-';
        const source = truth?.source && typeof truth.source === 'object' ? truth.source : {};
        const methods = Array.isArray(source.methods) ? source.methods.filter(Boolean).map(String) : [];
        const sourceParts = [String(source.table || '').trim(), ...methods];
        const sourceText = [...new Set(sourceParts.filter(Boolean))].join(' / ') || '-';
        const persistence = truth?.persistence && typeof truth.persistence === 'object' ? truth.persistence : {};
        const recordCount = Number(persistence.record_count || 0);
        const storedCount = Number(persistence.stored_count || 0);
        const readbackCount = Number(persistence.readback_verified_count || 0);
        const storedText = recordCount > 0
            ? `入库 ${storedCount}/${recordCount}；回读 ${readbackCount}/${recordCount}`
            : '无可核验入库记录';
        const failureReason = String(truth?.failure_reason || '').trim();
        return [
            { key: 'hotel', label: '门店', value: hotelText },
            { key: 'platform', label: '平台', value: platformText },
            { key: 'date', label: '日期', value: revenueAiTruthRangeText(truth?.date_range) },
            { key: 'source', label: '来源', value: sourceText },
            { key: 'collected', label: '采集时间', value: revenueAiTruthRangeText(truth?.collected_at_range) },
            { key: 'persistence', label: '入库', value: storedText },
            { key: 'failure', label: '失败原因', value: failureReason || '无' },
            { key: 'scope', label: '口径', value: String(truth?.scope_label || 'OTA渠道指标，不代表全酒店经营') },
        ];
    };

    const revenueAiMetricTruthSummary = (truth = {}) => revenueAiMetricTruthLines(truth)
        .map((line) => `${line.label}：${line.value}`)
        .join('；');

    const revenueAiClosureIssueRows = (items = [], fallbackType = 'missing') => {
        const rows = Array.isArray(items) ? items : [];
        return rows.slice(0, 6).map((item, index) => {
            const code = String(item?.code || item?.reason || `${fallbackType}_${index}`);
            const affected = Array.isArray(item?.affected_metrics) ? item.affected_metrics.filter(Boolean).join(' / ') : '';
            return {
                key: `${fallbackType}_${code}_${index}`,
                code,
                label: fallbackType === 'anomaly' ? '异常判断' : '缺失项说明',
                detail: item?.message || item?.display_reason || revenueAiReasonText(code.split(':').pop()),
                affected,
                severity: item?.severity || (fallbackType === 'anomaly' ? 'medium' : 'low'),
            };
        });
    };

    const revenueAiCanonicalFactLayerGaps = (overview = {}) => {
        const factLayer = overview?.three_source_fact_layer;
        if (!factLayer || typeof factLayer !== 'object') return null;
        const diagnostics = factLayer.analysis_diagnostics && typeof factLayer.analysis_diagnostics === 'object'
            ? factLayer.analysis_diagnostics
            : {};
        const diagnosticIssues = Array.isArray(diagnostics.issues) ? diagnostics.issues : null;
        const hasGapContract = Array.isArray(factLayer.analysis_gaps)
            || Array.isArray(factLayer.ai_review_gaps)
            || diagnosticIssues !== null
            || Object.prototype.hasOwnProperty.call(factLayer, 'unique_remaining_gap');
        if (!hasGapContract) return null;

        const analysisGaps = Array.isArray(factLayer.analysis_gaps) ? factLayer.analysis_gaps : [];
        const reviewGaps = Array.isArray(factLayer.ai_review_gaps) ? factLayer.ai_review_gaps : [];
        const uniqueGap = factLayer.unique_remaining_gap && typeof factLayer.unique_remaining_gap === 'object'
            ? [factLayer.unique_remaining_gap]
            : [];
        const seen = new Set();
        const sourceGaps = diagnosticIssues !== null
            ? diagnosticIssues
            : [...analysisGaps, ...reviewGaps, ...uniqueGap];
        return sourceGaps
            .filter((gap) => gap && typeof gap === 'object')
            .map((gap) => {
                const code = String(gap.code || gap.reason || 'fact_layer_gap');
                const source = String(gap.source || '');
                const dedupeKey = `${source}:${code}`;
                if (seen.has(dedupeKey)) return null;
                seen.add(dedupeKey);
                const isPricingGuard = source === 'pricing_guard' || code === 'floor_price_missing';
                const platform = source === 'ctrip_ota'
                    ? 'ctrip'
                    : (source === 'meituan_ota' ? 'meituan' : source);
                return {
                    ...gap,
                    key: gap.key || `fact-layer-${source || 'unknown'}-${code}`,
                    code,
                    reason: code,
                    type: 'missing_dataset',
                    label: isPricingGuard ? '最低保护价' : '三源事实层缺口',
                    channel: platform,
                    status: gap.status || 'missing',
                    severity: gap.severity || 'high',
                    message: gap.message || revenueAiReasonText(code),
                    display_reason: gap.display_reason || revenueAiReasonText(code),
                    affected_metrics: Array.isArray(gap.affected_metrics)
                        ? gap.affected_metrics
                        : (gap.category ? [String(gap.category)] : []),
                    next_action: gap.next_action || (
                        isPricingGuard
                            ? '为启用房型配置最低保护价，保存回显后重新审核。'
                            : '补齐对应来源并完成同店同日保存回读。'
                    ),
                    target_page: gap.target_page || (isPricingGuard ? 'agent-center' : 'online-data'),
                    target_tab: gap.target_tab || (isPricingGuard ? 'suggestions' : 'data-health'),
                    target_agent_tab: gap.target_agent_tab || (isPricingGuard ? 'revenue' : ''),
                    target_revenue_tab: gap.target_revenue_tab || (isPricingGuard ? 'suggestions' : ''),
                    target_platform: gap.target_platform || (
                        ['ctrip', 'meituan'].includes(platform) ? platform : ''
                    ),
                };
            })
            .filter(Boolean);
    };

    const revenueAiClosureMetricChip = (metric = {}, label = '', key = '') => {
        const status = metric?.status || 'unknown';
        const truth = metric?.truth && typeof metric.truth === 'object' ? metric.truth : {};
        const hasTruthStatus = ['verified', 'partial', 'unverified', 'collection_failed'].includes(String(truth?.status || '').toLowerCase());
        const truthStatus = hasTruthStatus ? String(truth.status).toLowerCase() : '';
        const reasons = Array.isArray(metric?.failure_reasons) ? metric.failure_reasons.filter(Boolean) : [];
        const reason = metric?.reason || reasons[0] || (status !== 'ok' ? status : '');
        return {
            key: key || metric?.key || label,
            label,
            value: revenueAiClosureValueText(metric),
            status,
            statusLabel: hasTruthStatus ? revenueAiTruthStatusLabel(truthStatus) : revenueAiStatusLabel(status),
            className: revenueAiStatusClass(hasTruthStatus ? revenueAiTruthStatusTone(truthStatus) : status),
            reasonText: reason ? revenueAiReasonText(reason) : '',
            truthStatus,
            truthSummary: revenueAiMetricTruthSummary(truth),
            truthLines: revenueAiMetricTruthLines(truth),
        };
    };

    const revenueAiClosureGroupStatus = (chips = []) => {
        const statuses = chips.map((chip) => String(chip?.status || 'unknown')).filter(Boolean);
        if (statuses.length === 0) return 'unknown';
        if (statuses.some((status) => ['blocked', 'failed', 'unauthorized', 'error'].includes(status))) return 'blocked';
        if (statuses.some((status) => ['warning', 'partial', 'stale', 'not_calculable', 'missing', 'unverified', 'unknown'].includes(status))) {
            return statuses.includes('ok') ? 'partial' : 'warning';
        }
        return statuses.every((status) => status === 'ok') ? 'ok' : 'unknown';
    };

    const revenueAiClosureSummaryChip = (key, label, value, status, detail = '') => ({
        key,
        label,
        value,
        status,
        statusLabel: revenueAiStatusLabel(status),
        className: revenueAiStatusClass(status),
        detail,
    });

    const revenueAiClosureNextAction = ({ calculationAllowed, missingRows, anomalyRows, operationStatus }) => {
        if (!calculationAllowed) return '先补齐已验证 OTA 数据，当前不输出收益计算结论。';
        if (anomalyRows.length > 0) return '先复核异常判断，再进入人工审核和执行证据闭环。';
        if (missingRows.length > 0) return '收益计算可用，但缺失项需保留可见并继续补齐。';
        if (!['ok', 'ready', 'reviewed'].includes(String(operationStatus || ''))) return '可进入 AI 建议输入，下一步补人工执行和效果复盘证据。';
        return '继续完成运营执行、证据记录和效果复盘。';
    };

    const buildRevenueAiBusinessClosure = ({ overview = null, overviewError = '', overviewLoading = false } = {}) => {
        if (overviewError) {
            return {
                status: 'failed',
                statusLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
                scopeText: 'OTA渠道口径',
                summary: overviewError,
                rows: [{
                    key: 'overview-failed',
                    stage: '接口',
                    title: 'Revenue AI 总览接口',
                    primary: '请求失败',
                    secondary: overviewError,
                    statusLabel: revenueAiStatusLabel('failed'),
                    className: revenueAiStatusClass('failed'),
                }],
                missingRows: [],
                anomalyRows: [{
                    key: 'overview_request_failed',
                    code: 'overview_request_failed',
                    label: '异常判断',
                    detail: overviewError,
                    severity: 'high',
                }],
                summaryChips: [],
                nextAction: '先恢复 Revenue AI 总览接口，再判断收益分析闭环。',
            };
        }

        if (!overview) {
            const status = overviewLoading ? 'not_loaded' : 'unknown';
            return {
                status,
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                scopeText: 'OTA渠道口径',
                summary: revenueAiReasonText('overview_not_loaded'),
                rows: [{
                    key: 'overview-not-loaded',
                    stage: '接口',
                    title: '等待 Revenue AI 总览',
                    primary: overviewLoading ? '加载中' : '未加载',
                    secondary: revenueAiReasonText('overview_not_loaded'),
                    statusLabel: revenueAiStatusLabel(status),
                    className: revenueAiStatusClass(status),
                }],
                missingRows: [],
                anomalyRows: [],
                summaryChips: [],
                nextAction: overviewLoading ? '等待 Revenue AI 总览加载完成。' : '先加载 Revenue AI 总览，再判断 P1 收益闭环。',
            };
        }

        const closure = overview.p1_revenue_closure || overview.metric_summary?.p1_revenue_closure || {};
        const gate = overview.metric_summary?.credibility_gate || {};
        const decisionUse = gate.decision_use || {};
        const revenueUse = closure.decision_use || decisionUse.revenue_analysis || {};
        const factLayer = overview.three_source_fact_layer && typeof overview.three_source_fact_layer === 'object'
            ? overview.three_source_fact_layer
            : {};
        const diagnostics = factLayer.analysis_diagnostics && typeof factLayer.analysis_diagnostics === 'object'
            ? factLayer.analysis_diagnostics
            : {};
        const diagnosticAnalysisAllowed = typeof diagnostics?.decision_use?.revenue_analysis?.allowed === 'boolean'
            ? diagnostics.decision_use.revenue_analysis.allowed
            : null;
        const hasFactLayerContract = Object.keys(factLayer).length > 0
            && (
                Object.prototype.hasOwnProperty.call(factLayer, 'source_completeness')
                || Object.prototype.hasOwnProperty.call(factLayer, 'analysis_gaps')
                || Object.prototype.hasOwnProperty.call(factLayer, 'facts')
            );
        const factLayerReady = diagnosticAnalysisAllowed !== null
            ? diagnosticAnalysisAllowed
            : (
                overview.revenue_analysis_status === 'ready'
                && factLayer.all_three_sources_readback_verified === true
            );
        const factLayerOtaReady = factLayer.all_three_sources_readback_verified === true
            || (
                factLayer?.sources?.ctrip_ota?.data_status === 'readback_verified'
                && factLayer?.sources?.meituan_ota?.data_status === 'readback_verified'
            );
        const useCanonicalOtaMetrics = factLayerReady || factLayerOtaReady;
        const canonicalOta = factLayer?.facts?.ota_channel?.combined || {};
        const revenueMetric = useCanonicalOtaMetrics
            ? (overview.metrics?.ota_room_revenue || {})
            : revenueAiClosureMetric(closure, ['sections', 'revenue']);
        const orderMetric = useCanonicalOtaMetrics
            ? {
                key: 'ota_orders',
                value: canonicalOta.orders ?? null,
                unit: 'orders',
                status: canonicalOta.orders === null || canonicalOta.orders === undefined ? 'not_calculable' : 'ok',
                reason: canonicalOta.orders === null || canonicalOta.orders === undefined ? 'source_fact_missing' : '',
                truth: overview.metrics?.ota_room_revenue?.truth || {},
            }
            : revenueAiClosureMetric(closure, ['sections', 'orders']);
        const roomNightMetric = useCanonicalOtaMetrics
            ? (overview.metrics?.ota_room_nights || {})
            : revenueAiClosureMetric(closure, ['sections', 'room_nights']);
        const adrMetric = useCanonicalOtaMetrics
            ? (overview.metrics?.ota_adr || {})
            : revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'adr']);
        const canonicalConversionPlaceholder = (key) => ({
            key,
            value: null,
            unit: 'percent',
            status: 'not_calculable',
            reason: '当前三源收益事实层不承载该转化指标，保持为空，不影响已回读 OTA 收益事实。',
        });
        const flowMetric = useCanonicalOtaMetrics
            ? canonicalConversionPlaceholder('flow_rate')
            : revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'flow_rate']);
        const submitMetric = useCanonicalOtaMetrics
            ? canonicalConversionPlaceholder('submit_rate')
            : revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'submit_rate']);
        const canonicalFactLayerGaps = revenueAiCanonicalFactLayerGaps(overview);
        const legacyMissingRows = revenueAiClosureIssueRows(closure.missing_items?.items, 'missing');
        const missingRows = canonicalFactLayerGaps === null
            ? legacyMissingRows
            : revenueAiClosureIssueRows(canonicalFactLayerGaps, 'missing');
        const legacyAnomalyRows = revenueAiClosureIssueRows(closure.anomaly_judgment?.items, 'anomaly');
        const anomalyRows = hasFactLayerContract && factLayerOtaReady
            ? legacyAnomalyRows.filter((row) => {
                const code = String(row?.code || '');
                return code !== 'ota_collection_quality:unverified'
                    && code !== 'p0_ota_gate_not_ready'
                    && !code.startsWith('critical_metric_untrusted:')
                    && !code.startsWith('p0_ota_gate_missing:');
            })
            : legacyAnomalyRows;
        const execution = overview.execution_summary || {};
        const operationStatus = execution.status || 'not_loaded';
        const aiDecision = decisionUse.ai_decision_support || {};
        const closureStatus = overview.revenue_analysis_status || closure.status || overview.data_status || 'unknown';
        const calculationAllowed = hasFactLayerContract
            ? factLayerReady
            : closure.calculation_allowed === true;
        const canonicalBlockingGap = Array.isArray(canonicalFactLayerGaps)
            ? (canonicalFactLayerGaps[0] || null)
            : null;
        const calculationDetail = hasFactLayerContract
            ? (
                String(diagnostics.summary || '')
                || (factLayerReady
                    ? '三源事实已完成同店同日回读。'
                    : String(
                        canonicalBlockingGap?.display_reason
                        || canonicalBlockingGap?.message
                        || '三源事实层仍有必需来源缺口。'
                    ))
            )
            : revenueAiReasonText(revenueUse.status || (calculationAllowed ? '' : 'blocked_by_data_credibility'));
        const canonicalNextAction = hasFactLayerContract
            ? String(
                diagnostics.next_action
                || (!factLayerReady
                    ? (canonicalBlockingGap?.next_action || '补齐唯一三源事实缺口后重新审核。')
                    : '')
            )
            : '';
        const aiDecisionAllowed = hasFactLayerContract ? factLayerReady : aiDecision.allowed === true;
        const metricChips = [
            revenueAiClosureMetricChip(revenueMetric, '收入', 'revenue'),
            revenueAiClosureMetricChip(orderMetric, '订单', 'orders'),
            revenueAiClosureMetricChip(roomNightMetric, '间夜', 'room_nights'),
            revenueAiClosureMetricChip(adrMetric, 'ADR', 'adr'),
            revenueAiClosureMetricChip(flowMetric, '流量转化', 'flow_rate'),
            revenueAiClosureMetricChip(submitMetric, '提交转化', 'submit_rate'),
        ];
        const revenueAnalysisStatus = factLayerReady
            ? 'ready'
            : (factLayerOtaReady ? 'partial' : revenueAiClosureGroupStatus(metricChips));
        const summaryChips = [
            revenueAiClosureSummaryChip('calculation', '收益计算', calculationAllowed ? '允许' : '阻断', calculationAllowed ? 'ok' : 'blocked', calculationDetail),
            revenueAiClosureSummaryChip('missing', '缺失项', `${missingRows.length}项`, missingRows.length > 0 ? 'warning' : 'ok', missingRows.length > 0 ? '继续补齐缺失项' : '未发现关键缺失项'),
            revenueAiClosureSummaryChip('anomaly', '异常判断', `${anomalyRows.length}项`, anomalyRows.length > 0 ? 'warning' : 'ok', anomalyRows.length > 0 ? '需人工复核' : '未命中异常'),
        ];

        return {
            status: closureStatus,
            statusLabel: revenueAiStatusLabel(closureStatus),
            className: revenueAiStatusClass(closureStatus),
            scopeText: revenueAiScopeLabel(overview.scope || closure.scope || 'ota'),
            summary: String(diagnostics.summary || '') || (
                factLayerReady
                    ? 'PMS 仅承载全酒店住宿事实，携程/美团仅承载 OTA 渠道事实；收入不跨口径相加。'
                    : (
                        factLayerOtaReady
                            ? '携程、美团 OTA 渠道事实已回读；PMS 全酒店住宿事实仍缺当前来源证据，PMS 与跨源指标保持为空，OTA 不冒充全酒店收入。'
                            : (closure.scope_statement || '仅基于已验证 OTA 渠道数据，不代表全酒店经营口径。')
                    )
            ),
            calculationAllowed,
            summaryChips,
            nextAction: canonicalNextAction || revenueAiClosureNextAction({
                calculationAllowed,
                missingRows,
                anomalyRows,
                operationStatus,
            }),
            rows: [
                {
                    key: 'ota-data',
                    stage: hasFactLayerContract ? '三源数据' : 'OTA数据',
                    title: hasFactLayerContract ? 'PMS＋携程＋美团同店同日准入' : '已验证数据准入',
                    primary: calculationAllowed ? '可进入收益计算' : '阻断收益计算',
                    secondary: hasFactLayerContract
                        ? (
                            factLayerReady
                                ? 'PMS=全酒店住宿；携程/美团=OTA渠道；缺失值保持为空。'
                                : '携程/美团 OTA 已回读；PMS 与跨源指标继续为空，等待当前来源证据通过。'
                        )
                        : (closure.scope_statement || '只读取 OTA 渠道事实和 metric_trust。'),
                    statusLabel: revenueAiStatusLabel(closureStatus),
                    className: revenueAiStatusClass(closureStatus),
                },
                {
                    key: 'revenue-analysis',
                    stage: '收益分析',
                    title: '收入 / 订单 / 间夜 / ADR / 转化',
                    primary: `${revenueAiClosureValueText(revenueMetric)} · ${revenueAiClosureValueText(orderMetric)} · ${revenueAiClosureValueText(roomNightMetric)}`,
                    secondary: `ADR ${revenueAiClosureValueText(adrMetric)} · 流量 ${revenueAiClosureValueText(flowMetric)} · 提交 ${revenueAiClosureValueText(submitMetric)}`,
                    metrics: metricChips,
                    statusLabel: revenueAiStatusLabel(revenueAnalysisStatus),
                    className: revenueAiStatusClass(revenueAnalysisStatus),
                },
                {
                    key: 'ai-decision',
                    stage: 'AI决策',
                    title: '只读建议输入',
                    primary: aiDecisionAllowed ? '可作为 AI 输入' : 'AI 输入阻断',
                    secondary: calculationDetail,
                    statusLabel: revenueAiStatusLabel(aiDecisionAllowed ? 'ready' : 'blocked'),
                    className: revenueAiStatusClass(aiDecisionAllowed ? 'ready' : 'blocked'),
                },
                {
                    key: 'operation-execution',
                    stage: '运营执行',
                    title: '人工执行闭环',
                    primary: execution.display || revenueAiStatusLabel(operationStatus),
                    secondary: canonicalNextAction || (
                        execution.reason
                            ? revenueAiReasonText(execution.reason)
                            : '建议需人工审核、执行证据和效果复盘。'
                    ),
                    statusLabel: revenueAiStatusLabel(operationStatus),
                    className: revenueAiStatusClass(operationStatus),
                },
            ],
            missingRows,
            anomalyRows,
            validationAssessment: String(diagnostics.overall_assessment || ''),
            qualityChecks: Array.isArray(diagnostics.checks) ? diagnostics.checks : [],
        };
    };

    const buildRevenueAiMetricCards = ({ overview = null, overviewError = '' } = {}) => {
        const metrics = overview?.metrics || {};
        return revenueAiMetricDefinitions.map((definition) => {
            const metric = metrics[definition.key] || {};
            const reason = overviewError ? 'overview_request_failed' : (metric.reason || (overview ? '' : 'overview_not_loaded'));
            const status = overviewError ? 'failed' : (metric.status || (overview ? 'unknown' : 'not_loaded'));
            const truth = metric?.truth && typeof metric.truth === 'object' ? metric.truth : {};
            const hasTruthStatus = ['verified', 'partial', 'unverified', 'collection_failed'].includes(String(truth?.status || '').toLowerCase());
            const truthStatus = overviewError ? 'collection_failed' : (hasTruthStatus ? String(truth.status).toLowerCase() : '');
            return {
                key: definition.key,
                label: metric.label || definition.label,
                display: metricDisplayText(metric),
                statusLabel: overviewError || hasTruthStatus ? revenueAiTruthStatusLabel(truthStatus) : revenueAiStatusLabel(status),
                className: revenueAiStatusClass(overviewError || hasTruthStatus ? revenueAiTruthStatusTone(truthStatus) : status),
                metricStatusLabel: revenueAiStatusLabel(status),
                reasonText: truth?.failure_reason || metric.display_reason || revenueAiReasonText(reason),
                scopeLabel: revenueAiScopeLabel(metric.scope || definition.scope || overview?.scope || 'ota'),
                dateBasisLabel: revenueAiDateBasisLabel(metric.date_basis || definition.dateBasis || overview?.date_basis || 'data_date'),
                truth,
                truthStatus,
                truthLines: revenueAiMetricTruthLines(truth),
                truthSummary: revenueAiMetricTruthSummary(truth),
                target_page: metric.target_page || 'online-data',
                target_tab: metric.target_tab || 'data-health',
                target_platform: metric.target_platform || '',
            };
        });
    };

    const buildRevenueAiGapRows = ({ overview = null, overviewError = '', overviewLoading = false } = {}) => {
        if (overviewError) {
            return [{
                key: 'overview_request_failed',
                label: 'Revenue AI总览接口',
                channelLabel: '系统',
                statusLabel: revenueAiStatusLabel('failed'),
                statusClass: revenueAiStatusClass('failed'),
                severityLabel: revenueAiSeverityLabel('high'),
                severityClass: revenueAiSeverityClass('high'),
                reasonText: overviewError || revenueAiReasonText('overview_request_failed'),
                nextAction: '检查接口返回和登录状态。',
                target_page: 'online-data',
                target_tab: 'data-health',
            }];
        }

        if (!overview) {
            const status = overviewLoading ? 'not_loaded' : 'unknown';
            return [{
                key: 'overview_not_loaded',
                label: 'Revenue AI总览接口',
                channelLabel: '系统',
                statusLabel: revenueAiStatusLabel(status),
                statusClass: revenueAiStatusClass(status),
                severityLabel: revenueAiSeverityLabel('medium'),
                severityClass: revenueAiSeverityClass('medium'),
                reasonText: revenueAiReasonText('overview_not_loaded'),
                nextAction: '等待 /api/revenue-ai/overview 返回。',
                target_page: 'online-data',
                target_tab: 'data-health',
            }];
        }

        const canonicalFactLayerGaps = revenueAiCanonicalFactLayerGaps(overview);
        const channelMetricGaps = Array.isArray(overview.channel_metric_gaps)
            ? overview.channel_metric_gaps
            : [];
        const missing = Array.isArray(overview.missing_datasets) ? overview.missing_datasets : [];
        const issues = Array.isArray(overview.quality_issues) ? overview.quality_issues : [];
        const channelReasons = new Set(channelMetricGaps.map((row) => String(row?.reason || '')).filter(Boolean));
        const legacyRows = [...missing, ...issues].filter((row) => {
            const reason = String(row?.reason || '');
            const channel = String(row?.channel || row?.target_platform || '').toLowerCase();
            return !(channelReasons.has(reason) && ['', 'ota', 'ota_channel'].includes(channel));
        });
        const sourceRows = canonicalFactLayerGaps === null
            ? [...channelMetricGaps, ...legacyRows]
            : [...channelMetricGaps, ...canonicalFactLayerGaps];
        const seen = new Set();
        const rows = sourceRows.filter((row, index) => {
            const key = String(row?.key || `${row?.channel || row?.target_platform || ''}:${row?.reason || ''}:${index}`);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        return rows.map((row, index) => {
            const channel = row.channel || row.target_platform || '';
            const status = row.status || (row.type === 'missing_dataset' ? 'empty' : 'unknown');
            const severity = row.severity || 'medium';
            return {
                key: row.key || `${row.reason || 'issue'}_${index}`,
                label: row.label || (row.type === 'missing_dataset' ? '缺失数据集' : '数据质量问题'),
                channelLabel: revenueAiChannelLabel(channel),
                statusLabel: revenueAiStatusLabel(status),
                statusClass: revenueAiStatusClass(status),
                severityLabel: revenueAiSeverityLabel(severity),
                severityClass: revenueAiSeverityClass(severity),
                reasonText: row.display_reason || revenueAiReasonText(row.reason),
                nextAction: row.next_action || '进入数据健康面板复核。',
                target_page: row.target_page || 'online-data',
                target_tab: row.target_tab || 'data-health',
                target_platform: row.target_platform || '',
                target_agent_tab: row.target_agent_tab || '',
                target_revenue_tab: row.target_revenue_tab || '',
                hotel_id: row.hotel_id ?? overview.hotel_id ?? null,
                business_date: row.business_date || overview.business_date || '',
                acceptanceCheck: row.acceptance_check || '',
                forbiddenShortcut: row.forbidden_shortcut || '',
                completionState: row.completion_state || '',
                raw: row,
            };
        });
    };

    const buildRevenueAiGapSummary = (rows = []) => ({
        total: rows.length,
        high: rows.filter((row) => row.severityLabel === revenueAiSeverityLabel('high')).length,
    });

    const resolveRevenueAiGapTarget = (row = {}) => {
        const raw = row.raw && typeof row.raw === 'object' ? row.raw : {};
        return {
            targetPage: row.target_page || row.targetPage || 'online-data',
            targetTab: row.target_tab || row.targetTab || 'data-health',
            targetPlatform: row.target_platform || row.targetPlatform || raw.target_platform || raw.targetPlatform || '',
            targetAgentTab: row.target_agent_tab || row.targetAgentTab || '',
            targetRevenueTab: row.target_revenue_tab || row.targetRevenueTab || '',
            hotelId: String(row.hotel_id ?? row.hotelId ?? raw.hotel_id ?? raw.hotelId ?? '').trim(),
            businessDate: String(row.business_date || row.businessDate || raw.business_date || raw.businessDate || '').trim(),
        };
    };

    const resolveRevenueAiDecisionBasisNavigation = (basis = {}) => ({
        targetPage: String(basis.targetPage || basis.target_page || '').trim(),
        targetTab: String(basis.targetTab || basis.target_tab || '').trim(),
        targetAgentTab: String(basis.targetAgentTab || basis.target_agent_tab || '').trim(),
        targetRevenueTab: String(basis.targetRevenueTab || basis.target_revenue_tab || '').trim(),
        targetFilter: basis.targetFilter || basis.target_filter || {},
        nextAction: String(basis.nextAction || basis.next_action || '').trim(),
        label: String(basis.label || '').trim(),
    });

    const buildRevenueAiStatusRows = ({
        readiness = {},
        overview = null,
        ctripStatus = overview?.channel_statuses?.ctrip || null,
        meituanStatus = overview?.channel_statuses?.meituan || null,
        lastSyncedAt = overview?.last_success_at || '--',
        completeness = overview?.data_completeness || null,
        overviewStatus = 'unknown',
        overviewLoading = false,
        overviewError = '',
        hotelName = '全部门店',
        hasHotelFilter = false,
        businessDate = '',
    } = {}) => {
        const normalizedOverviewStatus = overviewError
            ? 'failed'
            : (overview?.revenue_analysis_status || overview?.data_status || (overviewLoading ? 'not_loaded' : overviewStatus || 'unknown'));
        const isThreeSourceLayered = overview?.scope === 'three_source_layered';
        const readinessPercent = readiness?.percent !== null
            && readiness?.percent !== undefined
            && readiness?.percent !== ''
            && Number.isFinite(Number(readiness.percent))
            ? Math.max(0, Math.min(100, Number(readiness.percent)))
            : null;
        return [
            {
                key: 'hotel',
                label: '当前酒店',
                value: hotelName || '全部门店',
                status: hasHotelFilter ? '已选择' : '全部',
                detail: '沿用当前账号可见门店范围，不扩大数据权限。',
                className: revenueAiStatusClass(hasHotelFilter ? 'ok' : 'blocked'),
            },
            {
                key: 'business-date',
                label: '经营日期',
                value: businessDate || '--',
                status: revenueAiDateBasisLabel(overview?.date_basis || 'data_date'),
                detail: overview?.date_basis_note || 'Phase 1A 默认 data_date；尚未等同于入住日、下单日或结算日。',
                className: revenueAiStatusClass('blocked'),
            },
            {
                key: 'scope',
                label: '数据口径',
                value: revenueAiScopeLabel(overview?.scope || 'ota'),
                status: isThreeSourceLayered
                    ? 'PMS全酒店住宿 + OTA渠道'
                    : (overview?.scope === 'hotel' ? '全酒店' : '非全酒店'),
                detail: isThreeSourceLayered
                    ? '同店同日分层读取：PMS 不与 OTA 收入相加，OTA 不冒充全酒店收入。'
                    : '只把已验证 OTA 口径作为首页判断输入，不包装成全酒店经营结论。',
                className: revenueAiStatusClass(isThreeSourceLayered ? 'ready' : 'warning'),
            },
            ...(isThreeSourceLayered ? [{
                key: 'pms',
                label: 'PMS状态',
                value: overview?.three_source_fact_layer?.sources?.dingdandao_pms?.data_status === 'readback_verified'
                    ? '已保存并回读'
                    : '--',
                status: revenueAiStatusLabel(
                    overview?.three_source_fact_layer?.sources?.dingdandao_pms?.data_status === 'readback_verified'
                        ? 'ready'
                        : 'unverified',
                ),
                detail: '仅作为全酒店住宿收入、可售间夜与 RevPAR 来源。',
                className: revenueAiStatusClass(
                    overview?.three_source_fact_layer?.sources?.dingdandao_pms?.data_status === 'readback_verified'
                        ? 'ready'
                        : 'unverified',
                ),
            }] : []),
            {
                key: 'ctrip',
                label: '携程状态',
                value: ctripStatus?.label || '--',
                status: revenueAiStatusLabel(ctripStatus?.status || 'unknown'),
                detail: ctripStatus?.detail || revenueAiReasonText(ctripStatus?.reason || 'source_status_missing'),
                className: revenueAiStatusClass(ctripStatus?.status || 'unknown'),
            },
            {
                key: 'meituan',
                label: '美团状态',
                value: meituanStatus?.label || '--',
                status: revenueAiStatusLabel(meituanStatus?.status || 'unknown'),
                detail: meituanStatus?.detail || revenueAiReasonText(meituanStatus?.reason || 'source_status_missing'),
                className: revenueAiStatusClass(meituanStatus?.status || 'unknown'),
            },
            {
                key: 'last-success',
                label: '最后同步时间',
                value: lastSyncedAt,
                status: lastSyncedAt === '--' ? '无成功证据' : '已同步',
                detail: lastSyncedAt === '--' ? '未找到目标口径下的成功同步时间。' : '来自 OTA 入库行或平台数据源成功同步时间。',
                className: revenueAiStatusClass(lastSyncedAt === '--' ? 'unknown' : 'ok'),
            },
            {
                key: 'completeness',
                label: '数据完整度',
                value: completeness?.display || (readinessPercent === null ? '--' : (readiness.summaryText || `${readinessPercent}%`)),
                status: completeness?.status
                    ? revenueAiStatusLabel(completeness.status)
                    : (readinessPercent === null ? '完整度未返回' : `${readinessPercent}%`),
                detail: completeness?.reason ? revenueAiReasonText(completeness.reason) : (readiness.missingText || '等待核心数据状态生成。'),
                className: revenueAiStatusClass(completeness?.status || (readinessPercent === null ? 'unknown' : (readinessPercent >= 100 ? 'ok' : 'warning'))),
            },
            {
                key: 'overview',
                label: 'Revenue AI接口',
                value: overviewLoading ? '加载中' : (overviewError ? '请求失败' : revenueAiStatusLabel(normalizedOverviewStatus)),
                status: overviewLoading ? '加载中' : revenueAiStatusLabel(normalizedOverviewStatus),
                detail: overviewError || (overview ? '已读取只读聚合接口。' : '等待 /api/revenue-ai/overview 返回。'),
                className: revenueAiStatusClass(normalizedOverviewStatus),
            },
        ];
    };

    const buildRevenueAiSignalRows = ({ overview = null } = {}) => {
        const signals = overview?.signals || {};
        const definitions = [
            { key: 'holiday_event', label: '事件/节假日影响' },
            { key: 'demand_7d', label: '未来7天需求信号' },
            { key: 'competitor_price_warning', label: '竞对价格倒挂预警' },
            { key: 'booking_window_adr', label: '提前期房费结构' },
            { key: 'channel_booking_window_month', label: '渠道预售窗口' },
            { key: 'pricing_advice', label: '今日调价建议' },
        ];
        return definitions.map((definition) => {
            const signal = signals[definition.key] || {};
            const status = signal.status || (overview ? 'unknown' : 'not_loaded');
            const reason = signal.reason || (overview ? '' : 'overview_not_loaded');
            return {
                key: definition.key,
                label: signal.label || definition.label,
                value: signal.value || '--',
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                reasonText: signal.detail || revenueAiReasonText(reason),
            };
        });
    };

    const buildRevenueAiReviewQueueItems = (reviewQueue = {}) => {
        const pendingItems = Array.isArray(reviewQueue.pending_items) ? reviewQueue.pending_items : [];
        const recentItems = Array.isArray(reviewQueue.recent_items) ? reviewQueue.recent_items : [];
        const seen = new Set();
        const sourceItems = [...pendingItems, ...recentItems].filter((item, index) => {
            const key = item?.id ? `id:${item.id}` : `idx:${index}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        const numericPrice = (value) => {
            const number = Number(value);
            return Number.isFinite(number) && number > 0 ? number : null;
        };
        return sourceItems.slice(0, 5).map((item, index) => {
            const status = item.status || 'unknown';
            const actionEntry = item.action_entry && typeof item.action_entry === 'object' ? item.action_entry : {};
            const manualActions = Array.isArray(actionEntry.manual_actions) ? actionEntry.manual_actions : [];
            const allowedEndpoints = actionEntry.allowed_endpoints && typeof actionEntry.allowed_endpoints === 'object'
                ? actionEntry.allowed_endpoints
                : {};
            const trustedDecision = item.trusted_decision && typeof item.trusted_decision === 'object'
                ? item.trusted_decision
                : {};
            const trustedStore = trustedDecision.store && typeof trustedDecision.store === 'object' ? trustedDecision.store : {};
            const trustedPlatform = trustedDecision.platform && typeof trustedDecision.platform === 'object' ? trustedDecision.platform : {};
            const trustedDate = trustedDecision.date && typeof trustedDecision.date === 'object' ? trustedDecision.date : {};
            const trustedSources = trustedDecision.sources && typeof trustedDecision.sources === 'object' ? trustedDecision.sources : {};
            const trustedFormula = trustedDecision.metric_formula && typeof trustedDecision.metric_formula === 'object'
                ? trustedDecision.metric_formula
                : {};
            const trustedQuality = trustedDecision.data_quality && typeof trustedDecision.data_quality === 'object'
                ? trustedDecision.data_quality
                : {};
            const trustedConfidence = trustedDecision.confidence && typeof trustedDecision.confidence === 'object'
                ? trustedDecision.confidence
                : {};
            const trustedAction = trustedDecision.recommended_action && typeof trustedDecision.recommended_action === 'object'
                ? trustedDecision.recommended_action
                : {};
            const trustedExpectedEffect = trustedDecision.expected_effect && typeof trustedDecision.expected_effect === 'object'
                ? trustedDecision.expected_effect
                : {};
            const trustedConfirmation = trustedDecision.human_confirmation && typeof trustedDecision.human_confirmation === 'object'
                ? trustedDecision.human_confirmation
                : {};
            const trustedContractValid = trustedDecision.contract_version === 'revenue_ai_trusted_decision.v1';
            const canConfirmTrusted = trustedContractValid && trustedConfirmation.can_confirm === true && item.can_confirm === true;
            const canTransferTrusted = trustedContractValid
                && trustedConfirmation.can_transfer_to_operation_task === true
                && item.can_transfer_to_operation_task === true;
            const canApprove = item.can_review === true && canConfirmTrusted && manualActions.includes('approve') && !!allowedEndpoints.review;
            const canApproveWithChanges = item.can_review === true && canConfirmTrusted && manualActions.includes('approve_with_changes') && !!allowedEndpoints.review;
            const canReject = item.can_review === true && manualActions.includes('reject') && !!allowedEndpoints.review;
            const canCreateExecutionIntent = canTransferTrusted
                && manualActions.includes('create_execution_intent')
                && !!allowedEndpoints.execution_intent;
            const actionButtons = [
                canApprove ? { key: 'approve', label: '批准' } : null,
                canApproveWithChanges ? { key: 'approve_with_changes', label: '改价批准' } : null,
                canReject ? { key: 'reject', label: '拒绝' } : null,
                canCreateExecutionIntent ? { key: 'execution_intent', label: '转运营任务' } : null,
            ].filter(Boolean);
            const currentPrice = numericPrice(item.current_price);
            const suggestedPrice = numericPrice(item.suggested_price);
            const minPrice = numericPrice(item.min_price);
            const maxPrice = numericPrice(item.max_price);
            const titleParts = [];
            if (item.room_type_id) titleParts.push(`房型 #${item.room_type_id}`);
            if (item.suggestion_type_label && item.suggestion_type_label !== '--') titleParts.push(item.suggestion_type_label);
            const priceParts = [
                `当前 ${item.current_price_display || '--'}`,
                `建议 ${item.suggested_price_display || '--'}`,
                `最低保护 ${item.min_price_display || '--'}`,
            ];
            const evidenceParts = [];
            if (item.competitor_summary && item.competitor_summary !== '--') evidenceParts.push(item.competitor_summary);
            if (item.confidence_display && item.confidence_display !== '--') evidenceParts.push(`可信度 ${item.confidence_display}`);
            if (item.price_change_display && item.price_change_display !== '--') evidenceParts.push(`调整 ${item.price_change_display}`);
            const revparImpactDisplay = item.expected_revpar_impact_display || '--';
            const revparImpactReason = item.expected_revpar_impact_reason || 'expected_revpar_impact_missing';
            const impactLine = revparImpactDisplay !== '--'
                ? `预计RevPAR影响 ${revparImpactDisplay}`
                : `预计RevPAR影响 -- ${revenueAiReasonText(revparImpactReason)}`;
            const reason = item.reason && item.reason !== '--'
                ? item.reason
                : (item.missing_reason ? '关键价格字段不完整，需补齐后再审核。' : '建议原因待补充。');
            const sourceItems = Array.isArray(trustedSources.items) ? trustedSources.items : [];
            const sourceRefs = sourceItems.map(source => {
                if (typeof source === 'string') return source;
                if (!source || typeof source !== 'object') return '';
                return String(source.ref || source.source_ref || source.key || source.source || '').trim();
            }).filter(Boolean);
            const sourceText = sourceRefs.length
                ? sourceRefs.join(' / ')
                : (trustedSources.summary || '未提供已验证来源');
            const formulaDisplay = trustedFormula.status === 'calculable'
                ? (trustedFormula.display || '--')
                : '不可计算';
            const formulaText = trustedFormula.expression
                ? `${trustedFormula.expression} = ${formulaDisplay}`
                : formulaDisplay;
            const gapRows = Array.isArray(trustedDecision.gaps) ? trustedDecision.gaps : [];
            const gapText = gapRows.length
                ? gapRows.map(gap => typeof gap === 'string' ? gap : (gap?.message || gap?.code || '')).filter(Boolean).join('；')
                : '无输入缺口';
            const expectedEffectText = trustedExpectedEffect.display
                || trustedExpectedEffect.summary
                || '待执行后按同口径回读验证';
            const trustedDecisionRows = [
                { key: 'store', label: '门店', value: trustedStore.display || (item.hotel_id ? `门店 #${item.hotel_id}` : '门店未绑定') },
                { key: 'platform', label: '平台', value: trustedPlatform.label || trustedPlatform.key || '未绑定' },
                { key: 'date', label: '日期', value: trustedDate.value || item.suggestion_date || '未提供' },
                { key: 'source', label: '来源', value: sourceText },
                { key: 'formula', label: '指标公式', value: formulaText, status: trustedFormula.status || 'not_calculable' },
                { key: 'quality', label: '数据质量', value: [trustedQuality.label || trustedQuality.status || '未验证', trustedQuality.note || ''].filter(Boolean).join(' · ') },
                { key: 'confidence', label: '置信度', value: trustedConfidence.display || '不可计算' },
                { key: 'gaps', label: '缺口', value: gapText },
                { key: 'action', label: '建议动作', value: trustedAction.summary || reason },
                { key: 'effect', label: '预期效果', value: expectedEffectText },
            ];
            return {
                key: item.id || `${status}_${index}`,
                id: item.id || 0,
                hotelId: item.hotel_id || 0,
                title: titleParts.length ? titleParts.join(' · ') : `建议 #${item.id || index + 1}`,
                statusLabel: item.status_label || revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                suggestionDate: item.suggestion_date || '--',
                currentPrice,
                suggestedPrice,
                minPrice,
                maxPrice,
                priceLine: priceParts.join(' / '),
                evidenceLine: evidenceParts.length ? evidenceParts.join(' / ') : '--',
                impactLine,
                revparImpactDisplay,
                revparImpactStatus: item.expected_revpar_impact_status || 'not_calculable',
                revparImpactReason,
                factorLine: item.factors_summary || '--',
                reasonText: reason,
                trustedDecision,
                trustedDecisionRows,
                trustedContractValid,
                trustedInputReady: canConfirmTrusted || canTransferTrusted,
                manualReviewRequired: item.manual_review_required !== false,
                autoWriteOta: item.auto_write_ota === true,
                canReview: item.can_review === true,
                canApprove,
                canApproveWithChanges,
                canReject,
                canCreateExecutionIntent,
                actionButtons,
                actionEntry,
                actionLabel: canCreateExecutionIntent ? '转运营任务' : (canApprove || canApproveWithChanges || canReject ? '审核' : (actionEntry.label || '')),
                actionHelpText: canCreateExecutionIntent
                    ? '生成本地待执行运营任务；仍需人工执行、留证和复盘'
                    : (canApprove || canApproveWithChanges || canReject
                        ? '仅在可信输入通过后人工审核；不写 OTA'
                        : (trustedContractValid ? '当前可信输入仍有阻塞，先处理缺口' : '可信建议契约缺失，禁止批准')),
                requiresSuperAdmin: actionEntry.requires_super_admin === true,
                requiresHotelPermission: actionEntry.requires_hotel_permission === true,
                allowedEndpoint: actionEntry.allowed_endpoint || '',
                allowedEndpoints,
            };
        });
    };

    const revenueAiDecisionBasisPriority = (item = {}) => {
        const status = String(item.status || '').trim();
        const severity = String(item.severity || '').trim();
        if (status !== 'ok') {
            if (severity === 'high') return 0;
            if (severity === 'medium') return 1;
            if (severity === 'low') return 2;
            return 3;
        }
        return 10;
    };

    const buildRevenueAiResolutionPlanSummary = ({ overview = null, action = null } = {}) => {
        const candidates = [
            action?.ai_decision_resolution_plan,
            action?.ai_decision_review_contract?.resolution_plan,
            overview?.pricing_readiness?.ai_decision_resolution_plan,
            overview?.pricing_readiness?.ai_decision_review_contract?.resolution_plan,
        ];
        const plan = candidates.find(item => item && typeof item === 'object' && Object.keys(item).length > 0) || {};
        const items = Array.isArray(plan.items) ? plan.items : [];
        if (Object.keys(plan).length === 0 && items.length === 0) {
            return {
                visible: false,
                status: 'not_loaded',
                statusLabel: revenueAiStatusLabel('not_loaded'),
                className: revenueAiStatusClass('not_loaded'),
                sourceScope: '',
                sourceChannels: [],
                itemCount: 0,
                pendingCount: 0,
                hiddenCount: 0,
                display: '',
                items: [],
            };
        }
        const asList = (value) => Array.isArray(value) ? value.map(item => String(item || '').trim()).filter(Boolean) : [];
        const sourceChannels = asList(plan.source_channels);
        const status = String(plan.status || (items.length ? 'has_pending_evidence' : 'ready_for_ai_review'));
        const pendingCount = Number(plan.pending_count ?? items.filter(item => String(item?.status || '') !== 'ok').length);
        const itemCount = Number(plan.item_count ?? items.length);
        const visibleItems = items.slice(0, 5).map((item, index) => {
            const severity = String(item?.severity || 'medium').toLowerCase();
            const code = String(item?.code || item?.evidence_code || `resolution_${index + 1}`);
            return {
                key: code,
                order: Number(item?.order || index + 1),
                code,
                evidenceCode: String(item?.evidence_code || ''),
                inputType: String(item?.input_type || ''),
                statusLabel: revenueAiStatusLabel(item?.status || 'blocked'),
                className: revenueAiStatusClass(item?.status || 'blocked'),
                severity,
                severityLabel: revenueAiSeverityLabel(severity),
                severityClass: revenueAiSeverityClass(severity),
                resolutionAction: String(item?.resolution_action || item?.next_action || code),
                acceptanceCheck: String(item?.acceptance_check || ''),
                unblocks: String(item?.unblocks || ''),
                forbiddenShortcut: String(item?.forbidden_shortcut || ''),
                targetPage: String(item?.target_page || ''),
                targetTab: String(item?.target_tab || ''),
                targetPlatform: String(item?.target_platform || ''),
                targetAgentTab: String(item?.target_agent_tab || ''),
                targetRevenueTab: String(item?.target_revenue_tab || ''),
                canOpenTarget: Boolean(item?.target_page),
            };
        });
        return {
            visible: true,
            status,
            statusLabel: revenueAiStatusLabel(status === 'ready_for_ai_review' ? 'pending_review' : 'evidence_needed'),
            className: revenueAiStatusClass(status === 'ready_for_ai_review' ? 'pending_review' : 'blocked'),
            sourceScope: String(plan.source_scope || ''),
            sourceChannels,
            itemCount,
            pendingCount,
            hiddenCount: Math.max(0, items.length - visibleItems.length),
            display: `AI决策补证 ${pendingCount}/${itemCount}`,
            items: visibleItems,
        };
    };

    const revenueAiPricingGenerationStatusLabel = (status) => ({
        ready_for_manual_generation: '可生成待审',
        pending_review_exists: '已有待审',
        skipped_by_operator_policy: '缺口仍阻断',
        partial: '部分生成',
        blocked: '生成受阻',
        failed: '预检失败',
        not_loaded: '未加载',
    }[String(status || '').toLowerCase()] || revenueAiStatusLabel(status || 'unknown'));

    const revenueAiPricingGenerationReasonText = (reason) => ({
        price_suggestion_generation_not_loaded: '调价建议生成预检尚未加载。',
        pricing_generation_hotel_scope_missing: '调价建议生成缺少目标系统酒店范围。',
        room_types_empty: '携程目标酒店暂无启用房型，不能生成待审调价建议。',
        missing_pricing_inputs_skipped_by_operator_policy: '旧记录缺少可核验的操作者、确认时间和持久化记录，按定价输入缺口阻断。',
        exact_target_signals_missing: '目标入住日、目标房型的需求预测或携程竞品价格证据不完整；不使用旧日或酒店级样本补齐。',
        price_suggestion_generation_in_progress: '同一酒店已有远期定价生成任务正在执行，本次未重复写入。',
        price_suggestion_generation_lock_unavailable: '远期定价生成互斥锁不可用，本次已安全阻断写入。',
        pricing_candidate_signals_missing: '调价候选信号不足，当前不会生成待审建议。',
        pricing_generation_candidates_ready: '已存在可生成待审调价建议的只读候选。',
        price_suggestions_pending_review: '存在待人工审核调价建议。',
    }[String(reason || '')] || revenueAiReasonText(reason || 'overview_not_loaded'));

    const buildRevenueAiPricingGenerationPreflightSummary = ({ overview = null, action = null } = {}) => {
        const candidates = [
            action?.pricing_generation_preflight,
            overview?.pricing_generation_preflight,
            overview?.pricing_readiness?.pricing_generation_preflight,
        ];
        const preflight = candidates.find(item => item && typeof item === 'object' && Object.keys(item).length > 0) || {};
        if (Object.keys(preflight).length === 0) {
            return { visible: false };
        }

        const rawStatus = String(preflight.status || 'unknown');
        const legacyUnverifiedSkip = rawStatus === 'skipped_by_operator_policy';
        const status = legacyUnverifiedSkip ? 'blocked' : rawStatus;
        const reason = String(preflight.reason || '');
        const targetFilter = preflight.target_filter && typeof preflight.target_filter === 'object'
            ? preflight.target_filter
            : {};
        const rawRequiredInputs = Array.isArray(preflight.required_inputs) ? preflight.required_inputs : [];
        const requiredInputs = rawRequiredInputs
            .map((item) => ({
                code: String(item?.code || ''),
                source: String(item?.source || ''),
                status: String(item?.status || '') === 'skipped_by_operator_policy' ? 'missing_or_blocked' : String(item?.status || ''),
                nextAction: String(item?.next_action || ''),
            }))
            .filter(item => item.code)
            .slice(0, 4);
        const rawCandidateSkipReasons = Array.isArray(preflight.candidate_skip_reasons)
            ? preflight.candidate_skip_reasons.map(String).filter(Boolean)
            : [];
        const rawCandidateDataGaps = Array.isArray(preflight.candidate_data_gaps)
            ? preflight.candidate_data_gaps.map(String).filter(Boolean)
            : [];
        const rawHotelChecks = Array.isArray(preflight.hotel_checks) ? preflight.hotel_checks : [];
        const hotelChecks = rawHotelChecks
            .map((item, index) => {
                const skipReasons = Array.isArray(item?.skip_reasons)
                    ? item.skip_reasons.map(String).filter(Boolean)
                    : [];
                return {
                    key: `${Number(item?.hotel_id || 0) || 'hotel'}-${index}`,
                    hotelId: Number(item?.hotel_id || 0),
                    targetDateRows: Number(item?.target_date_rows || 0),
                    roomTypeCount: Number(item?.room_type_count || 0),
                    pendingSuggestions: Number(item?.pending_suggestions || 0),
                    demandForecasts: Number(item?.demand_forecasts || 0),
                    competitorAnalysisRecent: Number(item?.competitor_analysis_recent || 0),
                    createCandidateCount: Number(item?.create_candidate_count || 0),
                    skippedCandidateCount: Number(item?.skipped_candidate_count || 0),
                    skipReasons: skipReasons.slice(0, 3),
                    hiddenSkipReasonCount: Math.max(0, skipReasons.length - 3),
                };
            })
            .filter(item => item.hotelId > 0 || item.targetDateRows > 0 || item.roomTypeCount > 0 || item.skipReasons.length > 0)
            .slice(0, 4);
        const targetHotelIds = Array.isArray(preflight.target_hotel_ids)
            ? preflight.target_hotel_ids.map(item => Number(item || 0)).filter(item => item > 0)
            : [];
        const detailParts = [
            targetHotelIds.length ? `酒店 ${targetHotelIds.join(' / ')}` : '',
            `OTA行 ${Number(preflight.target_date_rows || 0)}`,
            `房型 ${Number(preflight.room_type_count || 0)}`,
            `候选 ${Number(preflight.create_candidate_count || 0)}`,
            `待审 ${Number(preflight.pending_suggestion_count || 0)}`,
        ].filter(Boolean);

        return {
            visible: status !== 'not_loaded',
            title: '调价建议生成预检',
            status,
            statusLabel: revenueAiPricingGenerationStatusLabel(status),
            className: revenueAiStatusClass(status),
            reasonText: legacyUnverifiedSkip
                ? revenueAiPricingGenerationReasonText('missing_pricing_inputs_skipped_by_operator_policy')
                : (String(preflight.detail || '') || revenueAiPricingGenerationReasonText(reason)),
            nextAction: String(preflight.next_action || ''),
            detailText: detailParts.join(' · '),
            sourceScope: String(preflight.source_scope || ''),
            sourceChannels: Array.isArray(preflight.source_channels) ? preflight.source_channels.map(String) : [],
            targetHotelIds,
            targetHotelCount: Number(preflight.target_hotel_count || targetHotelIds.length || 0),
            targetDateRows: Number(preflight.target_date_rows || 0),
            roomTypeCount: Number(preflight.room_type_count || 0),
            createCandidateCount: Number(preflight.create_candidate_count || 0),
            skippedCandidateCount: Number(preflight.skipped_candidate_count || 0),
            pendingSuggestionCount: Number(preflight.pending_suggestion_count || 0),
            candidateSkipReasons: rawCandidateSkipReasons.slice(0, 4),
            hiddenCandidateSkipReasonCount: Math.max(0, rawCandidateSkipReasons.length - 4),
            candidateDataGaps: rawCandidateDataGaps.slice(0, 5),
            hiddenCandidateDataGapCount: Math.max(0, rawCandidateDataGaps.length - 5),
            hotelChecks,
            hiddenHotelCheckCount: Math.max(0, rawHotelChecks.length - hotelChecks.length),
            requiredInputs,
            hiddenRequiredInputCount: Math.max(0, rawRequiredInputs.length - requiredInputs.length),
            canGeneratePendingSuggestions: preflight.can_generate_pending_suggestions === true,
            readOnly: preflight.read_only !== false,
            autoWriteOta: preflight.auto_write_ota === true,
            advisoryOnly: preflight.advisory_only !== false,
            target: {
                label: '调价建议生成预检',
                targetPage: String(preflight.target_page || ''),
                targetTab: String(preflight.target_tab || ''),
                targetAgentTab: String(preflight.target_agent_tab || ''),
                targetRevenueTab: String(preflight.target_revenue_tab || ''),
                targetFilter,
                nextAction: String(preflight.next_action || ''),
            },
            canOpenTarget: Boolean(preflight.target_page),
        };
    };

    const buildRevenueAiPriceSuggestionGenerateResult = ({ response = null, error = null } = {}) => {
        if (error) {
            const message = error && error.message ? String(error.message) : '定价建议生成请求失败';
            return {
                status: 'failed',
                statusLabel: revenueAiPricingGenerationStatusLabel('failed'),
                reason: 'request_failed',
                reasonText: message,
                message,
                level: 'error',
                className: revenueAiStatusClass('failed'),
                createdCount: 0,
                skippedCount: 0,
                canGeneratePendingSuggestions: false,
                requiredInputs: [],
                hiddenRequiredInputCount: 0,
                advisoryOnly: true,
                manualReviewRequired: true,
                autoWriteOta: false,
            };
        }

        const payload = response && typeof response === 'object' ? response : {};
        const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
        const createdCount = Number(data.created_count || 0);
        const skippedCount = Number(data.skipped_count || 0);
        const status = String(data.status || (createdCount > 0 ? 'created' : (payload.code === 200 ? 'blocked' : 'failed')));
        const reason = String(data.reason || (createdCount > 0 ? 'price_suggestions_pending_review' : 'pricing_candidate_signals_missing'));
        const isCreated = createdCount > 0 && status !== 'blocked' && status !== 'failed';
        const rawRequiredInputs = Array.isArray(data.required_inputs) ? data.required_inputs : [];
        const requiredInputs = rawRequiredInputs
            .map((item) => ({
                code: String(item?.code || ''),
                status: String(item?.status || ''),
                source: String(item?.source || ''),
                nextAction: String(item?.next_action || ''),
            }))
            .filter(item => item.code)
            .slice(0, 4);
        const reasonText = String(data.detail || '') || revenueAiPricingGenerationReasonText(reason);
        const nextAction = String(data.next_action || '');
        const readbackVerifiedCount = Number(data.readback_verified_count || 0);
        const message = isCreated
            ? `已生成 ${createdCount} 条待审建议并回读 ${readbackVerifiedCount} 条；仍需人工审核，不写 OTA。`
            : (nextAction || reasonText || String(payload.message || '定价建议生成受阻'));
        const targetFilter = data.target_filter && typeof data.target_filter === 'object'
            ? data.target_filter
            : {};
        const rawSkippedItems = Array.isArray(data.skipped) ? data.skipped : [];
        const skippedItems = rawSkippedItems
            .map((item, index) => {
                const dataGaps = Array.isArray(item?.data_gaps) ? item.data_gaps.map(String).filter(Boolean) : [];
                const reviewChecklist = Array.isArray(item?.review_checklist) ? item.review_checklist.map(String).filter(Boolean) : [];
                const targetDate = String(item?.target_stay_date || item?.suggestion_date || '');
                const rawPriceChangeRate = item?.price_change_rate;
                const priceChangeRate = rawPriceChangeRate === null || rawPriceChangeRate === undefined || rawPriceChangeRate === ''
                    ? null
                    : Number(rawPriceChangeRate);
                const rawPrimarySignalCount = item?.primary_signal_count;
                const primarySignalCount = rawPrimarySignalCount === null || rawPrimarySignalCount === undefined || rawPrimarySignalCount === ''
                    ? null
                    : Number(rawPrimarySignalCount);
                return {
                    key: `${targetDate || 'date'}-${Number(item?.room_type_id || 0) || 'room'}-${String(item?.reason || 'skipped')}-${index}`,
                    targetDate,
                    roomTypeId: Number(item?.room_type_id || 0),
                    roomTypeName: String(item?.room_type_name || item?.room_type?.name || '未命名房型'),
                    reason: String(item?.reason || 'not_created'),
                    primarySignalCount: Number.isFinite(primarySignalCount) ? primarySignalCount : null,
                    priceChangeRate: Number.isFinite(priceChangeRate) ? priceChangeRate : null,
                    riskLevel: String(item?.risk_level || ''),
                    dataGaps: dataGaps.slice(0, 4),
                    hiddenDataGapCount: Math.max(0, dataGaps.length - 4),
                    reviewChecklist: reviewChecklist.slice(0, 3),
                    hiddenReviewChecklistCount: Math.max(0, reviewChecklist.length - 3),
                };
            })
            .slice(0, 5);

        return {
            status,
            statusLabel: isCreated ? '已生成待审' : revenueAiPricingGenerationStatusLabel(status),
            reason,
            reasonText,
            message,
            nextAction,
            level: isCreated ? 'success' : (status === 'failed' ? 'error' : 'warning'),
            className: isCreated ? revenueAiStatusClass('pending_review') : revenueAiStatusClass(status),
            sourceScope: String(data.source_scope || ''),
            sourceChannels: Array.isArray(data.source_channels) ? data.source_channels.map(String) : [],
            targetHotelIds: Array.isArray(data.target_hotel_ids)
                ? data.target_hotel_ids.map(item => Number(item || 0)).filter(item => item > 0)
                : [],
            targetFilter,
            dateRange: data.date_range && typeof data.date_range === 'object' ? data.date_range : {},
            createdCount,
            skippedCount,
            createdRowIds: Array.isArray(data.created_row_ids)
                ? data.created_row_ids.map(item => Number(item || 0)).filter(item => item > 0)
                : [],
            readbackVerifiedCount,
            readbackVerified: data.readback_verified === true,
            reviewedCount: Number(data.reviewed_count || createdCount + skippedCount),
            skippedItems,
            hiddenSkippedItemCount: Math.max(0, rawSkippedItems.length - skippedItems.length),
            canGeneratePendingSuggestions: data.can_generate_pending_suggestions === true,
            requiredInputs,
            hiddenRequiredInputCount: Math.max(0, rawRequiredInputs.length - requiredInputs.length),
            advisoryOnly: data.advisory_only !== false,
            manualReviewRequired: data.manual_review_required !== false,
            autoWriteOta: data.auto_write_ota === true,
        };
    };

    const buildRevenueAiActionRows = ({ overview = null, overviewError = '' } = {}) => {
        const actions = Array.isArray(overview?.actions) ? overview.actions : [];
        const rows = actions.length ? actions : [{
            key: 'pricing_review',
            title: '暂无可审核调价建议',
            status: overviewError ? 'failed' : 'blocked',
            reason: overviewError ? 'overview_request_failed' : (overview ? 'phase1a_readonly_no_pricing_model' : 'overview_not_loaded'),
            review_queue: overview?.review_queue || {},
        }];
        return rows.map((action) => {
            const reviewQueue = action.review_queue && typeof action.review_queue === 'object'
                ? action.review_queue
                : (overview?.review_queue || {});
            const reviewQueueTargetFilter = reviewQueue.target_filter && typeof reviewQueue.target_filter === 'object'
                ? reviewQueue.target_filter
                : {};
            const reviewQueueTarget = {
                label: action.review_queue_summary || reviewQueue.display || action.title || '',
                targetPage: reviewQueue.target_page || '',
                targetTab: reviewQueue.target_tab || '',
                targetAgentTab: reviewQueue.target_agent_tab || '',
                targetRevenueTab: reviewQueue.target_revenue_tab || '',
                targetFilter: reviewQueueTargetFilter,
                nextAction: reviewQueue.next_action || '',
            };
            const reviewQueueStatus = reviewQueue.status || '';
            const decisionBasis = action.decision_basis_summary && typeof action.decision_basis_summary === 'object'
                ? action.decision_basis_summary
                : {};
            const decisionBasisStatus = decisionBasis.status || '';
            const decisionBasisEntries = Array.isArray(decisionBasis.items)
                ? decisionBasis.items
                    .map((item, index) => ({ item, index }))
                    .sort((left, right) => {
                        const priority = revenueAiDecisionBasisPriority(left.item) - revenueAiDecisionBasisPriority(right.item);
                        return priority !== 0 ? priority : left.index - right.index;
                    })
                : [];
            const visibleDecisionBasisEntries = decisionBasisEntries.slice(0, 4);
            const visibleBlockedDecisionBasisCount = visibleDecisionBasisEntries
                .filter(({ item }) => String(item.status || '').trim() !== 'ok')
                .length;
            const decisionBasisBlockedCount = Number(decisionBasis.blocked_count || 0);
            const decisionBasisHiddenBlockedCount = Math.max(0, decisionBasisBlockedCount - visibleBlockedDecisionBasisCount);
            const decisionBasisItems = visibleDecisionBasisEntries
                    .map(({ item }) => ({
                        key: item.key || item.label,
                        label: item.label || item.key || '未命名依据',
                        statusLabel: revenueAiStatusLabel(item.status || 'unknown'),
                        className: revenueAiStatusClass(item.status || 'unknown'),
                        reasonText: item.display_reason || revenueAiReasonText(item.reason || 'overview_not_loaded'),
                        nextAction: item.next_action || '',
                        targetPage: item.target_page || '',
                        targetTab: item.target_tab || '',
                        targetPlatform: item.target_platform || '',
                        targetAgentTab: item.target_agent_tab || '',
                        targetRevenueTab: item.target_revenue_tab || '',
                        canOpenTarget: Boolean(item.target_page),
                    }));
            const reviewQueueItems = buildRevenueAiReviewQueueItems(reviewQueue);
            const approvedExecutionPendingCount = reviewQueueItems.filter(item => item.canCreateExecutionIntent).length;
            const resolutionPlanSummary = buildRevenueAiResolutionPlanSummary({ overview, action });
            const pricingGenerationPreflightSummary = buildRevenueAiPricingGenerationPreflightSummary({ overview, action });
            return {
                key: action.key || action.title,
                title: action.title || '暂无可审核调价建议',
                statusLabel: revenueAiStatusLabel(action.status || 'blocked'),
                className: revenueAiStatusClass(action.status || 'blocked'),
                reasonText: action.detail || revenueAiReasonText(action.reason || 'phase1a_readonly_no_pricing_model'),
                nextActions: Array.isArray(action.next_actions)
                    ? action.next_actions.filter(item => String(item || '').trim()).slice(0, 4)
                    : [],
                autoWriteOta: action.auto_write_ota === true,
                manualReviewRequired: action.manual_review_required !== false,
                reviewQueueSummary: action.review_queue_summary || reviewQueue.display || '',
                reviewQueueTarget,
                reviewQueueCanOpenTarget: Boolean(reviewQueueTarget.targetPage),
                reviewQueueStatusLabel: reviewQueueStatus ? revenueAiStatusLabel(reviewQueueStatus) : '',
                reviewQueueClassName: reviewQueueStatus ? revenueAiStatusClass(reviewQueueStatus) : revenueAiStatusClass('unknown'),
                pendingReviewCount: Number(reviewQueue.pending_count || 0),
                approvedExecutionPendingCount,
                executionPendingDisplay: approvedExecutionPendingCount > 0 ? `已批准待转执行 ${approvedExecutionPendingCount}` : '',
                executionPendingReasonText: approvedExecutionPendingCount > 0 ? '已批准建议仍需转为运营执行意图，并记录人工执行和复盘证据。' : '',
                reviewQueueItems,
                decisionBasisDisplay: decisionBasis.display || '',
                decisionBasisStatusLabel: decisionBasisStatus ? revenueAiStatusLabel(decisionBasisStatus) : '',
                decisionBasisClassName: decisionBasisStatus ? revenueAiStatusClass(decisionBasisStatus) : revenueAiStatusClass('unknown'),
                decisionBasisReadyCount: Number(decisionBasis.ready_count || 0),
                decisionBasisBlockedCount,
                decisionBasisHiddenBlockedCount,
                decisionBasisHiddenDisplay: decisionBasisHiddenBlockedCount > 0 ? `另有 ${decisionBasisHiddenBlockedCount} 项待补未展示` : '',
                decisionBasisItems,
                resolutionPlanSummary,
                resolutionPlanVisible: resolutionPlanSummary.visible === true,
                resolutionPlanItems: resolutionPlanSummary.items,
                pricingGenerationPreflightSummary,
                pricingGenerationPreflightVisible: pricingGenerationPreflightSummary.visible === true,
            };
        });
    };

    const revenueAiEvidenceTarget = (payload = {}) => {
        const targetFilter = payload.target_filter && typeof payload.target_filter === 'object' ? payload.target_filter : {};
        return {
            targetPage: payload.target_page || '',
            targetTab: payload.target_tab || '',
            targetAgentTab: payload.target_agent_tab || '',
            targetRevenueTab: payload.target_revenue_tab || '',
            targetFilter,
            canOpenTarget: Boolean(payload.target_page),
        };
    };

    const buildRevenueAiEvidenceWorkbenchRows = ({ overview = null, overviewError = '' } = {}) => {
        if (overviewError) {
            return [{
                key: 'overview_request',
                label: 'Revenue AI 总览',
                status: 'failed',
                statusLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
                detailText: overviewError || revenueAiReasonText('overview_request_failed'),
                nextActionText: '检查 Revenue AI 总览接口和登录状态。',
                policyText: '接口失败时不生成 AI 结论。',
                metaText: '--',
                canOpenTarget: false,
            }];
        }

        const primaryAction = Array.isArray(overview?.actions) ? (overview.actions[0] || {}) : {};
        const p0Gate = overview?.p0_downstream_gate && typeof overview.p0_downstream_gate === 'object' ? overview.p0_downstream_gate : {};
        const reviewQueue = primaryAction.review_queue && typeof primaryAction.review_queue === 'object'
            ? primaryAction.review_queue
            : (overview?.review_queue || {});
        const aiToOperation = primaryAction.ai_to_operation_handoff && typeof primaryAction.ai_to_operation_handoff === 'object'
            ? primaryAction.ai_to_operation_handoff
            : (overview?.ai_to_operation_handoff || overview?.pricing_readiness?.ai_to_operation_handoff || {});
        const operationPacket = aiToOperation.operation_intake_packet && typeof aiToOperation.operation_intake_packet === 'object'
            ? aiToOperation.operation_intake_packet
            : {};
        const operationPreflight = operationPacket.operation_intake_preflight_contract && typeof operationPacket.operation_intake_preflight_contract === 'object'
            ? operationPacket.operation_intake_preflight_contract
            : (primaryAction.operation_intake_preflight_contract || {});
        const executionSummary = overview?.execution_summary && typeof overview.execution_summary === 'object' ? overview.execution_summary : {};
        const p0Status = p0Gate.status || (overview ? (overview.data_status || 'unknown') : 'not_loaded');
        const reviewStatus = reviewQueue.status || (overview ? (Number(reviewQueue.pending_count || 0) > 0 ? 'pending_review' : 'empty') : 'not_loaded');
        const operationStatus = aiToOperation.status || (overview ? 'operation_intake_blocked_by_manual_review' : 'not_loaded');
        const executionStatus = executionSummary.status || (overview ? 'empty' : 'not_loaded');

        return [
            {
                key: 'ota_evidence_gate',
                label: 'OTA 证据门禁',
                status: p0Status,
                statusLabel: revenueAiStatusLabel(p0Status),
                className: revenueAiStatusClass(p0Status),
                detailText: p0Gate.display || p0Gate.detail || revenueAiReasonText(p0Gate.reason || (overview ? 'blocked_by_data_credibility' : 'overview_not_loaded')),
                nextActionText: p0Gate.required_gate_command || p0Gate.next_action || '先补齐目标日 OTA 入库证据和 P0 门禁。',
                policyText: '只按目标日 OTA 渠道证据判断，不用 latest_available 或历史样本替代。',
                metaText: p0Gate.source_scope || overview?.source_scope || 'OTA渠道口径',
                ...revenueAiEvidenceTarget(p0Gate),
            },
            {
                key: 'manual_review',
                label: 'AI 建议人工审核',
                status: reviewStatus,
                statusLabel: revenueAiStatusLabel(reviewStatus),
                className: revenueAiStatusClass(reviewStatus),
                detailText: reviewQueue.display || `待审核 ${Number(reviewQueue.pending_count || 0)} / 已批准 ${Number(reviewQueue.approved_count || 0)}`,
                nextActionText: reviewQueue.next_action || '在收益管理 Agent 审核队列中人工批准、修改后批准或拒绝。',
                policyText: '人工审核必需；不自动写 OTA。',
                metaText: `pending=${Number(reviewQueue.pending_count || 0)} / approved=${Number(reviewQueue.approved_count || 0)} / auto_write_ota=false`,
                ...revenueAiEvidenceTarget(reviewQueue),
            },
            {
                key: 'operation_intake',
                label: 'AI 到运营交接',
                status: operationStatus,
                statusLabel: revenueAiStatusLabel(operationStatus),
                className: revenueAiStatusClass(operationStatus),
                detailText: [
                    operationPacket.status ? `packet=${operationPacket.status}` : '',
                    operationPreflight.status ? `preflight=${operationPreflight.status}` : '',
                    operationPacket.candidate_blocked_reason || '',
                ].filter(Boolean).join(' / ') || revenueAiReasonText('operation_intake_not_approved'),
                nextActionText: aiToOperation.target_entry || operationPacket.target_entry || '/api/operation/execution-intents',
                policyText: aiToOperation.protected_boundary || operationPreflight.protected_boundary || 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
                metaText: `can_create=${aiToOperation.can_create_operation_execution === true ? 'true' : 'false'} / auto_create=${aiToOperation.auto_create_operation_execution === true ? 'true' : 'false'}`,
                canOpenTarget: false,
            },
            {
                key: 'operation_execution',
                label: '运营执行与复盘',
                status: executionStatus,
                statusLabel: revenueAiStatusLabel(executionStatus),
                className: revenueAiStatusClass(executionStatus),
                detailText: executionSummary.display || revenueAiReasonText(executionSummary.reason || (overview ? 'operation_execution_empty' : 'overview_not_loaded')),
                nextActionText: executionSummary.next_action || '审批执行意图、记录执行证据，并完成效果复盘。',
                policyText: '没有执行证据和 ROI 复盘时，不进入投资判断。',
                metaText: `total=${Number(executionSummary.total_count || 0)} / evidence=${Number(executionSummary.evidence_ready_count || 0)} / roi=${Number(executionSummary.roi_ready_count || 0)}`,
                targetPage: 'ops-track',
                canOpenTarget: true,
            },
        ];
    };

    const buildRevenueAiEvidenceWorkbenchSummary = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        if (!safeRows.length) {
            return {
                status: 'not_loaded',
                statusLabel: revenueAiStatusLabel('not_loaded'),
                className: revenueAiStatusClass('not_loaded'),
                detailText: 'Revenue AI 证据链尚未加载。',
            };
        }
        const blockedRows = safeRows.filter(row => revenueAiStatusTone(row?.status) === 'blocked');
        const warningRows = safeRows.filter(row => revenueAiStatusTone(row?.status) === 'warning');
        const readyRows = safeRows.filter(row => revenueAiStatusTone(row?.status) === 'ok');
        const status = blockedRows.length ? 'blocked' : (warningRows.length ? 'warning' : 'ok');
        return {
            status,
            statusLabel: blockedRows.length ? `${blockedRows.length} 个门禁阻断` : (warningRows.length ? `${warningRows.length} 个环节待复核` : '证据链可继续推进'),
            className: revenueAiStatusClass(status),
            detailText: `已读 ${safeRows.length} 个环节：${readyRows.length} 个就绪，${warningRows.length} 个待复核，${blockedRows.length} 个阻断。`,
        };
    };

    const buildRevenueAiPricingGateRows = ({ overview = null, overviewError = '' } = {}) => {
        if (overviewError) {
            return [{
                key: 'overview_request',
                label: 'Revenue AI 总览接口',
                statusLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
                reasonText: overviewError || revenueAiReasonText('overview_request_failed'),
            }];
        }
        const gates = Array.isArray(overview?.pricing_readiness?.gates) ? overview.pricing_readiness.gates : [];
        if (!gates.length) {
            return [{
                key: 'overview_not_loaded',
                label: '调价前置条件',
                statusLabel: revenueAiStatusLabel(overview ? 'unknown' : 'not_loaded'),
                className: revenueAiStatusClass(overview ? 'unknown' : 'not_loaded'),
                reasonText: overview ? 'Revenue AI 总览未返回调价前置条件。' : revenueAiReasonText('overview_not_loaded'),
            }];
        }
        return gates.map((gate) => {
            const status = gate.status || 'unknown';
            const reason = gate.reason || '';
            return {
                key: gate.key || gate.label,
                label: gate.label || '调价前置条件',
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                reasonText: gate.display_reason || gate.detail || revenueAiReasonText(reason),
                nextAction: gate.next_action || '',
                severity: gate.severity || '',
                category: gate.category || '',
            };
        });
    };

    const buildRevenueAiAgentActivitySummary = ({ overview = null, overviewError = '' } = {}) => {
        const activity = overview?.agent_activity || {};
        const status = overviewError ? 'failed' : (activity.status || (overview ? 'unknown' : 'not_loaded'));
        const reason = overviewError ? 'overview_request_failed' : (activity.reason || (overview ? 'agent_logs_not_loaded' : 'overview_not_loaded'));
        return {
            label: activity.agent_type_label || '收益管理Agent',
            display: activity.display || '--',
            statusLabel: revenueAiStatusLabel(status),
            className: revenueAiStatusClass(status),
            reasonText: activity.detail || revenueAiReasonText(reason),
            nextAction: activity.next_action || '',
            totalCount: Number(activity.total_count || 0),
            errorCount: Number(activity.error_count || 0),
            warningCount: Number(activity.warning_count || 0),
            dateBasisLabel: revenueAiDateBasisLabel(activity.date_basis || 'create_time'),
            readOnly: activity.read_only !== false,
        };
    };

    const buildRevenueAiAgentActivityRows = ({ overview = null, overviewError = '' } = {}) => {
        if (overviewError) {
            return [{
                key: 'overview_request_failed',
                action: 'Revenue AI 总览接口',
                message: overviewError || revenueAiReasonText('overview_request_failed'),
                time: '--',
                statusLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
            }];
        }
        const activity = overview?.agent_activity || {};
        const logs = Array.isArray(activity.recent_logs) ? activity.recent_logs : [];
        if (!logs.length) {
            const status = activity.status || (overview ? 'empty' : 'not_loaded');
            const reason = activity.reason || (overview ? 'agent_logs_empty' : 'overview_not_loaded');
            return [{
                key: reason,
                action: activity.agent_type_label || '收益管理Agent',
                message: activity.detail || revenueAiReasonText(reason),
                time: activity.business_date || '--',
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
            }];
        }
        return logs.slice(0, 5).map((log, index) => {
            const status = log.status || 'unknown';
            return {
                key: log.id || `${log.action || 'agent_log'}_${index}`,
                action: log.action || 'Agent操作',
                message: log.message || '--',
                time: log.create_time || '--',
                statusLabel: log.level_label || revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
            };
        });
    };

    const revenueAiExecutionStageLabel = (stage) => ({
        recommendation: '建议动作',
        approval: '审批',
        execution: '执行',
        evidence: '执行证据',
        review: '效果复盘',
        reviewed: 'ROI确认',
        blocked: '阻塞',
        rejected: '已拒绝',
        failed: '失败',
    }[String(stage || '')] || '审批');

    const revenueAiExecutionActionLabel = (actionKey, fallback = '') => {
        const label = String(fallback || '').trim();
        if (label) return label;
        return ({
            approve_intent: '审批执行意图',
            record_execution: '记录执行结果',
            record_evidence: '补充执行证据',
            review_effect: '记录效果复盘',
            resolve_blocker: '处理阻塞原因',
            review_failure: '复核失败原因',
            wait_task_create: '查看执行进度',
        }[String(actionKey || '')] || '查看运营执行');
    };

    const revenueAiExecutionTargetKind = (actionKey, explicitKind = '') => {
        const kind = String(explicitKind || '').trim();
        if (kind) return kind;
        if (['approve_intent', 'resolve_blocker'].includes(String(actionKey || ''))) {
            return 'intent';
        }
        if (['record_execution', 'record_evidence', 'review_effect', 'review_failure'].includes(String(actionKey || ''))) {
            return 'task';
        }
        return '';
    };

    const buildRevenueAiExecutionSummary = ({ overview = null, overviewError = '' } = {}) => {
        const summary = overview?.execution_summary || {};
        const status = overviewError ? 'failed' : (summary.status || (overview ? 'unknown' : 'not_loaded'));
        const reason = overviewError ? 'overview_request_failed' : (summary.reason || (overview ? 'operation_execution_not_loaded' : 'overview_not_loaded'));
        const process = summary.process && typeof summary.process === 'object' ? summary.process : {};
        const effectReview = summary.effect_review && typeof summary.effect_review === 'object' ? summary.effect_review : {};
        const processStatus = overviewError ? 'failed' : (process.status || status);
        const effectStatus = overviewError ? 'failed' : (effectReview.status || status);
        const processReason = overviewError ? 'overview_request_failed' : (process.reason || reason);
        const effectReason = overviewError ? 'overview_request_failed' : (effectReview.reason || reason);
        return {
            label: '今日执行进度',
            display: summary.display || '--',
            statusLabel: revenueAiStatusLabel(status),
            className: revenueAiStatusClass(status),
            reasonText: overviewError || revenueAiReasonText(reason),
            nextAction: summary.next_action || '',
            totalCount: Number(summary.total_count || 0),
            approvedCount: Number(summary.approved_count || 0),
            executedCount: Number(summary.executed_count || 0),
            evidenceReadyCount: Number(summary.evidence_ready_count || 0),
            reviewNeededCount: Number(summary.review_needed_count || 0),
            reviewedCount: Number(summary.reviewed_count || 0),
            roiReadyCount: Number(summary.roi_ready_count || 0),
            blockedCount: Number(summary.blocked_count || 0),
            processDisplay: process.display || '--',
            processStatusLabel: revenueAiStatusLabel(processStatus),
            processClassName: revenueAiStatusClass(processStatus),
            processReasonText: revenueAiReasonText(processReason),
            effectReviewDisplay: effectReview.display || '--',
            effectReviewStatusLabel: revenueAiStatusLabel(effectStatus),
            effectReviewClassName: revenueAiStatusClass(effectStatus),
            effectReviewReasonText: revenueAiReasonText(effectReason),
            effectReviewInputDisplay: effectReview.input_display || '--',
            effectReviewInputReadyCount: Number(effectReview.input_ready_count || 0),
            effectReviewInputPartialCount: Number(effectReview.input_partial_count || 0),
            effectReviewInputMissingCount: Number(effectReview.input_missing_count || 0),
            nextDayInputReady: effectReview.next_day_input_ready === true,
            dateBasisLabel: revenueAiDateBasisLabel(summary.date_basis || 'operation_execution_intents.date_start/date_end'),
            readOnly: summary.read_only !== false,
            autoWriteOta: summary.auto_write_ota === true,
        };
    };

    const buildRevenueAiExecutionRows = ({ overview = null, overviewError = '' } = {}) => {
        if (overviewError) {
            return [{
                key: 'overview_request_failed',
                title: 'Revenue AI 总览接口',
                detail: overviewError || revenueAiReasonText('overview_request_failed'),
                stageLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
                meta: '--',
                nextAction: '检查接口返回和登录状态。',
                nextActionKey: '',
                targetPage: '',
                targetAction: '',
                targetId: 0,
                targetKind: '',
                intentId: 0,
                taskId: 0,
                hotelId: 0,
                actionLabel: '',
                canOpenExecution: false,
            }];
        }

        const summary = overview?.execution_summary || {};
        const items = Array.isArray(summary.recent_items) ? summary.recent_items : [];
        if (!items.length) {
            const status = summary.status || (overview ? 'empty' : 'not_loaded');
            const reason = summary.reason || (overview ? 'operation_execution_empty' : 'overview_not_loaded');
            return [{
                key: reason,
                title: '调价执行闭环',
                detail: revenueAiReasonText(reason),
                stageLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                meta: summary.business_date || '--',
                nextAction: summary.next_action || '',
                nextActionKey: '',
                targetPage: 'ops-track',
                targetAction: '',
                targetId: 0,
                targetKind: '',
                intentId: 0,
                taskId: 0,
                hotelId: Number(summary.hotel_id || overview?.hotel_id || 0),
                actionLabel: '查看运营执行',
                canOpenExecution: true,
            }];
        }

        return items.slice(0, 5).map((item, index) => {
            const stage = item.stage || 'approval';
            const nextAction = item.next_action && typeof item.next_action === 'object' ? item.next_action : {};
            const nextActionKey = String(item.target_action || nextAction.key || '');
            const targetPage = String(item.target_page || 'ops-track');
            const targetId = Number(item.target_id || nextAction.target_id || 0);
            const intentId = Number(item.intent_id || item.id || 0);
            const taskId = Number(item.task_id || 0);
            const hotelId = Number(item.hotel_id || overview?.hotel_id || 0);
            const targetKind = revenueAiExecutionTargetKind(nextActionKey, item.target_kind || '');
            const actionLabel = revenueAiExecutionActionLabel(nextActionKey, item.next_action_label || nextAction.label || '');
            const dateText = item.date_start && item.date_end && item.date_start !== item.date_end
                ? `${item.date_start}~${item.date_end}`
                : (item.date_start || item.date_end || '--');
            const evidenceCount = Number(item.evidence_count || 0);
            return {
                key: item.id || `${stage}_${index}`,
                title: `${item.platform_label || revenueAiChannelLabel(item.platform)} · ${item.action_type || 'price_adjust'}`,
                detail: `审批 ${item.approval_status || '--'} / 执行 ${item.execution_status || '--'} / 证据 ${evidenceCount}`,
                stageLabel: item.stage_label || revenueAiExecutionStageLabel(stage),
                className: revenueAiStatusClass(stage === 'reviewed' ? 'reviewed' : (['blocked', 'failed', 'rejected'].includes(stage) ? 'blocked' : 'warning')),
                meta: dateText,
                nextAction: actionLabel,
                nextActionKey,
                targetPage,
                targetAction: nextActionKey,
                targetId,
                targetKind,
                intentId,
                taskId,
                hotelId,
                actionLabel,
                canOpenExecution: targetPage === 'ops-track' && (intentId > 0 || taskId > 0 || targetId > 0),
                raw: item,
            };
        });
    };

    const revenueAiEffectInputDetail = (item = {}) => {
        const parts = [
            item.review_status ? `复盘 ${item.review_status}` : '',
            item.evidence_count !== undefined ? `证据 ${Number(item.evidence_count || 0)}` : '',
            item.evidence_summary ? String(item.evidence_summary) : '',
            item.roi_display && item.roi_display !== '--' ? `ROI ${item.roi_display}` : '',
        ].filter(Boolean);
        return parts.join(' / ') || revenueAiReasonText(item.input_reason || 'operation_effect_review_pending');
    };

    const buildRevenueAiEffectReviewRows = ({ overview = null, overviewError = '' } = {}) => {
        if (overviewError) {
            return [{
                key: 'effect_review_request_failed',
                title: '复盘输入',
                detail: overviewError || revenueAiReasonText('overview_request_failed'),
                statusLabel: revenueAiStatusLabel('failed'),
                className: revenueAiStatusClass('failed'),
                reasonText: overviewError || revenueAiReasonText('overview_request_failed'),
                meta: '--',
                roiDisplay: '--',
                reviewSummary: '',
                canOpenExecution: false,
            }];
        }

        const summary = overview?.execution_summary || {};
        const effectReview = summary.effect_review && typeof summary.effect_review === 'object' ? summary.effect_review : {};
        const inputs = Array.isArray(effectReview.inputs) ? effectReview.inputs : [];
        if (!inputs.length) {
            const status = effectReview.input_status || effectReview.status || (overview ? 'empty' : 'not_loaded');
            const reason = effectReview.input_reason || effectReview.reason || (overview ? 'operation_execution_empty' : 'overview_not_loaded');
            return [{
                key: reason,
                title: '明日调价判断输入',
                detail: revenueAiReasonText(reason),
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                reasonText: revenueAiReasonText(reason),
                meta: summary.business_date || '--',
                roiDisplay: '--',
                reviewSummary: '',
                canOpenExecution: String(status) !== 'failed',
                targetPage: 'ops-track',
                actionLabel: '查看运营执行',
            }];
        }

        return inputs.slice(0, 5).map((item, index) => {
            const status = item.input_status || item.roi_status || 'partial';
            const reason = item.input_reason || 'operation_effect_review_pending';
            const dateText = item.date_start && item.date_end && item.date_start !== item.date_end
                ? `${item.date_start}~${item.date_end}`
                : (item.date_start || item.date_end || '--');
            const intentId = Number(item.intent_id || item.id || 0);
            const taskId = Number(item.task_id || 0);
            return {
                key: item.id || `${status}_${index}`,
                title: `${item.platform_label || revenueAiChannelLabel(item.platform)} · ${item.action_type || 'price_adjust'}`,
                detail: revenueAiEffectInputDetail(item),
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                reasonText: revenueAiReasonText(reason),
                meta: dateText,
                roiDisplay: item.roi_display || '--',
                reviewSummary: item.review_summary || item.input_next_action || item.evidence_summary || '',
                evidenceSummary: item.evidence_summary || '',
                latestEvidenceType: item.latest_evidence_type || '',
                latestEvidenceAt: item.latest_evidence_at || '',
                hasRevenueEvidence: item.has_revenue_evidence === true,
                hasCostEvidence: item.has_cost_evidence === true,
                evidenceReadyForNextDay: item.evidence_ready_for_next_day === true,
                inputActionKey: item.input_action_key || '',
                inputActionLabel: item.input_action_label || '',
                inputNextAction: item.input_next_action || '',
                inputActionReason: item.input_action_reason || '',
                nextActionKey: item.input_action_key || item.target_action || '',
                targetPage: item.target_page || 'ops-track',
                targetAction: item.target_action || '',
                targetId: Number(item.target_id || 0),
                targetKind: item.target_kind || '',
                intentId,
                taskId,
                hotelId: Number(item.hotel_id || overview?.hotel_id || 0),
                actionLabel: item.input_action_label || (reason === 'operation_roi_missing' ? '补录ROI证据' : (status === 'ready' ? '查看复盘证据' : '补齐复盘证据')),
                canOpenExecution: (item.target_page || 'ops-track') === 'ops-track' && (intentId > 0 || taskId > 0 || Number(item.target_id || 0) > 0),
                raw: item,
            };
        });
    };

    const revenueAiExecutionNeedsRoiEvidence = (row = {}) => {
        const raw = row.raw && typeof row.raw === 'object' ? row.raw : {};
        const reason = String(row.inputReason || row.reason || raw.input_reason || '').trim();
        const roiStatus = String(row.roiStatus || raw.roi_status || '').trim();
        return reason === 'operation_roi_missing' || roiStatus === 'data_gap';
    };

    const revenueAiExecutionResolvedActionKey = (row = {}) => {
        const raw = row.raw && typeof row.raw === 'object' ? row.raw : {};
        const inputActionKey = String(row.inputActionKey || raw.input_action_key || '').trim();
        if (inputActionKey) {
            return inputActionKey;
        }
        return String(row.nextActionKey || row.targetAction || raw.target_action || '').trim();
    };

    const revenueAiExecutionTaskActionItem = (row = {}) => {
        const raw = row.raw && typeof row.raw === 'object' ? row.raw : {};
        const recommendation = raw.recommendation && typeof raw.recommendation === 'object' ? raw.recommendation : {};
        const targetKind = String(row.targetKind || raw.target_kind || '').trim();
        const taskId = Number(row.taskId || raw.task_id || (targetKind === 'task' ? (row.targetId || raw.target_id || 0) : 0) || 0);
        const objectType = String(raw.object_type || recommendation.object_type || 'price').trim() || 'price';
        const actionType = String(raw.action_type || recommendation.action_type || 'price_adjust').trim() || 'price_adjust';
        const platform = String(raw.platform || recommendation.platform || '').trim();
        const currentValue = raw.current_value && typeof raw.current_value === 'object'
            ? raw.current_value
            : (recommendation.current_value && typeof recommendation.current_value === 'object' ? recommendation.current_value : {});
        const targetValue = raw.target_value && typeof raw.target_value === 'object'
            ? raw.target_value
            : (recommendation.target_value && typeof recommendation.target_value === 'object' ? recommendation.target_value : {});
        return {
            execution: { task_id: taskId },
            recommendation: { object_type: objectType, action_type: actionType, platform, current_value: currentValue, target_value: targetValue },
        };
    };

    const resolveRevenueAiExecutionNavigation = ({ row = {}, fallbackHotelId = 0 } = {}) => {
        const raw = row.raw && typeof row.raw === 'object' ? row.raw : {};
        const explicitTargetPage = String(row.targetPage || raw.target_page || '').trim();
        const hotelId = Number(row.hotelId || raw.hotel_id || fallbackHotelId || 0);
        const intentId = Number(row.intentId || raw.intent_id || raw.id || 0);
        const taskItem = revenueAiExecutionTaskActionItem(row);
        const taskId = Number(taskItem.execution.task_id || 0);
        const targetPage = explicitTargetPage || (row.canOpenExecution || intentId > 0 || taskId > 0 ? 'ops-track' : '');
        const nextActionKey = revenueAiExecutionResolvedActionKey(row);
        const label = String(row.actionLabel || row.nextAction || '查看运营执行');
        return {
            targetPage,
            hotelId,
            intentId,
            taskItem,
            taskId,
            nextActionKey,
            focus: intentId > 0 || taskId > 0
                ? {
                    intentId,
                    taskId,
                    targetId: Number(row.targetId || raw.target_id || 0),
                    targetAction: nextActionKey,
                    label,
                }
                : null,
            actionLabel: row.actionLabel || '',
            label,
        };
    };

    const resolveRevenueAiExecutionAction = ({ row = {}, fallbackHotelId = 0 } = {}) => {
        const navigation = resolveRevenueAiExecutionNavigation({ row, fallbackHotelId });
        const taskId = Number(navigation.taskId || 0);
        const nextActionKey = String(navigation.nextActionKey || '').trim();
        const base = {
            ...navigation,
            action: '',
            message: '',
            level: '',
            confirmText: '',
            reloadOverview: false,
        };
        if (!navigation.targetPage) {
            return { ...base, action: 'missing_entry', message: '该执行记录暂未配置运营执行入口', level: 'warning' };
        }
        if (navigation.targetPage !== 'ops-track') {
            return { ...base, action: 'open_page' };
        }
        if (taskId > 0 && (nextActionKey === 'record_execution_evidence' || nextActionKey === 'record_evidence')) {
            return {
                ...base,
                action: 'record_execution_evidence',
                confirmText: '确认在 Revenue AI 首页补充执行证据？该动作只记录本地人工执行证据，不写入携程/美团价格。',
                reloadOverview: true,
            };
        }
        if (taskId > 0 && nextActionKey === 'record_roi_evidence') {
            return {
                ...base,
                action: 'record_roi_evidence',
                confirmText: '该记录缺少收入/ROI证据。确认先补录执行前后收入或成本证据？该动作只写入本地复盘证据，不写入携程/美团价格。',
                reloadOverview: true,
            };
        }
        if (taskId > 0 && nextActionKey === 'record_effect_review') {
            return { ...base, action: 'record_effect_review', reloadOverview: true };
        }
        if (taskId > 0 && nextActionKey === 'review_effect') {
            if (revenueAiExecutionNeedsRoiEvidence(row)) {
                return {
                    ...base,
                    action: 'record_roi_evidence',
                    confirmText: '该记录缺少收入/ROI证据。确认先补录执行前后收入或成本证据？该动作只写入本地复盘证据，不写入携程/美团价格。',
                    reloadOverview: true,
                };
            }
            return { ...base, action: 'record_effect_review', reloadOverview: true };
        }
        return { ...base, action: 'focus_ops' };
    };

    const revenueAiReviewActionKey = (item = {}, action = '') => `${Number(item.id || 0)}:${String(action || '')}`;

    const isRevenueAiReviewActionLoadingState = ({ state = {}, item = {}, action = '' } = {}) => {
        const source = state && typeof state === 'object' ? state : {};
        return source[revenueAiReviewActionKey(item, action)] === true;
    };

    const buildRevenueAiReviewActionLoadingState = ({ state = {}, item = {}, action = '', loading = false } = {}) => {
        const source = state && typeof state === 'object' ? state : {};
        return {
            ...source,
            [revenueAiReviewActionKey(item, action)]: loading === true,
        };
    };

    const normalizeRevenueAiApiPath = (endpoint = '') => {
        const value = String(endpoint || '').trim();
        if (!value) return '';
        return value.startsWith('/api/') ? value.slice(4) : value;
    };

    const revenueAiReviewActionText = (action = '') => ({
        approve: '批准该调价建议',
        approve_with_changes: '修改后批准该调价建议',
        reject: '拒绝该调价建议',
        execution_intent: '转为运营任务',
    }[String(action || '').trim()] || '');

    const revenueAiReviewEndpoint = (item = {}, action = '') => {
        const endpoints = item.allowedEndpoints || {};
        const normalizedAction = String(action || '').trim();
        return normalizedAction === 'execution_intent'
            ? normalizeRevenueAiApiPath(endpoints.execution_intent || item.allowedEndpoint)
            : normalizeRevenueAiApiPath(endpoints.review || item.allowedEndpoint);
    };

    const resolveRevenueAiReviewActionDraft = ({ item = {}, action = '' } = {}) => {
        const suggestionId = Number(item.id || 0);
        const normalizedAction = String(action || '').trim();
        if (!suggestionId) {
            return {
                ok: false,
                message: '定价建议ID缺失，无法审核',
                level: 'error',
                suggestionId,
                action: normalizedAction,
                endpoint: '',
                actionText: '',
            };
        }
        if (item.autoWriteOta === true) {
            return {
                ok: false,
                message: '异常：当前建议声明会写 OTA，已阻止首页操作',
                level: 'error',
                suggestionId,
                action: normalizedAction,
                endpoint: '',
                actionText: '',
            };
        }
        const endpoint = revenueAiReviewEndpoint(item, normalizedAction);
        if (!endpoint || !endpoint.startsWith('/revenue-ai/price-suggestions/')) {
            return {
                ok: false,
                message: '定价建议审核接口缺失，无法操作',
                level: 'error',
                suggestionId,
                action: normalizedAction,
                endpoint,
                actionText: '',
            };
        }
        const actionText = revenueAiReviewActionText(normalizedAction);
        if (!actionText) {
            return {
                ok: false,
                message: '不支持的审核动作',
                level: 'error',
                suggestionId,
                action: normalizedAction,
                endpoint,
                actionText,
            };
        }
        return {
            ok: true,
            message: '',
            level: '',
            suggestionId,
            action: normalizedAction,
            endpoint,
            actionText,
        };
    };

    const validateRevenueAiApprovedPrice = (inputValue = '', item = {}) => {
        const parsedPrice = Number(String(inputValue).replace(/[^\d.\-]/g, ''));
        if (!Number.isFinite(parsedPrice) || parsedPrice <= 0) {
            return { ok: false, approvedPrice: null, message: '修改后批准价必须是大于 0 的数字' };
        }
        const minPrice = Number(item.minPrice || item.min_price || 0);
        if (Number.isFinite(minPrice) && minPrice > 0 && parsedPrice < minPrice) {
            return { ok: false, approvedPrice: null, message: `修改后批准价低于最低保护价 ${minPrice}` };
        }
        const maxPrice = Number(item.maxPrice || item.max_price || 0);
        if (Number.isFinite(maxPrice) && maxPrice > 0 && parsedPrice > maxPrice) {
            return { ok: false, approvedPrice: null, message: `修改后批准价高于最高限制价 ${maxPrice}` };
        }
        return { ok: true, approvedPrice: Math.round(parsedPrice * 100) / 100, message: '' };
    };

    const buildRevenueAiReviewConfirmText = ({ action = '', actionText = '', approvedPrice = null } = {}) => {
        const normalizedAction = String(action || '').trim();
        if (normalizedAction === 'execution_intent') {
            return '确认转为本地待执行运营任务？该动作不会写入携程/美团价格，仍需人工执行、留证和复盘。';
        }
        if (normalizedAction === 'approve_with_changes') {
            return `确认以 ${approvedPrice} 元修改后批准？该动作只更新本地审核状态，不写入携程/美团价格。`;
        }
        return `确认${actionText}？该动作只更新本地审核状态，不写入携程/美团价格。`;
    };

    const buildRevenueAiReviewRequestBody = ({ action = '', item = {}, approvedPrice = null, reviewRemark = '' } = {}) => {
        const normalizedAction = String(action || '').trim();
        if (normalizedAction === 'execution_intent') {
            return { source: 'revenue_ai_homepage', expected_metric: 'orders', approve_to_task: true };
        }
        if (normalizedAction === 'approve_with_changes') {
            return {
                action: normalizedAction,
                approved_price: approvedPrice,
                remark: reviewRemark || `Revenue AI 首页人工修改后批准；未写 OTA。原建议价 ${item.suggestedPrice || item.suggested_price || '--'}，批准价 ${approvedPrice}。`,
            };
        }
        return {
            action: normalizedAction,
            remark: `Revenue AI 首页人工${normalizedAction === 'approve' ? '批准' : '拒绝'}；未写 OTA。`,
        };
    };

    const aiDailyReportActionSources = (action) => {
        const refs = action?.source_refs;
        if (Array.isArray(refs)) return refs.filter(Boolean).join(' / ');
        if (typeof refs === 'string') return refs;
        return '';
    };

    const aiDailyReportEvidenceTarget = (item = {}) => {
        const sourceRef = String(item.source_ref || item.sourceRef || item.ref || aiDailyReportActionSources(item) || '').trim();
        const code = String(item.code || item.key || item.stage || item.blocked_reason || item.next_action || item.nextAction || item.action_readiness?.next_action || '').trim();
        const text = `${sourceRef} ${code} ${item.label || ''} ${item.message || ''}`.toLowerCase();
        if (/execution|execute|action_item|operation|ops|执行|闭环/.test(text)) {
            return {
                page: 'ops-track',
                tab: '',
                label: '查看执行闭环',
                sourceRef: sourceRef || code || 'execution_flow',
            };
        }
        if (/platform|resource_catalog|collection_status|data_source|sync|profile|authorization|账号|授权|平台/.test(text)) {
            return {
                page: 'online-data',
                tab: 'platform-sources',
                label: '查看平台数据源',
                sourceRef: sourceRef || code || 'platform_sources',
            };
        }
        if (/table_missing|missing_table|init|schema|初始化/.test(text)) {
            return {
                page: 'online-data',
                tab: 'data-health',
                label: '查看初始化状态',
                sourceRef: sourceRef || code || 'data_health',
            };
        }
        return {
            page: 'online-data',
            tab: 'data-health',
            label: '查看数据健康',
            sourceRef: sourceRef || code || 'data_health',
        };
    };

    const aiDailyReportActionIsInvestigationOnly = (action) => {
        if (!action || typeof action !== 'object') return false;
        if (action.is_investigation_only === true) return true;
        if (String(action.recommendation_type || '') === 'investigation') return true;
        const text = `${action.title || ''} ${action.blocked_reason || ''}`.toLowerCase();
        return action.can_create_execution_intent === false
            && String(action.action_type || '') === 'manual_review'
            && /fallback|investigation-only|investigation item|review daily operating signal|调查项/i.test(text);
    };

    const aiDailyReportActionExecutionReady = (action) => action?.can_create_execution_intent === true
        && action?.decision_quality?.contract_version === 'ai_recommendation_quality.v2'
        && action?.decision_quality?.execution_ready === true;

    const aiDailyReportActionBlockedText = (action) => {
        if (!action) return '';
        if (aiDailyReportActionIsInvestigationOnly(action)) {
            return '调查项不可转执行单，仅用于查看证据和判断是否需要进一步分析。';
        }
        if (!aiDailyReportActionExecutionReady(action) && !action.execution_intent_id) {
            return action.blocked_reason || action.action_readiness?.notice || action.action_readiness?.next_action || '该建议受数据缺口阻断，不能直接转执行单。';
        }
        if (action.execution_blocked_reason) return action.execution_blocked_reason;
        const readiness = action.action_readiness || {};
        const stage = String(readiness.stage || '');
        if (['blocked_by_data_gap', 'blocked', 'rejected', 'failed'].includes(stage)) {
            return readiness.notice || readiness.next_action || '当前阶段不可转执行，需先处理阻断原因。';
        }
        return '';
    };

    const aiDailyReportActionStatusText = (action) => {
        if (aiDailyReportActionIsInvestigationOnly(action)) return '调查项 / 不可执行';
        if (action?.action_readiness?.status_label) return action.action_readiness.status_label;
        if (action?.execution_intent_id) {
            return action.execution_blocked_reason ? '已生成，待补齐' : '已生成执行单';
        }
        if (!aiDailyReportActionExecutionReady(action)) return action.blocked_reason || '不可转执行单';
        return '可转执行单';
    };

    const aiDailyReportActionButtonText = (action) => {
        if (aiDailyReportActionIsInvestigationOnly(action)) return '查看证据';
        if (action?.execution_intent_id) return '已转单';
        if (!aiDailyReportActionExecutionReady(action)) return '处理缺口';
        if (aiDailyReportActionBlockedText(action)) return '待处理';
        return '转单';
    };

    const buildAiDailyReportBlockingRows = ({ readinessMissing = [], actions = [] } = {}) => {
        const rows = [];
        (Array.isArray(readinessMissing) ? readinessMissing : []).forEach((item, index) => {
            if (!item || typeof item !== 'object') return;
            const target = aiDailyReportEvidenceTarget(item);
            rows.push({
                key: `readiness:${item.code || index}:${index}`,
                label: item.label || item.code || '证据缺口',
                nextAction: item.next_action || '先补齐证据再转执行',
                target,
                actionText: target.label,
                sourceRef: target.sourceRef,
                type: 'readiness',
            });
        });
        (Array.isArray(actions) ? actions : []).forEach((action, index) => {
            const blockedText = aiDailyReportActionBlockedText(action);
            if (!blockedText) return;
            const isInvestigation = aiDailyReportActionIsInvestigationOnly(action);
            const sourceRef = aiDailyReportActionSources(action);
            const target = isInvestigation
                ? {
                    page: 'online-data',
                    tab: 'data-health',
                    label: '查看事实证据',
                    sourceRef: sourceRef || 'operation.full_data',
                }
                : aiDailyReportEvidenceTarget({
                    ...action,
                    source_ref: sourceRef,
                    next_action: action?.action_readiness?.next_action || blockedText,
                });
            rows.push({
                key: `action:${action?.title || index}:${index}`,
                label: isInvestigation ? `调查项：${action?.title || index + 1}` : (action?.title || `建议${index + 1}`),
                nextAction: blockedText,
                target,
                actionText: target.label,
                sourceRef: target.sourceRef,
                type: isInvestigation ? 'investigation' : 'action',
            });
        });
        return rows;
    };

    const summarizeAiDailyReportBlockingRows = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const readinessCount = safeRows.filter(row => row.type === 'readiness').length;
        const actionCount = safeRows.filter(row => row.type === 'action').length;
        const investigationCount = safeRows.filter(row => row.type === 'investigation').length;
        const sourceCount = new Set(safeRows.map(row => row.sourceRef).filter(Boolean)).size;
        const opsCount = safeRows.filter(row => row.target?.page === 'ops-track').length;
        const dataHealthCount = safeRows.filter(row => (row.target?.tab || 'data-health') === 'data-health').length;
        const gateParts = [];
        if (opsCount > 0) gateParts.push(`运营执行门禁 ${opsCount}`);
        if (readinessCount + actionCount > 0 || dataHealthCount > investigationCount) {
            gateParts.push(`数据健康门禁 ${Math.max(0, dataHealthCount - investigationCount)}`);
        }
        if (investigationCount > 0) gateParts.push(`调查项不进入执行门禁 ${investigationCount}`);
        return {
            total: safeRows.length,
            detail: `证据缺口 ${readinessCount} / 动作阻断 ${actionCount} / 调查项 ${investigationCount} / 来源 ${sourceCount || 0}`,
            gateText: gateParts.join('；') || '当前没有执行门禁',
            sourceCount,
            readinessCount,
            actionCount,
            investigationCount,
            opsCount,
            dataHealthCount,
        };
    };

    const buildAiDailyReportEvidenceRows = ({ sourceRefs = [], dataGaps = [], actions = [] } = {}) => {
        const rows = [];
        (Array.isArray(sourceRefs) ? sourceRefs : []).forEach((item, index) => {
            const source = item && typeof item === 'object' ? item : { label: String(item || ''), source_ref: String(item || '') };
            const key = String(source.key || source.source_ref || `source_${index}`);
            if (!key) return;
            rows.push({
                key: `source:${key}:${index}`,
                type: '来源',
                title: source.label || key,
                detail: source.scope || source.message || '已纳入日报生成输入',
                ref: key,
                className: 'bg-blue-50 text-blue-700',
            });
        });
        (Array.isArray(dataGaps) ? dataGaps : []).forEach((gap, index) => {
            if (!gap || typeof gap !== 'object') return;
            rows.push({
                key: `gap:${gap.code || index}:${index}`,
                type: '缺口',
                title: gap.code || 'data_gap',
                detail: gap.message || '数据缺口待处理',
                ref: gap.source_ref || 'source pending',
                className: 'bg-amber-50 text-amber-700',
            });
        });
        (Array.isArray(actions) ? actions : []).forEach((action, index) => {
            const refs = aiDailyReportActionSources(action);
            if (!refs) return;
            const isInvestigation = aiDailyReportActionIsInvestigationOnly(action);
            rows.push({
                key: `action:${action?.title || index}:${index}`,
                type: isInvestigation ? '调查项' : '动作',
                title: action?.title || `建议${index + 1}`,
                detail: action?.reason || action?.action || '建议动作引用',
                ref: refs,
                className: isInvestigation
                    ? 'bg-slate-100 text-slate-700'
                    : (aiDailyReportActionBlockedText(action) ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700'),
            });
        });
        return rows.slice(0, 12);
    };

    const buildAiDailyFactGate = ({
        hotelId = '',
        targetDate = '',
        collectionStatus = null,
        profileStatus = null,
        errors = [],
    } = {}) => {
        const safeErrors = (Array.isArray(errors) ? errors : [errors]).map(item => String(item || '').trim()).filter(Boolean);
        const collection = collectionStatus && typeof collectionStatus === 'object' ? collectionStatus : null;
        const profiles = profileStatus && typeof profileStatus === 'object' ? profileStatus : null;
        const rawPlatforms = collection?.platforms && typeof collection.platforms === 'object'
            ? collection.platforms
            : {};
        const collectionRows = Array.isArray(rawPlatforms) ? rawPlatforms : Object.values(rawPlatforms);
        const profileRows = Array.isArray(profiles?.items) ? profiles.items : [];
        const platformKeys = Array.from(new Set([
            'ctrip',
            'meituan',
            ...collectionRows.map(row => String(row?.platform || '').toLowerCase()),
            ...profileRows.map(row => String(row?.platform || '').toLowerCase()),
        ].filter(Boolean)));
        const loginTextMap = {
            logged_in: '登录态已验证',
            waiting_login: '登录待验证',
            session_expired: '登录已过期',
            login_expired: '登录已过期',
            login_required: '需要登录',
            missing_profile: '缺少 Profile',
            needs_profile: '缺少 Profile',
            permission_denied: '无权限',
            no_permission: '无权限',
            unauthorized: '无权限',
            hotel_mismatch: '门店不匹配',
            unconfigured: '未配置',
            unverified: '未核验',
        };
        const collectionTextMap = {
            collected: '目标日已入库',
            partial: '目标日部分入库',
            collecting: '采集中',
            failed: '采集失败',
            stale: '数据已过期',
            stale_running: '任务运行超时',
            not_collected: '目标日未采集',
            not_loaded: '未加载',
        };
        const platformRows = platformKeys.map((platform) => {
            const row = collectionRows.find(item => String(item?.platform || '').toLowerCase() === platform) || {};
            const profile = profileRows.find(item => String(item?.platform || '').toLowerCase() === platform) || {};
            const profileDetail = row.profile && typeof row.profile === 'object' ? row.profile : {};
            const sourceSummary = row.sourceSummary && typeof row.sourceSummary === 'object' ? row.sourceSummary : {};
            const loginStatus = String(profile.status_code || row.platformLoginStatus || profileDetail.statusCode || 'unverified').toLowerCase();
            const collectionCode = String(row.collectionStatus || 'not_loaded').toLowerCase();
            const targetDateRows = Math.max(0, Number(row.targetDateRows || 0));
            const fieldFactsReady = Math.max(0, Number(row.fieldFactsReady || 0));
            const fieldFactsMissing = Math.max(0, Number(row.fieldFactsMissing || 0));
            const fieldFactStatus = String(row.fieldFactStatus || 'not_loaded').toLowerCase();
            const configuredCount = Math.max(0, Number(sourceSummary.configuredCount || 0));
            const applicable = configuredCount > 0
                || Number(profileDetail.dataSourceId || 0) > 0
                || profileDetail.profileExists === true
                || targetDateRows > 0
                || Number(row.storedRowCount || 0) > 0
                || !['', 'unconfigured', 'unverified'].includes(loginStatus)
                || !['', 'not_collected', 'not_loaded'].includes(collectionCode);
            const loginReady = loginStatus === 'logged_in';
            const targetDateReady = collectionCode === 'collected' && targetDateRows > 0;
            const fieldReady = fieldFactStatus === 'ready' && fieldFactsMissing === 0;
            const ready = applicable && loginReady && targetDateReady && fieldReady;
            const blockers = [];
            if (applicable && !loginReady) blockers.push(loginStatus || 'profile_login_unverified');
            if (applicable && !targetDateReady) blockers.push(targetDateRows > 0 ? collectionCode : 'target_date_no_data');
            if (applicable && !fieldReady) blockers.push(fieldFactsMissing > 0 ? 'field_missing' : `field_${fieldFactStatus || 'unverified'}`);
            let nextAction = '该渠道未配置，不计入当前就绪分母';
            if (applicable && !loginReady) nextAction = profile.next_action || profileDetail.nextAction || '先验证平台 Profile 登录状态';
            else if (applicable && !targetDateReady) nextAction = `补齐 ${targetDate || '目标日'} OTA 入库事实`;
            else if (applicable && !fieldReady) nextAction = '补齐字段事实、source path、metric key 与 verifier 证据';
            else if (ready) nextAction = 'OTA 事实已就绪，下游仍需独立验证';
            return {
                platform,
                label: row.platformName || (platform === 'meituan' ? '美团' : platform === 'ctrip' ? '携程' : platform),
                applicable,
                ready,
                loginStatus,
                loginText: loginTextMap[loginStatus] || profile.current_status || row.platformLoginText || loginStatus || '未核验',
                collectionStatus: collectionCode,
                collectionText: collectionTextMap[collectionCode] || collectionCode || '未核验',
                targetDateRows,
                fieldFactsReady,
                fieldFactsMissing,
                fieldFactStatus,
                fieldText: fieldReady
                    ? `字段事实已闭合 ${fieldFactsReady}`
                    : (fieldFactsMissing > 0 ? `字段缺口 ${fieldFactsMissing}` : `字段状态 ${fieldFactStatus || '未核验'}`),
                blockerCodes: blockers,
                nextAction,
                statusText: !applicable ? '未配置，不计入分母' : (ready ? '事实就绪' : '事实有缺口'),
                statusClass: !applicable
                    ? 'bg-slate-100 text-slate-600 border-slate-200'
                    : (ready ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200'),
            };
        });
        const applicableRows = platformRows.filter(row => row.applicable);
        const readyCount = applicableRows.filter(row => row.ready).length;
        const configuredCount = applicableRows.length;
        const fieldGapCount = applicableRows.reduce((sum, row) => sum + row.fieldFactsMissing, 0);
        let status = 'not_loaded';
        if (!String(hotelId || '').trim()) status = 'not_selected';
        else if (safeErrors.length > 0) status = 'unverified';
        else if (!collection) status = 'not_loaded';
        else if (configuredCount === 0) status = 'not_configured';
        else if (readyCount === configuredCount) status = 'ready';
        else if (readyCount > 0) status = 'partial';
        else status = 'blocked';
        const statusMeta = {
            ready: ['OTA事实门禁已通过', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
            partial: ['部分OTA渠道事实就绪', 'bg-amber-50 text-amber-700 border-amber-200'],
            blocked: ['OTA事实门禁有缺口', 'bg-rose-50 text-rose-700 border-rose-200'],
            unverified: ['OTA事实状态未核验', 'bg-red-50 text-red-700 border-red-200'],
            not_configured: ['未发现适用OTA渠道', 'bg-slate-100 text-slate-600 border-slate-200'],
            not_selected: ['请选择酒店', 'bg-slate-100 text-slate-600 border-slate-200'],
            not_loaded: ['尚未读取OTA事实', 'bg-slate-100 text-slate-600 border-slate-200'],
        }[status] || ['OTA事实状态未核验', 'bg-slate-100 text-slate-600 border-slate-200'];
        const otaChainStatus = status === 'ready' ? 'ready' : (status === 'unverified' ? 'unverified' : 'blocked');
        const downstreamStatus = status === 'ready' ? 'pending_validation' : 'blocked_upstream';
        const chainClass = (chainStatus) => ({
            ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            pending_validation: 'border-blue-200 bg-blue-50 text-blue-700',
            unverified: 'border-red-200 bg-red-50 text-red-700',
            blocked: 'border-rose-200 bg-rose-50 text-rose-700',
            blocked_upstream: 'border-slate-200 bg-slate-50 text-slate-500',
        }[chainStatus] || 'border-slate-200 bg-slate-50 text-slate-500');
        const chain = [
            { key: 'ota', label: 'OTA事实', status: otaChainStatus, text: statusMeta[0] },
            { key: 'revenue', label: '收益分析', status: downstreamStatus, text: status === 'ready' ? '待独立验证' : '等待OTA事实' },
            { key: 'ai', label: 'AI决策', status: downstreamStatus, text: status === 'ready' ? '待独立验证' : '等待OTA事实' },
            { key: 'operation', label: '运营管理', status: downstreamStatus, text: status === 'ready' ? '待独立验证' : '等待OTA事实' },
            { key: 'investment', label: '投资决策', status: downstreamStatus, text: status === 'ready' ? '待独立验证' : '等待OTA事实' },
        ].map(item => ({ ...item, className: chainClass(item.status) }));
        return {
            hotelId: String(hotelId || ''),
            targetDate: String(targetDate || collection?.targetDate || ''),
            generatedAt: String(collection?.generated_at || ''),
            status,
            statusText: statusMeta[0],
            statusClass: statusMeta[1],
            scopeText: 'OTA渠道事实门禁，不代表全酒店经营事实；Profile、目标日入库和字段闭环分层展示。',
            errorText: safeErrors.join('；'),
            configuredCount,
            readyCount,
            blockerCount: Math.max(0, configuredCount - readyCount),
            fieldGapCount,
            platformRows,
            chain,
            sourceRefs: ['online-data.collection-status', 'online-data.platform-profile-status'],
        };
    };

    const buildRevenueAiExecutionIntentOpenRow = ({ payload = {}, item = {} } = {}) => {
        const data = payload && typeof payload === 'object' ? payload : {};
        const intent = data.execution_intent && typeof data.execution_intent === 'object' ? data.execution_intent : {};
        const task = data.operation_task && typeof data.operation_task === 'object' ? data.operation_task : {};
        const intentId = Number(intent.id || 0);
        const taskId = Number(task.id || 0);
        const targetKind = data.target_kind || (taskId > 0 ? 'task' : 'intent');
        const targetId = Number(data.target_id || (targetKind === 'task' ? taskId : intentId) || 0);
        return {
            canOpenExecution: targetId > 0,
            targetPage: data.target_page || 'ops-track',
            targetAction: data.target_action || (taskId > 0 ? 'record_execution' : 'approve_intent'),
            targetId,
            targetKind,
            intentId,
            taskId,
            hotelId: Number(data.hotel_id || intent.hotel_id || item.hotelId || item.hotel_id || 0),
            actionLabel: taskId > 0
                ? '查看待执行运营任务'
                : (data.execution_intent_existing ? '查看执行意图' : '审批执行意图'),
            nextActionKey: data.target_action || (taskId > 0 ? 'record_execution' : 'approve_intent'),
        };
    };

    const resolveRevenueAiReviewNavigation = ({ item = {}, isSuperAdmin = false } = {}) => {
        const entry = item.actionEntry && typeof item.actionEntry === 'object' ? item.actionEntry : {};
        if (entry.requires_super_admin === true && isSuperAdmin !== true) {
            return {
                action: 'blocked',
                message: '当前账号无权进入超级管理员审核页；Revenue AI 首页不直接批准、拒绝或写 OTA。',
                level: 'warning',
            };
        }
        if (String(entry.target_page || '') !== 'agent-center') {
            return {
                action: 'gap',
                gapTarget: { target_tab: 'data-health' },
            };
        }
        const filter = entry.target_filter && typeof entry.target_filter === 'object' ? entry.target_filter : {};
        const dateText = filter.date
            ? String(filter.date)
            : (item.suggestionDate && item.suggestionDate !== '--' ? String(item.suggestionDate) : '');
        return {
            action: 'agent-center',
            hotelId: filter.hotel_id ? String(filter.hotel_id) : '',
            date: dateText,
            status: Number(filter.status || 0),
            agentTab: entry.target_agent_tab || 'revenue',
            revenueAgentTab: entry.target_revenue_tab || 'suggestions',
        };
    };

    const buildRevenueAiReviewNavigationState = (navigation = {}) => {
        if (!navigation || navigation.action !== 'agent-center') {
            return {
                shouldOpen: false,
                hotelId: '',
                date: '',
                status: 0,
                currentPage: '',
                agentTab: '',
                revenueAgentTab: '',
            };
        }
        return {
            shouldOpen: true,
            hotelId: navigation.hotelId ? String(navigation.hotelId) : '',
            date: navigation.date ? String(navigation.date) : '',
            status: Number(navigation.status || 0),
            currentPage: 'agent-center',
            agentTab: navigation.agentTab || 'revenue',
            revenueAgentTab: navigation.revenueAgentTab || 'suggestions',
        };
    };

    const competitorMicroscopeObject = (value) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        if (typeof value !== 'string' || !value.trim()) return {};
        try {
            const parsed = JSON.parse(value);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (_error) {
            return {};
        }
    };

    const competitorMicroscopeNumber = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };

    const competitorMicroscopePrice = (value) => {
        const number = competitorMicroscopeNumber(value);
        return number !== null && number > 0 ? number : null;
    };

    const competitorMicroscopeCurrencyText = (value) => {
        const price = competitorMicroscopePrice(value);
        return price === null ? '—' : `¥${price.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };

    const competitorMicroscopeAverage = (values) => {
        const valid = values.map(competitorMicroscopeNumber).filter(value => value !== null && value > 0);
        return valid.length ? Number((valid.reduce((sum, value) => sum + value, 0) / valid.length).toFixed(2)) : null;
    };

    const competitorMicroscopeName = (row = {}) => {
        const metadata = competitorMicroscopeObject(row.competitor_data);
        return String(
            row.competitor_name
            || row.competitor_hotel?.name
            || metadata.competitor_name
            || (Number(row.competitor_hotel_id || 0) > 0 ? `竞对 #${Number(row.competitor_hotel_id)}` : '未知竞对')
        ).trim();
    };

    const competitorMicroscopePlatformKey = (row = {}) => {
        const metadata = competitorMicroscopeObject(row.competitor_data);
        const raw = String(
            row.ota_platform
            ?? row.platform
            ?? metadata.ota_platform
            ?? metadata.platform_key
            ?? metadata.platform
            ?? ''
        ).trim().toLowerCase();
        const aliases = {
            '1': 'ctrip',
            ctrip: 'ctrip',
            trip: 'ctrip',
            'trip.com': 'ctrip',
            ebooking: 'ctrip',
            '携程': 'ctrip',
            '2': 'meituan',
            meituan: 'meituan',
            'meituan hotel': 'meituan',
            '美团': 'meituan',
            '3': 'fliggy',
            fliggy: 'fliggy',
            '飞猪': 'fliggy',
        };
        return aliases[raw] || (raw ? raw.replace(/[^a-z0-9_-]+/g, '_') : 'unknown');
    };

    const competitorMicroscopePlatformLabel = (platformKey) => ({
        ctrip: '携程',
        meituan: '美团',
        fliggy: '飞猪',
        unknown: '平台未返回',
    }[platformKey] || platformKey || '平台未返回');

    const competitorMicroscopeMetadataText = (metadata, keys) => {
        for (const key of keys) {
            const value = metadata?.[key];
            if (value !== null && value !== undefined && String(value).trim() !== '') return String(value).trim();
        }
        return '';
    };

    const competitorMicroscopeKey = (row = {}) => {
        const id = Number(row.competitor_hotel_id || 0);
        const competitorKey = id > 0 ? `id:${id}` : `name:${competitorMicroscopeName(row).toLowerCase()}`;
        return `${competitorKey}|platform:${competitorMicroscopePlatformKey(row)}`;
    };

    const competitorMicroscopeSourceStatus = (row = {}) => {
        const metadata = competitorMicroscopeObject(row.competitor_data);
        const evidenceStatus = String(metadata.evidence_status || row.evidence_status || '').toLowerCase();
        const sourceMethod = String(metadata.source_method || row.source_method || '').toLowerCase();
        const validationStatus = String(metadata.validation_status || row.validation_status || '').toLowerCase();
        const sourceRef = String(metadata.source_ref || row.source_ref || '').trim();
        const capturedAt = String(metadata.captured_at || metadata.collected_at || row.captured_at || row.update_time || '').trim();
        const readback = metadata.readback_verified ?? row.readback_verified;
        const readbackVerified = readback === true || readback === 1 || ['1', 'true', 'verified'].includes(String(readback).toLowerCase());
        const operatorProvided = evidenceStatus === 'operator_provided' || /(?:^|[_\-\s])manual(?:$|[_\-\s])/.test(sourceMethod);
        const verified = !operatorProvided
            && sourceMethod !== ''
            && sourceRef !== ''
            && capturedAt !== ''
            && readbackVerified
            && ['verified', 'available', 'normal', 'ok', 'valid'].includes(validationStatus);
        if (verified) return 'verified';
        if (operatorProvided) return 'operator_provided';
        return 'unverified';
    };

    const competitorMicroscopeRow = (row = {}, roomTypeName = '') => {
        const metadata = competitorMicroscopeObject(row.competitor_data);
        const ourPrice = competitorMicroscopePrice(row.our_price);
        const competitorPrice = competitorMicroscopePrice(row.competitor_price);
        const gap = ourPrice !== null && competitorPrice !== null ? Number((ourPrice - competitorPrice).toFixed(2)) : null;
        const explicitGapPercent = competitorMicroscopeNumber(row.diff_percent ?? row.price_gap_percent);
        const gapPercent = gap === null
            ? null
            : (explicitGapPercent !== null
            ? explicitGapPercent
            : (competitorPrice > 0 ? Number((gap / competitorPrice * 100).toFixed(2)) : null));
        const platformKey = competitorMicroscopePlatformKey(row);
        const resolvedRoomTypeName = String(row.room_type_name || row.room_type?.name || roomTypeName || '未知房型');
        const roomTypeId = Number(row.room_type_id || 0);
        const roomKey = roomTypeId > 0 ? `room:${roomTypeId}` : `room-name:${resolvedRoomTypeName.toLowerCase()}`;
        const breakfast = competitorMicroscopeMetadataText(metadata, ['breakfast', 'breakfast_policy', 'meal_plan']);
        const cancellationPolicy = competitorMicroscopeMetadataText(metadata, ['cancellation_policy', 'cancel_policy']);
        const ratePlan = competitorMicroscopeMetadataText(metadata, ['rate_plan_key', 'rate_plan', 'package_name']);
        const comparisonBasisComplete = breakfast !== '' && cancellationPolicy !== '';
        const comparisonKey = [platformKey, roomKey, breakfast || 'unknown-breakfast', cancellationPolicy || 'unknown-cancel', ratePlan || 'unknown-rate-plan'].join('|');
        return {
            ...row,
            competitor_key: competitorMicroscopeKey(row),
            competitor_name: competitorMicroscopeName(row),
            platform_key: platformKey,
            platform_label: competitorMicroscopePlatformLabel(platformKey),
            room_type_name: resolvedRoomTypeName,
            room_key: roomKey,
            comparison_key: comparisonKey,
            comparison_basis_complete: comparisonBasisComplete,
            comparison_basis_text: comparisonBasisComplete ? '房型、早餐与取消口径已记录' : '仅房型口径，早餐或取消政策待核',
            our_price: ourPrice,
            competitor_price: competitorPrice,
            price_gap: gap,
            price_gap_percent: gapPercent,
            source_status: competitorMicroscopeSourceStatus(row),
        };
    };

    const competitorMicroscopeComparableAverage = (rows, field) => {
        const groups = new Map();
        rows.forEach(row => {
            const value = competitorMicroscopePrice(row?.[field]);
            if (value === null) return;
            const key = String(row?.comparison_key || row?.room_key || 'unknown');
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(value);
        });
        return competitorMicroscopeAverage(Array.from(groups.values()).map(values => competitorMicroscopeAverage(values)));
    };

    const competitorMicroscopeMeituanRankDefinitions = [
        { key: 'P_RZ', label: '入住榜', metrics: ['roomNights', 'roomRevenue'] },
        { key: 'P_XS', label: '销售榜', metrics: ['salesRoomNights', 'sales'] },
        { key: 'P_LL', label: '流量榜', metrics: ['exposure', 'views'] },
        { key: 'P_ZH', label: '转化榜', metrics: ['viewConversion', 'payConversion'] },
    ];

    const competitorMicroscopeMeituanRankRangeKey = (value) => {
        const range = String(value ?? '').trim().toLowerCase();
        return ({ yesterday: '1', realtime: '0' })[range] ?? range;
    };

    const competitorMicroscopeMeituanRankRangePriority = (value) => {
        const range = competitorMicroscopeMeituanRankRangeKey(value);
        return ({ '1': 0, '0': 1, '': 2, '7': 3, '30': 4 })[range] ?? 5;
    };

    const competitorMicroscopeMeituanRankEntry = (row, definition, preferredRange = null, preferredMetric = null) => {
        const entries = Array.isArray(row?.rankHistory) ? row.rankHistory : [];
        const preferred = preferredRange === null ? null : competitorMicroscopeMeituanRankRangeKey(preferredRange);
        const metric = preferredMetric === null ? null : String(preferredMetric).trim();
        const candidates = entries.filter(entry => (
            String(entry?.rankType || '').trim().toUpperCase() === definition.key
            && (metric === null || String(entry?.metric || '').trim() === metric)
        ));
        return (preferred === null
            ? candidates
            : candidates.filter(entry => competitorMicroscopeMeituanRankRangeKey(entry?.dateRange) === preferred))
            .slice()
            .sort((left, right) => {
                const leftRange = competitorMicroscopeMeituanRankRangeKey(left?.dateRange);
                const rightRange = competitorMicroscopeMeituanRankRangeKey(right?.dateRange);
                const rangeCompare = competitorMicroscopeMeituanRankRangePriority(leftRange)
                    - competitorMicroscopeMeituanRankRangePriority(rightRange);
                if (rangeCompare !== 0) return rangeCompare;
                const leftMetric = definition.metrics.indexOf(String(left?.metric || ''));
                const rightMetric = definition.metrics.indexOf(String(right?.metric || ''));
                return (leftMetric < 0 ? 99 : leftMetric) - (rightMetric < 0 ? 99 : rightMetric);
            })[0] || null;
    };

    const competitorMicroscopeMeituanRank = (entry) => {
        const rank = competitorMicroscopeNumber(entry?.rank);
        return rank !== null && rank > 0 ? Math.round(rank) : null;
    };

    const competitorMicroscopeMeituanMetricText = (entry) => {
        const value = competitorMicroscopeNumber(entry?.value);
        if (value === null || value < 0) return '数值未返回';
        const metric = String(entry?.metric || '');
        if (['viewConversion', 'payConversion'].includes(metric)) {
            const ratio = Math.abs(value) > 1 ? value / 100 : value;
            return `${Number((ratio * 100).toFixed(2))}%`;
        }
        if (['roomRevenue', 'sales'].includes(metric)) return `¥${value.toLocaleString('zh-CN', { maximumFractionDigits: 2 })}`;
        return value.toLocaleString('zh-CN', { maximumFractionDigits: 2 });
    };

    const competitorMicroscopeDateOffset = (date, offsetDays) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(date || ''))) return '';
        const [year, month, day] = String(date).split('-').map(Number);
        return new Date(Date.UTC(year, month - 1, day) + offsetDays * 86400000).toISOString().slice(0, 10);
    };

    const normalizeMeituanCompetitionCircle = (payload = {}, expectedHotelId = '', expectedDate = '', requestedRange = 'yesterday') => {
        let circle = {
            ...competitorMicroscopeObject(payload),
            requested_rank_range: competitorMicroscopeMeituanRankRangeKey(requestedRange),
        };
        const dataStatus = String(circle.data_status || circle.status || 'missing').trim().toLowerCase();
        const usableStatuses = ['success', 'ready', 'available', 'partial', 'unverified'];
        const missingStatuses = ['missing', 'empty', 'not_found'];
        if (missingStatuses.includes(dataStatus)) {
            const responseHotelId = String(circle.system_hotel_id ?? circle.hotel_id ?? '').trim();
            const responseDate = String(circle.target_date || circle.query_scope?.date || '').trim();
            if (responseHotelId !== String(expectedHotelId).trim() || responseDate !== String(expectedDate).trim()) {
                circle = { ...circle, data_status: 'scope_mismatch', message: '美团竞争圈空结果与当前酒店或日期不一致' };
                return { payload: circle, accepted: false, status: 'scope_mismatch', errorMessage: circle.message };
            }
            return { payload: circle, accepted: true, status: 'missing', errorMessage: '' };
        }
        if (usableStatuses.includes(dataStatus)) {
            const responseHotelId = String(circle.system_hotel_id ?? circle.hotel_id ?? '').trim();
            const responseDate = String(circle.latest_data_date || '').trim();
            if (responseHotelId !== String(expectedHotelId).trim() || responseDate !== String(expectedDate).trim()) {
                circle = {
                    ...circle,
                    data_status: 'scope_mismatch',
                    display_hotels: [],
                    comparison: null,
                    message: '美团竞争圈响应与当前酒店或日期不一致',
                };
                return { payload: circle, accepted: false, status: 'scope_mismatch', errorMessage: circle.message };
            }
            if (circle.comparison) {
                const previousDate = competitorMicroscopeDateOffset(expectedDate, -1);
                const comparisonHotelId = String(circle.comparison.system_hotel_id ?? '').trim();
                if (
                    comparisonHotelId !== String(expectedHotelId).trim()
                    || String(circle.comparison.data_date || '').trim() !== previousDate
                ) {
                    circle = { ...circle, comparison: null, comparison_scope_mismatch: true };
                }
            }
            return { payload: circle, accepted: true, status: dataStatus, errorMessage: '' };
        }
        const errorMessage = String(circle.message || ({
            stale: '美团竞争圈数据已过期',
            permission_denied: '无权读取美团竞争圈',
            collection_failed: '美团竞争圈采集失败',
            failed: '美团竞争圈读取失败',
            error: '美团竞争圈读取失败',
        }[dataStatus] || `美团竞争圈状态不可用：${dataStatus}`));
        return { payload: circle, accepted: false, status: dataStatus, errorMessage };
    };

    const competitorMicroscopeMeituanHotelKey = (row = {}) => {
        const poiId = String(row?.poiId ?? row?.poi_id ?? '').trim();
        if (poiId !== '') return `poi:${poiId.replace(/\|/g, '_')}|platform:meituan`;
        const name = String(row?.hotelName ?? row?.hotel_name ?? '未知竞对').trim().toLowerCase();
        return `name:${name}|platform:meituan`;
    };

    const competitorMicroscopeMeituanFindHotel = (rows, target = {}) => {
        const poiId = String(target?.poiId ?? target?.poi_id ?? '').trim();
        if (poiId !== '') {
            const byPoi = rows.find(row => String(row?.poiId ?? row?.poi_id ?? '').trim() === poiId);
            if (byPoi) return byPoi;
        }
        return null;
    };

    const competitorMicroscopeMeituanContext = (analysis = {}) => {
        const hasPayload = Object.prototype.hasOwnProperty.call(analysis || {}, 'meituan_competition_circle');
        const circle = competitorMicroscopeObject(analysis?.meituan_competition_circle);
        const dataStatus = String(circle.data_status || circle.status || (hasPayload ? 'missing' : 'not_loaded')).trim().toLowerCase();
        const expectedHotelId = String(analysis?.query_scope?.hotel_id || '').trim();
        const expectedDate = String(analysis?.query_scope?.date || analysis?.date || '').trim();
        const responseHotelId = String(circle.system_hotel_id ?? circle.hotel_id ?? '').trim();
        const responseDate = String(circle.latest_data_date || '').trim();
        const usable = ['success', 'ready', 'available', 'partial', 'unverified'].includes(dataStatus);
        const scopeMismatch = usable && (
            (expectedHotelId !== '' && responseHotelId !== expectedHotelId)
            || (expectedDate !== '' && responseDate !== expectedDate)
        );
        const rows = !scopeMismatch && usable && Array.isArray(circle.display_hotels) ? circle.display_hotels : [];
        const requestedRange = competitorMicroscopeMeituanRankRangeKey(circle.requested_rank_range ?? '1');
        const targetPoiId = String(circle.target_poi_id || '').trim();
        const selfRow = rows.find(row => row?.isSelf === true)
            || (targetPoiId !== '' ? rows.find(row => String(row?.poiId ?? row?.poi_id ?? '').trim() === targetPoiId) : null)
            || null;
        const competitors = rows.filter(row => row && row !== selfRow && row.isSelf !== true);
        let status = 'not_loaded';
        let label = '未查询';
        let message = '尚未读取目标日美团竞争圈';
        if (scopeMismatch || dataStatus === 'scope_mismatch') {
            status = 'scope_mismatch';
            label = '范围不匹配';
            message = '美团竞争圈响应与当前酒店或日期不一致，已拒绝使用';
        } else if (dataStatus === 'permission_denied') {
            status = 'permission_denied';
            label = '权限不足';
            message = String(circle.message || '无权读取美团竞争圈');
        } else if (dataStatus === 'collection_failed') {
            status = 'collection_failed';
            label = '采集失败';
            message = String(circle.message || '美团竞争圈采集失败');
        } else if (dataStatus === 'stale') {
            status = 'stale';
            label = '数据过期';
            message = String(circle.message || '美团竞争圈数据已过期，未用于当前判断');
        } else if (['error', 'failed'].includes(dataStatus)) {
            status = 'error';
            label = '读取失败';
            message = String(circle.message || '美团竞争圈读取失败');
        } else if (['missing', 'empty', 'not_found'].includes(dataStatus) || (usable && competitors.length === 0)) {
            status = 'missing';
            label = '目标日无数据';
            message = String(circle.message || '目标日未找到美团竞争圈竞对行');
        } else if (dataStatus === 'partial') {
            status = 'partial';
            label = '部分可用';
            message = String(circle.message || circle?.readiness?.detail || '美团竞争圈仅部分可用');
        } else if (dataStatus === 'unverified') {
            status = 'unverified';
            label = '来源未核验';
            message = String(circle.message || '美团竞争圈来源未核验');
        } else if (usable) {
            const readiness = String(circle?.readiness?.status || '').trim().toLowerCase();
            status = readiness === 'ok' ? 'ready' : 'partial';
            label = readiness === 'ok' ? '已入库' : '部分可用';
            message = String(circle?.readiness?.detail || circle.source_notice || '已读取目标日美团竞争圈入库记录');
        } else if (dataStatus !== 'not_loaded') {
            status = dataStatus;
            label = '状态不可用';
            message = String(circle.message || `美团竞争圈状态不可用：${dataStatus}`);
        }
        const options = competitors.map(row => {
            const rankEntries = competitorMicroscopeMeituanRankDefinitions
                .map(definition => competitorMicroscopeMeituanRankEntry(row, definition, requestedRange))
                .filter(Boolean);
            const ranks = rankEntries.map(competitorMicroscopeMeituanRank).filter(rank => rank !== null);
            return {
                key: competitorMicroscopeMeituanHotelKey(row),
                competitorId: 0,
                poiId: String(row?.poiId ?? row?.poi_id ?? '').trim(),
                name: String(row?.hotelName ?? row?.hotel_name ?? '未知竞对').trim(),
                platformKey: 'meituan',
                platformLabel: '美团',
                sampleCount: ranks.length,
                roomTypeCount: 0,
                maxAbsGapPercent: null,
                sourceStatus: 'stored_unverified',
                kind: 'meituan_competition_circle',
                optionSummary: `四榜 ${ranks.length}/4`,
                bestRank: ranks.length ? Math.min(...ranks) : null,
            };
        }).sort((left, right) => (
            Number(left.bestRank ?? 999) - Number(right.bestRank ?? 999)
            || right.sampleCount - left.sampleCount
            || left.name.localeCompare(right.name, 'zh-CN')
        ));
        return { circle, status, label, message, options, rows, selfRow, responseDate, requestedRange };
    };

    const competitorMicroscopeMeituanDetail = (analysis, option, context) => {
        const circle = context.circle;
        const competitorRow = context.rows.find(row => competitorMicroscopeMeituanHotelKey(row) === option.key) || null;
        const expectedDate = String(analysis?.query_scope?.date || analysis?.date || '').trim();
        const expectedHotelId = String(analysis?.query_scope?.hotel_id ?? '').trim();
        const comparisonDate = String(circle?.comparison?.data_date || '').trim();
        const comparisonHotelId = String(circle?.comparison?.system_hotel_id ?? '').trim();
        const comparisonScopeMismatch = circle.comparison_scope_mismatch === true
            || (circle.comparison && (
                comparisonHotelId !== expectedHotelId
                || comparisonDate !== competitorMicroscopeDateOffset(expectedDate, -1)
            ));
        const comparisonRows = !comparisonScopeMismatch && Array.isArray(circle?.comparison?.display_hotels)
            ? circle.comparison.display_hotels
            : [];
        const previousCompetitor = competitorMicroscopeMeituanFindHotel(comparisonRows, competitorRow || {});
        const previousSelf = context.selfRow ? competitorMicroscopeMeituanFindHotel(comparisonRows, context.selfRow) : null;
        const rows = competitorMicroscopeMeituanRankDefinitions.map(definition => {
            const competitorEntry = competitorMicroscopeMeituanRankEntry(competitorRow, definition, context.requestedRange);
            const range = context.requestedRange;
            const metric = competitorEntry === null ? null : String(competitorEntry.metric || '').trim();
            const selfEntry = competitorEntry === null ? null : competitorMicroscopeMeituanRankEntry(context.selfRow, definition, range, metric);
            const previousCompetitorEntry = competitorEntry === null ? null : competitorMicroscopeMeituanRankEntry(previousCompetitor, definition, range, metric);
            const previousSelfEntry = competitorEntry === null ? null : competitorMicroscopeMeituanRankEntry(previousSelf, definition, range, metric);
            const ourRank = competitorMicroscopeMeituanRank(selfEntry);
            const competitorRank = competitorMicroscopeMeituanRank(competitorEntry);
            const previousCompetitorRank = competitorMicroscopeMeituanRank(previousCompetitorEntry);
            const gap = ourRank !== null && competitorRank !== null ? ourRank - competitorRank : null;
            const rankChange = competitorRank !== null && previousCompetitorRank !== null
                ? previousCompetitorRank - competitorRank
                : null;
            const gapText = gap === null
                ? '差距未知'
                : (gap > 0 ? `竞对领先${gap}名` : (gap < 0 ? `本店领先${Math.abs(gap)}名` : '名次持平'));
            const trendText = rankChange === null
                ? '前日未返回'
                : (rankChange > 0 ? `较前日上升${rankChange}名` : (rankChange < 0 ? `较前日下降${Math.abs(rankChange)}名` : '较前日持平'));
            const metricText = competitorMicroscopeMeituanMetricText(competitorEntry);
            return {
                id: `${option.key}|${definition.key}`,
                room_type_name: definition.label,
                rank_type: definition.key,
                rank_range: range || '',
                our_price: null,
                competitor_price: null,
                price_gap: gap,
                price_gap_percent: null,
                our_rank: ourRank,
                competitor_rank: competitorRank,
                previous_our_rank: competitorMicroscopeMeituanRank(previousSelfEntry),
                previous_competitor_rank: previousCompetitorRank,
                our_value_text: ourRank === null ? '未返回' : `第${ourRank}名`,
                competitor_value_text: competitorRank === null ? '未返回' : `第${competitorRank}名`,
                gap_text: gapText,
                trend_text: trendText,
                price_signal_readiness: {
                    status_label: `${String(competitorEntry?.sourceLabel || '美团榜单入库')} · ${metricText}`,
                },
            };
        });
        const rankCoverage = rows.filter(row => row.competitor_rank !== null).length;
        const dataGaps = ['same_product_price_not_returned', 'source_validation_status_not_returned'];
        if (!context.selfRow) dataGaps.push('meituan_self_row_missing');
        if (rankCoverage < 4) dataGaps.push('meituan_rank_types_incomplete');
        if (competitorRow && Object.keys(competitorMicroscopeObject(competitorRow.metricDerived)).length > 0) {
            dataGaps.push('meituan_derived_metrics_present');
        }
        if (comparisonScopeMismatch) dataGaps.push('meituan_comparison_scope_mismatch');
        const dataGapLabelMap = {
            same_product_price_not_returned: '美团竞争圈未返回同房型价格证据',
            source_validation_status_not_returned: '入库记录未返回平台源核验状态',
            meituan_self_row_missing: '竞争圈未命中本店 POI，无法计算本店名次差',
            meituan_rank_types_incomplete: `目标日四榜仅覆盖 ${rankCoverage}/4`,
            meituan_derived_metrics_present: '部分榜单数值由平台百分比推导',
            meituan_comparison_scope_mismatch: '前日对比日期不匹配，已拒绝使用',
        };
        const trend = rows.map(row => ({
            date: row.rank_type,
            label: row.room_type_name,
            ourPrice: null,
            competitorPrice: null,
            gap: row.price_gap,
            gapPercent: null,
            missing: row.competitor_rank === null,
            comparisonText: `本店${row.our_value_text} / 竞对${row.competitor_value_text}`,
            gapText: row.gap_text,
        }));
        return {
            ...option,
            kind: 'meituan_competition_circle',
            avgOurPrice: null,
            avgCompetitorPrice: null,
            priceGap: null,
            priceGapPercent: null,
            trendChange: null,
            trendChangeText: comparisonRows.length ? '含前日升降' : '前日未返回',
            trend,
            trendCoverageDays: rankCoverage,
            trendTargetDays: 4,
            trendTitle: '四榜排名对比',
            trendEmptyText: '目标日四榜均未返回',
            tableTitle: '美团竞争圈榜单证据',
            ourColumnLabel: '本店排名',
            competitorColumnLabel: '竞对排名',
            gapColumnLabel: '名次差',
            rows,
            sourceStatus: 'stored_unverified',
            sourceStatusText: '已入库，平台源核验状态未返回',
            sourceStatusClass: 'border-amber-200 bg-amber-50 text-amber-700',
            latestDataDate: String(circle.latest_data_date || ''),
            hotelId: String(analysis?.query_scope?.hotel_id || ''),
            displayDate: String(circle.latest_data_date || analysis?.query_scope?.date || analysis?.date || ''),
            latestFetchedAt: String(circle.latest_fetched_at || ''),
            dataGaps,
            dataGapLabels: dataGaps.map(code => dataGapLabelMap[code] || code),
            sameProductPriceNotice: '美团竞争圈用于酒店级排名、销售、流量和转化观察；未返回同房型、早餐、取消政策与价型价格，不能据此自动调价。',
            metricScope: 'ota_channel',
        };
    };

    const buildCompetitorMicroscope = (analysis = {}, requestedKey = '') => {
        const matrix = competitorMicroscopeObject(analysis?.price_matrix);
        const expectedHotelId = String(analysis?.query_scope?.hotel_id || '').trim();
        const expectedScopeDate = String(analysis?.query_scope?.date || '').trim();
        const responseDate = String(analysis?.date || analysis?.query_scope?.date || '').trim();
        const rows = [];
        let priceScopeRejectedCount = 0;
        Object.entries(matrix).forEach(([roomTypeName, competitorRows]) => {
            const entries = competitorRows && typeof competitorRows === 'object' ? Object.values(competitorRows) : [];
            entries.forEach(row => {
                if (!row || typeof row !== 'object') return;
                const rowHotelId = String(row.hotel_id ?? row.system_hotel_id ?? '').trim();
                const rowDate = String(row.analysis_date ?? row.date ?? '').trim();
                if ((expectedHotelId && rowHotelId !== expectedHotelId)
                    || (expectedScopeDate && rowDate !== expectedScopeDate)) {
                    priceScopeRejectedCount += 1;
                    return;
                }
                rows.push(competitorMicroscopeRow(row, roomTypeName));
            });
        });

        const grouped = new Map();
        rows.forEach(row => {
            if (!grouped.has(row.competitor_key)) grouped.set(row.competitor_key, []);
            grouped.get(row.competitor_key).push(row);
        });
        const priceOptions = Array.from(grouped.entries()).map(([key, groupRows]) => {
            const gapValues = groupRows
                .map(row => competitorMicroscopeNumber(row.price_gap_percent))
                .filter(value => value !== null);
            const sourceStatuses = new Set(groupRows.map(row => row.source_status));
            const sourceStatus = sourceStatuses.size === 1
                ? Array.from(sourceStatuses)[0]
                : 'partial';
            return {
                key,
                competitorId: Number(groupRows[0]?.competitor_hotel_id || 0),
                name: groupRows[0]?.competitor_name || '未知竞对',
                platformKey: groupRows[0]?.platform_key || 'unknown',
                platformLabel: groupRows[0]?.platform_label || '平台未返回',
                sampleCount: groupRows.length,
                roomTypeCount: new Set(groupRows.map(row => row.room_type_name)).size,
                maxAbsGapPercent: gapValues.length ? Number(Math.max(...gapValues.map(Math.abs)).toFixed(2)) : null,
                sourceStatus,
                optionSummary: gapValues.length ? `|价差| ${Number(Math.max(...gapValues.map(Math.abs)).toFixed(2))}%` : '价差未知',
            };
        }).sort((left, right) => {
            const gapCompare = Number(right.maxAbsGapPercent ?? -1) - Number(left.maxAbsGapPercent ?? -1);
            if (gapCompare !== 0) return gapCompare;
            if (right.sampleCount !== left.sampleCount) return right.sampleCount - left.sampleCount;
            return left.name.localeCompare(right.name, 'zh-CN');
        });
        const meituanContext = competitorMicroscopeMeituanContext(analysis);
        const options = [...priceOptions, ...meituanContext.options];
        const sourceErrors = competitorMicroscopeObject(analysis?.source_errors);
        const ctripStatus = sourceErrors.ctrip
            ? 'error'
            : (priceScopeRejectedCount > 0 ? (priceOptions.length ? 'partial' : 'scope_mismatch') : (priceOptions.length ? 'ready' : 'missing'));
        const platformStatuses = [
            {
                platformKey: 'ctrip',
                platformLabel: '携程',
                status: ctripStatus,
                label: ({ error: '读取失败', partial: '部分行范围不匹配', scope_mismatch: '范围不匹配', ready: '价格样本已返回' })[ctripStatus] || '目标日无价格样本',
                message: String(sourceErrors.ctrip || (priceScopeRejectedCount ? `${priceScopeRejectedCount} 条价格行缺少或不匹配酒店/日期范围` : '')),
            },
            {
                platformKey: 'meituan',
                platformLabel: '美团',
                status: meituanContext.status,
                label: meituanContext.label,
                message: meituanContext.message,
            },
        ];

        const selectedKey = options.some(option => option.key === requestedKey)
            ? requestedKey
            : (options[0]?.key || '');
        if (!selectedKey) {
            return {
                status: 'empty',
                selectedKey: '',
                options: [],
                detail: null,
                platformStatuses,
                scopeNotice: '携程按同品价格样本展示；美团按目标日竞争圈榜单展示。任一来源缺失时保持缺失，不使用旧日或其他酒店数据代替。',
            };
        }

        const selectedOption = options.find(option => option.key === selectedKey);
        if (selectedOption?.kind === 'meituan_competition_circle') {
            const detail = competitorMicroscopeMeituanDetail(analysis, selectedOption, meituanContext);
            return {
                status: 'partial',
                selectedKey,
                options,
                detail,
                platformStatuses,
                scopeNotice: `${String(meituanContext.circle.source_notice || '美团竞争圈入库数据').trim()}；仅为美团 OTA 竞争圈口径，不代表全酒店经营事实，且不包含同品价格证据。`,
            };
        }
        const selectedRows = (grouped.get(selectedKey) || []).slice().sort((left, right) => (
            (right.price_gap_percent === null ? -1 : Math.abs(Number(right.price_gap_percent)))
            - (left.price_gap_percent === null ? -1 : Math.abs(Number(left.price_gap_percent)))
        ));
        const comparableSelectedRows = selectedRows.filter(row => (
            competitorMicroscopePrice(row?.our_price) !== null
            && competitorMicroscopePrice(row?.competitor_price) !== null
        ));
        const avgOurPrice = competitorMicroscopeComparableAverage(comparableSelectedRows, 'our_price');
        const avgCompetitorPrice = competitorMicroscopeComparableAverage(comparableSelectedRows, 'competitor_price');
        const priceGap = avgOurPrice !== null && avgCompetitorPrice !== null
            ? Number((avgOurPrice - avgCompetitorPrice).toFixed(2))
            : null;
        const priceGapPercent = priceGap !== null && avgCompetitorPrice > 0
            ? Number((priceGap / avgCompetitorPrice * 100).toFixed(2))
            : null;

        const trends = analysis?.trends && typeof analysis.trends === 'object' ? analysis.trends : {};
        const rawTrendRows = Array.isArray(trends[String(selectedOption.competitorId)])
            ? trends[String(selectedOption.competitorId)]
            : (Array.isArray(trends[selectedOption.competitorId]) ? trends[selectedOption.competitorId] : []);
        const selectedComparisonKeys = new Set(comparableSelectedRows.map(row => row.comparison_key));
        const trendGroups = new Map();
        let trendScopeRejectedCount = 0;
        rawTrendRows.forEach(rawRow => {
            if (!rawRow || typeof rawRow !== 'object' || competitorMicroscopeKey(rawRow) !== selectedKey) return;
            const rowHotelId = String(rawRow.hotel_id ?? rawRow.system_hotel_id ?? '').trim();
            if (expectedHotelId && rowHotelId !== expectedHotelId) {
                trendScopeRejectedCount += 1;
                return;
            }
            const row = competitorMicroscopeRow(rawRow);
            if (row.our_price === null || row.competitor_price === null) return;
            const date = String(row.analysis_date || '').trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                trendScopeRejectedCount += 1;
                return;
            }
            if (!selectedComparisonKeys.has(row.comparison_key)) return;
            if (!trendGroups.has(date)) trendGroups.set(date, []);
            trendGroups.get(date).push(row);
        });

        const expectedTrendDates = [];
        if (/^\d{4}-\d{2}-\d{2}$/.test(responseDate)) {
            const [year, month, day] = responseDate.split('-').map(Number);
            const endTimestamp = Date.UTC(year, month - 1, day);
            for (let offset = 6; offset >= 0; offset -= 1) {
                expectedTrendDates.push(new Date(endTimestamp - offset * 86400000).toISOString().slice(0, 10));
            }
        } else {
            expectedTrendDates.push(...Array.from(trendGroups.keys()).sort((left, right) => left.localeCompare(right)));
        }
        const populatedTrendKeySets = expectedTrendDates
            .map(date => trendGroups.get(date) || [])
            .filter(dayRows => dayRows.length > 0)
            .map(dayRows => new Set(dayRows.map(row => row.comparison_key)));
        const commonTrendKeys = populatedTrendKeySets.length
            ? new Set(Array.from(populatedTrendKeySets[0]).filter(key => populatedTrendKeySets.every(keys => keys.has(key))))
            : new Set();
        const trendEvidenceRows = expectedTrendDates.flatMap(date => (
            (trendGroups.get(date) || []).filter(row => commonTrendKeys.has(row.comparison_key))
        ));
        const trend = expectedTrendDates.map(date => {
            const dayRows = (trendGroups.get(date) || []).filter(row => commonTrendKeys.has(row.comparison_key));
            const ourPrice = competitorMicroscopeComparableAverage(dayRows, 'our_price');
            const competitorPrice = competitorMicroscopeComparableAverage(dayRows, 'competitor_price');
            const gap = ourPrice !== null && competitorPrice !== null ? Number((ourPrice - competitorPrice).toFixed(2)) : null;
            const missing = dayRows.length === 0 || ourPrice === null || competitorPrice === null;
            const gapPercent = gap !== null && competitorPrice > 0 ? Number((gap / competitorPrice * 100).toFixed(2)) : null;
            return {
                date,
                label: date.slice(5),
                ourPrice,
                competitorPrice,
                gap,
                gapPercent,
                sampleCount: dayRows.length,
                missing,
                comparisonText: missing ? '缺样本' : `本 ${competitorMicroscopeCurrencyText(ourPrice)} / 竞 ${competitorMicroscopeCurrencyText(competitorPrice)}`,
                gapText: gapPercent === null ? '价差未知' : `${gapPercent > 0 ? '+' : ''}${gapPercent}%`,
            };
        });
        const trendCoverageDays = trend.filter(point => !point.missing).length;
        const firstTrendGap = trend[0]?.gapPercent ?? null;
        const lastTrendGap = trend[trend.length - 1]?.gapPercent ?? null;
        const trendChange = trendCoverageDays === 7 && firstTrendGap !== null && lastTrendGap !== null
            ? Number((lastTrendGap - firstTrendGap).toFixed(2))
            : null;
        const sourceStatusText = {
            verified: '来源与回读已验证',
            operator_provided: '人工提供样本',
            partial: '部分来源已验证',
            unverified: '来源未验证',
        }[selectedOption.sourceStatus] || '来源未验证';
        const sourceStatusClass = {
            verified: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            operator_provided: 'border-blue-200 bg-blue-50 text-blue-700',
            partial: 'border-amber-200 bg-amber-50 text-amber-700',
            unverified: 'border-gray-200 bg-gray-50 text-gray-600',
        }[selectedOption.sourceStatus] || 'border-gray-200 bg-gray-50 text-gray-600';
        const dataGaps = [];
        if (avgOurPrice === null || avgCompetitorPrice === null) dataGaps.push('current_comparable_price_missing');
        if (comparableSelectedRows.length < selectedRows.length) dataGaps.push('current_comparable_rows_incomplete');
        if (selectedOption.platformKey === 'unknown') dataGaps.push('platform_missing');
        if (selectedRows.some(row => row.comparison_basis_complete !== true)) dataGaps.push('rate_policy_comparability_unverified');
        if (trendCoverageDays === 0) dataGaps.push('seven_day_trend_missing');
        else if (trendCoverageDays < 7) dataGaps.push('seven_day_trend_incomplete');
        if (commonTrendKeys.size > 0 && commonTrendKeys.size < selectedComparisonKeys.size) dataGaps.push('trend_room_mix_reduced');
        if (trendEvidenceRows.some(row => competitorMicroscopeSourceStatus(row) !== 'verified')) {
            dataGaps.push('trend_source_unverified');
        }
        if (priceScopeRejectedCount > 0 || trendScopeRejectedCount > 0) dataGaps.push('price_scope_rows_rejected');
        if (!['verified'].includes(selectedOption.sourceStatus)) dataGaps.push(`source_${selectedOption.sourceStatus}`);
        const dataGapLabelMap = {
            current_comparable_price_missing: '当前同平台有效价格缺失',
            current_comparable_rows_incomplete: '部分房型缺少同一行双边有效价格，未计入均价',
            platform_missing: 'OTA平台未返回',
            rate_policy_comparability_unverified: '早餐或取消政策口径未核验',
            seven_day_trend_missing: '近7日无同口径趋势样本',
            seven_day_trend_incomplete: `近7日仅覆盖 ${trendCoverageDays}/7 天`,
            trend_room_mix_reduced: '趋势已缩小到各日共同房型口径',
            trend_source_unverified: '趋势中存在未验证来源',
            price_scope_rows_rejected: `已拒绝 ${priceScopeRejectedCount + trendScopeRejectedCount} 条缺少或不匹配酒店/日期范围的价格行`,
            source_verified: '来源与回读已验证',
            source_operator_provided: '当前为人工提供样本',
            source_partial: '当前样本来源仅部分验证',
            source_unverified: '当前样本来源未验证',
        };
        const dataGapLabels = dataGaps.map(code => dataGapLabelMap[code] || code);
        const displayRows = selectedRows.map(row => ({
            ...row,
            our_value_text: competitorMicroscopeCurrencyText(row.our_price),
            competitor_value_text: competitorMicroscopeCurrencyText(row.competitor_price),
            gap_text: row.price_gap_percent === null ? '—' : `${row.price_gap_percent > 0 ? '+' : ''}${row.price_gap_percent}%`,
        }));

        return {
            status: dataGaps.length ? 'partial' : 'ready',
            selectedKey,
            options,
            detail: {
                ...selectedOption,
                kind: 'ota_price_comparison',
                avgOurPrice,
                avgCompetitorPrice,
                priceGap,
                priceGapPercent,
                trendChange,
                trend,
                trendCoverageDays,
                trendTargetDays: 7,
                trendTitle: '7 日价差轨迹',
                trendChangeText: trendChange === null ? '变化未知' : `较首日 ${trendChange > 0 ? '+' : ''}${trendChange}pct`,
                trendEmptyText: '近 7 日无同口径样本',
                tableTitle: '同日房型证据',
                ourColumnLabel: '本店',
                competitorColumnLabel: '竞对',
                gapColumnLabel: '价差',
                sameProductPriceNotice: '',
                trendComparableKeyCount: commonTrendKeys.size,
                rows: displayRows,
                hotelId: expectedHotelId,
                displayDate: responseDate,
                sourceStatusText,
                sourceStatusClass,
                dataGaps,
                dataGapLabels,
                metricScope: 'ota_channel',
            },
            platformStatuses,
            scopeNotice: '仅比较当前门店与所选竞对在同一 OTA 平台、同一日期及共同房型口径下的公开价格；早餐或取消政策未核验时只视为提示性样本，不形成自动调价或全酒店经营结论。',
        };
    };

    const revenueCockpitPlatformLabel = (platform) => ({
        ctrip: '携程',
        meituan: '美团',
        all_ota: '携程 + 美团',
    }[String(platform || '').toLowerCase()] || 'OTA');

    const revenueCockpitSourceMeta = (sourceKey) => ({
        dingdandao_pms: {
            label: 'PMS（订单来了）',
            scope: 'whole_hotel_accommodation',
            scopeLabel: 'PMS 全酒店住宿口径',
            expectedTable: 'dingdandao_operating_target_captures',
        },
        ctrip_ota: {
            label: '携程 OTA',
            scope: 'ota_channel',
            scopeLabel: '携程 OTA 渠道口径',
            expectedTable: 'online_daily_data',
        },
        meituan_ota: {
            label: '美团 OTA',
            scope: 'ota_channel',
            scopeLabel: '美团 OTA 渠道口径',
            expectedTable: 'online_daily_data',
        },
        cockpit_rule: {
            label: '经营驾驶舱规则',
            scope: 'advisory_only',
            scopeLabel: '只读建议口径',
            expectedTable: '',
        },
    }[String(sourceKey || '')] || {
        label: String(sourceKey || '来源待确认'),
        scope: 'unknown',
        scopeLabel: '口径待确认',
        expectedTable: '',
    });

    const revenueCockpitIsoDate = (value) => {
        const text = String(value || '').trim().slice(0, 10);
        return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : '';
    };

    const revenueCockpitDateDistance = (businessDate, today) => {
        const left = revenueCockpitIsoDate(businessDate);
        const right = revenueCockpitIsoDate(today);
        if (!left || !right) return null;
        const leftParts = left.split('-').map(Number);
        const rightParts = right.split('-').map(Number);
        const leftTime = Date.UTC(leftParts[0], leftParts[1] - 1, leftParts[2]);
        const rightTime = Date.UTC(rightParts[0], rightParts[1] - 1, rightParts[2]);
        return Math.round((rightTime - leftTime) / 86400000);
    };

    const resolveRevenueCockpitScope = ({
        scopePayload = null,
        requestedPlatform = '',
        requestedDate = '',
        resetDate = false,
        today = '',
    } = {}) => {
        const payload = scopePayload && typeof scopePayload === 'object' ? scopePayload : {};
        const allowedPlatforms = new Set(['all_ota', 'ctrip', 'meituan']);
        const platformRows = (Array.isArray(payload.platforms) ? payload.platforms : [])
            .filter((row) => row && allowedPlatforms.has(String(row.platform || '').toLowerCase()))
            .map((row) => {
                const platform = String(row.platform || '').toLowerCase();
                const availableDates = Array.from(new Set(
                    (Array.isArray(row.available_dates) ? row.available_dates : [])
                        .map(revenueCockpitIsoDate)
                        .filter(Boolean),
                )).sort((left, right) => right.localeCompare(left));
                const latest = revenueCockpitIsoDate(row.latest_verified_date) || availableDates[0] || '';
                if (latest && !availableDates.includes(latest)) availableDates.unshift(latest);
                return {
                    platform,
                    label: revenueCockpitPlatformLabel(platform),
                    latestVerifiedDate: latest,
                    availableDates,
                    availableDateCount: Number(row.available_date_count || availableDates.length || 0),
                    verifiedFactCount: Number(row.verified_fact_count || 0),
                };
            })
            .filter((row) => row.availableDates.length > 0);
        const recommended = payload.recommended && typeof payload.recommended === 'object'
            ? payload.recommended
            : {};
        const recommendedPlatform = String(recommended.platform || '').toLowerCase();
        const requestedPlatformKey = String(requestedPlatform || '').toLowerCase();
        const selectedRow = platformRows.find((row) => row.platform === requestedPlatformKey)
            || platformRows.find((row) => row.platform === recommendedPlatform)
            || platformRows[0]
            || null;
        if (!selectedRow) {
            return {
                status: String(payload.data_status || 'empty'),
                strictGate: String(payload?.boundary?.strict_gate || 'history_success+validation_verified+readback_verified'),
                selectedPlatform: '',
                selectedPlatformLabel: '无严格可用平台',
                selectedDate: '',
                previousDate: '',
                sameWeekdayDate: '',
                latestVerifiedDate: '',
                dateDistance: null,
                isToday: false,
                isLatest: false,
                platformOptions: [],
                dateOptions: [],
                notice: '该酒店没有找到已保存、验证通过且完成严格回读的 OTA 经营日期。',
                boundary: payload.boundary || {},
            };
        }
        const requestedDateKey = revenueCockpitIsoDate(requestedDate);
        const selectedDate = !resetDate && selectedRow.availableDates.includes(requestedDateKey)
            ? requestedDateKey
            : selectedRow.latestVerifiedDate;
        const selectedIndex = selectedRow.availableDates.indexOf(selectedDate);
        const previousDate = selectedIndex >= 0 ? (selectedRow.availableDates[selectedIndex + 1] || '') : '';
        const selectedWeekday = (() => {
            const parts = selectedDate.split('-').map(Number);
            return parts.length === 3 ? new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])).getUTCDay() : -1;
        })();
        const sameWeekdayDate = selectedIndex >= 0
            ? (selectedRow.availableDates.slice(selectedIndex + 1).find((candidate) => {
                const parts = candidate.split('-').map(Number);
                return parts.length === 3
                    && new Date(Date.UTC(parts[0], parts[1] - 1, parts[2])).getUTCDay() === selectedWeekday;
            }) || '')
            : '';
        const distance = revenueCockpitDateDistance(selectedDate, today);
        const isToday = distance === 0;
        const distanceText = distance === null
            ? '与今天的差异待确认'
            : (distance === 0
                ? '就是今天'
                : (distance > 0 ? `比今天早 ${distance} 天` : `比今天晚 ${Math.abs(distance)} 天`));
        return {
            status: 'ready',
            strictGate: String(payload?.boundary?.strict_gate || 'history_success+validation_verified+readback_verified'),
            selectedPlatform: selectedRow.platform,
            selectedPlatformLabel: selectedRow.label,
            selectedDate,
            previousDate,
            sameWeekdayDate,
            latestVerifiedDate: selectedRow.latestVerifiedDate,
            dateDistance: distance,
            isToday,
            isLatest: selectedDate === selectedRow.latestVerifiedDate,
            platformOptions: platformRows.map((row) => ({
                value: row.platform,
                label: row.label,
                latestVerifiedDate: row.latestVerifiedDate,
                availableDateCount: row.availableDates.length,
            })),
            dateOptions: selectedRow.availableDates.map((date, index) => ({
                value: date,
                label: `${date}${index === 0 ? ' · 最近严格回读' : ''}`,
                isLatest: index === 0,
            })),
            notice: `${selectedRow.label}当前业务日 ${selectedDate}，${distanceText}；${selectedDate === selectedRow.latestVerifiedDate ? '已默认到该平台最近严格可用日期' : '当前为人工选择的历史严格可用日期'}。`,
            boundary: payload.boundary || {},
        };
    };

    const revenueCockpitNumber = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };

    const revenueCockpitMetricReady = (status) => ['readback_verified', 'derived_verified', 'verified'].includes(
        String(status || '').toLowerCase(),
    );

    const revenueCockpitStatusText = (status) => ({
        readback_verified: '已严格回读',
        derived_verified: '已验证派生',
        verified: '已验证',
        partial_readback_verified: '部分指标已回读',
        partial: '部分数据',
        missing: '缺失',
        not_verified: '未验证',
        not_calculable: '不可计算',
        evidence_investigation: '待补证核查',
        actionable: '发现可核查机会',
        no_signal: '未发现同口径信号',
        blocked: '证据阻断',
        unknown: '未知',
        analysis_blocked: '分析受阻',
        read_failed: '读取失败',
        failed: '加载失败',
        ready: '可用',
        ok: '可用',
    }[String(status || '').toLowerCase()] || '状态待确认');

    const revenueCockpitStatusClass = (status) => {
        const key = String(status || '').toLowerCase();
        if (['readback_verified', 'derived_verified', 'verified', 'ready', 'ok'].includes(key)) {
            return 'border-emerald-200 bg-emerald-50 text-emerald-700';
        }
        if (['read_failed', 'failed', 'error', 'blocked'].includes(key)) {
            return 'border-rose-200 bg-rose-50 text-rose-700';
        }
        if (key === 'actionable') return 'border-amber-300 bg-amber-50 text-amber-900';
        return 'border-amber-200 bg-amber-50 text-amber-800';
    };

    const revenueCockpitDisplayValue = (value, unit) => {
        const number = revenueCockpitNumber(value);
        if (number === null) return '—';
        const formatted = number.toLocaleString('zh-CN', {
            minimumFractionDigits: ['CNY', 'percent'].includes(unit) ? 2 : 0,
            maximumFractionDigits: ['CNY', 'percent'].includes(unit) ? 2 : 0,
        });
        if (unit === 'CNY') return `¥${formatted}`;
        if (unit === 'percent') return `${formatted}%`;
        if (unit === 'orders') return `${formatted} 单`;
        if (unit === 'room_nights') return `${formatted} 间夜`;
        if (unit === 'exposures') return `${formatted} 次`;
        return formatted;
    };

    const revenueCockpitUnitLabel = (unit) => ({
        CNY: '人民币',
        percent: '百分比',
        orders: '订单数',
        room_nights: '间夜数',
        exposures: '曝光次数',
        status: '状态',
        text: '说明',
    }[String(unit || '')] || String(unit || ''));

    const revenueCockpitSourceEvidence = (
        source = {},
        sourceKey,
        businessDate,
        strictEvidence = {},
        metricKey = '',
    ) => {
        const meta = revenueCockpitSourceMeta(sourceKey);
        const provenance = source?.source && typeof source.source === 'object' ? source.source : {};
        const rowIds = Array.isArray(provenance.row_ids)
            ? provenance.row_ids.filter((id) => Number(id) > 0)
            : (Number(provenance.record_id || 0) > 0 ? [Number(provenance.record_id)] : []);
        const traceIds = Array.isArray(provenance.source_trace_ids)
            ? provenance.source_trace_ids.filter(Boolean)
            : [];
        const strictMetric = metricKey
            && strictEvidence?.metrics?.[metricKey]
            && typeof strictEvidence.metrics[metricKey] === 'object'
            ? strictEvidence.metrics[metricKey]
            : null;
        const strictAcceptedIds = Array.isArray(strictMetric?.accepted_row_ids)
            ? strictMetric.accepted_row_ids.filter((id) => Number(id) > 0)
            : (Array.isArray(strictEvidence?.accepted_row_ids)
                ? strictEvidence.accepted_row_ids.filter((id) => Number(id) > 0)
                : []);
        const strictRejectedIds = Array.isArray(strictMetric?.rejected_row_ids)
            ? strictMetric.rejected_row_ids.filter((id) => Number(id) > 0)
            : (Array.isArray(strictEvidence?.rejected_row_ids)
                ? strictEvidence.rejected_row_ids.filter((id) => Number(id) > 0)
                : []);
        return [
            `来源：${meta.label} · ${String(provenance.table || meta.expectedTable || '来源表待确认')}`,
            `业务日期：${String(source.business_date || provenance.data_date || businessDate || '待确认')} · 实际日期：${String(source.actual_business_date || provenance.data_date || '待确认')}`,
            `保存记录：${rowIds.length ? rowIds.map((id) => `#${id}`).join('、') : '未返回可追溯记录ID'}`,
            `严格回读：${revenueCockpitStatusText(provenance.readback_status || source.data_status)}`,
            `驾驶舱严格事实闸门：${strictAcceptedIds.length ? strictAcceptedIds.map((id) => `#${id}`).join('、') : '未命中可用记录'}${strictRejectedIds.length ? `；拒绝 ${strictRejectedIds.map((id) => `#${id}`).join('、')}` : ''}`,
            `来源追踪：${traceIds.length ? `${traceIds.length} 条 trace` : '未返回 trace'}`,
            `口径：${meta.scopeLabel}`,
        ];
    };

    const revenueCockpitMetricCard = ({
        source = {},
        sourceKey,
        metricKey,
        label,
        unit,
        businessDate,
        strictEvidence = {},
    }) => {
        const meta = revenueCockpitSourceMeta(sourceKey);
        const facts = source?.facts && typeof source.facts === 'object' ? source.facts : {};
        const statuses = source?.fact_statuses && typeof source.fact_statuses === 'object' ? source.fact_statuses : {};
        const factStatus = statuses[metricKey] && typeof statuses[metricKey] === 'object'
            ? statuses[metricKey]
            : {};
        const hasFactStatus = Object.keys(factStatus).length > 0;
        const rawValue = revenueCockpitNumber(facts[metricKey]);
        const canonicalReady = revenueCockpitMetricReady(factStatus.status) && rawValue !== null;
        const otaSource = ['ctrip_ota', 'meituan_ota'].includes(sourceKey);
        const strictMetric = strictEvidence?.metrics?.[metricKey]
            && typeof strictEvidence.metrics[metricKey] === 'object'
            ? strictEvidence.metrics[metricKey]
            : {};
        const strictReady = !otaSource || strictMetric.strict_readback === true;
        const ready = canonicalReady && strictReady;
        const strictMismatch = canonicalReady && !strictReady;
        const displayStatus = ready
            ? String(factStatus.status || 'verified')
            : (strictMismatch ? 'not_verified' : String(hasFactStatus ? factStatus.status : 'missing'));
        const reasonCode = ready
            ? ''
            : (strictMismatch
                ? 'cockpit_strict_metric_readback_missing'
                : String(factStatus.reason || `${sourceKey}_${metricKey}_missing`));
        const evidenceLines = revenueCockpitSourceEvidence(
            source,
            sourceKey,
            businessDate,
            strictEvidence,
            metricKey,
        );
        evidenceLines.push(`指标状态：${revenueCockpitStatusText(displayStatus)}${reasonCode ? ` · ${reasonCode}` : ''}`);
        if (factStatus.formula) evidenceLines.push(`公式：${String(factStatus.formula)}`);
        if (factStatus.caliber) evidenceLines.push(`口径说明：${String(factStatus.caliber)}`);
        return {
            key: `${sourceKey}:${metricKey}`,
            kind: 'metric',
            label,
            display: ready ? revenueCockpitDisplayValue(rawValue, unit) : '—',
            value: ready ? rawValue : null,
            unit,
            unitLabel: revenueCockpitUnitLabel(unit),
            sourceKey,
            sourceLabel: meta.label,
            businessDate: String(source.business_date || businessDate || ''),
            status: displayStatus,
            statusLabel: revenueCockpitStatusText(displayStatus),
            statusClass: revenueCockpitStatusClass(displayStatus),
            scope: meta.scope,
            scopeLabel: meta.scopeLabel,
            missingState: ready ? '有值' : '缺失或未验证',
            reasonCode,
            reasonText: ready
                ? '该指标命中同酒店、同来源、同业务日的验证状态。'
                : (strictMismatch
                    ? '指标来源行未通过 history_status=success、validation_status=verified、readback_verified=1 的驾驶舱严格事实闸门，数值保持为空。'
                    : revenueAiReasonText(reasonCode)),
            evidenceLines,
        };
    };

    const revenueCockpitSourceCard = (source = {}, sourceKey, businessDate, strictEvidence = {}) => {
        const meta = revenueCockpitSourceMeta(sourceKey);
        const status = String(source.data_status || 'missing');
        const otaSource = ['ctrip_ota', 'meituan_ota'].includes(sourceKey);
        const strictReady = !otaSource || strictEvidence.source_strict_readback === true;
        const ready = status === 'readback_verified' && strictReady;
        const displayStatus = status === 'readback_verified' && !strictReady ? 'not_verified' : status;
        return {
            key: `source:${sourceKey}`,
            kind: 'source',
            label: meta.label,
            display: revenueCockpitStatusText(displayStatus),
            value: null,
            unit: 'status',
            unitLabel: '来源状态',
            sourceKey,
            sourceLabel: meta.label,
            businessDate: String(source.business_date || businessDate || ''),
            status: displayStatus,
            statusLabel: revenueCockpitStatusText(displayStatus),
            statusClass: revenueCockpitStatusClass(displayStatus),
            scope: meta.scope,
            scopeLabel: meta.scopeLabel,
            missingState: ready ? '完整' : '不完整',
            reasonCode: ready ? '' : (status === 'readback_verified'
                ? 'cockpit_strict_source_readback_missing'
                : `${sourceKey}_not_readback_verified`),
            reasonText: ready
                ? '来源身份、业务日期、保存记录和严格回读均已通过。'
                : (status === 'readback_verified'
                    ? '来源总览虽标记已回读，但承载当前指标的保存行未通过驾驶舱严格事实闸门。'
                    : revenueAiReasonText(`${sourceKey}_not_readback_verified`)),
            evidenceLines: revenueCockpitSourceEvidence(
                source,
                sourceKey,
                businessDate,
                strictEvidence,
            ),
        };
    };

    const revenueCockpitComparisonCard = (current, previous, previousDate, options = {}) => {
        const basisKey = String(options.basisKey || 'previous_comparable');
        const basisLabel = String(options.basisLabel || '前一可比营业日');
        const keyPrefix = basisKey === 'previous_comparable' ? 'compare' : `compare-${basisKey}`;
        const comparable = current?.value !== null
            && previous?.value !== null
            && current?.sourceKey === previous?.sourceKey
            && current?.unit === previous?.unit;
        const delta = comparable ? Number((current.value - previous.value).toFixed(2)) : null;
        const ratio = comparable && previous.value !== 0
            ? Number((delta / Math.abs(previous.value) * 100).toFixed(2))
            : null;
        const display = !comparable
            ? '—'
            : `${delta > 0 ? '+' : ''}${revenueCockpitDisplayValue(delta, current.unit)}${ratio === null ? '（基期为 0）' : `（${ratio > 0 ? '+' : ''}${ratio.toFixed(2)}%）`}`;
        const status = comparable ? 'verified' : 'not_calculable';
        return {
            key: `${keyPrefix}:${current?.key || 'unknown'}`,
            kind: 'comparison',
            label: `${String(current?.label || '指标')} · ${basisLabel} ${previousDate || '缺失'}`,
            display,
            value: delta,
            unit: current?.unit || '',
            unitLabel: current?.unitLabel || '',
            sourceKey: current?.sourceKey || '',
            sourceLabel: current?.sourceLabel || '来源待确认',
            businessDate: current?.businessDate || '',
            status,
            statusLabel: comparable ? '同来源同单位可比' : '不可同口径比较',
            statusClass: revenueCockpitStatusClass(status),
            scope: current?.scope || 'unknown',
            scopeLabel: current?.scopeLabel || '口径待确认',
            missingState: comparable ? '有值' : '缺少同口径基期',
            reasonCode: comparable ? '' : 'same_source_comparison_missing',
            reasonText: comparable
                ? `只比较 ${current.sourceLabel} 的同酒店、同平台、同一指标与同一单位，没有跨来源合并。`
                : `当前值或${basisLabel}的同来源、同单位指标缺失，变化保持为空。`,
            comparisonBasis: basisKey,
            baselineDate: previousDate || '',
            formula: comparable ? 'current_value - baseline_value' : '',
            causalityClaimed: false,
            evidenceLines: [
                `当前：${current?.businessDate || '日期待确认'} · ${current?.display || '—'} · ${current?.statusLabel || '状态待确认'}`,
                `基期：${previousDate || '无'} · ${previous?.display || '—'} · ${previous?.statusLabel || '状态待确认'}`,
                `比较基准：${basisLabel}`,
                `比较规则：同酒店、同平台、同来源、同指标、同单位；禁止跨来源或跨单位静默合并。`,
                '证据分类：当前值与基期值为平台事实；差值和百分比为公式计算；不形成因果结论。',
            ],
        };
    };

    const revenueCockpitTextCard = ({
        key,
        kind,
        label,
        display,
        sourceKey = 'cockpit_rule',
        businessDate = '',
        status = 'partial',
        reasonCode = '',
        reasonText = '',
        evidenceLines = [],
    }) => {
        const meta = revenueCockpitSourceMeta(sourceKey);
        return {
            key,
            kind,
            label,
            display: String(display || '—'),
            value: null,
            unit: 'text',
            unitLabel: '说明',
            sourceKey,
            sourceLabel: meta.label,
            businessDate,
            status,
            statusLabel: revenueCockpitStatusText(status),
            statusClass: revenueCockpitStatusClass(status),
            scope: meta.scope,
            scopeLabel: meta.scopeLabel,
            missingState: kind === 'gap' ? '数据缺口' : '说明',
            reasonCode,
            reasonText: String(reasonText || display || ''),
            evidenceLines: evidenceLines.length ? evidenceLines : [
                `来源：${meta.label}`,
                `业务日期：${businessDate || '待确认'}`,
                '边界：只生成解释或待审批入口，不自动执行。',
            ],
        };
    };

    const REVENUE_COCKPIT_OPPORTUNITY_DEFINITIONS = [
        {
            key: 'traffic_entry_shortage',
            title: '流量进入不足',
            businessOrder: 1,
            possibleCause: '可能与平台需求、搜索排名、投放、可售库存或活动曝光有关；当前只把它列为核查方向。',
            action: '核对同平台曝光来源、排名、投放、活动和可售库存，确认事实后再决定是否进入运营执行。',
        },
        {
            key: 'detail_conversion_shortage',
            title: '详情页转化不足',
            businessOrder: 2,
            possibleCause: '可能与首图、卖点、价格权益匹配或流量意图有关；当前变化不能证明任何单一原因。',
            action: '按同平台、同日期核对列表到详情路径、首图卖点和价格权益，先补齐可解释证据。',
        },
        {
            key: 'submit_payment_conversion_shortage',
            title: '提交 / 支付转化不足',
            businessOrder: 3,
            possibleCause: '可能与房态、价格权益、支付前阻断或用户意图有关；提交下降不能替代支付事实。',
            action: '分别核对详情到提交、提交到支付的分子分母及失败节点；缺少支付事实时不得把问题归到支付。',
        },
        {
            key: 'cancellation_anomaly',
            title: '取消异常',
            businessOrder: 4,
            possibleCause: '可能与价格变化、取消政策、客群结构或履约体验有关；当前仅为同口径变化信号。',
            action: '核对取消订单基数、取消原因、政策、客群与价格变化，确认是否需要人工干预。',
        },
        {
            key: 'price_competition_position',
            title: '价格竞争位置',
            businessOrder: 5,
            possibleCause: '没有同房型、同权益、同取消政策和同日期的竞价事实时，价格位置未知。',
            action: '补齐同平台、同房型、同权益、同取消政策和同入住日的本店与竞对价格样本后再判断。',
        },
        {
            key: 'bookability_gap',
            title: '可订性缺口',
            businessOrder: 6,
            possibleCause: '后台保存成功不等于游客侧可订；缺少搜索、详情和预订前检查时保持未知。',
            action: '以同平台、同入住日、同住客条件完成游客侧搜索、详情和预订前检查，并保存断点证据。',
        },
        {
            key: 'service_promise_risk',
            title: '服务承诺风险',
            businessOrder: 7,
            possibleCause: '只有承诺事实、履约事实、影响订单与单位损失都可信时才计算风险；否则只列补证。',
            action: '核对平台承诺、实际履约、影响订单和损失口径，缺少任一事实时不计算金额。',
        },
        {
            key: 'promotion_incrementality_evidence',
            title: '促销增量证据不足',
            businessOrder: 8,
            possibleCause: '平台归因不等于促销增量；没有活动阶段、对照、前趋势和样本门槛时不能形成因果结论。',
            action: '补齐同活动阶段、对照组、前趋势、样本量和来源质量，再评估促销增量；当前不宣称因果。',
        },
    ];

    const revenueCockpitMetricFromCards = (cards, platform, metricKey) => (
        (Array.isArray(cards) ? cards : []).find((card) => (
            String(card?.sourceKey || '') === `${platform}_ota`
            && String(card?.key || '').split(':').slice(-1)[0] === metricKey
        )) || null
    );

    const revenueCockpitBaseline = ({
        currentCards = [],
        previousCards = [],
        sameWeekdayCards = [],
        platform = '',
        metricKey = '',
        previousDate = '',
        sameWeekdayDate = '',
    } = {}) => {
        const current = revenueCockpitMetricFromCards(currentCards, platform, metricKey);
        const candidates = [
            {
                card: revenueCockpitMetricFromCards(sameWeekdayCards, platform, metricKey),
                basis: 'same_weekday',
                label: '同星期',
                date: sameWeekdayDate,
            },
            {
                card: revenueCockpitMetricFromCards(previousCards, platform, metricKey),
                basis: 'previous_comparable',
                label: '前一可比营业日',
                date: previousDate,
            },
        ];
        const baseline = candidates.find((item) => current?.value !== null && item.card?.value !== null) || candidates[0];
        const comparable = current?.value !== null && baseline?.card?.value !== null;
        const delta = comparable ? Number((current.value - baseline.card.value).toFixed(4)) : null;
        const deltaPercent = comparable && baseline.card.value !== 0
            ? Number((delta / Math.abs(baseline.card.value) * 100).toFixed(2))
            : null;
        return {
            current,
            baseline: baseline?.card || null,
            basis: baseline?.basis || 'missing',
            basisLabel: baseline?.label || '基期缺失',
            baselineDate: baseline?.date || '',
            comparable,
            delta,
            deltaPercent,
        };
    };

    const revenueCockpitSignalForPlatform = ({
        definition,
        platform,
        currentCards,
        previousCards,
        sameWeekdayCards,
        previousDate,
        sameWeekdayDate,
        sourceReady,
        businessDate,
    }) => {
        const compare = (metricKey) => revenueCockpitBaseline({
            currentCards,
            previousCards,
            sameWeekdayCards,
            platform,
            metricKey,
            previousDate,
            sameWeekdayDate,
        });
        let state = 'evidence_investigation';
        let priorityScore = null;
        let factChange = '缺少形成该判断所需的同口径事实或基期。';
        let evidenceSupport = sourceReady
            ? '当前平台来源已通过严格回读，但该机会仍缺必要指标或比较条件。'
            : '当前平台来源未通过严格回读，不能形成经营机会判断。';
        let missingEvidence = [];
        let reasonCodes = [];
        let formula = '';
        let baselineBasis = 'missing';
        let baselineDate = '';
        let platformDisplay = '待补证';

        if (definition.key === 'traffic_entry_shortage') {
            const signal = compare('list_exposure');
            baselineBasis = signal.basis;
            baselineDate = signal.baselineDate;
            formula = 'delta_percent = (current_list_exposure - baseline_list_exposure) / abs(baseline_list_exposure) * 100';
            if (signal.comparable && signal.deltaPercent !== null) {
                const actionable = signal.deltaPercent <= -15;
                state = actionable ? 'actionable' : 'no_signal';
                priorityScore = actionable ? Math.min(100, Number((60 + Math.abs(signal.deltaPercent)).toFixed(2))) : null;
                factChange = `${platform === 'ctrip' ? '携程' : '美团'}列表曝光较${signal.basisLabel} ${signal.deltaPercent > 0 ? '+' : ''}${signal.deltaPercent.toFixed(2)}%。`;
                evidenceSupport = `当前 ${signal.current.display}；基期 ${signal.baseline.display}；阈值为下降 15% 或以上。`;
                platformDisplay = actionable ? '曝光下降达到核查阈值' : '未命中曝光下降阈值';
            } else {
                reasonCodes.push('list_exposure_same_caliber_baseline_missing');
                missingEvidence.push('当前与基期列表曝光');
            }
        } else if (definition.key === 'detail_conversion_shortage') {
            const signal = compare('flow_rate_percent');
            baselineBasis = signal.basis;
            baselineDate = signal.baselineDate;
            formula = 'delta_pp = current_flow_rate_percent - baseline_flow_rate_percent';
            if (signal.comparable) {
                const actionable = signal.delta <= -2;
                state = actionable ? 'actionable' : 'no_signal';
                priorityScore = actionable ? Math.min(100, Number((62 + Math.abs(signal.delta) * 6).toFixed(2))) : null;
                factChange = `${platform === 'ctrip' ? '携程' : '美团'}列表到详情转化较${signal.basisLabel} ${signal.delta > 0 ? '+' : ''}${signal.delta.toFixed(2)} 个百分点。`;
                evidenceSupport = `当前 ${signal.current.display}；基期 ${signal.baseline.display}；阈值为下降 2 个百分点或以上。`;
                platformDisplay = actionable ? '详情转化下降达到核查阈值' : '未命中详情转化下降阈值';
            } else {
                reasonCodes.push('flow_rate_same_caliber_baseline_missing');
                missingEvidence.push('当前与基期列表到详情转化率');
            }
        } else if (definition.key === 'submit_payment_conversion_shortage') {
            const submit = compare('submit_rate_percent');
            const payment = compare('payment_conversion_percent');
            baselineBasis = submit.comparable ? submit.basis : payment.basis;
            baselineDate = submit.comparable ? submit.baselineDate : payment.baselineDate;
            formula = 'submit_delta_pp = current_submit_rate - baseline_submit_rate; payment must be assessed separately';
            if (submit.comparable) {
                const actionable = submit.delta <= -1;
                state = actionable ? 'actionable' : (payment.comparable ? 'no_signal' : 'evidence_investigation');
                priorityScore = actionable ? Math.min(100, Number((64 + Math.abs(submit.delta) * 7).toFixed(2))) : null;
                factChange = `${platform === 'ctrip' ? '携程' : '美团'}提交转化较${submit.basisLabel} ${submit.delta > 0 ? '+' : ''}${submit.delta.toFixed(2)} 个百分点；支付转化${payment.comparable ? '已有独立基期' : '仍缺独立事实'}。`;
                evidenceSupport = `提交当前 ${submit.current.display}；提交基期 ${submit.baseline.display}。`;
                if (!payment.comparable) {
                    missingEvidence.push('提交到支付的分子、分母和同口径基期');
                    reasonCodes.push('payment_conversion_missing');
                }
                platformDisplay = actionable ? '提交转化下降达到核查阈值' : (payment.comparable ? '未命中转化下降阈值' : '支付转化待补证');
            } else {
                reasonCodes.push('submit_conversion_same_caliber_baseline_missing');
                missingEvidence.push('当前与基期提交转化率', '提交到支付的分子、分母和同口径基期');
            }
        } else if (definition.key === 'cancellation_anomaly') {
            const signal = compare('cancellation_rate_percent');
            baselineBasis = signal.basis;
            baselineDate = signal.baselineDate;
            formula = 'delta_pp = current_cancellation_rate_percent - baseline_cancellation_rate_percent';
            if (signal.comparable) {
                const actionable = signal.delta >= 3;
                state = actionable ? 'actionable' : 'no_signal';
                priorityScore = actionable ? Math.min(100, Number((66 + signal.delta * 6).toFixed(2))) : null;
                factChange = `${platform === 'ctrip' ? '携程' : '美团'}取消率较${signal.basisLabel} ${signal.delta > 0 ? '+' : ''}${signal.delta.toFixed(2)} 个百分点。`;
                evidenceSupport = `当前 ${signal.current.display}；基期 ${signal.baseline.display}；阈值为上升 3 个百分点或以上。`;
                platformDisplay = actionable ? '取消率上升达到核查阈值' : '未命中取消异常阈值';
            } else {
                reasonCodes.push('cancellation_same_caliber_baseline_missing');
                missingEvidence.push('当前与基期取消率及毛订单基数');
            }
        } else if (definition.key === 'price_competition_position') {
            reasonCodes.push('comparable_competitor_price_missing');
            missingEvidence.push('同房型、同权益、同取消政策、同入住日的本店与竞对价格');
        } else if (definition.key === 'bookability_gap') {
            reasonCodes.push('guest_side_bookability_path_missing');
            missingEvidence.push('同住客条件的搜索、详情和预订前检查证据');
        } else if (definition.key === 'service_promise_risk') {
            reasonCodes.push('service_promise_effect_facts_missing');
            missingEvidence.push('平台承诺、履约事实、影响订单和单位损失');
        } else if (definition.key === 'promotion_incrementality_evidence') {
            reasonCodes.push('promotion_causal_design_missing');
            missingEvidence.push('同活动阶段、对照组、前趋势、样本量和来源质量');
            factChange = '当前平台事实只能描述活动期表现，不能证明促销造成了增量。';
            evidenceSupport = sourceReady
                ? '平台事实已严格回读；因果识别所需设计证据未提供。'
                : '平台事实与因果识别证据均未达到门槛。';
        }
        if (!sourceReady) {
            state = 'blocked';
            priorityScore = null;
            reasonCodes.push('strict_platform_source_not_ready');
            missingEvidence.unshift('同酒店、同平台、同营业日的严格回读来源');
        }
        if (!missingEvidence.length && state === 'no_signal') {
            missingEvidence = ['原因证据未检验；“未命中阈值”不等于经营一定正常'];
        }
        return {
            platform,
            businessDate,
            state,
            priorityScore,
            priorityBand: priorityScore === null ? (state === 'no_signal' ? 'monitor' : 'evidence_first')
                : (priorityScore >= 80 ? 'high' : 'medium'),
            evidenceLevel: sourceReady
                ? (state === 'actionable' || state === 'no_signal' ? 'strict_fact_formula' : 'strict_fact_partial')
                : 'missing_required_fact',
            display: platformDisplay,
            factChange,
            possibleCause: definition.possibleCause,
            evidenceSupport,
            missingEvidence,
            recommendedCheckAction: definition.action,
            reasonCodes: Array.from(new Set(reasonCodes)),
            formula,
            baselineBasis,
            baselineDate,
            relationshipType: state === 'actionable' ? 'same_caliber_change_signal' : 'not_established',
            correlationStatus: 'not_tested',
            causalityClaimed: false,
            causalityStatus: 'not_claimed',
            externalActionAllowed: false,
        };
    };

    const buildRevenueCockpitOpportunities = ({
        selectedOtaPlatforms = [],
        currentCards = [],
        previousCards = [],
        sameWeekdayCards = [],
        previousDate = '',
        sameWeekdayDate = '',
        sourceReadyByPlatform = {},
        businessDate = '',
    } = {}) => {
        const cards = REVENUE_COCKPIT_OPPORTUNITY_DEFINITIONS.map((definition) => {
            const platformSignals = selectedOtaPlatforms.map((platform) => revenueCockpitSignalForPlatform({
                definition,
                platform,
                currentCards,
                previousCards,
                sameWeekdayCards,
                previousDate,
                sameWeekdayDate,
                sourceReady: sourceReadyByPlatform[platform] === true,
                businessDate,
            }));
            const actionable = platformSignals.filter((signal) => signal.state === 'actionable')
                .sort((left, right) => Number(right.priorityScore || 0) - Number(left.priorityScore || 0));
            const investigations = platformSignals.filter((signal) => signal.state === 'evidence_investigation');
            const blocked = platformSignals.filter((signal) => signal.state === 'blocked');
            const state = actionable.length
                ? 'actionable'
                : (investigations.length ? 'evidence_investigation' : (blocked.length ? 'blocked' : 'no_signal'));
            const primary = actionable[0] || investigations[0] || blocked[0] || platformSignals[0] || {};
            const score = state === 'actionable' ? Number(primary.priorityScore) : null;
            const platformSummary = platformSignals.map((signal) => (
                `${signal.platform === 'ctrip' ? '携程' : '美团'}：${signal.display}`
            )).join('；');
            const evidenceLines = [
                ...platformSignals.flatMap((signal) => [
                    `${signal.platform === 'ctrip' ? '携程' : '美团'}事实变化：${signal.factChange}`,
                    `${signal.platform === 'ctrip' ? '携程' : '美团'}证据支持：${signal.evidenceSupport}`,
                    `${signal.platform === 'ctrip' ? '携程' : '美团'}尚缺证据：${signal.missingEvidence.join('、') || '无'}`,
                ]),
                '证据分类：平台事实 → 公式计算 → 模型解释；相关性未检验；因果结论未声明；缺失保持未知。',
                `核查动作：${definition.action}`,
            ];
            return {
                key: `opportunity:${definition.key}`,
                kind: 'opportunity',
                opportunityKey: definition.key,
                title: definition.title,
                label: definition.title,
                display: platformSummary || '缺少平台范围',
                value: score,
                unit: 'priority_score',
                unitLabel: '透明机会优先分',
                sourceKey: 'cockpit_rule',
                sourceLabel: '可信收益机会规则',
                businessDate,
                status: state,
                statusLabel: revenueCockpitStatusText(state),
                statusClass: revenueCockpitStatusClass(state),
                state,
                scope: 'selected_ota_platforms_only',
                scopeLabel: '所选 OTA 平台独立判断',
                missingState: state === 'actionable' || state === 'no_signal' ? '有同口径事实' : '缺少必要证据',
                reasonCode: Array.from(new Set(platformSignals.flatMap((signal) => signal.reasonCodes))).join('|'),
                reasonText: primary.possibleCause || definition.possibleCause,
                businessOrder: definition.businessOrder,
                priorityScore: score,
                priorityBand: score === null ? (state === 'no_signal' ? 'monitor' : 'evidence_first')
                    : (score >= 80 ? 'high' : 'medium'),
                evidenceLevel: primary.evidenceLevel || 'missing_required_fact',
                platformSignals,
                factChange: primary.factChange || '未知',
                possibleCause: primary.possibleCause || definition.possibleCause,
                evidenceSupport: primary.evidenceSupport || '证据不足',
                missingEvidence: Array.from(new Set(platformSignals.flatMap((signal) => signal.missingEvidence))),
                recommendedCheckAction: definition.action,
                formula: primary.formula || '',
                interpretationKind: 'model_explanation',
                relationshipType: primary.relationshipType || 'not_established',
                correlationStatus: 'not_tested',
                causalityClaimed: false,
                causalityStatus: 'not_claimed',
                unknownStatePreserved: state !== 'actionable' && state !== 'no_signal',
                canCreatePendingApproval: ['actionable', 'evidence_investigation'].includes(state)
                    && platformSignals.some((signal) => signal.evidenceLevel !== 'missing_required_fact'),
                evidenceLines,
            };
        });
        const stateOrder = { actionable: 0, evidence_investigation: 1, no_signal: 2, blocked: 3, unknown: 4 };
        cards.sort((left, right) => (
            (stateOrder[left.state] ?? 9) - (stateOrder[right.state] ?? 9)
            || (left.state === 'actionable' ? Number(right.priorityScore || 0) - Number(left.priorityScore || 0) : 0)
            || Number(left.businessOrder || 0) - Number(right.businessOrder || 0)
            || String(left.opportunityKey).localeCompare(String(right.opportunityKey))
        ));
        return cards.map((card, index) => ({
            ...card,
            rank: index + 1,
            display: `第 ${index + 1} 位 · ${card.display}`,
        }));
    };

    const buildRevenueCockpitModel = ({
        overview = null,
        comparisonOverview = null,
        sameWeekdayOverview = null,
        scope = null,
        selectedPlatform = '',
        businessDate = '',
        today = '',
        loading = false,
        error = '',
    } = {}) => {
        const context = scope && typeof scope === 'object' ? scope : {};
        const platform = String(selectedPlatform || context.selectedPlatform || '').toLowerCase();
        const date = revenueCockpitIsoDate(businessDate || context.selectedDate || overview?.business_date);
        const dateDistance = revenueCockpitDateDistance(date, today);
        const selectedOtaPlatforms = platform === 'all_ota'
            ? ['ctrip', 'meituan']
            : (['ctrip', 'meituan'].includes(platform) ? [platform] : []);
        const emptyModel = (status, summary) => ({
            contractVersion: 'revenue_daily_cockpit.v2',
            status,
            statusLabel: status === 'loading' ? '加载中' : (status === 'failed' ? '加载失败' : '无严格可用数据'),
            statusClass: revenueCockpitStatusClass(status),
            headline: status === 'loading' ? '正在读取严格回读事实' : (status === 'failed' ? '经营驾驶舱读取失败' : '暂无严格可用经营日期'),
            summary,
            dateNotice: context.notice || '',
            scopeBoundary: 'PMS 只形成全酒店住宿事实；OTA 只形成渠道结论，二者收入不相加。',
            selectedPlatform: platform,
            selectedPlatformLabel: revenueCockpitPlatformLabel(platform),
            businessDate: date,
            previousDate: context.previousDate || '',
            sameWeekdayDate: context.sameWeekdayDate || '',
            dateDistance,
            tenantId: 0,
            hotelId: 0,
            hotelName: '',
            sections: [],
            visibleSections: [],
            opportunities: [],
            comparisonFrames: [],
            anomalyChains: [],
            sourceRecords: [],
            missingItems: [],
            evidenceSummary: {},
            canAskQuestion: false,
            canCreatePendingApproval: false,
            canSaveSnapshot: false,
            actionDisabledReason: summary,
        });
        if (loading) return emptyModel('loading', '正在读取可用日期、当前事实和上一同口径日期。');
        if (error) return emptyModel('failed', String(error));
        if (!overview || !date || selectedOtaPlatforms.length === 0) {
            return emptyModel('empty', context.notice || '没有找到可供驾驶舱使用的严格回读事实。');
        }
        const factLayer = overview.three_source_fact_layer && typeof overview.three_source_fact_layer === 'object'
            ? overview.three_source_fact_layer
            : {};
        const sources = factLayer.sources && typeof factLayer.sources === 'object' ? factLayer.sources : {};
        const strictContract = overview.cockpit_strict_evidence
            && typeof overview.cockpit_strict_evidence === 'object'
            ? overview.cockpit_strict_evidence
            : {};
        const strictPlatforms = strictContract.platforms && typeof strictContract.platforms === 'object'
            ? strictContract.platforms
            : {};
        if (!Object.keys(sources).length) {
            return emptyModel('empty', '总览接口未返回三源事实层；数值保持为空。');
        }
        const previousFactLayer = comparisonOverview?.three_source_fact_layer && typeof comparisonOverview.three_source_fact_layer === 'object'
            ? comparisonOverview.three_source_fact_layer
            : {};
        const previousSources = previousFactLayer.sources && typeof previousFactLayer.sources === 'object'
            ? previousFactLayer.sources
            : {};
        const previousStrictContract = comparisonOverview?.cockpit_strict_evidence
            && typeof comparisonOverview.cockpit_strict_evidence === 'object'
            ? comparisonOverview.cockpit_strict_evidence
            : {};
        const previousStrictPlatforms = previousStrictContract.platforms
            && typeof previousStrictContract.platforms === 'object'
            ? previousStrictContract.platforms
            : {};
        const sameWeekdayFactLayer = sameWeekdayOverview?.three_source_fact_layer
            && typeof sameWeekdayOverview.three_source_fact_layer === 'object'
            ? sameWeekdayOverview.three_source_fact_layer
            : {};
        const sameWeekdaySources = sameWeekdayFactLayer.sources
            && typeof sameWeekdayFactLayer.sources === 'object'
            ? sameWeekdayFactLayer.sources
            : {};
        const sameWeekdayStrictContract = sameWeekdayOverview?.cockpit_strict_evidence
            && typeof sameWeekdayOverview.cockpit_strict_evidence === 'object'
            ? sameWeekdayOverview.cockpit_strict_evidence
            : {};
        const sameWeekdayStrictPlatforms = sameWeekdayStrictContract.platforms
            && typeof sameWeekdayStrictContract.platforms === 'object'
            ? sameWeekdayStrictContract.platforms
            : {};
        const strictEvidenceForSource = (sourceKey, platformRows) => {
            if (!String(sourceKey || '').endsWith('_ota')) return {};
            return platformRows[String(sourceKey).replace(/_ota$/, '')] || {};
        };
        const sourceKeys = ['dingdandao_pms', ...selectedOtaPlatforms.map((item) => `${item}_ota`)];
        const sourceCards = sourceKeys.map((sourceKey) => revenueCockpitSourceCard(
            sources[sourceKey] || {},
            sourceKey,
            date,
            strictEvidenceForSource(sourceKey, strictPlatforms),
        ));
        const coreDefinitions = {
            dingdandao_pms: [
                ['room_revenue', '全酒店住宿房费', 'CNY'],
                ['sold_room_nights', '全酒店已售间夜', 'room_nights'],
                ['occupancy_rate_percent', '全酒店入住率', 'percent'],
                ['adr', '全酒店住宿 ADR', 'CNY'],
                ['revpar', '全酒店住宿 RevPAR', 'CNY'],
            ],
            ctrip_ota: [
                ['revenue', '携程渠道订单金额', 'CNY'],
                ['orders', '携程渠道订单', 'orders'],
                ['room_nights', '携程渠道间夜', 'room_nights'],
                ['adr', '携程订单金额 / 间夜', 'CNY'],
            ],
            meituan_ota: [
                ['revenue', '美团渠道订单金额', 'CNY'],
                ['orders', '美团渠道订单', 'orders'],
                ['room_nights', '美团渠道间夜', 'room_nights'],
                ['adr', '美团订单金额 / 间夜', 'CNY'],
            ],
        };
        const trafficDefinitions = [
            ['list_exposure', '列表曝光', 'exposures'],
            ['detail_exposure', '详情曝光', 'exposures'],
            ['flow_rate_percent', '进店转化率', 'percent'],
            ['submit_rate_percent', '提交转化率', 'percent'],
            ['payment_conversion_percent', '支付转化率', 'percent'],
            ['cancellation_rate_percent', '取消率', 'percent'],
        ];
        const coreCards = sourceKeys.flatMap((sourceKey) => (coreDefinitions[sourceKey] || []).map(
            ([metricKey, label, unit]) => revenueCockpitMetricCard({
                source: sources[sourceKey] || {},
                sourceKey,
                metricKey,
                label,
                unit,
                businessDate: date,
                strictEvidence: strictEvidenceForSource(sourceKey, strictPlatforms),
            }),
        ));
        const trafficCards = selectedOtaPlatforms.flatMap((otaPlatform) => {
            const sourceKey = `${otaPlatform}_ota`;
            return trafficDefinitions.map(([metricKey, baseLabel, unit]) => revenueCockpitMetricCard({
                source: sources[sourceKey] || {},
                sourceKey,
                metricKey,
                label: `${revenueCockpitPlatformLabel(otaPlatform)}${baseLabel}`,
                unit,
                businessDate: date,
                strictEvidence: strictEvidenceForSource(sourceKey, strictPlatforms),
            }));
        });
        const previousCoreCards = sourceKeys.flatMap((sourceKey) => (coreDefinitions[sourceKey] || []).map(
            ([metricKey, label, unit]) => revenueCockpitMetricCard({
                source: previousSources[sourceKey] || {},
                sourceKey,
                metricKey,
                label,
                unit,
                businessDate: context.previousDate || comparisonOverview?.business_date || '',
                strictEvidence: strictEvidenceForSource(sourceKey, previousStrictPlatforms),
            }),
        ));
        const previousTrafficCards = selectedOtaPlatforms.flatMap((otaPlatform) => {
            const sourceKey = `${otaPlatform}_ota`;
            return trafficDefinitions.map(([metricKey, baseLabel, unit]) => revenueCockpitMetricCard({
                source: previousSources[sourceKey] || {},
                sourceKey,
                metricKey,
                label: `${revenueCockpitPlatformLabel(otaPlatform)}${baseLabel}`,
                unit,
                businessDate: context.previousDate || comparisonOverview?.business_date || '',
                strictEvidence: strictEvidenceForSource(sourceKey, previousStrictPlatforms),
            }));
        });
        const sameWeekdayCoreCards = sourceKeys.flatMap((sourceKey) => (coreDefinitions[sourceKey] || []).map(
            ([metricKey, label, unit]) => revenueCockpitMetricCard({
                source: sameWeekdaySources[sourceKey] || {},
                sourceKey,
                metricKey,
                label,
                unit,
                businessDate: context.sameWeekdayDate || sameWeekdayOverview?.business_date || '',
                strictEvidence: strictEvidenceForSource(sourceKey, sameWeekdayStrictPlatforms),
            }),
        ));
        const sameWeekdayTrafficCards = selectedOtaPlatforms.flatMap((otaPlatform) => {
            const sourceKey = `${otaPlatform}_ota`;
            return trafficDefinitions.map(([metricKey, baseLabel, unit]) => revenueCockpitMetricCard({
                source: sameWeekdaySources[sourceKey] || {},
                sourceKey,
                metricKey,
                label: `${revenueCockpitPlatformLabel(otaPlatform)}${baseLabel}`,
                unit,
                businessDate: context.sameWeekdayDate || sameWeekdayOverview?.business_date || '',
                strictEvidence: strictEvidenceForSource(sourceKey, sameWeekdayStrictPlatforms),
            }));
        });
        const currentComparableCards = [...coreCards, ...trafficCards];
        const previousComparableCards = [...previousCoreCards, ...previousTrafficCards];
        const sameWeekdayComparableCards = [...sameWeekdayCoreCards, ...sameWeekdayTrafficCards];
        const previousByKey = new Map(previousComparableCards.map((card) => [card.key, card]));
        const sameWeekdayByKey = new Map(sameWeekdayComparableCards.map((card) => [card.key, card]));
        const comparisonCards = currentComparableCards.map((card) => revenueCockpitComparisonCard(
            card,
            previousByKey.get(card.key),
            context.previousDate || comparisonOverview?.business_date || '',
        ));
        const sameWeekdayComparisonCards = currentComparableCards.map((card) => revenueCockpitComparisonCard(
            card,
            sameWeekdayByKey.get(card.key),
            context.sameWeekdayDate || sameWeekdayOverview?.business_date || '',
            { basisKey: 'same_weekday', basisLabel: '同星期' },
        ));
        const coverageFor = (cards, sourceKey) => {
            const scoped = cards.filter((card) => card.sourceKey === sourceKey);
            const readyKeys = scoped
                .filter((card) => card.value !== null)
                .map((card) => card.key.split(':').slice(-1)[0])
                .sort();
            return { ready: readyKeys.length, total: scoped.length, readyKeys };
        };
        const coverageCards = selectedOtaPlatforms.flatMap((otaPlatform) => {
            const sourceKey = `${otaPlatform}_ota`;
            const currentCoverage = coverageFor(currentComparableCards, sourceKey);
            const frames = [
                {
                    key: 'previous_comparable',
                    label: '前一可比营业日',
                    date: context.previousDate || '',
                    coverage: coverageFor(previousComparableCards, sourceKey),
                },
                {
                    key: 'same_weekday',
                    label: '同星期',
                    date: context.sameWeekdayDate || '',
                    coverage: coverageFor(sameWeekdayComparableCards, sourceKey),
                },
            ];
            return frames.map((frame) => {
                const sameCoverage = frame.date
                    && currentCoverage.ready === frame.coverage.ready
                    && currentCoverage.readyKeys.join('|') === frame.coverage.readyKeys.join('|');
                return revenueCockpitTextCard({
                    key: `coverage:${frame.key}:${otaPlatform}`,
                    kind: 'comparison',
                    label: `${revenueCockpitPlatformLabel(otaPlatform)} · ${frame.label}覆盖差异`,
                    display: frame.date
                        ? `当前 ${currentCoverage.ready}/${currentCoverage.total} 项；基期 ${frame.coverage.ready}/${frame.coverage.total} 项${sameCoverage ? '，覆盖一致' : '，覆盖不一致'}`
                        : '—',
                    sourceKey,
                    businessDate: date,
                    status: frame.date ? (sameCoverage ? 'verified' : 'partial') : 'not_calculable',
                    reasonCode: frame.date && !sameCoverage ? 'comparison_coverage_mismatch' : (frame.date ? '' : `${frame.key}_missing`),
                    reasonText: frame.date
                        ? (sameCoverage
                            ? '当前期与基期可用指标集合一致。'
                            : '当前期与基期可用指标集合不同；变化值仍展示，但不得直接解释为经营变化。')
                        : `没有${frame.label}严格回读日期，比较保持为空。`,
                    evidenceLines: [
                        `当前可用指标：${currentCoverage.readyKeys.join('、') || '无'}`,
                        `基期可用指标：${frame.coverage.readyKeys.join('、') || '无'}`,
                        '覆盖规则：指标集合不一致必须提示，禁止把缺字段造成的变化当作经营变化。',
                    ],
                });
            });
        });
        const campaignStageCard = revenueCockpitTextCard({
            key: 'comparison:campaign_stage',
            kind: 'comparison',
            label: '同活动阶段比较',
            display: '—',
            businessDate: date,
            status: 'not_calculable',
            reasonCode: 'campaign_stage_identity_missing',
            reasonText: '当前严格 OTA 日事实未携带活动 ID、阶段和阶段日期窗，不能静默选择其他日期作为同活动阶段。',
            evidenceLines: [
                `当前业务日：${date}`,
                '尚缺证据：活动 ID、活动阶段、阶段起止日及基期来源记录。',
                '边界：缺少活动阶段身份时比较为未知，不形成促销增量或因果结论。',
            ],
        });

        const rawGaps = (Array.isArray(factLayer.analysis_gaps) ? factLayer.analysis_gaps : [])
            .filter((gap) => gap && typeof gap === 'object');
        const anomalyCards = [];
        rawGaps.forEach((gap, index) => {
            const code = String(gap.code || `fact_gap_${index + 1}`);
            anomalyCards.push(revenueCockpitTextCard({
                key: `anomaly:${code}:${String(gap.source || index)}`,
                kind: 'anomaly',
                label: '事实异常 / 阻断',
                display: String(gap.display_reason || gap.message || revenueAiReasonText(code)),
                sourceKey: String(gap.source || 'cockpit_rule'),
                businessDate: date,
                status: String(gap.status || 'partial'),
                reasonCode: code,
                reasonText: String(gap.next_action || revenueAiReasonText(code)),
                evidenceLines: [
                    `异常代码：${code}`,
                    `来源：${String(gap.source || '三源事实层')}`,
                    `业务日期：${date}`,
                    `处理建议：${String(gap.next_action || '补齐同店同日事实并重新回读。')}`,
                ],
            }));
        });
        selectedOtaPlatforms.forEach((otaPlatform) => {
            const prefix = `${otaPlatform}_ota`;
            const cards = Object.fromEntries(coreCards
                .filter((card) => card.sourceKey === prefix)
                .map((card) => [card.key.split(':')[1], card]));
            if (cards.revenue?.value > 0 && cards.orders?.value === 0) {
                anomalyCards.push(revenueCockpitTextCard({
                    key: `anomaly:${otaPlatform}:revenue_positive_orders_zero`,
                    kind: 'anomaly',
                    label: `${revenueCockpitPlatformLabel(otaPlatform)}收入与订单矛盾`,
                    display: revenueAiReasonText('revenue_positive_orders_zero'),
                    sourceKey: prefix,
                    businessDate: date,
                    status: 'partial',
                    reasonCode: 'revenue_positive_orders_zero',
                }));
            }
            if (cards.revenue?.value > 0 && cards.room_nights?.value === 0) {
                anomalyCards.push(revenueCockpitTextCard({
                    key: `anomaly:${otaPlatform}:revenue_positive_room_nights_zero`,
                    kind: 'anomaly',
                    label: `${revenueCockpitPlatformLabel(otaPlatform)}收入与间夜矛盾`,
                    display: revenueAiReasonText('revenue_positive_room_nights_zero'),
                    sourceKey: prefix,
                    businessDate: date,
                    status: 'partial',
                    reasonCode: 'revenue_positive_room_nights_zero',
                }));
            }
        });
        if (!anomalyCards.length) {
            anomalyCards.push(revenueCockpitTextCard({
                key: 'anomaly:none_verified',
                kind: 'anomaly',
                label: '异常判断',
                display: '当前已验证事实未命中可判定异常',
                businessDate: date,
                status: 'verified',
                reasonText: '这不代表经营一定正常，只代表当前已回读字段未命中确定性异常规则。',
            }));
        }

        const missingMetricCards = [...sourceCards, ...coreCards, ...trafficCards]
            .filter((card) => card.missingState !== '有值' && card.missingState !== '完整');
        const gapCards = [];
        const seenGapKeys = new Set();
        missingMetricCards.forEach((card) => {
            const key = `gap:${card.key}`;
            if (seenGapKeys.has(key)) return;
            seenGapKeys.add(key);
            gapCards.push(revenueCockpitTextCard({
                key,
                kind: 'gap',
                label: `${card.label}缺口`,
                display: card.reasonText || '该卡片缺少同店同日严格回读事实。',
                sourceKey: card.sourceKey,
                businessDate: date,
                status: card.status,
                reasonCode: card.reasonCode,
                reasonText: '补齐相同酒店、来源与业务日的保存记录并完成精确回读；不使用 0、旧日或其他来源代替。',
                evidenceLines: card.evidenceLines,
            }));
        });
        rawGaps.forEach((gap, index) => {
            const code = String(gap.code || `fact_gap_${index + 1}`);
            const key = `gap:fact-layer:${code}:${String(gap.source || index)}`;
            if (seenGapKeys.has(key)) return;
            seenGapKeys.add(key);
            gapCards.push(revenueCockpitTextCard({
                key,
                kind: 'gap',
                label: String(gap.category || '三源事实缺口'),
                display: String(gap.display_reason || gap.message || revenueAiReasonText(code)),
                sourceKey: String(gap.source || 'cockpit_rule'),
                businessDate: date,
                status: String(gap.status || 'partial'),
                reasonCode: code,
                reasonText: String(gap.next_action || '补齐同店同日来源并完成严格回读。'),
            }));
        });
        if (!gapCards.length) {
            gapCards.push(revenueCockpitTextCard({
                key: 'gap:none',
                kind: 'gap',
                label: '数据缺口',
                display: '当前可见卡片未发现缺失或未验证字段',
                businessDate: date,
                status: 'verified',
                reasonText: '仅代表当前筛选范围和可见字段，不扩大为其他平台或全酒店完整性结论。',
            }));
        }

        const actionCards = [];
        if (dateDistance !== null && dateDistance > 0) {
            actionCards.push(revenueCockpitTextCard({
                key: 'action:refresh-current-date',
                kind: 'action',
                label: '优先补齐今天的数据',
                display: `当前最近严格可用日为 ${date}，比今天早 ${dateDistance} 天；先复核今天是否已采集、保存并严格回读。`,
                businessDate: date,
                status: 'partial',
                reasonText: '入口只提示补数，不自动启动采集。',
            }));
        }
        rawGaps.slice(0, 4).forEach((gap, index) => {
            const code = String(gap.code || `fact_gap_${index + 1}`);
            actionCards.push(revenueCockpitTextCard({
                key: `action:${code}:${index}`,
                kind: 'action',
                label: `处理 ${String(gap.category || gap.source || '数据缺口')}`,
                display: String(gap.next_action || '补齐对应来源并完成同店同日保存回读。'),
                sourceKey: String(gap.source || 'cockpit_rule'),
                businessDate: date,
                status: 'partial',
                reasonCode: code,
                reasonText: '建议动作必须经过人工复核；本页不会自动采集、审批或执行。',
            }));
        });
        if (!actionCards.length) {
            actionCards.push(revenueCockpitTextCard({
                key: 'action:daily-review',
                kind: 'action',
                label: '完成当日人工复核',
                display: '核对收入、订单、流量转化与变化后，再决定是否生成待审批行动。',
                businessDate: date,
                status: 'verified',
                reasonText: '建议只读，不自动写 OTA 或创建执行任务。',
            }));
        }

        const sourceReadyByPlatform = Object.fromEntries(selectedOtaPlatforms.map((otaPlatform) => {
            const strictSource = strictPlatforms[otaPlatform]
                && typeof strictPlatforms[otaPlatform] === 'object'
                ? strictPlatforms[otaPlatform]
                : {};
            const acceptedIds = Array.isArray(strictSource.accepted_row_ids)
                ? strictSource.accepted_row_ids
                : [];
            return [otaPlatform, strictSource.source_strict_readback === true
                && acceptedIds.some((id) => Number(id) > 0)];
        }));
        const requiredOtaSourcesReady = selectedOtaPlatforms.every(
            (otaPlatform) => sourceReadyByPlatform[otaPlatform] === true,
        );
        const opportunityCards = buildRevenueCockpitOpportunities({
            selectedOtaPlatforms,
            currentCards: currentComparableCards,
            previousCards: previousComparableCards,
            sameWeekdayCards: sameWeekdayComparableCards,
            previousDate: context.previousDate || comparisonOverview?.business_date || '',
            sameWeekdayDate: context.sameWeekdayDate || sameWeekdayOverview?.business_date || '',
            sourceReadyByPlatform,
            businessDate: date,
        });
        const anomalyChains = opportunityCards.map((card) => ({
            anomalyId: `anomaly-chain:${card.opportunityKey}`,
            opportunityKey: card.opportunityKey,
            factChange: card.factChange,
            possibleCause: card.possibleCause,
            evidenceSupport: card.evidenceSupport,
            missingEvidence: card.missingEvidence,
            recommendedCheckAction: card.recommendedCheckAction,
            interpretationKind: card.interpretationKind,
            relationshipType: card.relationshipType,
            correlationStatus: card.correlationStatus,
            causalityClaimed: false,
        }));
        const comparisonFrames = [
            {
                key: 'previous_comparable',
                label: '前一可比营业日',
                currentDate: date,
                baselineDate: context.previousDate || '',
                status: context.previousDate ? 'available' : 'missing',
                sameHotel: true,
                samePlatform: true,
                coverageWarning: coverageCards.some((card) => card.key.includes('previous_comparable') && card.status === 'partial'),
            },
            {
                key: 'same_weekday',
                label: '同星期',
                currentDate: date,
                baselineDate: context.sameWeekdayDate || '',
                status: context.sameWeekdayDate ? 'available' : 'missing',
                sameHotel: true,
                samePlatform: true,
                coverageWarning: coverageCards.some((card) => card.key.includes('same_weekday') && card.status === 'partial'),
            },
            {
                key: 'same_campaign_stage',
                label: '同活动阶段',
                currentDate: date,
                baselineDate: '',
                status: 'missing_campaign_identity',
                sameHotel: true,
                samePlatform: true,
                coverageWarning: true,
            },
        ];

        const sections = [
            {
                key: 'data_completeness',
                title: '1. 数据是否完整',
                subtitle: '按来源独立判断；部分数据、读取失败和未验证不会显示成正常。',
                cards: sourceCards,
            },
            {
                key: 'core_metrics',
                title: '2. 核心收入与订单指标',
                subtitle: 'PMS 与各 OTA 独立展示；不同来源或单位禁止静默合并。',
                cards: coreCards,
            },
            {
                key: 'traffic_conversion',
                title: '3. 渠道流量和转化',
                subtitle: '只形成所选 OTA 渠道结论，不扩大为全酒店流量事实。',
                cards: trafficCards,
            },
            {
                key: 'comparable_change',
                title: '4. 同口径变化',
                subtitle: `当前 ${date} 分别核对前一可比营业日、同星期和同活动阶段；覆盖不一致会单独提示。`,
                cards: [
                    ...comparisonCards,
                    ...sameWeekdayComparisonCards,
                    ...coverageCards,
                    campaignStageCard,
                ],
            },
            {
                key: 'anomaly_reasons',
                title: '5. 异常原因',
                subtitle: '只陈述已验证字段能支持的异常或阻断，不把缺失推断为正常。',
                cards: anomalyCards,
            },
            {
                key: 'opportunity_ranking',
                title: '6. 八类经营机会排序',
                subtitle: '有同口径信号的机会按透明规则排序；证据缺口进入补证队列且不伪造机会分。',
                cards: opportunityCards,
            },
            {
                key: 'data_gaps',
                title: '7. 数据缺口',
                subtitle: '逐项保留缺失、未验证、读取失败和无法比较状态。',
                cards: gapCards,
            },
            {
                key: 'suggested_actions',
                title: '8. 其他核查动作',
                subtitle: '建议只读，必须由用户主动选择后才能进入待审批流程。',
                cards: actionCards,
            },
        ];
        const completeSourceCount = sourceCards.filter((card) => card.status === 'readback_verified').length;
        const status = completeSourceCount === sourceCards.length && missingMetricCards.length === 0
            ? 'ready'
            : 'partial';
        const oldDateNotice = dateDistance === null
            ? '与今天的差异待确认。'
            : (dateDistance === 0
                ? '业务日就是今天。'
                : (dateDistance > 0
                    ? `业务日比今天早 ${dateDistance} 天，页面展示的是最新严格可用历史事实。`
                    : `业务日晚于今天 ${Math.abs(dateDistance)} 天，请复核日期。`));
        const sourceRecords = selectedOtaPlatforms.map((otaPlatform) => ({
            sourceKey: `${otaPlatform}_ota`,
            table: 'online_daily_data',
            platform: otaPlatform,
            businessDate: date,
            rowIds: (Array.isArray(strictPlatforms?.[otaPlatform]?.accepted_row_ids)
                ? strictPlatforms[otaPlatform].accepted_row_ids
                : []).map(Number).filter((id) => id > 0),
            readbackStatus: sourceReadyByPlatform[otaPlatform] ? 'readback_verified' : 'not_verified',
            factScope: 'ota_channel',
        }));
        const pmsProvenance = sources?.dingdandao_pms?.source && typeof sources.dingdandao_pms.source === 'object'
            ? sources.dingdandao_pms.source
            : {};
        if (sourceCards.find((card) => card.sourceKey === 'dingdandao_pms')?.status === 'readback_verified'
            && Number(pmsProvenance.record_id || 0) > 0
        ) {
            sourceRecords.push({
                sourceKey: 'dingdandao_pms',
                table: 'dingdandao_operating_target_captures',
                platform: 'dingdandao_pms',
                businessDate: date,
                rowIds: [Number(pmsProvenance.record_id)],
                readbackStatus: 'readback_verified',
                factScope: 'whole_hotel_accommodation',
            });
        }
        const missingItems = sections.flatMap((section) => section.cards
            .filter((card) => !['readback_verified', 'derived_verified', 'verified', 'ready', 'ok', 'no_signal'].includes(String(card.status || '')))
            .map((card) => ({
                sectionKey: section.key,
                cardKey: card.key,
                label: card.label,
                status: card.status,
                reasonCode: card.reasonCode || 'unknown',
                sourceKey: card.sourceKey || 'cockpit_rule',
            })));
        const metricDefinitions = {
            revenue: { label: 'OTA渠道订单金额', unit: 'CNY', sourceMeaning: 'order_amount', scope: 'per_ota_platform', missingPolicy: 'null' },
            orders: { label: 'OTA渠道订单', unit: 'orders', scope: 'per_ota_platform', missingPolicy: 'null' },
            room_nights: { label: 'OTA渠道间夜', unit: 'room_nights', scope: 'per_ota_platform', missingPolicy: 'null' },
            adr: { label: 'OTA订单金额 / 间夜', unit: 'CNY', formula: 'order_amount / room_nights', scope: 'per_ota_platform', missingPolicy: 'null' },
            list_exposure: { label: '列表曝光', unit: 'exposures', scope: 'per_ota_platform', missingPolicy: 'null' },
            detail_exposure: { label: '详情访问/曝光', unit: 'exposures', scope: 'per_ota_platform', missingPolicy: 'null', uvClaimed: false },
            flow_rate_percent: { label: '列表到详情转化率', unit: 'percent', scope: 'per_ota_platform', missingPolicy: 'null' },
            submit_rate_percent: { label: '详情到提交转化率', unit: 'percent', scope: 'per_ota_platform', missingPolicy: 'null' },
            payment_conversion_percent: { label: '提交到支付转化率', unit: 'percent', scope: 'per_ota_platform', missingPolicy: 'null' },
            cancellation_rate_percent: { label: '取消率', unit: 'percent', scope: 'per_ota_platform', missingPolicy: 'null' },
            bookability: { label: '游客侧可订性', unit: 'status', scope: 'per_ota_platform', missingPolicy: 'unknown' },
        };
        const hotelId = Number(factLayer?.hotel?.system_hotel_id || overview?.hotel_id || 0);
        const tenantId = Number(factLayer?.hotel?.tenant_id || strictContract?.tenant_id || 0);
        return {
            contractVersion: 'revenue_daily_cockpit.v2',
            status,
            statusLabel: status === 'ready' ? '数据完整可读' : '部分数据可读',
            statusClass: revenueCockpitStatusClass(status),
            headline: status === 'ready' ? '当前经营状态已有完整可追溯视图' : '当前经营状态可读，但仍有明确数据缺口',
            summary: `${sourceCards.filter((card) => card.status === 'readback_verified').length}/${sourceCards.length} 个当前来源完成严格回读；缺失项保持为空。`,
            dateNotice: `${context.notice || ''}${context.notice ? ' ' : ''}${oldDateNotice}`,
            scopeBoundary: 'PMS 只形成全酒店住宿事实；携程/美团订单金额只形成各自 OTA 渠道结论；不同来源收入不相加，订单金额也不相加，order_amount 不冒充已核验房费收入。',
            selectedPlatform: platform,
            selectedPlatformLabel: revenueCockpitPlatformLabel(platform),
            businessDate: date,
            previousDate: context.previousDate || '',
            sameWeekdayDate: context.sameWeekdayDate || '',
            dateDistance,
            tenantId,
            hotelId,
            hotelName: String(factLayer?.hotel?.name || ''),
            sections,
            visibleSections: sections,
            opportunities: opportunityCards,
            comparisonFrames,
            anomalyChains,
            sourceRecords,
            metricDefinitions,
            missingItems,
            evidenceSummary: {
                strictGate: String(strictContract.strict_gate || 'history_success+validation_verified+readback_verified'),
                sourceRecordCount: sourceRecords.length,
                opportunityCount: opportunityCards.length,
                wholeHotelConclusionAllowed: sourceRecords.some((record) => record.factScope === 'whole_hotel_accommodation'),
                otaPlatformsSeparate: true,
                pageDownloadSharedViewModel: true,
                causalityClaimed: false,
            },
            canAskQuestion: hotelId > 0 && !!date,
            canCreatePendingApproval: requiredOtaSourcesReady,
            canSaveSnapshot: requiredOtaSourcesReady && tenantId > 0 && hotelId > 0,
            actionDisabledReason: requiredOtaSourcesReady
                ? ''
                : '所选 OTA 范围尚未同时返回可追溯记录ID和严格回读状态，不能生成待审批行动。',
        };
    };

    const buildRevenueCockpitDownloadRows = (model = {}) => {
        const sections = Array.isArray(model.visibleSections) ? model.visibleSections : [];
        let order = 0;
        return sections.flatMap((section) => (
            (Array.isArray(section.cards) ? section.cards : []).map((card) => {
                order += 1;
                return {
                    order,
                    section: String(section.title || ''),
                    card: String(card.label || ''),
                    display: String(card.display || '—'),
                    unit: String(card.unitLabel || card.unit || ''),
                    source: String(card.sourceLabel || ''),
                    business_date: String(card.businessDate || model.businessDate || ''),
                    verification_status: String(card.statusLabel || ''),
                    scope: String(card.scopeLabel || ''),
                    missing_state: String(card.missingState || ''),
                    opportunity_key: String(card.opportunityKey || ''),
                    rank: Number(card.rank || 0) || '',
                    evidence_level: String(card.evidenceLevel || ''),
                    relationship_type: String(card.relationshipType || ''),
                    causality_claimed: card.causalityClaimed === true ? 'true' : 'false',
                    explanation: String(card.reasonText || ''),
                    evidence: (Array.isArray(card.evidenceLines) ? card.evidenceLines : []).join('；'),
                };
            })
        ));
    };

    const revenueCockpitCsvCell = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

    const buildRevenueCockpitCsv = (model = {}) => {
        const headers = [
            ['order', '顺序'],
            ['section', '分区'],
            ['card', '卡片'],
            ['display', '页面显示'],
            ['unit', '单位'],
            ['source', '来源'],
            ['business_date', '业务日期'],
            ['verification_status', '验证状态'],
            ['scope', '口径'],
            ['missing_state', '缺失状态'],
            ['opportunity_key', '机会键'],
            ['rank', '机会顺序'],
            ['evidence_level', '证据等级'],
            ['relationship_type', '关系类型'],
            ['causality_claimed', '是否因果结论'],
            ['explanation', '说明'],
            ['evidence', '证据'],
        ];
        const rows = buildRevenueCockpitDownloadRows(model);
        return `\uFEFF${[
            headers.map(([, label]) => revenueCockpitCsvCell(label)).join(','),
            ...rows.map((row) => headers.map(([key]) => revenueCockpitCsvCell(row[key])).join(',')),
        ].join('\r\n')}`;
    };

    const buildRevenueCockpitDownloadPayload = (model = {}, fallbackHotelName = '') => {
        const rows = buildRevenueCockpitDownloadRows(model);
        if (!rows.length) {
            return { ok: false, rows, csv: '', fileName: '', message: '当前没有可见驾驶舱卡片可下载' };
        }
        const safeHotel = String(model.hotelName || fallbackHotelName || 'hotel')
            .replace(/[\\/:*?"<>|\s]+/g, '_')
            .slice(0, 40);
        return {
            ok: true,
            rows,
            csv: buildRevenueCockpitCsv(model),
            fileName: `经营驾驶舱_${safeHotel}_${model.businessDate || '日期缺失'}_${model.selectedPlatform || '平台缺失'}.csv`,
            message: `已按页面当前 ${rows.length} 张可见卡片顺序下载`,
        };
    };

    const buildRevenueCockpitQuestionDraft = (model = {}, hotelId = 0) => {
        const normalizedHotelId = Number(hotelId || 0);
        if (!model.canAskQuestion || !normalizedHotelId || !model.businessDate || !model.selectedPlatform) {
            return { ok: false, message: '当前酒店、平台或严格业务日期不完整，不能转为经营问题' };
        }
        const incompleteText = model.status === 'ready' ? '数据完整' : '存在数据缺口';
        return {
            ok: true,
            hotelId: String(normalizedHotelId),
            platform: String(model.selectedPlatform),
            businessDate: String(model.businessDate),
            decisionObject: 'channel',
            question: `${model.businessDate} ${model.selectedPlatformLabel}经营状态如何？当前${incompleteText}，最需要复核的异常、原因和人工动作是什么？`,
            notice: `已从经营驾驶舱带入：${model.selectedPlatformLabel} · ${model.businessDate}；尚未提交问题。`,
            message: '经营问题范围和问题草稿已带入，请人工确认后提交',
        };
    };

    const buildRevenueCockpitOverviewEndpoint = (hotelId, businessDate, platform) => {
        const params = new URLSearchParams({
            hotel_id: String(hotelId || ''),
            business_date: String(businessDate || ''),
            cockpit: '1',
        });
        if (String(platform || '') === 'all_ota') {
            params.set('platform', 'all_ota');
            params.set('enabled_channels', 'ctrip,meituan');
        } else if (platform) {
            params.set('platform', String(platform));
        }
        return `/revenue-ai/overview?${params.toString()}`;
    };

    const resolveRevenueCockpitOverviewResponse = (response = {}, expected = {}) => {
        if (Number(response.code || 0) !== 200) {
            return { ok: false, overview: null, message: response.message || '经营驾驶舱事实读取失败' };
        }
        const overview = response.data && typeof response.data === 'object' ? response.data : null;
        const factLayer = overview?.three_source_fact_layer || {};
        const strict = overview?.cockpit_strict_evidence || {};
        const platform = String(expected.platform || '').toLowerCase();
        const requiredPlatforms = platform === 'all_ota'
            ? ['ctrip', 'meituan']
            : (['ctrip', 'meituan'].includes(platform) ? [platform] : []);
        if (!overview
            || String(overview.business_date || '') !== String(expected.businessDate || '')
            || Number(overview.hotel_id || factLayer?.hotel?.system_hotel_id || 0) !== Number(expected.hotelId || 0)
            || String(factLayer.business_date || expected.businessDate || '') !== String(expected.businessDate || '')
            || String(strict.contract_version || '') !== 'revenue_cockpit_strict_evidence.v1'
            || Number(strict.hotel_id || 0) !== Number(expected.hotelId || 0)
            || String(strict.business_date || '') !== String(expected.businessDate || '')
            || Number(strict.tenant_id || 0) <= 0
            || requiredPlatforms.length === 0
            || requiredPlatforms.some((item) => {
                const source = factLayer?.sources?.[`${item}_ota`] || {};
                const provenance = source?.source || {};
                const strictPlatform = strict?.platforms?.[item] || {};
                return String(source.business_date || '') !== String(expected.businessDate || '')
                    || String(source.actual_business_date || '') !== String(expected.businessDate || '')
                    || String(provenance.platform || '') !== item
                    || String(provenance.table || '') !== 'online_daily_data'
                    || strictPlatform.source_strict_readback !== true
                    || !(Array.isArray(strictPlatform.accepted_row_ids)
                        && strictPlatform.accepted_row_ids.some((id) => Number(id) > 0));
            })
        ) {
            return { ok: false, overview: null, message: '经营驾驶舱回读的酒店、平台、业务日期或严格事实合同与当前筛选不一致' };
        }
        return { ok: true, overview, message: '' };
    };

    const resolveRevenueCockpitScopeResponse = (response = {}, hotelId = 0) => {
        if (Number(response.code || 0) !== 200) {
            return { ok: false, payload: null, message: response.message || '严格可用日期读取失败' };
        }
        const payload = response.data || {};
        if (String(payload.contract_version || '') !== 'operating_question_scope_options.v1'
            || Number(payload.hotel_id || 0) !== Number(hotelId || 0)
            || payload?.boundary?.silent_date_fallback !== false
        ) {
            return { ok: false, payload: null, message: '严格可用日期回读身份或日期回退边界不一致' };
        }
        return { ok: true, payload, message: '' };
    };

    const loadRevenueCockpitSnapshot = async ({
        hotelId = '',
        scopePayload = null,
        scopeHotelId = '',
        selectedPlatform = '',
        selectedDate = '',
        reloadScope = false,
        resetContext = false,
        resetDate = false,
        today = '',
        request,
        readScope,
        readOverview,
        isCurrent = () => true,
    } = {}) => {
        const scopeReader = readScope || ((id) => request(`/agent/operating-question-scopes?hotel_id=${encodeURIComponent(id)}`, {
            requestPolicy: { scope: 'action', force: true },
        }));
        const overviewReader = readOverview || (async (id, date, platform) => {
            const response = await request(buildRevenueCockpitOverviewEndpoint(id, date, platform), {
                requestPolicy: { scope: 'action', force: true },
            });
            const result = resolveRevenueCockpitOverviewResponse(response, {
                hotelId: id,
                businessDate: date,
                platform,
            });
            if (!result.ok) throw new Error(result.message);
            return result.overview;
        });
        let nextScope = scopePayload;
        if (reloadScope || String(scopeHotelId || '') !== String(hotelId || '') || !nextScope) {
            const scopeResult = resolveRevenueCockpitScopeResponse(await scopeReader(hotelId), hotelId);
            if (!isCurrent()) return { status: 'superseded' };
            if (!scopeResult.ok) throw new Error(scopeResult.message);
            nextScope = scopeResult.payload;
        }
        const selection = resolveRevenueCockpitScope({
            scopePayload: nextScope,
            requestedPlatform: resetContext ? '' : selectedPlatform,
            requestedDate: resetContext ? '' : selectedDate,
            resetDate,
            today,
        });
        if (!selection.selectedPlatform || !selection.selectedDate) {
            return {
                status: 'empty',
                scopePayload: nextScope,
                selection,
                overview: null,
                comparisonOverview: null,
                sameWeekdayOverview: null,
            };
        }
        const [overview, comparisonOverview, sameWeekdayOverview] = await Promise.all([
            overviewReader(hotelId, selection.selectedDate, selection.selectedPlatform),
            selection.previousDate
                ? overviewReader(hotelId, selection.previousDate, selection.selectedPlatform)
                : Promise.resolve(null),
            selection.sameWeekdayDate && selection.sameWeekdayDate !== selection.previousDate
                ? overviewReader(hotelId, selection.sameWeekdayDate, selection.selectedPlatform)
                : (selection.sameWeekdayDate && selection.sameWeekdayDate === selection.previousDate
                    ? Promise.resolve('__reuse_previous__')
                    : Promise.resolve(null)),
        ]);
        if (!isCurrent()) return { status: 'superseded' };
        return {
            status: overview ? 'ready' : 'empty',
            scopePayload: nextScope,
            selection,
            overview,
            comparisonOverview,
            sameWeekdayOverview: sameWeekdayOverview === '__reuse_previous__'
                ? comparisonOverview
                : sameWeekdayOverview,
        };
    };

    const revenueDecisionSnapshotParams = (model = {}, hotelId = 0, snapshotId = 0) => {
        const params = new URLSearchParams({
            hotel_id: String(Number(hotelId || model.hotelId || 0)),
            business_date: String(model.businessDate || ''),
            platform: String(model.selectedPlatform || ''),
        });
        if (Number(snapshotId || 0) > 0) params.set('snapshot_id', String(Number(snapshotId)));
        return params;
    };

    const resolveRevenueDecisionSnapshot = (payload = {}, expected = {}) => {
        const wrapper = payload && typeof payload === 'object' ? payload : {};
        if (wrapper.found === false) {
            return String(wrapper.persistence_status || '') === 'not_saved'
                ? { ok: true, status: 'not_saved', snapshot: null, message: '当前范围尚未保存收益决策快照' }
                : { ok: false, status: 'invalid', snapshot: null, message: '快照未保存状态缺少完整回读凭证' };
        }
        const snapshot = wrapper.snapshot && typeof wrapper.snapshot === 'object'
            ? wrapper.snapshot
            : wrapper;
        const model = snapshot.visible_model && typeof snapshot.visible_model === 'object'
            ? snapshot.visible_model
            : {};
        const digestsValid = ['visible_model_digest', 'evidence_digest', 'content_digest'].every(
            (key) => /^[a-f0-9]{64}$/.test(String(snapshot[key] || '')),
        );
        const opportunities = Array.isArray(model.opportunities) ? model.opportunities : [];
        if (Number(snapshot.id || 0) <= 0
            || String(snapshot.contract_version || '') !== 'revenue_decision_snapshot.v1'
            || String(snapshot.persistence_status || wrapper.persistence_status || '') !== 'readback_verified'
            || !digestsValid
            || String(model.contractVersion || '') !== 'revenue_daily_cockpit.v2'
            || Number(snapshot.system_hotel_id || 0) !== Number(expected.hotelId || model.hotelId || 0)
            || Number(model.hotelId || 0) !== Number(snapshot.system_hotel_id || 0)
            || String(snapshot.platform || '') !== String(expected.platform || model.selectedPlatform || '')
            || String(model.selectedPlatform || '') !== String(snapshot.platform || '')
            || String(snapshot.business_date || '') !== String(expected.businessDate || model.businessDate || '')
            || String(model.businessDate || '') !== String(snapshot.business_date || '')
            || opportunities.length !== 8
            || new Set(opportunities.map((item) => String(item?.opportunityKey || ''))).size !== 8
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '收益决策快照身份、摘要或八类机会合同不一致' };
        }
        if (Number(expected.snapshotId || 0) > 0
            && Number(snapshot.id) !== Number(expected.snapshotId)
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '收益决策快照 ID 回读不一致' };
        }
        return {
            ok: true,
            status: String(snapshot.evidence_identity_status || 'not_checked'),
            snapshot,
            message: String(snapshot.evidence_identity_status || '') === 'stale_current_evidence'
                ? `快照 #${snapshot.id} 已精确回读，但当前事实证据身份已变化`
                : `快照 #${snapshot.id} 已按同一内容与证据身份精确回读`,
        };
    };

    const saveRevenueDecisionSnapshotWithReadback = async ({ request, model = {}, hotelId = 0 } = {}) => {
        const normalizedHotelId = Number(hotelId || model.hotelId || 0);
        if (typeof request !== 'function'
            || !model.canSaveSnapshot
            || !normalizedHotelId
            || String(model.contractVersion || '') !== 'revenue_daily_cockpit.v2'
            || !model.businessDate
            || !['ctrip', 'meituan', 'all_ota'].includes(String(model.selectedPlatform || ''))
        ) {
            return { ok: false, snapshot: null, message: model.actionDisabledReason || '当前严格事实不足，不能保存收益决策快照' };
        }
        const saved = await request('/revenue-ai/cockpit/decision-snapshots', {
            method: 'POST',
            body: JSON.stringify({
                hotel_id: normalizedHotelId,
                business_date: model.businessDate,
                platform: model.selectedPlatform,
                visible_model: model,
            }),
        });
        if (Number(saved?.code || 0) !== 200) throw new Error(saved?.message || '收益决策快照保存失败');
        const savedResult = resolveRevenueDecisionSnapshot(saved.data, {
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
        });
        if (!savedResult.ok || !savedResult.snapshot) throw new Error(savedResult.message);
        const params = revenueDecisionSnapshotParams(model, normalizedHotelId, savedResult.snapshot.id);
        const readback = await request(`/revenue-ai/cockpit/decision-snapshots?${params.toString()}`, {
            requestPolicy: { scope: 'action', force: true },
        });
        if (Number(readback?.code || 0) !== 200) throw new Error(readback?.message || '收益决策快照精确回读失败');
        const exact = resolveRevenueDecisionSnapshot(readback.data, {
            snapshotId: savedResult.snapshot.id,
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
        });
        if (!exact.ok || !exact.snapshot
            || exact.snapshot.content_digest !== savedResult.snapshot.content_digest
            || exact.snapshot.evidence_digest !== savedResult.snapshot.evidence_digest
            || exact.snapshot.visible_model_digest !== savedResult.snapshot.visible_model_digest
        ) {
            throw new Error(exact.message || '收益决策快照保存与回读摘要不一致');
        }
        return { ok: true, status: exact.status, snapshot: exact.snapshot, message: exact.message };
    };

    const restoreRevenueDecisionSnapshotWithReadback = async ({ request, model = {}, hotelId = 0 } = {}) => {
        const normalizedHotelId = Number(hotelId || model.hotelId || 0);
        if (typeof request !== 'function'
            || !normalizedHotelId
            || !model.businessDate
            || !['ctrip', 'meituan', 'all_ota'].includes(String(model.selectedPlatform || ''))
        ) {
            return { ok: false, status: 'invalid', snapshot: null, message: '当前范围不完整，无法恢复收益决策快照' };
        }
        const params = revenueDecisionSnapshotParams(model, normalizedHotelId);
        const response = await request(`/revenue-ai/cockpit/decision-snapshots?${params.toString()}`, {
            requestPolicy: { scope: 'action', force: true },
        });
        if (Number(response?.code || 0) !== 200) throw new Error(response?.message || '收益决策快照恢复失败');
        return resolveRevenueDecisionSnapshot(response.data, {
            hotelId: normalizedHotelId,
            businessDate: model.businessDate,
            platform: model.selectedPlatform,
        });
    };

    const createRevenueOpportunityPendingApprovalWithReadback = async ({
        request,
        snapshot = null,
        opportunityKey = '',
    } = {}) => {
        const stored = snapshot && typeof snapshot === 'object' ? snapshot : {};
        const model = stored.visible_model && typeof stored.visible_model === 'object'
            ? stored.visible_model
            : {};
        const opportunity = (Array.isArray(model.opportunities) ? model.opportunities : []).find(
            (item) => String(item?.opportunityKey || '') === String(opportunityKey || ''),
        );
        if (typeof request !== 'function'
            || Number(stored.id || 0) <= 0
            || !opportunity
            || opportunity.canCreatePendingApproval !== true
            || String(stored.evidence_identity_status || '') !== 'matched_current'
        ) {
            return { ok: false, intent: null, message: '请先保存并精确回读当前证据身份，再选择一条可送审建议' };
        }
        const response = await request(`/revenue-ai/cockpit/decision-snapshots/${Number(stored.id)}/pending-approval`, {
            method: 'POST',
            body: JSON.stringify({
                hotel_id: Number(stored.system_hotel_id),
                business_date: String(stored.business_date),
                platform: String(stored.platform),
                opportunity_key: String(opportunity.opportunityKey),
            }),
        });
        if (Number(response?.code || 0) !== 200) throw new Error(response?.message || '经营机会送审失败');
        const payload = response.data || {};
        const savedResult = resolveRevenueCockpitPendingApprovalSave(payload);
        const recommendation = payload.opportunity || {};
        if (!savedResult.ok
            || savedResult.status !== 'pending_approval'
            || savedResult.taskCount !== 0
            || Number(payload?.snapshot?.id || 0) !== Number(stored.id)
            || String(payload?.snapshot?.content_digest || '') !== String(stored.content_digest || '')
            || String(recommendation.opportunity_key || '') !== String(opportunity.opportunityKey)
            || !/^[a-f0-9]{64}$/.test(String(recommendation.recommendation_digest || ''))
        ) {
            throw new Error(savedResult.message || '经营机会与收益决策快照绑定不一致');
        }
        const readback = await request(`/operation/execution-intents/${savedResult.intentId}`);
        if (Number(readback?.code || 0) !== 200) throw new Error(readback?.message || '待审批行动精确回读失败');
        const exact = resolveRevenueCockpitPendingApprovalReadback(readback.data, {
            intentId: savedResult.intentId,
            tenantId: Number(stored.tenant_id),
            hotelId: Number(stored.system_hotel_id),
            platform: String(stored.platform),
            businessDate: String(stored.business_date),
            objectType: 'operation_checklist',
            actionType: 'human_reviewed_operating_check',
            requirePending: true,
            status: 'pending_approval',
            taskCount: 0,
            sourceModule: 'revenue_cockpit_action',
            decisionContext: {
                snapshotId: Number(stored.id),
                snapshotDigest: String(stored.content_digest || ''),
                opportunityKey: String(opportunity.opportunityKey),
                opportunityDigest: String(recommendation.recommendation_digest || ''),
            },
        });
        const actionCard = exact.intent?.target_value?.action_card || exact.intent?.evidence?.action_card || {};
        if (!exact.ok
            || String(actionCard.contract_version || '') !== 'operation_action_card.v1'
            || !/^[a-f0-9]{64}$/.test(String(actionCard.content_digest || ''))
            || String(actionCard?.metric_contract?.target_type || '') !== 'observation'
            || String(actionCard?.action?.title || '') !== String(recommendation.title || '')
            || String(actionCard?.action?.description || '') !== String(recommendation.action_text || '')
        ) {
            throw new Error(exact.message || '经营机会没有精确回读同一受管行动生命周期');
        }
        return {
            ok: true,
            intent: exact.intent,
            opportunity,
            message: `${opportunity.title}已转为 pending_approval；未审批、未调价、未写 OTA`,
        };
    };

    const resolveRevenueCockpitPendingApprovalSave = (payload = {}) => {
        const savedIntent = payload?.execution_intent || {};
        const intentId = Number(savedIntent.id || 0);
        const tasks = savedIntent.tasks;
        const status = String(payload?.status || savedIntent.status || '');
        const taskCount = Number(payload?.execution_task_count ?? (Array.isArray(tasks) ? tasks.length : -1));
        if (!intentId
            || status !== 'pending_approval'
            || String(savedIntent.status || '') !== 'pending_approval'
            || String(payload?.persistence_status || '') !== 'readback_verified'
            || !Array.isArray(tasks)
            || !Number.isInteger(taskCount)
            || taskCount !== 0
            || tasks.length !== taskCount
            || payload?.execution_task_created !== false
            || payload?.external_action_triggered !== false
        ) {
            return { ok: false, intentId: 0, message: '待审批行动未返回“已回读且未执行”的完整凭证' };
        }
        return {
            ok: true,
            intentId,
            status,
            taskCount,
            reused: payload?.reused_existing_intent === true,
            message: `待审批行动 #${intentId} 已精确回读；未创建执行任务，也未写 OTA`,
        };
    };

    const resolveRevenueCockpitPendingApprovalReadback = (intent = {}, expected = {}) => {
        if (!Array.isArray(intent.tasks)) {
            return { ok: false, intent: null, message: '运营行动回读缺少真实任务数组' };
        }
        const tasks = intent.tasks;
        const allowedSources = ['revenue_cockpit_action', 'operating_question', 'operating_loop_approval'];
        const requirePending = expected.requirePending !== false;
        const expectedStatus = String(expected.status || '');
        const expectedTaskCount = Number(expected.taskCount);
        const actionCard = intent?.target_value?.action_card || intent?.evidence?.action_card || {};
        const trace = actionCard?.trace || {};
        const decisionContext = expected?.decisionContext || null;
        if (Number(intent.id || 0) !== Number(expected.intentId || 0)
            || Number(intent.tenant_id || 0) <= 0
            || (Number(expected.tenantId || 0) > 0
                && Number(intent.tenant_id || 0) !== Number(expected.tenantId || 0))
            || Number(intent.hotel_id || 0) !== Number(expected.hotelId || 0)
            || !['ctrip', 'meituan', 'all_ota'].includes(String(intent.platform || ''))
            || (expected.platform && String(intent.platform || '') !== String(expected.platform))
            || !allowedSources.includes(String(intent.source_module || ''))
            || (expected.sourceModule && String(intent.source_module || '') !== String(expected.sourceModule))
            || String(intent.object_type || '') !== String(expected.objectType || 'operation_checklist')
            || String(intent.action_type || '') !== String(expected.actionType || 'human_reviewed_operating_check')
            || String(intent.date_start || '') !== String(expected.businessDate || '')
            || String(intent.date_end || '') !== String(expected.businessDate || '')
            || !String(intent.status || '')
            || (requirePending && String(intent.status || '') !== 'pending_approval')
            || (requirePending && tasks.length !== 0)
            || (!requirePending && expectedStatus && String(intent.status || '') !== expectedStatus)
            || (!requirePending && Number.isInteger(expectedTaskCount) && expectedTaskCount >= 0
                && tasks.length !== expectedTaskCount)
            || !revenueCockpitTaskCardinalityIsValid(String(intent.status || ''), tasks)
            || String(actionCard.contract_version || '') !== 'operation_action_card.v1'
            || !/^[a-f0-9]{64}$/.test(String(actionCard.content_digest || ''))
            || (decisionContext && (
                Number(trace.decision_snapshot_id || 0) !== Number(decisionContext.snapshotId || 0)
                || String(trace.decision_snapshot_digest || '') !== String(decisionContext.snapshotDigest || '')
                || String(trace.opportunity_key || '') !== String(decisionContext.opportunityKey || '')
                || String(trace.opportunity_digest || '') !== String(decisionContext.opportunityDigest || '')
            ))
            || (Array.isArray(expected.taskSignatures)
                && JSON.stringify(tasks.map(task => ({
                    id: Number(task?.id || 0),
                    status: String(task?.status || ''),
                    result_status: String(task?.result_status || ''),
                })).sort((left, right) => left.id - right.id)) !== JSON.stringify(expected.taskSignatures))
        ) {
            return { ok: false, intent: null, message: '待审批行动保存与精确回读身份不一致' };
        }
        return { ok: true, intent, message: '' };
    };

    const revenueCockpitTaskCardinalityIsValid = (intentStatus = '', tasks = []) => {
        if (!Array.isArray(tasks) || tasks.some(task => !Number.isInteger(Number(task?.id || 0)) || Number(task.id) <= 0)) {
            return false;
        }
        const status = String(intentStatus || '').trim().toLowerCase();
        if (['draft', 'pending_approval'].includes(status)) return tasks.length === 0;
        if (status === 'approved') return tasks.length === 1;
        if (['cancelled', 'rejected'].includes(status)) return tasks.length <= 1;
        return false;
    };

    const restoreRevenueCockpitPendingApprovalWithReadback = async ({
        request,
        model = {},
        hotelId = 0,
        snapshot = null,
    } = {}) => {
        const normalizedHotelId = Number(hotelId || 0);
        const tenantId = Number(model.tenantId || 0);
        const businessDate = String(model.businessDate || '').trim();
        const platform = String(model.selectedPlatform || '').trim().toLowerCase();
        if (typeof request !== 'function'
            || !normalizedHotelId
            || !tenantId
            || !businessDate
            || !['ctrip', 'meituan', 'all_ota'].includes(platform)
        ) {
            return { ok: false, intent: null, message: '当前经营驾驶舱范围不完整，无法恢复已保存行动' };
        }
        const params = new URLSearchParams({
            hotel_id: String(normalizedHotelId),
            business_date: businessDate,
            platform,
        });
        const restored = await request(`/revenue-ai/cockpit/pending-approval?${params.toString()}`);
        if (Number(restored?.code || 0) !== 200) {
            throw new Error(restored?.message || '已保存运营行动恢复失败');
        }
        const payload = restored?.data || {};
        const scope = payload?.cockpit_scope || {};
        if (Number(scope.hotel_id || 0) !== normalizedHotelId
            || Number(scope.tenant_id || 0) !== tenantId
            || String(scope.business_date || '') !== businessDate
            || String(scope.platform || '') !== platform
        ) {
            throw new Error('已保存运营行动恢复范围与当前驾驶舱不一致');
        }
        if (payload?.found === false) {
            if (String(payload?.status || '') !== 'not_saved'
                || String(payload?.persistence_status || '') !== 'not_saved'
                || payload?.execution_intent !== null
                || (Array.isArray(payload?.execution_intents) && payload.execution_intents.length !== 0)
                || Number(payload?.execution_task_count || 0) !== 0
            ) {
                throw new Error('未保存状态缺少完整的只读回读凭证');
            }
            return {
                ok: true,
                status: 'not_saved',
                intent: null,
                message: '当前事实范围尚未保存运营行动',
            };
        }

        const scopedIntents = Array.isArray(payload?.execution_intents)
            ? payload.execution_intents
            : [payload?.execution_intent || {}];
        const scopedIntent = payload?.execution_intent || scopedIntents[0] || {};
        const intentId = Number(scopedIntent.id || 0);
        const taskCount = Number(payload?.execution_task_count);
        const intentCount = Number(payload?.execution_intent_count ?? scopedIntents.length);
        const scopedIntentIds = scopedIntents.map(intent => Number(intent?.id || 0));
        if (payload?.found !== true
            || String(payload?.persistence_status || '') !== 'readback_verified'
            || !intentId
            || !Array.isArray(scopedIntents)
            || scopedIntents.length < 1
            || !Number.isInteger(intentCount)
            || intentCount !== scopedIntents.length
            || scopedIntentIds.some(id => id <= 0)
            || new Set(scopedIntentIds).size !== scopedIntentIds.length
            || Number(scopedIntents[0]?.id || 0) !== intentId
            || !Number.isInteger(taskCount)
            || taskCount < 0
            || !Array.isArray(scopedIntent.tasks)
            || scopedIntent.tasks.length !== taskCount
            || String(scopedIntent.status || '') !== String(payload?.status || '')
            || !revenueCockpitTaskCardinalityIsValid(String(scopedIntent.status || ''), scopedIntent.tasks)
        ) {
            throw new Error('已保存运营行动未返回完整的范围回读凭证');
        }

        const exactIntents = await Promise.all(scopedIntents.map(async (candidate, index) => {
            if (!Array.isArray(candidate?.tasks) || !String(candidate?.status || '')) {
                throw new Error('已保存运营行动列表缺少真实状态或任务数组');
            }
            if (!revenueCockpitTaskCardinalityIsValid(String(candidate.status || ''), candidate.tasks)) {
                throw new Error('已保存运营行动任务基数与生命周期状态不一致');
            }
            const candidateId = Number(candidate.id || 0);
            const readback = await request(`/operation/execution-intents/${candidateId}`);
            if (Number(readback?.code || 0) !== 200) {
                throw new Error(readback?.message || '已保存运营行动精确回读失败');
            }
            const exactResult = resolveRevenueCockpitPendingApprovalReadback(readback.data, {
                intentId: candidateId,
                tenantId,
                hotelId: normalizedHotelId,
                platform,
                businessDate,
                objectType: 'operation_checklist',
                actionType: 'human_reviewed_operating_check',
                requirePending: false,
                status: String(candidate.status || ''),
                taskCount: candidate.tasks.length,
                taskSignatures: candidate.tasks.map(task => ({
                    id: Number(task?.id || 0),
                    status: String(task?.status || ''),
                    result_status: String(task?.result_status || ''),
                })).sort((left, right) => left.id - right.id),
            });
            if (!exactResult.ok) throw new Error(exactResult.message);
            const actionCard = exactResult.intent?.target_value?.action_card
                || exactResult.intent?.evidence?.action_card
                || {};
            const trace = actionCard?.trace || {};
            const opportunityKey = String(trace.opportunity_key || '').trim();
            if (opportunityKey) {
                const storedSnapshot = snapshot && typeof snapshot === 'object' ? snapshot : {};
                const storedModel = storedSnapshot.visible_model && typeof storedSnapshot.visible_model === 'object'
                    ? storedSnapshot.visible_model
                    : {};
                const opportunity = (Array.isArray(storedModel.opportunities) ? storedModel.opportunities : []).find(
                    item => String(item?.opportunityKey || '') === opportunityKey,
                );
                if (!opportunity
                    || String(storedSnapshot.evidence_identity_status || '') !== 'matched_current'
                    || Number(trace.decision_snapshot_id || 0) !== Number(storedSnapshot.id || 0)
                    || String(trace.decision_snapshot_digest || '') !== String(storedSnapshot.content_digest || '')
                    || !/^[a-f0-9]{64}$/.test(String(trace.opportunity_digest || ''))
                    || String(actionCard?.action?.title || '') !== String(opportunity.title || '')
                    || String(actionCard?.action?.description || '') !== String(opportunity.recommendedCheckAction || '')
                ) {
                    throw new Error('已保存经营机会与当前收益决策快照身份不一致');
                }
            }
            if (index === 0 && candidate.tasks.length !== taskCount) {
                throw new Error('主运营行动任务数与范围回读不一致');
            }
            return exactResult.intent;
        }));
        return {
            ok: true,
            status: 'readback_verified',
            intent: exactIntents[0],
            intents: exactIntents,
            message: `已恢复 ${exactIntents.length} 个运营行动及各自真实任务状态`,
        };
    };

    window.SUXI_REVENUE_AI_STATIC = Object.freeze({
        revenueAiStatusTone,
        revenueAiStatusClass,
        revenueAiStatusLabel,
        revenueAiTruthStatusLabel,
        revenueAiMetricTruthLines,
        revenueAiMetricTruthSummary,
        revenueAiReasonText,
        revenueAiScopeLabel,
        revenueAiDateBasisLabel,
        revenueAiChannelLabel,
        revenueAiSeverityLabel,
        revenueAiSeverityClass,
        revenueAiMetricDefinitions,
        buildRevenueAiOverviewQuery,
        buildRevenueAiOverviewEndpoint,
        resolveRevenueAiBusinessDate,
        resolveRevenueAiOverviewRequest,
        resolveRevenueAiOverviewResponse,
        buildRevenueAiBusinessClosure,
        buildRevenueAiMetricCards,
        buildRevenueAiGapRows,
        buildRevenueAiGapSummary,
        resolveRevenueAiGapTarget,
        resolveRevenueAiDecisionBasisNavigation,
        buildRevenueAiStatusRows,
        buildRevenueAiSignalRows,
        buildRevenueAiReviewQueueItems,
        buildRevenueAiResolutionPlanSummary,
        buildRevenueAiPricingGenerationPreflightSummary,
        buildRevenueAiPriceSuggestionGenerateResult,
        buildRevenueAiActionRows,
        buildRevenueAiEvidenceWorkbenchRows,
        buildRevenueAiEvidenceWorkbenchSummary,
        buildRevenueAiPricingGateRows,
        buildRevenueAiAgentActivitySummary,
        buildRevenueAiAgentActivityRows,
        buildRevenueAiExecutionSummary,
        buildRevenueAiExecutionRows,
        buildRevenueAiEffectReviewRows,
        revenueAiExecutionNeedsRoiEvidence,
        revenueAiExecutionResolvedActionKey,
        revenueAiExecutionTaskActionItem,
        resolveRevenueAiExecutionNavigation,
        resolveRevenueAiExecutionAction,
        revenueAiReviewActionKey,
        isRevenueAiReviewActionLoadingState,
        buildRevenueAiReviewActionLoadingState,
        normalizeRevenueAiApiPath,
        revenueAiReviewActionText,
        revenueAiReviewEndpoint,
        resolveRevenueAiReviewActionDraft,
        validateRevenueAiApprovedPrice,
        buildRevenueAiReviewConfirmText,
        buildRevenueAiReviewRequestBody,
        aiDailyReportActionSources,
        aiDailyReportEvidenceTarget,
        aiDailyReportActionIsInvestigationOnly,
        aiDailyReportActionExecutionReady,
        aiDailyReportActionBlockedText,
        aiDailyReportActionStatusText,
        aiDailyReportActionButtonText,
        buildAiDailyReportBlockingRows,
        summarizeAiDailyReportBlockingRows,
        buildAiDailyReportEvidenceRows,
        buildAiDailyFactGate,
        buildRevenueAiExecutionIntentOpenRow,
        resolveRevenueAiReviewNavigation,
        buildRevenueAiReviewNavigationState,
        normalizeMeituanCompetitionCircle,
        buildCompetitorMicroscope,
        resolveRevenueCockpitScope,
        buildRevenueCockpitModel,
        buildRevenueCockpitDownloadRows,
        buildRevenueCockpitCsv,
        buildRevenueCockpitDownloadPayload,
        buildRevenueCockpitQuestionDraft,
        buildRevenueCockpitOverviewEndpoint,
        resolveRevenueCockpitOverviewResponse,
        resolveRevenueCockpitScopeResponse,
        loadRevenueCockpitSnapshot,
        resolveRevenueDecisionSnapshot,
        saveRevenueDecisionSnapshotWithReadback,
        restoreRevenueDecisionSnapshotWithReadback,
        createRevenueOpportunityPendingApprovalWithReadback,
        resolveRevenueCockpitPendingApprovalSave,
        resolveRevenueCockpitPendingApprovalReadback,
        revenueCockpitTaskCardinalityIsValid,
        restoreRevenueCockpitPendingApprovalWithReadback,
    });
}());
