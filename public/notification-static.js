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

    return {
        sanitizeGlobalNotificationText,
        globalNotificationSeverityFromPriority,
        globalNotificationSeverityDotClass,
        globalNotificationBadgeClass,
        globalNotificationTargetFromAction,
        formatGlobalNotificationTime,
        buildGlobalNotificationId,
        normalizeBackendGlobalNotification,
    };
})();
