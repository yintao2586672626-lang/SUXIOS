window.SUXI_COMPASS_STATIC = (() => {
    const defaultHomeQuickEntryOrder = [
        'online-data',
        'operation-diagnosis',
        'operation-root-cause',
        'operation-alerts',
        'strategy-simulation',
        'action-tracking',
        'hotel-management',
        'ai-tools',
        'system-settings',
    ];
    const defaultHomeQuickEntryHidden = ['hotel-management', 'ai-tools', 'system-settings'];
    const homeTrendRanges = [
        { key: '3', label: '近3日' },
        { key: '7', label: '近7日' },
        { key: '30', label: '近30日' },
        { key: 'month', label: '本月' },
        { key: 'custom', label: '自定义' },
    ];
    const homeTrendMetrics = [
        { key: 'revenue', label: '营收' },
        { key: 'adr', label: 'ADR' },
        { key: 'revpar', label: 'RevPAR' },
        { key: 'room_nights', label: '间夜' },
    ];
    const defaultHomeTrendCards = [
        {
            key: 'revenue',
            name: '收益趋势',
            value: '--',
            direction: '待同步',
            level: 'gray',
            note: '等待线上数据同步后生成收益趋势',
            source: '来源：经营日报收入；无日报时取 OTA 成交额',
            source_type: 'daily_report_with_ota_reference',
            source_type_label: '经营日报 + OTA参考',
            data_quality_status: 'mixed_unverified',
            data_quality_label: '混合口径待复核',
            decision_allowed: false,
            decision_status: '需确认日报与OTA口径后决策',
            spark: [26, 34, 28, 42, 36, 48, 40, 52, 44],
        },
        {
            key: 'demand',
            name: '市场需求',
            value: '--',
            direction: '数据不足',
            level: 'gray',
            note: '数据依据不足，暂不生成趋势判断',
            source: '来源：OTA 订单数；无订单时取需求预测',
            source_type: 'ota_with_forecast_reference',
            source_type_label: 'OTA + 预测参考',
            data_quality_status: 'derived_unverified',
            data_quality_label: '衍生口径待复核',
            decision_allowed: false,
            decision_status: '需确认订单样本后决策',
            spark: [30, 30, 30, 30, 30, 30, 30, 30, 30],
        },
        {
            key: 'price',
            name: '价格竞争',
            value: '--',
            direction: '待同步',
            level: 'gray',
            note: '等待竞对价格同步后判断价格劣势',
            source: '来源：经营日报/OTA 推算 ADR，对比竞对价格',
            source_type: 'daily_report_ota_competitor',
            source_type_label: '日报/OTA + 竞对',
            data_quality_status: 'derived_unverified',
            data_quality_label: '推算口径待复核',
            decision_allowed: false,
            decision_status: '需确认ADR与竞对价后决策',
            spark: [24, 38, 32, 46, 34, 50, 36, 54, 40],
        },
        {
            key: 'channel',
            name: '渠道表现',
            value: '--',
            direction: '待同步',
            level: 'gray',
            note: '等待 OTA 数据同步',
            source: '来源：OTA 曝光、访客、转化和订单数据',
            source_type: 'ota_channel',
            source_type_label: 'OTA渠道',
            data_quality_status: 'ota_unverified',
            data_quality_label: 'OTA样本待同步',
            decision_allowed: false,
            decision_status: '仅代表OTA渠道，需复核后决策',
            spark: [40, 36, 42, 38, 44, 40, 46, 42, 48],
        },
    ];
    const homeTrendSourceMap = {
        revenue: '来源：经营日报收入；无日报时取 OTA 成交额',
        demand: '来源：OTA 订单数；无订单时取需求预测',
        price: '来源：经营日报/OTA 推算 ADR，对比竞对价格',
        channel: '来源：OTA 曝光、访客、转化和订单数据',
    };
    const homeTrendSourceMetaMap = defaultHomeTrendCards.reduce((acc, card) => {
        acc[card.key] = {
            source_type: card.source_type,
            source_type_label: card.source_type_label,
            data_quality_status: card.data_quality_status,
            data_quality_label: card.data_quality_label,
            decision_allowed: card.decision_allowed,
            decision_status: card.decision_status,
        };
        return acc;
    }, {});
    const dailyOpsPrimaryActions = [
        { label: '检查数据是否已同步', page: 'online-data', tab: 'data-health', icon: 'fas fa-heartbeat' },
        { label: '处理平台采集状态', page: 'online-data', tab: 'platform-auto', icon: 'fas fa-robot' },
        { label: '查看携程流量漏斗', page: 'ctrip-ebooking', tab: 'ctrip-traffic', icon: 'fas fa-route' },
        { label: '查看美团竞对排名', page: 'meituan-ebooking', tab: 'meituan-ranking', icon: 'fas fa-chart-bar' },
    ];
    const dailyOpsReviewSteps = [
        { index: 1, title: '先跑经营概况', detail: '拿访客、订单、销售额、间夜、价格、入住率和紧张度，先形成 OTA 结果口径。' },
        { index: 2, title: '再看竞争圈', detail: '同步竞对、排名、占比、转化和流失，区分本店问题还是圈层变化。' },
        { index: 3, title: '补服务和投放', detail: 'PSI、点评标签和金字塔依赖授权与样例，命中后再进入结构化复盘。' },
        { index: 4, title: '只处理缺口', detail: '字段未命中时显示缺失原因，不用空值、均值或转化率倒推结果。' },
    ];
    const weatherMajorCities = [
        '北京市', '上海市', '天津市', '重庆市', '广州市', '深圳市', '杭州市', '成都市',
        '武汉市', '西安市', '南京市', '苏州市', '长沙市', '郑州市', '青岛市', '宁波市',
        '无锡市', '佛山市', '合肥市', '巢湖市', '济南市', '厦门市', '福州市', '东莞市', '昆明市',
        '沈阳市', '大连市', '哈尔滨市', '长春市', '南昌市', '南宁市', '贵阳市', '太原市',
        '石家庄市', '泉州市', '温州市', '珠海市', '中山市', '惠州市', '常州市', '嘉兴市',
        '绍兴市', '南通市', '徐州市', '扬州市', '烟台市', '潍坊市', '洛阳市', '襄阳市',
        '宜昌市', '株洲市', '赣州市', '海口市', '三亚市', '兰州市', '西宁市', '银川市',
        '呼和浩特市', '乌鲁木齐市',
    ];
    const defaultCompassWeatherCity = '成都市';
    const homeQuickEntryDefinitions = [
        { key: 'online-data', title: '数据是否可用', desc: '看授权、采集、字段缺口', page: 'online-data', tab: 'data-health', icon: 'fas fa-cloud-download-alt', permission: 'can_view_online_data' },
        { key: 'operation-diagnosis', title: '今天经营结果', desc: '收入、订单、转化总览', page: 'ops-source', icon: 'fas fa-search' },
        { key: 'operation-root-cause', title: '为什么变差/变好', desc: '核对获客和收入的可能影响因素与证据', page: 'ops-analysis', icon: 'fas fa-microscope' },
        { key: 'operation-alerts', title: '今天要处理什么', desc: '风险、预警和动作建议', page: 'ops-insight', icon: 'fas fa-bell' },
        { key: 'strategy-simulation', title: '试算运营策略', desc: '调价、促销、投放影响', page: 'ops-plan', icon: 'fas fa-lightbulb' },
        { key: 'action-tracking', title: '执行进度和效果', desc: '跟踪执行与ROI复盘', page: 'ops-track', icon: 'fas fa-play-circle' },
        { key: 'ai-tools', title: '高级AI工具箱', desc: '管理员和专项诊断入口', page: 'agent-center', icon: 'fas fa-toolbox', requireSuper: true },
        { key: 'hotel-management', title: '酒店管理', desc: '维护基础信息', page: 'hotels', icon: 'fas fa-hotel' },
        { key: 'system-settings', title: '配置中心', desc: '权限、模型与数据源', page: 'system-config', icon: 'fas fa-cog', requireSuper: true },
    ];
    const macroSignalMeaningMap = {
        cycle: {
            icon: 'fas fa-calendar-check',
            meaning: '判断订单节奏、周末与节假日窗口是否进入机会期。',
            impact: '影响预售价、库存保留和连住策略。',
            action: '结合订单节奏复核未来7天价盘。',
        },
        weather: {
            icon: 'fas fa-cloud-sun-rain',
            meaning: '判断天气对到店率、取消率和周边游需求的影响。',
            impact: '影响到店提醒、取消订单二次售卖和本地客需求。',
            action: '按异常天气日期补充到店提醒。',
        },
        channel: {
            icon: 'fas fa-route',
            meaning: '判断 OTA 曝光、点击、访客与订单承接是否顺畅。',
            impact: '影响渠道投放、主图卖点、价格力和转化效率。',
            action: '优先检查低转化渠道的价格与详情页。',
        },
        price: {
            icon: 'fas fa-tags',
            meaning: '判断本店 ADR 与竞对价格是否形成明显价差。',
            impact: '影响调价、促销、底价和竞对跟价动作。',
            action: '结合入住率和转化率小步调整价格。',
        },
        demand: {
            icon: 'fas fa-users',
            meaning: '判断近期订单与未来需求预测是否走强或走弱。',
            impact: '影响保留房量、涨价幅度和高峰日房型控制。',
            action: '用近3天与近7天订单节奏校准库存。',
        },
    };
    const homeMarketForecastSummaryNoteMap = {
        '市场需求': '判断是否需要保留高价值库存、控制低价房。',
        '价格带': '判断价格区间是否支撑提价、补量或竞对校准。',
        '渠道热度': '判断 OTA 曝光、访客、转化是否支撑当前策略。',
    };
    const defaultMeituanRankTypes = [
        { key: 'P_RZ', label: '入住榜' },
        { key: 'P_XS', label: '销售榜' },
        { key: 'P_LL', label: '流量榜' },
        { key: 'P_ZH', label: '转化榜' },
    ];

    return {
        defaultHomeQuickEntryOrder,
        defaultHomeQuickEntryHidden,
        homeTrendRanges,
        homeTrendMetrics,
        defaultHomeTrendCards,
        homeTrendSourceMap,
        homeTrendSourceMetaMap,
        dailyOpsPrimaryActions,
        dailyOpsReviewSteps,
        weatherMajorCities,
        defaultCompassWeatherCity,
        homeQuickEntryDefinitions,
        macroSignalMeaningMap,
        homeMarketForecastSummaryNoteMap,
        defaultMeituanRankTypes,
    };
})();
