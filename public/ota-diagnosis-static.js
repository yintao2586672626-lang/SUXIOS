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

    const otaDiagnosisPlatformText = (platform) => {
        const value = String(platform || '').trim().toLowerCase();
        if (value === 'meituan') return '美团';
        if (value === 'ctrip') return '携程';
        return value ? '未知平台' : '平台未指定';
    };

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
            { label: '入库记录', value: formatOtaMetricValue(metrics.record_count ?? null, formatNumber), hint: summary.core_metrics_complete ? '本次诊断入库记录' : '含补充记录，不代表核心经营事实', icon: 'fas fa-database' },
            { label: '订单', value: formatOtaMetricValue(metrics.book_order_num ?? null, formatNumber), hint: '周期内订单量', icon: 'fas fa-receipt' },
            { label: '曝光', value: formatOtaMetricValue(metrics.list_exposure ?? null, formatNumber), hint: '列表曝光量', icon: 'fas fa-eye' },
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
            action_required: '需要行动',
            no_action: '无需行动',
            blocked_by_data: '数据不足',
            blocked: '证据阻断',
            blocked_by_missing_ota_data: '缺OTA数据',
            blocked_by_non_target_date_data: '非目标日数据',
            blocked_by_insufficient_evidence: '证据不足',
            blocked_by_data_gap: '数据缺口',
            blocked_by_operation_closure: '待运营复盘',
            not_required: '无需确认',
            optional_missing: '补充项缺失',
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
        if (['ready', 'confirmed', 'no_action'].includes(value)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['pending', 'pending_human_confirmation', 'partial_ready', 'action_required'].includes(value)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (value.startsWith('blocked') || ['rejected'].includes(value)) return 'bg-red-50 text-red-700 border-red-100';
        if (['not_required', 'optional_missing'].includes(value)) return 'bg-slate-50 text-slate-600 border-slate-200';
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
        const decisionStatus = String(closure.status || '').toLowerCase();
        const noAction = decisionStatus === 'no_action';
        const evidenceReady = evidence.enough_for_decision === true;

        return [
            {
                key: 'data_evidence_input',
                title: '数据证据输入',
                status: evidenceReady ? 'ready' : 'blocked',
                value: `${evidenceRefs.length}项证据`,
                detail: dataGaps.length ? `缺口 ${dataGaps.length} 项` : (evidence.source_policy || 'database_only'),
            },
            {
                key: 'diagnostic_conclusion',
                title: '诊断结论',
                status: evidenceReady ? (conclusion.summary ? 'ready' : 'unknown') : 'blocked_by_data',
                value: evidenceReady
                    ? (conclusion.confidence_level ? otaDiagnosisDecisionStatusText(conclusion.confidence_level) : '已生成')
                    : '仅形成缺数结论',
                detail: conclusion.summary || result?.core_conclusion || result?.diagnosis?.summary || '-',
            },
            {
                key: 'suggested_actions',
                title: '建议动作',
                status: evidenceReady
                    ? (noAction ? 'no_action' : (readyCount > 0 ? 'action_required' : 'blocked_by_data'))
                    : 'blocked_by_data',
                value: evidenceReady
                    ? (noAction ? '本次无需新增行动' : `${readyCount}可执行 / ${blockedCount}阻断`)
                    : '上游证据不足，动作不可执行',
                detail: evidenceReady
                    ? (noAction ? '不创建执行意图' : `${normalizeOtaDiagnosisArray(suggested.items || result.action_items).length} 项动作`)
                    : '建议动作不能替代 OTA 数据证据',
            },
            {
                key: 'blocked_state',
                title: 'blocked 状态',
                status: blocked.is_blocked || !evidenceReady ? 'blocked_by_data' : (noAction ? 'no_action' : 'ready'),
                value: blocked.is_blocked ? '有阻断' : '无阻断',
                detail: normalizeOtaDiagnosisArray(blocked.blocked_reasons).slice(0, 2).join('、') || '-',
            },
            {
                key: 'human_confirmation',
                title: '人工确认',
                status: evidenceReady ? (human.status || (readyCount > 0 ? 'pending' : 'not_required')) : 'blocked_by_data',
                value: evidenceReady
                    ? (human.required === false ? '无需确认' : otaDiagnosisDecisionStatusText(human.status || (readyCount > 0 ? 'pending' : 'not_required')))
                    : '上游证据不足',
                detail: human.reason || '运营执行前需要人工确认',
            },
        ];
    };

    const buildOtaDiagnosisBusinessLoopSteps = (result = {}) => {
        const closure = otaDiagnosisDecisionClosure(result);
        const readyCount = otaDiagnosisReadyActionCount(result);
        const blockedCount = otaDiagnosisBlockedActionCount(result);
        const evidence = closure.data_evidence_input || {};
        const conclusion = closure.diagnostic_conclusion || {};
        const decisionStatus = String(closure.status || '').toLowerCase();
        const noAction = decisionStatus === 'no_action';
        const evidenceReady = evidence.enough_for_decision === true;
        return [
            {
                key: 'ota_data',
                title: 'OTA数据',
                status: evidenceReady ? 'ready' : 'blocked',
                detail: evidenceReady ? '已形成可验证的目标日 OTA 证据' : '需补目标日期证据',
            },
            {
                key: 'revenue_analysis',
                title: '收益分析',
                status: evidenceReady
                    ? (conclusion.summary || result?.diagnosis?.summary ? 'ready' : 'unknown')
                    : 'blocked_by_data',
                detail: evidenceReady ? (conclusion.confidence_level || '按已验证的 OTA 指标诊断') : '仅能确认目标日核心数据缺失',
            },
            {
                key: 'ai_decision',
                title: 'AI决策',
                status: evidenceReady ? (noAction ? 'no_action' : (readyCount > 0 ? 'action_required' : 'blocked_by_data')) : 'blocked_by_data',
                detail: evidenceReady
                    ? (noAction ? '本次无需新增行动' : `${readyCount}项可执行，${blockedCount}项阻断`)
                    : '上游 OTA 证据不足，不生成可执行决策',
            },
            {
                key: 'operation_management',
                title: '运营管理',
                status: evidenceReady
                    ? (noAction ? 'not_required' : (readyCount > 0 ? 'pending' : 'blocked_by_data'))
                    : 'blocked_by_data',
                detail: evidenceReady
                    ? (noAction ? '无需创建执行意图' : (readyCount > 0 ? '待确认后进入执行' : '不能创建执行动作'))
                    : '数据证据未就绪，不能创建执行动作',
            },
            {
                key: 'effect_review',
                title: '效果复盘',
                status: evidenceReady && noAction ? 'not_required' : (evidenceReady ? 'blocked_by_operation_closure' : 'blocked_by_data'),
                detail: evidenceReady && noAction
                    ? '本次没有执行动作，无需复盘效果'
                    : (evidenceReady ? '等待运营执行与后续真实数据' : '核心数据未补齐，不能进入效果复盘'),
            },
        ];
    };

    const buildOtaDiagnosisActionRows = (result = {}) => otaDiagnosisActionItems(result).map((item, index) => {
        const superseded = result?.record_status === 'superseded' || result?.saved_record?.status === 'superseded';
        const missingEvidence = normalizeOtaDiagnosisArray(item.missing_evidence);
        const evidenceRefs = normalizeOtaDiagnosisArray(item.evidence_refs);
        const requiredEvidence = normalizeOtaDiagnosisArray(item.required_evidence);
        const dataBasis = item.data_basis && typeof item.data_basis === 'object' ? item.data_basis : {};
        const expectedEffect = item.expected_effect && typeof item.expected_effect === 'object' ? item.expected_effect : {};
        const risk = item.risk && typeof item.risk === 'object' ? item.risk : {};
        const quality = item.decision_quality && typeof item.decision_quality === 'object' ? item.decision_quality : {};
        const qualityV2Ready = quality.contract_version === 'ai_recommendation_quality.v2'
            && quality.execution_ready === true
            && item.can_create_execution_intent === true;
        const status = superseded
            ? 'superseded'
            : (item.status || (item.execution_ready ? 'pending_human_confirmation' : 'blocked'));
        return {
            index,
            id: item.id || `ota_action_${index + 1}`,
            qualityItem: item,
            priority: item.priority || 'P1',
            title: item.title || `建议${index + 1}`,
            action: item.action || item.title || '-',
            status,
            statusText: superseded ? '已被新诊断替代' : otaDiagnosisDecisionStatusText(status),
            statusClass: superseded ? 'bg-slate-100 text-slate-600 border-slate-200' : otaDiagnosisDecisionStatusClass(status),
            executionReady: !superseded && item.execution_ready === true && qualityV2Ready,
            canCreateIntent: !superseded
                && item.execution_ready === true
                && item.can_request_execution_intent !== false
                && qualityV2Ready,
            evidenceText: evidenceRefs.length ? evidenceRefs.slice(0, 3).join('、') : '-',
            dataBasisText: dataBasis.summary || dataBasis.quality_note || '未提供可追溯依据',
            dataBasisMeta: [dataBasis.scope, dataBasis.platform, dataBasis.date].filter(Boolean).join(' · ') || '范围/日期待核验',
            expectedEffectText: expectedEffect.summary || '未定义效果指标',
            expectedMetricText: expectedEffect.metric_label || expectedEffect.metric || item.expected_metric || '待定义指标',
            riskLevel: risk.level || item.risk_level || 'unverified',
            riskText: risk.summary || '未提供风险说明',
            qualityStatus: quality.status || 'legacy_incomplete',
            requiredText: requiredEvidence.length ? requiredEvidence.join('、') : '-',
            missingText: missingEvidence.map(evidence => evidence.label || evidence.code || '').filter(Boolean).slice(0, 3).join('、') || '',
            blockedReason: superseded ? '该保存记录仅供审计回看，不能再转入运营执行。' : (item.blocked_reason || ''),
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
                code: 'optional_missing_section',
                message: String(item || '').trim(),
                status: 'optional_missing',
                scope: '补充数据（不直接代表经营异常）',
            }
        ));
        const seen = new Set();
        return [...closureGaps, ...resultGaps, ...missingSections].filter(item => {
            const source = item && typeof item === 'object' ? item : { code: String(item || '').trim() };
            const code = String(source.code || source.key || '').trim();
            const message = String(source.message || source.label || source.title || source.detail || '').trim();
            const key = code === 'optional_missing_section' ? `${code}|${message}` : (code || message);
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
        const selectedPlatform = String(context.form?.platform || '').trim().toLowerCase();
        const wantsCtrip = !selectedPlatform || selectedPlatform === 'ctrip';
        const wantsMeituan = !selectedPlatform || selectedPlatform === 'meituan';

        const ctripConfigId = String(firstOtaDiagnosisValue(ctripConfig?.config_id, ctripConfig?.id) || '').trim();
        if (wantsCtrip && isOtaDiagnosisCredentialReady(ctripConfig)) {
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

        if (wantsCtrip && isOtaDiagnosisCredentialReady(ctripConfig) && isSavedOtaDiagnosisDataConfigUsable(ctripTrafficConfig, systemHotelId)) {
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

        if (wantsCtrip && context.hasCtripCookieApiRequests && isOtaDiagnosisCredentialReady(ctripConfig)) {
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
        if (wantsMeituan && isOtaDiagnosisCredentialReady(meituanConfig)) {
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

        if (wantsMeituan && isOtaDiagnosisCredentialReady(meituanConfig) && isSavedOtaDiagnosisDataConfigUsable(meituanTrafficConfig, systemHotelId)) {
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

    const buildOtaDiagnosisProfileSyncTask = ({
        selectedHotel = {},
        form = {},
        platformDataSources = [],
    } = {}) => {
        const systemHotelId = String(selectedHotel?.system_hotel_id || selectedHotel?.hotel_id || form.hotel_id || '').trim();
        const platform = String(form.platform || '').trim().toLowerCase();
        const targetDate = String(form.end_date || form.start_date || '').trim();
        if (!systemHotelId || !['ctrip', 'meituan'].includes(platform) || !targetDate || form.start_date !== form.end_date) {
            return null;
        }

        const sources = (Array.isArray(platformDataSources) ? platformDataSources : [])
            .filter(source => {
                const sourceHotelId = String(source?.system_hotel_id || source?.systemHotelId || '').trim();
                const method = String(source?.ingestion_method || source?.ingestionMethod || '').trim().toLowerCase();
                const enabled = source?.enabled !== false && source?.enabled !== 0 && String(source?.enabled).toLowerCase() !== 'false';
                return enabled
                    && sourceHotelId === systemHotelId
                    && String(source?.platform || '').trim().toLowerCase() === platform
                    && ['browser_profile', 'profile_browser'].includes(method)
                    && Number(source?.id || 0) > 0;
            })
            .sort((left, right) => {
                const score = source => ['ready', 'active', 'success'].includes(String(source?.status || '').toLowerCase()) ? 1 : 0;
                return score(right) - score(left) || Number(left.id) - Number(right.id);
            });
        const source = sources[0];
        if (!source) return null;

        return {
            kind: 'browser_profile',
            label: `${platform}-profile-core`,
            url: `/online-data/data-sources/${Number(source.id)}/sync`,
            body: {
                trigger_type: 'daily_profile_reuse',
                interactive_browser: false,
                data_date: targetDate,
                target_date: targetDate,
                data_period: 'historical_daily',
                capture_sections: platform === 'meituan' ? 'traffic,orders' : 'traffic',
                system_hotel_id: systemHotelId,
                ...(platform === 'meituan' ? { meituan_auto_fetch_mode: 'profile_browser' } : { ctrip_auto_fetch_mode: 'profile_browser' }),
            },
        };
    };

    const buildEmptyOtaDiagnosisFetchSummary = () => ({
        attempted: 0,
        success: 0,
        failed: 0,
        results: [],
    });

    const otaDiagnosisOperatorMessageText = (value) => {
        const text = String(value || '').trim();
        const map = {
            profile_session_unverified: 'Profile 登录态未通过目标日验证',
            login_required: '平台登录态已失效，需要重新授权',
            session_expired: '平台授权已失效',
            hotel_mismatch: '当前 Profile 与目标门店不匹配',
            no_data: '未返回目标日业务数据',
        };
        if (map[text.toLowerCase()]) return map[text.toLowerCase()];
        if (/[一-鿿]/.test(text)) return text;
        return text ? '后端返回了未识别的失败状态' : '后端未提供可读的失败原因';
    };

    const otaDiagnosisFetchTaskDisplayLabel = (item = {}) => {
        const label = String(item.label || '').toLowerCase();
        const platform = label.includes('meituan') ? '美团' : (label.includes('ctrip') ? '携程' : 'OTA');
        return item.kind === 'browser_profile' ? `${platform} Profile 同步` : `${platform}补充采集`;
    };

    const otaDiagnosisParsedRowCount = (data = {}) => {
        const numericCounts = [
            data.parsed_count,
            data.parsed_row_count,
            data.row_count,
            data.record_count,
            data.display_hotel_count,
        ].map(value => Number(value || 0)).filter(Number.isFinite);
        const arrayCounts = ['rows', 'display_rows', 'display_hotels', 'records', 'orders']
            .map(key => Array.isArray(data[key]) ? data[key].length : 0);
        if (Array.isArray(data.data)) arrayCounts.push(data.data.length);
        return Math.max(0, ...numericCounts, ...arrayCounts);
    };

    const runOtaDiagnosisFetchTasks = async ({
        tasks = [],
        requestTask = async () => ({}),
    } = {}) => {
        const results = [];
        for (const task of tasks) {
            try {
                const res = await requestTask(task);
                const syncResult = res?.data && typeof res.data === 'object' ? res.data : {};
                const diagnostics = syncResult.sync_diagnostics && typeof syncResult.sync_diagnostics === 'object'
                    ? syncResult.sync_diagnostics
                    : {};
                const profileTask = task.kind === 'browser_profile';
                const profileReady = String(diagnostics.p0_status || '').toLowerCase() === 'ready';
                const responseSuccess = Number(res?.code || 0) === 200;
                const businessStatus = String(syncResult.status || syncResult.business_status || '').trim().toLowerCase();
                const persistenceStatus = String(syncResult.persistence_status || '').trim().toLowerCase();
                const savedCount = Number(syncResult.saved_count || 0);
                const parsedRowCount = otaDiagnosisParsedRowCount(syncResult);
                const readbackVerified = syncResult.readback_verified === true
                    || syncResult.database_readback?.verified === true
                    || syncResult.database_readback?.readback_verified === true
                    || persistenceStatus === 'readback_verified';
                const persisted = savedCount > 0 && (
                    readbackVerified
                    || syncResult.persisted === true
                    || persistenceStatus === 'persisted'
                );
                const businessFailed = ['failed', 'error', 'blocked', 'not_persisted'].includes(businessStatus)
                    || ['failed', 'blocked', 'not_persisted', 'readback_failed'].includes(persistenceStatus);
                const businessCompleted = ['success', 'completed', 'complete', 'partial_success'].includes(businessStatus);
                const taskSuccess = profileTask
                    ? responseSuccess && businessCompleted && profileReady && !businessFailed
                    : responseSuccess
                        && !businessFailed
                        && (savedCount > 0 || readbackVerified || businessCompleted || parsedRowCount > 0);
                const rawMessage = diagnostics.operator_message || syncResult.message || res?.message || res?.msg || '';
                const readableReason = otaDiagnosisOperatorMessageText(rawMessage);
                const message = !responseSuccess
                    ? `请求失败：${readableReason}`
                    : (profileTask && !profileReady)
                        ? `Profile 同步未闭环：${readableReason}`
                        : businessFailed
                            ? `业务处理未完成：${readableReason}`
                            : readbackVerified && savedCount > 0
                                ? `已入库 ${savedCount} 条，并完成数据库回读核验`
                                : persisted
                                    ? `后端明确报告已持久化 ${savedCount} 条，尚未完成数据库回读核验`
                                    : savedCount > 0
                                        ? `请求已完成，接口报告处理 ${savedCount} 条，尚未确认数据库回读`
                                        : parsedRowCount > 0
                                            ? `请求已完成，解析到 ${parsedRowCount} 条可展示记录，尚未确认入库`
                                            : businessCompleted
                                                ? '请求已完成，未解析到可保存记录'
                                                : '请求已返回，但未提供可验证的业务完成状态';
                results.push({
                    label: task.label,
                    display_label: otaDiagnosisFetchTaskDisplayLabel(task),
                    kind: task.kind || 'supplemental_api',
                    success: taskSuccess,
                    request_completed: responseSuccess,
                    message,
                    saved_count: savedCount,
                    parsed_row_count: parsedRowCount,
                    persisted,
                    readback_verified: readbackVerified,
                    persistence_status: persistenceStatus,
                    business_status: businessStatus,
                    p0_status: String(diagnostics.p0_status || ''),
                    sync_status: String(syncResult.status || ''),
                    request_source: task.body?.request_source || '',
                });
            } catch (error) {
                results.push({
                    label: task.label,
                    display_label: otaDiagnosisFetchTaskDisplayLabel(task),
                    kind: task.kind || 'supplemental_api',
                    success: false,
                    request_completed: false,
                    message: `请求异常：${otaDiagnosisOperatorMessageText(error?.data?.message || error?.data?.msg || error?.message)}`,
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
        platformDataSources = [],
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

        const profileTask = buildOtaDiagnosisProfileSyncTask({
            selectedHotel,
            form,
            platformDataSources,
        });
        const tasks = buildOtaDiagnosisFetchTasks({
            context: fetchContext,
        });
        if (profileTask) tasks.unshift(profileTask);
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

    const hasOtaDiagnosisArtifact = (data = {}) => {
        const conclusion = String(
            data?.diagnosis?.summary
            || data?.core_conclusion
            || data?.decision_closure?.diagnostic_conclusion?.summary
            || ''
        ).trim();
        if (conclusion) return true;
        if (Array.isArray(data?.diagnosis_sections) && data.diagnosis_sections.some(section => (
            Array.isArray(section?.items) && section.items.some(Boolean)
        ))) return true;
        if (Array.isArray(data?.action_items) && data.action_items.length > 0) return true;
        if (Array.isArray(data?.data_gaps) && data.data_gaps.length > 0) return true;
        if (Array.isArray(data?.missing_sections) && data.missing_sections.length > 0) return true;
        const diagnosis = data?.diagnosis;
        return Boolean(diagnosis && typeof diagnosis === 'object' && Object.values(diagnosis).some(value => (
            (typeof value === 'string' && value.trim() !== '')
            || (Array.isArray(value) && value.length > 0)
        )));
    };

    const buildOtaDiagnosisFetchFailureWarning = (fetchSummary = {}) => {
        if (!(fetchSummary.attempted > 0 && fetchSummary.failed > 0)) return '';
        const failedText = (Array.isArray(fetchSummary.results) ? fetchSummary.results : [])
            .filter(item => !item.success)
            .map(item => `${item.display_label || otaDiagnosisFetchTaskDisplayLabel(item)}：${item.message || '未提供失败原因'}`)
            .slice(0, 2)
            .join('；');
        return `OTA数据同步未闭环，${fetchSummary.failed} 项未确认成功：${failedText}。诊断仅使用数据库中已有且可验证的数据`;
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
                const businessStatus = String(data.status || data.diagnosis_status || '').trim().toLowerCase();
                const businessFailed = ['failed', 'error'].includes(businessStatus);
                const hasArtifact = hasOtaDiagnosisArtifact(data);
                setEmpty(isEmpty);
                setResult(data);
                if (businessFailed) {
                    const errorMessage = data.message || res.message || 'OTA诊断业务处理未完成';
                    setError(errorMessage);
                    notify(errorMessage, 'error');
                    return {
                        status: 'business_failed',
                        response: res,
                        requestBody,
                        fetchSummary,
                        data,
                        errorMessage,
                    };
                }
                if (isEmpty) {
                    notify('未找到目标酒店及日期范围内的可验证 OTA 数据', 'warning');
                } else if (!hasArtifact) {
                    setEmpty(true);
                    notify('诊断请求已完成，但未形成可验证的诊断结论', 'warning');
                } else if (String(data.decision_status || data.decision_closure?.status || '').startsWith('blocked')) {
                    notify('诊断结果已生成，但受数据缺口阻断', 'warning');
                } else {
                    notify('OTA诊断结果已生成', 'success');
                }
                return {
                    status: isEmpty ? 'empty' : (hasArtifact ? 'success' : 'incomplete'),
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
        buildOtaDiagnosisProfileSyncTask,
        runOtaDiagnosisHotelFetchFlow,
        buildOtaDiagnosisGenerateRequestBody,
        isEmptyOtaDiagnosisResult,
        hasOtaDiagnosisArtifact,
        buildOtaDiagnosisFetchFailureWarning,
        runOtaDiagnosisGenerateFlow,
    };
})();
