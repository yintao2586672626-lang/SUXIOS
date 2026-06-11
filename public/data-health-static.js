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
        buildOnlineAnalysisChartConfig,
    };
})();
