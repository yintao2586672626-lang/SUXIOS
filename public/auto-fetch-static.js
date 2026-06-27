window.SUXI_AUTO_FETCH_STATIC = (() => {
    const autoFetchModeOptions = [
        { value: 'hybrid_auto', label: '接口直连自动' },
        { value: 'cookie_config', label: '授权配置自动' },
        { value: 'profile_browser', label: '登录会话自动采集' },
    ];
    const autoFetchCollectionBlueprintRows = [
        { label: '采集对象', value: '授权 OTA 门店指标' },
        { label: '业务日期', value: '历史固定默认昨日；实时快照默认今日' },
        { label: '数据层', value: '原始证据 + 标准行 + 指标行' },
        { label: '入库规则', value: '历史按日更新；实时按小时快照更新' },
    ];
    const autoFetchFieldScopeGroups = [
        {
            category: 'OTA经营',
            metric: '经营概况',
            fields: [
                '今日APP访客', '预订销售额', '实时起价', '点评分', '在店间夜',
                '订单数', '紧张度', '昨日访客', '转化率', '离店销售额',
                '离店间夜', '平均卖价', '实时预订订单', '入住率', '实时排名', '竞争圈排名',
            ],
            source: '携程经营概要、销售报告、流量报告和房态价格页面；美团按已授权流量/订单模块补齐。',
            status: 'ready',
            statusText: '已归档路径',
            action: '默认优先跑经营概要、销售和流量；起价、入住率等以真实响应字段为准。',
        },
        {
            category: '服务质量',
            metric: '服务 / 点评',
            fields: [
                'PSI服务质量分', '点评分', '5分钟回复率', '收藏数',
                '正面标签', '负面标签', '好评率',
            ],
            source: 'PSI、评分、回复率、收藏数已有候选路径；点评标签和好评率涉及点评内容，需显式授权与样例核验。',
            status: 'partial',
            statusText: '需显式验证',
            action: '先保留服务质量指标；点评标签只在明确启用并通过采集门禁后接入。',
        },
        {
            category: '竞争对比',
            metric: '竞争圈',
            fields: [
                '竞对酒店', '距离', '商圈', '订单占比', '转化率',
                '订单数', '销售榜', '流量榜', '服务榜',
                '流失订单', '流失间夜', '流失金额',
            ],
            source: '携程竞争圈概览、榜单、流失分析和竞品酒店接口；美团排名按平台接口独立表达。',
            status: 'ready',
            statusText: '已归档路径',
            action: '使用 wide/all 采集，不把竞争圈数据当成全市场或全酒店经营口径。',
        },
        {
            category: '广告投放',
            metric: '金字塔',
            fields: [
                '广告曝光', '点击', '点击率', '预订', '转化率',
                '花费', '订单金额', '同行TOP对比', '同行平均对比', '自身排名对比',
            ],
            source: '携程金字塔 CPC 页面/接口已归档；费用、订单金额和同行对比依赖广告账号权限。',
            status: 'partial',
            statusText: '需广告授权',
            action: '广告数据独立进入 advertising 口径，不和自然流量、自然订单混算。',
        },
    ];
    const autoFetchScopeStatusClass = (status) => ({
        ready: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        partial: 'bg-amber-50 text-amber-700 border-amber-100',
        blocked: 'bg-rose-50 text-rose-700 border-rose-100',
        manual: 'bg-slate-50 text-slate-600 border-slate-200',
    }[String(status || '')] || 'bg-slate-50 text-slate-600 border-slate-200');
    const autoFetchModeLabel = (mode, options = autoFetchModeOptions) => {
        const rows = Array.isArray(options) ? options : [];
        const found = rows.find(item => item?.value === mode);
        return found ? found.label : '接口直连自动';
    };
    const formatAutoFetchElapsed = (seconds) => {
        const total = Math.max(0, Number.parseInt(seconds, 10) || 0);
        const minutes = Math.floor(total / 60);
        const remain = total % 60;
        if (minutes <= 0) return `${remain}秒`;
        return `${minutes}分${String(remain).padStart(2, '0')}秒`;
    };
    const formatAutoFetchMs = (ms) => {
        const totalMs = Math.max(0, Number.parseInt(ms, 10) || 0);
        if (totalMs < 1000) return `${totalMs}ms`;
        const seconds = Math.round(totalMs / 1000);
        return formatAutoFetchElapsed(seconds);
    };
    const autoFetchResultStatusText = (row) => {
        if (row?.success) return '成功';
        if (row?.skipped) return '跳过';
        return '失败';
    };
    const autoFetchResultStatusClass = (row) => {
        if (row?.success) return 'bg-green-100 text-green-700 border-green-200';
        if (row?.skipped) return 'bg-gray-100 text-gray-600 border-gray-200';
        return 'bg-red-100 text-red-700 border-red-200';
    };
    const autoFetchModuleLabel = (module) => ({
        business: '经营',
        traffic: '流量',
        ranking: '排名',
        rank: '排名',
        comments: '点评',
        reviews: '点评',
        ads: '广告',
        configuration: '配置',
        cookie_config_tasks: '配置任务',
        day_report_api: '昨日概况',
        browser_profile: '登录会话',
        browser_business: '经营',
        browser_traffic: '流量',
        browser_catalog_standard: '标准字段',
        ranking_api: '排名',
    }[module] || module || '模块');
    const platformProfileMachineText = (value) => /[a-z]+[_-][a-z]+|\/api\/|https?:|[{}[\]=]/i.test(String(value || ''));
    const platformProfileStatusLabel = (item) => {
        const statusCode = String(item?.status_code || '').trim().toLowerCase();
        const map = {
            unconfigured: '未配置',
            waiting_login: '待登录',
            logged_in: '已登录',
            login_expired: '登录失效',
            login_required: '需要登录',
            capture_failed: '采集失败',
            missing_profile: '缺少 Profile',
            needs_profile: '缺少 Profile',
        };
        if (map[statusCode]) return map[statusCode];
        const currentStatus = String(item?.current_status || '').trim();
        if (currentStatus && !platformProfileMachineText(currentStatus)) return currentStatus;
        return '未配置';
    };
    const platformProfileStatusRawText = (item) => [
        `platform=${item?.platform || 'platform_missing'}`,
        `status_code=${item?.status_code || 'status_missing'}`,
        `current_status=${item?.current_status || 'status_text_missing'}`,
        `profile_key=${item?.profile_key || 'profile_key_missing'}`,
    ].join(' / ');
    const platformProfileStatusBadgeClass = (statusCode) => ({
        logged_in: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        waiting_login: 'bg-amber-50 text-amber-700 border-amber-200',
        login_expired: 'bg-red-50 text-red-700 border-red-200',
        capture_failed: 'bg-red-50 text-red-700 border-red-200',
        unconfigured: 'bg-gray-50 text-gray-500 border-gray-200',
    }[statusCode] || 'bg-gray-50 text-gray-500 border-gray-200');
    const platformProfileCheckClass = (status) => ({
        ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        warning: 'bg-amber-50 text-amber-700 border-amber-200',
        missing: 'bg-slate-50 text-slate-600 border-slate-200',
        error: 'bg-red-50 text-red-700 border-red-200',
    }[status] || 'bg-slate-50 text-slate-600 border-slate-200');
    const platformProfileBindingRawText = (item) => {
        const binding = item?.binding || {};
        if (item?.platform === 'ctrip') {
            const profile = binding.profile_id || item.profile_key || '-';
            const hotelId = binding.ctrip_hotel_id || binding.hotel_id || '-';
            const name = binding.hotel_name ? ` / ${binding.hotel_name}` : '';
            return `登录会话 ${profile} / 平台酒店 ${hotelId}${name}`;
        }
        if (item?.platform === 'meituan') {
            const storeId = binding.store_id || item.profile_key || '-';
            const poiId = binding.poi_id || '-';
            const partner = binding.partner_id_configured ? '接口标识已配置' : '接口标识未配置';
            return `美团门店 ${storeId} / 平台门店 ${poiId} / ${partner}`;
        }
        return '-';
    };
    const platformProfileBindingText = (item) => {
        const binding = item?.binding || {};
        if (item?.platform === 'ctrip') {
            const profileConfigured = !!(binding.profile_id || item.profile_key);
            const hotelConfigured = !!(binding.ctrip_hotel_id || binding.hotel_id);
            const name = String(binding.hotel_name || '').trim();
            return [
                profileConfigured ? '登录会话已绑定' : '登录会话未绑定',
                hotelConfigured ? '平台酒店标识已配置' : '平台酒店标识未配置',
                name ? `酒店 ${name}` : '',
            ].filter(Boolean).join(' / ');
        }
        if (item?.platform === 'meituan') {
            const storeConfigured = !!(binding.store_id || item.profile_key);
            const poiConfigured = !!binding.poi_id;
            const partnerConfigured = !!binding.partner_id_configured;
            return [
                storeConfigured ? '美团门店会话已绑定' : '美团门店会话未绑定',
                poiConfigured ? '平台门店标识已配置' : '平台门店标识未配置',
                partnerConfigured ? '接口标识已配置' : '接口标识未配置',
            ].join(' / ');
        }
        return 'Profile 绑定状态待确认';
    };
    const platformProfileStrategyText = (item) => {
        if (item?.platform === 'ctrip') return '登录会话自动采集';
        if (item?.platform === 'meituan') return '平台授权与门店标识；完成登录后可自动同步';
        return '-';
    };
    const platformProfilePrimaryActionText = (item) => {
        if (item?.status_code === 'login_expired') return '重新登录平台账号';
        return item?.platform === 'meituan' ? '登录美团' : '登录携程';
    };
    const platformProfileNextActionText = (item) => {
        const raw = String(item?.next_action || '').trim();
        const statusCode = String(item?.status_code || '').trim().toLowerCase();
        if (['logged_in'].includes(statusCode)) return '授权可用，下一步以目标日入库行证明采集成功';
        if (['waiting_login', 'login_expired', 'login_required'].includes(statusCode) || /login|auth|cookie|登录|授权|过期|失效/i.test(raw)) {
            return '完成或刷新平台登录后，再运行现有自动采集';
        }
        if (['unconfigured', 'missing_profile', 'needs_profile'].includes(statusCode) || /profile|store|poi|hotel|配置|绑定|标识|缺少|missing/i.test(raw)) {
            return '先补齐平台绑定和 Profile，再运行现有采集入口';
        }
        if (/capture|fetch|采集|抓取|失败|failed/i.test(raw)) return '按现有采集入口重试，并保留失败原因';
        if (raw && !platformProfileMachineText(raw)) return raw;
        return '复核平台绑定、登录状态和目标日入库证据';
    };
    const platformProfileLoginTaskText = (task) => {
        if (!task) return '';
        const statusText = String(task.status_text || '').trim();
        const status = String(task.status || '').trim().toLowerCase();
        const message = String(task.message || '').trim();
        const sync = task.after_login_sync || null;
        if (status === 'syncing_after_login' || sync?.status === 'running') return '登录已完成，正在同步目标日 OTA 数据';
        if (sync?.status === 'success' && Number(sync?.saved_count || 0) > 0) return `登录后同步完成，目标日已入库 ${Number(sync.saved_count || 0)} 条`;
        if (sync?.status && sync.status !== 'success' && sync.status !== 'skipped') return `登录已完成，但目标日同步未闭环：${String(sync.message || sync.status).trim()}`;
        const combined = `${statusText} ${status} ${message}`;
        if (/success|done|logged|完成|成功|已登录/i.test(combined)) return '登录任务已完成，请刷新状态并运行现有采集';
        if (/running|pending|wait|启动|等待|处理中|进行中/i.test(combined)) return '登录任务进行中，请在打开的浏览器内完成平台验证';
        if (/fail|error|expired|timeout|失败|错误|超时|过期/i.test(combined)) return '登录任务异常，请重新触发登录并保留失败原因';
        if (message && !platformProfileMachineText(message)) return message;
        return statusText || '登录任务状态待确认';
    };
    const platformProfileLoginTaskRawText = (task) => {
        if (!task) return '';
        return [
            `status=${task.status || 'status_missing'}`,
            `status_text=${task.status_text || 'status_text_missing'}`,
            `message=${task.message || 'message_missing'}`,
            `task_id=${task.task_id || 'task_id_missing'}`,
        ].join(' / ');
    };
    const platformSourceStatusClass = (status) => {
        if (status === 'success' || status === 'ready') return 'bg-emerald-50 text-emerald-700';
        if (status === 'failed') return 'bg-red-50 text-red-700';
        if (status === 'partial_success' || status === 'waiting_config') return 'bg-amber-50 text-amber-700';
        if (status === 'disabled') return 'bg-gray-100 text-gray-500';
        return 'bg-blue-50 text-blue-700';
    };
    const platformTaskStatusClass = (status) => {
        if (status === 'success') return 'bg-emerald-50 text-emerald-700';
        if (status === 'failed') return 'bg-red-50 text-red-700';
        if (status === 'partial_success') return 'bg-amber-50 text-amber-700';
        return 'bg-blue-50 text-blue-700';
    };
    const platformSyncActionText = (message) => {
        const text = String(message || '');
        if (!text) return '';
        if (text.includes('browser_runtime_error=spawn EPERM') || text.includes('browser_runtime_error=spawn EACCES')) {
            return '处理动作：检查服务器/定时任务运行账号是否允许启动浏览器；本次未写入空数据。';
        }
        if (text.includes('login session is not ready') || text.includes('login expired') || text.includes('重新登录')) {
            return '处理动作：重新登录平台账号后再同步。';
        }
        if (text.includes('Profile is not prepared') || text.includes('Profile ID is not configured') || text.includes('store_id is not configured')) {
            return '处理动作：先配置平台账号并完成首次登录。';
        }
        if (text.includes('no business rows') || text.includes('No business rows')) {
            return '处理动作：检查采集页面、接口命中和字段映射；系统不会写入空数据。';
        }
        return '';
    };
    const firstDataConfigValue = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && item !== '');
        return value === undefined ? '' : value;
    };
    const parseDataConfigValue = (value) => {
        if (!value) return {};
        if (typeof value === 'string') {
            try {
                return JSON.parse(value) || {};
            } catch (e) {
                return {};
            }
        }
        return typeof value === 'object' ? value : {};
    };
    const runPostFetchRefresh = (callback, ...args) => {
        try {
            Promise.resolve(callback(...args)).catch(error => {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[auto-fetch-static] post-fetch refresh failed:', error);
                }
            });
        } catch (error) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[auto-fetch-static] post-fetch refresh failed:', error);
            }
        }
    };
    const normalizeDataConfigForForm = (config = {}) => {
        const normalized = { ...config };
        normalized.node_id = firstDataConfigValue(normalized.node_id, normalized.nodeId);
        normalized.nodeId = firstDataConfigValue(normalized.nodeId, normalized.node_id);
        normalized.partner_id = firstDataConfigValue(normalized.partner_id, normalized.partnerId);
        normalized.partnerId = firstDataConfigValue(normalized.partnerId, normalized.partner_id);
        normalized.poi_id = firstDataConfigValue(normalized.poi_id, normalized.poiId);
        normalized.poiId = firstDataConfigValue(normalized.poiId, normalized.poi_id);
        normalized.rank_type = firstDataConfigValue(normalized.rank_type, normalized.rankType, 'P_RZ');
        normalized.rankType = firstDataConfigValue(normalized.rankType, normalized.rank_type);
        normalized.start_date = firstDataConfigValue(normalized.start_date, normalized.startDate);
        normalized.startDate = firstDataConfigValue(normalized.startDate, normalized.start_date);
        normalized.end_date = firstDataConfigValue(normalized.end_date, normalized.endDate);
        normalized.endDate = firstDataConfigValue(normalized.endDate, normalized.end_date);
        normalized.extra_params = firstDataConfigValue(normalized.extra_params, normalized.extraParams);
        normalized.extraParams = firstDataConfigValue(normalized.extraParams, normalized.extra_params);
        normalized.payload_json = firstDataConfigValue(normalized.payload_json, normalized.payloadJson);
        normalized.payloadJson = firstDataConfigValue(normalized.payloadJson, normalized.payload_json);
        normalized.request_urls = firstDataConfigValue(normalized.request_urls, normalized.requestUrls);
        normalized.requestUrls = firstDataConfigValue(normalized.requestUrls, normalized.request_urls);
        normalized.endpoints_json = firstDataConfigValue(normalized.endpoints_json, normalized.endpointsJson);
        normalized.endpointsJson = firstDataConfigValue(normalized.endpointsJson, normalized.endpoints_json);
        normalized.headers_json = firstDataConfigValue(normalized.headers_json, normalized.headersJson);
        normalized.headersJson = firstDataConfigValue(normalized.headersJson, normalized.headers_json);
        normalized.profile_id = firstDataConfigValue(normalized.profile_id, normalized.profileId);
        normalized.profileId = firstDataConfigValue(normalized.profileId, normalized.profile_id);
        normalized.hotel_id = firstDataConfigValue(normalized.hotel_id, normalized.ctrip_hotel_id, normalized.ctripHotelId);
        normalized.ctrip_hotel_id = firstDataConfigValue(normalized.ctrip_hotel_id, normalized.hotel_id);
        normalized.ctripHotelId = firstDataConfigValue(normalized.ctripHotelId, normalized.ctrip_hotel_id);
        normalized.cookies = firstDataConfigValue(normalized.cookies, normalized.cookie);
        normalized.cookie = firstDataConfigValue(normalized.cookie, normalized.cookies);
        normalized.system_hotel_id = firstDataConfigValue(normalized.system_hotel_id, normalized.hotelId);
        normalized.hotelId = firstDataConfigValue(normalized.hotelId, normalized.system_hotel_id);
        return normalized;
    };
    const compactDataConfigBody = (body = {}) => {
        const compacted = {};
        Object.keys(body).forEach(key => {
            const value = body[key];
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value) && value.length === 0) return;
            compacted[key] = value;
        });
        return compacted;
    };
    const normalizeCtripAdsApiType = () => 'effect_report';
    const buildDataConfigRequestBody = (type, input = {}) => {
        const form = normalizeDataConfigForForm(input || {});
        const startDate = firstDataConfigValue(form.start_date, form.startDate);
        const endDate = firstDataConfigValue(form.end_date, form.endDate);
        const systemHotelId = firstDataConfigValue(form.system_hotel_id, form.hotelId);
        const body = { auto_save: false };

        switch (type) {
            case 'ctrip-ebooking':
                Object.assign(body, {
                    url: form.url,
                    node_id: firstDataConfigValue(form.node_id, form.nodeId),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    auth_data: form.auth_data,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-ebooking':
                Object.assign(body, {
                    url: form.url,
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    rank_type: firstDataConfigValue(form.rank_type, form.rankType, 'P_RZ'),
                    data_scope: form.data_scope,
                    date_range: form.date_range,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    auth_data: form.auth_data,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-traffic':
                Object.assign(body, {
                    url: form.url,
                    platform: form.platform || 'Ctrip',
                    date_range: form.date_range || 'yesterday',
                    start_date: startDate,
                    end_date: endDate,
                    spiderkey: form.spiderkey,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-cookie-api':
                Object.assign(body, {
                    request_urls: firstDataConfigValue(form.request_urls, form.requestUrls),
                    endpoints_json: firstDataConfigValue(form.endpoints_json, form.endpointsJson),
                    request_url: firstDataConfigValue(form.request_url, form.url),
                    method: String(form.method || 'GET').toUpperCase(),
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    headers_json: firstDataConfigValue(form.headers_json, form.headersJson),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    profile_id: firstDataConfigValue(form.profile_id, form.profileId),
                    hotel_id: firstDataConfigValue(form.hotel_id, form.ctrip_hotel_id, form.ctripHotelId),
                    node_id: firstDataConfigValue(form.node_id, form.nodeId),
                    data_date: firstDataConfigValue(startDate, endDate),
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-traffic':
                Object.assign(body, {
                    url: form.url,
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    start_date: startDate,
                    end_date: endDate,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'booking-ota':
            case 'agoda-ota':
            case 'expedia-ota':
                Object.assign(body, {
                    platform: form.platform,
                    url: form.url,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-comments':
                Object.assign(body, {
                    request_url: firstDataConfigValue(form.request_url, form.url),
                    hotel_id: firstDataConfigValue(form.hotel_id, form.hotelId),
                    master_hotel_id: form.master_hotel_id,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    spidertoken: form.spidertoken,
                    page_index: form.page_index,
                    page_size: form.page_size,
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    _fxpcqlniredt: form._fxpcqlniredt,
                    x_trace_id: form.x_trace_id,
                    tag_type: form.tag_type,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-comments':
                Object.assign(body, {
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    mtgsig: form.mtgsig,
                    _mtsi_eb_u: form._mtsi_eb_u,
                    reply_type: form.reply_type,
                    tag: form.tag,
                    limit: form.limit,
                    offset: form.offset,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-ads':
                Object.assign(body, {
                    url: form.url,
                    api_type: normalizeCtripAdsApiType(form.api_type),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson, form.extra_params, form.extraParams),
                    date_range: form.date_range,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-ads':
                Object.assign(body, {
                    url: form.url,
                    method: form.method || 'GET',
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId, form.shop_id),
                    shop_id: firstDataConfigValue(form.shop_id, form.shopId, form.poi_id),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    start_date: firstDataConfigValue(form.begin_date, startDate),
                    end_date: endDate,
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            default:
                break;
        }

        return compactDataConfigBody(body);
    };

    const dataConfigTestEndpointMap = {
        'ctrip-ebooking': '/online-data/fetch-ctrip',
        'meituan-ebooking': '/online-data/fetch-meituan',
        'ctrip-traffic': '/online-data/fetch-ctrip-traffic',
        'ctrip-cookie-api': '/online-data/fetch-ctrip-cookie-api',
        'meituan-traffic': '/online-data/fetch-meituan-traffic',
        'ctrip-ads': '/online-data/fetch-ctrip-ads',
        'meituan-ads': '/online-data/fetch-meituan-ads',
    };
    const unsupportedDataConfigTestTypes = new Set(['booking-ota', 'agoda-ota', 'expedia-ota']);
    const resolveDataConfigTestEndpoint = (type = '') => {
        const key = String(type || '');
        if (unsupportedDataConfigTestTypes.has(key)) {
            return {
                status: 'unsupported',
                type: key,
                message: '该平台当前支持配置保存，自动连接测试需后续接入平台接口',
                level: 'info',
            };
        }
        const apiUrl = dataConfigTestEndpointMap[key] || '';
        if (!apiUrl) {
            return {
                status: 'unknown_type',
                type: key,
                message: '未知配置类型',
                level: 'error',
            };
        }
        return { status: 'ready', type: key, apiUrl };
    };
    const buildDataConfigTestRequest = ({
        type = '',
        form = {},
        validateCtripAdsApiUrl = () => true,
        ctripAdsApiUrlHint = '',
    } = {}) => {
        const endpoint = resolveDataConfigTestEndpoint(type);
        if (endpoint.status !== 'ready') return endpoint;
        if (endpoint.type === 'ctrip-ads') {
            const url = String(firstDataConfigValue(form.url, form.request_url, form.requestUrl)).trim();
            if (url && !validateCtripAdsApiUrl(url)) {
                return {
                    status: 'invalid_url',
                    type: endpoint.type,
                    message: ctripAdsApiUrlHint || '接口 URL 不符合携程广告接口要求',
                    level: 'error',
                };
            }
        }
        return {
            status: 'ready',
            type: endpoint.type,
            apiUrl: endpoint.apiUrl,
            body: buildDataConfigRequestBody(endpoint.type, form),
        };
    };
    const runDataConfigTestFlow = async ({
        getType = () => '',
        getForm = () => ({}),
        setTesting = () => {},
        notify = () => {},
        requestTest = async () => ({}),
        validateCtripAdsApiUrl = () => true,
        ctripAdsApiUrlHint = '',
    } = {}) => {
        setTesting(true);
        try {
            const requestContext = buildDataConfigTestRequest({
                type: getType(),
                form: getForm() || {},
                validateCtripAdsApiUrl,
                ctripAdsApiUrlHint,
            });
            if (requestContext.status !== 'ready') {
                notify(requestContext.message, requestContext.level);
                return requestContext;
            }

            const res = await requestTest(requestContext.apiUrl, requestContext.body);
            if (res.code === 200) {
                notify('连接测试成功！数据获取正常');
                return { ...requestContext, status: 'success', response: res };
            }

            notify(res.message || '连接测试失败', 'error');
            return { ...requestContext, status: 'failed', response: res };
        } catch (error) {
            notify('测试失败: ' + error.message, 'error');
            return { status: 'exception', error };
        } finally {
            setTesting(false);
        }
    };

    const buildAutoFetchTriggerRequestBody = ({
        hotelId = '',
        browserHeadless = false,
        modePayload = {},
    } = {}) => ({
        system_hotel_id: hotelId,
        data_period: 'realtime_snapshot',
        interactive_browser: !browserHeadless,
        browser_headless: browserHeadless,
        async: true,
        ...(modePayload || {}),
    });

    const buildAutoFetchRunStartState = ({
        startedAt = '',
        ctripExecutionText = '',
        modePayload = {},
        modeLabel = value => value,
        browserHeadless = false,
    } = {}) => ({
        active: true,
        type: 'running',
        message: `已提交后端执行。${ctripExecutionText}；美团使用${modeLabel(modePayload?.meituan_auto_fetch_mode)}；浏览器${browserHeadless ? '无头运行' : '可视运行'}。`,
        started_at: startedAt,
        finished_at: '',
    });

    const runAutoFetchTriggerFlow = async ({
        getHotelId = () => '',
        hasPlatformFetchConfig = () => false,
        setFetching = () => {},
        startTimer = () => {},
        stopTimer = () => {},
        getTimestamp = () => new Date().toLocaleString('zh-CN', { hour12: false }),
        getBrowserHeadless = () => false,
        getCtripExecutionText = () => '',
        buildModePayload = () => ({}),
        modeLabel = value => value,
        getCtripSectionConcurrency = () => '',
        notify = () => {},
        setRunState = () => {},
        requestAutoFetch = async () => ({}),
        getDurationText = () => '',
        updateLastResult = () => {},
        refreshOnlineData = async () => {},
        refreshOnlineHistory = async () => {},
        refreshLatestCtripData = async () => {},
        openCtripProfileFieldsForReview = async () => {},
        loadAutoFetchStatus = async () => {},
        loadBackendGlobalNotifications = async () => {},
    } = {}) => {
        const hotelId = getHotelId();
        if (!hotelId) {
            notify('请先选择酒店', 'error');
            return { status: 'missing_hotel' };
        }
        if (!hasPlatformFetchConfig(hotelId)) {
            notify('请先在酒店管理中为该酒店保存并关联携程或美团配置', 'error');
            return { status: 'missing_config' };
        }

        setFetching(true);
        startTimer();
        const startedAt = getTimestamp();
        const browserHeadless = !!getBrowserHeadless();
        const modePayload = buildModePayload() || {};
        setRunState(buildAutoFetchRunStartState({
            startedAt,
            ctripExecutionText: getCtripExecutionText(),
            modePayload,
            modeLabel,
            browserHeadless,
        }));
        notify(`正在启动平台抓取：携程 ${getCtripSectionConcurrency()} 页并发 / ${browserHeadless ? '无头' : '可视'}浏览器`, 'info');

        const requestBody = buildAutoFetchTriggerRequestBody({
            hotelId,
            browserHeadless,
            modePayload,
        });
        try {
            const res = await requestAutoFetch(requestBody);
            const finishedAt = getTimestamp();
            const durationText = getDurationText();
            if (res.code === 200) {
                const responseStatus = String(res.data?.status || '').toLowerCase();
                if (['running', 'queued', 'accepted'].includes(responseStatus)) {
                    const message = res.message || `自动获取已提交后台执行（启动耗时 ${durationText}）`;
                    updateLastResult(res, null, message);
                    setRunState({
                        active: true,
                        type: 'running',
                        message,
                        started_at: startedAt,
                        finished_at: '',
                    });
                    notify(message, 'info');
                    runPostFetchRefresh(loadAutoFetchStatus);
                    runPostFetchRefresh(loadBackendGlobalNotifications);
                    return { status: 'accepted', response: res, requestBody };
                }
                const message = `采集完成并入库 ${res.data?.saved_count || 0} 条 OTA 指标行（耗时 ${durationText}）`;
                updateLastResult(res, true, res.message || message);
                setRunState({
                    active: false,
                    type: 'success',
                    message,
                    started_at: startedAt,
                    finished_at: finishedAt,
                });
                notify(message);
                runPostFetchRefresh(refreshOnlineData);
                runPostFetchRefresh(refreshOnlineHistory);
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                runPostFetchRefresh(openCtripProfileFieldsForReview);
                runPostFetchRefresh(loadAutoFetchStatus);
                runPostFetchRefresh(loadBackendGlobalNotifications);
                return { status: 'success', response: res, requestBody };
            }

            const message = `${res.message || '获取失败'}（耗时 ${durationText}）`;
            updateLastResult(res, false, message);
            setRunState({
                active: false,
                type: 'error',
                message,
                started_at: startedAt,
                finished_at: finishedAt,
            });
            notify(message, 'error');
            runPostFetchRefresh(loadAutoFetchStatus);
            runPostFetchRefresh(loadBackendGlobalNotifications);
            return { status: 'error_response', response: res, requestBody };
        } catch (error) {
            const finishedAt = getTimestamp();
            const durationText = getDurationText();
            const message = '获取失败: ' + error.message + `（耗时 ${durationText}）`;
            setRunState({
                active: false,
                type: 'error',
                message,
                started_at: startedAt,
                finished_at: finishedAt,
            });
            notify(message, 'error');
            runPostFetchRefresh(loadAutoFetchStatus);
            runPostFetchRefresh(loadBackendGlobalNotifications);
            return { status: 'exception', error, requestBody };
        } finally {
            stopTimer();
            setFetching(false);
        }
    };

    return {
        autoFetchModeOptions,
        autoFetchCollectionBlueprintRows,
        autoFetchFieldScopeGroups,
        autoFetchScopeStatusClass,
        autoFetchModeLabel,
        formatAutoFetchElapsed,
        formatAutoFetchMs,
        autoFetchResultStatusText,
        autoFetchResultStatusClass,
        autoFetchModuleLabel,
        platformProfileMachineText,
        platformProfileStatusLabel,
        platformProfileStatusRawText,
        platformProfileStatusBadgeClass,
        platformProfileCheckClass,
        platformProfileBindingRawText,
        platformProfileBindingText,
        platformProfileStrategyText,
        platformProfilePrimaryActionText,
        platformProfileNextActionText,
        platformProfileLoginTaskText,
        platformProfileLoginTaskRawText,
        platformSourceStatusClass,
        platformTaskStatusClass,
        platformSyncActionText,
        parseDataConfigValue,
        normalizeDataConfigForForm,
        compactDataConfigBody,
        buildDataConfigRequestBody,
        resolveDataConfigTestEndpoint,
        buildDataConfigTestRequest,
        runDataConfigTestFlow,
        buildAutoFetchTriggerRequestBody,
        buildAutoFetchRunStartState,
        runAutoFetchTriggerFlow,
    };
})();
