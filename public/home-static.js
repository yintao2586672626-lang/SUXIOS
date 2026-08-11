window.SUXI_HOME_STATIC = (() => {
    const buildHomeClosedLoopStages = ({
        readiness = {},
        compassLastSyncedAt = '',
        trendReady = false,
        forecastStatus = '',
        homeMarketForecastAction = '',
        homeObservationSampleDaysText = '',
        executionCount = 0,
        operationExecutionMoneyStatusText = '',
        operationExecutionMoneyStatusClass = '',
        operationExecutionBottleneckText = '',
    } = {}) => {
        const safeReadiness = readiness && typeof readiness === 'object' ? readiness : {};
        const readyPercent = Number(safeReadiness.percent || 0);
        const coreReady = readyPercent >= 100;
        const safeForecastStatus = String(forecastStatus || '');
        const aiReady = !!trendReady && !safeForecastStatus.startsWith('待');
        return [
            {
                key: 'ota-trust',
                index: '第1步',
                title: '先确认数据能不能用',
                statusText: coreReady ? '核心就绪' : (readyPercent > 0 ? safeReadiness.summaryText : '待同步'),
                statusClass: coreReady ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : (readyPercent > 0 ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200'),
                desc: safeReadiness.missingText || '等待授权 OTA 数据形成可验证输入。',
                evidence: `最近同步 ${compassLastSyncedAt || '--'}`,
                actionLabel: coreReady ? '查看数据状态' : '去补数据',
                entry: { page: 'online-data', tab: coreReady ? 'data-health' : 'platform-auto' },
                icon: 'fas fa-shield-alt',
            },
            {
                key: 'revenue-analysis',
                index: '第2步',
                title: '看今天经营结果',
                statusText: trendReady ? '可分析' : '样本不足',
                statusClass: trendReady ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                desc: homeMarketForecastAction || '先形成 OTA 与经营日报样本，再判断收入、入住和价格走势。',
                evidence: `样本 ${homeObservationSampleDaysText || '--'}`,
                actionLabel: trendReady ? '看收益分析' : '先同步数据',
                entry: trendReady ? { page: 'revenue-research-center' } : { page: 'online-data', tab: 'platform-auto' },
                icon: 'fas fa-chart-line',
            },
            {
                key: 'ai-decision',
                index: '第3步',
                title: '让AI给出动作',
                statusText: aiReady ? '可生成动作' : '待数据支撑',
                statusClass: aiReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                desc: aiReady ? '进入预警建议或策略模拟后生成动作，仍需人工确认后下发。' : '核心样本不足时只显示缺口，不把模型输出当作事实。',
                evidence: `经营信号 ${safeForecastStatus || '待形成'}`,
                actionLabel: aiReady ? '试算策略' : '先看缺口',
                entry: aiReady ? { page: 'ops-plan' } : { page: 'online-data', tab: 'data-health' },
                icon: 'fas fa-brain',
            },
            {
                key: 'operation-execution',
                index: '第4步',
                title: '把动作派下去',
                statusText: executionCount ? operationExecutionMoneyStatusText : '待生成执行单',
                statusClass: executionCount ? operationExecutionMoneyStatusClass : 'bg-gray-50 text-gray-500 border-gray-200',
                desc: executionCount ? `当前瓶颈：${operationExecutionBottleneckText}` : '策略动作需进入审批、执行、证据和 ROI 复盘链路。',
                evidence: executionCount ? `执行单 ${executionCount} 条` : '暂无执行闭环记录',
                actionLabel: '看执行进度',
                entry: { page: 'ops-track' },
                icon: 'fas fa-tasks',
            },
        ];
    };

    const buildHomeAiTraceRows = ({
        readiness = {},
        trendReady = false,
        homeMarketForecastStatus = '',
        executionCount = 0,
        operationExecutionBottleneckText = '',
    } = {}) => {
        const safeReadiness = readiness && typeof readiness === 'object' ? readiness : {};
        return [
            {
                label: '输入证据',
                value: safeReadiness.summaryText || '待同步',
                className: (Number(safeReadiness.percent || 0) >= 100) ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                detail: safeReadiness.missingText || '先校验 OTA、收益趋势和辅助信号来源。',
                entry: { page: 'online-data', tab: 'data-health' },
            },
            {
                label: '生成动作',
                value: homeMarketForecastStatus || '待形成样本',
                className: trendReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                detail: '模型建议只作为待确认动作，缺样本时不生成确定结论。',
                entry: { page: 'ops-plan' },
            },
            {
                label: '人工确认',
                value: executionCount ? `${executionCount}条执行单` : '待确认',
                className: executionCount ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                detail: executionCount ? operationExecutionBottleneckText : '动作需经过审批、执行证据和 ROI 复盘后才能闭环。',
                entry: { page: 'ops-track' },
            },
        ];
    };

    const requireHomeHelper = (helpers, key) => {
        const helper = helpers?.[key];
        if (typeof helper !== 'function') {
            throw new Error(`Missing home static helper: ${key}`);
        }
        return helper;
    };
    const buildHomeOperatingResultCards = ({
        revenueCard = null,
        demandCard = null,
        priceCard = null,
        roomNights = 0,
        revenueSum = 0,
        adrAvg = 0,
        rangeLabel = '',
        helpers = {},
    } = {}) => {
        const formatNumber = requireHomeHelper(helpers, 'formatNumber');
        const homeTextHasValue = requireHomeHelper(helpers, 'homeTextHasValue');
        const homeMetricToneClass = requireHomeHelper(helpers, 'homeMetricToneClass');
        const revenueValue = homeTextHasValue(revenueCard?.value)
            ? revenueCard.value
            : (revenueSum > 0 ? `¥${formatNumber(Math.round(revenueSum))}` : '待同步');
        const orderValue = homeTextHasValue(demandCard?.value) ? demandCard.value : '待同步';
        const roomNightValue = roomNights > 0 ? `${formatNumber(Math.round(roomNights))}间夜` : '未返回';
        const adrValue = adrAvg > 0
            ? `¥${formatNumber(Math.round(adrAvg))}`
            : (homeTextHasValue(priceCard?.value) ? priceCard.value : '待同步');
        const orderReady = homeTextHasValue(orderValue);
        const roomNightReady = roomNights > 0;
        const adrReady = homeTextHasValue(adrValue);
        const revenueReady = homeTextHasValue(revenueValue);
        const cardVisual = {
            orders: {
                accentClass: orderReady ? 'bg-blue-500' : 'bg-slate-300',
                iconClass: orderReady ? 'border-blue-100 bg-blue-50 text-blue-700' : 'border-slate-200 bg-slate-50 text-slate-500',
            },
            roomNights: {
                accentClass: roomNightReady ? 'bg-emerald-500' : 'bg-slate-300',
                iconClass: roomNightReady ? 'border-emerald-100 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500',
            },
            adr: {
                accentClass: adrReady ? 'bg-amber-500' : 'bg-slate-300',
                iconClass: adrReady ? 'border-amber-100 bg-amber-50 text-amber-700' : 'border-slate-200 bg-slate-50 text-slate-500',
            },
            revenue: {
                accentClass: revenueReady ? 'bg-rose-500' : 'bg-slate-300',
                iconClass: revenueReady ? 'border-rose-100 bg-rose-50 text-rose-700' : 'border-slate-200 bg-slate-50 text-slate-500',
            },
        };
        return [
            {
                key: 'orders',
                label: 'OTA订单',
                value: orderValue,
                sub: demandCard?.note || '来源：OTA 订单数；未返回时保留缺口',
                status: demandCard?.direction || '待同步',
                icon: 'fas fa-receipt',
                ready: orderReady,
                accentClass: cardVisual.orders.accentClass,
                iconClass: cardVisual.orders.iconClass,
                toneClass: homeMetricToneClass(orderReady, demandCard?.level),
                entry: { page: 'online-data', tab: 'history' },
            },
            {
                key: 'room-nights',
                label: 'OTA间夜',
                value: roomNightValue,
                sub: roomNights > 0 ? `来源：${rangeLabel}趋势样本` : '趋势接口未返回稳定间夜字段',
                status: roomNights > 0 ? '已返回' : '未返回',
                icon: 'fas fa-bed',
                ready: roomNightReady,
                accentClass: cardVisual.roomNights.accentClass,
                iconClass: cardVisual.roomNights.iconClass,
                toneClass: homeMetricToneClass(roomNightReady, 'blue'),
                entry: { page: 'online-data', tab: 'history' },
            },
            {
                key: 'adr',
                label: 'ADR',
                value: adrValue,
                sub: priceCard?.note || '优先展示采集字段，不用收入/间夜倒推',
                status: priceCard?.direction || '待同步',
                icon: 'fas fa-tag',
                ready: adrReady,
                accentClass: cardVisual.adr.accentClass,
                iconClass: cardVisual.adr.iconClass,
                toneClass: homeMetricToneClass(adrReady, priceCard?.level),
                entry: { page: 'revenue-research-center' },
            },
            {
                key: 'revenue',
                label: '收入样本',
                value: revenueValue,
                sub: revenueCard?.source || 'OTA/经营日报样本口径，不替代全酒店总营收',
                status: revenueCard?.direction || '待同步',
                icon: 'fas fa-yen-sign',
                ready: revenueReady,
                accentClass: cardVisual.revenue.accentClass,
                iconClass: cardVisual.revenue.iconClass,
                toneClass: homeMetricToneClass(revenueReady, revenueCard?.level),
                entry: { page: 'revenue-research-center' },
            },
        ];
    };
    const buildHomeCausalChainNodes = ({
        exposure = {},
        visitors = {},
        conversion = {},
        fallbackOrders = {},
        operatingCards = [],
        helpers = {},
    } = {}) => {
        const homeTextHasValue = requireHomeHelper(helpers, 'homeTextHasValue');
        const safeCards = Array.isArray(operatingCards) ? operatingCards : [];
        const operatingOrders = safeCards.find(card => card.key === 'orders');
        const orders = homeTextHasValue(operatingOrders?.value)
            ? { value: operatingOrders.value, ready: true }
            : fallbackOrders;
        const revenue = safeCards.find(card => card.key === 'revenue');
        return [
            { key: 'exposure', label: '曝光', value: exposure.value, ready: exposure.ready, icon: 'fas fa-eye' },
            { key: 'visitors', label: '浏览/访客', value: visitors.value, ready: visitors.ready, icon: 'fas fa-mouse-pointer' },
            { key: 'conversion', label: '转化率', value: conversion.value, ready: conversion.ready, icon: 'fas fa-filter' },
            { key: 'orders', label: '订单承接', value: orders.value, ready: orders.ready, icon: 'fas fa-receipt' },
            { key: 'revenue', label: '收入结果', value: revenue?.value || '待同步', ready: homeTextHasValue(revenue?.value), icon: 'fas fa-chart-line' },
        ];
    };

    const formatHomeTrendAxisTick = (value) => {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) return String(value ?? '');
        const absValue = Math.abs(numeric);
        if (absValue >= 10000) {
            const wanValue = numeric / 10000;
            const maxDigits = Math.abs(wanValue) >= 100 || Number.isInteger(wanValue) ? 0 : 1;
            return `${wanValue.toLocaleString('zh-CN', { maximumFractionDigits: maxDigits })}万`;
        }
        return numeric.toLocaleString('zh-CN', { maximumFractionDigits: 0 });
    };

    const buildHomeTrendChartConfig = ({ labels = [], metric = {}, metricKey = 'revenue' } = {}) => {
        const colors = {
            revenue: ['rgb(37, 99, 235)', 'rgba(37, 99, 235, 0.12)'],
            adr: ['rgb(217, 119, 6)', 'rgba(217, 119, 6, 0.12)'],
            revpar: ['rgb(79, 70, 229)', 'rgba(79, 70, 229, 0.12)'],
            room_nights: ['rgb(14, 116, 144)', 'rgba(14, 116, 144, 0.12)'],
        };
        const [borderColor, backgroundColor] = colors[metricKey] || colors.revenue;
        return {
            type: 'line',
            data: {
                labels: Array.isArray(labels) ? labels : [],
                datasets: [{
                    label: metric.label || '趋势',
                    data: Array.isArray(metric.data) ? metric.data.map(value => value === null || value === undefined ? null : Number(value)) : [],
                    borderColor,
                    backgroundColor,
                    borderWidth: 2,
                    tension: 0.35,
                    fill: true,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    spanGaps: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const unit = metric.unit || '';
                                const value = context.parsed.y;
                                if (value === null || Number.isNaN(value)) return `${metric.label}: -`;
                                return unit === '¥'
                                    ? `${metric.label}: ¥${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 1 })}`
                                    : `${metric.label}: ${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 1 })}${unit}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#64748b', maxTicksLimit: 8 } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        ticks: { color: '#64748b', callback: formatHomeTrendAxisTick },
                    },
                },
            },
        };
    };

    const buildHomeBoardActionRows = ({
        readiness = {},
        channelSignal = null,
        channelSignalClassName = '',
        competitorCards = [],
        competitorNotice = '',
        homeMarketForecastAction = '',
    } = {}) => {
        const safeReadiness = readiness && typeof readiness === 'object' ? readiness : {};
        const safeCards = Array.isArray(competitorCards) ? competitorCards : [];
        const competitorReady = safeCards.some(card => !['待同步', '未返回', '待补'].includes(String(card?.value || '')));
        return [
            {
                key: 'data',
                title: Number(safeReadiness.percent || 0) >= 100 ? '复核经营结果' : '先补核心数据',
                detail: safeReadiness.missingText || homeMarketForecastAction,
                badge: safeReadiness.summaryText || '待同步',
                className: Number(safeReadiness.percent || 0) >= 100 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                entry: { page: 'online-data', tab: 'data-health' },
            },
            {
                key: 'funnel',
                title: '检查曝光到收入链路',
                detail: channelSignal?.summary || '同步 OTA 流量后判断曝光、浏览、转化和订单承接。',
                badge: channelSignal?.status_text || '待同步',
                className: channelSignal ? channelSignalClassName : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: { page: 'ctrip-ebooking', tab: 'ctrip-traffic' },
            },
            {
                key: 'competition',
                title: competitorReady ? '竞对仅供异常复核' : '竞对诊断证据未取得',
                detail: competitorReady
                    ? `仅在自家门店出现异常时按同平台、同日期口径参考。${competitorNotice || ''}`
                    : '不影响自家门店事实展示；出现异常且确需对比时再同步竞对榜单。',
                badge: competitorReady ? '诊断参考' : '非首页主线',
                className: competitorReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: { page: 'meituan-ebooking', tab: 'meituan-ranking' },
            },
        ];
    };

    const homeBusinessFactDefinitions = [
        { key: 'ota_revenue', label: 'OTA收入', unit: '元', icon: 'fas fa-yen-sign' },
        { key: 'ota_orders', label: 'OTA订单', unit: '单', icon: 'fas fa-receipt' },
        { key: 'ota_room_nights', label: 'OTA间夜', unit: '间夜', icon: 'fas fa-bed' },
        { key: 'ota_detail_exposure', label: 'OTA详情访问', unit: '次', icon: 'fas fa-eye' },
    ];

    const homeBusinessStatusClass = (status) => ({
        '已取得': 'border-emerald-200 bg-emerald-50 text-emerald-700',
        '已验证': 'border-emerald-200 bg-emerald-50 text-emerald-700',
        '事实已回读·分析受限': 'border-amber-200 bg-amber-50 text-amber-700',
        '已形成': 'border-emerald-200 bg-emerald-50 text-emerald-700',
        '部分取得': 'border-amber-200 bg-amber-50 text-amber-700',
        '正在读取': 'border-blue-200 bg-blue-50 text-blue-700',
        '读取失败': 'border-red-200 bg-red-50 text-red-700',
        '未取得': 'border-slate-200 bg-slate-100 text-slate-600',
        '未验证': 'border-slate-200 bg-slate-100 text-slate-600',
        '尚未形成': 'border-slate-200 bg-slate-100 text-slate-600',
    }[status] || 'border-slate-200 bg-slate-100 text-slate-600');

    const homeBusinessMetricAvailable = (value) => (
        value !== null
        && value !== undefined
        && value !== ''
        && Number.isFinite(Number(value))
    );

    const homeFactValueText = (value, format, formatNumber) => {
        if (!homeBusinessMetricAvailable(value)) return '未取得';
        const numeric = Number(value);
        if (format === 'money') {
            const rounded = Math.round((numeric + Number.EPSILON) * 100) / 100;
            return `¥${rounded.toLocaleString('zh-CN', {
                minimumFractionDigits: Number.isInteger(rounded) ? 0 : 2,
                maximumFractionDigits: 2,
            })}`;
        }
        if (format === 'percent') return `${formatNumber(numeric)}%`;
        if (format === 'orders') return `${formatNumber(numeric)}单`;
        if (format === 'room_nights') return `${formatNumber(numeric)}间夜`;
        if (format === 'visits') return `${formatNumber(numeric)}次`;
        return formatNumber(numeric);
    };

    const homeFactStatusText = (ready, loading, loadError) => {
        if (loading) return '正在读取';
        if (loadError) return '读取失败';
        return ready ? '已验证' : '未取得';
    };

    const homeReconciliationStatusText = (status) => ({
        matched: '已对齐',
        canonicalized: '订单级已去重',
        matched_or_not_calculable: '证据不全',
        ota_only_ready: '仅OTA可用',
        available: '已取得',
        reference_only: '仅作参考',
        matched_with_scope_caveats: '同日可对照',
        blocked: '日期阻断',
        mismatch: '发现差异',
        review_needed: '待复核',
        incomplete: '证据不全',
        not_comparable: '口径不可比',
        not_checkable: '不可核验',
        partial: '部分可对照',
    }[String(status || '')] || '待核验');

    const buildHomeBusinessTimeModel = ({
        temporalData = {},
        hotelName = '',
        futureCard = null,
        revenueMetricCards = [],
        revenueOverviewScope = '',
        revenueFactLayer = null,
        selectedHotelId = '',
        revenueFactLayerLoading = false,
        revenueFactLayerError = '',
        loading = false,
        error = '',
        helpers = {},
    } = {}) => {
        const temporal = temporalData && typeof temporalData === 'object' ? temporalData : {};
        const past = temporal.past && typeof temporal.past === 'object' ? temporal.past : {};
        const present = temporal.present && typeof temporal.present === 'object' ? temporal.present : {};
        const future = temporal.future && typeof temporal.future === 'object' ? temporal.future : {};
        const rawFactLayer = revenueFactLayer && typeof revenueFactLayer === 'object'
            ? revenueFactLayer
            : {};
        const selectedHotelKey = String(selectedHotelId || '').trim();
        const rawFactLayerHotelKey = String(
            rawFactLayer.hotel?.system_hotel_id || ''
        ).trim();
        const factLayerMatchesHotel = selectedHotelKey !== ''
            && rawFactLayerHotelKey === selectedHotelKey;
        const factLayerHotelMismatch = selectedHotelKey !== ''
            && rawFactLayerHotelKey !== ''
            && rawFactLayerHotelKey !== selectedHotelKey;
        const factLayer = factLayerMatchesHotel ? rawFactLayer : {};
        const factLayerDate = String(factLayer.business_date || '').trim();
        const formatNumber = typeof helpers.formatNumber === 'function'
            ? helpers.formatNumber
            : (value) => Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 0 });
        const loadError = String(error || '').trim();
        const targetDate = String(
            past?.period?.end_date || factLayerDate || ''
        ).trim();
        const pastSeries = Array.isArray(past.series) ? past.series : [];
        const yesterdayRow = targetDate
            ? pastSeries.find(row => String(row?.date || '') === targetDate) || null
            : null;
        const latestHistoricalDate = String(pastSeries[pastSeries.length - 1]?.date || '').trim();
        const platformText = Array.isArray(yesterdayRow?.platforms) && yesterdayRow.platforms.length
            ? yesterdayRow.platforms.map(platform => ({ ctrip: '携程', meituan: '美团' }[platform] || platform)).join('、')
            : '平台未返回';

        const facts = homeBusinessFactDefinitions.map((definition) => {
            const rawValue = yesterdayRow?.[definition.key];
            const ready = !!yesterdayRow && homeBusinessMetricAvailable(rawValue);
            const status = loading ? '正在读取' : (loadError ? '读取失败' : (ready ? '已取得' : '未取得'));
            let value = '未取得';
            if (loading) value = '读取中';
            else if (loadError) value = '读取失败';
            else if (ready) {
                const numeric = Number(rawValue);
                value = definition.key === 'ota_revenue'
                    ? `¥${formatNumber(Math.round(numeric))}`
                    : `${formatNumber(Math.round(numeric))}${definition.unit}`;
            }
            return {
                ...definition,
                ready,
                status,
                statusClass: homeBusinessStatusClass(status),
                value,
                detail: ready
                    ? `${targetDate} · ${platformText} · 已定稿 OTA 渠道事实`
                    : `${targetDate || '目标日待确认'}未取得该项事实；不回退旧日期，也不按 0 补齐。`,
            };
        });

        const factLayerMatchesTarget = !!targetDate && factLayerDate === targetDate;
        const factLayerLoading = !factLayerMatchesTarget
            && (revenueFactLayerLoading === true || (!factLayerDate && loading));
        const factLayerError = factLayerMatchesTarget
            ? ''
            : String(revenueFactLayerError || '').trim();
        const dateAlignment = factLayer.date_alignment && typeof factLayer.date_alignment === 'object'
            ? factLayer.date_alignment
            : {};
        const reconciliation = factLayer.reconciliation && typeof factLayer.reconciliation === 'object'
            ? factLayer.reconciliation
            : {};
        const wholeHotelSource = factLayer.sources?.dingdandao_pms || {};
        const wholeHotelValues = factLayer.facts?.whole_hotel_accommodation || {};
        const otaChannelValues = factLayer.facts?.ota_channel || {};
        const combinedOtaValues = otaChannelValues.combined || {};
        const combinedOtaStatus = otaChannelValues.combined_status || {};
        const derivedValues = factLayer.derived_metrics || {};
        const exactDateBlocked = String(dateAlignment.status || '') === 'blocked_date_mismatch';
        const wholeHotelReady = factLayerMatchesTarget
            && !exactDateBlocked
            && String(wholeHotelSource.data_status || '') === 'readback_verified'
            && String(wholeHotelSource.actual_business_date || '') === targetDate;
        const wholeHotelMetricReady = (metricKey, allowDerived = false) => {
            const statuses = wholeHotelSource.fact_statuses;
            if (!statuses || typeof statuses !== 'object'
                || !Object.prototype.hasOwnProperty.call(statuses, metricKey)
            ) {
                return wholeHotelReady;
            }
            const status = String(statuses?.[metricKey]?.status || '');
            return wholeHotelReady && (
                status === 'readback_verified'
                || (allowDerived && status === 'derived_verified')
            );
        };
        const otaPlatformDatesMatch = factLayerMatchesTarget
            && ['ctrip_ota', 'meituan_ota'].every((sourceKey) => (
                String(
                    factLayer.sources?.[sourceKey]?.actual_business_date || ''
                ) === targetDate
            ));
        const legacyCombinedOtaReady = otaPlatformDatesMatch
            && ['ctrip_ota', 'meituan_ota'].every((sourceKey) => {
                const source = factLayer.sources?.[sourceKey] || {};
                return String(source.data_status || '') === 'readback_verified';
            });
        const combinedOtaMetricReady = (metricKey) => {
            const statuses = combinedOtaStatus.fact_statuses;
            if (!statuses || typeof statuses !== 'object') {
                return legacyCombinedOtaReady;
            }
            if (!Object.prototype.hasOwnProperty.call(statuses, metricKey)) {
                return false;
            }
            const status = String(statuses?.[metricKey]?.status || '');
            return otaPlatformDatesMatch && (
                status === 'readback_verified'
                || (metricKey === 'adr' && status === 'derived_verified')
            );
        };
        const combinedOtaReady = [
            'revenue',
            'orders',
            'room_nights',
        ].every(combinedOtaMetricReady);
        const factCard = ({ key, label, value, format, ready, detail, reason = '' }) => {
            const valueReady = ready && homeBusinessMetricAvailable(value);
            const status = homeFactStatusText(valueReady, factLayerLoading, factLayerError);
            return {
                key,
                label,
                value: factLayerLoading
                    ? '读取中'
                    : (factLayerError ? '读取失败' : homeFactValueText(valueReady ? value : null, format, formatNumber)),
                status,
                statusClass: homeBusinessStatusClass(status),
                ready: valueReady,
                detail: valueReady
                    ? detail
                    : `${targetDate || '目标日待确认'}未取得${label}${reason ? `：${reason}` : ''}；不使用0、旧日期或另一口径补齐。`,
            };
        };
        const wholeHotelFacts = [
            factCard({
                key: 'sold_room_nights',
                label: '出租房晚',
                value: wholeHotelValues.sold_room_nights,
                format: 'room_nights',
                ready: wholeHotelMetricReady('sold_room_nights'),
                detail: `${targetDate} · PMS全酒店住宿口径 · 精确回读`,
            }),
            factCard({
                key: 'sellable_room_nights',
                label: '可售房晚（推导校验）',
                value: wholeHotelValues.sellable_room_nights,
                format: 'room_nights',
                ready: wholeHotelMetricReady('sellable_room_nights', true),
                detail: `${targetDate} · PMS全酒店住宿口径 · 由出租房晚与入住率交叉校验`,
            }),
            factCard({
                key: 'payment_collected_amount',
                label: '支付实收（非会计收入）',
                value: wholeHotelValues.payment_collected_amount,
                format: 'money',
                ready: wholeHotelMetricReady('payment_collected_amount'),
                detail: `${targetDate} · PMS支付通道实收`,
                reason: '当前PMS合同只有住宿房费，支付实收字段未接入',
            }),
            factCard({
                key: 'occupancy_rate_percent',
                label: '入住率',
                value: wholeHotelValues.occupancy_rate_percent,
                format: 'percent',
                ready: wholeHotelMetricReady('occupancy_rate_percent'),
                detail: `${targetDate} · 出租房晚 / 可售房晚`,
            }),
        ];
        const wholeHotelDerivedFacts = [
            factCard({
                key: 'room_revenue',
                label: '住宿房费（非实收）',
                value: wholeHotelValues.room_revenue,
                format: 'money',
                ready: wholeHotelMetricReady('room_revenue'),
                detail: `${targetDate} · PMS住宿房费，不等同支付实收`,
            }),
            factCard({
                key: 'whole_hotel_adr',
                label: 'ADR',
                value: derivedValues.whole_hotel_adr?.value,
                format: 'money',
                ready: wholeHotelMetricReady('adr', true)
                    && derivedValues.whole_hotel_adr?.status === 'ready',
                detail: `${targetDate} · PMS住宿房费 / 出租房晚`,
            }),
            factCard({
                key: 'whole_hotel_revpar',
                label: 'RevPAR',
                value: derivedValues.whole_hotel_revpar?.value,
                format: 'money',
                ready: wholeHotelMetricReady('revpar', true)
                    && derivedValues.whole_hotel_revpar?.status === 'ready',
                detail: `${targetDate} · PMS住宿房费 / 可售房晚`,
            }),
        ];
        const otaChannelFacts = [
            factCard({
                key: 'ota_orders',
                label: '渠道订单',
                value: combinedOtaValues.orders,
                format: 'orders',
                ready: combinedOtaMetricReady('orders'),
                detail: `${targetDate} · 携程+美团OTA渠道，不代表全酒店订单`,
            }),
            factCard({
                key: 'ota_room_nights',
                label: '渠道房晚',
                value: combinedOtaValues.room_nights,
                format: 'room_nights',
                ready: combinedOtaMetricReady('room_nights'),
                detail: `${targetDate} · 携程+美团OTA渠道`,
            }),
            factCard({
                key: 'ota_adr',
                label: '渠道ADR',
                value: derivedValues.ota_adr?.value,
                format: 'money',
                ready: combinedOtaMetricReady('adr')
                    && derivedValues.ota_adr?.status === 'ready',
                detail: `${targetDate} · OTA房费 / OTA房晚`,
            }),
            factCard({
                key: 'ota_room_night_share_percent',
                label: '渠道房晚占比',
                value: derivedValues.ota_room_night_share_percent?.value,
                format: 'percent',
                ready: factLayerMatchesTarget
                    && !exactDateBlocked
                    && derivedValues.ota_room_night_share_percent?.status === 'ready',
                detail: `${targetDate} · OTA房晚 / PMS全酒店出租房晚`,
            }),
            factCard({
                key: 'ota_room_revenue_share_percent',
                label: '渠道房费结构占比',
                value: derivedValues.ota_room_revenue_share_percent?.value,
                format: 'percent',
                ready: factLayerMatchesTarget
                    && !exactDateBlocked
                    && derivedValues.ota_room_revenue_share_percent?.status === 'ready',
                detail: `${targetDate} · OTA房费 / PMS住宿房费；不是全酒店总营收或支付实收占比`,
            }),
            factCard({
                key: 'ota_cancellation_rate_percent',
                label: '取消率',
                value: derivedValues.ota_cancellation_rate_percent?.value,
                format: 'percent',
                ready: otaPlatformDatesMatch
                    && derivedValues.ota_cancellation_rate_percent?.status === 'ready',
                detail: `${targetDate} · 按携程/美团已完整分类的总订单数加权，仅OTA渠道口径`,
            }),
        ];
        const otaPlatformRows = ['ctrip', 'meituan'].map((platform) => {
            const source = factLayer.sources?.[`${platform}_ota`] || {};
            const values = otaChannelValues[platform] || {};
            const platformDateReady = factLayerMatchesTarget
                && String(source.actual_business_date || '') === targetDate;
            const platformAnalysisLimited = source.analysis_readiness?.allowed === false;
            const metricReady = (metricKey) => {
                const metricStatus = String(
                    source.fact_statuses?.[metricKey]?.status || ''
                );
                return platformDateReady && (
                    metricStatus === 'readback_verified'
                    || (metricKey === 'adr' && metricStatus === 'derived_verified')
                    || (
                        metricStatus === ''
                        && String(source.data_status || '') === 'readback_verified'
                    )
                );
            };
            const readyMetricCount = [
                'revenue',
                'orders',
                'room_nights',
                'adr',
                'list_exposure',
                'detail_exposure',
                'flow_rate_percent',
                'submit_rate_percent',
                'cancellation_rate_percent',
            ].filter(metricReady).length;
            const platformStatus = factLayerLoading
                ? '正在读取'
                : (factLayerError
                    ? '读取失败'
                    : (
                        String(source.data_status || '') === 'readback_verified'
                            ? (
                                platformAnalysisLimited
                                    ? '事实已回读·分析受限'
                                    : '已验证'
                            )
                            : (readyMetricCount > 0 ? '部分取得' : '未取得')
                    ));
            const platformLabel = platform === 'ctrip' ? '携程' : '美团';
            return {
                key: platform,
                label: platformLabel,
                status: platformStatus,
                statusClass: homeBusinessStatusClass(platformStatus),
                date: String(source.actual_business_date || source.business_date || ''),
                facts: [
                    factCard({ key: `${platform}-revenue`, label: '渠道成交额', value: values.revenue, format: 'money', ready: metricReady('revenue'), detail: `${targetDate} · ${platformLabel}渠道成交额；不代表全酒店收入` }),
                    factCard({ key: `${platform}-orders`, label: '订单', value: values.orders, format: 'orders', ready: metricReady('orders'), detail: `${targetDate} · ${platformLabel}渠道` }),
                    factCard({ key: `${platform}-room-nights`, label: '房晚', value: values.room_nights, format: 'room_nights', ready: metricReady('room_nights'), detail: `${targetDate} · ${platformLabel}渠道` }),
                    factCard({ key: `${platform}-adr`, label: 'ADR', value: values.adr, format: 'money', ready: metricReady('adr'), detail: `${targetDate} · ${platformLabel}渠道` }),
                    factCard({ key: `${platform}-list-exposure`, label: '列表曝光', value: values.list_exposure, format: 'visits', ready: metricReady('list_exposure'), detail: `${targetDate} · ${platformLabel}列表流量事实` }),
                    factCard({ key: `${platform}-detail-exposure`, label: '详情曝光', value: values.detail_exposure, format: 'visits', ready: metricReady('detail_exposure'), detail: `${targetDate} · ${platformLabel}流量事实` }),
                    factCard({ key: `${platform}-flow-rate`, label: '流量转化', value: values.flow_rate_percent, format: 'percent', ready: metricReady('flow_rate_percent'), detail: `${targetDate} · ${platformLabel}流量转化口径` }),
                    factCard({ key: `${platform}-submit-rate`, label: '提交转化', value: values.submit_rate_percent, format: 'percent', ready: metricReady('submit_rate_percent'), detail: `${targetDate} · ${platformLabel}提交转化口径` }),
                    factCard({ key: `${platform}-cancel-rate`, label: '取消率', value: values.cancellation_rate_percent, format: 'percent', ready: metricReady('cancellation_rate_percent'), detail: `${targetDate} · ${platformLabel}订单取消口径` }),
                ],
            };
        });
        const dateSourceLabels = {
            dingdandao_pms: 'PMS实际业务日',
            ctrip_ota: '携程实际业务日',
            meituan_ota: '美团实际业务日',
        };
        const dateSourceRows = Object.entries(dateSourceLabels).map(([key, label]) => {
            const source = dateAlignment.sources?.[key] || factLayer.sources?.[key] || {};
            const actualDate = String(
                source.observed_date || source.actual_business_date || source.business_date || ''
            ).trim();
            const sourceStatus = String(source.status || source.data_status || '').trim();
            const aligned = factLayerMatchesTarget
                && actualDate === targetDate
                && sourceStatus === 'readback_verified';
            const partiallyAligned = factLayerMatchesTarget
                && actualDate === targetDate
                && ['partial', 'partial_readback_verified'].includes(sourceStatus);
            const status = actualDate && actualDate !== targetDate
                ? '日期错位'
                : (
                    aligned
                        ? '同日已验证'
                        : (partiallyAligned ? '同日部分已验证' : '日期未取得')
                );
            return {
                key,
                label,
                date: actualDate || '未取得',
                status,
                statusClass: homeBusinessStatusClass(
                    status === '同日已验证'
                        ? '已验证'
                        : (
                            status === '同日部分已验证'
                                ? '部分取得'
                                : (status === '日期错位' ? '读取失败' : '未取得')
                        )
                ),
            };
        });
        const reconciliationRows = (Array.isArray(reconciliation.checks) ? reconciliation.checks : []).map((row) => {
            const status = homeReconciliationStatusText(row?.status);
            let value = '';
            if (row?.key === 'duplicate_orders') {
                const candidateRows = Math.max(0, Number(row?.order_identity_candidate_rows) || 0);
                const coveredRows = Math.max(0, Number(row?.order_identity_covered_rows) || 0);
                const suppressedOrders = Math.max(0, Number(row?.suppressed_duplicate_order_rows) || 0);
                const suppressedRepresentations = Math.max(0, Number(row?.suppressed_representation_rows) || 0);
                if (suppressedOrders > 0) {
                    value = `已排除 ${formatNumber(suppressedOrders)} 条重复订单版本`;
                } else if (candidateRows > 0 && coveredRows >= candidateRows) {
                    value = `订单身份覆盖 ${formatNumber(coveredRows)}/${formatNumber(candidateRows)}`;
                } else if (candidateRows > 0) {
                    value = `可信订单身份 ${formatNumber(coveredRows)}/${formatNumber(candidateRows)}`;
                } else if (suppressedRepresentations > 0) {
                    value = `仅归并 ${formatNumber(suppressedRepresentations)} 条重复表示，订单级未核验`;
                } else {
                    value = '缺少订单明细身份';
                }
            } else if (row?.key === 'summary_representation' && Array.isArray(row?.differences)) {
                value = row.differences.length > 0
                    ? `${formatNumber(row.differences.length)} 项数值差异`
                    : (
                        String(row?.status || '') === 'matched'
                            ? '未发现数值冲突'
                            : '缺少同口径可比指标'
                    );
            } else if (row?.key === 'cancellation' && homeBusinessMetricAvailable(row?.combined_rate_percent)) {
                value = `OTA加权取消率 ${formatNumber(Number(row.combined_rate_percent))}%`;
            } else if (row?.key === 'floor_vs_sales') {
                if (homeBusinessMetricAvailable(row?.reference_gap)) {
                    value = `综合OTA ADR - 最低保护价：¥${formatNumber(Number(row.reference_gap))}`;
                } else {
                    const salesEvidence = row?.sales_evidence && typeof row.sales_evidence === 'object'
                        ? Object.values(row.sales_evidence)
                        : [];
                    const availableSales = salesEvidence.filter((evidence) => (
                        ['available', 'conflicted'].includes(String(evidence?.status || ''))
                        && homeBusinessMetricAvailable(evidence?.value)
                    ));
                    if (availableSales.length > 0) {
                        const floorStatus = String(row?.floor_evidence?.status || 'missing');
                        value = `销售证据 ${formatNumber(availableSales.length)}/2；${floorStatus === 'ready' ? '综合ADR未就绪' : '最低保护价未取得'}`;
                        const currentConflict = availableSales
                            .map((evidence) => evidence?.current_snapshot_conflict)
                            .find((conflict) => homeBusinessMetricAvailable(conflict?.absolute_delta));
                        if (currentConflict) {
                            const conflictDelta = Number(
                                currentConflict.absolute_delta
                            ).toLocaleString('zh-CN', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            });
                            value += `；同批表示差 ¥${conflictDelta}`;
                        }
                    }
                }
            }
            return {
                key: String(row?.key || ''),
                label: String(row?.label || '对账项'),
                status,
                statusClass: homeBusinessStatusClass(
                    ['已对齐', '订单级已去重', '未发现冲突', '同日可对照'].includes(status)
                        ? '已验证'
                        : (['日期阻断', '发现差异'].includes(status) ? '读取失败' : '部分取得')
                ),
                value,
                detail: String(row?.detail || '尚无对账说明。'),
            };
        });

        const wholeHotelFactsComplete = [
            ...wholeHotelFacts,
            ...wholeHotelDerivedFacts,
        ].every((fact) => fact.ready === true);
        const otaChannelFactsComplete = otaChannelFacts.every(
            (fact) => fact.ready === true,
        ) && otaPlatformRows.every(
            (row) => Array.isArray(row.facts)
                && row.facts.every((fact) => fact.ready === true),
        );
        const dualScopeReady = factLayerMatchesTarget
            && !exactDateBlocked
            && wholeHotelFactsComplete
            && otaChannelFactsComplete;
        const anyStrictFactReady = [
            ...wholeHotelFacts,
            ...wholeHotelDerivedFacts,
            ...otaChannelFacts,
            ...otaPlatformRows.flatMap((row) => (
                Array.isArray(row.facts) ? row.facts : []
            )),
        ].some((fact) => fact.ready === true);

        const readyFactCount = facts.filter(fact => fact.ready).length;
        let yesterdayStatus = readyFactCount === facts.length ? '已取得' : (readyFactCount > 0 ? '部分取得' : '未取得');
        if (loading) yesterdayStatus = '正在读取';
        if (loadError) yesterdayStatus = '读取失败';
        let yesterdaySummary = loadError
            ? `昨日事实读取失败：${loadError}`
            : (loading
                ? '正在读取目标日已定稿事实。'
                : (readyFactCount > 0
                    ? `${targetDate}已取得 ${readyFactCount}/${facts.length} 项 OTA 渠道事实；缺失项保持“未取得”。`
                    : `${targetDate || '目标日'}未取得已定稿 OTA 事实${latestHistoricalDate && latestHistoricalDate !== targetDate ? `；最近历史日 ${latestHistoricalDate} 不用于替代` : ''}。`));
        if (factLayerMatchesTarget) {
            if (exactDateBlocked) {
                yesterdayStatus = '读取失败';
                yesterdaySummary = `${targetDate}发现PMS与OTA实际业务日期错位，本次不可对账；页面不会自动改日或混合口径。`;
            } else if (dualScopeReady) {
                yesterdayStatus = '已取得';
                yesterdaySummary = `${targetDate}要求的全酒店PMS事实、派生指标和携程/美团OTA渠道事实均已逐指标验证，并按两个口径分开显示。`;
            } else {
                yesterdayStatus = anyStrictFactReady
                    ? '部分取得'
                    : '未取得';
                yesterdaySummary = `${targetDate}双口径事实仅部分取得；已验证值按两个口径分开显示，缺失指标、来源和分母保持“未取得”。`;
            }
        } else if (factLayerHotelMismatch) {
            yesterdayStatus = '读取失败';
            yesterdaySummary = '当前返回的经营事实不属于所选门店，已阻止显示；请刷新该门店事实，旧门店数据不会沿用。';
        } else if (factLayerDate) {
            yesterdayStatus = '未取得';
            yesterdaySummary = `${targetDate || '目标日'}未取得同日PMS与OTA严格事实；当前回读日 ${factLayerDate} 不用于替代或计入完成度。`;
        }

        const presentRowCount = Number.isFinite(Number(present.snapshot_row_count))
            ? Number(present.snapshot_row_count)
            : 0;
        let todayStatus = presentRowCount > 0
            ? (String(present.status || '') === 'ready' ? '已取得' : '部分取得')
            : '未取得';
        if (loading) todayStatus = '正在读取';
        if (loadError) todayStatus = '读取失败';
        const todaySummary = loadError
            ? `今日状态读取失败：${loadError}`
            : (loading
                ? '正在确认今天已经取得的快照状态。'
                : (presentRowCount > 0
                    ? `今天已取得 ${presentRowCount} 条 OTA 实时快照${present.as_of_time ? `，最近更新 ${present.as_of_time}` : ''}。`
                    : '今天尚未取得有效 OTA 实时快照；当前只显示采集状态。'));

        const safeFutureCard = futureCard && typeof futureCard === 'object' ? futureCard : {};
        let futureStatus = String(future.status || '') === 'ready' ? '已形成' : '尚未形成';
        if (loading) futureStatus = '正在读取';
        if (loadError) futureStatus = '读取失败';
        const futureValue = loadError
            ? '读取失败'
            : (loading ? '读取中' : (futureStatus === '已形成' ? (safeFutureCard.value || '已形成预测版本') : '尚未形成'));
        const futureDetail = loadError
            ? loadError
            : (futureStatus === '已形成'
                ? (safeFutureCard.detail || '已形成未来趋势研判，需结合事实复核。')
                : (future.message || '历史事实足够后才形成预测版本；不直接给出执行价格。'));

        const metricCards = Array.isArray(revenueMetricCards) ? revenueMetricCards : [];
        const verifiedWholeHotelCards = metricCards.filter((card) => {
            const truthStatus = String(card?.truthStatus || card?.truth?.status || '').toLowerCase();
            return truthStatus === 'verified' && /全酒店/.test(String(card?.scopeLabel || card?.truth?.scope_label || ''));
        });
        const verifiedSourceMethods = verifiedWholeHotelCards.flatMap((card) => {
            const methods = card?.truth?.source?.methods;
            return Array.isArray(methods) ? methods.map(method => String(method || '').toLowerCase()) : [];
        });
        const hasPmsSource = wholeHotelReady
            || verifiedSourceMethods.some(method => /(pms|crs|property_management)/.test(method));
        const hasImportSource = verifiedSourceMethods.some(method => /(import|manual|excel|daily_report|file)/.test(method));
        const hasUnclassifiedWholeHotel = verifiedWholeHotelCards.length > 0
            || String(revenueOverviewScope || '').toLowerCase() === 'hotel';
        const scopeRows = [
            {
                key: 'ota',
                label: 'OTA渠道',
                status: yesterdayStatus,
                statusClass: homeBusinessStatusClass(yesterdayStatus),
                detail: temporal.scope_note || '只代表已授权携程/美团渠道，不等于全酒店经营结果。',
            },
            {
                key: 'pms',
                label: '全酒店（PMS / CRS）',
                status: hasPmsSource ? '已验证' : '未验证',
                statusClass: homeBusinessStatusClass(hasPmsSource ? '已验证' : '未验证'),
                detail: hasPmsSource
                    ? '已识别经验证的全酒店 PMS / CRS 来源；按其经营日期与分母口径使用。'
                    : (hasUnclassifiedWholeHotel
                        ? '存在全酒店口径信号，但尚未验证为 PMS / CRS 来源。'
                        : '未取得可核验 PMS / CRS 事实；不使用 OTA 数据外推全酒店 OCC、RevPAR 或总营收。'),
            },
            {
                key: 'import',
                label: '全酒店（规范导入）',
                status: hasImportSource ? '已验证' : '未验证',
                statusClass: homeBusinessStatusClass(hasImportSource ? '已验证' : '未验证'),
                detail: hasImportSource
                    ? '已识别经验证的规范导入来源；仍按导入日期、字段和质量状态单独使用。'
                    : '导入数据未验证时不与 OTA 渠道事实混算，也不冒充 PMS 实时数据。',
            },
        ];

        const yesterday = {
            date: targetDate || '目标日待确认',
            status: yesterdayStatus,
            statusClass: homeBusinessStatusClass(yesterdayStatus),
            summary: yesterdaySummary,
            facts,
            wholeHotelFacts,
            wholeHotelDerivedFacts,
            otaChannelFacts,
            otaPlatformRows,
            dateSourceRows,
            reconciliationRows,
            reconciliationStatus: homeReconciliationStatusText(reconciliation.status),
            reconciliationStatusClass: homeBusinessStatusClass(
                reconciliation.status === 'blocked'
                    ? '读取失败'
                    : (reconciliation.status === 'matched_with_scope_caveats' ? '已验证' : '部分取得')
            ),
            dateAlignmentStatus: String(dateAlignment.status || 'incomplete'),
            dateAlignmentMessage: String(dateAlignment.message || '尚未取得三源同日证据。'),
            dualScopeReady,
            requiresHotelSelection: selectedHotelKey === '',
            hotelScopeMismatch: factLayerHotelMismatch,
            sourceText: factLayerMatchesTarget
                ? `PMS全酒店住宿事实 + 携程/美团OTA渠道事实 · ${targetDate} · 保存与精确回读证据见昨日经营闭环`
                : (factLayerDate && !factLayerHotelMismatch
                    ? `严格经营事实层 · 目标日 ${targetDate || '待确认'} 未命中 · 当前回读日 ${factLayerDate} 不作替代`
                    : `${platformText} OTA · ${targetDate || '目标日待确认'}定稿事实 · 入库与回读证据见数据健康`),
        };
        const today = {
            status: todayStatus,
            statusClass: homeBusinessStatusClass(todayStatus),
            summary: todaySummary,
            detail: '这里只报告今天已取得到哪一步，不把进行中快照写成日终经营结果。',
        };
        const futureStage = {
            status: futureStatus,
            statusClass: homeBusinessStatusClass(futureStatus),
            value: futureValue,
            detail: futureDetail,
            note: 'AI辅助研判 · OTA渠道输入 · 置信度为未校准规则指数 · 不自动执行',
        };
        return {
            hotelName: hotelName && hotelName !== '全部门店' ? hotelName : '全部可见门店汇总',
            yesterday,
            today,
            future: futureStage,
            scopeRows,
            timeline: [
                {
                    key: 'yesterday',
                    testid: 'home-yesterday-stage',
                    label: '昨天事实',
                    value: yesterday.date,
                    detail: '只读取目标日已定稿事实；旧日期不替代，缺失不显示为 0。',
                    status: yesterday.status,
                    statusClass: yesterday.statusClass,
                },
                {
                    key: 'today',
                    testid: 'home-today-acquired-status',
                    label: '今天状态',
                    value: today.summary,
                    detail: today.detail,
                    status: today.status,
                    statusClass: today.statusClass,
                },
                {
                    key: 'future',
                    testid: 'home-future-ai-judgement',
                    label: '未来 AI 研判',
                    value: futureStage.value,
                    detail: `${futureStage.detail}；${futureStage.note}`,
                    status: futureStage.status,
                    statusClass: futureStage.statusClass,
                },
            ],
        };
    };

    const homeOperatingScheduleDatePart = (value) => String(value || '').trim().slice(0, 10);
    const homeOperatingScheduleTimePart = (value) => {
        const text = String(value || '').trim();
        return /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(text) ? text.slice(11, 16) : '';
    };
    const homeOperatingScheduleDateInRange = (today, start, end) => {
        const target = homeOperatingScheduleDatePart(today);
        const startDate = homeOperatingScheduleDatePart(start);
        const endDate = homeOperatingScheduleDatePart(end);
        if (!target || !startDate) return false;
        return startDate <= target && (!endDate || endDate === '0000-00-00' || target <= endDate);
    };
    const homeOperatingScheduleSourceLabel = (item = {}) => {
        const recommendation = item?.recommendation || {};
        const sourceModule = String(recommendation.source_module || '').trim();
        const platform = String(recommendation.platform || '').trim().toLowerCase();
        const moduleLabel = {
            manual: '人工创建',
            ota_diagnosis: '历史 OTA 诊断',
            ota_diagnosis_saved: 'OTA 诊断',
            daily_workbench_patrol: '经营巡检',
            ai_daily_report: 'AI 经营日报',
            price_suggestion: '收益调价建议',
            temporal_forecast_recommendation: '趋势预测建议',
            operation_optimizer: '运营优化器',
            strategy_simulation: '策略模拟',
        }[sourceModule] || sourceModule || '来源未返回';
        const platformLabel = { ctrip: '携程', meituan: '美团', pms: 'PMS' }[platform] || platform;
        return platformLabel ? `${platformLabel} · ${moduleLabel}` : moduleLabel;
    };
    const homeOperatingScheduleActionTitle = (item = {}, helpers = {}) => {
        if (typeof helpers.actionText === 'function') {
            const resolved = String(helpers.actionText(item) || '').trim();
            if (resolved) return resolved;
        }
        const recommendation = item?.recommendation || {};
        const actionType = String(recommendation.action_type || '').trim();
        const objectType = String(recommendation.object_type || '').trim();
        const actionLabel = {
            complete_public_page_evidence: '补齐公开页证据',
            review_public_page_evidence: '复核公开页证据',
            manual_forecast_review: '预测复核',
            booking_conversion_optimization: '下单转化核查',
            listing_conversion_optimization: '列表转化核查',
            service_quality_improvement: '服务质量核查',
            advertising_optimization: '广告优化',
            ota_operation_follow_up: 'OTA 运营跟进',
        }[actionType] || actionType;
        const objectLabel = {
            price: '价格',
            inventory: '房态',
            campaign: '活动',
            data_collection: '证据采集',
            operation_checklist: '运营核查',
        }[objectType] || objectType;
        return [objectLabel, actionLabel].filter(Boolean).join(' · ') || '未命名运营任务';
    };
    const homeOperatingScheduleStatus = (item = {}, today = '') => {
        const stage = String(item?.stage || '').trim().toLowerCase();
        const reviewAvailableAt = String(item?.review?.available_at || item?.review?.available_on || '').trim();
        const reviewDate = homeOperatingScheduleDatePart(reviewAvailableAt);
        const reviewDue = !!reviewDate && reviewDate <= homeOperatingScheduleDatePart(today);
        if (stage === 'blocked') {
            return { code: 'blocked', label: '已阻塞', rank: 0, toneClass: 'border-red-200 bg-red-50 text-red-700', accentClass: 'border-l-red-400' };
        }
        if (stage === 'failed') {
            return { code: 'failed', label: '执行失败', rank: 0, toneClass: 'border-red-200 bg-red-50 text-red-700', accentClass: 'border-l-red-400' };
        }
        if (stage === 'rejected') {
            return { code: 'rejected', label: '已驳回', rank: 0, toneClass: 'border-orange-200 bg-orange-50 text-orange-700', accentClass: 'border-l-orange-400' };
        }
        if (stage === 'approval') {
            return { code: 'approval', label: '待审批', rank: 1, toneClass: 'border-amber-200 bg-amber-50 text-amber-700', accentClass: 'border-l-amber-400' };
        }
        if (stage === 'execution') {
            return { code: 'execution', label: '待执行', rank: 2, toneClass: 'border-blue-200 bg-blue-50 text-blue-700', accentClass: 'border-l-blue-400' };
        }
        if (stage === 'evidence') {
            return { code: 'evidence', label: '待补来源事实', rank: 3, toneClass: 'border-orange-200 bg-orange-50 text-orange-700', accentClass: 'border-l-orange-400' };
        }
        if (stage === 'review') {
            return reviewDue
                ? { code: 'review', label: '待复盘', rank: 4, toneClass: 'border-violet-200 bg-violet-50 text-violet-700', accentClass: 'border-l-violet-400' }
                : { code: 'waiting_review', label: '等待复盘', rank: 5, toneClass: 'border-slate-200 bg-slate-50 text-slate-600', accentClass: 'border-l-slate-300' };
        }
        if (stage === 'reviewed') {
            return { code: 'reviewed', label: '已复盘', rank: 8, toneClass: 'border-emerald-200 bg-emerald-50 text-emerald-700', accentClass: 'border-l-emerald-400' };
        }
        return { code: 'unknown', label: '状态未返回', rank: 6, toneClass: 'border-slate-200 bg-slate-50 text-slate-600', accentClass: 'border-l-slate-300' };
    };
    const homeOperatingScheduleMoment = (item = {}, status = {}, today = '') => {
        const candidates = status.code === 'review' || status.code === 'waiting_review'
            ? [item?.review?.available_at, item?.assignment?.review_at, item?.execution?.executed_at]
            : (status.code === 'execution'
                ? [item?.assignment?.due_at, item?.approval?.approved_at, item?.recommendation?.date_start]
                : (status.code === 'approval'
                    ? [item?.assignment?.due_at, item?.recommendation?.created_at, item?.recommendation?.date_start]
                    : [item?.execution?.executed_at, item?.review?.available_at, item?.assignment?.due_at, item?.recommendation?.created_at]));
        const value = candidates.map(candidate => String(candidate || '').trim()).find(Boolean) || '';
        const date = homeOperatingScheduleDatePart(value);
        const time = homeOperatingScheduleTimePart(value);
        const target = homeOperatingScheduleDatePart(today);
        if (!date) return { value: '', date: '', timeText: '未排期', fullText: '未返回排期时间' };
        if (date < target && !['reviewed', 'failed', 'rejected'].includes(status.code)) {
            return { value, date, timeText: '已逾期', fullText: value };
        }
        if (date === target) return { value, date, timeText: time || '今日', fullText: value };
        return { value, date, timeText: date.slice(5), fullText: value };
    };
    const homeOperatingScheduleRelevant = (item = {}, today = '', status = {}) => {
        const recommendation = item?.recommendation || {};
        const dates = [
            recommendation.created_at,
            item?.approval?.approved_at,
            item?.execution?.executed_at,
            item?.assignment?.due_at,
            item?.assignment?.review_at,
            item?.review?.available_at,
            item?.review?.available_on,
        ].map(homeOperatingScheduleDatePart).filter(Boolean);
        const exactToday = homeOperatingScheduleDatePart(today);
        const active = !['reviewed', 'failed', 'rejected', 'blocked'].includes(status.code);
        const dueDate = homeOperatingScheduleDatePart(item?.assignment?.due_at);
        const reviewDate = homeOperatingScheduleDatePart(item?.review?.available_at || item?.review?.available_on);
        const scheduledToday = homeOperatingScheduleDateInRange(exactToday, recommendation.date_start, recommendation.date_end);
        const eventToday = dates.includes(exactToday);
        const overdueActive = active && ((dueDate && dueDate < exactToday) || (reviewDate && reviewDate < exactToday));
        const unscheduledActive = active && !recommendation.date_start && !dueDate && !reviewDate;
        return scheduledToday || eventToday || overdueActive || unscheduledActive;
    };
    const buildHomeOperatingScheduleModel = ({
        flow = {},
        today = '',
        scopeHotelName = '',
        selectedHotelId = '',
        yesterday = {},
        loading = false,
        error = '',
        lastReadAt = '',
        maxItems = 7,
        helpers = {},
    } = {}) => {
        const safeFlow = flow && typeof flow === 'object' ? flow : {};
        const allItems = Array.isArray(safeFlow.list) ? safeFlow.list : [];
        const targetDate = homeOperatingScheduleDatePart(today) || '日期未返回';
        const hotelNameForId = typeof helpers.hotelNameForId === 'function' ? helpers.hotelNameForId : () => '';
        const rows = allItems.map((item) => {
            const status = homeOperatingScheduleStatus(item, targetDate);
            if (!homeOperatingScheduleRelevant(item, targetDate, status)) return null;
            const recommendation = item?.recommendation || {};
            const hotelId = Number(item?.hotel_id || 0);
            const hotelName = String(hotelNameForId(hotelId) || '').trim() || (hotelId > 0 ? `酒店 #${hotelId}（名称未返回）` : '酒店身份未返回');
            const startDate = homeOperatingScheduleDatePart(recommendation.date_start);
            const endDate = homeOperatingScheduleDatePart(recommendation.date_end);
            const dateText = startDate
                ? (endDate && endDate !== startDate && endDate !== '0000-00-00' ? `${startDate} 至 ${endDate}` : startDate)
                : '业务日期未返回';
            const sourceRef = String(recommendation.source || '').trim() || '来源记录未返回';
            const moment = homeOperatingScheduleMoment(item, status, targetDate);
            return {
                kind: 'task',
                key: `task-${Number(item?.id || 0)}`,
                intentId: Number(item?.id || 0),
                hotelId,
                hotelName,
                businessDateText: dateText,
                title: homeOperatingScheduleActionTitle(item, helpers),
                sourceLabel: typeof helpers.sourceText === 'function'
                    ? String(helpers.sourceText(item) || '').trim() || homeOperatingScheduleSourceLabel(item)
                    : homeOperatingScheduleSourceLabel(item),
                sourceRef,
                statusCode: status.code,
                statusLabel: status.label,
                statusClass: status.toneClass,
                accentClass: status.accentClass,
                stage: String(item?.stage || ''),
                timeText: moment.timeText,
                timeFullText: moment.fullText,
                sortRank: status.rank,
                sortValue: moment.value || '9999-12-31 23:59:59',
                nextAction: String(item?.next_action?.label || item?.next_action?.key || '').trim(),
                blockedReason: String(item?.approval?.blocked_reason || item?.execution?.blocked_reason || item?.review?.failure_reason || '').trim(),
            };
        }).filter(Boolean).sort((left, right) => (
            left.sortRank - right.sortRank
            || String(left.sortValue).localeCompare(String(right.sortValue))
            || right.intentId - left.intentId
        ));
        const counts = rows.reduce((result, row) => {
            result[row.statusCode] = (result[row.statusCode] || 0) + 1;
            return result;
        }, {});
        const visibleRows = rows.slice(0, Math.max(1, Number(maxItems || 7)));
        const dataStatus = String(safeFlow.data_status || '').trim();
        const hasReadback = dataStatus !== '' || Object.prototype.hasOwnProperty.call(safeFlow, 'list');
        let stateCode = 'ready';
        let stateLabel = rows.length ? `已读取 ${rows.length} 项` : '今日暂无任务';
        let notice = '';
        if (String(error || '').trim()) {
            stateCode = hasReadback ? 'refresh_failed' : 'failed';
            stateLabel = '读取失败';
            notice = hasReadback
                ? `刷新失败：${String(error).trim()}。下方保留上次成功回读，不能视为当前最新状态。`
                : `读取失败：${String(error).trim()}。`;
        } else if (loading) {
            stateCode = hasReadback ? 'refreshing' : 'loading';
            stateLabel = hasReadback ? '正在刷新' : '正在读取';
            notice = hasReadback ? '正在刷新今日编排；下方为上次成功回读。' : '正在读取今日编排。';
        } else if (dataStatus === '待接入真实数据') {
            stateCode = 'waiting';
            stateLabel = '等待数据';
            notice = '执行意图数据尚未接入；当前不显示为无任务或已完成。';
        } else if (dataStatus === 'partial' || (Array.isArray(safeFlow.data_gaps) && safeFlow.data_gaps.length)) {
            stateCode = 'partial';
            stateLabel = `部分读取 · ${rows.length} 项`;
            notice = '部分任务、执行证据或复盘数据未完整返回；已显示项仍按各自真实状态呈现。';
        }
        const stateClass = {
            ready: 'border-blue-200 bg-blue-50 text-blue-700',
            refreshing: 'border-blue-200 bg-blue-50 text-blue-700',
            loading: 'border-blue-200 bg-blue-50 text-blue-700',
            partial: 'border-amber-200 bg-amber-50 text-amber-700',
            waiting: 'border-slate-200 bg-slate-50 text-slate-600',
            refresh_failed: 'border-red-200 bg-red-50 text-red-700',
            failed: 'border-red-200 bg-red-50 text-red-700',
        }[stateCode] || 'border-slate-200 bg-slate-50 text-slate-600';
        const yesterdayStatus = String(yesterday?.status || '未取得');
        const fact = {
            kind: 'fact',
            key: 'yesterday-fact',
            hotelName: String(scopeHotelName || '').trim() || '酒店范围未返回',
            businessDateText: String(yesterday?.date || '目标日待确认'),
            title: yesterdayStatus === '已取得'
                ? '昨日经营事实已读取'
                : (yesterdayStatus === '读取失败' ? '昨日经营事实读取失败' : '昨日经营事实尚未完整取得'),
            sourceLabel: String(yesterday?.sourceText || '来源未返回'),
            sourceRef: '数据健康 · OTA 渠道事实',
            statusCode: yesterdayStatus === '已取得' ? 'fact_ready' : (yesterdayStatus === '读取失败' ? 'failed' : 'fact_waiting'),
            statusLabel: yesterdayStatus,
            statusClass: String(yesterday?.statusClass || 'border-slate-200 bg-slate-50 text-slate-600'),
            accentClass: yesterdayStatus === '已取得' ? 'border-l-sky-400' : (yesterdayStatus === '读取失败' ? 'border-l-red-400' : 'border-l-slate-300'),
            timeText: '事实',
            timeFullText: String(yesterday?.date || '目标日待确认'),
        };
        return {
            date: targetDate,
            scopeHotelId: String(selectedHotelId || ''),
            scopeHotelName: String(scopeHotelName || '').trim() || '酒店范围未返回',
            stateCode,
            stateLabel,
            stateClass,
            notice,
            fact,
            items: visibleRows,
            total: rows.length,
            hiddenCount: Math.max(0, rows.length - visibleRows.length),
            counts,
            lastReadAt: String(lastReadAt || '').trim(),
            isInitialLoading: stateCode === 'loading',
            isEmpty: stateCode === 'ready' && rows.length === 0,
        };
    };
    const HomeOperatingOrchestration = {
        name: 'HomeOperatingOrchestration',
        props: {
            model: { type: Object, default: () => ({}) },
            loading: { type: Boolean, default: false },
            currentClockText: { type: String, default: '' },
        },
        emits: ['refresh', 'open', 'openAll'],
        render() {
            const h = window.Vue?.h;
            if (typeof h !== 'function') return null;
            const model = this.model || {};
            const pill = (label, className) => h('span', {
                class: ['rounded-full border px-2 py-0.5 text-[11px]', className],
            }, label || '状态未返回');
            const entry = (item = {}, fact = false) => h('button', {
                type: 'button',
                class: ['home-orchestration-entry', fact ? 'is-fact' : '', item.accentClass || 'border-l-slate-300'],
                'data-testid': fact ? 'home-operating-fact-entry' : undefined,
                'data-intent-id': fact ? undefined : item.intentId,
                onClick: () => this.$emit('open', item),
            }, [
                h('div', { class: 'home-orchestration-time' }, [
                    h('strong', null, item.timeText || '未排期'),
                    h('span', { title: item.timeFullText || '' }, fact
                        ? (item.businessDateText || '目标日待确认')
                        : (item.businessDateText === model.date ? '今日' : String(item.businessDateText || '').slice(5) || '日期未返回')),
                ]),
                h('div', { class: 'home-orchestration-content' }, [
                    h('div', { class: 'home-orchestration-entry-heading' }, [
                        h('strong', null, item.title || '未命名任务'),
                        pill(item.statusLabel, item.statusClass),
                    ]),
                    h('p', null, `酒店 ${item.hotelName || '身份未返回'} · 业务日 ${item.businessDateText || '未返回'}`),
                    h('small', { title: [item.sourceLabel, item.sourceRef].filter(Boolean).join(' · ') },
                        `来源 ${item.sourceLabel || '未返回'}${item.sourceRef ? ` · ${item.sourceRef}` : ''}`),
                    item.blockedReason
                        ? h('em', { class: 'is-error' }, item.blockedReason)
                        : (item.nextAction ? h('em', null, `下一步 ${item.nextAction}`) : null),
                ]),
            ]);
            const taskItems = Array.isArray(model.items) ? model.items : [];
            const body = model.isInitialLoading
                ? h('div', {
                    class: 'home-orchestration-body',
                    'data-testid': 'home-operating-orchestration-loading',
                }, [1, 2, 3].map(index => h('div', {
                    key: index,
                    class: 'mb-3 h-20 animate-pulse rounded-xl border border-slate-100 bg-slate-50',
                })))
                : h('div', { class: 'home-orchestration-body' }, [
                    entry(model.fact || {}, true),
                    h('div', { class: 'home-orchestration-now', 'aria-hidden': 'true' }, [
                        h('span'), h('b', null, '现在'), h('span'),
                    ]),
                    taskItems.length
                        ? h('div', { class: 'space-y-3', 'data-testid': 'home-operating-task-list' }, taskItems.map(item => entry(item)))
                        : h('div', { class: 'home-orchestration-empty', 'data-testid': 'home-operating-orchestration-empty' }, [
                            h('strong', null, model.stateCode === 'waiting' ? '任务数据仍在等待接入' : '今天没有匹配的运营任务'),
                            h('p', null, '这不等于经营已完成；可进入任务执行与复盘查看其他日期或补建人工动作。'),
                            h('button', { type: 'button', onClick: () => this.$emit('openAll') }, '进入任务执行与复盘'),
                        ]),
                    Number(model.hiddenCount || 0) > 0
                        ? h('button', {
                            type: 'button',
                            class: 'home-orchestration-more',
                            onClick: () => this.$emit('openAll'),
                        }, `另有 ${model.hiddenCount} 项，进入任务执行与复盘查看`)
                        : null,
                ]);
            return h('section', {
                class: 'home-orchestration',
                'data-testid': 'home-operating-orchestration',
            }, [
                h('div', { class: 'home-orchestration-header' }, [
                    h('div', { class: 'home-orchestration-copy' }, [
                        h('div', { class: 'home-orchestration-heading-row' }, [
                            h('p', { class: 'home-orchestration-eyebrow' }, 'TODAY · OPERATING ORCHESTRATION'),
                            pill(model.stateLabel, model.stateClass),
                        ]),
                        h('h2', null, '今日经营编排'),
                        h('p', null, `${model.scopeHotelName || '酒店范围未返回'} · ${model.date || '日期未返回'} · 每项均标明酒店、业务日期、来源与回读状态`),
                        model.lastReadAt ? h('small', null, `最近回读 ${model.lastReadAt}`) : null,
                    ]),
                    h('div', { class: 'home-orchestration-actions' }, [
                        h('span', { class: 'home-orchestration-clock' }, `现在 ${this.currentClockText || '--:--'}`),
                        h('button', {
                            type: 'button',
                            class: 'home-orchestration-secondary',
                            disabled: this.loading,
                            onClick: () => this.$emit('refresh'),
                        }, this.loading ? '刷新中' : '刷新编排'),
                        h('button', {
                            type: 'button',
                            class: 'home-orchestration-primary',
                            onClick: () => this.$emit('openAll'),
                        }, '查看全部 →'),
                    ]),
                ]),
                model.notice ? h('div', {
                    class: ['home-orchestration-notice', model.stateClass],
                    'data-testid': 'home-operating-orchestration-notice',
                }, model.notice) : null,
                body,
            ]);
        },
    };
    const HomeYesterdayOperatingFacts = {
        name: 'HomeYesterdayOperatingFacts',
        props: {
            model: { type: Object, default: () => ({}) },
            showHeader: { type: Boolean, default: false },
            showControls: { type: Boolean, default: false },
            hotelOptions: { type: Array, default: () => [] },
            selectedHotelId: { type: [String, Number], default: '' },
            refreshing: { type: Boolean, default: false },
        },
        emits: ['update:selectedHotelId', 'refresh'],
        render() {
            const h = window.Vue?.h;
            const Fragment = window.Vue?.Fragment;
            if (typeof h !== 'function' || !Fragment) return null;
            const model = this.model && typeof this.model === 'object' ? this.model : {};
            const yesterday = model.yesterday && typeof model.yesterday === 'object'
                ? model.yesterday
                : {};
            const statusPill = (label, className) => h('span', {
                class: ['home-status-pill', className],
            }, label || '待核验');
            const factCard = (fact) => h('div', {
                key: fact?.key,
                class: 'rounded-xl border border-white bg-white px-3 py-3 shadow-sm',
            }, [
                h('div', { class: 'flex items-center justify-between gap-2' }, [
                    h('span', { class: 'text-xs font-medium text-slate-600' }, fact?.label || '指标'),
                    statusPill(fact?.status, fact?.statusClass),
                ]),
                h('strong', { class: 'mt-2 block text-lg text-slate-900' }, fact?.value || '未取得'),
                h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, fact?.detail || '口径说明未返回。'),
            ]);
            const derivedCard = (fact) => h('div', {
                key: fact?.key,
                class: 'rounded-xl border border-emerald-100 bg-white px-3 py-3',
            }, [
                h('span', { class: 'text-xs font-medium text-slate-500' }, fact?.label || '派生指标'),
                h('strong', { class: 'mt-1 block text-base text-slate-900' }, fact?.value || '未取得'),
            ]);
            const platformCard = (platform) => h('div', {
                key: platform?.key,
                class: 'rounded-xl border border-blue-100 bg-white p-3',
            }, [
                h('div', { class: 'flex items-center justify-between gap-2' }, [
                    h('strong', { class: 'text-sm text-slate-800' }, `${platform?.label || 'OTA'} · ${platform?.date || '日期未取得'}`),
                    statusPill(platform?.status, platform?.statusClass),
                ]),
                h('div', { class: 'mt-2 grid grid-cols-2 gap-2 text-xs' }, (
                    Array.isArray(platform?.facts) ? platform.facts : []
                ).map(fact => h('div', {
                    key: fact?.key,
                    class: 'rounded-lg bg-slate-50 px-2 py-2',
                }, [
                    h('span', { class: 'text-slate-500' }, fact?.label || '指标'),
                    h('strong', { class: 'mt-1 block text-slate-800' }, fact?.value || '未取得'),
                ]))),
            ]);
            const pmsPanel = h('article', {
                class: 'rounded-2xl border border-emerald-200 bg-emerald-50 p-4',
                'data-testid': 'home-whole-hotel-scope',
            }, [
                h('strong', { class: 'text-base text-slate-900' }, '全酒店口径（PMS）'),
                h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, '住宿经营事实；住宿房费与支付实收严格分开。'),
                h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-2' }, (
                    Array.isArray(yesterday.wholeHotelFacts) ? yesterday.wholeHotelFacts : []
                ).map(factCard)),
                h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-3' }, (
                    Array.isArray(yesterday.wholeHotelDerivedFacts) ? yesterday.wholeHotelDerivedFacts : []
                ).map(derivedCard)),
            ]);
            const otaPanel = h('article', {
                class: 'rounded-2xl border border-blue-200 bg-blue-50 p-4',
                'data-testid': 'home-ota-channel-scope',
            }, [
                h('strong', { class: 'text-base text-slate-900' }, 'OTA 渠道口径（携程 + 美团）'),
                h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, '渠道订单、房晚、价格、流量与转化；不代表整家酒店。'),
                h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3' }, (
                    Array.isArray(yesterday.otaChannelFacts) ? yesterday.otaChannelFacts : []
                ).map(factCard)),
                h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-2' }, (
                    Array.isArray(yesterday.otaPlatformRows) ? yesterday.otaPlatformRows : []
                ).map(platformCard)),
            ]);
            const dateRows = h('div', { class: 'mt-3 grid gap-2 sm:grid-cols-3' }, (
                Array.isArray(yesterday.dateSourceRows) ? yesterday.dateSourceRows : []
            ).map(row => h('div', {
                key: row?.key,
                class: 'rounded-xl border border-slate-200 bg-white px-3 py-2',
            }, [
                h('span', { class: 'text-xs text-slate-500' }, row?.label || '来源业务日'),
                h('div', { class: 'mt-1 flex items-center justify-between gap-2' }, [
                    h('strong', { class: 'text-sm text-slate-800' }, row?.date || '未取得'),
                    statusPill(row?.status, row?.statusClass),
                ]),
            ])));
            const checkRows = h('div', { class: 'mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3' }, (
                Array.isArray(yesterday.reconciliationRows) ? yesterday.reconciliationRows : []
            ).map(row => h('div', {
                key: row?.key,
                class: 'rounded-xl border border-slate-200 bg-white px-3 py-3',
            }, [
                h('div', { class: 'flex items-center justify-between gap-2' }, [
                    h('strong', { class: 'text-sm text-slate-800' }, row?.label || '对账项'),
                    statusPill(row?.status, row?.statusClass),
                ]),
                row?.value ? h('strong', { class: 'mt-2 block text-xs text-slate-700' }, row.value) : null,
                h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, row?.detail || '尚无对账说明。'),
            ])));
            const reconciliation = h('article', {
                class: 'mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4',
                'data-testid': 'home-reconciliation-facts',
            }, [
                h('div', { class: 'flex flex-wrap items-start justify-between gap-2' }, [
                    h('div', null, [
                        h('strong', { class: 'text-base text-slate-900' }, 'PMS 与 OTA 对账'),
                        h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, `目标日 ${yesterday.date || '待确认'} · ${yesterday.dateAlignmentMessage || '尚未取得三源同日证据。'}`),
                    ]),
                    statusPill(yesterday.reconciliationStatus, yesterday.reconciliationStatusClass),
                ]),
                dateRows,
                checkRows,
            ]);
            const controls = this.showControls ? h('div', {
                class: 'flex flex-wrap items-center justify-end gap-2',
            }, [
                h('select', {
                    class: 'input-field',
                    value: String(this.selectedHotelId || ''),
                    'aria-label': '昨日经营事实门店',
                    onChange: event => this.$emit(
                        'update:selectedHotelId',
                        String(event?.target?.value || '')
                    ),
                }, [
                    h('option', { value: '' }, '请选择具体门店'),
                    ...this.hotelOptions.map(hotel => h('option', {
                        key: hotel?.id,
                        value: String(hotel?.id || ''),
                    }, hotel?.name || `门店 ${hotel?.id || ''}`)),
                ]),
                h('button', {
                    type: 'button',
                    class: 'compass-primary-cta disabled:opacity-60',
                    disabled: this.refreshing,
                    onClick: () => this.$emit('refresh'),
                }, this.refreshing ? '刷新中' : '刷新事实'),
            ]) : null;
            const header = this.showHeader ? h('div', {
                class: 'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between',
            }, [
                h('div', { class: 'min-w-0' }, [
                    h('p', { class: 'text-xs font-semibold uppercase tracking-wide text-amber-700' }, '昨日经营事实'),
                    h('h2', { class: 'mt-1 text-xl font-semibold text-slate-900' }, `${model.hotelName || '门店'} · ${yesterday.date || '目标日待确认'}`),
                    h('p', { class: 'mt-1 text-sm leading-6 text-slate-600' }, yesterday.summary || '等待读取经营事实。'),
                ]),
                h('div', { class: 'flex flex-col items-end gap-2' }, [
                    statusPill(yesterday.reconciliationStatus, yesterday.reconciliationStatusClass),
                    controls,
                ]),
            ]) : null;
            const body = yesterday.requiresHotelSelection
                ? h('div', {
                    class: 'mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900',
                    'data-testid': 'home-yesterday-hotel-required',
                }, '请选择一个具体门店后读取事实。PMS 与 OTA 不允许跨门店汇总后对账。')
                : h(Fragment, null, [
                    h('div', {
                        class: 'mt-4 grid gap-4 lg:grid-cols-2',
                        'data-testid': 'home-yesterday-dual-scope',
                    }, [pmsPanel, otaPanel]),
                    reconciliation,
                    h('p', { class: 'mt-3 text-xs text-slate-500' }, yesterday.sourceText || ''),
                ]);
            return h('section', {
                class: this.showHeader
                    ? 'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5'
                    : 'mt-5',
                'data-testid': this.showHeader
                    ? 'home-yesterday-operating-facts'
                    : 'home-yesterday-facts',
            }, [header, body]);
        },
    };
    const HomeBusinessTimeAxis = {
        name: 'HomeBusinessTimeAxis',
        props: {
            model: { type: Object, default: () => ({}) },
            competitorReadiness: { type: Object, default: () => ({}) },
            generating: { type: Boolean, default: false },
            selectedHotelId: { type: [String, Number], default: '' },
        },
        emits: ['generate'],
        render() {
            const h = window.Vue?.h;
            if (typeof h !== 'function') return null;
            const stages = Array.isArray(this.model?.timeline) ? this.model.timeline : [];
            return h(window.Vue.Fragment, null, [
                h('details', {
                    open: true,
                    class: 'compass-temporal-fold',
                    'data-testid': 'home-temporal-axis',
                }, [
                    h('summary', { class: 'compass-temporal-summary', 'data-testid': 'home-temporal-toggle' }, [
                        h('span', { class: 'compass-temporal-summary-title' }, [h('span', { 'aria-hidden': 'true' }, '时'), '昨天事实 / 今天状态 / 未来 AI 研判']),
                        h('span', { class: 'compass-temporal-summary-note' }, '今天不写成日终，预测不写成事实'),
                    ]),
                    h('div', { class: 'home-temporal-grid' }, stages.map(stage => h('article', {
                        key: stage.key,
                        class: 'home-temporal-stage',
                        'data-testid': stage.testid,
                    }, [
                        h('div', null, [
                            h('span', null, stage.label),
                            h('span', { class: ['rounded-full border px-2 py-0.5 text-[10px]', stage.statusClass] }, stage.status),
                        ]),
                        h('strong', null, stage.value),
                        h('p', null, stage.detail),
                        stage.key === 'future' ? h('button', {
                            type: 'button',
                            disabled: this.generating || !this.selectedHotelId,
                            onClick: () => this.$emit('generate'),
                        }, this.generating ? '正在生成研判' : (this.selectedHotelId ? '生成未来研判' : '先选择一家门店')) : null,
                    ]))),
                ]),
                h('details', { class: 'home-competitor-reference', 'data-testid': 'home-competitor-diagnostic-reference' }, [
                    h('summary', null, `竞对异常诊断参考（非首页主线） · ${this.competitorReadiness?.label || '未取得'}`),
                    h('p', null, '仅在本店事实异常且同平台、同日期、同口径可比时参考；不替代本店事实，不自动决定价格。'),
                ]),
            ]);
        },
    };

    const isHomeSignalReady = (signal) => !!signal && !['pending', 'unknown'].includes(String(signal.status || 'pending'));

    const buildHomeDataSources = ({
        sampleDays = 0,
        trendReady = false,
        trendUpdatedAt = '',
        channelSignal = null,
        priceSignal = null,
        weatherSignal = null,
        weatherCount = 0,
        nearestHoliday = null,
        holidayUpdatedAt = '',
        compassLastSyncedAt = '',
    } = {}) => {
        const normalizedSampleDays = Number(sampleDays || 0);
        const channelReady = isHomeSignalReady(channelSignal);
        const priceReady = isHomeSignalReady(priceSignal);
        const weatherReady = Number(weatherCount || 0) > 0;
        const holidayReady = !!nearestHoliday;
        return [
            {
                name: '经营趋势样本',
                status: trendReady ? `可用 ${normalizedSampleDays}天` : '样本不足',
                updatedAt: trendUpdatedAt || '--',
                impact: '会影响收益、入住、ADR、RevPAR 等趋势判断',
                role: 'core',
                ready: !!trendReady,
                className: trendReady ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200',
            },
            {
                name: 'OTA 渠道数据',
                status: channelReady ? '已同步' : '未同步',
                updatedAt: channelSignal?.updated_at || '--',
                impact: '会影响曝光、访客、转化和订单质量判断',
                role: 'core',
                ready: channelReady,
                className: channelReady ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200',
            },
            {
                name: '竞对价格',
                status: priceReady ? '已同步' : '未同步',
                updatedAt: priceSignal?.updated_at || '--',
                impact: '仅在自家门店异常时用于同平台、同日期诊断，不参与首页事实就绪度',
                role: 'diagnostic',
                ready: priceReady,
                className: priceReady ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200',
            },
            {
                name: '天气/日期因子',
                status: weatherReady ? '已获取' : '未获取',
                updatedAt: weatherSignal?.updated_at || '--',
                impact: '作为辅助信号，用于修正需求变化、取消率与节假日策略判断',
                role: 'support',
                ready: weatherReady,
                className: weatherReady ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-gray-50 text-gray-500 border-gray-200',
            },
            {
                name: '节假期窗口',
                status: holidayReady ? '已生成' : '未生成',
                updatedAt: holidayUpdatedAt || compassLastSyncedAt || '--',
                impact: '作为辅助信号，用于修正预售节奏、库存控制和连住策略',
                role: 'support',
                ready: holidayReady,
                className: holidayReady ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-gray-50 text-gray-500 border-gray-200',
            },
        ];
    };

    const buildCompassDataReadiness = (sources = []) => {
        const safeSources = Array.isArray(sources) ? sources : [];
        const coreSources = safeSources.filter(source => source?.role === 'core');
        const supportSources = safeSources.filter(source => !['core', 'diagnostic'].includes(source?.role));
        const diagnosticSources = safeSources.filter(source => source?.role === 'diagnostic');
        const readyCoreCount = coreSources.filter(source => source?.ready).length;
        const readySupportCount = supportSources.filter(source => source?.ready).length;
        const readyDiagnosticCount = diagnosticSources.filter(source => source?.ready).length;
        const percent = coreSources.length ? Math.round(readyCoreCount / coreSources.length * 100) : 0;
        const missingCore = coreSources.filter(source => !source?.ready).map(source => source?.name);
        const missingSupport = supportSources.filter(source => !source?.ready).map(source => source?.name);
        return {
            percent,
            summaryText: `核心数据 ${readyCoreCount}/${coreSources.length}`,
            progressText: `核心数据就绪度 ${readyCoreCount}/${coreSources.length}`,
            missingText: missingCore.length
                ? `待补全 ${missingCore.join(' / ')}`
                : (missingSupport.length ? `辅助信号待补 ${missingSupport.join(' / ')}` : '核心数据与辅助信号已就绪'),
            signalDensity: readyCoreCount === coreSources.length && readySupportCount === supportSources.length ? '高' : (readyCoreCount >= Math.ceil(coreSources.length / 2) ? '中' : '低'),
            nextAction: missingCore.length ? '先补核心数据' : (missingSupport.length ? '补辅助信号' : '可分析'),
            diagnosticText: diagnosticSources.length
                ? `竞对诊断参考 ${readyDiagnosticCount}/${diagnosticSources.length}，不计入核心事实就绪度`
                : '未配置竞对诊断参考',
        };
    };

    const buildHomeDecisionSummaryRows = ({
        readiness = {},
        trendReady = false,
        sampleText = '--',
        homeMarketForecastStatus = '',
        competitorReadiness = {},
        competitorReadinessClassName = '',
        competitorTagText = '',
        competitorSourceNotice = '',
        action = {},
        homeMarketForecastAction = '',
    } = {}) => {
        const safeReadiness = readiness && typeof readiness === 'object' ? readiness : {};
        const safeCompetitorReadiness = competitorReadiness && typeof competitorReadiness === 'object' ? competitorReadiness : {};
        const safeAction = action && typeof action === 'object' ? action : {};
        const percent = Number(safeReadiness.percent || 0);
        return [
            {
                key: 'data-readiness',
                label: '数据就绪',
                value: safeReadiness.summaryText || '待同步',
                note: safeReadiness.missingText || safeReadiness.nextAction || '等待核心数据',
                badge: `${percent}%`,
                badgeClass: percent >= 100 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : (percent > 0 ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200'),
                icon: 'fas fa-database',
                iconClass: 'border-emerald-100 bg-emerald-50 text-emerald-700',
                entry: { page: 'online-data', tab: 'data-health' },
            },
            {
                key: 'trend-sample',
                label: '趋势样本',
                value: sampleText || '--',
                note: homeMarketForecastStatus || '等待趋势样本',
                badge: trendReady ? '可判断' : '待形成',
                badgeClass: trendReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                icon: 'fas fa-chart-line',
                iconClass: 'border-blue-100 bg-blue-50 text-blue-700',
                entry: { page: 'online-data', tab: 'data-health' },
            },
            {
                key: 'competitor',
                label: '异常诊断参考',
                value: safeCompetitorReadiness.label || '待同步',
                note: `仅在自家门店异常时参考；${competitorTagText || competitorSourceNotice || '不推断VIP'}`,
                badge: safeCompetitorReadiness.status === 'ok' ? '仅参考' : '未取得',
                badgeClass: competitorReadinessClassName,
                icon: 'fas fa-trophy',
                iconClass: 'border-indigo-100 bg-indigo-50 text-indigo-700',
                entry: { page: 'meituan-ebooking', tab: 'meituan-ranking' },
            },
            {
                key: 'next-action',
                label: '下一步',
                value: safeAction.title || safeReadiness.nextAction || '复核数据',
                note: safeAction.detail || safeReadiness.missingText || homeMarketForecastAction,
                badge: safeAction.badge || '待处理',
                badgeClass: safeAction.className || 'bg-gray-50 text-gray-500 border-gray-200',
                icon: 'fas fa-arrow-right',
                iconClass: 'border-amber-100 bg-amber-50 text-amber-700',
                entry: safeAction.entry || { page: 'online-data', tab: 'data-health' },
            },
        ];
    };

    const parseHolidayDate = (value) => {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return null;
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    };

    const formatHolidayDate = (date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    const normalizeHolidayCountdownItem = (item) => {
        const name = item?.name || item?.holiday_name || item?.title || '';
        const start = parseHolidayDate(item?.start_date || item?.startDate);
        const end = parseHolidayDate(item?.end_date || item?.endDate || item?.start_date || item?.startDate);
        if (!name || !start || !end) return null;
        const today = new Date();
        const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (end < todayStart) return null;
        const dayMs = 24 * 60 * 60 * 1000;
        const daysLeft = Math.max(0, Math.round((start - todayStart) / dayMs));
        const holidayDays = Math.max(1, Math.round((end - start) / dayMs) + 1);
        return {
            name,
            start_date: formatHolidayDate(start),
            end_date: formatHolidayDate(end),
            days_left: daysLeft,
            distance_text: start <= todayStart && end >= todayStart ? '进行中' : `${daysLeft}天`,
            holiday_days: holidayDays,
        };
    };

    const homeTrendBadgeClass = (level) => ({
        red: 'bg-red-50 text-red-700 border-red-200',
        yellow: 'bg-yellow-50 text-yellow-700 border-yellow-200',
        green: 'bg-green-50 text-green-700 border-green-200',
        blue: 'bg-blue-50 text-blue-700 border-blue-200',
        gray: 'bg-gray-50 text-gray-500 border-gray-200',
    }[level] || 'bg-gray-50 text-gray-500 border-gray-200');

    const homeTrendCardHasData = (card) => {
        const value = String(card?.value ?? '').trim();
        const direction = String(card?.direction ?? '').trim();
        if (!value || value === '--' || value === '-' || value === '待同步') return false;
        return !['待同步', '数据不足'].includes(direction);
    };

    const macroSignalLevelClass = (signal) => {
        const level = signal?.level || 'gray';
        const map = {
            red: 'bg-red-50 text-red-700 border-red-200',
            yellow: 'bg-yellow-50 text-yellow-700 border-yellow-200',
            green: 'bg-green-50 text-green-700 border-green-200',
            blue: 'bg-blue-50 text-blue-700 border-blue-200',
            gray: 'bg-gray-50 text-gray-500 border-gray-200'
        };
        return map[level] || map.gray;
    };

    const homeTextHasValue = (value) => {
        const text = String(value ?? '').trim();
        return !!text && !['--', '-', '待同步', '数据不足', '未返回'].includes(text);
    };

    const competitorPlatformTagSummary = (summary) => {
        const displaySummary = summary?.display_summary || {};
        return displaySummary.platform_tag_summary || summary?.platform_tag_summary || {};
    };

    const competitorPlatformTagClass = (summary) => ({
        returned: 'bg-orange-50 text-orange-800 border-orange-100',
        returned_empty: 'bg-amber-50 text-amber-700 border-amber-100',
        not_returned: 'bg-gray-50 text-gray-500 border-gray-200',
    }[String(competitorPlatformTagSummary(summary)?.status || 'not_returned')] || 'bg-gray-50 text-gray-500 border-gray-200');

    const competitorPlatformTagText = (summary) => {
        const tagSummary = competitorPlatformTagSummary(summary);
        const status = String(tagSummary?.status || 'not_returned');
        if (status === 'returned') {
            const vipCount = Number(tagSummary.vip_count || 0);
            const returnedCount = Number(tagSummary.returned_count || 0);
            const tagCount = Number(tagSummary.tag_count || 0);
            return `VIP ${vipCount}家 / 标签返回 ${returnedCount}家 / 标签种类 ${tagCount}类 · 字段 raw_data.hasVipTag`;
        }
        if (status === 'returned_empty') {
            const emptyCount = Number(tagSummary.returned_empty_count || 0);
            return `平台返回空标签 ${emptyCount}家，不推断VIP · 字段 raw_data.platformTagStatus`;
        }
        return '平台标签未返回，不推断VIP · 待同步美团榜单字段';
    };

    const holidayOperationStageText = (nearest = null) => {
        if (!nearest) return '等待节假日';
        const days = Number(nearest.days_left || 0);
        if (nearest.distance_text === '进行中' || days === 0) return '假期执行中';
        if (days <= 7) return '临门执行';
        if (days <= 30) return '重点跟进';
        if (days <= 45) return '预热筹备';
        return '年度排期';
    };

    const buildHolidayOperationSuggestions = ({
        nearest = null,
        next = null,
        hotelPool = [],
        selectedHotelId = '',
        trendHasSamples = false,
        trendSampleDays = 0,
        trendJudgement = '',
        weatherSignal = null,
    } = {}) => {
        const suggestions = [];
        const add = (text) => {
            if (text && !suggestions.includes(text)) suggestions.push(text);
        };
        if (!nearest) {
            return ['暂无可用节假日窗口，先维护节假日日历和基准价盘'];
        }

        const days = Number(nearest.days_left || 0);
        if (nearest.distance_text === '进行中' || days === 0) {
            add(`${nearest.name}正在进行，优先盯今日房态、取消订单和到店提醒`);
        } else if (days <= 7) {
            add(`${nearest.name}还有${days}天，逐日复核可售房、低价房和连住限制`);
        } else if (days <= 30) {
            add(`${nearest.name}进入T-${days}重点期，先锁底价、库存和活动价，避免临近被低价占量`);
        } else if (days <= 45) {
            add(`${nearest.name}还有${days}天，先完成预售价盘和渠道活动报名，T-30再加密复盘`);
        } else {
            add(`${nearest.name}还有${days}天，保留年度价盘占位，暂不占用每日运营节奏`);
        }

        if (Number(nearest.holiday_days || 0) >= 3) {
            add(`${nearest.name}连续${nearest.holiday_days}天，设置首尾日差异价和连住策略`);
        } else {
            add(`${nearest.name}为${nearest.holiday_days}天短假，重点看周边游、亲子和临近订单`);
        }

        const hotels = Array.isArray(hotelPool) ? hotelPool : [];
        if (!selectedHotelId && hotels.length > 1) {
            add(`当前为全部门店视角，按门店拆分${nearest.name}价盘，避免统一价格覆盖差异需求`);
        } else if (selectedHotelId) {
            const selectedHotel = hotels.find(hotel => String(hotel?.id || '') === String(selectedHotelId));
            add(`${selectedHotel?.name || '当前门店'}单店视角下，优先复核本店房型库存和渠道价差`);
        }

        if (trendHasSamples) {
            add(`结合${Number(trendSampleDays || 0)}天经营趋势样本，按${trendJudgement || '当前趋势'}校准节假日涨价幅度`);
        } else {
            add('趋势样本不足，先同步 OTA 和经营日报，再决定节假日涨价幅度');
        }

        if (weatherSignal && ['yellow', 'red'].includes(weatherSignal.level || '')) {
            add(`天气信号提示${weatherSignal.status_text || '关注'}，节前补充到店提醒和取消订单二次售卖预案`);
        }

        if (next && suggestions.length < 4) {
            add(`${next.name}还有${next.days_left}天，先维护预售价盘和节假日日历，不进入重点跟进`);
        }

        return suggestions.slice(0, 4);
    };

    const buildMacroSignalFallback = (summary = '待同步') => ([
        { key: 'cycle', title: '周期信号', status: 'pending', status_text: '待同步', level: 'gray', summary, metrics: [{ label: '数据状态', value: '待同步' }], suggestions: ['同步订单与日期数据后生成判断'], action_text: '查看详情', updated_at: '--' },
        { key: 'weather', title: '天气信号', status: 'pending', status_text: '自动获取中', level: 'gray', summary: '天气会按门店城市自动获取，正在等待返回结果', metrics: [{ label: '获取方式', value: '自动获取' }], suggestions: ['检查门店地址和高德天气配置'], action_text: '查看详情', updated_at: '--' },
        { key: 'channel', title: '渠道信号', status: 'pending', status_text: '待同步', level: 'gray', summary, metrics: [{ label: '数据状态', value: '待同步' }], suggestions: ['同步 OTA 流量数据后生成判断'], action_text: '去分析', updated_at: '--' },
        { key: 'price', title: '价格信号', status: 'pending', status_text: '待同步', level: 'gray', summary, metrics: [{ label: '数据状态', value: '待同步' }], suggestions: ['同步价格与竞对数据后生成判断'], action_text: '去分析', updated_at: '--' },
        { key: 'demand', title: '需求信号', status: 'pending', status_text: '待同步', level: 'gray', summary, metrics: [{ label: '数据状态', value: '待同步' }], suggestions: ['同步订单与预测数据后生成判断'], action_text: '查看详情', updated_at: '--' }
    ]);

    const normalizeMacroSignalMetric = (metric) => ({
        label: metric?.label || '数据状态',
        value: metric?.value === undefined || metric?.value === null || metric?.value === '' ? '--' : metric.value,
        unit: metric?.unit || '',
    });

    const macroSignalPrimaryMetrics = (signal) => {
        const metrics = Array.isArray(signal?.metrics) ? signal.metrics.map(normalizeMacroSignalMetric) : [];
        if (metrics.length >= 2) return metrics.slice(0, 2);
        return [
            ...metrics,
            { label: '状态', value: signal?.status_text || signal?.status || '待同步', unit: '' },
        ].slice(0, 2);
    };

    const buildMacroSignalViewCards = (signals = [], meaningMap = {}) => (
        (Array.isArray(signals) ? signals : []).map(signal => {
            const meta = meaningMap[signal.key] || {
                icon: 'fas fa-signal',
                meaning: '用于辅助判断当前经营环境。',
                impact: '影响运营优先级和后续跟进动作。',
                action: '查看详情后确认下一步动作。',
            };
            const suggestions = Array.isArray(signal.suggestions) ? signal.suggestions : [];
            return {
                ...signal,
                icon: meta.icon,
                meaning: meta.meaning,
                impact: meta.impact,
                primaryAction: suggestions[0] || meta.action,
                primaryMetrics: macroSignalPrimaryMetrics(signal),
            };
        })
    );

    const buildHomeMarketForecastItems = ({
        trendCards = [],
        demandSignal = null,
        priceSignal = null,
        channelSignal = null,
        nearestHoliday = null,
        weatherValue = '',
        trendHasSamples = false,
    } = {}) => {
        const cards = Array.isArray(trendCards) ? trendCards : [];
        const findTrendCard = (key) => cards.find(card => card.key === key) || null;
        const formatTrendValue = (card, fallback) => {
            if (!card) return fallback;
            return [card.value, card.direction].filter(Boolean).join(' ');
        };
        return [
            {
                name: '市场需求',
                value: formatTrendValue(findTrendCard('demand'), isHomeSignalReady(demandSignal) ? (demandSignal.status_text || '已形成') : '待需求样本'),
                level: 'core',
                actionLabel: trendHasSamples ? '查看趋势' : '同步样本',
                entry: trendHasSamples ? { page: 'revenue-research-center' } : { page: 'ctrip-ebooking', tab: 'ctrip-ranking' }
            },
            {
                name: '价格带',
                value: formatTrendValue(findTrendCard('price'), isHomeSignalReady(priceSignal) ? (priceSignal.status_text || '已形成') : '待竞对价格'),
                level: 'core',
                actionLabel: '进入策略模拟',
                entry: { page: 'ops-plan' }
            },
            {
                name: '渠道热度',
                value: formatTrendValue(findTrendCard('channel'), isHomeSignalReady(channelSignal) ? (channelSignal.status_text || '已形成') : '待 OTA 数据'),
                level: 'core',
                actionLabel: '查看流量漏斗',
                entry: { page: 'ctrip-ebooking', tab: 'ctrip-traffic' }
            },
            {
                name: '天气影响',
                value: weatherValue || '待天气数据',
                level: 'support',
                actionLabel: '查看预警',
                entry: { page: 'ops-insight' }
            },
            {
                name: '节假期窗口',
                value: nearestHoliday ? `${nearestHoliday.name} ${nearestHoliday.distance_text}` : '待生成',
                level: 'support',
                actionLabel: '安排策略',
                entry: { page: 'ops-plan' }
            }
        ];
    };

    const homeMarketForecastStatus = (items = []) => {
        const readyCount = (Array.isArray(items) ? items : []).filter(item => !/^待/.test(String(item.value || ''))).length;
        if (readyCount >= 4) return '可形成预估';
        if (readyCount > 0) return `部分可估 ${readyCount}/5`;
        return '待形成样本';
    };

    const buildHomeMarketForecastSummaryRows = (items = [], noteMap = {}) => (
        (Array.isArray(items) ? items : [])
            .filter(item => ['市场需求', '价格带', '渠道热度'].includes(item.name))
            .map(item => ({
                ...item,
                note: noteMap[item.name] || '用于辅助当前经营动作排序。',
                actionLabel: item.actionLabel || '查看',
            }))
    );

    const resolveHomeMarketForecastAction = ({
        trendHasSamples = false,
        trendAction = '',
        readinessNextAction = '',
    } = {}) => {
        if (!trendHasSamples) return '先同步 OTA 与经营日报，形成可用趋势样本';
        return (trendAction || readinessNextAction || '进入数据中心复核关键指标').replace(/。$/, '');
    };

    const homeMetricSeriesValues = (metrics = {}, key = '') => {
        const raw = metrics?.[key]?.data;
        if (!Array.isArray(raw)) return [];
        return raw
            .map(value => {
                if (value === null || value === undefined || value === '') return null;
                const numeric = Number(String(value).replace(/,/g, ''));
                return Number.isFinite(numeric) ? numeric : null;
            })
            .filter(value => value !== null && value > 0);
    };

    const homeMetricSeriesSum = (metrics = {}, key = '') => (
        homeMetricSeriesValues(metrics, key).reduce((sum, value) => sum + value, 0)
    );

    const homeMetricSeriesAvg = (metrics = {}, key = '') => {
        const values = homeMetricSeriesValues(metrics, key);
        return values.length ? values.reduce((sum, value) => sum + value, 0) / values.length : 0;
    };

    const homeMetricToneClass = (ready, level = '') => {
        if (!ready) return 'border-gray-200 bg-gray-50 text-gray-500';
        return {
            red: 'border-rose-100 bg-rose-50 text-rose-700',
            yellow: 'border-amber-100 bg-amber-50 text-amber-700',
            green: 'border-emerald-100 bg-emerald-50 text-emerald-700',
            blue: 'border-blue-100 bg-blue-50 text-blue-700',
            gray: 'border-gray-200 bg-gray-50 text-gray-500',
        }[level] || 'border-blue-100 bg-blue-50 text-blue-700';
    };

    const findHomeSignalMetric = (signal = null, labels = []) => {
        const metrics = Array.isArray(signal?.metrics) ? signal.metrics : [];
        const safeLabels = Array.isArray(labels) ? labels : [];
        return metrics.find(metric => safeLabels.some(label => String(metric?.label || '').includes(label))) || null;
    };

    const homeSignalMetricText = (signal = null, labels = []) => {
        const metric = findHomeSignalMetric(signal, labels);
        if (!metric) return { value: '待同步', ready: false };
        const value = String(metric.value ?? '').trim() || '待同步';
        const unit = String(metric.unit || '').trim();
        const display = unit && value !== '待同步' && !value.endsWith(unit) ? `${value}${unit}` : value;
        return { value: display, ready: homeTextHasValue(display) };
    };

    const competitorDisplayRows = (summary) => (
        Array.isArray(summary?.display_hotels) ? summary.display_hotels : []
    );

    const competitorDisplaySummary = (summary) => summary?.display_summary || {};

    const competitorSummarySourceNotice = (summary) => (
        summary?.source_notice
        || competitorDisplaySummary(summary).source_notice
        || '仅展示美团榜单已返回字段；未返回字段保留缺失状态。'
    );

    const competitorSummaryReadinessClass = (readiness) => ({
        ok: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        attention: 'bg-amber-50 text-amber-700 border-amber-200',
        warning: 'bg-amber-50 text-amber-700 border-amber-200',
        missing: 'bg-gray-50 text-gray-500 border-gray-200',
        error: 'bg-red-50 text-red-700 border-red-200',
        blocked: 'bg-red-50 text-red-700 border-red-200',
    }[readiness?.status] || 'bg-gray-50 text-gray-500 border-gray-200');

    return {
        buildHomeClosedLoopStages,
        buildHomeAiTraceRows,
        buildHomeOperatingResultCards,
        buildHomeCausalChainNodes,
        buildHomeTrendChartConfig,
        buildHomeBoardActionRows,
        buildHomeBusinessTimeModel,
        buildHomeOperatingScheduleModel,
        HomeOperatingOrchestration,
        HomeYesterdayOperatingFacts,
        HomeBusinessTimeAxis,
        buildHomeDataSources,
        buildCompassDataReadiness,
        buildHomeDecisionSummaryRows,
        normalizeHolidayCountdownItem,
        homeTrendBadgeClass,
        homeTrendCardHasData,
        macroSignalLevelClass,
        homeTextHasValue,
        competitorPlatformTagText,
        competitorPlatformTagClass,
        holidayOperationStageText,
        buildHolidayOperationSuggestions,
        buildMacroSignalFallback,
        buildMacroSignalViewCards,
        buildHomeMarketForecastItems,
        homeMarketForecastStatus,
        buildHomeMarketForecastSummaryRows,
        resolveHomeMarketForecastAction,
        homeMetricSeriesValues,
        homeMetricSeriesSum,
        homeMetricSeriesAvg,
        homeMetricToneClass,
        homeSignalMetricText,
        competitorDisplayRows,
        competitorDisplaySummary,
        competitorSummarySourceNotice,
        competitorSummaryReadinessClass,
    };
})();
