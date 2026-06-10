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

    return {
        buildHomeClosedLoopStages,
        buildHomeAiTraceRows,
    };
})();
