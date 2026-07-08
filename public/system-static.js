window.SUXI_SYSTEM_STATIC = (() => {
    const CHART_JS_SRC = 'https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js';
    let chartJsLoadPromise = null;
    const loadChartJs = () => {
        if (window.Chart) {
            return Promise.resolve(window.Chart);
        }
        if (!chartJsLoadPromise) {
            chartJsLoadPromise = new Promise((resolve, reject) => {
                const existing = document.querySelector('script[data-suxi-chartjs="1"]');
                if (existing) {
                    existing.addEventListener('load', () => resolve(window.Chart), { once: true });
                    existing.addEventListener('error', () => reject(new Error('Chart.js加载失败')), { once: true });
                    return;
                }
                const script = document.createElement('script');
                script.src = CHART_JS_SRC;
                script.async = true;
                script.dataset.suxiChartjs = '1';
                script.onload = () => {
                    if (window.Chart) {
                        resolve(window.Chart);
                    } else {
                        reject(new Error('Chart.js加载后未暴露Chart对象'));
                    }
                };
                script.onerror = () => reject(new Error('Chart.js加载失败'));
                document.head.appendChild(script);
            }).catch((error) => {
                chartJsLoadPromise = null;
                console.warn(error.message || 'Chart.js加载失败');
                return null;
            });
        }
        return chartJsLoadPromise;
    };

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
    const normalizeLocale = (locale) => {
        const lang = String(locale || '').replace('_', '-').toLowerCase();
        if (lang.startsWith('en')) return 'en-US';
        return 'zh-CN';
    };
    const getInitialLocale = ({
        search = typeof window !== 'undefined' ? (window.location?.search || '') : '',
        storage = browserLocalStorage(),
        navigatorLanguage = typeof navigator !== 'undefined' ? navigator.language : '',
    } = {}) => {
        const params = new URLSearchParams(search || '');
        return normalizeLocale(
            params.get('lang') ||
            params.get('locale') ||
            params.get('think_lang') ||
            storage?.getItem?.('suxios_locale') ||
            navigatorLanguage ||
            'zh-CN'
        );
    };
    const createAiModelConfigText = (localeResolver) => (key, values = {}) => {
        const resolvedLocale = normalizeLocale(typeof localeResolver === 'function' ? localeResolver() : localeResolver);
        const defaultText = aiModelConfigI18n['zh-CN']?.[key] || key;
        let text = aiModelConfigI18n[resolvedLocale]?.[key] || defaultText;
        Object.entries(values || {}).forEach(([name, value]) => {
            text = text.replaceAll(`{${name}}`, String(value));
        });
        return text;
    };
    const languageOptions = [
        { value: 'zh-CN', label: '中文' },
        { value: 'en-US', label: 'English' },
    ];
    const menuItemDefinitions = [
        {
            name: '经营工作台',
            testid: 'nav-revenue-management',
            icon: 'fas fa-chart-line',
            requireSuper: false,
            permissions: [],
            children: [
                { name: '今日经营工作台', path: 'compass', icon: 'fas fa-tachometer-alt', requireSuper: false, permissions: [] },
                { name: '收益分析中心', path: 'revenue-research-center', icon: 'fas fa-chart-line', testid: 'nav-revenue-research-center', permissions: [] },
                {
                    name: '开店/扩张/投决',
                    path: 'lifecycle-auxiliary',
                    icon: 'fas fa-share-alt',
                    requireSuper: false,
                    permissions: [],
                    children: [
                        { name: '辅助总览', path: 'lifecycle', icon: 'fas fa-route' },
                        { name: 'P4·投决辅助', path: 'investment-decision', icon: 'fas fa-balance-scale' },
                        { name: '筹建·战略推演', path: 'ai-strategy', icon: 'fas fa-chess' },
                        { name: '筹建·量化模拟', path: 'ai-simulation', icon: 'fas fa-calculator' },
                        { name: '筹建·可行性报告', path: 'ai-feasibility', icon: 'fas fa-file-contract' },
                        { name: '开业准备总览', path: 'opening-overview', icon: 'fas fa-clipboard-check' },
                        { name: '开业检查清单', path: 'opening-checklist', icon: 'fas fa-tasks' },
                        { name: '扩张·市场评估', path: 'market-evaluation', icon: 'fas fa-chart-area' },
                        { name: '扩张·标杆选模', path: 'benchmark-model', icon: 'fas fa-star' },
                        { name: '扩张·协同提效', path: 'collaboration-efficiency', icon: 'fas fa-link' },
                        { name: '转让·资产定价', path: 'asset-pricing', icon: 'fas fa-calculator' },
                        { name: '转让·时机推演', path: 'timing-strategy', icon: 'fas fa-chess-knight' },
                        { name: '转让·数据看板', path: 'decision-board', icon: 'fas fa-chart-pie' },
                        { name: '图片优化助手', path: 'hotel-image-optimizer', icon: 'fas fa-image', requireManager: true, permissions: [] },
                        { name: '酒店AI工具箱', path: 'agent-center', icon: 'fas fa-toolbox', requireSuper: true, permissions: [] },
                    ],
                },
            ],
        },
        {
            name: '线上数据',
            icon: 'fas fa-cloud-download-alt',
            requireSuper: false,
            permissions: [],
            children: [
                { name: '数据中心', path: 'online-data', tab: 'data-health', icon: 'fas fa-heartbeat', requireSuper: false, permissions: ['can_view_online_data'] },
                { name: '携程ebooking', path: 'ctrip-ebooking', icon: 'fas fa-plane', requireSuper: false, permissions: ['can_view_online_data'] },
                { name: '美团ebooking', path: 'meituan-ebooking', icon: 'fas fa-store', requireSuper: false, permissions: ['can_view_online_data'] },
                { name: '平台数据自动获取', path: 'online-data', tab: 'platform-auto', icon: 'fas fa-robot', testid: 'nav-platform-auto-fetch', requireSuper: false, permissions: ['can_view_online_data'] },
            ],
        },
        {
            name: '运营执行',
            testid: 'nav-operation-execution',
            icon: 'fas fa-tasks',
            requireSuper: false,
            permissions: [],
            children: [
                { name: '经营数据总览', path: 'ops-source', icon: 'fas fa-search' },
                { name: '问题根因分析', path: 'ops-analysis', icon: 'fas fa-microscope' },
                { name: '风险预警', path: 'ops-insight', icon: 'fas fa-bell' },
                { name: 'AI经营日报', path: 'ai-daily-report', icon: 'fas fa-file-alt' },
                { name: '策略模拟', path: 'ops-plan', icon: 'fas fa-lightbulb' },
                { name: '执行跟踪', path: 'ops-track', icon: 'fas fa-play-circle' },
            ],
        },
        {
            name: '系统设置',
            icon: 'fas fa-cog',
            requireSuper: false,
            permissions: [],
            children: [
                { name: '门店管理', path: 'hotels', icon: 'fas fa-hotel', configKey: 'menu_hotel_name', requireSuper: false, permissions: ['can_manage_own_hotels'] },
                { name: '智能知识中枢', path: 'knowledge-center', icon: 'fas fa-brain', requireManager: true, permissions: [] },
                {
                    name: '团队管理',
                    icon: 'fas fa-users',
                    requireSuper: true,
                    permissions: [],
                    children: [
                        { name: '员工管理', path: 'users', icon: 'fas fa-user-friends' },
                        { name: '角色权限', path: 'roles', icon: 'fas fa-user-shield', requireSuper: true },
                        { name: '操作日志', path: 'operation-logs', icon: 'fas fa-history', requireSuper: true },
                    ],
                },
                { name: '系统配置', path: 'system-config', icon: 'fas fa-sliders-h', requireSuper: true, permissions: [] },
                { name: 'AI模型配置', path: 'ai-model-config', icon: 'fas fa-robot', i18nKey: 'aiModelConfig.pageTitle', requireSuper: true, permissions: [] },
                { name: 'AI决策追溯', path: 'ai-governance', icon: 'fas fa-shield-alt', requireSuper: true, permissions: [] },
                { name: '数据配置', path: 'data-config', icon: 'fas fa-database', requireSuper: true, permissions: [] },
            ],
        },
    ];
    const testIdNameMap = {
        '项目AI管理': 'project-ai-management',
        '首页': 'compass',
        '收益管理': 'revenue-management',
        '收益管理智能体总览': 'revenue-ai-overview',
        '线上数据': 'online-data',
        '运营执行': 'operation-execution',
        '全生命周期服务': 'lifecycle',
        '全生命周期辅助': 'lifecycle',
        'P4·投决辅助': 'investment-decision',
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
    const rememberedUsernameStorageKey = 'remembered_username';
    const legacyRememberedPasswordStorageKey = 'remembered_password';
    const createLoginForm = ({ username = '' } = {}) => ({
        username: String(username || ''),
        password: '',
    });
    const getRememberedLoginAccount = (storage) => {
        const username = String(storage?.getItem?.(rememberedUsernameStorageKey) || '');
        storage?.removeItem?.(legacyRememberedPasswordStorageKey);
        return {
            username,
            remember: !!username,
            form: createLoginForm({ username }),
        };
    };
    const authUserCacheKey = 'suxios_auth_user_cache_v1';
    const authUserCacheMaxAgeMs = 12 * 60 * 60 * 1000;
    const browserLocalStorage = () => (typeof localStorage !== 'undefined' ? localStorage : null);
    const normalizePermissionMap = (permissions = null) => {
        if (Array.isArray(permissions)) {
            return permissions.reduce((acc, key) => {
                if (key) acc[String(key)] = true;
                return acc;
            }, {});
        }
        return permissions && typeof permissions === 'object'
            ? { ...permissions }
            : {};
    };
    const sanitizeCachedAuthUser = (profile = null) => {
        if (!profile || typeof profile !== 'object') return null;
        const permissions = normalizePermissionMap(profile.permissions);
        const capabilities = Array.isArray(profile.capabilities)
            ? profile.capabilities.filter(Boolean).map(item => String(item))
            : [];
        const permitted = Array.isArray(profile.permitted_hotels)
            ? profile.permitted_hotels
                .filter(item => item && item.id)
                .map(item => ({
                    id: item.id,
                    tenant_id: item.tenant_id ?? null,
                    name: item.name || '',
                    code: item.code || '',
                    status: item.status ?? 1,
                }))
            : [];
        return {
            id: profile.id ?? null,
            username: profile.username || '',
            realname: profile.realname || '',
            role_id: profile.role_id ?? null,
            role_name: profile.role_name || '',
            hotel_id: profile.hotel_id ?? null,
            is_super_admin: profile.is_super_admin === true,
            is_hotel_manager: profile.is_hotel_manager === true,
            permissions,
            capabilities,
            hotel_scope: profile.hotel_scope && typeof profile.hotel_scope === 'object' ? { ...profile.hotel_scope } : null,
            modules: profile.modules && typeof profile.modules === 'object' ? { ...profile.modules } : {},
            permitted_hotels: permitted,
        };
    };
    const loadCachedAuthUser = (storage = browserLocalStorage(), now = Date.now()) => {
        try {
            const payload = JSON.parse(storage?.getItem?.(authUserCacheKey) || 'null');
            if (!payload || typeof payload !== 'object') return null;
            if (now - Number(payload.saved_at || 0) > authUserCacheMaxAgeMs) return null;
            return sanitizeCachedAuthUser(payload.user);
        } catch (e) {
            return null;
        }
    };
    const saveCachedAuthUser = (profile, storage = browserLocalStorage(), now = Date.now()) => {
        const cachedUser = sanitizeCachedAuthUser(profile);
        if (!cachedUser) return;
        try {
            storage?.setItem?.(authUserCacheKey, JSON.stringify({ saved_at: now, user: cachedUser }));
        } catch (e) {
            // Auth cache only improves first paint and should not block login.
        }
    };
    const clearCachedAuthUser = (storage = browserLocalStorage()) => {
        try {
            storage?.removeItem?.(authUserCacheKey);
        } catch (e) {
            // Ignore localStorage cleanup failures.
        }
    };
    const buildClientPagination = (rows, page, pageSize) => {
        const list = Array.isArray(rows) ? rows : [];
        const size = Math.max(1, Number(pageSize) || 50);
        const total = list.length;
        const totalPages = Math.max(1, Math.ceil(total / size));
        const safePage = Math.min(Math.max(1, Number(page) || 1), totalPages);
        const startIndex = (safePage - 1) * size;
        return {
            rows: list.slice(startIndex, startIndex + size),
            total,
            page: safePage,
            pageSize: size,
            totalPages,
            startIndex,
            start: total ? startIndex + 1 : 0,
            end: Math.min(total, startIndex + size),
        };
    };
    const toNumber = (value, fallback = 0) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : fallback;
    };
    const toFixedSafe = (value, digits = 0, fallback = '-') => {
        const num = toNumber(value, NaN);
        if (!Number.isFinite(num)) return fallback;
        return num.toFixed(digits);
    };
    const safeDivide = (num, denom, fallback = 0) => {
        const n = toNumber(num, NaN);
        const d = toNumber(denom, NaN);
        if (!Number.isFinite(n) || !Number.isFinite(d) || d === 0) return fallback;
        return n / d;
    };
    const formatNumber = (num) => {
        if (num === null || num === undefined || num === '') return '-';
        const value = toNumber(num, NaN);
        return Number.isFinite(value) ? value.toLocaleString() : '-';
    };
    const formatDate = (date) => {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };
    const formatConfigDate = (value) => {
        if (!value) return '';
        return String(value).slice(0, 10);
    };
    const formatKnowledgeJson = (value) => {
        try {
            return JSON.stringify(value || {}, null, 2);
        } catch (error) {
            return String(value || '');
        }
    };
    const formatCommentTime = (timestamp) => {
        if (!timestamp) return '';
        if (typeof timestamp === 'number') {
            return new Date(timestamp).toLocaleString('zh-CN');
        }
        return timestamp;
    };
    const aiRound = (value, digits = 0) => Number((Number(value) || 0).toFixed(digits));
    const formatPaybackMonth = (value) => value === null || value === undefined ? '不可回收' : `${aiRound(value, 1)}个月`;
    const formatCurrency = (value) => `¥${Math.round(toNumber(value)).toLocaleString()}`;
    const formatMoney = formatCurrency;
    const formatPercent = (value) => `${aiRound(toNumber(value) * 100, 1)}%`;
    const formatWan = (value) => value === null || value === undefined ? '--' : `${aiRound(toNumber(value), 2)}万元`;
    const calculateHhi = (items, valueGetter) => {
        const values = (items || []).map(item => Math.max(0, toNumber(valueGetter(item), 0)));
        const total = values.reduce((sum, value) => sum + value, 0);
        if (total <= 0) return 0;
        return values.reduce((sum, value) => {
            const share = value / total;
            return sum + share * share;
        }, 0) * 10000;
    };
    const getConcentrationLevel = (hhi, lowThreshold, mediumThreshold, highLevel) => {
        if (hhi <= 0) {
            return {
                value: '0.00',
                level: '',
                textClass: 'text-gray-600',
                style: { backgroundColor: '#ffffff', border: '1px solid #e5e7eb' },
            };
        }
        if (hhi < lowThreshold) {
            return {
                value: toFixedSafe(hhi, 2),
                level: '低度内卷',
                textClass: 'text-yellow-600',
                style: { backgroundColor: '#fefce8', border: '1px solid #fde047' },
            };
        }
        if (hhi <= mediumThreshold) {
            return {
                value: toFixedSafe(hhi, 2),
                level: '中度内卷',
                textClass: 'text-orange-600',
                style: { backgroundColor: '#fff7ed', border: '1px solid #fdba74' },
            };
        }
        return {
            value: toFixedSafe(hhi, 2),
            level: highLevel,
            textClass: 'text-red-600',
            style: { backgroundColor: '#fef2f2', border: '1px solid #fca5a5' },
        };
    };
    const revenueConcentration = (items, valueGetter) => getConcentrationLevel(calculateHhi(items, valueGetter), 500, 800, '寡头市场');
    const visitConcentration = (items, valueGetter) => getConcentrationLevel(calculateHhi(items, valueGetter), 400, 700, '高度内卷');
    const isExpansionStaticPage = (page) => [
        'ai-strategy',
        'ai-feasibility',
        'market-evaluation',
        'market-eval',
        'benchmark-model',
        'collaboration-efficiency',
        'sync-efficiency',
    ].includes(page);
    const isSimulationStaticPage = (page) => [
        'ai-strategy',
        'ai-feasibility',
        'ai-simulation',
        'benchmark-model',
        'collaboration-efficiency',
        'sync-efficiency',
        'asset-pricing',
        'timing-strategy',
        'decision-board',
    ].includes(page);
    const deferUiTask = (callback, delay = 0) => {
        const runner = () => {
            try {
                const result = callback();
                if (result && typeof result.catch === 'function') {
                    result.catch(error => console.warn('deferred task failed:', error));
                }
            } catch (error) {
                console.warn('deferred task failed:', error);
            }
        };
        if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(runner, { timeout: Math.max(80, delay || 80) });
            return;
        }
        setTimeout(runner, delay);
    };
    const scheduleDelayedPageTask = (callback, delay = 0) => {
        setTimeout(() => {
            try {
                const result = callback();
                if (result && typeof result.catch === 'function') {
                    result.catch(error => console.warn('deferred task failed:', error));
                }
            } catch (error) {
                console.warn('deferred task failed:', error);
            }
        }, Math.max(0, Number(delay) || 0));
    };
    const deferFrameTask = (callback, delay = 0) => {
        if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(() => deferUiTask(callback, delay));
            return;
        }
        deferUiTask(callback, delay);
    };
    const sidebarPreferenceKey = 'suxios_sidebar_collapsed';
    const isCompactViewport = () => typeof window !== 'undefined' && window.matchMedia('(max-width: 640px)').matches;
    const loadSidebarCollapsedPreference = (storage = browserLocalStorage()) => {
        if (isCompactViewport()) return true;
        try {
            const saved = storage?.getItem?.(sidebarPreferenceKey);
            if (saved === 'expanded') return false;
            if (saved === 'collapsed') return true;
        } catch (e) {
            // Ignore storage failures and keep the layout compact.
        }
        return false;
    };
    const persistSidebarCollapsedPreference = (collapsed, storage = browserLocalStorage()) => {
        if (isCompactViewport()) return;
        try {
            storage?.setItem?.(sidebarPreferenceKey, collapsed ? 'collapsed' : 'expanded');
        } catch (e) {
            // Layout state should not block the app.
        }
    };
    const buildLoginRequestPayload = (form = {}) => ({
        username: String(form.username || ''),
        password: String(form.password || ''),
    });
    const validateLoginRequestPayload = (payload = {}) => (
        payload.username && payload.password ? '' : '请输入用户名和密码'
    );
    const applyRememberedLoginAccount = ({ storage, username = '', remember = false } = {}) => {
        if (remember) {
            storage?.setItem?.(rememberedUsernameStorageKey, String(username || ''));
            storage?.removeItem?.(legacyRememberedPasswordStorageKey);
            return;
        }
        storage?.removeItem?.(rememberedUsernameStorageKey);
        storage?.removeItem?.(legacyRememberedPasswordStorageKey);
    };
    const createRegisterForm = () => ({
        username: '',
        realname: '',
        password: '',
        confirm_password: '',
    });
    const buildRegisterRequestPayload = (form = {}) => ({
        username: String(form.username || '').trim(),
        realname: String(form.realname || '').trim(),
        password: String(form.password || ''),
        confirm_password: String(form.confirm_password || ''),
    });
    const validateRegisterRequestPayload = (payload = {}) => {
        if (!payload.username || !payload.password || !payload.confirm_password) {
            return '请填写用户名、密码和确认密码';
        }
        if (payload.password !== payload.confirm_password) {
            return '两次输入的密码不一致';
        }
        return '';
    };
    const createHotelForm = ({ hotel = null, operatorName = '', code = '', parsedDescription = {} } = {}) => {
        if (hotel) {
            return {
                id: hotel.id,
                name: hotel.name || '',
                code: hotel.code || '',
                address: hotel.address || '',
                contact_person: hotel.contact_person || operatorName,
                contact_phone: hotel.contact_phone || '',
                status: hotel.status ?? 1,
                description: parsedDescription.description || '',
            };
        }
        return {
            id: null,
            name: '',
            code,
            address: '',
            contact_person: operatorName,
            contact_phone: '',
            status: 1,
            description: '',
        };
    };
    const buildHotelSavePayload = ({ form = {}, normalizedCode = '', operatorName = '', description = '' } = {}) => ({
        name: String(form.name || '').trim(),
        code: normalizedCode,
        address: String(form.address || '').trim(),
        contact_person: String(form.contact_person || '').trim() || operatorName,
        contact_phone: String(form.contact_phone || '').trim(),
        status: parseInt(form.status),
        description,
    });
    const createHotelMergeForm = () => ({
        source_hotel_id: '',
        target_hotel_id: '',
        deactivate_source: false,
        confirmation_text: '',
    });
    const hotelMergeVisibleItems = (preview = null) => {
        const items = Array.isArray(preview?.items) ? preview.items : [];
        return items.filter(item => Number(item?.source_count || 0) > 0 || Number(item?.target_count || 0) > 0 || Number(item?.conflict_count || 0) > 0);
    };
    const hotelMergeSkippableConflictCount = (preview = null) => {
        const items = Array.isArray(preview?.items) ? preview.items : [];
        return items.reduce((total, item) => total + Number(item?.skippable_conflict_count || 0), 0);
    };
    const hotelMergeCanExecute = ({ preview = null, form = {} } = {}) => {
        const expected = String(preview?.confirmation_text || '').trim();
        const actual = String(form.confirmation_text || '').trim();
        const sameSource = String(preview?.source_hotel?.id || '') === String(form.source_hotel_id || '');
        const sameTarget = String(preview?.target_hotel?.id || '') === String(form.target_hotel_id || '');
        return preview?.can_execute === true && sameSource && sameTarget && expected !== '' && actual === expected;
    };
    const buildHotelMergeExecutePayload = (form = {}) => ({
        source_hotel_id: Number(form.source_hotel_id),
        target_hotel_id: Number(form.target_hotel_id),
        deactivate_source: form.deactivate_source === true,
        confirmation_text: String(form.confirmation_text || '').trim(),
    });
    const hotelMergeSuccessMessage = (data = {}) => {
        const updatedTotal = Number(data?.updated_total || 0);
        const mergedTotal = Number(data?.merged_conflict_total || 0);
        return `门店数据迁移完成：迁移 ${updatedTotal} 条，合并重复授权 ${mergedTotal} 条`;
    };
    const buildHotelOtaCtripConfigSavePayload = ({ hotelIdText = '', ctrip = {}, existing = null, fallbackName = '', defaultUrl = '' } = {}) => ({
        id: ctrip.id || existing?.id || null,
        name: ctrip.name || existing?.name || fallbackName,
        hotel_id: hotelIdText,
        ctrip_hotel_id: ctrip.ctrip_hotel_id || existing?.ctrip_hotel_id || existing?.ctripHotelId || existing?.ota_hotel_id || '',
        cookies: ctrip.cookies,
        url: ctrip.url || existing?.url || defaultUrl,
        node_id: ctrip.node_id || existing?.node_id || '24588',
    });
    const buildHotelOtaMeituanConfigSavePayload = ({ hotelIdText = '', meituan = {}, existing = null, fallbackName = '' } = {}) => ({
        id: meituan.id || existing?.id || null,
        name: meituan.name || existing?.name || fallbackName,
        hotel_id: hotelIdText,
        partner_id: meituan.partner_id,
        poi_id: meituan.poi_id,
        cookies: meituan.cookies,
        hotel_room_count: meituan.hotel_room_count || existing?.hotel_room_count || '',
        competitor_room_count: meituan.competitor_room_count || existing?.competitor_room_count || '',
    });
    const getHotelCodeNumber = (code) => {
        const match = String(code || '').match(/(\d+)$/);
        return match ? parseInt(match[1], 10) : 0;
    };
    const formatHotelCode = (num) => String(Math.max(num, 1)).padStart(4, '0');
    const normalizeOtaConfigHotelName = (value = '') => String(value || '')
        .trim()
        .replace(/\s+/g, '')
        .replace(/(?:\u7f8e\u56e2|\u643a\u7a0b)?\u6570\u636e\u6e90$/u, '');
    const secretPreview = (item = {}) => {
        if (item.cookies_preview) return item.cookies_preview;
        return item.has_cookies ? '\u5df2\u4fdd\u5b58' : '-';
    };
    const meituanConfigHasProfileCookieSource = (config) => (
        !!(config?.has_profile_cookie_source || config?.profile_cookie_source || String(config?.cookie_source || '').trim() === 'browser_profile')
    );
    const meituanConfigHasCookies = (config) => (
        !!(String(config?.cookies || '').trim() || config?.has_cookies || meituanConfigHasProfileCookieSource(config))
    );
    const meituanConfigMissingFields = (config) => {
        const backendMissing = config?.credential_requirement?.missing_fields || config?.missing_fields;
        if (Array.isArray(backendMissing)) {
            return backendMissing.filter(Boolean);
        }
        const missing = [];
        if (!String(config?.partner_id || config?.partnerId || '').trim()) missing.push('\u5e73\u53f0\u63a5\u53e3\u6807\u8bc6');
        if (!String(config?.poi_id || config?.poiId || '').trim()) missing.push('\u5e73\u53f0\u95e8\u5e97\u6807\u8bc6');
        if (!meituanConfigHasCookies(config)) missing.push('\u5e73\u53f0\u6388\u6743');
        return missing;
    };
    const meituanConfigMissingText = (config) => meituanConfigMissingFields(config).join(' / ');
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
    const buildKnowledgeImportRequestBody = ({ form = {}, raw = '', tags = [] } = {}) => {
        const mode = form.mode || 'document';
        return {
            mode,
            source: form.source || mode,
            hotel_id: Number(form.hotel_id),
            model_key: form.model_key || 'deepseek_chat',
            tags: Array.isArray(tags) ? tags : [],
            raw,
        };
    };
    const knowledgeImportSuccessMessage = (data = {}) => {
        const successCount = Number(data?.success_count || 0);
        const errorCount = Number(data?.error_count || 0);
        return errorCount > 0
            ? `导入完成：成功 ${successCount} 条，失败 ${errorCount} 条，请查看异常状态`
            : `导入完成：成功 ${successCount} 条`;
    };
    const knowledgeImportErrorMessage = (error) => {
        if (error?.name === 'AbortError') {
            return 'AI读取超过90秒，已停止等待；请刷新列表确认是否已生成，避免重复提交';
        }
        return error?.message || '导入失败';
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
            requiredText: '接口地址 / 节点 / Cookie/API 辅助',
            outputText: '房价、销量、订单、排名',
        },
        'meituan-ebooking': {
            description: '用于美团竞对排名抓取，核心参数为平台接口标识、门店标识、榜单类型、Cookie/API 辅助和日期范围。',
            requiredText: '接口标识 / 门店标识 / Cookie/API 辅助',
            outputText: '入住、销售、转化、流量榜',
        },
        'ctrip-traffic': {
            description: '用于携程流量分析抓取，支持携程/去哪儿平台、日期范围和额外 JSON 参数。',
            requiredText: '接口地址 / Cookie/API 辅助 / 平台',
            outputText: '访问量、转化率、趋势',
        },
        'ctrip-cookie-api': {
            description: '用于携程 Cookie API 临时诊断，支持接口清单、单个 Request URL、Cookie 或已验证 Profile。',
            requiredText: 'Request URL 清单 / Cookie 或 Profile',
            outputText: '经营、流量、广告、商旅、质量等诊断行',
        },
        'meituan-traffic': {
            description: '用于美团流量接口抓取，支持接口地址、平台接口标识、门店标识、Cookie/API 辅助、日期范围和额外参数。',
            requiredText: '接口地址 / 接口标识 / 门店标识 / Cookie/API 辅助',
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
            requiredText: '接口标识 / 门店标识 / Cookie/API 辅助',
            outputText: '暂缓 / 手动启用',
        },
        'ctrip-ads': {
            description: '用于携程金字塔广告投放数据直接获取，核心参数为广告接口 URL、Cookie、日期和可选 Payload。',
            requiredText: '接口URL / Cookie / 日期',
            outputText: '曝光、点击、成交、费用',
        },
        'meituan-ads': {
            description: '用于美团推广通广告接口抓取，支持广告接口地址、Cookie/API 辅助、店铺或门店标识、日期范围和请求参数。',
            requiredText: '接口地址 / Cookie/API 辅助 / 店铺或门店标识',
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

        const hasPermission = (permissions, key) => {
            if (Array.isArray(permissions)) return permissions.includes(key);
            if (permissions && typeof permissions === 'object') return !!permissions[key];
            return false;
        };
        const isItemVisible = (item) => {
            if (item.requireSuper) return false;
            if (item.requireManager && currentUser.role_id !== 2 && !currentUser.is_hotel_manager) return false;
            if (item.permissions && item.permissions.length > 0) {
                const perms = currentUser.permissions || {};
                return item.permissions.some(p => hasPermission(perms, p));
            }
            return true;
        };
        const filterTree = (list = []) => (Array.isArray(list) ? list : [])
            .map((item) => {
                if (!isItemVisible(item)) {
                    return null;
                }
                const visibleChildren = filterTree(item.children || []);
                if (visibleChildren.length) {
                    return { ...item, children: visibleChildren };
                }
                if (!item.children && isItemVisible(item)) {
                    return { ...item };
                }
                return null;
            })
            .filter(Boolean);

        return filterTree(items);
    };
    const firstNonEmptyText = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && String(item).trim() !== '');
        return value === undefined ? '' : String(value).trim();
    };
    const formatHotelBindingDate = (value) => {
        const text = firstNonEmptyText(value);
        if (!text) return '-';
        return text.replace('T', ' ').slice(0, 16);
    };
    const hotelPlatformCardClass = (platform) => {
        if (platform === 'ctrip') return 'bg-blue-50/60 border-blue-100';
        if (platform === 'meituan') return 'bg-orange-50/60 border-orange-100';
        return 'bg-gray-50 border-gray-100';
    };
    const platformAccountStatusClass = (statusCode) => ({
        unbound: 'bg-gray-50 text-gray-500 border-gray-200',
        waiting_login: 'bg-amber-50 text-amber-700 border-amber-200',
        logged_in: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        login_expired: 'bg-red-50 text-red-700 border-red-200',
        missing_config: 'bg-amber-50 text-amber-700 border-amber-200',
        mismatch: 'bg-red-50 text-red-700 border-red-200',
    }[statusCode] || 'bg-gray-50 text-gray-500 border-gray-200');
    const platformAccountStatusText = (statusCode) => ({
        unbound: '\u672a\u7ed1\u5b9a',
        waiting_login: '\u5f85\u767b\u5f55',
        logged_in: '\u5df2\u767b\u5f55',
        login_expired: '\u767b\u5f55\u5931\u6548',
        missing_config: '\u914d\u7f6e\u7f3a\u9879',
        mismatch: '\u5e73\u53f0\u95e8\u5e97\u4e0d\u5339\u914d',
    }[statusCode] || '\u672a\u7ed1\u5b9a');
    const platformCaptureStatusClass = (statusCode) => ({
        success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        partial_success: 'bg-amber-50 text-amber-700 border-amber-200',
        failed: 'bg-red-50 text-red-700 border-red-200',
        running: 'bg-blue-50 text-blue-700 border-blue-200',
        none: 'bg-gray-50 text-gray-500 border-gray-200',
        unknown: 'bg-gray-50 text-gray-500 border-gray-200',
    }[statusCode] || 'bg-gray-50 text-gray-500 border-gray-200');
    const platformCaptureStatusText = (statusCode) => ({
        success: '\u6700\u8fd1\u91c7\u96c6\u6210\u529f',
        partial_success: '\u90e8\u5206\u6210\u529f',
        failed: '\u6700\u8fd1\u91c7\u96c6\u5931\u8d25',
        running: '\u91c7\u96c6\u4e2d',
        none: '\u672a\u91c7\u96c6',
        unknown: '\u72b6\u6001\u672a\u77e5',
    }[statusCode] || '\u72b6\u6001\u672a\u77e5');
    const platformLastSuccessText = (source = {}, config = {}, captureCode = '', lastCaptureText = '-') => {
        const successTime = firstNonEmptyText(
            source?.last_success_time,
            source?.last_success_at,
            source?.last_success_sync_time,
            source?.last_successful_sync_time,
            source?.last_stored_at,
            config?.last_success_time,
            config?.last_success_at,
            config?.last_success_sync_time,
            config?.last_successful_sync_time,
            config?.last_stored_at
        );
        if (successTime) return formatHotelBindingDate(successTime);
        if (captureCode === 'success') return lastCaptureText || '-';
        return '-';
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
        const effectiveReady = !missingConfig && (ready || sourceReady);
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
                    config?.profile_id,
                    config?.profileId,
                    config?.browser_profile_id,
                    config?.browserProfileId,
                    `system_${hotel?.id || ''}`
                ),
                hotel_id: firstNonEmptyText(sourceConfig.hotel_id, sourceConfig.ctrip_hotel_id, config?.ota_hotel_id, config?.ctrip_hotel_id, config?.ctripHotelId),
                ctrip_hotel_id: firstNonEmptyText(sourceConfig.ctrip_hotel_id, sourceConfig.hotel_id, config?.ota_hotel_id, config?.ctrip_hotel_id, config?.ctripHotelId),
                system_hotel_id: firstNonEmptyText(sourceConfig.system_hotel_id, config?.system_hotel_id, hotel?.id),
                hotel_name: firstNonEmptyText(sourceConfig.hotel_name, config?.hotel_name, hotel?.name),
            }
            : {
                store_id: firstNonEmptyText(sourceConfig.store_id, sourceConfig.poi_id, config?.store_id, config?.poi_id, config?.poiId),
                poi_id: firstNonEmptyText(sourceConfig.poi_id, sourceConfig.store_id, config?.poi_id, config?.poiId),
                poi_name: firstNonEmptyText(sourceConfig.poi_name, sourceConfig.store_name, config?.poi_name, config?.store_name, hotel?.name),
                partner_id: firstNonEmptyText(sourceConfig.partner_id, config?.partner_id, config?.partnerId),
                system_hotel_id: firstNonEmptyText(sourceConfig.system_hotel_id, config?.system_hotel_id, hotel?.id),
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
                data_source_id: profileSource?.id || source?.id || undefined,
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

    const buildHotelPlatformBindingRows = ({
        hotel = {},
        ctripConfig = null,
        meituanConfig = null,
        ctripProfile = null,
        meituanProfile = null,
        ctripSource = null,
        meituanSource = null,
        meituanMissingFields = [],
        helpers = {},
    } = {}) => {
        const meituanProfileConfig = meituanProfile?.config || {};
        const ctripHasCookie = !!(ctripConfig && (String(ctripConfig.cookies || '').trim() || ctripConfig.has_cookies));
        const ctripReady = !!(ctripProfile || ctripHasCookie);
        const meituanPartnerId = firstNonEmptyText(meituanProfileConfig.partner_id, meituanConfig?.partner_id, meituanConfig?.partnerId);
        const meituanPoiId = firstNonEmptyText(meituanProfileConfig.poi_id, meituanProfileConfig.store_id, meituanConfig?.poi_id, meituanConfig?.poiId);
        const meituanMissing = Array.isArray(meituanMissingFields) ? meituanMissingFields : [];
        const meituanIdentifierMissing = [
            meituanPartnerId ? '' : '平台接口标识',
            meituanPoiId ? '' : '平台门店标识',
        ].filter(Boolean);
        const meituanStoreInfoMissing = meituanIdentifierMissing.length > 0 || meituanMissing.some(field => !/cookie/i.test(String(field || '')));
        const meituanReady = !!((meituanProfile && meituanIdentifierMissing.length === 0) || (meituanConfig && meituanMissing.length === 0));
        const meituanPartial = !!(meituanProfile || meituanConfig);

        return [
            buildHotelPlatformAccountRow({
                hotel,
                platform: 'ctrip',
                label: '携程',
                icon: 'fas fa-plane-departure',
                iconClass: 'bg-white text-blue-600 border border-blue-100',
                profileSource: ctripProfile,
                source: ctripProfile || ctripSource || {},
                config: ctripConfig,
                ready: ctripReady,
                partial: !!ctripConfig,
                missingConfig: false,
                modules: ['经营日报', '流量', '竞对'],
                helpers,
            }),
            buildHotelPlatformAccountRow({
                hotel,
                platform: 'meituan',
                label: '美团',
                icon: 'fas fa-store',
                iconClass: 'bg-white text-orange-600 border border-orange-100',
                profileSource: meituanProfile,
                source: meituanProfile || meituanSource || {},
                config: meituanConfig,
                ready: meituanReady,
                partial: meituanPartial,
                missingConfig: meituanPartial && !meituanReady && meituanStoreInfoMissing,
                modules: ['榜单', '流量', '广告'],
                helpers,
            }),
        ];
    };

    const riskBadgeClass = (risk) => {
        if (['高风险', 'D', 'E'].includes(risk)) return 'bg-red-50 text-red-700 border-red-200';
        if (['中高风险', 'C'].includes(risk)) return 'bg-orange-50 text-orange-700 border-orange-200';
        if (['中风险', 'B'].includes(risk)) return 'bg-yellow-50 text-yellow-700 border-yellow-200';
        return 'bg-green-50 text-green-700 border-green-200';
    };

    const transferRiskTextClass = (risk) => {
        if (risk === '高风险') return 'text-red-600';
        if (risk === '中风险') return 'text-amber-600';
        return 'text-green-600';
    };

    const transferDecisionClass = (decision) => {
        if (decision === '适合转让') return 'text-green-600';
        if (decision === '谨慎转让') return 'text-amber-600';
        return 'text-red-600';
    };

    const pricingReadinessBadgeClass = (stage) => {
        if (stage === 'pricing_ready' || stage === 'evidence_ready') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (['pending_approval', 'approved_pending_execution', 'execution_intent_pending_approval', 'local_applied_pending_evidence'].includes(stage)) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (['data_recheck_required', 'rejected', 'blocked'].includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const priceSuggestionReviewReadinessClass = (stage) => {
        if (stage === 'effect_review_ready') return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (['effect_review_window_open', 'effect_review_sample_missing', 'effect_review_not_started'].includes(stage)) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (stage === 'effect_review_read_failed') return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const agentClosureReadinessBadgeClass = (stage) => {
        if (['service_closed', 'conversation_service_closed', 'saving_verified', 'maintenance_completed', 'knowledge_active_used'].includes(stage)) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (['conversation_observed', 'resolved_pending_review', 'completed_pending_saving', 'executed_pending_review'].includes(stage)) return 'bg-blue-50 text-blue-700 border-blue-200';
        if (['conversation_needs_work_order', 'low_confidence_review', 'pending_assignment', 'in_progress', 'pending_processing', 'pending_approval', 'approved_pending_start', 'implementing', 'maintenance_due', 'active_missing_schedule', 'active_pending_execution', 'knowledge_active_unused', 'knowledge_missing_content', 'knowledge_missing_keywords'].includes(stage) || String(stage || '').startsWith('work_order_linked_')) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (['escalated_blocked', 'rejected', 'overdue', 'cancelled'].includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    };

    const operationDataStatusText = (status) => status === 'ok' ? '已接入' : (status || '待接入真实数据');
    const operationProblemLevelLabel = (level) => ({
        high: '高风险',
        medium: '中风险',
        low: '低风险',
        data_insufficient: '数据不足',
    }[level] || '待判断');
    const operationAlertLevelLabel = (level) => ({ high: '高风险', medium: '中风险', low: '低风险' }[level] || '待判断');
    const operationAlertStatusLabel = (status) => ({ unread: '未读', read: '已读' }[status] || '未读');
    const operationAlertLevelClass = (level) => ({
        high: 'bg-red-50 text-red-600',
        medium: 'bg-amber-50 text-amber-600',
        low: 'bg-blue-50 text-blue-600',
    }[level] || 'bg-gray-100 text-gray-500');
    const operationRiskLevelLabel = (level) => ({
        low: '低风险',
        medium: '中风险',
        medium_high: '中高风险',
        high: '高风险',
    }[level] || '待判断');
    const operationActionStatusLabel = (status) => ({
        observing: '观察中',
        success: '有效',
        near_success: '接近有效',
        failed: '无效',
        active: '执行中',
        finished: '已结束',
        cancelled: '已取消',
    }[status] || '观察中');
    const operationEffectStatusLabel = (status) => ({
        ready: '已形成闭环',
        partial: '部分可验证',
        data_gap: '待补齐数据',
    }[status] || '待验证');
    const operationEffectStatusClass = (status) => ({
        ready: 'bg-green-50 text-green-700',
        partial: 'bg-amber-50 text-amber-700',
        data_gap: 'bg-gray-100 text-gray-500',
    }[status] || 'bg-gray-100 text-gray-500');
    const operationEffectMetricStatusLabel = (status) => status === 'ready' ? '可验证' : '数据不足';
    const operationExecutionStatusLabel = (status) => ({
        draft: '草稿',
        pending_approval: '待审批',
        approved: '已审批',
        rejected: '已驳回',
        blocked: '已阻塞',
        pending_create: '待生成任务',
        pending_execute: '待执行',
        executing: '执行中',
        executed: '已执行',
        failed: '执行失败',
        observing: '观察中',
        success: '有效',
        near_success: '接近有效',
    }[status] || status || '-');
    const operationExecutionStatusClass = (status) => ({
        approved: 'bg-green-50 text-green-700',
        executed: 'bg-green-50 text-green-700',
        success: 'bg-green-50 text-green-700',
        pending_approval: 'bg-amber-50 text-amber-700',
        pending_execute: 'bg-blue-50 text-blue-700',
        executing: 'bg-blue-50 text-blue-700',
        blocked: 'bg-amber-50 text-amber-700',
        rejected: 'bg-gray-100 text-gray-500',
        failed: 'bg-red-50 text-red-700',
    }[status] || 'bg-gray-100 text-gray-500');
    const operationExecutionNextActionClass = (action) => ({
        high: 'bg-red-50 text-red-700',
        medium: 'bg-amber-50 text-amber-700',
        low: 'bg-gray-100 text-gray-500',
    }[action?.priority] || 'bg-gray-100 text-gray-500');
    const operationClosureStatusClass = (status) => ({
        roi_ready: 'bg-emerald-50 text-emerald-700 border-emerald-100',
        reviewed_no_roi: 'bg-blue-50 text-blue-700 border-blue-100',
        evidence_ready: 'bg-indigo-50 text-indigo-700 border-indigo-100',
        executed_missing_evidence: 'bg-amber-50 text-amber-700 border-amber-100',
        approved_pending_execution: 'bg-amber-50 text-amber-700 border-amber-100',
        pending_approval: 'bg-slate-50 text-slate-700 border-slate-200',
        record_only: 'bg-orange-50 text-orange-700 border-orange-100',
        not_started: 'bg-gray-50 text-gray-500 border-gray-200',
        not_loaded: 'bg-red-50 text-red-700 border-red-100',
        blocked: 'bg-red-50 text-red-700 border-red-100',
        blocked_by_p0_ota_gate: 'bg-red-50 text-red-700 border-red-100',
        rejected: 'bg-gray-100 text-gray-500 border-gray-200',
    }[status] || 'bg-gray-50 text-gray-500 border-gray-200');
    const operationClosureScoreClass = (score) => {
        const value = Number(score || 0);
        if (value >= 90) return 'text-emerald-700';
        if (value >= 70) return 'text-blue-700';
        if (value >= 40) return 'text-amber-700';
        return 'text-gray-500';
    };
    const operationValue = (value, suffix = '') => {
        if (value === null || value === undefined || value === '') return '-';
        if (typeof value === 'number') return `${Number.isInteger(value) ? value.toLocaleString() : value.toFixed(2)}${suffix}`;
        return `${value}${suffix}`;
    };
    const operationMoney = (value) => value === null || value === undefined || value === '' ? '-' : `¥${Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
    const operationPercent = (value) => {
        if (value === null || value === undefined || value === '') return '-';
        const number = Number(value);
        if (!Number.isFinite(number)) return '-';
        return `${number <= 1 ? (number * 100).toFixed(0) : number.toFixed(2)}%`;
    };
    const operationMetricLabel = (key) => ({
        avg_orders: '日均订单',
        avg_revenue: '日均收入',
        avg_room_nights: '日均间夜',
        avg_conversion: '平均转化',
        orders_change: '订单变化',
        revenue_change: '收入变化',
        conversion_change: '转化变化',
        data_status: '数据状态',
        actual_days: '有效天数',
        days: '统计天数',
    }[key] || key);
    const operationMetricRows = (data) => Object.entries(data || {})
        .filter(([key]) => !['data_status'].includes(key))
        .map(([key, value]) => ({
            label: operationMetricLabel(key),
            value: key.includes('revenue') ? operationMoney(value) : (key.includes('conversion') ? operationValue(value, '%') : operationValue(value)),
        }));
    const operationActionDataText = (data) => {
        if (!data || data.data_status === '待接入真实数据') return '待接入真实数据';
        return `订单${operationValue(data.avg_orders)} / 收入${operationMoney(data.avg_revenue)}`;
    };
    const operationActionTarget = (action) => {
        const label = { orders: '订单', revenue: '收入', room_nights: '间夜', conversion: '转化率' }[action?.target_metric] || '目标';
        const rate = action?.target_change_rate ?? action?.result?.target_change_rate ?? '';
        return `${label}提升${rate || '-'}%`;
    };
    const operationEffectMetricValue = (metric) => {
        if (!metric || metric.value === null || metric.value === undefined) return '待验证';
        const value = Number(metric.value);
        if (!Number.isFinite(value)) return '待验证';
        return `${value.toFixed(2)}${metric.unit || ''}`;
    };

    return {
        aiModelConfigI18n,
        normalizeLocale,
        getInitialLocale,
        createAiModelConfigText,
        languageOptions,
        menuItemDefinitions,
        testIdNameMap,
        hotelColumns,
        userColumns,
        createLoginForm,
        getRememberedLoginAccount,
        normalizePermissionMap,
        sanitizeCachedAuthUser,
        loadCachedAuthUser,
        saveCachedAuthUser,
        clearCachedAuthUser,
        buildClientPagination,
        toNumber,
        toFixedSafe,
        safeDivide,
        formatNumber,
        formatDate,
        formatConfigDate,
        formatKnowledgeJson,
        formatCommentTime,
        aiRound,
        formatPaybackMonth,
        formatCurrency,
        formatMoney,
        formatPercent,
        formatWan,
        calculateHhi,
        revenueConcentration,
        visitConcentration,
        isExpansionStaticPage,
        isSimulationStaticPage,
        deferUiTask,
        scheduleDelayedPageTask,
        deferFrameTask,
        isCompactViewport,
        loadSidebarCollapsedPreference,
        persistSidebarCollapsedPreference,
        buildLoginRequestPayload,
        validateLoginRequestPayload,
        applyRememberedLoginAccount,
        createRegisterForm,
        buildRegisterRequestPayload,
        validateRegisterRequestPayload,
        createHotelForm,
        buildHotelSavePayload,
        createHotelMergeForm,
        hotelMergeVisibleItems,
        hotelMergeSkippableConflictCount,
        hotelMergeCanExecute,
        buildHotelMergeExecutePayload,
        hotelMergeSuccessMessage,
        buildHotelOtaCtripConfigSavePayload,
        buildHotelOtaMeituanConfigSavePayload,
        getHotelCodeNumber,
        formatHotelCode,
        normalizeOtaConfigHotelName,
        secretPreview,
        meituanConfigHasCookies,
        meituanConfigMissingFields,
        meituanConfigMissingText,
        formatHotelBindingDate,
        hotelPlatformCardClass,
        platformAccountStatusClass,
        platformAccountStatusText,
        platformCaptureStatusClass,
        platformCaptureStatusText,
        platformLastSuccessText,
        knowledgeCenterBaseSourceOptions,
        knowledgeImportModeMetaMap,
        buildKnowledgeImportRequestBody,
        knowledgeImportSuccessMessage,
        knowledgeImportErrorMessage,
        aiQuickSetupProviderModelMap,
        baseAiModelOptions,
        aiGovernanceTabs,
        dataConfigProfiles,
        getDefaultDataConfigForm,
        getDataConfigTypeDefaults,
        getSystemConfigDefaults,
        getPlatformAccountBindingGuideRows,
        buildHotelPlatformAccountRow,
        buildHotelPlatformBindingRows,
        knowledgeDocumentTextExtensions,
        knowledgeDocumentHtmlExtensions,
        knowledgeDocumentSupportedExtensions,
        agentTabs,
        resolveMenuItems,
        filterVisibleMenuItems,
        platformNextActionMeta,
        platformAccountStoreText,
        riskBadgeClass,
        transferRiskTextClass,
        transferDecisionClass,
        pricingReadinessBadgeClass,
        priceSuggestionReviewReadinessClass,
        agentClosureReadinessBadgeClass,
        operationDataStatusText,
        operationProblemLevelLabel,
        operationAlertLevelLabel,
        operationAlertStatusLabel,
        operationAlertLevelClass,
        operationRiskLevelLabel,
        operationActionStatusLabel,
        operationEffectStatusLabel,
        operationEffectStatusClass,
        operationEffectMetricStatusLabel,
        operationExecutionStatusLabel,
        operationExecutionStatusClass,
        operationExecutionNextActionClass,
        operationClosureStatusClass,
        operationClosureScoreClass,
        operationValue,
        operationMoney,
        operationPercent,
        operationMetricLabel,
        operationMetricRows,
        operationActionDataText,
        operationActionTarget,
        operationEffectMetricValue,
        loadChartJs,
    };
})();
