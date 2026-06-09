window.SUXI_OPERATION_STATIC = (() => {
    const lifecycleMetricLabels = {
        reports: '可研报告',
        latest_grade: '最新评级',
        latest_project: '最新项目',
        projects: '开业项目',
        open_tasks: '未完成任务',
        overdue_tasks: '逾期任务',
        avg_score: '平均评分',
        unread_alerts: '未读预警',
        active_actions: '执行动作',
        ota_rows: 'OTA数据',
        pending_prices: '待审价格',
        applied_prices: '已应用价格',
        future_forecasts: '未来预测',
        strategy_simulations: '推演记录',
        competitor_price_logs: '竞对价格',
    };
    const lifecycleStageTitles = {
        investment: '筹建',
        opening: '开业',
        operation: '运营',
        revenue: '收益',
        transfer: '转让',
    };
    const operationAlertFilters = [
        { key: 'all', label: '全部' },
        { key: 'high', label: '高风险' },
        { key: 'medium', label: '中风险' },
        { key: 'low', label: '低风险' },
        { key: 'unread', label: '未读' },
        { key: 'read', label: '已读' },
    ];
    const operationStrategyTypes = [
        { key: 'price_adjust', label: '调价模拟' },
        { key: 'promotion', label: '促销模拟' },
        { key: 'room_inventory', label: '房量模拟' },
        { key: 'competitor_follow', label: '竞对跟价' },
        { key: 'holiday_strategy', label: '节假日策略' },
    ];
    const openingCategories = [
        '证照合规',
        'PMS系统配置',
        'OTA上线配置',
        '房型房价库存',
        '客房工程验收',
        '物资布草备品',
        '员工招聘排班',
        '员工培训演练',
        '开业营销推广',
        '财务收银风控',
    ];
    const openingStatusOptions = [
        { value: 'todo', label: '未开始' },
        { value: 'doing', label: '进行中' },
        { value: 'done', label: '已完成' },
        { value: 'blocked', label: '受阻' },
    ];
    const openingProgressQuickValues = [0, 25, 50, 75, 100];

    return {
        lifecycleMetricLabels,
        lifecycleStageTitles,
        operationAlertFilters,
        operationStrategyTypes,
        openingCategories,
        openingStatusOptions,
        openingProgressQuickValues,
    };
})();
