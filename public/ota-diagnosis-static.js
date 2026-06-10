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
    const firstOtaDiagnosisValue = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && item !== '');
        return value === undefined ? '' : value;
    };
    const readOtaDiagnosisHeaderValue = (headers, headerName) => {
        const target = String(headerName || '').trim().toLowerCase();
        if (!target || headers === undefined || headers === null || headers === '') return '';
        if (typeof headers === 'object' && !Array.isArray(headers)) {
            const foundKey = Object.keys(headers).find(key => key.toLowerCase() === target);
            return foundKey ? String(headers[foundKey] || '').trim() : '';
        }
        const raw = String(headers || '').trim();
        if (!raw) return '';
        if (raw.startsWith('{')) {
            try {
                return readOtaDiagnosisHeaderValue(JSON.parse(raw), target);
            } catch (e) {
                return '';
            }
        }
        const line = raw.split(/\r?\n/).find(item => item.trim().toLowerCase().startsWith(`${target}:`));
        return line ? line.split(':').slice(1).join(':').trim() : '';
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
            config.endpoints_json,
            config.endpointsJson,
            config.request_url,
            config.requestUrl,
            config.url
        ) || '').trim() !== ''
        || (Array.isArray(config.endpoints) && config.endpoints.length > 0)
        || (Array.isArray(config.requests) && config.requests.length > 0)
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
        const ctripCookieApiProfileId = firstOtaDiagnosisValue(
            ctripCookieApiConfig.profile_id,
            ctripCookieApiConfig.profileId,
            ctripCookieApiConfig.browser_profile_id,
            ctripCookieApiConfig.browserProfileId,
            ctripCookieApiConfig.ota_hotel_id,
            ctripCookieApiConfig.ctrip_hotel_id,
            ctripCookieApiConfig.ctripHotelId,
            ctripCookieApiConfig.node_id,
            ctripCookieApiConfig.nodeId,
            ctripCookieApiConfig.hotel_id,
            ctripConfig?.profile_id,
            ctripConfig?.profileId,
            ctripConfig?.browser_profile_id,
            ctripConfig?.browserProfileId,
            ctripConfig?.ota_hotel_id,
            ctripConfig?.ctrip_hotel_id,
            ctripConfig?.ctripHotelId,
            ctripConfig?.node_id,
            ctripConfig?.nodeId,
            ctripConfig?.hotel_id,
            systemHotelId
        );
        const ctripCookieApiHeaderCookie = firstOtaDiagnosisValue(
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.headers_json, 'cookie'),
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.headersJson, 'cookie'),
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.request_headers, 'cookie'),
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.requestHeaders, 'cookie'),
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.request_headers_json, 'cookie'),
            readOtaDiagnosisHeaderValue(ctripCookieApiConfig.requestHeadersJson, 'cookie')
        );
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
            ctripTrafficCookies: firstOtaDiagnosisValue(ctripTrafficConfig.cookies, ctripTrafficConfig.cookie, ctripConfig?.cookies),
            ctripCookieApiProfileId,
            ctripCookieApiCookies: firstOtaDiagnosisValue(
                ctripCookieApiConfig.cookies,
                ctripCookieApiConfig.cookie,
                ctripCookieApiHeaderCookie,
                ctripConfig?.cookies,
                ctripConfig?.cookie
            ),
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
    const buildOtaDiagnosisFetchTasks = ({
        context = {},
        genericCtripCookie = null,
        useCtripCorePresetForDiagnosis = false,
        ctripCorePresetReason = '',
        ctripCorePresetJson = '',
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

        if (ctripConfig && String(ctripConfig.cookies || '').trim()) {
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-business',
                url: '/online-data/fetch-ctrip',
                required: ['cookies', 'node_id'],
                body: {
                    url: ctripConfig.url,
                    node_id: firstOtaDiagnosisValue(ctripConfig.node_id, ctripConfig.nodeId, '24588'),
                    cookies: ctripConfig.cookies,
                    auth_data: ctripConfig.auth_data || {},
                    start_date: startDate,
                    end_date: endDate,
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        const ctripTrafficCookies = context.ctripTrafficCookies;
        if (ctripTrafficCookies && (isSavedOtaDiagnosisDataConfigUsable(ctripTrafficConfig, systemHotelId) || ctripConfig)) {
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-traffic',
                url: '/online-data/ctrip/traffic',
                required: ['cookies'],
                body: {
                    url: ctripTrafficConfig.url,
                    platform: ctripTrafficConfig.platform || 'Ctrip',
                    date_range: 'custom',
                    start_date: startDate,
                    end_date: endDate,
                    spiderkey: ctripTrafficConfig.spiderkey,
                    cookies: ctripTrafficCookies,
                    extra_params: firstOtaDiagnosisValue(ctripTrafficConfig.extra_params, ctripTrafficConfig.extraParams),
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        if (context.hasCtripCookieApiRequests || useCtripCorePresetForDiagnosis) {
            const endpointsJson = context.hasCtripCookieApiRequests
                ? firstOtaDiagnosisValue(ctripCookieApiConfig.endpoints_json, ctripCookieApiConfig.endpointsJson)
                : ctripCorePresetJson;
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'ctrip-cookie-api',
                url: '/online-data/fetch-ctrip-cookie-api',
                required: [],
                body: {
                    request_urls: firstOtaDiagnosisValue(ctripCookieApiConfig.request_urls, ctripCookieApiConfig.requestUrls),
                    endpoints: firstOtaDiagnosisValue(ctripCookieApiConfig.endpoints, ctripCookieApiConfig.requests, []),
                    endpoints_json: endpointsJson,
                    request_url: firstOtaDiagnosisValue(ctripCookieApiConfig.request_url, ctripCookieApiConfig.requestUrl, ctripCookieApiConfig.url),
                    method: String(ctripCookieApiConfig.method || 'GET').toUpperCase(),
                    payload_json: firstOtaDiagnosisValue(ctripCookieApiConfig.payload_json, ctripCookieApiConfig.payloadJson),
                    headers_json: firstOtaDiagnosisValue(ctripCookieApiConfig.headers_json, ctripCookieApiConfig.headersJson),
                    cookies: firstOtaDiagnosisValue(context.ctripCookieApiCookies, genericCtripCookie?.cookies),
                    profile_id: context.ctripCookieApiProfileId,
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
                    request_source: context.hasCtripCookieApiRequests ? 'saved_config' : `core_preset:${ctripCorePresetReason || 'unknown'}`,
                },
            });
        }

        if (meituanConfig && String(meituanConfig.cookies || '').trim()) {
            ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'].forEach(rankType => {
                pushOtaDiagnosisFetchTask(tasks, {
                    label: `meituan-${rankType}`,
                    url: '/online-data/fetch-meituan',
                    required: ['cookies', 'partner_id', 'poi_id'],
                    body: {
                        url: meituanConfig.url,
                        partner_id: firstOtaDiagnosisValue(meituanConfig.partner_id, meituanConfig.partnerId),
                        poi_id: firstOtaDiagnosisValue(meituanConfig.poi_id, meituanConfig.poiId),
                        rank_type: rankType,
                        data_scope: meituanConfig.data_scope,
                        date_range: 'custom',
                        cookies: meituanConfig.cookies,
                        auth_data: meituanConfig.auth_data || {},
                        start_date: startDate,
                        end_date: endDate,
                        auto_save: true,
                        system_hotel_id: systemHotelId,
                    },
                });
            });
        }

        if (isSavedOtaDiagnosisDataConfigUsable(meituanTrafficConfig, systemHotelId)) {
            const meituanTrafficPartnerId = firstOtaDiagnosisValue(meituanTrafficConfig.partner_id, meituanTrafficConfig.partnerId, meituanConfig?.partner_id, meituanConfig?.partnerId);
            const meituanTrafficPoiId = firstOtaDiagnosisValue(meituanTrafficConfig.poi_id, meituanTrafficConfig.poiId, meituanConfig?.poi_id, meituanConfig?.poiId);
            pushOtaDiagnosisFetchTask(tasks, {
                label: 'meituan-traffic',
                url: '/online-data/fetch-meituan-traffic',
                required: ['url', 'cookies', 'partner_id', 'poi_id'],
                body: {
                    url: meituanTrafficConfig.url,
                    partner_id: meituanTrafficPartnerId,
                    poi_id: meituanTrafficPoiId,
                    cookies: firstOtaDiagnosisValue(meituanTrafficConfig.cookies, meituanTrafficConfig.cookie),
                    start_date: startDate,
                    end_date: endDate,
                    extra_params: firstOtaDiagnosisValue(meituanTrafficConfig.extra_params, meituanTrafficConfig.extraParams),
                    auto_save: true,
                    system_hotel_id: systemHotelId,
                },
            });
        }

        return tasks;
    };

    return {
        normalizeOtaDiagnosisList,
        otaDiagnosisPlatformText,
        otaDiagnosisDateRangeText,
        otaDiagnosisPriorityClass,
        otaDiagnosisPriorityText,
        buildOtaDiagnosisMetricCards,
        buildOtaDiagnosisResultSections,
        buildOtaDiagnosisFetchContext,
        buildOtaDiagnosisFetchTasks,
    };
})();
