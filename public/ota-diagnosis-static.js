window.SUXI_OTA_DIAGNOSIS_STATIC = (() => {
    const normalizeOtaDiagnosisList = (value) => {
        if (Array.isArray(value)) {
            return value.map(item => String(item || '').trim()).filter(Boolean);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return [value.trim()];
        }
        return ['暂无'];
    };

    const otaDiagnosisPlatformText = (platform) => String(platform || '') === 'meituan' ? '美团' : '携程';

    const otaDiagnosisDateRangeText = ({ result = null, form = {} } = {}) => {
        const resultRange = result?.date_range || {};
        const start = resultRange.start_date || form.start_date || '-';
        const end = resultRange.end_date || form.end_date || '-';
        const text = start === end ? start : `${start} 至 ${end}`;
        return result?.data_summary?.used_latest_available_data ? `${text}（最近已抓取数据）` : text;
    };

    const otaDiagnosisPriorityClass = (priority) => {
        const value = String(priority || '').toLowerCase();
        if (value === 'high') return 'bg-red-50 text-red-700 border-red-200';
        if (value === 'medium') return 'bg-orange-50 text-orange-700 border-orange-200';
        if (value === 'low') return 'bg-green-50 text-green-700 border-green-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const otaDiagnosisPriorityText = (priority) => {
        const value = String(priority || '').toLowerCase();
        if (value === 'high') return '高优先级';
        if (value === 'medium') return '中优先级';
        if (value === 'low') return '低优先级';
        return '未分级';
    };

    const formatOtaMetricValue = (value, formatNumber = null) => {
        if (value === null || value === undefined || value === '') return '-';
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return String(value);
        return typeof formatNumber === 'function' ? formatNumber(numeric) : String(numeric);
    };

    const buildOtaDiagnosisMetricCards = ({ result = null, formatNumber = null } = {}) => {
        const metrics = result?.metrics || {};
        const summary = result?.data_summary || {};
        return [
            { label: '数据记录', value: formatOtaMetricValue(metrics.record_count || 0, formatNumber), hint: '本次诊断样本量', icon: 'fas fa-database' },
            { label: '订单', value: formatOtaMetricValue(metrics.book_order_num || 0, formatNumber), hint: '周期内订单量', icon: 'fas fa-receipt' },
            { label: '曝光', value: formatOtaMetricValue(metrics.list_exposure || 0, formatNumber), hint: '列表曝光量', icon: 'fas fa-eye' },
            { label: '最近同步', value: summary.last_sync_time || '-', hint: '线上数据更新时间', icon: 'fas fa-sync-alt' },
        ];
    };

    const buildOtaDiagnosisResultSections = (result = {}) => {
        const diagnosis = result.diagnosis || {};
        if (Array.isArray(result.diagnosis_sections) && result.diagnosis_sections.length > 0) {
            const iconMap = {
                data_overview: ['fas fa-exclamation-circle', 'text-orange-500'],
                abnormal_metrics: ['fas fa-search', 'text-blue-500'],
                traffic: ['fas fa-chart-line', 'text-indigo-500'],
                conversion: ['fas fa-filter', 'text-cyan-500'],
                price_competitor: ['fas fa-tags', 'text-purple-500'],
                advertising_efficiency: ['fas fa-bullhorn', 'text-amber-500'],
                service_quality: ['fas fa-concierge-bell', 'text-teal-500'],
                actions: ['fas fa-check-circle', 'text-green-500'],
                data_gaps: ['fas fa-clipboard-check', 'text-slate-500'],
            };
            return result.diagnosis_sections.map(section => {
                const [icon, iconClass] = iconMap[section.key] || ['fas fa-list-check', 'text-slate-500'];
                return {
                    title: section.title || section.key || '诊断分组',
                    icon,
                    iconClass,
                    items: normalizeOtaDiagnosisList(section.items),
                };
            });
        }
        return [
            { title: '数据概览', icon: 'fas fa-exclamation-circle', iconClass: 'text-orange-500', items: normalizeOtaDiagnosisList(diagnosis.data_overview || result.main_problems) },
            { title: '异常指标', icon: 'fas fa-search', iconClass: 'text-blue-500', items: normalizeOtaDiagnosisList(diagnosis.abnormal_metrics || result.possible_reasons) },
            { title: '流量问题', icon: 'fas fa-chart-line', iconClass: 'text-indigo-500', items: normalizeOtaDiagnosisList(diagnosis.traffic_analysis || diagnosis.exposure_analysis) },
            { title: '转化问题', icon: 'fas fa-filter', iconClass: 'text-cyan-500', items: normalizeOtaDiagnosisList([diagnosis.visit_conversion_analysis, diagnosis.order_conversion_analysis].filter(Boolean)) },
            { title: '价格/竞对问题', icon: 'fas fa-tags', iconClass: 'text-purple-500', items: normalizeOtaDiagnosisList([diagnosis.price_analysis, diagnosis.competitor_analysis].filter(Boolean)) },
            { title: '广告效率', icon: 'fas fa-bullhorn', iconClass: 'text-amber-500', items: normalizeOtaDiagnosisList(diagnosis.advertising_analysis) },
            { title: '服务质量', icon: 'fas fa-concierge-bell', iconClass: 'text-teal-500', items: normalizeOtaDiagnosisList(diagnosis.service_quality_analysis) },
            { title: '运营建议', icon: 'fas fa-check-circle', iconClass: 'text-green-500', items: normalizeOtaDiagnosisList(diagnosis.actions || result.recommended_actions) },
            { title: '数据缺失提示', icon: 'fas fa-clipboard-check', iconClass: 'text-slate-500', items: normalizeOtaDiagnosisList(result.missing_sections || result.data_anomalies_needing_confirmation) },
        ];
    };

    const normalizeOtaDiagnosisArray = (value) => Array.isArray(value) ? value.filter(Boolean) : [];

    const otaDiagnosisDecisionStatusText = (status) => {
        const value = String(status || '').toLowerCase();
        const labels = {
            ready: '证据充分',
            pending: '待确认',
            pending_human_confirmation: '待人工确认',
            partial_ready: '部分可执行',
            blocked: '证据阻断',
            blocked_by_missing_ota_data: '缺OTA数据',
            blocked_by_non_target_date_data: '非目标日数据',
            blocked_by_insufficient_evidence: '证据不足',
            blocked_by_data_gap: '数据缺口',
            blocked_by_operation_closure: '待运营复盘',
            not_required: '无需确认',
            confirmed: '已确认',
            rejected: '已驳回',
            high: '高置信',
            medium: '中置信',
            low: '低置信',
            unknown: '待核验',
        };
        return labels[value] || status || '-';
    };

    const otaDiagnosisDecisionStatusClass = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ready', 'confirmed'].includes(value)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['pending', 'pending_human_confirmation', 'partial_ready'].includes(value)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (value.startsWith('blocked') || ['rejected'].includes(value)) return 'bg-red-50 text-red-700 border-red-100';
        if (['not_required'].includes(value)) return 'bg-slate-50 text-slate-600 border-slate-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const otaDiagnosisDecisionClosure = (result = {}) => {
        const closure = result?.decision_closure || result?.evidence_report?.decision_closure || {};
        return closure && typeof closure === 'object' ? closure : {};
    };

    const otaDiagnosisActionItems = (result = {}) => {
        const closureItems = otaDiagnosisDecisionClosure(result)?.suggested_actions?.items;
        if (Array.isArray(closureItems)) return closureItems.filter(item => item && typeof item === 'object');
        return normalizeOtaDiagnosisArray(result?.action_items).filter(item => item && typeof item === 'object');
    };

    const otaDiagnosisReadyActionCount = (result = {}) => {
        const closureCount = Number(otaDiagnosisDecisionClosure(result)?.suggested_actions?.ready_count);
        if (Number.isFinite(closureCount) && closureCount > 0) return closureCount;
        return otaDiagnosisActionItems(result).filter(item => item.execution_ready === true).length;
    };

    const otaDiagnosisBlockedActionCount = (result = {}) => {
        const closureCount = Number(otaDiagnosisDecisionClosure(result)?.suggested_actions?.blocked_count);
        if (Number.isFinite(closureCount) && closureCount > 0) return closureCount;
        return otaDiagnosisActionItems(result).filter(item => String(item.status || '').startsWith('blocked') || item.execution_ready === false).length;
    };

    const buildOtaDiagnosisDecisionClosureCards = (result = {}) => {
        const closure = otaDiagnosisDecisionClosure(result);
        const evidence = closure.data_evidence_input || {};
        const conclusion = closure.diagnostic_conclusion || {};
        const suggested = closure.suggested_actions || {};
        const blocked = closure.blocked_state || {};
        const human = closure.human_confirmation || {};
        const evidenceRefs = normalizeOtaDiagnosisArray(evidence.evidence_refs);
        const dataGaps = normalizeOtaDiagnosisArray(evidence.data_gaps || result.data_gaps);
        const readyCount = otaDiagnosisReadyActionCount(result);
        const blockedCount = otaDiagnosisBlockedActionCount(result);
        const enough = evidence.enough_for_executable_actions === true || readyCount > 0;

        return [
            {
                key: 'data_evidence_input',
                title: '数据证据输入',
                status: enough ? 'ready' : 'blocked',
                value: `${evidenceRefs.length}项证据`,
                detail: dataGaps.length ? `缺口 ${dataGaps.length} 项` : (evidence.source_policy || 'database_only'),
            },
            {
                key: 'diagnostic_conclusion',
                title: '诊断结论',
                status: conclusion.summary ? 'ready' : 'unknown',
                value: conclusion.confidence_level ? otaDiagnosisDecisionStatusText(conclusion.confidence_level) : '已生成',
                detail: conclusion.summary || result?.core_conclusion || result?.diagnosis?.summary || '-',
            },
            {
                key: 'suggested_actions',
                title: '建议动作',
                status: readyCount > 0 ? 'pending_human_confirmation' : 'blocked',
                value: `${readyCount}可执行 / ${blockedCount}阻断`,
                detail: `${normalizeOtaDiagnosisArray(suggested.items || result.action_items).length} 项动作`,
            },
            {
                key: 'blocked_state',
                title: 'blocked 状态',
                status: blocked.is_blocked ? 'blocked' : 'ready',
                value: blocked.is_blocked ? '有阻断' : '无阻断',
                detail: normalizeOtaDiagnosisArray(blocked.blocked_reasons).slice(0, 2).join('、') || '-',
            },
            {
                key: 'human_confirmation',
                title: '人工确认',
                status: human.status || (readyCount > 0 ? 'pending' : 'blocked'),
                value: human.required === false ? '无需确认' : otaDiagnosisDecisionStatusText(human.status || (readyCount > 0 ? 'pending' : 'blocked')),
                detail: human.reason || 'manual confirmation required before operation execution',
            },
        ];
    };

    const buildOtaDiagnosisBusinessLoopSteps = (result = {}) => {
        const closure = otaDiagnosisDecisionClosure(result);
        const readyCount = otaDiagnosisReadyActionCount(result);
        const blockedCount = otaDiagnosisBlockedActionCount(result);
        const evidence = closure.data_evidence_input || {};
        const conclusion = closure.diagnostic_conclusion || {};
        const enough = evidence.enough_for_executable_actions === true || readyCount > 0;
        return [
            {
                key: 'ota_data',
                title: 'OTA数据',
                status: enough ? 'ready' : 'blocked',
                detail: enough ? '已形成动作证据' : '需补目标日期证据',
            },
            {
                key: 'revenue_analysis',
                title: '收益分析',
                status: conclusion.summary || result?.diagnosis?.summary ? 'ready' : 'unknown',
                detail: conclusion.confidence_level || '按入库指标诊断',
            },
            {
                key: 'ai_decision',
                title: 'AI决策',
                status: readyCount > 0 ? 'pending_human_confirmation' : 'blocked',
                detail: `${readyCount}项可执行，${blockedCount}项阻断`,
            },
            {
                key: 'operation_management',
                title: '运营管理',
                status: readyCount > 0 ? 'pending' : 'blocked',
                detail: readyCount > 0 ? '待确认后进入执行' : '不能创建执行动作',
            },
            {
                key: 'investment_decision',
                title: '投资决策',
                status: 'blocked_by_operation_closure',
                detail: '等待运营执行与ROI复盘',
            },
        ];
    };

    const buildOtaDiagnosisActionRows = (result = {}) => otaDiagnosisActionItems(result).map((item, index) => {
        const missingEvidence = normalizeOtaDiagnosisArray(item.missing_evidence);
        const evidenceRefs = normalizeOtaDiagnosisArray(item.evidence_refs);
        const requiredEvidence = normalizeOtaDiagnosisArray(item.required_evidence);
        return {
            id: item.id || `ota_action_${index + 1}`,
            action: item.action || item.title || '-',
            status: item.status || (item.execution_ready ? 'pending_human_confirmation' : 'blocked'),
            statusText: otaDiagnosisDecisionStatusText(item.status || (item.execution_ready ? 'pending_human_confirmation' : 'blocked')),
            statusClass: otaDiagnosisDecisionStatusClass(item.status || (item.execution_ready ? 'pending_human_confirmation' : 'blocked')),
            executionReady: item.execution_ready === true,
            evidenceText: evidenceRefs.length ? evidenceRefs.slice(0, 3).join('、') : '-',
            requiredText: requiredEvidence.length ? requiredEvidence.join('、') : '-',
            missingText: missingEvidence.map(evidence => evidence.label || evidence.code || '').filter(Boolean).slice(0, 3).join('、') || '',
            blockedReason: item.blocked_reason || '',
            confirmationText: otaDiagnosisDecisionStatusText(item.human_confirmation_status || (item.execution_ready ? 'pending' : 'blocked')),
        };
    });

    const normalizeOtaDiagnosisGapSource = (value) => {
        if (Array.isArray(value)) return value.filter(Boolean);
        if (value && typeof value === 'object') return [value];
        if (typeof value === 'string' && value.trim() !== '') return [value.trim()];
        return [];
    };

    const otaDiagnosisDataGapItems = (result = {}) => {
        const closureGaps = normalizeOtaDiagnosisGapSource(otaDiagnosisDecisionClosure(result)?.data_evidence_input?.data_gaps);
        const resultGaps = normalizeOtaDiagnosisGapSource(result?.data_gaps);
        const missingSections = normalizeOtaDiagnosisGapSource(result?.missing_sections).map(item => (
            item && typeof item === 'object' ? item : {
                code: 'missing_section',
                message: String(item || '').trim(),
            }
        ));
        const seen = new Set();
        return [...closureGaps, ...resultGaps, ...missingSections].filter(item => {
            const source = item && typeof item === 'object' ? item : { code: String(item || '').trim() };
            const key = [
                source.code || source.key || '',
                source.message || source.label || source.title || source.detail || '',
            ].join('|').trim();
            if (!key || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    };

    const buildOtaDiagnosisDataGapRows = (result = {}) => otaDiagnosisDataGapItems(result).map((gap, index) => {
        const source = gap && typeof gap === 'object' ? gap : { code: String(gap || '').trim() };
        const code = String(source.code || source.key || `data_gap_${index + 1}`).trim();
        const label = String(source.label || source.title || code || '证据缺口').trim();
        const message = String(source.message || source.description || source.detail || source.reason || '').trim();
        const nextAction = String(source.next_action || source.nextAction || source.required_action || source.action || '').trim();
        const scope = String(source.scope || source.source_scope || source.platform_scope || source.platform || 'OTA渠道口径').trim();
        const status = source.status || source.blocked_status || 'blocked_by_data_gap';
        return {
            id: code || `data_gap_${index + 1}`,
            code: code || 'data_gap',
            label,
            message: message || '-',
            nextAction: nextAction || '-',
            scope,
            status,
            statusText: otaDiagnosisDecisionStatusText(status),
            statusClass: otaDiagnosisDecisionStatusClass(status),
        };
    });

    const firstOtaDiagnosisValue = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && item !== '');
        return value === undefined ? '' : value;
    };
    const compactOtaDiagnosisBody = (body = {}) => {
        const compacted = {};
        Object.keys(body).forEach(key => {
            const value = body[key];
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value) && value.length === 0) return;
            compacted[key] = value;
        });
        return compacted;
    };
    const isSavedOtaDiagnosisDataConfigUsable = (config, systemHotelId) => {
        if (!config || Object.keys(config).length === 0) return false;
        const enabled = config.enabled;
        if (enabled === false || enabled === 0 || String(enabled).toLowerCase() === 'false') return false;
        const configHotelId = String(firstOtaDiagnosisValue(config.system_hotel_id, config.hotelId, config.hotel_id)).trim();
        return configHotelId === '' || configHotelId === String(systemHotelId);
    };
    const hasCtripCookieApiRequestConfig = (config = {}, systemHotelId = '') => isSavedOtaDiagnosisDataConfigUsable(config, systemHotelId) && (
        String(firstOtaDiagnosisValue(
            config.request_urls,
            config.requestUrls,
            config.request_url,
            config.requestUrl,
            config.url
        ) || '').trim() !== ''
    );
    const buildOtaDiagnosisFetchContext = ({
        selectedHotel = {},
        form = {},
        ctripConfig = null,
        meituanConfig = null,
        ctripTrafficConfig = {},
        ctripCookieApiConfig = {},
        meituanTrafficConfig = {},
    } = {}) => {
        const systemHotelId = String(selectedHotel?.system_hotel_id || selectedHotel?.hotel_id || form.hotel_id || '').trim();
        return {
            selectedHotel,
            form,
            systemHotelId,
            startDate: form.start_date,
            endDate: form.end_date,
            ctripConfig,
            meituanConfig,
            ctripTrafficConfig,
            ctripCookieApiConfig,
            meituanTrafficConfig,
            hasCtripCookieApiRequests: hasCtripCookieApiRequestConfig(ctripCookieApiConfig, systemHotelId),
        };
    };
    const pushOtaDiagnosisFetchTask = (tasks, task) => {
        const missing = (task.required || []).some(key => !String(task.body?.[key] || '').trim());
        if (missing) return;
        tasks.push({
            label: task.label,
            url: task.url,
            body: compactOtaDiagnosisBody(task.body || {}),
        });
    };
    const isOtaDiagnosisCredentialReady = (config = null) => Boolean(
        config
        && String(firstOtaDiagnosisValue(config.config_id, config.id) || '').trim()
        && String(config.credential_status || '') === 'ready'
        && config.has_cookies === true
    );
    const buildOtaDiagnosisFetchTasks = ({
        context = {},
    } = {}) => {
        const tasks = [];
        const systemHotelId = context.systemHotelId;
        if (!systemHotelId) return tasks;
        const startDate = context.startDate;
        const endDate = context.endDate;
        const ctripConfig = context.ctripConfig || null;
        const ctripTrafficConfig = context.ctripTrafficConfig || {};
        const ctripCookieApiConfig = context.ctripCookieApiConfig || {};
        const meituanConfig = context.meituanConfig || null;
        const meituanTrafficConfig = context.meituanTrafficConfig || {};

        const ctripConfigId = String(firstOtaDiagnosisValue(ctripConfig?.config_id, ctripConfig?.id) || '').trim();
        if (isOtaDiagnosisCredentialReady(ctripConfig)) {
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-business',
                url: '/online-data/fetch-ctrip',
                required: ['config_id', 'node_id'],
                body: {
                    config_id: ctripConfigId,
                    url: ctripConfig.url,
                    node_id: firstOtaDiagnosisValue(ctripConfig.node_id, ctripConfig.nodeId, '24588'),
                    start_date: startDate,
                    end_date: endDate,
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        if (isOtaDiagnosisCredentialReady(ctripConfig) && isSavedOtaDiagnosisDataConfigUsable(ctripTrafficConfig, systemHotelId)) {
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-traffic',
                url: '/online-data/ctrip/traffic',
                required: ['config_id'],
                body: {
                    config_id: ctripConfigId,
                    url: ctripTrafficConfig.url,
                    platform: ctripTrafficConfig.platform || 'Ctrip',
                    date_range: 'custom',
                    start_date: startDate,
                    end_date: endDate,
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        if (context.hasCtripCookieApiRequests && isOtaDiagnosisCredentialReady(ctripConfig)) {
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-cookie-api',
                url: '/online-data/fetch-ctrip-cookie-api',
                required: ['config_id'],
                body: {
                    config_id: ctripConfigId,
                    request_urls: firstOtaDiagnosisValue(ctripCookieApiConfig.request_urls, ctripCookieApiConfig.requestUrls),
                    request_url: firstOtaDiagnosisValue(ctripCookieApiConfig.request_url, ctripCookieApiConfig.requestUrl, ctripCookieApiConfig.url),
                    method: String(ctripCookieApiConfig.method || 'GET').toUpperCase(),
                    hotel_id: firstOtaDiagnosisValue(
                        ctripCookieApiConfig.hotel_id,
                        ctripCookieApiConfig.ctrip_hotel_id,
                        ctripCookieApiConfig.ctripHotelId,
                        ctripCookieApiConfig.node_id,
                        ctripCookieApiConfig.nodeId,
                        ctripConfig?.ota_hotel_id,
                        ctripConfig?.ctrip_hotel_id,
                        ctripConfig?.hotel_id,
                        ctripConfig?.node_id,
                        ctripConfig?.nodeId
                    ),
                    node_id: firstOtaDiagnosisValue(ctripCookieApiConfig.node_id, ctripCookieApiConfig.nodeId),
                    hotel_name: firstOtaDiagnosisValue(ctripCookieApiConfig.hotel_name, ctripCookieApiConfig.hotelName, ctripConfig?.name),
                    data_date: startDate,
                    start_date: startDate,
                    end_date: endDate,
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                    request_source: 'saved_metadata',
                },
            });
        }

        const meituanConfigId = String(firstOtaDiagnosisValue(meituanConfig?.config_id, meituanConfig?.id) || '').trim();
        if (isOtaDiagnosisCredentialReady(meituanConfig)) {
            ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'].forEach(rankType => {
                pushOtaDiagnosisFetchTask(tasks, {
                    label: `meituan-${rankType}`,
                    url: '/online-data/fetch-meituan',
                    required: ['config_id', 'partner_id', 'poi_id'],
                    body: {
                        config_id: meituanConfigId,
                        url: meituanConfig.url,
                        partner_id: firstOtaDiagnosisValue(meituanConfig.partner_id, meituanConfig.partnerId),
                        poi_id: firstOtaDiagnosisValue(meituanConfig.poi_id, meituanConfig.poiId),
                        rank_type: rankType,
                        data_scope: meituanConfig.data_scope,
                        date_range: 'custom',
                        start_date: startDate,
                        end_date: endDate,
                        auto_save: true,
                        system_hotel_id: systemHotelId,
                    },
                });
            });
        }

        if (isOtaDiagnosisCredentialReady(meituanConfig) && isSavedOtaDiagnosisDataConfigUsable(meituanTrafficConfig, systemHotelId)) {
            const meituanTrafficPartnerId = firstOtaDiagnosisValue(meituanTrafficConfig.partner_id, meituanTrafficConfig.partnerId, meituanConfig?.partner_id, meituanConfig?.partnerId);
            const meituanTrafficPoiId = firstOtaDiagnosisValue(meituanTrafficConfig.poi_id, meituanTrafficConfig.poiId, meituanConfig?.poi_id, meituanConfig?.poiId);
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'meituan-traffic',
                url: '/online-data/fetch-meituan-traffic',
                required: ['config_id', 'url', 'partner_id', 'poi_id'],
                body: {
                    config_id: meituanConfigId,
                    url: meituanTrafficConfig.url,
                    partner_id: meituanTrafficPartnerId,
                    poi_id: meituanTrafficPoiId,
                    start_date: startDate,
                    end_date: endDate,
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        return tasks;
    };

    const buildEmptyOtaDiagnosisFetchSummary = () => ({
        attempted: 0,
        success: 0,
        failed: 0,
        results: [],
    });

    const runOtaDiagnosisFetchTasks = async ({
        tasks = [],
        requestTask = async () => ({}),
    } = {}) => {
        const results = [];
        for (const task of tasks) {
            try {
                const res = await requestTask(task);
                results.push({
                    label: task.label,
                    success: Number(res?.code || 0) === 200,
                    message: res?.message || res?.msg || '',
                    saved_count: Number(res?.data?.saved_count || 0),
                    request_source: task.body?.request_source || '',
                });
            } catch (error) {
                results.push({
                    label: task.label,
                    success: false,
                    message: error?.data?.message || error?.data?.msg || error?.message || 'fetch failed',
                    saved_count: 0,
                    request_source: task.body?.request_source || '',
                });
            }
        }

        const success = results.filter(item => item.success).length;
        return {
            attempted: results.length,
            success,
            failed: results.length - success,
            results,
        };
    };

    const runOtaDiagnosisHotelFetchFlow = async ({
        selectedHotel = {},
        form = {},
        findCtripConfigByHotelId = () => null,
        findMeituanConfigByHotelId = () => null,
        requestTask = async () => ({}),
        notify = () => {},
    } = {}) => {
        const initialSystemHotelId = String(selectedHotel?.system_hotel_id || selectedHotel?.hotel_id || form.hotel_id || '').trim();
        if (!initialSystemHotelId) return buildEmptyOtaDiagnosisFetchSummary();

        const ctripConfig = findCtripConfigByHotelId(initialSystemHotelId);
        const meituanConfig = findMeituanConfigByHotelId(initialSystemHotelId);
        const fetchContext = buildOtaDiagnosisFetchContext({
            selectedHotel,
            form,
            ctripConfig,
            meituanConfig,
        });
        const systemHotelId = fetchContext.systemHotelId;
        if (!systemHotelId) return buildEmptyOtaDiagnosisFetchSummary();

        const tasks = buildOtaDiagnosisFetchTasks({
            context: fetchContext,
        });
        if (tasks.length === 0) return buildEmptyOtaDiagnosisFetchSummary();

        notify('正在同步该门店OTA数据...');
        return runOtaDiagnosisFetchTasks({ tasks, requestTask });
    };

    const buildOtaDiagnosisGenerateRequestBody = ({
        selectedHotel = null,
        form = {},
        modelKey = '',
    } = {}) => {
        const diagnosisHotelId = String(selectedHotel?.hotel_id || form.hotel_id || '').trim();
        return {
            hotel_id: diagnosisHotelId || 0,
            platform_hotel_id: selectedHotel?.platform_hotel_id || '',
            config_id: selectedHotel?.config_id || '',
            config_source: selectedHotel?.source || '',
            hotel_name: selectedHotel?.name || '',
            platform: form.platform,
            start_date: form.start_date,
            end_date: form.end_date,
            mode: 'historical_db',
            analysis_type: 'all',
            data_type: 'traffic',
            model_key: modelKey,
        };
    };

    const isEmptyOtaDiagnosisResult = (data = {}) => {
        const conclusion = String(data?.diagnosis?.summary || data?.core_conclusion || '');
        return conclusion.includes('暂无 OTA 数据')
            || conclusion.includes('暂无OTA数据')
            || conclusion.includes('暂无该酒店在该日期范围内的OTA数据');
    };

    const buildOtaDiagnosisFetchFailureWarning = (fetchSummary = {}) => {
        if (!(fetchSummary.attempted > 0 && fetchSummary.failed > 0)) return '';
        const failedText = (Array.isArray(fetchSummary.results) ? fetchSummary.results : [])
            .filter(item => !item.success)
            .map(item => `${item.label}: ${item.message || 'failed'}`)
            .slice(0, 2)
            .join('；');
        return `OTA数据同步完成，${fetchSummary.failed} 项失败：${failedText}。继续使用已入库数据生成诊断`;
    };

    const runOtaDiagnosisGenerateFlow = async ({
        form = {},
        hotelOptions = [],
        getModelKey = () => '',
        runHotelFetch = async () => buildEmptyOtaDiagnosisFetchSummary(),
        requestDiagnosis = async () => ({}),
        setLoading = () => {},
        setError = () => {},
        setResult = () => {},
        setEmpty = () => {},
        notify = () => {},
    } = {}) => {
        const currentForm = form || {};
        setError('');
        setResult(null);
        setEmpty(false);

        if (!currentForm.hotel_id) {
            setError('请选择酒店');
            return { status: 'missing_hotel' };
        }
        const selectedHotel = (Array.isArray(hotelOptions) ? hotelOptions : [])
            .find(item => item.value === currentForm.hotel_id);
        if (!currentForm.start_date || !currentForm.end_date) {
            setError('请选择日期范围');
            return { status: 'missing_date_range', selectedHotel };
        }
        if (currentForm.start_date > currentForm.end_date) {
            setError('开始日期不能晚于结束日期');
            return { status: 'invalid_date_range', selectedHotel };
        }

        let fetchSummary = buildEmptyOtaDiagnosisFetchSummary();
        let requestBody = null;
        setLoading(true);
        try {
            fetchSummary = await runHotelFetch(selectedHotel, currentForm);
            const warning = buildOtaDiagnosisFetchFailureWarning(fetchSummary);
            if (warning) notify(warning, 'warning');

            requestBody = buildOtaDiagnosisGenerateRequestBody({
                selectedHotel,
                form: currentForm,
                modelKey: getModelKey(),
            });
            const res = await requestDiagnosis(requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                const isEmpty = isEmptyOtaDiagnosisResult(data);
                setEmpty(isEmpty);
                setResult(data);
                notify(isEmpty ? '暂无OTA数据' : 'OTA诊断已生成', isEmpty ? 'warning' : undefined);
                return {
                    status: isEmpty ? 'empty' : 'success',
                    response: res,
                    requestBody,
                    fetchSummary,
                    data,
                };
            }

            const errorMessage = res.message || res.msg || 'OTA诊断生成失败';
            setError(errorMessage);
            return {
                status: 'failed',
                response: res,
                requestBody,
                fetchSummary,
                errorMessage,
            };
        } catch (error) {
            const errorMessage = error?.data?.message || error?.data?.msg || error.message || 'OTA诊断生成失败';
            setError(errorMessage);
            return {
                status: 'exception',
                error,
                requestBody,
                fetchSummary,
                errorMessage,
            };
        } finally {
            setLoading(false);
        }
    };

    return {
        normalizeOtaDiagnosisList,
        otaDiagnosisPlatformText,
        otaDiagnosisDateRangeText,
        otaDiagnosisPriorityClass,
        otaDiagnosisPriorityText,
        buildOtaDiagnosisMetricCards,
        buildOtaDiagnosisResultSections,
        otaDiagnosisDecisionStatusText,
        otaDiagnosisDecisionStatusClass,
        buildOtaDiagnosisDecisionClosureCards,
        buildOtaDiagnosisBusinessLoopSteps,
        buildOtaDiagnosisActionRows,
        buildOtaDiagnosisDataGapRows,
        buildOtaDiagnosisFetchContext,
        buildOtaDiagnosisFetchTasks,
        runOtaDiagnosisHotelFetchFlow,
        buildOtaDiagnosisGenerateRequestBody,
        isEmptyOtaDiagnosisResult,
        buildOtaDiagnosisFetchFailureWarning,
        runOtaDiagnosisGenerateFlow,
    };
})();
