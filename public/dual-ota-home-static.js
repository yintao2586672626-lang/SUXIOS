window.SUXI_DUAL_OTA_HOME = (() => {
    const buildPendingMarketMetrics = (platformLabel, rankLabel) => ([
        { label: '营收', value: '待接入', note: `${platformLabel}${rankLabel}待接入` },
        { label: '订单', value: '待接入', note: `${platformLabel}${rankLabel}待接入` },
        { label: '间夜', value: '待接入', note: `${platformLabel}${rankLabel}待接入` },
        { label: 'ADR', value: '待接入', note: `${platformLabel}${rankLabel}待接入` },
        { label: '转化率', value: '待接入', note: '需商圈样本' },
        { label: '数据状态', value: '未核验', note: '不生成结论', tone: 'warning' },
    ]);
    const ctripCurrentMetrics = [
        { label: '携程营收', value: '¥4,190', note: '样例拆分 88%' },
        { label: '订单', value: '8', note: '平台收入样例' },
        { label: '间夜', value: '13', note: '平台收入样例' },
        { label: 'ADR', value: '¥322', note: '平台收入样例' },
        { label: 'APP访客', value: '109', note: '排名 10/26' },
        { label: '实时起价', value: '¥100', note: '排名 8/26' },
        { label: '点评分', value: '4.7', note: '排名 9/26' },
    ];
    const meituanCurrentMetrics = [
        { label: '美团营收', value: '¥549', note: '样例拆分 12%' },
        { label: '订单', value: '1', note: '平台收入样例' },
        { label: '间夜', value: '2', note: '平台收入样例' },
        { label: 'ADR', value: '¥275', note: '平台收入样例' },
        { label: '曝光量', value: '待同步', note: '本店美团字段' },
        { label: '浏览量', value: '待同步', note: '本店美团字段' },
        { label: '支付转化', value: '待同步', note: '本店美团字段' },
    ];
    const combinedCurrentMetrics = [
        { label: '双平台收入', value: '¥4,739', note: '携程+美团样例' },
        { label: '携程营收', value: '¥4,190', note: '占比 88%' },
        { label: '美团营收', value: '¥549', note: '占比 12%' },
        { label: '订单', value: '9', note: '平台收入样例' },
        { label: '间夜', value: '15', note: '平台收入样例' },
        { label: '同店结论', value: '未生成', note: '门店未统一', tone: 'warning' },
    ];
    const meituanMarketFirstMetrics = [
        { label: '美团营收', value: '¥549', note: '商圈第一样例' },
        { label: '曝光量', value: '2,147', note: '美团监控' },
        { label: '浏览量', value: '220', note: '排名 3/17' },
        { label: '支付订单', value: '26', note: '排名 3/17' },
        { label: '入住间夜', value: '20', note: '排名 3' },
        { label: '销售间夜', value: '27', note: '排名 4/17' },
        { label: '曝光→浏览', value: '10.25%', note: '可算' },
        { label: '浏览→支付', value: '11.82%', note: '可算', tone: 'good' },
    ];
    const systemOverviewGroupsByScope = {
        combined: [
            {
                title: '本店数据',
                subtitle: '兰熙酒店 / 双平台口径',
                metrics: combinedCurrentMetrics,
            },
            {
                title: '商圈平均',
                subtitle: '同商圈 / 双平台平均',
                metrics: buildPendingMarketMetrics('双平台', '平均'),
            },
            {
                title: '商圈第一',
                subtitle: '同商圈 / 双平台第一',
                metrics: buildPendingMarketMetrics('双平台', '第一'),
            },
        ],
        ctrip: [
            {
                title: '本店数据',
                subtitle: '兰熙酒店 / 携程口径',
                metrics: ctripCurrentMetrics,
            },
            {
                title: '商圈平均',
                subtitle: '同商圈 / 携程平均',
                metrics: buildPendingMarketMetrics('携程', '平均'),
            },
            {
                title: '商圈第一',
                subtitle: '同商圈 / 携程第一',
                metrics: buildPendingMarketMetrics('携程', '第一'),
            },
        ],
        meituan: [
            {
                title: '本店数据',
                subtitle: '兰熙酒店 / 美团口径',
                metrics: meituanCurrentMetrics,
            },
            {
                title: '商圈平均',
                subtitle: '同商圈 / 美团平均',
                metrics: buildPendingMarketMetrics('美团', '平均'),
            },
            {
                title: '商圈第一',
                subtitle: '华通铂悦酒店 / 美团口径',
                metrics: meituanMarketFirstMetrics,
            },
        ],
    };
    const dashboardData = {
        brand: {
            name: 'AI工作台',
            pageTitle: '',
            subtitle: '',
            version: 'v7 / practical MVP',
        },
        connections: [
            { name: '携程', status: 'connected', label: '携程' },
            { name: '美团', status: 'connected', label: '美团' },
            { name: '外部市场', status: 'disabled', label: '外部未接' },
        ],
        timeRanges: [
            { value: 'realtime', label: '今日实时' },
            { value: 'yesterday', label: '昨日' },
            { value: '7d', label: '近7天' },
            { value: '30d', label: '近30天' },
        ],
        principle: '',
        systemOverview: {
            title: '',
            sourceStatus: '已接字段优先',
            description: '',
            sourceNote: '左列为携程口径，右列为美团口径；中间只放合计或待核验总览。当前样例未统一到单一门店，不生成同店经营结论。',
            storeScope: {
                title: '平台切换',
                warning: '',
                rows: [
                    { value: 'combined', label: '双平台', selected: true },
                    { value: 'ctrip', label: '携程' },
                    { value: 'meituan', label: '美团' },
                ],
            },
            groups: systemOverviewGroupsByScope.combined,
            groupsByScope: systemOverviewGroupsByScope,
            sourceNotesByScope: {
                combined: '左列为本店双平台合并口径；中间为商圈双平台平均；右列为商圈双平台第一。商圈平均/第一待真实数据接入前不生成经营结论。',
                ctrip: '左列只展示本店携程口径；中间为携程商圈平均；右列为携程商圈第一。商圈数据待真实接入前不生成经营结论。',
                meituan: '左列只展示本店美团口径；中间为美团商圈平均；右列为美团商圈第一。商圈数据待真实接入前不生成经营结论。',
            },
        },
        dataTrust: [
            {
                name: '订单/收入',
                status: 'available',
                label: '可用',
                description: '来自美团、携程订单与收入字段。',
            },
            {
                name: '曝光/转化',
                status: 'conditional',
                label: '视平台',
                description: '平台开放则展示，缺失则降级。',
            },
            {
                name: '外部市场',
                status: 'notPrimary',
                label: '不纳入',
                description: '首版不写入AI结论。',
            },
            {
                name: 'PMS/画像',
                status: 'future',
                label: '后续',
                description: '当前不参与首屏判断。',
            },
        ],
        platformRevenue: {
            title: '双平台收入结构',
            subtitle: '合计 + 平台拆分',
            platforms: [
                {
                    id: 'ctrip',
                    name: '携程营收',
                    revenue: 4190,
                    revenueText: '¥4,190',
                    contribution: 88,
                    metricsText: '订单/间夜/ADR',
                    metrics: [
                        { label: '订单', value: '8单' },
                        { label: '间夜', value: '13间夜' },
                        { label: 'ADR', value: '¥322' },
                    ],
                },
                {
                    id: 'meituan',
                    name: '美团营收',
                    revenue: 549,
                    revenueText: '¥549',
                    contribution: 12,
                    metricsText: '订单/间夜/ADR',
                    metrics: [
                        { label: '订单', value: '1单' },
                        { label: '间夜', value: '2间夜' },
                        { label: 'ADR', value: '¥275' },
                    ],
                },
            ],
            contributionText: '平台贡献：携程 88% | 美团 12%',
        },
        lossChain: {
            title: '经营损耗链',
            subtitle: '曝光 → 浏览 → 订单 → 间夜 → 收入',
            activePlatform: 'combined',
            activeRange: 'realtime',
            platformOptions: [
                { value: 'meituan', label: '美团' },
                { value: 'ctrip', label: '携程' },
                { value: 'combined', label: '合计' },
            ],
            rangeOptions: [
                { value: 'realtime', label: '今日实时' },
                { value: 'yesterday', label: '昨日' },
                { value: '7d', label: '近7天' },
                { value: '30d', label: '近30天' },
            ],
            nodes: [
                { id: 'exposure', label: '曝光', value: '840', delta: '+5.3%', severity: 'normal' },
                { id: 'browse', label: '浏览', value: '69', delta: '-14.8%', severity: 'warning' },
                { id: 'paidOrders', label: '支付订单', value: '3', delta: '-50%', severity: 'critical' },
                { id: 'roomNights', label: '间夜', value: '7', delta: '+16.7%', severity: 'normal' },
                { id: 'revenue', label: '收入', value: '¥1,154', delta: '-66.7%', severity: 'critical' },
            ],
            nodeExplanations: {
                exposure: {
                    title: '入口流量够',
                    description: '曝光正增长，先不归因入口不足。',
                    evidence: '字段：曝光 / 日期 / 平台',
                    action: '继续观察曝光字段完整性。',
                },
                browse: {
                    title: '浏览承接弱',
                    description: '曝光增、浏览降，查首图和标题。',
                    evidence: '字段：浏览 / 曝光 / 平台',
                    action: '复核列表页、首图、标题。',
                },
                paidOrders: {
                    title: '订单量拖累',
                    description: '支付订单下降 50%。',
                    evidence: '字段：支付订单 / 状态 / 日期',
                    action: '先核价、房态、活动。',
                },
                roomNights: {
                    title: '间夜未恶化',
                    description: '少量多晚订单在支撑。',
                    evidence: '字段：间夜 / 入住日 / 平台',
                    action: '拆单看长住或团单。',
                },
                revenue: {
                    title: '收入看量价',
                    description: '继续拆 ADR 和平台结构。',
                    evidence: '字段：收入 / ADR / 平台占比',
                    action: '按平台拆 ADR。',
                },
            },
            scenarios: {
                combined: {
                    realtime: [
                        { id: 'exposure', label: '曝光', value: '840', delta: '+5.3%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '69', delta: '-14.8%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '3', delta: '-50%', severity: 'critical' },
                        { id: 'roomNights', label: '间夜', value: '7', delta: '+16.7%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥1,154', delta: '-66.7%', severity: 'critical' },
                    ],
                    yesterday: [
                        { id: 'exposure', label: '曝光', value: '840', delta: '+5.3%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '69', delta: '-14.8%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '3', delta: '-50%', severity: 'critical' },
                        { id: 'roomNights', label: '间夜', value: '7', delta: '+16.7%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥1,154', delta: '-66.7%', severity: 'critical' },
                    ],
                    '7d': [
                        { id: 'exposure', label: '曝光', value: '5,420', delta: '+3.1%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '514', delta: '-8.6%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '31', delta: '-22.5%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '64', delta: '-6.4%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥18,640', delta: '-18.2%', severity: 'warning' },
                    ],
                    '30d': [
                        { id: 'exposure', label: '曝光', value: '22,418', delta: '+1.7%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '2,106', delta: '-3.2%', severity: 'normal' },
                        { id: 'paidOrders', label: '支付订单', value: '146', delta: '-9.1%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '289', delta: '-2.8%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥84,320', delta: '-7.5%', severity: 'warning' },
                    ],
                },
                meituan: {
                    realtime: [
                        { id: 'exposure', label: '曝光', value: '2,147', delta: '+78', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '220', delta: '+7', severity: 'normal' },
                        { id: 'paidOrders', label: '支付订单', value: '26', delta: '排名 3/17', severity: 'normal' },
                        { id: 'roomNights', label: '间夜', value: '20', delta: '排名 3', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '未给出', delta: '销售间夜 27', severity: 'warning' },
                    ],
                    yesterday: [
                        { id: 'exposure', label: '曝光', value: '2,147', delta: '+78', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '220', delta: '+7', severity: 'normal' },
                        { id: 'paidOrders', label: '支付订单', value: '26', delta: '排名 3/17', severity: 'normal' },
                        { id: 'roomNights', label: '间夜', value: '20', delta: '排名 3', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '未给出', delta: '销售间夜 27', severity: 'warning' },
                    ],
                    '7d': [
                        { id: 'exposure', label: '曝光', value: '1,328', delta: '-4.2%', severity: 'warning' },
                        { id: 'browse', label: '浏览', value: '102', delta: '-17.9%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '8', delta: '-31.8%', severity: 'critical' },
                        { id: 'roomNights', label: '间夜', value: '15', delta: '-18.6%', severity: 'warning' },
                        { id: 'revenue', label: '收入', value: '¥4,220', delta: '-28.5%', severity: 'critical' },
                    ],
                    '30d': [
                        { id: 'exposure', label: '曝光', value: '6,430', delta: '-1.2%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '486', delta: '-8.8%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '39', delta: '-14.1%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '72', delta: '-8.7%', severity: 'warning' },
                        { id: 'revenue', label: '收入', value: '¥19,850', delta: '-11.6%', severity: 'warning' },
                    ],
                },
                ctrip: {
                    realtime: [
                        { id: 'exposure', label: '曝光', value: '622', delta: '+8.6%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '52', delta: '-11.9%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '2', delta: '-33.3%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '5', delta: '+25.0%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥4,190', delta: '-16.8%', severity: 'warning' },
                    ],
                    yesterday: [
                        { id: 'exposure', label: '曝光', value: '622', delta: '+8.6%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '52', delta: '-11.9%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '2', delta: '-33.3%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '5', delta: '+25.0%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥4,190', delta: '-16.8%', severity: 'warning' },
                    ],
                    '7d': [
                        { id: 'exposure', label: '曝光', value: '4,092', delta: '+5.8%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '412', delta: '-5.9%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '23', delta: '-18.3%', severity: 'warning' },
                        { id: 'roomNights', label: '间夜', value: '49', delta: '-1.7%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥14,420', delta: '-9.3%', severity: 'warning' },
                    ],
                    '30d': [
                        { id: 'exposure', label: '曝光', value: '15,988', delta: '+2.9%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '1,620', delta: '-1.4%', severity: 'normal' },
                        { id: 'paidOrders', label: '支付订单', value: '107', delta: '-6.8%', severity: 'normal' },
                        { id: 'roomNights', label: '间夜', value: '217', delta: '-0.6%', severity: 'normal' },
                        { id: 'revenue', label: '收入', value: '¥64,470', delta: '-4.8%', severity: 'normal' },
                    ],
                },
            },
            hint: '点击节点看字段边界；美团昨日漏斗来自用户提供巡查文本样例，收入字段未在该段文本给出。',
        },
        anomalies: [
            {
                rank: 1,
                title: '订单量拖累',
                description: '订单下降解释主要收入缺口',
                formula: '收入缺口 ≈ 订单差 × 历史单均收入',
                evidenceFields: ['支付订单', '间夜', '收入'],
                impactAmount: '¥1,860',
                manualCheck: '核验两平台订单是否同步完整',
                todayAction: '优先检查携程近两天价格与房态',
            },
            {
                rank: 2,
                title: '转化链路弱',
                description: '浏览到支付低于自身历史',
                formula: '转化损失 ≈ 浏览量 × 历史支付转化率 - 当前订单',
                evidenceFields: ['浏览', '支付订单'],
                impactAmount: '¥920',
                manualCheck: '确认平台是否开放浏览与转化字段',
                todayAction: '查看美团/携程转化页与流失原因',
            },
            {
                rank: 3,
                title: '平台结构偏移',
                description: '携程强，美团承接弱',
                formula: '结构偏移 = 当前平台占比 - 历史平台占比',
                evidenceFields: ['平台收入', '平台订单', '平台间夜'],
                impactAmount: '¥540',
                manualCheck: '核验美团活动与内容是否变化',
                todayAction: '补齐美团首图、标签和卖点',
            },
        ],
        orderPaceHeatmap: {
            title: '订单节奏热力图',
            subtitle: '只看本店订单节奏。',
            rows: ['今天', '+1天', '+2天', '+3天'],
            columns: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14'],
            values: [
                [2, 1, 0, 3, 4, 4, 2, 1, 0, 3, 4, 1, 2, 0],
                [1, 0, 1, 3, 4, 3, 1, 0, 2, 2, 3, 1, 1, 0],
                [0, 1, 0, 2, 3, 2, 1, 1, 2, 3, 3, 1, 0, 0],
                [1, 2, 1, 1, 3, 4, 2, 0, 1, 2, 4, 2, 1, 0],
            ],
            judgementPoints: [
                '慢在哪天',
                '弱在哪个平台',
                'ADR是否伤成交',
                '明天先查哪天',
            ],
        },
        actionChecklist: [
            { id: 'check_ctrip_price', title: '携程核价', description: '查明后两天房价', status: 'pending' },
            { id: 'verify_meituan', title: '美团核验', description: '看流失/转化页', status: 'pending' },
            { id: 'content_optimize', title: '内容优化', description: '补首图/标签卖点', status: 'pending' },
            { id: 'mark_execution', title: '标记执行', description: '次日自动复盘', status: 'pending' },
        ],
        reviewMemory: [
            {
                id: 'memo-suggestion',
                type: '建议',
                content: '6/30 调整携程价',
                detail: '建议先核验后两天价格与房态，再由运营确认是否执行。',
            },
            {
                id: 'memo-execution',
                type: '执行',
                content: '运营已确认',
                detail: '人工确认后在 OTA 后台执行，系统只记录动作和证据。',
            },
            {
                id: 'memo-result',
                type: '结果',
                content: '次日订单 +2',
                detail: '结果来自本店订单回传，未混入外部市场判断。',
            },
            {
                id: 'memo-learning',
                type: '沉淀',
                content: '周二不宜大幅降价',
                detail: '沉淀为本店历史经营经验，后续建议会优先参考。',
            },
        ],
        deprioritizedModules: [
            { name: '酒店GEO', reason: '' },
            { name: '嗨蚁AI', reason: '' },
            { name: '订单来了', reason: '' },
            { name: '价格监控', reason: '' },
        ],
        emptyState: {
            title: '连接美团和携程后，宿析OS将生成首份经营归因诊断。',
            steps: ['连接美团数据', '连接携程数据', '生成双平台收入结构', '生成平台内经营损耗链', '开启人工操作清单与次日复盘'],
            cta: '开始连接数据',
        },
        copyText: '建议先核验携程近两天价格与房态，再检查美团流失/转化页；当前版本只生成任务，不自动改价。',
        positioning: '双OTA数据 -> 收益分析 -> AI建议 -> 人工执行 -> 本店复盘。',
    };

    const cloneDashboardData = () => JSON.parse(JSON.stringify(dashboardData));

    return {
        dashboardData,
        cloneDashboardData,
    };
})();
