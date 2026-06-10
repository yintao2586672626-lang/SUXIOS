window.SUXI_NOTIFICATION_STATIC = (() => {
    const sanitizeGlobalNotificationText = (text, fallback = '-') => {
        const value = String(text || fallback || '').trim();
        return value
            .replace(/(1[3-9]\d)\d{4}(\d{4})/g, '$1****$2')
            .replace(/\b\d{8,}\b/g, '[编号已隐藏]')
            .replace(/(cookie|token|authorization|spidertoken)\s*[:=]\s*[^;\s,]+/gi, '$1=****')
            .slice(0, 180);
    };

    const globalNotificationSeverityFromPriority = (priority = 'medium') => ({
        high: 'error',
        medium: 'warning',
        low: 'info',
        ok: 'success',
    }[priority] || 'warning');

    const globalNotificationSeverityDotClass = (severity = 'info') => ({
        error: 'bg-red-500',
        warning: 'bg-amber-500',
        success: 'bg-green-500',
        info: 'bg-blue-500',
    }[severity] || 'bg-gray-400');

    const globalNotificationBadgeClass = (severity = 'info') => ({
        error: 'border-red-100 bg-red-50 text-red-700',
        warning: 'border-amber-100 bg-amber-50 text-amber-700',
        success: 'border-green-100 bg-green-50 text-green-700',
        info: 'border-blue-100 bg-blue-50 text-blue-700',
    }[severity] || 'border-gray-200 bg-gray-50 text-gray-600');

    const globalNotificationTargetFromAction = (row = {}) => {
        const actionType = String(row.action_type || '').toLowerCase();
        if (actionType === 'log') {
            return { page: 'operation-logs', tab: '', actionLabel: '查看日志' };
        }
        if (actionType === 'fetch' && row.action_tab) {
            const actionTab = String(row.action_tab || '');
            return {
                page: actionTab.includes('meituan') ? 'meituan-ebooking' : 'ctrip-ebooking',
                tab: actionTab,
                actionLabel: row.button_text || '去补采集',
            };
        }
        if (actionType === 'cookie') {
            return { page: 'online-data', tab: 'data-health', actionLabel: '更新授权' };
        }
        return { page: 'online-data', tab: 'data-health', actionLabel: row.button_text || '查看处理' };
    };

    const formatGlobalNotificationTime = (value) => {
        const text = String(value || '').trim();
        if (!text) return '';
        if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(text)) {
            return text.slice(5, 16);
        }
        return text.slice(0, 16);
    };

    const buildGlobalNotificationId = (parts) => parts
        .map(part => String(part || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .join('|')
        .slice(0, 240);

    const normalizeBackendGlobalNotification = (row = {}) => {
        const id = Number(row.id || 0);
        const payload = row.action_payload && typeof row.action_payload === 'object' ? row.action_payload : {};
        return {
            id: row.notification_id || `system-notification-${id}`,
            backend_id: id,
            severity: row.severity || 'info',
            category: row.category || 'general',
            category_label: row.category_label || '系统通知',
            title: sanitizeGlobalNotificationText(row.title, '系统通知'),
            detail: sanitizeGlobalNotificationText(row.detail || row.message, '查看通知详情'),
            time_label: formatGlobalNotificationTime(row.time_label || row.updated_at || row.created_at),
            action_label: row.action_label || payload.action_label || '查看处理',
            target_page: row.target_page || payload.target_page || 'online-data',
            target_tab: row.target_tab || payload.target_tab || 'data-health',
            is_read: row.is_read === true || row.is_read === 1,
            source: 'backend',
        };
    };

    const buildGlobalNotifications = ({
        backendItems = [],
        autoFetchRunState = null,
        autoFetchRunningHint = '',
        autoFetchRunElapsedLabel = '',
        autoFetchStatus = null,
        autoFetchRecentRuns = [],
        dataHealthTodayWorkOrders = [],
        readIds = [],
    } = {}) => {
        const rows = Array.isArray(backendItems) ? [...backendItems] : [];
        if (autoFetchRunState?.active) {
            rows.push({
                id: 'auto-fetch-running',
                severity: 'info',
                category: 'capture_running',
                category_label: '采集中',
                title: 'OTA 自动采集正在运行',
                detail: sanitizeGlobalNotificationText(autoFetchRunState.message || autoFetchRunningHint || '后台正在采集授权 OTA 数据'),
                time_label: autoFetchRunElapsedLabel || '',
                action_label: '查看进度',
                target_page: 'online-data',
                target_tab: 'platform-auto',
            });
        }

        const lastResult = autoFetchStatus?.last_result;
        if (lastResult && (lastResult.message || autoFetchStatus?.last_run_time)) {
            const success = lastResult.success === true;
            const savedCount = Number(lastResult.saved_count || 0);
            const message = lastResult.message || (success ? `自动采集完成，保存 ${savedCount} 条数据` : '自动采集失败');
            rows.push({
                id: buildGlobalNotificationId(['auto-fetch-last', autoFetchStatus?.last_run_time, success ? 'success' : 'failed', message]),
                severity: success ? 'success' : 'error',
                category: success ? 'capture_success' : 'capture_failed',
                category_label: success ? '采集完成' : '采集失败',
                title: success ? 'OTA 自动采集完成' : 'OTA 自动采集失败',
                detail: sanitizeGlobalNotificationText(message),
                time_label: autoFetchStatus?.last_run_time || '',
                action_label: success ? '查看数据' : '查看原因',
                target_page: 'online-data',
                target_tab: 'data-health',
            });
        }

        (Array.isArray(autoFetchRecentRuns) ? autoFetchRecentRuns : []).slice(0, 3).forEach((run, index) => {
            const success = run?.success === true;
            rows.push({
                id: buildGlobalNotificationId(['auto-fetch-run', index, run?.run_at, run?.data_date, success ? 'success' : 'failed']),
                severity: success ? 'success' : 'error',
                category: success ? 'capture_success' : 'capture_failed',
                category_label: success ? '采集完成' : '采集失败',
                title: `${run?.data_date || '最近'} OTA 采集${success ? '完成' : '失败'}`,
                detail: sanitizeGlobalNotificationText(run?.message || (success ? '已形成 OTA 采集记录' : '最近自动采集未成功')),
                time_label: run?.run_at || '',
                action_label: success ? '查看记录' : '查看原因',
                target_page: 'online-data',
                target_tab: 'data-health',
            });
        });

        (Array.isArray(dataHealthTodayWorkOrders) ? dataHealthTodayWorkOrders : []).forEach((row, index) => {
            const target = globalNotificationTargetFromAction(row);
            const severity = globalNotificationSeverityFromPriority(row.priority);
            rows.push({
                id: buildGlobalNotificationId(['data-health', index, row.key, row.title, row.detail]),
                severity,
                category: row.action_type === 'cookie' ? 'cookie_alert' : (row.action_type === 'log' ? 'risk_action' : 'data_quality'),
                category_label: row.source_label || '数据健康',
                title: sanitizeGlobalNotificationText(row.title, '数据健康待处理'),
                detail: sanitizeGlobalNotificationText(row.detail, '请进入数据健康中心查看处理'),
                time_label: row.platform_label || '',
                action_label: target.actionLabel,
                target_page: target.page,
                target_tab: target.tab,
            });
        });

        const readSet = new Set(Array.isArray(readIds) ? readIds : []);
        const seen = new Set();
        return rows
            .filter(row => {
                if (!row.id || seen.has(row.id)) return false;
                seen.add(row.id);
                return true;
            })
            .map(row => row.source === 'backend' ? row : ({ ...row, is_read: readSet.has(row.id) }));
    };

    return {
        sanitizeGlobalNotificationText,
        globalNotificationSeverityFromPriority,
        globalNotificationSeverityDotClass,
        globalNotificationBadgeClass,
        globalNotificationTargetFromAction,
        formatGlobalNotificationTime,
        buildGlobalNotificationId,
        normalizeBackendGlobalNotification,
        buildGlobalNotifications,
    };
})();
