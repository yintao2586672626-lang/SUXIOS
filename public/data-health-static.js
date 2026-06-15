window.SUXI_DATA_HEALTH_STATIC = (() => {
    const onlineDataQualityStatusText = (quality) => {
        const status = quality?.status || 'ok';
        if (status === 'error') return '异常';
        if (status === 'warning') return '需复核';
        return '完整';
    };

    const onlineDataQualityStatusClass = (quality) => {
        const status = quality?.status || 'ok';
        if (status === 'error') return 'bg-red-50 text-red-700 border-red-200';
        if (status === 'warning') return 'bg-amber-50 text-amber-700 border-amber-200';
        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
    };

    const onlineDataQualityPromptList = (quality, limit = 3) => {
        const prompts = quality?.prompts || quality?.top_prompts || [];
        return Array.isArray(prompts) ? prompts.filter(Boolean).slice(0, limit) : [];
    };

    const onlineDataQualityScopeText = (quality) => {
        if (!quality) return '质量摘要未加载。';
        const scope = quality.calculation_scope || 'selected_rows';
        const sampleSize = Number(quality.sample_size ?? quality.checked_records ?? 0);
        const totalRecords = Number(quality.total_records ?? sampleSize);
        const page = Number(quality.page || 1);
        if (scope === 'current_page') {
            return `质量摘要仅统计当前第 ${page} 页 ${sampleSize} 条样本，筛选范围共 ${totalRecords} 条，不是全量质量结论。`;
        }
        return `质量摘要统计已加载样本 ${sampleSize} 条。`;
    };

    const autoFetchRecordStatusClass = (status) => {
        if (status === 'success') return 'bg-green-100 text-green-700';
        if (status === 'skipped') return 'bg-gray-200 text-gray-600';
        if (status === 'running') return 'bg-blue-100 text-blue-700';
        if (status === 'pending') return 'bg-amber-100 text-amber-700';
        return 'bg-red-100 text-red-700';
    };

    const buildOnlineHistoryQueryParams = ({ page = 1, pageSize = 20, filter = {} } = {}) => {
        const params = new URLSearchParams({
            page: String(page || 1),
            page_size: String(pageSize || 20),
        });
        const currentFilter = filter || {};
        if (currentFilter.platform && currentFilter.platform !== 'all') {
            params.append('platform', currentFilter.platform);
        }
        if (currentFilter.data_type && currentFilter.data_type !== 'all') {
            params.append('data_type', currentFilter.data_type);
        }
        if (currentFilter.hotel_scope) {
            const hotelScope = String(currentFilter.hotel_scope);
            if (['all', 'mine', 'competitor_avg'].includes(hotelScope)) {
                params.append('hotel_scope', hotelScope);
            } else {
                params.append('hotel_scope', 'hotel');
                params.append('hotel_id', hotelScope);
            }
        }
        if (currentFilter.keyword) {
            params.append('keyword', currentFilter.keyword);
        }
        if (currentFilter.start_date) {
            params.append('start_date', currentFilter.start_date);
        }
        if (currentFilter.end_date) {
            params.append('end_date', currentFilter.end_date);
        }
        return params;
    };

    const buildHotelDataDashboardRequests = ({ selectedHotelId = '', days = 30 } = {}) => {
        const accountParams = new URLSearchParams();
        accountParams.append('days', String(days || 30));
        const portraitParams = new URLSearchParams(accountParams);
        const sourceParams = new URLSearchParams(accountParams);
        if (selectedHotelId) {
            portraitParams.append('hotel_id', selectedHotelId);
        }

        return {
            accountOverviewUrl: `/dashboard/account-overview?${accountParams.toString()}`,
            hotelPortraitUrl: `/dashboard/hotel-portrait?${portraitParams.toString()}`,
            dataSourcesUrl: `/dashboard/data-sources?${sourceParams.toString()}`,
        };
    };

    const collectionHealthCookieLightClass = (row) => ({
        green: 'bg-green-500',
        red: 'bg-red-500',
    }[String(row?.light_status || (row?.is_usable ? 'green' : 'red')).toLowerCase()] || 'bg-red-500');

    const collectionHealthCookieLightText = (row) => row?.light_label || (row?.is_usable ? '可用' : '不可用');

    const dataHealthNormalizeStatus = (status) => {
        const value = String(status || '').toLowerCase();
        if (['ok', 'success'].includes(value)) return 'ok';
        if (['expired', 'failed', 'error', 'auth_failed', 'request_failed'].includes(value)) return 'failed';
        if (['warning', 'partial_success', 'unknown'].includes(value)) return 'warning';
        if (['waiting_config', 'not_collected', 'missing_file'].includes(value)) return 'waiting_config';
        return value || 'unknown';
    };

    const dataHealthPriorityClass = (priority = 'medium') => ({
        high: 'bg-red-50 text-red-700 border-red-200',
        medium: 'bg-amber-50 text-amber-700 border-amber-200',
        low: 'bg-blue-50 text-blue-700 border-blue-200',
        ok: 'bg-green-50 text-green-700 border-green-200',
    }[priority] || 'bg-gray-50 text-gray-600 border-gray-200');

    const dataHealthPriorityText = (priority = 'medium') => ({
        high: '高优先级',
        medium: '中优先级',
        low: '低优先级',
        ok: '正常',
    }[priority] || '待确认');

    const dataHealthPlatformText = (platform = '') => ({
        ctrip: '携程',
        meituan: '美团',
        qunar: '去哪儿',
    }[String(platform || '').toLowerCase()] || (platform || 'OTA'));

    const platformBatchHealthBadgeClass = (level) => ({
        ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        medium: 'bg-amber-50 text-amber-700 border-amber-200',
        high: 'bg-red-50 text-red-700 border-red-200',
        unknown: 'bg-gray-50 text-gray-500 border-gray-200',
    }[level] || 'bg-gray-50 text-gray-500 border-gray-200');

    const platformBatchHealthSourceHotelId = (source) => String(source?.system_hotel_id || source?.hotel_id || '').trim();
    const platformBatchHealthSourceActive = (source) => source?.enabled !== false && Number(source?.enabled ?? 1) !== 0 && String(source?.status || '') !== 'disabled';
    const platformBatchHealthSourceTime = (source) => String(source?.last_sync_time || source?.last_capture_time || source?.update_time || '').trim();

    const buildPlatformBatchHealthRows = ({
        hotelPool = [],
        platformDataSources = [],
        hotelCompetitorSummaries = {},
        getHotelNameById = () => '',
        competitorSummaryReadiness = () => ({}),
        hotelCompetitorSummaryMeta = () => '',
    } = {}) => {
        const safeHotelName = typeof getHotelNameById === 'function' ? getHotelNameById : () => '';
        const safeCompetitorReadiness = typeof competitorSummaryReadiness === 'function' ? competitorSummaryReadiness : () => ({});
        const safeCompetitorMeta = typeof hotelCompetitorSummaryMeta === 'function' ? hotelCompetitorSummaryMeta : () => '';
        const sources = (Array.isArray(platformDataSources) ? platformDataSources : [])
            .filter(platformBatchHealthSourceActive);
        const sourceMap = new Map();
        for (const source of sources) {
            const hotelId = platformBatchHealthSourceHotelId(source);
            if (!hotelId) continue;
            if (!sourceMap.has(hotelId)) sourceMap.set(hotelId, []);
            sourceMap.get(hotelId).push(source);
        }

        return (Array.isArray(hotelPool) ? hotelPool : [])
            .filter(hotel => hotel && hotel.id)
            .slice(0, 50)
            .map((hotel) => {
                const hotelId = String(hotel.id || '').trim();
                const hotelName = hotel.name || hotel.hotel_name || safeHotelName(hotelId) || `酒店 ${hotelId}`;
                const hotelSources = sourceMap.get(hotelId) || [];
                const failedSource = hotelSources.find(source => String(source.last_sync_status || source.status || '') === 'failed');
                const partialSource = hotelSources.find(source => String(source.last_sync_status || source.status || '') === 'partial_success');
                const readySource = hotelSources.find(source => ['success', 'ready'].includes(String(source.last_sync_status || source.status || '')));
                const profileCount = hotelSources.filter(source => String(source.ingestion_method || '') === 'browser_profile').length;
                const apiCount = hotelSources.filter(source => String(source.ingestion_method || '') === 'api').length;
                const latestSyncTime = hotelSources
                    .map(platformBatchHealthSourceTime)
                    .filter(Boolean)
                    .sort()
                    .pop() || '';

                let bindingLevel = 'unknown';
                let bindingText = '待绑定';
                let bindingDetail = '未发现该门店的有效平台数据源';
                if (hotelSources.length > 0) {
                    bindingLevel = profileCount > 0 || apiCount > 0 ? 'ok' : 'medium';
                    bindingText = profileCount > 0 || apiCount > 0 ? '已绑定' : '仅手工/导入';
                    bindingDetail = `Profile ${profileCount} / API ${apiCount} / 数据源 ${hotelSources.length}`;
                }

                let collectionLevel = 'unknown';
                let collectionText = '未采集';
                let collectionDetail = '暂无最近采集证据';
                if (failedSource) {
                    collectionLevel = 'high';
                    collectionText = '采集失败';
                    collectionDetail = failedSource.last_error || failedSource.message || '最近同步失败，需查看同步日志';
                } else if (partialSource) {
                    collectionLevel = 'medium';
                    collectionText = '部分成功';
                    collectionDetail = partialSource.last_error || latestSyncTime || '部分模块缺失，需复核字段和日志';
                } else if (readySource || latestSyncTime) {
                    collectionLevel = 'ok';
                    collectionText = '已采集';
                    collectionDetail = latestSyncTime || '有成功状态，但未返回采集时间';
                } else if (hotelSources.length > 0) {
                    collectionLevel = 'medium';
                    collectionText = '待试采';
                    collectionDetail = '已绑定数据源，暂无试采集结果';
                }

                const competitorSummaryForHotel = hotelCompetitorSummaries?.[hotelId] || null;
                const competitorReadiness = safeCompetitorReadiness(competitorSummaryForHotel, hotel) || {};
                const competitorDetail = competitorReadiness.detail || safeCompetitorMeta(hotel);
                const competitorOk = ['ok', 'success'].includes(String(competitorReadiness.status || ''));

                let actionLevel = 'ok';
                let nextAction = '暂无处理动作';
                if (!hotelSources.length) {
                    actionLevel = 'medium';
                    nextAction = '配置平台账号绑定';
                } else if (failedSource) {
                    actionLevel = 'high';
                    nextAction = '查看同步日志并重试采集';
                } else if (collectionLevel === 'medium') {
                    actionLevel = 'medium';
                    nextAction = '执行一次试采集';
                } else if (!competitorOk) {
                    actionLevel = competitorReadiness.status === 'missing' ? 'medium' : 'high';
                    nextAction = competitorReadiness.next_action || '复核竞对榜单';
                }

                return {
                    key: `platform-batch-health-${hotelId}`,
                    hotelId,
                    hotelName,
                    bindingLevel,
                    bindingText,
                    bindingDetail,
                    collectionLevel,
                    collectionText,
                    collectionDetail,
                    competitorReadiness,
                    competitorDetail,
                    nextAction,
                    actionLevel,
                    evidenceText: latestSyncTime ? `最近采集 ${latestSyncTime}` : '缺少最近采集证据',
                };
            });
    };

    const buildPlatformBatchHealthSummaryCards = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        const unbound = safeRows.filter(row => row.bindingLevel !== 'ok').length;
        const collectionIssues = safeRows.filter(row => row.collectionLevel !== 'ok').length;
        const competitorIssues = safeRows.filter(row => !['ok', 'success'].includes(String(row.competitorReadiness?.status || ''))).length;
        const highActions = safeRows.filter(row => row.actionLevel === 'high').length;
        return [
            { key: 'hotels', label: '体检门店', value: safeRows.length, badge: safeRows.length ? '已加载' : '无门店', level: safeRows.length ? 'ok' : 'unknown' },
            { key: 'binding', label: '绑定待处理', value: unbound, badge: unbound ? '待处理' : '正常', level: unbound ? 'medium' : 'ok' },
            { key: 'collection', label: '采集待处理', value: collectionIssues, badge: collectionIssues ? '待试采' : '正常', level: collectionIssues ? 'medium' : 'ok' },
            { key: 'competitor', label: '竞对待复核', value: competitorIssues + highActions, badge: highActions ? '高优先' : (competitorIssues ? '待复核' : '正常'), level: highActions ? 'high' : (competitorIssues ? 'medium' : 'ok') },
        ];
    };

    const collectionHealthAuthorizationPlatformText = (platform) => {
        const normalized = String(platform || '').trim().toLowerCase();
        if (['ctrip', 'meituan', 'qunar'].includes(normalized)) return dataHealthPlatformText(normalized);
        return normalized ? '未识别 OTA 平台' : 'OTA 平台';
    };
    const collectionHealthAuthorizationMachineText = (value) => /[a-z]+[_-][a-z]+|\/api\/|https?:|[{}[\]=]/i.test(String(value || ''));
    const collectionHealthAuthorizationMessageText = (row = {}) => {
        const raw = String(row?.message || '').trim();
        const status = String(row?.status || '').trim().toLowerCase();
        const actionHint = String(row?.action_hint || '').trim();
        const text = `${raw} ${status} ${actionHint}`;
        if (row?.is_usable || ['ok', 'ready', 'valid', 'success', 'usable', 'active', 'logged_in'].includes(status)) {
            return '授权可用，仍以目标日入库行为采集证明';
        }
        if (['waiting_config', 'missing', 'unbound', 'not_configured', 'profile_missing'].includes(status) || /未配置|缺失|待补|missing|unbound|not[_-]?configured|waiting[_-]?config/i.test(text)) {
            return '授权配置待补齐';
        }
        if (['expired', 'failed', 'auth_failed', 'invalid', 'unauthorized', 'forbidden', 'login_required', 'blocked'].includes(status) || /cookie|login|auth|401|403|unauthorized|forbidden|expired|invalid|登录|授权|过期|失效|异常/i.test(text)) {
            return '授权或登录状态异常，需要重新授权后再采集';
        }
        if (raw && !collectionHealthAuthorizationMachineText(raw)) return raw;
        return '授权状态待确认';
    };
    const collectionHealthAuthorizationActionHintText = (row = {}) => {
        const raw = String(row?.action_hint || '').trim();
        const text = `${raw} ${row?.message || ''} ${row?.status || ''}`;
        if (row?.is_usable || /ready|ok|success|usable|valid|已登录|可用|正常/i.test(text)) {
            return '可作为授权上下文，仍需目标日入库证明';
        }
        if (/missing|unbound|not[_-]?configured|waiting[_-]?config|未配置|缺失|待补/i.test(text)) {
            return '补齐授权配置';
        }
        if (/delete|remove|expired|failed|invalid|401|403|unauthorized|forbidden|cookie|login|auth|删除|重新|登录|授权|过期|失效/i.test(text)) {
            return '重新授权或清理失效记录';
        }
        if (raw && !collectionHealthAuthorizationMachineText(raw)) return raw;
        return '待复核';
    };

    const collectionHealthFailureTypeText = (type) => {
        const raw = String(type || '').trim();
        const normalized = raw.toLowerCase();
        const map = {
            authorization: '授权/登录',
            auth: '授权/登录',
            cookie: '授权 Cookie',
            collection: '采集请求',
            capture: '采集请求',
            browser_profile: '浏览器 Profile',
            data_quality: '数据质量',
            field_missing: '字段缺失',
            request_failed: '请求失败',
            etl: '标准事实层',
            metric: '指标计算',
            unknown: '待确认',
        };
        if (map[normalized]) return map[normalized];
        return raw ? '未识别类型' : '待确认';
    };
    const collectionHealthFailureReasonText = (reason) => {
        const raw = String(reason || '').trim();
        if (!raw) return '失败原因待确认';
        if (/cookie|login|auth|401|403|登录|授权|过期|失效|unauthorized|forbidden/i.test(raw)) {
            return '授权或登录状态异常，需要重新授权后再采集';
        }
        if (/source[_\s-]*rows|target[_\s-]*date|no\s+same|no\s+data|empty|未采集|无数据|入库行缺失/i.test(raw)) {
            return '目标日 OTA 源数据缺失，不能证明当天已采到';
        }
        if (/field|schema|mapping|字段|结构|口径/i.test(raw)) {
            return '字段结构或指标口径异常，需要按字段资产复核';
        }
        if (/traffic|conversion|flow|流量|转化/i.test(raw)) {
            return '流量/转化事实缺失，不能输出确定漏斗判断';
        }
        if (/etl|standard|metric|revenue|标准|收益|指标/i.test(raw)) {
            return '标准事实或收益指标未就绪，需要复核入库与指标输入';
        }
        return raw;
    };
    const collectionHealthFailureNextActionText = (nextAction, item = {}) => {
        const raw = String(nextAction || '').trim();
        const reason = `${raw} ${item?.reason || ''} ${item?.type || ''}`;
        if (/cookie|login|auth|401|403|登录|授权|过期|失效|unauthorized|forbidden/i.test(reason)) {
            return '更新授权或登录状态后，使用现有采集入口重试';
        }
        if (/source[_\s-]*rows|target[_\s-]*date|no\s+same|no\s+data|empty|未采集|无数据|入库行缺失/i.test(reason)) {
            return '补齐目标日 OTA 源数据，再复跑数据健康巡检';
        }
        if (/field|schema|mapping|字段|结构|口径/i.test(reason)) {
            return '按字段资产核对平台返回和入库字段';
        }
        if (/traffic|conversion|flow|流量|转化/i.test(reason)) {
            return '补齐流量/转化事实，再复核收益诊断和 AI 建议';
        }
        if (/etl|standard|metric|revenue|标准|收益|指标/i.test(reason)) {
            return '复核标准事实层和收益指标输入';
        }
        return raw || '检查授权、字段结构和平台接口返回后重试采集';
    };

    const buildCollectionHealthFailureReasonRanking = (failureReasons = [], platformText = dataHealthPlatformText) => {
        const groups = new Map();
        const rows = Array.isArray(failureReasons) ? failureReasons : [];
        rows.forEach((item) => {
            const reason = String(item?.reason || '采集失败原因待确认').trim();
            const key = reason.toLowerCase();
            const platform = platformText(item?.platform);
            const nextAction = String(item?.next_action || '').trim();
            const occurredAt = String(item?.occurred_at || '').trim();
            if (!groups.has(key)) {
                groups.set(key, {
                    key,
                    reason,
                    count: 0,
                    platforms: new Set(),
                    latest_at: '',
                    next_action: '',
                    priority: 'medium',
                });
            }
            const row = groups.get(key);
            row.count += 1;
            if (platform) row.platforms.add(platform);
            if (nextAction && !row.next_action) row.next_action = nextAction;
            if (occurredAt && (!row.latest_at || occurredAt > row.latest_at)) row.latest_at = occurredAt;
            if (String(item?.type || '').toLowerCase() === 'authorization' || /cookie|login|auth|401|403|登录|授权|过期|失效/i.test(reason)) {
                row.priority = 'high';
            }
        });

        return Array.from(groups.values())
            .map(row => ({
                ...row,
                platformsText: Array.from(row.platforms).join(' / ') || 'OTA',
            }))
            .sort((left, right) => {
                const priorityWeight = { high: 0, medium: 1, low: 2 };
                const priorityDiff = (priorityWeight[left.priority] ?? 9) - (priorityWeight[right.priority] ?? 9);
                if (priorityDiff !== 0) return priorityDiff;
                if (right.count !== left.count) return right.count - left.count;
                return String(right.latest_at || '').localeCompare(String(left.latest_at || ''));
            })
            .slice(0, 5);
    };

    const buildDataHealthTodayWorkOrders = ({
        cookieAlertRows = [],
        qualityTaskRows = [],
        highRiskActionRows = [],
        platformText = dataHealthPlatformText,
    } = {}) => {
        const priorityWeight = { high: 0, medium: 1, low: 2, ok: 3 };
        const rows = [];
        (Array.isArray(cookieAlertRows) ? cookieAlertRows : []).forEach((row, index) => {
            rows.push({
                key: `cookie-${index}-${row?.platform || ''}-${row?.hotel_id || ''}-${row?.config_id || row?.name || ''}`,
                priority: row?.priority || 'medium',
                source_label: '授权',
                platform_label: row?.platform_label || platformText(row?.platform),
                title: row?.title || 'OTA 授权待处理',
                detail: row?.message || row?.action_text || 'Cookie 状态异常，需重新授权后再采集。',
                action_type: 'cookie',
            });
        });
        (Array.isArray(qualityTaskRows) ? qualityTaskRows : []).forEach((row, index) => {
            rows.push({
                key: `quality-${index}-${row?.key || row?.title || ''}`,
                priority: row?.priority || 'medium',
                source_label: '数据质量',
                platform_label: row?.platform_label || platformText(row?.platform),
                title: row?.title || '数据质量任务待处理',
                detail: row?.action || '复核授权、字段映射和平台返回。',
                action_type: row?.actionTab ? 'fetch' : 'history',
                action_tab: row?.actionTab || '',
                button_text: row?.actionLabel || '补抓数据',
            });
        });
        (Array.isArray(highRiskActionRows) ? highRiskActionRows : []).forEach((row, index) => {
            rows.push({
                key: `risk-${index}-${row?.id || row?.action || ''}`,
                priority: row?.priority || 'medium',
                source_label: '后台动作',
                platform_label: row?.hotel || '后台',
                title: row?.title || '高风险后台动作待复核',
                detail: row?.error || `${row?.user || '-'} / ${row?.time || '-'}`,
                action_type: 'log',
            });
        });
        const seen = new Set();
        return rows
            .filter(row => {
                const key = `${row.source_label}|${row.platform_label}|${row.title}|${row.detail}`;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .sort((left, right) => (priorityWeight[left.priority] ?? 9) - (priorityWeight[right.priority] ?? 9))
            .slice(0, 8);
    };

    const buildDataHealthDiagnosticBoundary = (fullDiagnosticsLoaded = false) => {
        if (fullDiagnosticsLoaded) {
            return {
                title: '完整诊断已加载',
                detail: '当前已包含账号级驾驶舱、单店画像、数据源诊断、授权、采集失败、字段缺口和后台高风险动作；仍仅代表 OTA 渠道数据质量，不代表全酒店经营口径。',
                badges: ['账号级驾驶舱', '单店画像', '数据源诊断', 'OTA渠道口径'],
                className: 'border-emerald-200 bg-emerald-50 text-emerald-800',
            };
        }

        return {
            title: '当前为轻量刷新',
            detail: '只展示平台授权、采集失败、字段缺口和高风险动作摘要；未拉取账号级驾驶舱、单店画像和数据源完整诊断，缺证据项保持未知状态。',
            badges: ['授权状态', '失败原因', '字段缺口', '高风险动作'],
            className: 'border-amber-200 bg-amber-50 text-amber-800',
        };
    };

    const buildDataHealthCookieAlertRows = (
        authorizationRows = [],
        normalizeStatus = dataHealthNormalizeStatus,
        platformText = dataHealthPlatformText,
    ) => (Array.isArray(authorizationRows) ? authorizationRows : [])
        .filter(row => normalizeStatus(row?.status) !== 'ok')
        .map(row => {
            const status = normalizeStatus(row?.status);
            return {
                ...row,
                priority: status === 'failed' ? 'high' : 'medium',
                status,
                platform_label: platformText(row?.platform),
                title: `${platformText(row?.platform)} / ${row?.name || row?.config_id || '未命名授权'}`,
                message: row?.message || row?.action_hint || '授权状态待复核',
                action_text: row?.next_action || row?.action_hint || '重新授权后刷新数据健康',
            };
        });

    const summarizeDataHealthCookieAlerts = (rows = []) => {
        const safeRows = Array.isArray(rows) ? rows : [];
        return {
            total: safeRows.length,
            high: safeRows.filter(row => row.priority === 'high').length,
            warning: safeRows.filter(row => row.priority !== 'high').length,
        };
    };

    const buildDataHealthQualityTaskRows = ({
        pendingActions = [],
        failureReasons = [],
        dashboardDiagnostics = [],
        ctripMissingActionRows = [],
        normalizeStatus = dataHealthNormalizeStatus,
        platformText = dataHealthPlatformText,
    } = {}) => {
        const rows = [];
        (Array.isArray(pendingActions) ? pendingActions : []).forEach((item, index) => {
            const status = normalizeStatus(item?.status);
            rows.push({
                key: `pending-${index}-${item?.type || ''}-${item?.platform || ''}`,
                priority: status === 'failed' ? 'high' : 'medium',
                type: item?.type || 'pending',
                platform: item?.platform || '',
                platform_label: platformText(item?.platform),
                title: item?.reason || item?.type || '待处理数据质量任务',
                action: item?.action || '复核授权、字段映射和平台返回',
                status,
                actionTab: item?.actionTab || '',
            });
        });
        (Array.isArray(failureReasons) ? failureReasons : []).slice(0, 6).forEach((item, index) => {
            rows.push({
                key: `failure-${index}-${item?.type || ''}-${item?.platform || ''}`,
                priority: 'high',
                type: item?.type || 'failure',
                platform: item?.platform || '',
                platform_label: platformText(item?.platform),
                title: item?.reason || '采集失败原因待处理',
                action: item?.next_action || '先处理失败原因，再重新采集对应模块',
                status: 'failed',
                actionTab: '',
            });
        });
        (Array.isArray(dashboardDiagnostics) ? dashboardDiagnostics : []).slice(0, 6).forEach((item, index) => {
            rows.push({
                key: `dashboard-${index}-${item?.problem || ''}`,
                priority: item?.risk === 'high' || item?.status === 'auth_failed' || item?.status === 'request_failed' ? 'high' : 'medium',
                type: 'dashboard',
                platform: 'ota',
                platform_label: 'OTA',
                title: item?.problem || '数据源诊断',
                action: item?.action || '复核数据源状态',
                status: normalizeStatus(item?.status),
                actionTab: '',
            });
        });
        (Array.isArray(ctripMissingActionRows) ? ctripMissingActionRows : []).slice(0, 8).forEach((item, index) => {
            rows.push({
                key: `ctrip-missing-${index}-${item?.diagnosisType || ''}-${item?.actionTab || ''}`,
                priority: item?.diagnosisType === 'request_failed' || item?.diagnosisType === 'config' ? 'high' : 'medium',
                type: item?.diagnosisType || 'field_missing',
                platform: 'ctrip',
                platform_label: '携程',
                title: `${item?.module || '携程模块'}：${item?.count || 0}项未抓到`,
                action: item?.reasonText || item?.actionLabel || '补抓或复核字段映射',
                status: item?.diagnosisType === 'ok' ? 'ok' : 'warning',
                actionTab: item?.actionTab || '',
            });
        });
        const seen = new Set();
        return rows.filter(row => {
            const key = `${row.type}|${row.platform}|${row.title}|${row.action}`;
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        }).slice(0, 12);
    };

    const buildDataHealthHighRiskActionRows = (operationLogs = []) => (Array.isArray(operationLogs) ? operationLogs : [])
        .map((log) => {
            const action = String(log?.action || '').toLowerCase();
            const module = String(log?.module || '').toLowerCase();
            const backendPriority = String(log?.risk_priority || '').trim();
            const hasError = !!String(log?.error_info || '').trim();
            const isDelete = action.includes('delete') || action.includes('clear') || action.includes('archive');
            const isExecution = action.includes('auto_fetch') || action.includes('sync') || action.includes('execute') || action.includes('approve') || action.includes('apply');
            const isConfig = action.includes('config') || action.includes('save_cookies') || action.includes('save_data_source');
            const isAgent = module === 'agent' || action.includes('analysis') || action.includes('analyze');
            const priority = backendPriority || (hasError || isDelete ? 'high' : (isExecution || isConfig || isAgent ? 'medium' : 'low'));
            return {
                id: log?.id,
                priority,
                module: log?.module || '-',
                action: log?.action || '-',
                title: log?.risk_title || log?.description || `${log?.module || '-'} / ${log?.action || '-'}`,
                user: log?.user?.realname || log?.user?.username || log?.user_name || '-',
                hotel: log?.hotel?.name || log?.hotel_name || '-',
                time: log?.create_time || '-',
                error: log?.error_info || '',
            };
        })
        .filter(row => row.priority !== 'low')
        .slice(0, 8);

    const summarizeDataHealthHighRiskActions = ({
        isSuperAdmin = false,
        loading = false,
        error = '',
        rows = [],
    } = {}) => {
        if (!isSuperAdmin) {
            return {
                status: 'unknown',
                text: '无权限',
                detail: '当前账号无权查看后台高风险动作摘要；未展示不代表暂无风险。',
            };
        }
        if (loading) {
            return { status: 'unknown', text: '加载中', detail: '高风险动作摘要正在加载。' };
        }
        if (error) {
            return { status: 'high', text: '加载失败', detail: error };
        }
        const safeRows = Array.isArray(rows) ? rows : [];
        const hasHigh = safeRows.some(row => row.priority === 'high');
        return {
            status: hasHigh ? 'high' : (safeRows.length ? 'medium' : 'ok'),
            text: safeRows.length ? `${safeRows.length} 项` : '暂无风险',
            detail: safeRows.length ? `${safeRows.length} 项高风险后台动作待复核` : '已加载近 7 天摘要，暂无需要重点复核的高风险动作。',
        };
    };

    const summarizePublicEndpointSecurity = ({
        isSuperAdmin = false,
        loading = false,
        error = '',
        payload = null,
        rows = null,
    } = {}) => {
        if (!isSuperAdmin) {
            return { status: 'unknown', text: '无权限', failureCount: 0, rateLimitedCount: 0, period: {}, scanScope: {} };
        }
        if (loading) {
            return { status: 'unknown', text: '加载中', failureCount: 0, rateLimitedCount: 0, period: {}, scanScope: {} };
        }
        if (error) {
            return { status: 'high', text: '加载失败', failureCount: 0, rateLimitedCount: 0, period: {}, scanScope: {} };
        }
        if (!payload) {
            return { status: 'unknown', text: '未加载', failureCount: 0, rateLimitedCount: 0, period: {}, scanScope: {} };
        }
        const safeRows = Array.isArray(rows) ? rows : (Array.isArray(payload?.endpoints) ? payload.endpoints : []);
        if (!safeRows.length) {
            return { status: 'unknown', text: '未加载', failureCount: 0, rateLimitedCount: 0, period: payload?.period || {}, scanScope: payload?.scan_scope || {} };
        }
        const failureCount = safeRows.reduce((sum, row) => sum + Number(row?.recent_failure_count || 0), 0);
        const rateLimitedCount = safeRows.reduce((sum, row) => sum + Number(row?.rate_limited_count || 0), 0);
        const cron = safeRows.find(row => row?.endpoint === 'cron_trigger') || {};
        const status = cron.token_configured === false ? 'high' : (failureCount > 0 ? 'medium' : 'ok');
        return {
            status,
            text: status === 'high' ? '需处理' : (status === 'medium' ? '有失败' : '暂无风险'),
            failureCount,
            rateLimitedCount,
            period: payload?.period || {},
            scanScope: payload?.scan_scope || {},
        };
    };

    const publicEndpointTokenText = (value) => {
        if (value === true) return '已配置';
        if (value === false) return '未配置';
        return '登录令牌';
    };

    const publicEndpointPathText = (row = {}) => `${row.method || '-'} ${row.path || '-'}`;

    const dashboardStateText = (state) => ({
        ok: '已采集',
        zero: '0',
        null: '空值',
        not_collected: '未采集',
        auth_failed: '授权失败',
        request_failed: '请求失败',
        field_missing: '字段缺失',
        warning: '需复核',
    }[state] || state || '未知');

    const dashboardStateClass = (state) => {
        if (['ok', 'zero'].includes(state)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['warning', 'null', 'field_missing', 'not_collected'].includes(state)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['auth_failed', 'request_failed'].includes(state)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };

    const dashboardMetricText = (metric) => {
        if (!metric) return '-';
        if (metric.state && metric.state !== 'ok' && metric.state !== 'zero') return dashboardStateText(metric.state);
        return metric.display_value ?? metric.value ?? '-';
    };

    const dashboardEvidenceText = (evidence) => {
        if (!evidence) return '-';
        if (typeof evidence === 'string') return evidence;
        try {
            return JSON.stringify(evidence);
        } catch (error) {
            return '-';
        }
    };

    const collectionHealthStatusText = (status) => ({
        ok: '正常',
        success: '成功',
        zero: '0',
        null: 'null',
        not_collected: '未采集',
        auth_failed: '授权失败',
        request_failed: '请求失败',
        field_missing: '字段缺失',
        warning: '预警',
        expired: '授权过期',
        unknown: '未知',
        waiting_config: '待配置',
        failed: '失败',
        partial_success: '部分成功',
        error: '异常',
        no_data: '暂无数据',
    }[status] || status || '未知');

    const collectionHealthStatusClass = (status) => {
        if (['ok', 'success', 'zero'].includes(status)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['warning', 'partial_success', 'waiting_config', 'null', 'not_collected', 'field_missing'].includes(status)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['expired', 'failed', 'error', 'auth_failed', 'request_failed'].includes(status)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };

    const platformCollectionResourceLabel = (resource) => ({
        businessData: '经营核心',
        peerRank: '竞对榜单',
        flowData: '流量漏斗',
        searchKeywords: '搜索词',
        reviewData: '点评摘要',
        roomTypes: '房型目录',
    }[String(resource || '')] || resource || '-');

    const platformCollectionResourceStatusText = (status) => ({
        ready: '可展示',
        stale: '已过期',
        collecting: '采集中',
        failed: '采集失败',
        partial_success: '部分成功',
        login_required: '需登录',
        manual_intervention_required: '需人工处理',
        unbound: '未绑定',
        ready_to_sync: '待同步',
        unknown: '待确认',
    }[String(status || '').toLowerCase()] || collectionHealthStatusText(status));

    const platformCollectionResourceStatusClass = (status) => {
        const normalized = String(status || '').toLowerCase();
        if (['ready', 'stored_displayable', 'fresh', 'authorized'].includes(normalized)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['stale', 'partial_success', 'ready_to_sync', 'not_started', 'pending', 'configured'].includes(normalized)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['failed', 'capture_failed', 'login_required', 'manual_intervention_required'].includes(normalized)) return 'bg-red-50 text-red-700 border-red-100';
        if (['unbound', 'missing', 'not_stored', 'unknown'].includes(normalized)) return 'bg-gray-50 text-gray-600 border-gray-100';
        return collectionHealthStatusClass(normalized);
    };

    const platformCollectionEtlStatusText = (status) => ({
        stored_displayable: '已入库可展示',
        stored_from_previous_task: '历史数据可展示',
        capture_success_not_stored: '采集成功未入库',
        normalized_not_stored: '已解析未入库',
        capture_failed: '采集失败',
        pending: '待入库',
        not_started: '未开始',
        not_stored: '未入库',
    }[String(status || '').toLowerCase()] || status || '-');

    const platformCollectionFreshnessText = (freshness) => ({
        fresh: '有效',
        stale: '超过24小时未更新',
        unknown: '待确认',
        no_data: '暂无数据',
    }[String(freshness || '').toLowerCase()] || freshness || '-');

    const collectionHealthPendingActionPlatformText = (platform) => {
        const parts = String(platform || '').split(/[、,，\s]+/).map(item => item.trim()).filter(Boolean);
        if (!parts.length) return 'OTA 平台';
        return parts.map(dataHealthPlatformText).filter(Boolean).join('、') || 'OTA 平台';
    };

    const collectionHealthPendingActionTypeText = (item) => {
        const type = String(item?.type || '').trim();
        return ({
            authorization: '授权/账号',
            failure_reason: '授权告警',
            collection: '采集状态',
            collection_gap: '源数据缺口',
            field_quality: '字段质量',
        }[type] || '待处理动作');
    };

    const collectionHealthPendingActionText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '复核平台授权、账号/Profile 绑定，并按现有入口重跑同步';
        if (code.startsWith('ota_collection_')) return '复查采集日志、平台响应和授权状态后，按现有手动或自动入口重试';
        if (code === 'ota_same_period_source_rows_missing') return '补齐携程/美团同日期 OTA 入库数据，再复核字段、指标、AI 和执行动作';
        if (code.startsWith('ota_field_quality_')) return '复核缺失字段、原始响应路径和字段映射，缺口继续保留为 data_gaps';
        return String(item?.action || item?.next_action || '').trim() || '查看待处理动作并按数据健康明细复核';
    };

    const collectionHealthPendingActionReasonText = (item) => {
        const code = String(item?.action_code || '').trim();
        const platformText = collectionHealthPendingActionPlatformText(item?.platform);
        if (code.startsWith('ota_authorization_')) return `${platformText}授权或账号上下文需要复核`;
        if (code.startsWith('ota_collection_')) return `${platformText}采集状态不是稳定成功，需要复查失败、部分成功或待配置原因`;
        if (code === 'ota_same_period_source_rows_missing') return '选定周期缺少可证明经营诊断的 OTA 同日期入库数据';
        if (code.startsWith('ota_field_quality_')) return `${platformText}字段质量存在缺口，不能把缺字段指标显示成可信`;
        return String(item?.reason || '').trim();
    };

    const collectionHealthPendingActionEvidenceText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '授权状态、账号/Profile 绑定、重跑同步日志';
        if (code.startsWith('ota_collection_')) return '采集日志、平台响应状态、validation_flags、source_trace_id 或 raw_data';
        if (code === 'ota_same_period_source_rows_missing') return 'online_daily_data 同日期源数据行、data_source_id/sync_task_id、source_trace_id 或 raw_data';
        if (code.startsWith('ota_field_quality_')) return '缺失字段列表、raw_data.field_facts、source_path、metric_key、storage_field、source_trace_id、validation_flags';
        const evidence = Array.isArray(item?.evidence_needed) ? item.evidence_needed : [];
        return evidence.map(value => String(value || '').trim()).filter(Boolean).slice(0, 4).join('、');
    };

    const collectionHealthPendingActionProtectedBoundaryText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '只处理授权和账号绑定；不改变携程/美团采集字段、字段映射或获取逻辑';
        if (code.startsWith('ota_collection_')) return '只复查下游状态和响应证据；不改变携程/美团手动或自动获取逻辑';
        if (code === 'ota_same_period_source_rows_missing') return '不改变采集字段、字段映射或携程/美团获取逻辑；不能用空数据生成经营结论';
        if (code.startsWith('ota_field_quality_')) return '不使用兜底值掩盖字段缺失，不把缺字段指标显示成可信';
        return String(item?.protected_boundary || '').trim();
    };

    const collectionHealthPendingActionOwnerText = (item) => {
        const code = String(item?.action_code || '').trim();
        if (code.startsWith('ota_authorization_')) return '酒店运营人员';
        if (code.startsWith('ota_collection_')) return '产品/技术 + 酒店运营人员';
        if (code === 'ota_same_period_source_rows_missing') return '酒店运营人员';
        if (code.startsWith('ota_field_quality_')) return '产品/技术';
        return String(item?.owner || '').trim();
    };

    const collectionHealthCtripCatalogStatusText = (status) => {
        const raw = String(status || '').trim();
        const normalized = raw.toLowerCase();
        return ({
            pass: '已通过',
            ok: '已通过',
            success: '已通过',
            fail: '未通过',
            failed: '未通过',
            missing: '待验证',
            unknown: '待确认',
            snapshot_ready: '诊断快照可用',
        }[normalized] || (raw ? '待确认' : '待验证'));
    };

    const collectionHealthCtripCatalogAuthStatusText = (status) => {
        const raw = String(status || '').trim();
        const normalized = raw.toLowerCase();
        return ({
            logged_in: '已登录',
            ok: '授权可用',
            ok_or_unverified: '已有授权，登录态待复核',
            login_required: '需要重新登录',
            expired: '登录已失效',
            unknown: '授权待确认',
            snapshot_ready: '诊断快照可用',
        }[normalized] || (raw ? '授权待确认' : '授权待确认'));
    };

    const collectionHealthCtripCatalogCodeText = (value) => {
        const raw = String(value || '').trim();
        const normalized = raw.toLowerCase();
        const directMap = {
            business_overview: '收益经营',
            business_weekly_overview: '周度经营',
            sales_report: '销售报表',
            traffic_report: '流量漏斗',
            competitor_overview: '竞争表现',
            competitor_rank: '竞争圈动态-竞争圈榜单',
            im_board: '用户行为-IM看板',
            quality_psi: '服务质量 PSI',
            ads: '广告投放',
            advertising: '广告投放',
            homepage: '首页快照',
            hotel_homepage: '酒店首页',
            auth_session: '授权会话',
            response_count: '业务响应数',
            standard_rows: '标准入库行',
            endpoint_coverage: '采集规则覆盖',
            field_coverage: '字段覆盖',
            capture_gate_missing: '采集门禁缺失',
            missing_formal_endpoint: '采集规则未命中',
            missing_fields: '字段值缺失',
            no_p3_evidence: '缺少候选证据方向',
        };
        if (directMap[normalized]) return directMap[normalized];
        if (normalized.includes('traffic') || normalized.includes('flow')) return '流量漏斗';
        if (normalized.includes('competitor') || normalized.includes('rank')) return '竞争表现';
        if (normalized.includes('quality') || normalized.includes('psi')) return '服务质量';
        if (normalized.includes('ad')) return '广告投放';
        if (normalized.includes('business') || normalized.includes('sales') || normalized.includes('overview')) return '收益经营';
        if (normalized.includes('auth') || normalized.includes('login')) return '授权会话';
        if (normalized.includes('endpoint')) return '采集规则覆盖';
        if (normalized.includes('field')) return '字段覆盖';
        if (normalized.includes('standard')) return '标准入库行';
        if (normalized.includes('response')) return '业务响应数';
        return raw || '-';
    };

    const collectionHealthCtripCatalogCodeListText = (values) => {
        const list = Array.isArray(values) ? values : (values ? [values] : []);
        const mapped = list.map(collectionHealthCtripCatalogCodeText).filter(Boolean);
        return mapped.length ? Array.from(new Set(mapped)).join('、') : '-';
    };

    const collectionHealthCtripSectionText = (sections) => (
        collectionHealthCtripCatalogCodeListText(sections)
    );

    const collectionHealthCtripCatalogActionReasonText = (reason) => {
        const raw = String(reason || '').trim();
        const normalized = raw.toLowerCase();
        if (!raw) return '';
        if (normalized.includes('auth') || normalized.includes('login') || normalized.includes('cookie')) return '授权或登录态需先恢复';
        if (normalized.includes('endpoint')) return '采集规则未命中，需要补抓对应模块';
        if (normalized.includes('field')) return '字段值未返回，需要复核平台响应';
        if (normalized.includes('evidence')) return '缺少可复核响应证据';
        return raw;
    };

    const collectionHealthCtripModuleStatusText = (status) => ({
        captured: '已抓到',
        needs_mapping: '待映射',
        empty: '无有效数据',
        failed: '抓取失败',
        missing_file: '未抓到',
    }[status] || status || '-');

    const collectionHealthCtripModuleStatusClass = (status) => ({
        captured: 'bg-green-50 text-green-700 border-green-200',
        needs_mapping: 'bg-amber-50 text-amber-700 border-amber-200',
        empty: 'bg-gray-50 text-gray-500 border-gray-200',
        failed: 'bg-red-50 text-red-700 border-red-200',
        missing_file: 'bg-red-50 text-red-700 border-red-200',
    }[status] || 'bg-gray-50 text-gray-500 border-gray-200');

    const collectionHealthCtripShortList = (items, limit = 5) => {
        if (!Array.isArray(items) || !items.length) return '-';
        const head = items.slice(0, limit).join('、');
        return items.length > limit ? `${head} 等 ${items.length} 项` : head;
    };

    const collectionHealthCtripMetricText = (metric) => {
        const examples = Array.isArray(metric?.examples) ? metric.examples.filter(item => item !== null && item !== '') : [];
        const exampleText = examples.length ? examples.slice(0, 3).join(' / ') : '-';
        const count = metric?.count || 0;
        return `样例：${exampleText} · ${count} 次`;
    };

    const collectionHealthCtripValueText = (item) => {
        const value = item?.value === null || item?.value === undefined || item?.value === '' ? '-' : String(item.value);
        const unit = item?.unit ? String(item.unit) : '';
        return unit && value !== '-' ? `${value}${unit}` : value;
    };

    const collectionHealthCtripMetricDisplay = (value, unit = '') => {
        if (value === null || value === undefined || value === '') return '未抓到';
        const numeric = typeof value === 'number' ? value : (isNaN(Number(String(value).replace(/[,￥¥%]/g, ''))) ? null : Number(String(value).replace(/[,￥¥%]/g, '')));
        if (numeric === null) return String(value);
        const formatted = Math.abs(numeric) >= 1000 ? numeric.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) : String(Number(numeric.toFixed(2)));
        return unit ? `${formatted}${unit}` : formatted;
    };

    const collectionHealthCtripNumberValue = (value) => {
        if (value === null || value === undefined || value === '') return null;
        if (typeof value === 'string') {
            value = value.replace(/[,￥¥%]/g, '').trim();
        }
        return isNaN(Number(value)) ? null : Number(value);
    };

    const collectionHealthCtripEffectivenessClass = (status) => ({
        effective: 'bg-green-50 text-green-700 border-green-200',
        needs_mapping: 'bg-amber-50 text-amber-700 border-amber-200',
        missing: 'bg-gray-50 text-gray-500 border-gray-200',
        fresh: 'bg-green-50 text-green-700 border-green-200',
        aging: 'bg-amber-50 text-amber-700 border-amber-200',
        stale: 'bg-red-50 text-red-700 border-red-200',
    }[status] || 'bg-gray-50 text-gray-500 border-gray-200');

    const collectionHealthFieldSourceText = (source) => {
        const raw = String(source || '').trim();
        const map = {
            ctrip: '携程',
            meituan: '美团',
            privacy_boundary: '隐私边界',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别来源' : '未标注来源';
    };

    const collectionHealthFieldModuleText = (module) => {
        const raw = String(module || '').trim();
        const map = {
            business: '经营概况',
            traffic: '流量/转化',
            order: '订单',
            orders: '订单',
            advertising: '广告',
            forbidden: '禁止采集范围',
            privacy_boundary: '隐私边界',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别模块' : '未标注模块';
    };

    const collectionHealthFieldStorageTableText = (storageTable) => {
        const raw = String(storageTable || '').trim();
        const map = {
            online_daily_data: 'OTA 数据入库表',
            not_collected: '不采集/不入库',
        };
        if (map[raw]) return map[raw];
        return raw ? '未识别入库位置' : '未标注入库位置';
    };

    const collectionHealthFieldAssetStatusText = (field) => {
        const status = String(field?.asset_status || '').trim();
        const storageTable = String(field?.storage_table || '').trim();
        if (status === 'forbidden' || storageTable === 'not_collected') return '禁止采集';
        if (status === 'not_returned_visible') return '平台未返回可见';
        if (status === 'stable') return '稳定字段';
        if (field?.required) return '必填字段';
        return '字段定义';
    };

    const collectionHealthFieldAssetStatusClass = (field) => {
        const status = String(field?.asset_status || '').trim();
        const storageTable = String(field?.storage_table || '').trim();
        if (status === 'forbidden' || storageTable === 'not_collected') return 'bg-red-50 text-red-700 border-red-200';
        if (status === 'not_returned_visible') return 'bg-amber-50 text-amber-700 border-amber-200';
        if (status === 'stable') return 'bg-green-50 text-green-700 border-green-200';
        if (field?.required) return 'bg-blue-50 text-blue-700 border-blue-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const collectionHealthFieldAssetListText = (rows) => {
        const items = Array.isArray(rows) ? rows : [];
        if (!items.length) return '-';
        return items
            .slice(0, 4)
            .map(item => item.label || item.field || '-')
            .filter(Boolean)
            .join(' / ') + (items.length > 4 ? ` 等${items.length}项` : '');
    };

    const buildCollectionHealthCtripCatalogCards = (catalog = {}) => {
        const valueOrZero = (key) => catalog[key] || 0;
        return [
            { key: 'sections', label: '覆盖模块', value: `${valueOrZero('section_count')}`, sub: '经营、流量、竞争等' },
            { key: 'rules', label: '采集规则', value: `${valueOrZero('endpoint_count')}`, sub: '可请求的数据接口' },
            { key: 'metrics', label: '指标口径', value: `${valueOrZero('field_count')}`, sub: '已定义核心指标' },
            { key: 'responses', label: '接口响应', value: `${valueOrZero('response_count')}`, sub: valueOrZero('response_count') > 0 ? '本轮已返回' : '本轮未返回' },
            { key: 'rows', label: '入库快照', value: `${valueOrZero('standard_row_count')}`, sub: valueOrZero('standard_row_count') > 0 ? '已形成标准数据' : '未形成标准数据' },
            { key: 'coverage', label: '覆盖率', value: catalog.coverage_rate === null || catalog.coverage_rate === undefined ? '-' : `${catalog.coverage_rate}%`, sub: '按已抓/待补统计' },
        ];
    };

    const collectionHealthCtripCatalogDiagnosticScopeText = () => '经营、流量、竞争、PSI、广告';

    const collectionHealthCtripCatalogAuthText = (catalog = {}) => {
        const authStatus = String(catalog.auth_status || '').toLowerCase();
        if (authStatus === 'login_required') return '需要重新登录';
        return catalog.is_live_capture_ready ? '授权可用' : '待验证';
    };

    const collectionHealthCtripCatalogPendingFetchText = (catalog = {}) => `${catalog.capture_gap_missing_formal_endpoint_count || 0} 项`;

    const collectionHealthCtripCatalogPendingFieldText = (catalog = {}) => `${catalog.capture_gap_missing_field_count || 0} 项`;

    const buildCollectionHealthCtripCatalogVisibleNotes = ({
        diagnosticScope = '',
        authText = '',
        pendingFetchText = '',
        pendingFieldText = '',
    } = {}) => [
        { label: '诊断口径', value: diagnosticScope },
        { label: '授权状态', value: authText },
        { label: '待补采集', value: pendingFetchText },
        { label: '待补字段', value: pendingFieldText },
    ];

    const collectionHealthCtripCatalogActionText = (catalog = {}) => {
        if (!catalog.available) return '等待携程采集目录生成后再判断。';
        if (catalog.is_live_capture_ready) return '';
        const authStatus = String(catalog.auth_status || '').toLowerCase();
        const blockers = Array.isArray(catalog.capture_gap_blockers) ? catalog.capture_gap_blockers : [];
        if (authStatus === 'login_required' || blockers.includes('auth_session')) {
            return 'Cookie 不可用或登录态失效，请先更新携程 Cookie。';
        }
        const missingEndpoints = Number(catalog.capture_gap_missing_formal_endpoint_count || 0);
        const missingFields = Number(catalog.capture_gap_missing_field_count || 0);
        if (missingEndpoints > 0 || missingFields > 0) {
            return '本轮采集不完整，建议重新采集目标门店数据。';
        }
        return '采集状态待确认，请查看失败原因或重新采集。';
    };

    const buildCollectionHealthCtripLatestCards = (latest = {}) => {
        const freshness = latest.freshness || {};
        const effectiveness = latest.effectiveness || {};
        const freshnessValue = freshness.age_hours === null || freshness.age_hours === undefined
            ? (freshness.label || '暂无有效采集')
            : `${freshness.age_hours} 小时`;
        return [
            { key: 'module_count', label: '覆盖模块', value: `${latest.module_count || 0} 个`, sub: '不含订单明细、点评列表' },
            { key: 'response_count', label: '接口响应', value: `${latest.response_count || 0}`, sub: latest.response_count ? '本轮已返回' : '本轮未返回' },
            { key: 'standard_row_count', label: '入库快照', value: `${latest.standard_row_count || 0}`, sub: latest.standard_row_count ? '可用于门店分析' : '未形成标准数据' },
            { key: 'catalog_fact_count', label: '已识别指标', value: `${latest.catalog_fact_count || 0}`, sub: '已提取的字段和值' },
            { key: 'coverage_rate', label: '覆盖率', value: latest.coverage_rate === null || latest.coverage_rate === undefined ? '-' : `${latest.coverage_rate}%`, sub: '按已抓/待补口径统计' },
            { key: 'freshness', label: '实效', value: freshnessValue, sub: effectiveness.label || freshness.label || '需要重新采集' },
        ];
    };

    const buildCollectionHealthCtripOverviewStatusCards = ({
        latest = {},
        persistedCount = 0,
        authState = {},
        latestRow = {},
        identityReport = {},
        identityBlocked = false,
        dataDate = '-',
        capturedAt = '暂无有效采集',
        sourceRowCount = 0,
        moduleCount = 5,
        catalogAuthText = '',
    } = {}) => {
        const effect = latest.effectiveness || {};
        const safePersistedCount = Number(persistedCount || 0);
        const rowCount = identityBlocked ? safePersistedCount : Number(sourceRowCount || 0);
        const statusText = identityBlocked ? '门店身份冲突' : (effect.label || (rowCount > 0 ? '已形成入库快照' : '未形成入库快照'));
        const statusClass = identityBlocked ? 'text-red-700' : (['effective', 'fresh'].includes(String(effect.status || '')) || rowCount > 0 ? 'text-emerald-700' : 'text-amber-700');
        return [
            { key: 'auth', label: '当前授权', value: authState.value, sub: catalogAuthText, className: authState.className },
            { key: 'date', label: '数据日期', value: dataDate || latestRow.data_date || '-', sub: '当前展示口径', className: 'text-gray-900' },
            { key: 'latest', label: '最近采集', value: capturedAt || latestRow.updated_at || '暂无有效采集', sub: latest.freshness?.label || '-', className: 'text-gray-900' },
            { key: 'rows', label: '本轮入库', value: identityBlocked ? `安全 ${safePersistedCount} 条` : (rowCount > 0 ? `${rowCount} 条` : '未形成入库快照'), sub: identityBlocked ? `已过滤 ${identityReport.filtered_count || 0} 条错店风险数据` : 'online_daily_data', className: identityBlocked ? 'text-red-700' : (rowCount > 0 ? 'text-emerald-700' : 'text-amber-700') },
            { key: 'modules', label: '可抓模块', value: '经营 / 流量 / 竞争 / PSI / 广告', sub: `${moduleCount || 5} 个模块`, className: 'text-gray-900' },
            { key: 'status', label: '采集状态', value: statusText, sub: identityBlocked ? '已阻止错店数据展示' : `缺失 ${latest.missing_field_count || 0} 项`, className: statusClass },
        ];
    };

    const buildCtripOverviewFetchModuleCards = (authState = {}) => {
        const disabledLabel = authState.status === 'expired' ? '重新登录后抓取' : '';
        const actionLabel = (label) => disabledLabel || label;
        return [
            { key: 'business', title: '收益经营', subtitle: '订单、间夜、成交率、均价', tab: 'ctrip-flow-overview', icon: 'fas fa-yen-sign', actionLabel: actionLabel('抓取经营') },
            { key: 'traffic', title: '流量漏斗', subtitle: '曝光、访客、下单转化', tab: 'ctrip-traffic', icon: 'fas fa-filter', actionLabel: actionLabel('抓取流量') },
            { key: 'competitor', title: '竞争表现', subtitle: '竞争圈排名、价格排名', tab: 'ctrip-ranking', icon: 'fas fa-trophy', actionLabel: actionLabel('抓取竞争') },
            { key: 'quality', title: '服务质量', subtitle: 'PSI、评分、回复率、收藏数', tab: 'ctrip-quality', icon: 'fas fa-shield-alt', actionLabel: actionLabel('抓取 PSI') },
            { key: 'ads', title: '广告投放', subtitle: '花费、曝光、点击、ROAS', tab: 'ctrip-ads', icon: 'fas fa-bullhorn', actionLabel: actionLabel('抓取广告') },
        ];
    };

    const normalizePhase1MetricDataType = (value) => String(value || '').toLowerCase().trim();
    const phase1TargetDateDataTypes = (row) => Array.isArray(row?.target_date_data_types)
        ? row.target_date_data_types.map(normalizePhase1MetricDataType).filter(Boolean)
        : [];
    const phase1HasAnyDataType = (types, needles) => types.some(type => needles.some(needle => type.includes(needle)));
    const buildPhase1MetricDomainReadiness = ({
        sourceDatePlatformRows = [],
        metricTrustKeys = [],
        hasCompleteTargetDateCoverage = false,
    } = {}) => {
        const platformRows = Array.isArray(sourceDatePlatformRows) ? sourceDatePlatformRows : [];
        const trustKeys = Array.isArray(metricTrustKeys) ? metricTrustKeys.map(item => String(item || '').trim()).filter(Boolean) : [];
        const metricDomainReadiness = platformRows.map(row => {
            const platform = String(row?.platform || '').toLowerCase();
            const targetRows = Math.max(0, Number(row?.target_date_rows || 0));
            const targetTypes = phase1TargetDateDataTypes(row);
            const revenueReady = targetRows > 0 && phase1HasAnyDataType(targetTypes, ['business', 'order', 'orders', 'revenue']);
            const trafficReady = targetRows > 0 && phase1HasAnyDataType(targetTypes, ['traffic', 'flow', 'flow_data']);
            const conversionReady = trafficReady;
            const missingDomains = [];
            if (!revenueReady) missingDomains.push('revenue');
            if (!trafficReady) missingDomains.push('traffic');
            if (!conversionReady) missingDomains.push('conversion');
            return {
                platform,
                target_date_rows: targetRows,
                target_date_data_types: targetTypes,
                revenue_status: revenueReady ? 'ready' : 'missing',
                traffic_status: trafficReady ? 'ready' : 'missing',
                conversion_status: conversionReady ? 'ready' : 'missing',
                missing_domains: missingDomains,
            };
        });
        const revenueReadyPlatforms = metricDomainReadiness.filter(row => row.revenue_status === 'ready').map(row => row.platform).filter(Boolean);
        const trafficReadyPlatforms = metricDomainReadiness.filter(row => row.traffic_status === 'ready').map(row => row.platform).filter(Boolean);
        const conversionReadyPlatforms = metricDomainReadiness.filter(row => row.conversion_status === 'ready').map(row => row.platform).filter(Boolean);
        const revenueMissingPlatforms = metricDomainReadiness.filter(row => row.revenue_status !== 'ready').map(row => row.platform).filter(Boolean);
        const trafficMissingPlatforms = metricDomainReadiness.filter(row => row.traffic_status !== 'ready').map(row => row.platform).filter(Boolean);
        const conversionMissingPlatforms = metricDomainReadiness.filter(row => row.conversion_status !== 'ready').map(row => row.platform).filter(Boolean);
        const metricDomainGapCodes = metricDomainReadiness.flatMap(row => {
            const platform = String(row?.platform || '').toLowerCase();
            if (!platform) return [];
            const codes = [];
            if (row.revenue_status !== 'ready') codes.push(`${platform}_revenue_metric_inputs_missing`);
            if (trustKeys.length === 0 && Number(row?.target_date_rows || 0) > 0) codes.push(`${platform}_metric_trust_missing`);
            if (row.traffic_status !== 'ready') codes.push(`${platform}_traffic_conversion_facts_missing`);
            return codes;
        });
        const platformFieldTrust = metricDomainReadiness.map(row => {
            const platform = String(row?.platform || '').toLowerCase();
            const targetRows = Math.max(0, Number(row?.target_date_rows || 0));
            const revenueReady = row?.revenue_status === 'ready';
            const reasonCodes = [];
            if (targetRows <= 0 && platform) reasonCodes.push(`${platform}_target_date_source_rows_missing`);
            if (!revenueReady && platform) reasonCodes.push(`${platform}_revenue_metric_inputs_missing`);
            if (targetRows > 0 && trustKeys.length === 0 && platform) reasonCodes.push(`${platform}_metric_trust_missing`);
            return {
                platform,
                target_date_rows: targetRows,
                target_date_data_types: Array.isArray(row?.target_date_data_types) ? row.target_date_data_types : [],
                field_trust_status: targetRows <= 0
                    ? 'target_date_source_missing'
                    : (revenueReady ? 'target_date_revenue_sample_present' : 'target_date_metric_inputs_missing'),
                reason_codes: reasonCodes,
                metric_trust_required: true,
                source_policy: 'target_date_rows_field_definitions_metric_trust_required',
            };
        });
        return {
            metricDomainReadiness,
            revenueReadyPlatforms,
            trafficReadyPlatforms,
            conversionReadyPlatforms,
            revenueMissingPlatforms,
            trafficMissingPlatforms,
            conversionMissingPlatforms,
            metricDomainGapCodes,
            platformFieldTrust,
            allMetricDomainsReady: hasCompleteTargetDateCoverage
                && metricDomainReadiness.length > 0
                && trustKeys.length > 0
                && revenueReadyPlatforms.length === metricDomainReadiness.length
                && trafficReadyPlatforms.length === metricDomainReadiness.length
                && conversionReadyPlatforms.length === metricDomainReadiness.length,
        };
    };

    const phase1TrafficActionModeLabel = (mode) => ({
        manual_cookie_api: '手动 Cookie/API',
        browser_profile: '浏览器 Profile',
        status_check: '状态复核',
    }[String(mode || '')] || '');
    const phase1TrafficP0GateLabel = (status) => ({
        ready: 'P0流量已就绪',
        requires_p0_verifier: 'P0待字段复验',
        missing_target_date_traffic_rows: 'P0缺目标日流量',
    }[String(status || '')] || '');
    const phase1TrafficPayloadCandidateLabel = (status) => ({
        missing_expected_payload: '预期Payload缺失',
        expected_payload_present_unverified: 'Payload待dry-run',
        system_hotel_id_missing: '本地酒店范围缺失',
    }[String(status || '')] || '');
    const phase1TrafficPreImportEvidenceLabel = (status) => ({
        not_provided: '预导入证据未提供',
        valid_external_evidence_not_ingested: '外部证据未入库',
        valid_external_evidence_with_ingested_rows: '外部证据已入库',
        external_evidence_not_valid: '外部证据无效',
    }[String(status || '')] || '');
    const phase1TrafficFieldFactLabel = (status) => ({
        no_target_date_traffic_rows: '目标日流量字段未加载',
        requires_p0_verifier: '需复验字段事实',
    }[String(status || '')] || '');
    const buildPhase1TrafficP0NextText = (row = {}) => {
        const gateLabel = phase1TrafficP0GateLabel(row?.p0_traffic_gate_status || '');
        const modeLabel = phase1TrafficActionModeLabel(row?.p0_next_action_mode || row?.recommended_collection_mode || '');
        const controlledEntry = String(row?.p0_next_action_entry || row?.action_entry || '').startsWith('/api/online-data/');
        const noSensitiveCommand = row?.next_command_policy === 'metadata_only_no_sensitive_commands';
        const stepCount = Number(row?.p0_next_step_count || 0);
        const externalEvidenceStatus = String(row?.p0_external_evidence_status || row?.external_evidence_status || 'not_provided');
        const preImportStatus = String(row?.p0_pre_import_evidence_status || row?.pre_import_evidence_status || 'not_provided');
        const preImportPolicy = String(row?.p0_pre_import_evidence_policy || '');
        const trafficFieldFactStatus = String(row?.p0_traffic_field_fact_status || '');
        const sourceChainScope = String(row?.p0_source_chain_scope || '');
        const sourceChainPolicy = String(row?.p0_source_chain_policy || '');
        const targetTrafficDataTypeCount = Array.isArray(row?.p0_target_traffic_data_types) ? row.p0_target_traffic_data_types.length : 0;
        const sourceChainNoTargetRows = sourceChainScope === 'no_target_date_source_rows';
        const sourceChainReferenceOnly = targetTrafficDataTypeCount <= 0
            && (row?.p0_source_chain_reference_only === true
                || sourceChainScope === 'reference_only_non_traffic_source_rows'
                || sourceChainPolicy.includes('reference only'));
        const payloadCandidateCounts = row?.p0_payload_candidate_status_counts && typeof row.p0_payload_candidate_status_counts === 'object'
            ? row.p0_payload_candidate_status_counts
            : {};
        const payloadCandidatePolicy = String(row?.p0_payload_candidate_policy || '');
        const payloadCandidatePayloadPolicy = String(row?.p0_payload_candidate_payload_policy || '');
        const payloadCandidateStoragePolicy = String(row?.p0_payload_candidate_storage_policy || '');
        const payloadCandidateMissingCount = Number(row?.p0_payload_candidate_missing_count || payloadCandidateCounts.missing_expected_payload || 0);
        const payloadCandidateUnverifiedCount = Number(row?.p0_payload_candidate_unverified_count || payloadCandidateCounts.expected_payload_present_unverified || 0);
        const payloadCandidateReadyCount = Number(row?.p0_payload_candidate_ready_count || 0);
        const payloadCandidatePathCount = Array.isArray(row?.p0_payload_candidate_paths) ? row.p0_payload_candidate_paths.length : 0;
        const payloadCandidateIssueCount = Array.isArray(row?.p0_payload_candidate_issue_codes) ? row.p0_payload_candidate_issue_codes.length : 0;
        const requiredMetricCount = Array.isArray(row?.p0_required_metric_keys) ? row.p0_required_metric_keys.length : 0;
        const requiredStorageFieldCount = Array.isArray(row?.p0_required_storage_fields) ? row.p0_required_storage_fields.length : 0;
        const requiredFieldFactCount = Array.isArray(row?.p0_required_field_fact_keys) ? row.p0_required_field_fact_keys.length : 0;
        const missingMetricCount = Array.isArray(row?.p0_missing_metric_keys) ? row.p0_missing_metric_keys.length : 0;
        const fieldLoopMatrix = Array.isArray(row?.p0_field_loop_matrix) ? row.p0_field_loop_matrix : [];
        const unloadedFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'no_target_date_traffic_rows').length;
        const verifierFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'requires_p0_verifier').length;
        const completeFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'complete').length;
        const incompleteFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'incomplete').length;
        const missingFieldLoopCount = fieldLoopMatrix.filter(item => String(item?.status || '') === 'missing').length;
        const closureChain = row?.p0_traffic_closure_chain && typeof row.p0_traffic_closure_chain === 'object'
            ? Object.values(row.p0_traffic_closure_chain)
            : [];
        const closureChainPolicy = String(row?.p0_traffic_closure_chain_policy || '');
        const closureChainNoTargetCount = closureChain.filter(item => String(item?.status || '') === 'no_target_date_traffic_rows').length;
        const closureChainVerifierCount = closureChain.filter(item => String(item?.status || '') === 'requires_p0_verifier').length;
        const closureChainReadyCount = closureChain.filter(item => String(item?.status || '') === 'ready').length;
        const closureChainIncompleteCount = closureChain.filter(item => String(item?.status || '') === 'incomplete').length;
        const platformHotelIdentifierStatus = String(row?.p0_platform_hotel_identifier_status || '');
        const platformHotelIdentifierSource = String(row?.p0_platform_hotel_identifier_source || '');
        const platformHotelIdentifierPolicy = String(row?.p0_platform_hotel_identifier_policy || '');
        const preImportLabel = phase1TrafficPreImportEvidenceLabel(preImportStatus);
        const parts = [];
        if (gateLabel) parts.push(gateLabel);
        if (sourceChainNoTargetRows) parts.push('目标日源数据未入库');
        if (sourceChainReferenceOnly) parts.push('源证据仅参考');
        if (preImportLabel && (preImportStatus !== 'not_provided' || row?.p0_traffic_gate_status !== 'ready')) parts.push(preImportLabel);
        if (externalEvidenceStatus !== 'not_provided' && preImportPolicy.includes('source proof only')) parts.push('证据不等于闭环');
        if (payloadCandidateMissingCount > 0) parts.push(`${phase1TrafficPayloadCandidateLabel('missing_expected_payload')} ${payloadCandidateMissingCount} 项`);
        if (payloadCandidateUnverifiedCount > 0) parts.push(`${phase1TrafficPayloadCandidateLabel('expected_payload_present_unverified')} ${payloadCandidateUnverifiedCount} 项`);
        if (payloadCandidateReadyCount > 0) parts.push(`Payload可执行 ${payloadCandidateReadyCount} 项`);
        if (payloadCandidatePathCount > 0) parts.push(`预期路径 ${payloadCandidatePathCount} 项`);
        if (payloadCandidateIssueCount > 0) parts.push(`Payload缺口 ${payloadCandidateIssueCount} 类`);
        if (payloadCandidatePolicy === 'ui_metadata_only_no_import') parts.push('UI不导入Payload');
        if (payloadCandidatePayloadPolicy === 'path_metadata_only_no_payload_content') parts.push('不展示Payload内容');
        if (payloadCandidateStoragePolicy === 'does_not_write_online_daily_data') parts.push('不写入库表');
        if (requiredMetricCount > 0) parts.push(`需闭环指标 ${requiredMetricCount} 项`);
        if (requiredStorageFieldCount > 0) parts.push(`入库字段 ${requiredStorageFieldCount} 项`);
        if (requiredFieldFactCount > 0) parts.push(`字段事实 ${requiredFieldFactCount} 项`);
        if (fieldLoopMatrix.length > 0) parts.push(`字段矩阵 ${fieldLoopMatrix.length} 项`);
        if (closureChain.length > 0) parts.push(`闭环链 ${closureChain.length} 项`);
        if (closureChainPolicy.includes('OTA-channel evidence only')) parts.push('仅OTA渠道证据');
        if (platformHotelIdentifierStatus === 'no_target_date_traffic_rows') parts.push('平台酒店身份未加载');
        if (platformHotelIdentifierStatus === 'requires_p0_verifier') parts.push('平台酒店身份待复验');
        if (platformHotelIdentifierStatus === 'ready') parts.push('平台酒店身份已证明');
        if (platformHotelIdentifierSource) parts.push(`身份来源 ${platformHotelIdentifierSource}`);
        if (platformHotelIdentifierPolicy.includes('not raw IDs')) parts.push('不展示平台原始ID');
        if (unloadedFieldLoopCount > 0) parts.push(`未加载 ${unloadedFieldLoopCount} 项`);
        if (verifierFieldLoopCount > 0) parts.push(`待复验 ${verifierFieldLoopCount} 项`);
        if (completeFieldLoopCount > 0) parts.push(`完成 ${completeFieldLoopCount} 项`);
        if (incompleteFieldLoopCount > 0) parts.push(`待补 ${incompleteFieldLoopCount} 项`);
        if (missingFieldLoopCount > 0) parts.push(`缺事实 ${missingFieldLoopCount} 项`);
        if (closureChainNoTargetCount > 0) parts.push(`链路未加载 ${closureChainNoTargetCount} 项`);
        if (closureChainVerifierCount > 0) parts.push(`链路待复验 ${closureChainVerifierCount} 项`);
        if (closureChainReadyCount > 0) parts.push(`链路完成 ${closureChainReadyCount} 项`);
        if (closureChainIncompleteCount > 0) parts.push(`链路待补 ${closureChainIncompleteCount} 项`);
        if (missingMetricCount > 0) parts.push(`缺指标 ${missingMetricCount} 项`);
        const fieldFactLabel = phase1TrafficFieldFactLabel(trafficFieldFactStatus);
        if (fieldFactLabel) parts.push(fieldFactLabel);
        if (modeLabel) parts.push(`建议${modeLabel}`);
        if (stepCount > 0) parts.push(`酒店级步骤 ${stepCount} 项`);
        if (controlledEntry && noSensitiveCommand) parts.push('不展示敏感命令');
        return parts.length ? `，${parts.join('，')}` : '';
    };

    const phase1EmployeeQuestionStatusText = (status) => ({
        proved: '已证明',
        warning: '需复核',
        missing: '缺失',
        not_proved: '待证明',
    }[String(status || '')] || '待证明');

    const phase1EmployeeQuestionStatusClass = (status) => ({
        proved: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        warning: 'bg-amber-50 text-amber-700 border-amber-100',
        missing: 'bg-red-50 text-red-700 border-red-100',
        not_proved: 'bg-gray-50 text-gray-600 border-gray-200',
        missing_question: 'bg-red-50 text-red-700 border-red-100',
        request_failed: 'bg-red-50 text-red-700 border-red-100',
    }[String(status || '')] || 'bg-gray-50 text-gray-600 border-gray-200');

    const dailyWorkbenchStatusText = (status) => ({
        complete: '已闭合',
        incomplete: '未闭合',
        empty: '无数据',
        ready: '已加载',
        proved: '已证明',
        warning: '需复核',
        missing: '缺失',
        not_proved: '待证明',
        missing_question: '问题缺失',
        request_failed: '请求失败',
        not_loaded: '未加载',
        unknown: '未知',
    }[String(status || '').toLowerCase()] || phase1EmployeeQuestionStatusText(status));

    const dailyWorkbenchStatusClass = (status) => ({
        complete: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        ready: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        proved: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        incomplete: 'bg-amber-50 text-amber-700 border-amber-100',
        warning: 'bg-amber-50 text-amber-700 border-amber-100',
        not_proved: 'bg-gray-50 text-gray-600 border-gray-200',
        not_loaded: 'bg-gray-50 text-gray-600 border-gray-200',
        empty: 'bg-gray-50 text-gray-600 border-gray-200',
        missing: 'bg-red-50 text-red-700 border-red-100',
        missing_question: 'bg-red-50 text-red-700 border-red-100',
        request_failed: 'bg-red-50 text-red-700 border-red-100',
    }[String(status || '').toLowerCase()] || 'bg-gray-50 text-gray-600 border-gray-200');

    const phase3OperationEffectLoopStatusText = (status) => ({
        patrol_anomaly_confirmed: '异常已确认',
        source_row_missing: '源行缺失',
        action_required: '待执行',
        execution_missing: '缺执行',
        execution_in_progress: '执行中',
        done_without_execution_task: '缺任务证据',
        executed_evidence_recorded: '已举证',
        skipped: '已跳过',
        execution_incomplete: '执行未完',
        review_missing: '待复盘',
        observing: '观察中',
        reviewed: '已复盘',
        candidate: '候选',
        not_ready: '未就绪',
        ready: '已就绪',
        metric_window_missing: '指标不足',
    }[String(status || '').toLowerCase()] || status || '未知');

    const phase3OperationEffectLoopStatusClass = (status) => {
        const normalized = String(status || '').toLowerCase();
        if (['executed_evidence_recorded', 'reviewed', 'candidate', 'ready'].includes(normalized)) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (['action_required', 'execution_in_progress', 'observing'].includes(normalized)) return 'bg-blue-50 text-blue-700 border-blue-100';
        if (['review_missing', 'execution_incomplete', 'not_ready', 'metric_window_missing'].includes(normalized)) return 'bg-amber-50 text-amber-700 border-amber-100';
        if (['execution_missing', 'source_row_missing', 'done_without_execution_task'].includes(normalized)) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const phase1EmployeeActionFamilyText = (family) => ({
        target_date_source_rows: '采集补证',
        standard_facts: '标准事实',
        revenue_metric_inputs: '收益指标',
        traffic_conversion_facts: '流量/转化',
        ai_diagnosis_evidence: 'AI 证据',
        operation_execution_evidence: '执行闭环',
        evidence_scope: '证据范围',
    }[String(family || '').trim()] || String(family || '').trim() || '证据缺口');

    const phase1EmployeeReadinessStatusText = (status) => ({
        ready: '可核对现有状态',
        requires_user_context: '需要先提供授权上下文',
        profile_missing: '未找到本机 Profile',
        profile_found_login_unverified: '发现 Profile，但登录态未验证',
    }[String(status || '').trim()] || String(status || '').trim());

    const phase1EmployeeReadinessEvidenceText = (value) => ({
        user_supplied_cookie_or_payload_required: '需要用户提供 Cookie/Payload/导出上下文',
        storage_profile_directory_count: '只读取本机 Profile 目录数量',
        read_local_profile_directory_names_only: '只读取本机 Profile 目录名',
        read_existing_collection_reliability_only: '只读现有采集可靠性状态',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeQuestionKeyText = (key) => ({
        today_ota_collected: '今天 OTA 数据有没有采到',
        trusted_fields: '哪些字段可信',
        missing_fields: '哪些字段缺失',
        revenue_traffic_conversion: '收入/流量/转化问题',
        ai_evidence: 'AI 建议依据',
        next_operation_action: '下一步执行动作',
    }[String(key || '').trim()] || String(key || '').trim());

    const phase1EmployeePlatformText = (platform) => ({
        ctrip: '携程',
        meituan: '美团',
    }[String(platform || '').toLowerCase()] || String(platform || '').toUpperCase());

    const phase1EmployeeDateRelationText = (relation) => ({
        target_date: '目标日',
        stale_before_target: '早于目标日',
        future_dated_for_target: '晚于目标日',
        none: '未匹配目标日',
    }[String(relation || '').trim()] || String(relation || '').trim());

    const phase1EmployeeActionStatusText = (status) => ({
        missing: '待补证据',
        blocked: '被上游缺口阻断',
        warning: '需复核',
        ready: '可复核',
        proved: '已证明',
    }[String(status || '').trim()] || String(status || '').trim());

    const phase1MetricDomainPlatformText = (platform) => ({
        ctrip: '携程',
        meituan: '美团',
    }[String(platform || '').toLowerCase()] || (platform ? 'OTA 平台' : 'OTA'));

    const phase1MetricDomainDataTypeText = (type) => {
        const raw = String(type || '').toLowerCase();
        if (['business', 'business_overview', 'revenue', 'order', 'orders'].includes(raw)) return '经营/收益';
        if (['traffic', 'flow', 'flow_data'].includes(raw)) return '流量/转化';
        if (['advertising', 'ads'].includes(raw)) return '广告';
        if (['quality', 'quality_psi'].includes(raw)) return '服务质量';
        if (['review', 'comment'].includes(raw)) return '点评';
        return raw ? '未识别数据类型' : '未标注数据类型';
    };

    const phase1MissingFieldDetailText = (code) => ({
        available_room_nights_missing: '缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准。',
        commission_fields_missing: '缺佣金金额或佣金率，暂不能核算净收入和渠道成本。',
        net_revenue_fields_missing: '缺净收入输入，暂不能输出净 RevPAR 或真实到手收入。',
        lead_time_fields_missing: '缺提前预订天数，暂不能判断提前期结构和临近入住风险。',
        cancellation_fields_missing: '缺取消订单或取消金额，暂不能判断取消对收入的影响。',
        cancel_room_nights_missing: '缺取消房晚，暂不能计算房晚取消率。',
        competitor_price_fields_missing: '缺竞品价格，暂不能做竞品价差和调价判断。',
    }[String(code || '').trim()] || '该缺口需要补齐字段定义或目标日样本后再判断。');

    const phase1EmployeeEvidenceStatusText = (value) => ({
        proved: '已证明',
        ready: '已就绪',
        warning: '需复核',
        missing: '缺失',
        blocked: '被阻断',
        pending: '待处理',
        incomplete: '未完成',
        unknown: '未知',
        ok: '正常',
        normal: '正常',
        empty: '空数据',
        partial: '部分就绪',
        blocked_by_verified_ota_gaps: '已验证 OTA 缺口阻断',
        blocked_by_missing_ota_data: '缺少 OTA 数据阻断',
        ai_action_items_blocked: 'AI 动作项被上游证据阻断',
        ai_action_items_missing: 'AI 动作项缺失',
        operation_execution_sample_missing: '运营执行样例缺失',
        operation_execution_ai_action_link_missing: '运营执行未关联 OTA 诊断动作',
        operation_execution_evidence_incomplete: '运营执行证据不完整',
        missing_real_api_response: '缺少真实接口响应',
        missing_real_ota_diagnosis_response: '缺少真实 OTA 诊断响应',
        read_existing_ota_gap_evidence_only: '只读 OTA 缺口证据',
        read_existing_collection_reliability_only: '只读采集可靠性状态',
        read_existing_online_daily_data_only: '只读 online_daily_data 入库状态',
        read_existing_ota_standard_revenue_metrics_only: '只读 OTA 标准收益指标',
        read_existing_operation_execution_state_only: '只读运营执行状态',
        local_ui_derived_from_employee_questions: '前端根据员工六问派生',
        target_date_rows_field_definitions_metric_trust_required: '目标日源数据 + 字段定义 + 指标可信证据',
        target_date_rows_plus_metric_trust_required: '目标日源数据 + 指标可信证据',
        generated_blocked_from_verified_missing_requirements: '由已验证缺口生成的阻断状态',
        user_supplied_cookie_or_payload_required: '需要用户提供授权上下文',
        storage_profile_directory_count: '只读本机 Profile 目录数量',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1FieldTrustStatusText = (status) => ({
        metric_trust_ready: '可复核',
        target_date_revenue_sample_present: '待指标可信证据',
        target_date_metric_inputs_missing: '指标缺失',
        target_date_source_missing: '源数据缺失',
    }[String(status || '').toLowerCase()] || '未证明');

    const phase1EmployeeEvidencePolicyText = (value) => ({
        read_existing_ota_gap_evidence_only: '只读现有 OTA 缺口证据',
        read_existing_collection_reliability_only: '只读现有采集可靠性状态',
        read_existing_online_daily_data_only: '只读 OTA 入库状态',
        read_existing_ota_standard_revenue_metrics_only: '只读 OTA 标准收益指标',
        read_existing_operation_execution_state_only: '只读运营执行状态',
        local_ui_derived_from_employee_questions: '前端根据员工六问派生',
        target_date_rows_field_definitions_metric_trust_required: '目标日源数据 + 字段定义 + 指标可信证据',
        target_date_rows_plus_metric_trust_required: '目标日源数据 + 指标可信证据',
        read_target_date_online_daily_data_types_only: '只读目标日 OTA 指标域',
        read_platform_data_sources_metadata_only: '只读平台采集源元数据',
        requires_target_date_rows_field_definitions_metric_trust_and_data_quality: '需要目标日源数据、字段定义、指标可信和数据质量证据',
        generated_blocked_from_verified_missing_requirements: '由已验证缺口生成的阻断状态',
        user_supplied_cookie_or_payload_required: '需要用户提供授权上下文',
        storage_profile_directory_count: '只读本机 Profile 目录数量',
        read_local_profile_directory_names_only: '只读本机 Profile 目录名',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeStorageTableText = (value) => ({
        online_daily_data: 'OTA 入库表',
    }[String(value || '').trim()] || String(value || '').trim());

    const phase1EmployeeGapCodeText = (code, knownQuestionText = () => '') => {
        const raw = String(code || '').trim();
        if (!raw) return '';
        const questionText = typeof knownQuestionText === 'function' ? knownQuestionText(raw) : '';
        if (questionText) return questionText;
        return ({
            source_date_evidence_missing: '目标日来源证据缺失',
            target_date_source_rows_missing: '目标日 OTA 源数据缺失',
            ctrip_source_rows_missing: '携程目标日源数据缺失',
            meituan_source_rows_missing: '美团目标日源数据缺失',
            ctrip_target_date_source_rows_missing: '携程目标日源数据缺失',
            meituan_target_date_source_rows_missing: '美团目标日源数据缺失',
            ctrip_etl_not_ready: '携程标准事实层未就绪',
            meituan_etl_not_ready: '美团标准事实层未就绪',
            ctrip_revenue_metrics_not_ready: '携程收益指标未就绪',
            meituan_revenue_metrics_not_ready: '美团收益指标未就绪',
            ctrip_traffic_facts_missing: '携程流量事实缺失',
            meituan_traffic_facts_missing: '美团流量事实缺失',
            ctrip_conversion_facts_missing: '携程转化事实缺失',
            meituan_conversion_facts_missing: '美团转化事实缺失',
            ctrip_metric_trust_missing: '携程指标可信证据缺失',
            meituan_metric_trust_missing: '美团指标可信证据缺失',
            ctrip_revenue_metric_inputs_missing: '携程收益指标输入缺失',
            meituan_revenue_metric_inputs_missing: '美团收益指标输入缺失',
            metric_trust_not_loaded: '指标可信证据未加载',
            target_date_metric_inputs_missing: '目标日指标输入缺失',
            target_date_revenue_sample_missing: '目标日收益样本缺失',
            field_definitions_missing: '字段资产定义缺失',
            field_definition_keys_missing: '字段定义键缺失',
            missing_field_codes_missing: '缺失字段码未返回',
            data_gap_codes_missing: '数据缺口码未返回',
            ai_evidence_sources_missing: 'AI 证据来源缺失',
            ai_data_gaps_missing: 'AI 数据缺口字段缺失',
            ai_action_items_missing: 'AI 动作项缺失',
            ai_action_items_blocked: 'AI 动作项被上游证据阻断',
            blocked_by_verified_ota_gaps: '已验证 OTA 缺口阻断',
            operation_execution_sample_missing: '运营执行样例缺失',
            operation_execution_ai_action_link_missing: '运营执行未关联 OTA 诊断动作',
            operation_execution_evidence_incomplete: '运营执行证据不完整',
            evidence_scope_date_mismatch: '证据日期范围不一致',
            latest_available_reference_only: '只有历史或其他日期参考数据',
            online_daily_data_target_date_rows_missing: 'online_daily_data 目标日入库行缺失',
            read_existing_ota_gap_evidence_only: '只读现有 OTA 缺口证据',
        }[raw] || '未识别证据缺口');
    };

    const phase1EmployeeActionCodeText = (code, helpers = {}) => {
        const raw = String(code || '').trim();
        if (!raw) return '';
        const knownQuestionText = typeof helpers.knownQuestionText === 'function' ? helpers.knownQuestionText : () => '';
        const platformText = typeof helpers.platformText === 'function' ? helpers.platformText : value => String(value || '').toUpperCase();
        if (raw === 'phase1_confirm_source_date_evidence') return '核对目标日 OTA 入库证据';
        if (raw === 'phase1_collect_ai_diagnosis_evidence' || raw === 'collect_ai_diagnosis_evidence') return '补齐 AI 诊断证据';
        if (raw === 'resolve_ai_diagnosis_blocked_action_items') return '先解除 AI 动作项阻断';
        if (raw === 'phase1_create_operation_execution_evidence' || raw === 'collect_operation_execution_evidence') return '补齐运营执行证据';
        const localMatch = raw.match(/^local_(.+)_required_action$/);
        if (localMatch) return `补齐${knownQuestionText(localMatch[1]) || '未识别员工问题'}证据`;
        const targetRowsMatch = raw.match(/^phase1_collect_(ctrip|meituan)_target_date_source_rows$/);
        if (targetRowsMatch) return `补齐${platformText(targetRowsMatch[1])}目标日源数据`;
        const sourceRowsMatch = raw.match(/^(ctrip|meituan)_source_rows_missing_collect_existing_path$/);
        if (sourceRowsMatch) return `使用现有${platformText(sourceRowsMatch[1])}入口补齐目标日源数据`;
        const standardFactsMatch = raw.match(/^(ctrip|meituan)_etl_not_ready_check_standard_facts$/);
        if (standardFactsMatch) return `复核${platformText(standardFactsMatch[1])}标准事实层`;
        const revenueMetricMatch = raw.match(/^(?:phase1_(?:check|confirm)_(ctrip|meituan)_revenue_metric_inputs|(ctrip|meituan)_revenue_metrics_not_ready_check_metric_inputs)$/);
        if (revenueMetricMatch) return `复核${platformText(revenueMetricMatch[1] || revenueMetricMatch[2])}收益指标输入`;
        const trafficMatch = raw.match(/^(?:phase1_confirm_(ctrip|meituan)_traffic_conversion_facts|(ctrip|meituan)_traffic_facts_missing_confirm_traffic_collection)$/);
        if (trafficMatch) return `核对${platformText(trafficMatch[1] || trafficMatch[2])}流量/转化采集证据`;
        return '未识别补证动作';
    };

    const onlineAnalysisSourceText = (source) => {
        if (source === 'ctrip') return '携程';
        if (source === 'meituan') return '美团';
        return source || '-';
    };

    const onlineAnalysisDataTypeText = (type) => ({
        business: '经营',
        traffic: '流量',
        rank: '排名',
        advertising: '广告',
        review: '点评',
        quality: '服务质量',
        service: '服务',
        service_quality: '服务质量',
        psi: 'PSI',
    }[type] || type || '-');

    const buildOnlineAnalysisSummaryCards = (summary = {}, dimension = 'day', formatNumber = value => String(value ?? '')) => [
        {
            key: 'amount',
            label: 'OTA销售额',
            value: `¥${formatNumber(summary.total_amount || 0)}`,
            sub: `${dimension === 'day' ? '日' : dimension === 'week' ? '周' : '月'}维度汇总`,
            className: 'text-emerald-700',
        },
        {
            key: 'quantity',
            label: 'OTA间夜',
            value: formatNumber(summary.total_quantity || 0),
            sub: `均值 ${formatNumber(summary.avg_quantity || 0)}`,
            className: 'text-blue-700',
        },
        {
            key: 'orders',
            label: 'OTA订单',
            value: formatNumber(summary.total_orders || 0),
            sub: `评分 ${formatNumber(summary.avg_score || 0)}`,
            className: 'text-amber-700',
        },
        {
            key: 'metric_value',
            label: '指标值',
            value: formatNumber(summary.total_data_value || 0),
            sub: '流量/排名/服务等扩展指标',
            className: 'text-indigo-700',
        },
        {
            key: 'records',
            label: '入库事实行',
            value: formatNumber(summary.total_record_count || 0),
            sub: 'online_daily_data',
            className: 'text-slate-900',
        },
        {
            key: 'hotels',
            label: '覆盖酒店',
            value: formatNumber(summary.hotel_count || 0),
            sub: summary.latest_data_date ? `最新 ${summary.latest_data_date}` : '暂无日期',
            className: 'text-gray-700',
        },
    ];

    const buildOnlineAnalysisMetricDefinitionRows = (hasSamples = false) => [
        {
            key: 'ota_revenue',
            label: 'OTA销售额',
            formula: '来自 online_daily_data.amount 汇总，仅表示已入库 OTA 渠道销售额。',
            source: '来源：携程/美团已授权采集结果；不等同于全酒店总营收。',
            status: hasSamples ? '有样本' : '待样本',
            className: hasSamples ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-500 border-gray-200',
        },
        {
            key: 'room_nights',
            label: 'OTA间夜',
            formula: '来自 quantity / room_nights 类字段汇总；缺字段时保留缺失状态。',
            source: '来源：经营、订单、销售报告中已映射字段。',
            status: '需字段命中',
            className: 'bg-amber-50 text-amber-700 border-amber-100',
        },
        {
            key: 'adr',
            label: 'ADR',
            formula: '优先展示采集字段；无稳定字段时不使用销售额/间夜倒推替代。',
            source: '来源：房价、平均卖价、实时起价等 OTA 字段。',
            status: '口径复核',
            className: 'bg-blue-50 text-blue-700 border-blue-100',
        },
        {
            key: 'conversion',
            label: '流量转化',
            formula: '曝光、访客、下单、成交分层展示；不同漏斗层不混算。',
            source: '来源：流量报告、竞争圈、广告模块的独立数据域。',
            status: '分层展示',
            className: 'bg-indigo-50 text-indigo-700 border-indigo-100',
        },
    ];

    const onlineAnalysisFieldFactStatus = (item) => (
        item?.field_fact_status && typeof item.field_fact_status === 'object'
            ? item.field_fact_status
            : { status: 'not_loaded', label: '字段事实未写入', detail: '未返回 field_fact_status' }
    );

    const onlineAnalysisP0CaptureEvidenceStatus = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const fieldFactStatus = String(status.status || 'not_loaded');
        const captured = Number(status.captured_count || 0);
        const looseEvidence = Number(status.capture_evidence_count || 0);
        const desensitizedEvidence = Number(status.desensitized_capture_evidence_count || 0);
        if (fieldFactStatus === 'not_loaded') {
            return {
                status: 'not_loaded',
                label: 'P0证据未写入',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        if (captured <= 0) {
            return {
                status: 'missing',
                label: 'P0证据缺失',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        if (desensitizedEvidence >= captured) {
            return {
                status: 'ready',
                label: 'P0证据就绪',
                captured,
                looseEvidence,
                desensitizedEvidence,
            };
        }
        return {
            status: looseEvidence > 0 ? 'partial' : 'missing',
            label: looseEvidence > 0 ? 'P0证据待补' : 'P0证据缺失',
            captured,
            looseEvidence,
            desensitizedEvidence,
        };
    };

    const onlineAnalysisP0CaptureEvidenceStatusText = (item) => {
        const status = onlineAnalysisP0CaptureEvidenceStatus(item);
        if (status.captured <= 0) return status.label;
        return `${status.label} ${status.desensitizedEvidence}/${status.captured}`;
    };

    const onlineAnalysisP0CaptureEvidenceStatusClass = (item) => {
        const status = String(onlineAnalysisP0CaptureEvidenceStatus(item).status || 'not_loaded');
        const base = 'inline-flex max-w-[9rem] items-center justify-center rounded-full border px-2 py-0.5 text-[11px] leading-4';
        if (status === 'ready') return `${base} border-emerald-100 bg-emerald-50 text-emerald-700`;
        if (status === 'partial') return `${base} border-amber-100 bg-amber-50 text-amber-700`;
        if (status === 'missing') return `${base} border-red-100 bg-red-50 text-red-700`;
        return `${base} border-slate-200 bg-slate-50 text-slate-500`;
    };

    const onlineAnalysisP0CaptureEvidenceDetailText = (item) => {
        const status = onlineAnalysisP0CaptureEvidenceStatus(item);
        const parts = [
            `脱敏采集证据 source_trace_id + source_url_hash ${status.desensitizedEvidence}/${status.captured}`,
            `普通采集证据 ${status.looseEvidence}`,
        ];
        if (status.status !== 'ready') {
            parts.push('P0闭环需每个 metric 具备脱敏 trace 与 source URL hash');
        }
        return parts.join('；');
    };

    const onlineAnalysisFieldFactStatusText = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const label = String(status.label || '').trim() || '字段事实';
        const captured = Number(status.captured_count || 0);
        const missing = Number(status.missing_count || 0);
        if (status.status === 'not_loaded') return label;
        return missing > 0 ? `${label} ${captured}/${captured + missing}` : `${label} ${captured}`;
    };

    const onlineAnalysisFieldFactStatusClass = (item) => {
        const status = String(onlineAnalysisFieldFactStatus(item).status || 'not_loaded');
        const base = 'inline-flex max-w-[9rem] items-center justify-center rounded-full border px-2 py-0.5 text-[11px] leading-4';
        if (status === 'ready') return `${base} border-emerald-100 bg-emerald-50 text-emerald-700`;
        if (status === 'partial') return `${base} border-amber-100 bg-amber-50 text-amber-700`;
        if (status === 'missing') return `${base} border-red-100 bg-red-50 text-red-700`;
        return `${base} border-slate-200 bg-slate-50 text-slate-500`;
    };

    const onlineAnalysisFieldFactDetailText = (item) => {
        const status = onlineAnalysisFieldFactStatus(item);
        const detail = String(status.detail || '').trim();
        const capturedKeys = Array.isArray(status.captured_metric_keys) ? status.captured_metric_keys : [];
        const missingKeys = Array.isArray(status.missing_metric_keys) ? status.missing_metric_keys : [];
        const storedPresent = Number(status.stored_value_present_count || 0);
        const storedMissing = Number(status.stored_value_missing_count || 0);
        const parts = [];
        if (detail) parts.push(detail);
        if (storedPresent > 0 || storedMissing > 0) parts.push(`入库值 ${storedPresent}/${storedPresent + storedMissing}`);
        if (capturedKeys.length) parts.push(`已闭环 ${capturedKeys.slice(0, 6).join('、')}`);
        if (missingKeys.length) parts.push(`缺失 ${missingKeys.slice(0, 6).join('、')}`);
        return parts.join('；') || '字段事实未写入';
    };

    const buildOnlineAnalysisChartConfig = (chartData) => ({
        type: 'line',
        data: chartData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: {
                y: { type: 'linear', display: true, position: 'left', title: { display: true, text: '销售额(¥)' } },
                y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: '房晚/订单' }, grid: { drawOnChartArea: false } },
            },
        },
    });

    return {
        onlineDataQualityStatusText,
        onlineDataQualityStatusClass,
        onlineDataQualityPromptList,
        onlineDataQualityScopeText,
        autoFetchRecordStatusClass,
        buildOnlineHistoryQueryParams,
        buildHotelDataDashboardRequests,
        collectionHealthCookieLightClass,
        collectionHealthCookieLightText,
        dataHealthNormalizeStatus,
        dataHealthPriorityClass,
        dataHealthPriorityText,
        dataHealthPlatformText,
        collectionHealthAuthorizationPlatformText,
        collectionHealthAuthorizationMessageText,
        collectionHealthAuthorizationActionHintText,
        collectionHealthFailureTypeText,
        collectionHealthFailureReasonText,
        collectionHealthFailureNextActionText,
        platformBatchHealthBadgeClass,
        buildPlatformBatchHealthRows,
        buildPlatformBatchHealthSummaryCards,
        buildCollectionHealthFailureReasonRanking,
        buildDataHealthTodayWorkOrders,
        buildDataHealthDiagnosticBoundary,
        buildDataHealthCookieAlertRows,
        summarizeDataHealthCookieAlerts,
        buildDataHealthQualityTaskRows,
        buildDataHealthHighRiskActionRows,
        summarizeDataHealthHighRiskActions,
        summarizePublicEndpointSecurity,
        publicEndpointTokenText,
        publicEndpointPathText,
        dashboardStateText,
        dashboardStateClass,
        dashboardMetricText,
        dashboardEvidenceText,
        collectionHealthStatusText,
        collectionHealthStatusClass,
        platformCollectionResourceLabel,
        platformCollectionResourceStatusText,
        platformCollectionResourceStatusClass,
        platformCollectionEtlStatusText,
        platformCollectionFreshnessText,
        collectionHealthPendingActionPlatformText,
        collectionHealthPendingActionTypeText,
        collectionHealthPendingActionText,
        collectionHealthPendingActionReasonText,
        collectionHealthPendingActionEvidenceText,
        collectionHealthPendingActionProtectedBoundaryText,
        collectionHealthPendingActionOwnerText,
        collectionHealthCtripCatalogStatusText,
        collectionHealthCtripCatalogAuthStatusText,
        collectionHealthCtripCatalogCodeText,
        collectionHealthCtripCatalogCodeListText,
        collectionHealthCtripSectionText,
        collectionHealthCtripCatalogActionReasonText,
        collectionHealthCtripModuleStatusText,
        collectionHealthCtripModuleStatusClass,
        collectionHealthCtripShortList,
        collectionHealthCtripMetricText,
        collectionHealthCtripValueText,
        collectionHealthCtripMetricDisplay,
        collectionHealthCtripNumberValue,
        collectionHealthCtripEffectivenessClass,
        collectionHealthFieldSourceText,
        collectionHealthFieldModuleText,
        collectionHealthFieldStorageTableText,
        collectionHealthFieldAssetStatusText,
        collectionHealthFieldAssetStatusClass,
        collectionHealthFieldAssetListText,
        buildCollectionHealthCtripCatalogCards,
        collectionHealthCtripCatalogDiagnosticScopeText,
        collectionHealthCtripCatalogAuthText,
        collectionHealthCtripCatalogPendingFetchText,
        collectionHealthCtripCatalogPendingFieldText,
        buildCollectionHealthCtripCatalogVisibleNotes,
        collectionHealthCtripCatalogActionText,
        buildCollectionHealthCtripLatestCards,
        buildCollectionHealthCtripOverviewStatusCards,
        buildCtripOverviewFetchModuleCards,
        buildPhase1MetricDomainReadiness,
        buildPhase1TrafficP0NextText,
        phase1EmployeeQuestionStatusText,
        phase1EmployeeQuestionStatusClass,
        dailyWorkbenchStatusText,
        dailyWorkbenchStatusClass,
        phase3OperationEffectLoopStatusText,
        phase3OperationEffectLoopStatusClass,
        phase1EmployeeActionFamilyText,
        phase1EmployeeReadinessStatusText,
        phase1EmployeeReadinessEvidenceText,
        phase1EmployeeQuestionKeyText,
        phase1EmployeePlatformText,
        phase1EmployeeDateRelationText,
        phase1EmployeeActionStatusText,
        phase1MetricDomainPlatformText,
        phase1MetricDomainDataTypeText,
        phase1MissingFieldDetailText,
        phase1EmployeeEvidenceStatusText,
        phase1FieldTrustStatusText,
        phase1EmployeeEvidencePolicyText,
        phase1EmployeeStorageTableText,
        phase1EmployeeGapCodeText,
        phase1EmployeeActionCodeText,
        onlineAnalysisFieldFactStatus,
        onlineAnalysisP0CaptureEvidenceStatus,
        onlineAnalysisP0CaptureEvidenceStatusText,
        onlineAnalysisP0CaptureEvidenceStatusClass,
        onlineAnalysisP0CaptureEvidenceDetailText,
        onlineAnalysisFieldFactStatusText,
        onlineAnalysisFieldFactStatusClass,
        onlineAnalysisFieldFactDetailText,
        onlineAnalysisSourceText,
        onlineAnalysisDataTypeText,
        buildOnlineAnalysisSummaryCards,
        buildOnlineAnalysisMetricDefinitionRows,
        buildOnlineAnalysisChartConfig,
    };
})();
