window.SUXI_SYSTEM_STATIC = (() => {
    const aiModelConfigI18n = {
        'zh-CN': {
            'global.languageLabel': '语言',
            'aiModelConfig.pageTitle': 'AI模型配置',
            'aiModelConfig.pageDescription': '管理 DeepSeek、OpenAI 等 OpenAI 兼容模型',
            'aiModelConfig.refreshButton': '刷新',
            'aiModelConfig.advancedButton': '高级配置',
            'aiModelConfig.advancedTitle': '高级配置',
            'aiModelConfig.advancedDescription': '手动维护 model_key、provider、base_url、model_name 和使用场景。',
            'aiModelConfig.addManualConfig': '新增手动配置',
            'aiModelConfig.columnDisplayName': '显示名称',
            'aiModelConfig.columnModelKey': 'model_key',
            'aiModelConfig.columnProvider': '供应商',
            'aiModelConfig.columnModel': '模型',
            'aiModelConfig.columnBaseUrl': 'Base URL',
            'aiModelConfig.columnApiKey': 'API Key',
            'aiModelConfig.columnScene': '场景',
            'aiModelConfig.columnStatus': '状态',
            'aiModelConfig.columnActions': '操作',
            'aiModelConfig.loading': '加载中...',
            'aiModelConfig.defaultBadge': '默认',
            'aiModelConfig.unconfigured': '未配置',
            'aiModelConfig.enabled': '启用',
            'aiModelConfig.disabled': '禁用',
            'aiModelConfig.editTitle': '编辑',
            'aiModelConfig.testConnectionTitle': '测试连接',
            'aiModelConfig.empty': '暂无模型配置',
            'aiModelConfig.modalEditTitle': '高级配置：编辑AI模型',
            'aiModelConfig.modalCreateTitle': '高级配置：新增AI模型',
            'aiModelConfig.nameLabel': '显示名称 *',
            'aiModelConfig.modelKeyLabel': 'model_key *',
            'aiModelConfig.providerLabel': 'provider *',
            'aiModelConfig.modelNameLabel': 'model_name *',
            'aiModelConfig.baseUrlLabel': 'base_url *',
            'aiModelConfig.apiKeyLabel': 'API Key',
            'aiModelConfig.apiKeyEditPlaceholder': '留空则不覆盖已有 Key',
            'aiModelConfig.apiKeyCreatePlaceholder': '请输入 API Key',
            'aiModelConfig.apiKeyHelp': '列表只显示脱敏 Key；编辑时留空不会覆盖旧 Key。',
            'aiModelConfig.currentKeyPrefix': '当前：',
            'aiModelConfig.usageSceneLabel': '使用场景',
            'aiModelConfig.usageScenePlaceholder': '日常经营诊断',
            'aiModelConfig.setDefaultTitle': '设为默认',
            'aiModelConfig.setDefaultDescription': '用于默认模型选择',
            'aiModelConfig.enableModelTitle': '启用模型',
            'aiModelConfig.enableModelDescription': '禁用后不可用于调用',
            'aiModelConfig.cancelButton': '取消',
            'aiModelConfig.saveButton': '保存',
            'aiModelConfig.loadFailed': '加载AI模型配置失败',
            'aiModelConfig.saveUpdated': '模型配置已更新',
            'aiModelConfig.saveCreated': '模型配置已创建',
            'aiModelConfig.saveFailed': '保存失败',
            'aiModelConfig.modelEnabled': '模型已启用',
            'aiModelConfig.modelDisabled': '模型已禁用',
            'aiModelConfig.operationFailed': '操作失败',
            'aiModelConfig.testSuccess': '模型连接测试成功',
            'aiModelConfig.testFailed': '模型连接测试失败',
            'aiModelConfig.networkErrorFallback': '网络错误',
            'aiModelQuickSetup.quickTitle': '快速配置AI厂家',
            'aiModelQuickSetup.quickDescription': '选择厂家并填写 API Key，系统自动生成可用模型配置。',
            'aiModelQuickSetup.providerLabel': '模型厂家',
            'aiModelQuickSetup.apiKeyLabel': 'API Key',
            'aiModelQuickSetup.apiKeyPlaceholder': '请输入 API Key',
            'aiModelQuickSetup.baseUrlLabel': 'Base URL',
            'aiModelQuickSetup.baseUrlPlaceholder': '留空则使用内置兼容地址',
            'aiModelQuickSetup.baseUrlHelp': 'Meta、Amazon、Microsoft、IBM 等需填写 OpenAI 兼容网关地址。',
            'aiModelQuickSetup.saving': '配置中...',
            'aiModelQuickSetup.saveButton': '保存并自动配置',
            'aiModelQuickSetup.providerRequired': '请选择模型厂家',
            'aiModelQuickSetup.apiKeyRequired': '请输入 API Key',
            'aiModelQuickSetup.success': '自动配置成功：新增 {created} 条，更新 {updated} 条',
            'aiModelQuickSetup.autoConfigureFailed': '自动配置失败',
            'aiModelQuickSetup.networkError': '自动配置失败: {message}',
            'aiModelQuickSetup.networkErrorFallback': '网络错误',
        },
        'en-US': {
            'global.languageLabel': 'Language',
            'aiModelConfig.pageTitle': 'AI Model Configuration',
            'aiModelConfig.pageDescription': 'Manage DeepSeek, OpenAI, and other OpenAI-compatible models',
            'aiModelConfig.refreshButton': 'Refresh',
            'aiModelConfig.advancedButton': 'Advanced',
            'aiModelConfig.advancedTitle': 'Advanced Configuration',
            'aiModelConfig.advancedDescription': 'Manually maintain model_key, provider, base_url, model_name, and usage scenario.',
            'aiModelConfig.addManualConfig': 'Add Manual Config',
            'aiModelConfig.columnDisplayName': 'Display Name',
            'aiModelConfig.columnModelKey': 'model_key',
            'aiModelConfig.columnProvider': 'Provider',
            'aiModelConfig.columnModel': 'Model',
            'aiModelConfig.columnBaseUrl': 'Base URL',
            'aiModelConfig.columnApiKey': 'API Key',
            'aiModelConfig.columnScene': 'Scenario',
            'aiModelConfig.columnStatus': 'Status',
            'aiModelConfig.columnActions': 'Actions',
            'aiModelConfig.loading': 'Loading...',
            'aiModelConfig.defaultBadge': 'Default',
            'aiModelConfig.unconfigured': 'Not configured',
            'aiModelConfig.enabled': 'Enabled',
            'aiModelConfig.disabled': 'Disabled',
            'aiModelConfig.editTitle': 'Edit',
            'aiModelConfig.testConnectionTitle': 'Test connection',
            'aiModelConfig.empty': 'No model configurations',
            'aiModelConfig.modalEditTitle': 'Advanced Configuration: Edit AI Model',
            'aiModelConfig.modalCreateTitle': 'Advanced Configuration: Add AI Model',
            'aiModelConfig.nameLabel': 'Display Name *',
            'aiModelConfig.modelKeyLabel': 'model_key *',
            'aiModelConfig.providerLabel': 'provider *',
            'aiModelConfig.modelNameLabel': 'model_name *',
            'aiModelConfig.baseUrlLabel': 'base_url *',
            'aiModelConfig.apiKeyLabel': 'API Key',
            'aiModelConfig.apiKeyEditPlaceholder': 'Leave blank to keep the existing key',
            'aiModelConfig.apiKeyCreatePlaceholder': 'Enter API Key',
            'aiModelConfig.apiKeyHelp': 'The list only shows masked keys. Leaving this blank while editing will keep the existing key.',
            'aiModelConfig.currentKeyPrefix': 'Current: ',
            'aiModelConfig.usageSceneLabel': 'Usage Scenario',
            'aiModelConfig.usageScenePlaceholder': 'Daily operations diagnosis',
            'aiModelConfig.setDefaultTitle': 'Set as default',
            'aiModelConfig.setDefaultDescription': 'Used for default model selection',
            'aiModelConfig.enableModelTitle': 'Enable model',
            'aiModelConfig.enableModelDescription': 'Disabled models cannot be used for calls',
            'aiModelConfig.cancelButton': 'Cancel',
            'aiModelConfig.saveButton': 'Save',
            'aiModelConfig.loadFailed': 'Failed to load AI model configurations',
            'aiModelConfig.saveUpdated': 'Model configuration updated',
            'aiModelConfig.saveCreated': 'Model configuration created',
            'aiModelConfig.saveFailed': 'Save failed',
            'aiModelConfig.modelEnabled': 'Model enabled',
            'aiModelConfig.modelDisabled': 'Model disabled',
            'aiModelConfig.operationFailed': 'Operation failed',
            'aiModelConfig.testSuccess': 'Model connection test succeeded',
            'aiModelConfig.testFailed': 'Model connection test failed',
            'aiModelConfig.networkErrorFallback': 'Network error',
            'aiModelQuickSetup.quickTitle': 'Quick AI Provider Setup',
            'aiModelQuickSetup.quickDescription': 'Select a provider and enter an API Key. The system will generate usable model configurations automatically.',
            'aiModelQuickSetup.providerLabel': 'Model Provider',
            'aiModelQuickSetup.apiKeyLabel': 'API Key',
            'aiModelQuickSetup.apiKeyPlaceholder': 'Enter API Key',
            'aiModelQuickSetup.baseUrlLabel': 'Base URL',
            'aiModelQuickSetup.baseUrlPlaceholder': 'Leave blank to use the built-in compatible endpoint',
            'aiModelQuickSetup.baseUrlHelp': 'Meta, Amazon, Microsoft, and IBM require an OpenAI-compatible gateway URL.',
            'aiModelQuickSetup.saving': 'Configuring...',
            'aiModelQuickSetup.saveButton': 'Save and Auto-configure',
            'aiModelQuickSetup.providerRequired': 'Please select a model provider',
            'aiModelQuickSetup.apiKeyRequired': 'Please enter an API Key',
            'aiModelQuickSetup.success': 'Auto-configured: {created} created, {updated} updated',
            'aiModelQuickSetup.autoConfigureFailed': 'Auto-configuration failed',
            'aiModelQuickSetup.networkError': 'Auto-configuration failed: {message}',
            'aiModelQuickSetup.networkErrorFallback': 'Network error',
        },
    };
    const languageOptions = [
        { value: 'zh-CN', label: '中文' },
        { value: 'en-US', label: 'English' },
    ];
    const menuItemDefinitions = [
        { name: '首页', path: 'compass', icon: 'fas fa-home', requireSuper: false, requireManager: true, permissions: [] },
        {
            name: '运营收益闭环',
            testid: 'nav-project-ai-management',
            icon: 'fas fa-project-diagram',
            requireSuper: false,
            permissions: [],
            children: [
                {
                    name: '运营管理（P0）',
                    testid: 'nav-ai-ops',
                    icon: 'fas fa-cogs',
                    path: 'ai-ops',
                    children: [
                        { name: '策源·全维数据', path: 'ops-source', icon: 'fas fa-search' },
                        { name: '策析·根因定位', path: 'ops-analysis', icon: 'fas fa-microscope' },
                        { name: '策见·预警推送', path: 'ops-insight', icon: 'fas fa-bell' },
                        { name: 'AI经营日报', path: 'ai-daily-report', icon: 'fas fa-file-alt' },
                        { name: '策案·策略模拟', path: 'ops-plan', icon: 'fas fa-lightbulb' },
                        { name: '策行·效果追踪', path: 'ops-track', icon: 'fas fa-play-circle' }
                    ]
                },
                {
                    name: '筹建管理（二期）',
                    testid: 'nav-ai-construction',
                    icon: 'fas fa-hammer',
                    path: 'ai-construction',
                    children: [
                        { name: '智略·战略推演', path: 'ai-strategy', icon: 'fas fa-chess' },
                        { name: '智算·量化模拟', path: 'ai-simulation', icon: 'fas fa-calculator' },
                        { name: '智策·可行性报告', path: 'ai-feasibility', icon: 'fas fa-file-contract' }
                    ]
                },
                {
                    name: '开业管理（二期）',
                    testid: 'nav-ai-opening',
                    icon: 'fas fa-store',
                    path: 'ai-opening',
                    children: [
                        { name: '开业准备总览', path: 'opening-overview', icon: 'fas fa-clipboard-check' },
                        { name: '开业检查清单', path: 'opening-checklist', icon: 'fas fa-tasks' }
                    ]
                },
                {
                    name: '扩张管理（二期）',
                    testid: 'nav-ai-expansion',
                    icon: 'fas fa-chart-line',
                    path: 'ai-expansion',
                    children: [
                        { name: '智投·市场评估', path: 'market-evaluation', icon: 'fas fa-chart-area' },
                        { name: '智瞰·标杆选模', path: 'benchmark-model', icon: 'fas fa-star' },
                        { name: '智联·协同提效', path: 'collaboration-efficiency', icon: 'fas fa-link' }
                    ]
                },
                {
                    name: '转让管理（二期）',
                    testid: 'nav-ai-transfer',
                    icon: 'fas fa-exchange-alt',
                    path: 'ai-transfer',
                    children: [
                        { name: '智算·资产定价', path: 'asset-pricing', icon: 'fas fa-calculator' },
                        { name: '智略·时机推演', path: 'timing-strategy', icon: 'fas fa-chess-knight' },
                        { name: '智决·数据看板', path: 'decision-board', icon: 'fas fa-chart-pie' }
                    ]
                }
            ]
        },
        { name: '全生命周期辅助', path: 'lifecycle', icon: 'fas fa-share-alt', requireSuper: false, permissions: [], highlight: true },
        { name: '门店管理', path: 'hotels', icon: 'fas fa-hotel', configKey: 'menu_hotel_name', requireSuper: false, permissions: [] },
        {
            name: '线上数据手动获取',
            icon: 'fas fa-cloud-download-alt',
            requireSuper: false,
            permissions: ['can_view_online_data'],
            children: [
                { name: '携程ebooking', path: 'ctrip-ebooking', icon: 'fas fa-plane' },
                { name: '美团ebooking', path: 'meituan-ebooking', icon: 'fas fa-store' }
            ]
        },
        { name: '平台数据自动获取', path: 'online-data', tab: 'platform-auto', icon: 'fas fa-robot', testid: 'nav-platform-auto-fetch', requireSuper: false, permissions: ['can_view_online_data'] },
        { name: '酒店AI工具箱', path: 'agent-center', icon: 'fas fa-toolbox', requireSuper: true, permissions: [] },
        { name: '酒店图片优化助手', path: 'hotel-image-optimizer', icon: 'fas fa-image', requireManager: true, permissions: [] },
        { name: '酒店收益管理研究中心', path: 'revenue-research-center', icon: 'fas fa-chart-line', testid: 'nav-revenue-research-center', requireManager: true, permissions: [] },
        { name: '智能知识中枢', path: 'knowledge-center', icon: 'fas fa-brain', requireManager: true, permissions: [] },
        {
            name: '团队管理',
            icon: 'fas fa-users',
            requireManager: true,
            permissions: [],
            children: [
                { name: '员工管理', path: 'users', icon: 'fas fa-user-friends' },
                { name: '角色权限', path: 'roles', icon: 'fas fa-user-shield', requireSuper: true },
                { name: '操作日志', path: 'operation-logs', icon: 'fas fa-history', requireSuper: true }
            ]
        },
        {
            name: '系统设置',
            icon: 'fas fa-cog',
            requireSuper: true,
            permissions: [],
            children: [
                { name: '系统配置', path: 'system-config', icon: 'fas fa-sliders-h' },
                { name: 'AI模型配置', path: 'ai-model-config', icon: 'fas fa-robot', i18nKey: 'aiModelConfig.pageTitle' },
                { name: 'AI决策追溯', path: 'ai-governance', icon: 'fas fa-shield-alt' },
                { name: '数据配置', path: 'data-config', icon: 'fas fa-database' }
            ]
        },
    ];
    const testIdNameMap = {
        '项目AI管理': 'project-ai-management',
        '首页': 'compass',
        '全生命周期服务': 'lifecycle',
        '全生命周期辅助': 'lifecycle',
        '线上数据手动获取': 'online-data',
        '团队管理': 'team',
        '智能知识中枢': 'knowledge-center',
        '系统设置': 'system',
        '生成战略推荐': 'generate-strategy',
        '刷新历史': 'refresh-history',
        '运行三情景模拟': 'run-scenario-simulation',
        '生成报告': 'generate-feasibility-report',
        '重新生成': 'regenerate-feasibility-report',
        '生成市场评估': 'generate-market-evaluation',
        '生成标杆模型': 'generate-benchmark-model',
        '生成协同看板': 'generate-collaboration-board',
        '从真实数据带入': 'load-source-data',
        '刷新记录': 'refresh-records',
        '计算资产定价': 'calculate-asset-pricing',
        '生成时机判断': 'generate-timing',
        '生成决策看板': 'generate-decision-board',
        '生成数据看板': 'generate-decision-board',
        '刷新': 'refresh',
        '开始分析': 'start-analysis',
        '模拟': 'simulate',
        '创建': 'create',
        '项目名称': 'project-name',
        '城市': 'city',
        '区域': 'district',
        '地址': 'address',
        '物业面积(㎡)': 'property-area',
        '物业面积（㎡）': 'property-area',
        '计划房间数': 'room-count',
        '房间数': 'room-count',
        '目标房量': 'target-room-count',
        '月租金(元)': 'monthly-rent',
        '预估租金（元/月）': 'estimated-rent',
        '装修预算(元)': 'decoration-budget',
        '装修投资': 'decoration-investment',
        '家具设备投资': 'furniture-investment',
        '开办费': 'opening-cost',
        '其他投资': 'other-investment',
        'ADR': 'adr',
        '入住率(%)': 'occupancy-rate',
        '入住率': 'occupancy-rate',
        '其他收入': 'other-income',
        '城市线级': 'city-tier',
        '商圈/区域': 'business-area',
        '商圈': 'business-area',
        '装修档次': 'decoration-level',
        '主客群': 'primary-customer',
        '辅助客群': 'secondary-customer',
        '目标价格带': 'target-price-band',
        '酒店类型': 'hotel-type',
        '绑定酒店': 'bound-hotel',
        '取数截止日': 'source-date',
        '酒店ID': 'hotel-id',
        '策略标题': 'strategy-title',
        '备注': 'remark',
    };
    const hotelColumns = [
        { key: 'id', label: 'ID' },
        { key: 'name', label: '酒店名称' },
        { key: 'code', label: '编码' },
        { key: 'address', label: '地址' },
        { key: 'contact_person', label: '联系人' },
        { key: 'contact_phone', label: '联系电话' },
        { key: 'status', label: '状态' },
        { key: 'actions', label: '操作' },
    ];
    const userColumns = [
        { key: 'id', label: 'ID' },
        { key: 'username', label: '用户名' },
        { key: 'realname', label: '姓名' },
        { key: 'role', label: '角色' },
        { key: 'hotel', label: '所属酒店' },
        { key: 'status', label: '状态' },
        { key: 'actions', label: '操作' },
    ];
    const knowledgeCenterBaseSourceOptions = ['document', 'video', 'link', 'text', 'strategy', 'manual', 'url', 'ota', 'ctrip', 'meituan', 'ai', 'revenue_research', 'ml_distillation'];
    const knowledgeImportModeMetaMap = {
        document: { label: '门店文档', placeholder: '整份门店文档会作为一条资料读取，不按空行拆分' },
        video: { label: '视频链接列表', placeholder: '每行一个视频链接，可填写培训视频、直播回放、案例讲解链接' },
        link: { label: '链接列表', placeholder: '每行一个文章、案例、报表或资料链接' },
        text: { label: '文本内容', placeholder: '每段经验用空行分隔，可粘贴会议纪要、SOP、点评处理过程' },
        strategy: { label: '策略复盘内容', placeholder: '每个策略复盘用空行分隔，建议包含场景、动作、结果、可复用经验' },
        url: { label: 'URL 列表', placeholder: '每行一个 URL' },
        manual: { label: '手动内容', placeholder: '每个知识块用空行分隔' },
    };
    const aiQuickSetupProviderModelMap = {
        deepseek: ['deepseek-chat', 'deepseek-reasoner'],
        openai: ['openai_gpt', 'openai_fast'],
        anthropic: ['anthropic_claude'],
        gemini: ['gemini_flash'],
        meta_llama: ['meta_llama'],
        xai: ['xai_grok'],
        mistral: ['mistral_large'],
        cohere: ['cohere_command'],
        perplexity: ['perplexity_sonar'],
        amazon_nova: ['amazon_nova'],
        microsoft_phi: ['microsoft_phi'],
        ibm_granite: ['ibm_granite'],
        nvidia: ['nvidia_nemotron'],
    };
    const baseAiModelOptions = [
        { value: 'deepseek_chat', label: 'DeepSeek Chat' },
        { value: 'deepseek_reasoner', label: 'DeepSeek Reasoner' },
        { value: 'openai_fast', label: 'OpenAI Fast' },
    ];
    const aiGovernanceTabs = [
        { key: 'logs', label: '调用日志' },
        { key: 'prompts', label: 'Prompt版本' },
        { key: 'evals', label: '评估集' },
    ];
    const dataConfigProfiles = {
        'ctrip-ebooking': {
            description: '用于携程经营榜单抓取，核心参数为接口地址、Node ID、Cookie 和日期范围。',
            requiredText: '接口地址 / 节点 / 平台授权',
            outputText: '房价、销量、订单、排名',
        },
        'meituan-ebooking': {
            description: '用于美团竞对排名抓取，核心参数为平台接口标识、门店标识、榜单类型、平台授权和日期范围。',
            requiredText: '接口标识 / 门店标识 / 平台授权',
            outputText: '入住、销售、转化、流量榜',
        },
        'ctrip-traffic': {
            description: '用于携程流量分析抓取，支持携程/去哪儿平台、日期范围和额外 JSON 参数。',
            requiredText: '接口地址 / 平台授权 / 平台',
            outputText: '访问量、转化率、趋势',
        },
        'ctrip-cookie-api': {
            description: '用于携程 Cookie API 直连诊断，支持接口清单、单个 Request URL、Cookie 或已登录 Profile。',
            requiredText: 'Request URL 清单 / Cookie 或 Profile',
            outputText: '经营、流量、广告、商旅、质量等诊断行',
        },
        'meituan-traffic': {
            description: '用于美团流量接口抓取，支持接口地址、平台接口标识、门店标识、平台授权、日期范围和额外参数。',
            requiredText: '接口地址 / 接口标识 / 门店标识 / 平台授权',
            outputText: '曝光、访问、转化',
        },
        'booking-ota': {
            description: '用于 Booking.com 配置归档，优先覆盖房费收入、出租间夜和后台数据源口径。',
            requiredText: '后台入口 / 关联门店 / 字段映射',
            outputText: '房费收入、出租间夜、配置口径',
        },
        'agoda-ota': {
            description: '用于 Agoda 配置归档，优先覆盖房费收入、出租间夜和亚太客源口径。',
            requiredText: '后台入口 / 关联门店 / 字段映射',
            outputText: '房费收入、出租间夜、配置口径',
        },
        'expedia-ota': {
            description: '用于 Expedia 配置归档，优先覆盖房费收入、出租间夜和海外渠道口径。',
            requiredText: '后台入口 / 关联门店 / 字段映射',
            outputText: '房费收入、出租间夜、配置口径',
        },
        'ctrip-comments': {
            description: '携程点评当前暂缓，不进入默认自动采集；该配置仅保留为显式手动兼容入口。',
            requiredText: 'Profile / Cookie / spidertoken',
            outputText: '暂缓 / 手动启用',
        },
        'meituan-comments': {
            description: '美团点评当前暂缓，不进入默认自动采集；该配置仅保留为显式手动兼容入口。',
            requiredText: '接口标识 / 门店标识 / 平台授权',
            outputText: '暂缓 / 手动启用',
        },
        'ctrip-ads': {
            description: '用于携程金字塔广告投放数据直接获取，核心参数为广告接口 URL、Cookie、日期和可选 Payload。',
            requiredText: '接口URL / Cookie / 日期',
            outputText: '曝光、点击、成交、费用',
        },
        'meituan-ads': {
            description: '用于美团推广通广告接口抓取，支持广告接口地址、平台授权、店铺或门店标识、日期范围和请求参数。',
            requiredText: '接口地址 / 平台授权 / 店铺或门店标识',
            outputText: '曝光、点击、转化',
        },
    };
    const getDefaultDataConfigForm = () => ({
        config_name: '',
        enabled: true,
        url: '',
        cookies: '',
        cookie: '',
        hotelId: '',
        system_hotel_id: '',
        startDate: '',
        endDate: '',
        start_date: '',
        end_date: '',
        begin_date: '',
        date_range: '1',
        platform: 'Ctrip',
        fetch_frequency: 'manual',
        schedule_time: '06:00',
        cookie_expire_days: 7,
        auto_save: true,
        extraParams: '',
        extra_params: '',
        remark: '',
        nodeId: '',
        node_id: '',
        auth_data: '',
        partnerId: '',
        poiId: '',
        rankType: '',
        partner_id: '',
        poi_id: '',
        rank_type: 'P_RZ',
        rank_types: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'],
        data_scope: 'vpoi',
        method: 'GET',
        shop_id: '',
        hotel_room_count: '',
        competitor_room_count: '',
        request_url: '',
        request_urls: '',
        requestUrls: '',
        endpoints_json: '',
        endpointsJson: '',
        headers_json: '',
        headersJson: '',
        master_hotel_id: '',
        profile_id: '',
        profileId: '',
        hotel_id: '',
        ctrip_hotel_id: '',
        ctripHotelId: '',
        spidertoken: '',
        payloadJson: '',
        payload_json: '',
        _fxpcqlniredt: '',
        x_trace_id: '',
        tag_type: '',
        page_index: 1,
        page_size: 10,
        mtgsig: '',
        _mtsi_eb_u: '',
        reply_type: '2',
        tag: '',
        limit: 10,
        offset: 0,
        custom_url: '',
        api_type: 'effect_report',
        campaign_id: '',
        time_unit: 'day',
    });
    const getDataConfigTypeDefaults = (type) => ({
        'ctrip-ebooking': {
            url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
            node_id: '24588',
            nodeId: '24588',
            date_range: '1',
        },
        'meituan-ebooking': {
            url: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
            rank_type: 'P_RZ',
            rankType: 'P_RZ',
            data_scope: 'vpoi',
            date_range: '1',
        },
        'ctrip-traffic': {
            url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
            platform: 'Ctrip',
            date_range: '1',
        },
        'ctrip-cookie-api': {
            method: 'GET',
            date_range: '1',
            request_urls: '',
            endpoints_json: '',
        },
        'meituan-traffic': {
            date_range: '1',
        },
        'booking-ota': {
            platform: 'booking',
            url: 'https://admin.booking.com/',
            extra_params: '{"revenue_field":"Booking.com房费收入","rooms_field":"Booking.com出租间夜"}',
        },
        'agoda-ota': {
            platform: 'agoda',
            url: 'https://ycs.agoda.com/',
            extra_params: '{"revenue_field":"Agoda房费收入","rooms_field":"Agoda出租间夜"}',
        },
        'expedia-ota': {
            platform: 'expedia',
            url: 'https://apps.expediapartnercentral.com/',
            extra_params: '{"revenue_field":"Expedia房费收入","rooms_field":"Expedia出租间夜"}',
        },
        'ctrip-comments': {
            page_index: 1,
            page_size: 50,
        },
        'meituan-comments': {
            reply_type: '2',
            limit: 50,
            offset: 0,
        },
        'ctrip-ads': {
            api_type: 'effect_report',
            date_range: '1',
        },
        'meituan-ads': {
            method: 'GET',
            time_unit: 'day',
        },
    }[type] || {});
    const getSystemConfigDefaults = () => ({
        system_name: '宿析OS',
        logo_url: '',
        favicon_url: '',
        system_description: '授权OTA数据驱动的经营诊断、AI建议与动作复盘系统',
        system_keywords: '酒店管理,收益分析,数据分析',
        menu_hotel_name: '酒店管理',
        menu_users_name: '用户管理',
        menu_compass_name: '罗盘',
        menu_online_data_name: '竞对价格监控',
        theme: 'light',
        primary_color: '#3B82F6',
        date_format: 'Y-m-d',
        time_format: 'H:i:s',
        page_size_options: '10,20,50,100',
        default_page_size: '20',
        enable_registration: '0',
        enable_login_log: '1',
        enable_operation_log: '1',
        enable_data_backup: '1',
        enable_wechat_mini: '0',
        enable_online_data: '1',
        wechat_mini_appid: '',
        wechat_mini_secret: '',
        complaint_mini_page: 'pages/complaint/index',
        complaint_mini_use_scene: '1',
        login_max_attempts: '5',
        login_lockout_duration: '15',
        session_timeout: '1440',
        password_min_length: '6',
        password_require_special: '0',
        notify_email_enabled: '0',
        notify_email_server: '',
        notify_email_port: '587',
        notify_email_user: '',
        notify_email_pass: '',
    });
    const platformAccountBindingGuidePresetRows = [
        {
            key: 'ctrip-profile',
            title: '携程 Profile',
            summary: '适合日常自动采集；系统复用门店浏览器登录态并监听业务 JSON。',
            methodLabel: 'browser_profile',
            platform: 'ctrip',
            dataType: 'business',
            ingestionMethod: 'browser_profile',
            icon: 'fas fa-user-check text-blue-600',
            toneClass: 'border-blue-100 bg-blue-50/60',
            evidence: 'profile_id / capture_gate',
            boundary: '不保存评论正文或客人手机号',
            nextAction: '先登录 Profile，再执行试采集',
            config: {
                profile_id: 'replace_with_ctrip_profile_id',
                hotel_id: 'replace_with_ctrip_hotel_id',
                capture_sections: 'default',
            },
        },
        {
            key: 'meituan-profile',
            title: '美团 Profile',
            summary: '适合流量、榜单、平台标签；页面触发接口后按批次入库。',
            methodLabel: 'browser_profile',
            platform: 'meituan',
            dataType: 'traffic',
            ingestionMethod: 'browser_profile',
            icon: 'fas fa-store text-orange-600',
            toneClass: 'border-orange-100 bg-orange-50/70',
            evidence: 'store_id / poi_id / capture_gate',
            boundary: '不配置订单手机号、房态、房源映射',
            nextAction: '先补 POI，再登录 Profile',
            config: {
                store_id: 'replace_with_meituan_store_id',
                poi_id: 'replace_with_meituan_poi_id',
                partner_id: 'replace_with_meituan_partner_id',
                capture_sections: 'traffic',
            },
        },
        {
            key: 'cookie-api',
            title: 'Cookie/API',
            summary: '适合已确认 URL、Payload、字段语义的轻量补数或巡检。',
            methodLabel: 'api',
            platform: 'ctrip',
            dataType: 'business',
            ingestionMethod: 'api',
            icon: 'fas fa-plug text-emerald-600',
            toneClass: 'border-emerald-100 bg-emerald-50/70',
            evidence: 'allowed_hosts / request_url',
            boundary: '密文只进后端 secret_json',
            nextAction: '先校验门店ID，再试采集',
            config: {
                request_url: 'replace_with_verified_api_url',
                method: 'GET',
                allowed_hosts: ['ebooking.ctrip.com', 'meituan.com'],
                hotel_id: 'replace_with_platform_hotel_id',
            },
        },
    ];
    const getPlatformAccountBindingGuideRows = () => platformAccountBindingGuidePresetRows.map(row => ({
        ...row,
        config: {
            ...(row.config || {}),
            allowed_hosts: Array.isArray(row.config?.allowed_hosts) ? [...row.config.allowed_hosts] : row.config?.allowed_hosts,
        },
    }));
    const knowledgeDocumentTextExtensions = ['txt', 'md', 'markdown', 'csv', 'json', 'log'];
    const knowledgeDocumentHtmlExtensions = ['html', 'htm'];
    const knowledgeDocumentSupportedExtensions = [...knowledgeDocumentTextExtensions, ...knowledgeDocumentHtmlExtensions, 'docx'];
    const agentTabs = [
        { key: 'overview', name: '工具箱', icon: 'fas fa-toolbox' },
        { key: 'staff', name: '智能员工', icon: 'fas fa-user-friends' },
        { key: 'revenue', name: '收益管理', icon: 'fas fa-chart-line' },
        { key: 'asset', name: '资产运维', icon: 'fas fa-tools' },
        { key: 'logs', name: '运行日志', icon: 'fas fa-list-alt' },
    ];
    const resolveMenuItems = (items = [], config = {}) => (Array.isArray(items) ? items : []).map((item) => {
        const resolved = { ...item };
        if (resolved.configKey) {
            resolved.name = config?.[resolved.configKey] || resolved.name;
        }
        if (Array.isArray(resolved.children)) {
            resolved.children = resolveMenuItems(resolved.children, config);
        }
        return resolved;
    });
    const filterVisibleMenuItems = (items = [], currentUser = null) => {
        if (!currentUser) return [];
        if (currentUser.is_super_admin) return Array.isArray(items) ? items : [];

        const isItemVisible = (item) => {
            if (item.requireSuper) return false;
            if (item.requireManager && currentUser.role_id !== 2) return false;
            if (item.permissions && item.permissions.length > 0) {
                const perms = currentUser.permissions || {};
                return item.permissions.some(p => perms[p]);
            }
            return true;
        };

        return (Array.isArray(items) ? items : []).filter((item) => {
            if (item.children) {
                const visibleChildren = item.children.filter(child => isItemVisible(child));
                return visibleChildren.length > 0;
            }
            return isItemVisible(item);
        }).map((item) => {
            if (item.children) {
                return {
                    ...item,
                    children: item.children.filter(child => isItemVisible(child)),
                };
            }
            return item;
        });
    };
    const firstNonEmptyText = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && String(item).trim() !== '');
        return value === undefined ? '' : String(value).trim();
    };
    const requireHotelPlatformHelper = (helpers, key) => {
        const helper = helpers?.[key];
        if (typeof helper !== 'function') {
            throw new Error(`Missing hotel platform account helper: ${key}`);
        }
        return helper;
    };
    const platformNextActionMeta = ({ label = '', statusCode = '', captureCode = '', bound = false } = {}) => {
        const prefix = label || '账号';
        if (statusCode === 'mismatch') {
            return { text: `${prefix}复核`, weight: 5, className: 'bg-red-50 text-red-700 border-red-200', target: 'hotel-ota', actionKey: 'fix_identity_mismatch' };
        }
        if (statusCode === 'login_expired') {
            return { text: `${prefix}重登`, weight: 10, className: 'bg-red-50 text-red-700 border-red-200', target: 'profile-login', actionKey: 'login_platform_profile' };
        }
        if (captureCode === 'failed') {
            return { text: `${prefix}重采`, weight: 15, className: 'bg-red-50 text-red-700 border-red-200', target: 'sync-logs', actionKey: 'open_sync_logs' };
        }
        if (statusCode === 'missing_config') {
            return { text: `${prefix}补配置`, weight: 20, className: 'bg-amber-50 text-amber-700 border-amber-200', target: 'hotel-ota', actionKey: 'complete_platform_identity' };
        }
        if (statusCode === 'waiting_login') {
            return { text: `${prefix}登录`, weight: 25, className: 'bg-amber-50 text-amber-700 border-amber-200', target: 'profile-login', actionKey: 'login_platform_profile' };
        }
        if (statusCode === 'unbound') {
            return { text: `${prefix}添加`, weight: bound ? 30 : 35, className: 'bg-gray-50 text-gray-500 border-gray-200', target: 'hotel-ota', actionKey: 'bind_platform_account' };
        }
        if (statusCode === 'logged_in' && captureCode === 'none') {
            return { text: `${prefix}采集`, weight: 40, className: 'bg-blue-50 text-blue-700 border-blue-200', target: 'platform-auto', actionKey: 'run_trial_capture' };
        }
        return { text: '正常', weight: 99, className: 'bg-emerald-50 text-emerald-700 border-emerald-200', target: '', actionKey: '' };
    };
    const platformAccountStoreText = (label, hotel, source = {}, config = {}) => {
        const sourceConfig = source?.config || {};
        const name = firstNonEmptyText(
            sourceConfig.hotel_name,
            sourceConfig.poi_name,
            sourceConfig.store_name,
            config?.hotel_name,
            config?.poi_name,
            config?.store_name,
            source?.platform_hotel_name,
            config?.name
        );
        if (name) return name;
        if (source || config) return `${label}账号已绑定`;
        return '-';
    };
    const buildHotelPlatformAccountRow = ({
        hotel,
        platform,
        label,
        icon,
        iconClass,
        profileSource,
        config,
        ready,
        partial,
        missingConfig,
        modules,
        source = {},
        helpers = {},
    } = {}) => {
        const hasPlatformHotelMismatch = requireHotelPlatformHelper(helpers, 'hasPlatformHotelMismatch');
        const isPlatformSourceLoginExpired = requireHotelPlatformHelper(helpers, 'isPlatformSourceLoginExpired');
        const platformCaptureStatusCode = requireHotelPlatformHelper(helpers, 'platformCaptureStatusCode');
        const platformAccountReason = requireHotelPlatformHelper(helpers, 'platformAccountReason');
        const formatHotelBindingDate = requireHotelPlatformHelper(helpers, 'formatHotelBindingDate');
        const platformLastSuccessText = requireHotelPlatformHelper(helpers, 'platformLastSuccessText');
        const platformAccountStatusText = requireHotelPlatformHelper(helpers, 'platformAccountStatusText');
        const platformAccountStatusClass = requireHotelPlatformHelper(helpers, 'platformAccountStatusClass');
        const platformCaptureStatusText = requireHotelPlatformHelper(helpers, 'platformCaptureStatusText');
        const platformCaptureStatusClass = requireHotelPlatformHelper(helpers, 'platformCaptureStatusClass');
        const mismatch = hasPlatformHotelMismatch(source, config);
        const loginExpired = isPlatformSourceLoginExpired(source, config);
        const sourceStatus = String(source?.status || source?.last_sync_status || '').toLowerCase();
        const sourceReady = !!source?.id && ['success', 'logged_in', 'active', 'ok'].includes(sourceStatus);
        const effectiveReady = ready || sourceReady;
        const effectivePartial = partial || !!source?.id;
        const statusCode = mismatch
            ? 'mismatch'
            : (loginExpired ? 'login_expired' : (effectiveReady ? 'logged_in' : (effectivePartial ? (missingConfig ? 'missing_config' : 'waiting_login') : 'unbound')));
        const captureCode = platformCaptureStatusCode(source, config);
        const reason = platformAccountReason(statusCode, captureCode, source, config);
        const bound = !!(profileSource || config || source?.id);
        const lastCaptureText = formatHotelBindingDate(firstNonEmptyText(
            source?.last_sync_time,
            source?.last_capture_time,
            source?.last_fetch_time,
            config?.last_sync_time,
            config?.last_capture_time,
            config?.last_fetch_time
        ));
        const nextAction = platformNextActionMeta({ label, statusCode, captureCode, bound });
        const sourceConfig = source?.config || {};
        const loginBinding = platform === 'ctrip'
            ? {
                profile_id: firstNonEmptyText(
                    sourceConfig.profile_id,
                    sourceConfig.hotel_id,
                    config?.profile_id,
                    config?.profileId,
                    config?.browser_profile_id,
                    config?.browserProfileId,
                    config?.ota_hotel_id,
                    config?.ctrip_hotel_id,
                    config?.ctripHotelId,
                    `system_${hotel?.id || ''}`
                ),
                hotel_id: firstNonEmptyText(sourceConfig.hotel_id, sourceConfig.ctrip_hotel_id, config?.ota_hotel_id, config?.ctrip_hotel_id, config?.ctripHotelId),
                hotel_name: firstNonEmptyText(sourceConfig.hotel_name, config?.hotel_name, hotel?.name),
            }
            : {
                store_id: firstNonEmptyText(sourceConfig.store_id, sourceConfig.poi_id, config?.store_id, config?.poi_id, config?.poiId),
                poi_id: firstNonEmptyText(sourceConfig.poi_id, sourceConfig.store_id, config?.poi_id, config?.poiId),
                poi_name: firstNonEmptyText(sourceConfig.poi_name, sourceConfig.store_name, config?.poi_name, config?.store_name, hotel?.name),
                partner_id_configured: !!firstNonEmptyText(sourceConfig.partner_id, config?.partner_id, config?.partnerId),
            };
        return {
            platform,
            label,
            icon,
            iconClass,
            profileSource,
            config: profileSource || config || null,
            deleteKey: profileSource?.id || `${platform}-${hotel?.id || ''}`,
            level: effectiveReady && !mismatch && !loginExpired ? 'ready' : (effectivePartial ? 'partial' : 'missing'),
            statusCode,
            statusText: platformAccountStatusText(statusCode),
            statusClass: platformAccountStatusClass(statusCode),
            accountStoreText: bound ? platformAccountStoreText(label, hotel, source, config) : '-',
            lastLoginText: formatHotelBindingDate(firstNonEmptyText(
                source?.last_login_time,
                source?.last_login_at,
                source?.login_time,
                source?.updated_at,
                source?.update_time,
                config?.last_login_time,
                config?.last_login_at,
                config?.update_time,
                config?.created_at
            )),
            lastCaptureText,
            lastSuccessText: platformLastSuccessText(source, config, captureCode, lastCaptureText),
            captureStatusText: platformCaptureStatusText(captureCode),
            captureStatusClass: platformCaptureStatusClass(captureCode),
            modules: effectiveReady && !mismatch && !loginExpired ? modules : ['无'],
            reasonText: reason.text,
            reasonClass: reason.className,
            nextActionText: nextAction.text,
            nextActionWeight: nextAction.weight,
            nextActionClass: nextAction.className,
            nextActionTarget: nextAction.target || '',
            nextActionKey: nextAction.actionKey || '',
            primaryActionText: bound ? '重新登录' : `添加${label}账号`,
            loginItem: {
                platform,
                platform_name: label,
                profile_key: firstNonEmptyText(loginBinding.profile_id, loginBinding.store_id, loginBinding.poi_id),
                binding: loginBinding,
            },
            canUnbind: !!profileSource,
            unbindItem: profileSource ? {
                data_source_id: profileSource.id,
                platform,
                platform_name: label,
                profile_key: firstNonEmptyText(profileSource?.config?.profile_id, profileSource?.config?.store_id, profileSource?.config?.hotel_id),
                binding: profileSource.config || {},
            } : null,
        };
    };

    return {
        aiModelConfigI18n,
        languageOptions,
        menuItemDefinitions,
        testIdNameMap,
        hotelColumns,
        userColumns,
        knowledgeCenterBaseSourceOptions,
        knowledgeImportModeMetaMap,
        aiQuickSetupProviderModelMap,
        baseAiModelOptions,
        aiGovernanceTabs,
        dataConfigProfiles,
        getDefaultDataConfigForm,
        getDataConfigTypeDefaults,
        getSystemConfigDefaults,
        getPlatformAccountBindingGuideRows,
        knowledgeDocumentTextExtensions,
        knowledgeDocumentHtmlExtensions,
        knowledgeDocumentSupportedExtensions,
        agentTabs,
        resolveMenuItems,
        filterVisibleMenuItems,
        platformNextActionMeta,
        platformAccountStoreText,
        buildHotelPlatformAccountRow,
    };
})();
