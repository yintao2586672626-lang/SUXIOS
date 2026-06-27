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
        snapshot = null,
        transferSourceDate = '',
    } = {}) => {
        const safeReadiness = readiness && typeof readiness === 'object' ? readiness : {};
        const readyPercent = Number(safeReadiness.percent || 0);
        const coreReady = readyPercent >= 100;
        const safeForecastStatus = String(forecastStatus || '');
        const aiReady = !!trendReady && !safeForecastStatus.startsWith('待');
        const snapshotDate = snapshot?.snapshot_date || snapshot?.date || transferSourceDate || '--';
        const snapshotStatus = snapshot?.data_status || '';
        return [
            {
                key: 'ota-trust',
                index: '01 / OTA DATA',
                title: 'OTA数据可信度',
                statusText: coreReady ? '核心就绪' : (readyPercent > 0 ? safeReadiness.summaryText : '待同步'),
                statusClass: coreReady ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : (readyPercent > 0 ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200'),
                desc: safeReadiness.missingText || '等待授权 OTA 数据形成可验证输入。',
                evidence: `最近同步 ${compassLastSyncedAt || '--'}`,
                actionLabel: coreReady ? '查看数据健康' : '补齐数据',
                entry: { page: 'online-data', tab: coreReady ? 'data-health' : 'platform-auto' },
                icon: 'fas fa-shield-alt',
            },
            {
                key: 'revenue-analysis',
                index: '02 / REVENUE',
                title: '收益分析',
                statusText: trendReady ? '可分析' : '样本不足',
                statusClass: trendReady ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
                desc: homeMarketForecastAction || '先形成 OTA 与经营日报样本，再判断收入、入住和价格走势。',
                evidence: `样本 ${homeObservationSampleDaysText || '--'}`,
                actionLabel: trendReady ? '查看收益趋势' : '同步样本',
                entry: trendReady ? { page: 'revenue-research-center' } : { page: 'online-data', tab: 'platform-auto' },
                icon: 'fas fa-chart-line',
            },
            {
                key: 'ai-decision',
                index: '03 / AI',
                title: 'AI决策',
                statusText: aiReady ? '可生成动作' : '待数据支撑',
                statusClass: aiReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                desc: aiReady ? '进入预警建议或策略模拟后生成动作，仍需人工确认后下发。' : '核心样本不足时只显示缺口，不把模型输出当作事实。',
                evidence: `经营信号 ${safeForecastStatus || '待形成'}`,
                actionLabel: aiReady ? '进入策略模拟' : '查看缺口',
                entry: aiReady ? { page: 'ops-plan' } : { page: 'online-data', tab: 'data-health' },
                icon: 'fas fa-brain',
            },
            {
                key: 'operation-execution',
                index: '04 / EXECUTION',
                title: '运营执行',
                statusText: executionCount ? operationExecutionMoneyStatusText : '待生成执行单',
                statusClass: executionCount ? operationExecutionMoneyStatusClass : 'bg-gray-50 text-gray-500 border-gray-200',
                desc: executionCount ? `当前瓶颈：${operationExecutionBottleneckText}` : '策略动作需进入审批、执行、证据和 ROI 复盘链路。',
                evidence: executionCount ? `执行单 ${executionCount} 条` : '暂无执行闭环记录',
                actionLabel: '查看动作复盘',
                entry: { page: 'ops-track' },
                icon: 'fas fa-tasks',
            },
            {
                key: 'investment-decision',
                index: '05 / INVEST',
                title: '投资决策',
                statusText: snapshot ? (snapshotStatus || '有经营快照') : '待取经营快照',
                statusClass: snapshot ? 'bg-indigo-50 text-indigo-700 border-indigo-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                desc: '投资模块读取经营快照后再估值、判断时机和进入决策板，不用 OTA 渠道数据替代全酒店口径。',
                evidence: snapshot ? `快照日期 ${snapshotDate}` : '先从投资模块获取经营快照',
                actionLabel: '进入投决辅助',
                entry: { page: 'investment-decision' },
                icon: 'fas fa-balance-scale',
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
                title: competitorReady ? '查看竞对摘要' : '同步竞对榜单',
                detail: competitorReady ? competitorNotice : '先同步美团竞对榜单，再查看本店位置、TOP1、VIP标签和榜单健康。',
                badge: competitorReady ? '可复核' : '待同步',
                className: competitorReady ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: { page: 'meituan-ebooking', tab: 'meituan-ranking' },
            },
        ];
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
                impact: '会影响价格竞争、价差和调价建议判断',
                role: 'core',
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
        const supportSources = safeSources.filter(source => source?.role !== 'core');
        const readyCoreCount = coreSources.filter(source => source?.ready).length;
        const readySupportCount = supportSources.filter(source => source?.ready).length;
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
                label: '竞对可信',
                value: safeCompetitorReadiness.label || '待同步',
                note: competitorTagText || competitorSourceNotice || '不推断VIP',
                badge: safeCompetitorReadiness.status === 'ok' ? '可复核' : '待核对',
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
