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
            spark: [40, 36, 42, 38, 44, 40, 46, 42, 48],
        },
    ];
    const homeTrendSourceMap = {
        revenue: '来源：经营日报收入；无日报时取 OTA 成交额',
        demand: '来源：OTA 订单数；无订单时取需求预测',
        price: '来源：经营日报/OTA 推算 ADR，对比竞对价格',
        channel: '来源：OTA 曝光、访客、转化和订单数据',
    };
    const dailyOpsPrimaryActions = [
        { label: '平台自动获取', page: 'online-data', tab: 'platform-auto', icon: 'fas fa-robot' },
        { label: '携程/去哪流量', page: 'ctrip-ebooking', tab: 'ctrip-traffic', icon: 'fas fa-route' },
        { label: '美团排名', page: 'meituan-ebooking', tab: 'meituan-ranking', icon: 'fas fa-chart-bar' },
        { label: '广告数据', page: 'ctrip-ebooking', tab: 'ctrip-ads', icon: 'fas fa-bullhorn' },
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
        { key: 'online-data', title: 'OTA数据同步', desc: '采集校验携程美团', page: 'ctrip-ebooking', tab: 'ctrip-ranking', icon: 'fas fa-cloud-download-alt', permission: 'can_view_online_data' },
        { key: 'operation-diagnosis', title: '收益诊断', desc: '收入与OTA漏斗', page: 'ops-source', icon: 'fas fa-search' },
        { key: 'operation-root-cause', title: '根因定位', desc: '找获客和收入问题', page: 'ops-analysis', icon: 'fas fa-microscope' },
        { key: 'operation-alerts', title: '预警建议', desc: '查看风险与动作', page: 'ops-insight', icon: 'fas fa-bell' },
        { key: 'strategy-simulation', title: '策略模拟', desc: '生成运营方案', page: 'ops-plan', icon: 'fas fa-lightbulb' },
        { key: 'action-tracking', title: '动作复盘', desc: '跟踪执行与ROI', page: 'ops-track', icon: 'fas fa-play-circle' },
        { key: 'ai-tools', title: '酒店AI工具箱', desc: '辅助工具入口', page: 'agent-center', icon: 'fas fa-toolbox', requireSuper: true },
        { key: 'hotel-management', title: '酒店管理', desc: '维护基础信息', page: 'hotels', icon: 'fas fa-hotel' },
        { key: 'system-settings', title: '系统设置', desc: '配置数据源与权限', page: 'system-config', icon: 'fas fa-cog', requireSuper: true },
    ];

    return {
        defaultHomeQuickEntryOrder,
        defaultHomeQuickEntryHidden,
        homeTrendRanges,
        homeTrendMetrics,
        defaultHomeTrendCards,
        homeTrendSourceMap,
        dailyOpsPrimaryActions,
        dailyOpsReviewSteps,
        weatherMajorCities,
        defaultCompassWeatherCity,
        homeQuickEntryDefinitions,
    };
})();
