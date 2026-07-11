(function () {
    'use strict';

    const revenueAiStatusTone = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ok', 'success', 'ready', 'reviewed', 'ready_for_manual_generation', 'pricing_generation_candidates_ready'].includes(value)) return 'ok';
        if (['partial', 'warning', 'stale', 'not_calculable', 'missing', 'unverified', 'skipped_by_operator_policy', 'pending_review', 'pending_review_exists', 'pending_approval', 'in_progress', 'evidence_needed', 'evidence_ready', 'review_needed', 'reviewed_no_roi', 'investment_precheck_waiting_decision_record', 'waiting_decision_record_readiness', 'operation_intake_waiting_human_approval', 'operation_intake_ready_for_human_create', 'operation_intake_in_operation_flow', 'operation_intake_waiting_operation_progress'].includes(value)) return 'warning';
        if (['failed', 'unauthorized', 'blocked', 'error', 'investment_precheck_blocked_by_operation_roi', 'blocked_by_operation_roi', 'blocked_by_p0_ota_gate', 'operation_intake_blocked_by_manual_review', 'operation_intake_blocked_by_operation_execution'].includes(value)) return 'blocked';
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
        skipped_by_operator_policy: '已暂时跳过',
        not_loaded: '未接入',
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
        available_room_nights_missing: '暂缺可信全酒店可售房数据。',
        adr_denominator_zero: 'OTA 间夜为 0，ADR 不可计算。',
        competitor_price_fields_missing: '暂缺竞对价格字段。',
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
        floor_price_missing: '暂缺最低保护价。',
        missing_pricing_inputs_skipped_by_operator_policy: '已按人工策略暂时跳过抓不到的房型、保护价、需求预测和竞对样本缺口。',
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
    }[String(scope || '')] || '口径待确认');

    const revenueAiDateBasisLabel = (dateBasis) => ({
        data_date: 'data_date',
        stay_date: 'stay_date',
        booking_date: 'booking_date',
        settlement_date: 'settlement_date',
        create_time: 'create_time',
        forecast_date: 'forecast_date',
        calendar_date: 'calendar_date',
        'operation_execution_intents.date_start/date_end': '执行意图日期',
    }[String(dateBasis || '')] || 'date_basis待确认');

    const revenueAiChannelLabel = (channel) => ({
        ctrip: '携程',
        meituan: '美团',
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
        { key: 'ota_room_revenue', label: '昨日OTA房费收入' },
        { key: 'ota_room_nights', label: '昨日OTA间夜' },
        { key: 'ota_adr', label: 'OTA ADR' },
        { key: 'ota_contribution_revpar', label: 'OTA贡献RevPAR' },
        { key: 'data_completeness', label: '数据完整度' },
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

    const resolveRevenueAiBusinessDate = ({ overview = null, now = new Date() } = {}) => {
        const overviewDate = String(overview?.business_date || '').trim();
        if (overviewDate) return overviewDate;
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

    const revenueAiClosureIssueRows = (items = [], fallbackType = 'missing') => {
        const rows = Array.isArray(items) ? items : [];
        return rows.slice(0, 6).map((item, index) => {
            const code = String(item?.code || item?.reason || `${fallbackType}_${index}`);
            const affected = Array.isArray(item?.affected_metrics) ? item.affected_metrics.filter(Boolean).join(' / ') : '';
            return {
                key: `${fallbackType}_${code}_${index}`,
                code,
                label: fallbackType === 'anomaly' ? '异常判断' : '缺失项说明',
                detail: item?.message || revenueAiReasonText(code.split(':').pop()),
                affected,
                severity: item?.severity || (fallbackType === 'anomaly' ? 'medium' : 'low'),
            };
        });
    };

    const revenueAiClosureMetricChip = (metric = {}, label = '', key = '') => {
        const status = metric?.status || 'unknown';
        const reasons = Array.isArray(metric?.failure_reasons) ? metric.failure_reasons.filter(Boolean) : [];
        const reason = metric?.reason || reasons[0] || (status !== 'ok' ? status : '');
        return {
            key: key || metric?.key || label,
            label,
            value: revenueAiClosureValueText(metric),
            status,
            statusLabel: revenueAiStatusLabel(status),
            className: revenueAiStatusClass(status),
            reasonText: reason ? revenueAiReasonText(reason) : '',
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
                    primary: overviewLoading ? '加载中' : '未接入',
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
        const revenueMetric = revenueAiClosureMetric(closure, ['sections', 'revenue']);
        const orderMetric = revenueAiClosureMetric(closure, ['sections', 'orders']);
        const roomNightMetric = revenueAiClosureMetric(closure, ['sections', 'room_nights']);
        const adrMetric = revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'adr']);
        const flowMetric = revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'flow_rate']);
        const submitMetric = revenueAiClosureMetric(closure, ['sections', 'adr_conversion', 'metrics', 'submit_rate']);
        const missingRows = revenueAiClosureIssueRows(closure.missing_items?.items, 'missing');
        const anomalyRows = revenueAiClosureIssueRows(closure.anomaly_judgment?.items, 'anomaly');
        const execution = overview.execution_summary || {};
        const operationStatus = execution.status || 'not_loaded';
        const aiDecision = decisionUse.ai_decision_support || {};
        const closureStatus = closure.status || overview.data_status || 'unknown';
        const calculationAllowed = closure.calculation_allowed === true;
        const metricChips = [
            revenueAiClosureMetricChip(revenueMetric, '收入', 'revenue'),
            revenueAiClosureMetricChip(orderMetric, '订单', 'orders'),
            revenueAiClosureMetricChip(roomNightMetric, '间夜', 'room_nights'),
            revenueAiClosureMetricChip(adrMetric, 'ADR', 'adr'),
            revenueAiClosureMetricChip(flowMetric, '流量转化', 'flow_rate'),
            revenueAiClosureMetricChip(submitMetric, '提交转化', 'submit_rate'),
        ];
        const revenueAnalysisStatus = revenueAiClosureGroupStatus(metricChips);
        const summaryChips = [
            revenueAiClosureSummaryChip('calculation', '收益计算', calculationAllowed ? '允许' : '阻断', calculationAllowed ? 'ok' : 'blocked', revenueAiReasonText(revenueUse.status || (calculationAllowed ? '' : 'blocked_by_data_credibility'))),
            revenueAiClosureSummaryChip('missing', '缺失项', `${missingRows.length}项`, missingRows.length > 0 ? 'warning' : 'ok', missingRows.length > 0 ? '继续补齐缺失项' : '关键缺失项未返回'),
            revenueAiClosureSummaryChip('anomaly', '异常判断', `${anomalyRows.length}项`, anomalyRows.length > 0 ? 'warning' : 'ok', anomalyRows.length > 0 ? '需人工复核' : '未命中异常'),
        ];

        return {
            status: closureStatus,
            statusLabel: revenueAiStatusLabel(closureStatus),
            className: revenueAiStatusClass(closureStatus),
            scopeText: revenueAiScopeLabel(closure.scope || overview.scope || 'ota'),
            summary: closure.scope_statement || '仅基于已验证 OTA 渠道数据，不代表全酒店经营口径。',
            calculationAllowed,
            summaryChips,
            nextAction: revenueAiClosureNextAction({ calculationAllowed, missingRows, anomalyRows, operationStatus }),
            rows: [
                {
                    key: 'ota-data',
                    stage: 'OTA数据',
                    title: '已验证数据准入',
                    primary: calculationAllowed ? '可进入收益计算' : '阻断收益计算',
                    secondary: closure.scope_statement || '只读取 OTA 渠道事实和 metric_trust。',
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
                    primary: aiDecision.allowed === true ? '可作为 AI 输入' : 'AI 输入阻断',
                    secondary: revenueAiReasonText(aiDecision.status || (aiDecision.allowed === true ? '' : 'blocked_by_data_credibility')),
                    statusLabel: revenueAiStatusLabel(aiDecision.allowed === true ? 'ready' : 'blocked'),
                    className: revenueAiStatusClass(aiDecision.allowed === true ? 'ready' : 'blocked'),
                },
                {
                    key: 'operation-execution',
                    stage: '运营执行',
                    title: '人工执行闭环',
                    primary: execution.display || revenueAiStatusLabel(operationStatus),
                    secondary: execution.reason ? revenueAiReasonText(execution.reason) : '建议需人工审核、执行证据和效果复盘。',
                    statusLabel: revenueAiStatusLabel(operationStatus),
                    className: revenueAiStatusClass(operationStatus),
                },
            ],
            missingRows,
            anomalyRows,
        };
    };

    const buildRevenueAiMetricCards = ({ overview = null, overviewError = '' } = {}) => {
        const metrics = overview?.metrics || {};
        return revenueAiMetricDefinitions.map((definition) => {
            const metric = metrics[definition.key] || {};
            const reason = overviewError ? 'overview_request_failed' : (metric.reason || (overview ? '' : 'overview_not_loaded'));
            const status = overviewError ? 'failed' : (metric.status || (overview ? 'unknown' : 'not_loaded'));
            return {
                key: definition.key,
                label: metric.label || definition.label,
                display: metricDisplayText(metric),
                statusLabel: revenueAiStatusLabel(status),
                className: revenueAiStatusClass(status),
                reasonText: metric.display_reason || revenueAiReasonText(reason),
                scopeLabel: revenueAiScopeLabel(metric.scope || overview?.scope || 'ota'),
                dateBasisLabel: revenueAiDateBasisLabel(metric.date_basis || overview?.date_basis || 'data_date'),
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

        const missing = Array.isArray(overview.missing_datasets) ? overview.missing_datasets : [];
        const issues = Array.isArray(overview.quality_issues) ? overview.quality_issues : [];
        return [...missing, ...issues].slice(0, 8).map((row, index) => {
            const channel = row.target_platform || row.channel || '';
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
                target_platform: channel,
                raw: row,
            };
        });
    };

    const buildRevenueAiGapSummary = (rows = []) => ({
        total: rows.length,
        high: rows.filter((row) => row.severityLabel === revenueAiSeverityLabel('high')).length,
    });

    const resolveRevenueAiGapTarget = (row = {}) => ({
        targetPage: row.target_page || row.targetPage || 'online-data',
        targetTab: row.target_tab || row.targetTab || 'data-health',
        targetPlatform: row.target_platform || row.targetPlatform || '',
    });

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
            : (overview?.data_status || (overviewLoading ? 'not_loaded' : overviewStatus || 'unknown'));
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
                status: overview?.scope === 'hotel' ? '全酒店' : '非全酒店',
                detail: '只把已验证 OTA 口径作为首页判断输入，不包装成全酒店经营结论。',
                className: revenueAiStatusClass('warning'),
            },
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
                value: completeness?.display || readiness.summaryText || '--',
                status: completeness?.status ? revenueAiStatusLabel(completeness.status) : `${Number(readiness.percent || 0)}%`,
                detail: completeness?.reason ? revenueAiReasonText(completeness.reason) : (readiness.missingText || '等待核心数据状态生成。'),
                className: revenueAiStatusClass(completeness?.status || (Number(readiness.percent || 0) >= 100 ? 'ok' : 'warning')),
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
            const canApprove = item.can_review === true && manualActions.includes('approve') && !!allowedEndpoints.review;
            const canApproveWithChanges = item.can_review === true && manualActions.includes('approve_with_changes') && !!allowedEndpoints.review;
            const canReject = item.can_review === true && manualActions.includes('reject') && !!allowedEndpoints.review;
            const canCreateExecutionIntent = manualActions.includes('create_execution_intent') && !!allowedEndpoints.execution_intent;
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
                manualReviewRequired: item.manual_review_required !== false,
                autoWriteOta: item.auto_write_ota === true,
                canReview: item.can_review === true,
                canApprove,
                canApproveWithChanges,
                canReject,
                canCreateExecutionIntent,
                actionEntry,
                actionLabel: canCreateExecutionIntent ? '转执行' : (canApprove || canApproveWithChanges || canReject ? '审核' : (actionEntry.label || '')),
                actionHelpText: canCreateExecutionIntent ? '转为运营执行意图，仍需人工执行和复盘' : (canApprove || canApproveWithChanges || canReject ? '首页人工审核，可修改后批准；不写 OTA' : '查看建议状态'),
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
        skipped_by_operator_policy: '已暂时跳过',
        blocked: '生成受阻',
        failed: '预检失败',
        not_loaded: '未加载',
    }[String(status || '').toLowerCase()] || revenueAiStatusLabel(status || 'unknown'));

    const revenueAiPricingGenerationReasonText = (reason) => ({
        price_suggestion_generation_not_loaded: '调价建议生成预检尚未加载。',
        pricing_generation_hotel_scope_missing: '调价建议生成缺少目标系统酒店范围。',
        room_types_empty: '携程目标酒店暂无启用房型，不能生成待审调价建议。',
        missing_pricing_inputs_skipped_by_operator_policy: '已按人工策略暂时跳过抓不到的房型、保护价、需求预测和竞对样本缺口。',
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

        const status = String(preflight.status || 'unknown');
        const reason = String(preflight.reason || '');
        const targetFilter = preflight.target_filter && typeof preflight.target_filter === 'object'
            ? preflight.target_filter
            : {};
        const rawRequiredInputs = Array.isArray(preflight.required_inputs) ? preflight.required_inputs : [];
        const requiredInputs = rawRequiredInputs
            .map((item) => ({
                code: String(item?.code || ''),
                source: String(item?.source || ''),
                status: String(item?.status || ''),
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
            reasonText: String(preflight.detail || '') || revenueAiPricingGenerationReasonText(reason),
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
        const message = isCreated
            ? `已生成 ${createdCount} 条待审建议；仍需人工审核，不写 OTA。`
            : (nextAction || reasonText || String(payload.message || '定价建议生成受阻'));
        const targetFilter = data.target_filter && typeof data.target_filter === 'object'
            ? data.target_filter
            : {};
        const rawSkippedItems = Array.isArray(data.skipped) ? data.skipped : [];
        const skippedItems = rawSkippedItems
            .map((item, index) => {
                const dataGaps = Array.isArray(item?.data_gaps) ? item.data_gaps.map(String).filter(Boolean) : [];
                const reviewChecklist = Array.isArray(item?.review_checklist) ? item.review_checklist.map(String).filter(Boolean) : [];
                return {
                    key: `${Number(item?.room_type_id || 0) || 'room'}-${String(item?.reason || 'skipped')}-${index}`,
                    roomTypeId: Number(item?.room_type_id || 0),
                    roomTypeName: String(item?.room_type_name || item?.room_type?.name || '未命名房型'),
                    reason: String(item?.reason || 'not_created'),
                    primarySignalCount: Number(item?.primary_signal_count || 0),
                    priceChangeRate: Number(item?.price_change_rate || 0),
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
            createdCount,
            skippedCount,
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
        execution_intent: '转为运营执行意图',
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
            return '确认转为运营执行意图？该动作不会写入携程/美团价格，仍需人工执行和复盘。';
        }
        if (normalizedAction === 'approve_with_changes') {
            return `确认以 ${approvedPrice} 元修改后批准？该动作只更新本地审核状态，不写入携程/美团价格。`;
        }
        return `确认${actionText}？该动作只更新本地审核状态，不写入携程/美团价格。`;
    };

    const buildRevenueAiReviewRequestBody = ({ action = '', item = {}, approvedPrice = null, reviewRemark = '' } = {}) => {
        const normalizedAction = String(action || '').trim();
        if (normalizedAction === 'execution_intent') {
            return { source: 'revenue_ai_homepage', expected_metric: 'orders' };
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

    const aiDailyReportActionBlockedText = (action) => {
        if (!action) return '';
        if (aiDailyReportActionIsInvestigationOnly(action)) {
            return '调查项不可转执行单，仅用于查看证据和判断是否需要进一步分析。';
        }
        if (action.can_create_execution_intent === false) {
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
        if (action?.can_create_execution_intent === false) return action.blocked_reason || '不可转执行单';
        return '可转执行单';
    };

    const aiDailyReportActionButtonText = (action) => {
        if (aiDailyReportActionIsInvestigationOnly(action)) return '查看证据';
        if (action?.execution_intent_id) return '已转单';
        if (action?.can_create_execution_intent === false) return '处理缺口';
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
        const intentId = Number(data.target_id || intent.id || 0);
        return {
            canOpenExecution: intentId > 0,
            targetPage: data.target_page || 'ops-track',
            targetAction: data.target_action || 'approve_intent',
            targetId: intentId,
            targetKind: data.target_kind || 'intent',
            intentId,
            hotelId: Number(data.hotel_id || intent.hotel_id || item.hotelId || item.hotel_id || 0),
            actionLabel: data.execution_intent_existing ? '查看执行意图' : '审批执行意图',
            nextActionKey: data.target_action || 'approve_intent',
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

    window.SUXI_REVENUE_AI_STATIC = Object.freeze({
        revenueAiStatusTone,
        revenueAiStatusClass,
        revenueAiStatusLabel,
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
    });
}());
