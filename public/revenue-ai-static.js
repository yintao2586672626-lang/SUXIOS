(function () {
    'use strict';

    const revenueAiStatusTone = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ok', 'success', 'ready', 'reviewed'].includes(value)) return 'ok';
        if (['partial', 'warning', 'stale', 'not_calculable', 'missing', 'unverified', 'pending_review', 'pending_approval', 'in_progress', 'evidence_needed', 'evidence_ready', 'review_needed', 'reviewed_no_roi'].includes(value)) return 'warning';
        if (['failed', 'unauthorized', 'blocked', 'error'].includes(value)) return 'blocked';
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
        ready: '可作为输入',
        reviewed: '已处理',
        unknown: '状态未知',
        empty: '无数据',
        missing: '缺失',
        unverified: '未验证',
        not_loaded: '未接入',
        not_calculable: '不可计算',
        blocked: '待补数据',
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

    const buildRevenueAiOverviewQuery = ({ businessDate = '', hotelId = '' } = {}) => {
        const params = new URLSearchParams();
        const dateText = String(businessDate || '').trim();
        const hotelIdText = String(hotelId || '').trim();
        if (dateText) params.set('business_date', dateText);
        if (hotelIdText) params.set('hotel_id', hotelIdText);
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

    const resolveRevenueAiOverviewRequest = ({ hasToken = false, currentPage = '', businessDate = '', hotelId = '' } = {}) => {
        if (hasToken !== true) {
            return { shouldLoad: false, endpoint: '', reason: 'token_missing' };
        }
        if (!['compass', 'agent-center'].includes(String(currentPage || ''))) {
            return { shouldLoad: false, endpoint: '', reason: 'not_revenue_ai_surface' };
        }
        return {
            shouldLoad: true,
            endpoint: buildRevenueAiOverviewEndpoint({ businessDate, hotelId }),
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

    const revenueAiClosureNextAction = ({ calculationAllowed, missingRows, anomalyRows, operationStatus, investmentAllowed }) => {
        if (!calculationAllowed) return '先补齐已验证 OTA 数据，当前不输出收益计算结论。';
        if (anomalyRows.length > 0) return '先复核异常判断，再进入人工审核和执行证据闭环。';
        if (missingRows.length > 0) return '收益计算可用，但缺失项需保留可见并继续补齐。';
        if (!['ok', 'ready', 'reviewed'].includes(String(operationStatus || ''))) return '可进入 AI 建议输入，下一步补人工执行和效果复盘证据。';
        return investmentAllowed ? '可作为受控输入继续流转。' : '可进入运营闭环；全酒店投决仍需独立口径证明。';
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
        const investmentDecision = decisionUse.investment_decision || {};
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
        const investmentAllowed = investmentDecision.allowed === true && closure.whole_hotel_guard?.allowed === true;
        const summaryChips = [
            revenueAiClosureSummaryChip('calculation', '收益计算', calculationAllowed ? '允许' : '阻断', calculationAllowed ? 'ok' : 'blocked', revenueAiReasonText(revenueUse.status || (calculationAllowed ? '' : 'blocked_by_data_credibility'))),
            revenueAiClosureSummaryChip('missing', '缺失项', `${missingRows.length}项`, missingRows.length > 0 ? 'warning' : 'ok', missingRows.length > 0 ? '继续补齐缺失项' : '关键缺失项未返回'),
            revenueAiClosureSummaryChip('anomaly', '异常判断', `${anomalyRows.length}项`, anomalyRows.length > 0 ? 'warning' : 'ok', anomalyRows.length > 0 ? '需人工复核' : '未命中异常'),
            revenueAiClosureSummaryChip('investment', '投决边界', investmentAllowed ? '可用' : '阻断', investmentAllowed ? 'ready' : 'blocked', revenueAiReasonText(closure.whole_hotel_guard?.reason || investmentDecision.status || 'whole_hotel_scope_not_proved')),
        ];

        return {
            status: closureStatus,
            statusLabel: revenueAiStatusLabel(closureStatus),
            className: revenueAiStatusClass(closureStatus),
            scopeText: revenueAiScopeLabel(closure.scope || overview.scope || 'ota'),
            summary: closure.scope_statement || '仅基于已验证 OTA 渠道数据，不代表全酒店经营口径。',
            calculationAllowed,
            summaryChips,
            nextAction: revenueAiClosureNextAction({ calculationAllowed, missingRows, anomalyRows, operationStatus, investmentAllowed }),
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
                {
                    key: 'investment-boundary',
                    stage: '投决边界',
                    title: '全酒店口径阻断',
                    primary: investmentAllowed ? '投决输入可用' : '不进入全酒店投决',
                    secondary: revenueAiReasonText(closure.whole_hotel_guard?.reason || investmentDecision.status || 'whole_hotel_scope_not_proved'),
                    statusLabel: revenueAiStatusLabel(investmentAllowed ? 'ready' : 'blocked'),
                    className: revenueAiStatusClass(investmentAllowed ? 'ready' : 'blocked'),
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
                        canOpenTarget: Boolean(item.target_page),
                    }));
            const reviewQueueItems = buildRevenueAiReviewQueueItems(reviewQueue);
            const approvedExecutionPendingCount = reviewQueueItems.filter(item => item.canCreateExecutionIntent).length;
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
            };
        });
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
        buildRevenueAiActionRows,
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
        buildRevenueAiExecutionIntentOpenRow,
        resolveRevenueAiReviewNavigation,
        buildRevenueAiReviewNavigationState,
    });
}());
