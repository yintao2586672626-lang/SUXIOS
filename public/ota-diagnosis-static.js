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

    return {
        normalizeOtaDiagnosisList,
        otaDiagnosisPlatformText,
        otaDiagnosisDateRangeText,
        otaDiagnosisPriorityClass,
        otaDiagnosisPriorityText,
        buildOtaDiagnosisMetricCards,
        buildOtaDiagnosisResultSections,
    };
})();
