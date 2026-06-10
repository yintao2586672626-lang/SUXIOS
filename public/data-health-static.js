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
    };
})();
