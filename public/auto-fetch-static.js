window.SUXI_AUTO_FETCH_STATIC = (() => {
    const autoFetchModeOptions = [
        { value: 'hybrid_auto', label: '接口直连自动' },
        { value: 'cookie_config', label: '授权配置自动' },
        { value: 'profile_browser', label: '登录会话自动采集' },
    ];
    const autoFetchCollectionBlueprintRows = [
        { label: '采集对象', value: '授权 OTA 门店指标' },
        { label: '业务日期', value: '历史固定默认昨日；实时快照默认今日' },
        { label: '数据层', value: '原始证据 + 标准行 + 指标行' },
        { label: '入库规则', value: '历史按日更新；实时按小时快照更新' },
    ];
    const autoFetchFieldScopeGroups = [
        {
            category: 'OTA经营',
            metric: '经营概况',
            fields: [
                '今日APP访客', '预订销售额', '实时起价', '点评分', '在店间夜',
                '订单数', '紧张度', '昨日访客', '转化率', '离店销售额',
                '离店间夜', '平均卖价', '实时预订订单', '入住率', '实时排名', '竞争圈排名',
            ],
            source: '携程经营概要、销售报告、流量报告和房态价格页面；美团按已授权流量/订单模块补齐。',
            status: 'ready',
            statusText: '已归档路径',
            action: '默认优先跑经营概要、销售和流量；起价、入住率等以真实响应字段为准。',
        },
        {
            category: '服务质量',
            metric: '服务 / 点评',
            fields: [
                'PSI服务质量分', '点评分', '5分钟回复率', '收藏数',
                '正面标签', '负面标签', '好评率',
            ],
            source: 'PSI、评分、回复率、收藏数已有候选路径；点评标签和好评率涉及点评内容，需显式授权与样例核验。',
            status: 'partial',
            statusText: '需显式验证',
            action: '先保留服务质量指标；点评标签只在明确启用并通过采集门禁后接入。',
        },
        {
            category: '竞争对比',
            metric: '竞争圈',
            fields: [
                '竞对酒店', '距离', '商圈', '订单占比', '转化率',
                '订单数', '销售榜', '流量榜', '服务榜',
                '流失订单', '流失间夜', '流失金额',
            ],
            source: '携程竞争圈概览、榜单、流失分析和竞品酒店接口；美团排名按平台接口独立表达。',
            status: 'ready',
            statusText: '已归档路径',
            action: '使用 wide/all 采集，不把竞争圈数据当成全市场或全酒店经营口径。',
        },
        {
            category: '广告投放',
            metric: '金字塔',
            fields: [
                '广告曝光', '点击', '点击率', '预订', '转化率',
                '花费', '订单金额', '同行TOP对比', '同行平均对比', '自身排名对比',
            ],
            source: '携程金字塔 CPC 页面/接口已归档；费用、订单金额和同行对比依赖广告账号权限。',
            status: 'partial',
            statusText: '需广告授权',
            action: '广告数据独立进入 advertising 口径，不和自然流量、自然订单混算。',
        },
    ];

    return {
        autoFetchModeOptions,
        autoFetchCollectionBlueprintRows,
        autoFetchFieldScopeGroups,
    };
})();
