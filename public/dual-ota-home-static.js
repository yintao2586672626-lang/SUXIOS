window.SUXI_DUAL_OTA_HOME = (() => {
    const dashboardData = {
        brand: {
            name: '宿析OS',
            pageTitle: '双OTA可交付版经营台',
            subtitle: '只用美团 + 携程数据，先做“可判断、可执行、可复盘”的 MVP；外部市场数据不作为首屏依赖。',
            version: 'v6 / feasible MVP',
        },
        connections: [
            { name: '美团', status: 'connected', label: '美团已接' },
            { name: '携程', status: 'connected', label: '携程已接' },
            { name: '外部市场', status: 'disabled', label: '外部不接' },
        ],
        principle: '设计原则：AI不伪造市场情报。拿不到的数据标灰；拿得到的数据做深归因；动作先人审，不做自动改价承诺。',
        aiJudgement: {
            title: 'AI经营总判',
            evidenceLevel: '证据A级',
            summary: '收入变化先拆成：平台结构 × 订单量 × 间夜 × ADR。只有美团/携程数据，也能判断“到底是订单少了、转化弱了，还是价格变了”。',
            outputFormat: '问题 → 证据 → 影响金额 → 人工核验 → 今日动作',
        },
        dataTrust: [
            {
                name: '订单 / 间夜 / 收入',
                status: 'available',
                label: '可用',
                description: '来自美团、携程订单与收入数据，是当前首页核心依据。',
            },
            {
                name: '曝光 / 浏览 / 转化',
                status: 'conditional',
                label: '看平台是否开放',
                description: '平台开放则展示；未开放时降级为订单链路分析，不阻塞判断。',
            },
            {
                name: '实时竞对 / 商圈热度',
                status: 'notPrimary',
                label: '不作为首屏依赖',
                description: '当前版本不以外部市场情报作为核心判断依据。',
            },
            {
                name: 'PMS / 库存 / 客户画像',
                status: 'future',
                label: '后续接入',
                description: '作为后续增强数据源，当前不参与首页核心链路。',
            },
        ],
        platformRevenue: {
            title: '双平台收入结构',
            subtitle: '先做可交付的“合计 + 平台拆分”。',
            platforms: [
                {
                    id: 'meituan',
                    name: '美团营收',
                    revenue: 549,
                    revenueText: '¥549',
                    contribution: 12,
                    metrics: [
                        { label: '订单', value: '1单' },
                        { label: '间夜', value: '2间夜' },
                        { label: 'ADR', value: '¥275' },
                    ],
                },
                {
                    id: 'ctrip',
                    name: '携程营收',
                    revenue: 4190,
                    revenueText: '¥4,190',
                    contribution: 88,
                    metrics: [
                        { label: '订单', value: '8单' },
                        { label: '间夜', value: '13间夜' },
                        { label: 'ADR', value: '¥322' },
                    ],
                },
            ],
            contributionText: '平台贡献：携程 88% | 美团 12%',
        },
        lossChain: {
            title: '平台内经营损耗链',
            subtitle: '用平台自己的曝光、浏览、订单、间夜、收入串链路；缺哪一环就降级，不阻塞。',
            activePlatform: 'combined',
            activeRange: 'yesterday',
            platformOptions: [
                { value: 'meituan', label: '美团' },
                { value: 'ctrip', label: '携程' },
                { value: 'combined', label: '合计' },
            ],
            rangeOptions: [
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
                    title: '曝光先判断入口是否足够',
                    description: '曝光仍为正增长，当前收入缺口不能直接归因到入口流量不足。',
                    evidence: '字段：platform_exposure、stat_date、platform',
                    action: '继续观察平台是否完整开放曝光字段，不把外部商圈热度当作证据。',
                },
                browse: {
                    title: '浏览承接变弱',
                    description: '曝光增加但浏览下降，说明页面承接或排序位置需要人工复核。',
                    evidence: '字段：browse_count、exposure_count、platform',
                    action: '查看美团/携程列表页、首图与标签是否变化。',
                },
                paidOrders: {
                    title: '支付订单是主要拖累',
                    description: '支付订单下降 50%，是解释收入缺口的优先节点。',
                    evidence: '字段：paid_order_count、order_status、stat_date',
                    action: '先核验近两天价格、房态与活动承接。',
                },
                roomNights: {
                    title: '间夜未同步恶化',
                    description: '间夜正增长，说明订单结构可能由少量多晚订单支撑，不能只看订单数。',
                    evidence: '字段：room_nights、checkin_date、platform',
                    action: '拆分单订单间夜，确认是否存在长住或团单影响。',
                },
                revenue: {
                    title: '收入缺口需要拆成量价结构',
                    description: '收入下降幅度大于订单和间夜变化，需要继续检查 ADR 与平台结构。',
                    evidence: '字段：revenue_amount、adr、platform_share',
                    action: '按平台拆 ADR，再看是否由携程/美团结构偏移导致。',
                },
            },
            scenarios: {
                combined: {
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
                    yesterday: [
                        { id: 'exposure', label: '曝光', value: '218', delta: '+2.1%', severity: 'normal' },
                        { id: 'browse', label: '浏览', value: '17', delta: '-21.4%', severity: 'warning' },
                        { id: 'paidOrders', label: '支付订单', value: '1', delta: '-66.7%', severity: 'critical' },
                        { id: 'roomNights', label: '间夜', value: '2', delta: '-33.3%', severity: 'warning' },
                        { id: 'revenue', label: '收入', value: '¥549', delta: '-58.4%', severity: 'critical' },
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
            hint: '可点击下钻：美团 / 携程 / 合计；昨日 / 近7天 / 近30天。',
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
            subtitle: '替代“未来30天商圈机会地图”：只看本店已接订单与历史节奏。',
            rows: ['今天', '+1天', '+2天', '+3天'],
            columns: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14'],
            values: [
                [2, 1, 0, 3, 4, 4, 2, 1, 0, 3, 4, 1, 2, 0],
                [1, 0, 1, 3, 4, 3, 1, 0, 2, 2, 3, 1, 1, 0],
                [0, 1, 0, 2, 3, 2, 1, 1, 2, 3, 3, 1, 0, 0],
                [1, 2, 1, 1, 3, 4, 2, 0, 1, 2, 4, 2, 1, 0],
            ],
            judgementPoints: [
                '哪些入住日订单节奏慢',
                '哪个平台提前量变短',
                'ADR上涨是否伤害成交',
                '明天先查哪一天的价',
            ],
        },
        actionChecklist: [
            { id: 'check_ctrip_price', title: '携程核价', description: '查明后两天房价', status: 'pending' },
            { id: 'verify_meituan', title: '美团核验', description: '看流失 / 转化页', status: 'pending' },
            { id: 'content_optimize', title: '内容优化', description: '补首图 / 标签卖点', status: 'pending' },
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
            { name: '商圈热度', reason: '外部难拿' },
            { name: '实时竞对价', reason: '来源不稳' },
            { name: '全网舆情', reason: '后续导入' },
            { name: '自动调价', reason: '先人审' },
        ],
        emptyState: {
            title: '连接美团和携程后，宿析OS将生成首份经营归因诊断。',
            steps: ['连接美团数据', '连接携程数据', '生成双平台收入结构', '生成平台内经营损耗链', '开启人工操作清单与次日复盘'],
            cta: '开始连接数据',
        },
        copyText: '建议先核验携程近两天价格与房态，再检查美团流失 / 转化页；当前版本只生成任务，不自动改价。',
        positioning: '新版定位：双OTA数据归因 + AI证据化诊断 + 人工执行闭环 + 本店复盘记忆。',
    };

    const cloneDashboardData = () => JSON.parse(JSON.stringify(dashboardData));

    return {
        dashboardData,
        cloneDashboardData,
    };
})();
