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

    return {
        onlineDataQualityStatusText,
        onlineDataQualityStatusClass,
        onlineDataQualityPromptList,
        onlineDataQualityScopeText,
        autoFetchRecordStatusClass,
        collectionHealthCookieLightClass,
        collectionHealthCookieLightText,
        dataHealthNormalizeStatus,
        dataHealthPriorityClass,
        dataHealthPriorityText,
        dataHealthPlatformText,
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
    };
})();
