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
                actionLabel: snapshot ? '进入决策板' : '获取投资数据',
                entry: snapshot ? { page: 'decision-board' } : { page: 'asset-pricing' },
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

    return {
        buildHomeClosedLoopStages,
        buildHomeAiTraceRows,
        buildHomeBoardActionRows,
        buildCompassDataReadiness,
        buildHomeDecisionSummaryRows,
    };
})();
