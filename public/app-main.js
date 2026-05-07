/* app-main.js - Auto-extracted from index.html */
/* Generated for performance optimization */
/* Contains: Vue app setup, components, all business logic */

const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;

    const API_BASE = '/api';

    // 复用组件定义
    const CompassCardHeader = {
        props: ['icon', 'iconColor', 'title'],
        template: `
            <div class="compass-card-header flex items-center justify-between">
                <h4 class="font-semibold text-gray-800 flex items-center">
                    <i :class="[icon, iconColor, 'mr-2']"></i>{{ title }}
                </h4>
                <slot></slot>
            </div>
        `
    };

    const MetricCard = {
        props: ['label', 'value'],
        template: `
            <div class="metric-card">
                <div class="text-xs text-gray-400 mb-1">{{ label }}</div>
                <div class="metric-value">{{ value }}</div>
            </div>
        `
    };

    const SearchInput = {
        props: ['modelValue', 'placeholder'],
        emits: ['update:modelValue'],
        template: `
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" 
                    :placeholder="placeholder" class="input-field pl-10 w-64">
            </div>
        `
    };

    const StatusFilter = {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: `
            <select :value="modelValue" @change="$emit('update:modelValue', $event.target.value)" class="input-field">
                <option value="">全部状态</option>
                <option value="1">启用</option>
                <option value="0">禁用</option>
            </select>
        `
    };

    const StatusBadge = {
        props: ['status', 'canToggle'],
        emits: ['toggle'],
        template: `
            <button v-if="canToggle" @click="$emit('toggle')" 
                :class="String(status) === '1' ? 'badge-success' : 'badge-danger'"
                class="badge cursor-pointer hover:shadow-md transition">
                {{ String(status) === '1' ? '启用' : '禁用' }}
            </button>
            <span v-else :class="String(status) === '1' ? 'badge-success' : 'badge-danger'" class="badge">
                {{ String(status) === '1' ? '正常' : '禁用' }}
            </span>
        `
    };

    const RoleBadge = {
        props: ['role'],
        template: `
            <span :class="{
                'badge-danger': role?.level === 1,
                'badge-warning': role?.level === 2,
                'badge-info': role?.level === 3
            }" class="badge">
                {{ role?.display_name || '未知' }}
            </span>
        `
    };

    const ActionButtons = {
        props: ['canEdit', 'canDelete'],
        emits: ['edit', 'delete'],
        template: `
            <div class="flex items-center gap-2">
                <button v-if="canEdit" @click="$emit('edit')" class="action-btn action-btn-edit" title="编辑">
                    <i class="fas fa-edit"></i>
                </button>
                <button v-if="canDelete" @click="$emit('delete')" class="action-btn action-btn-delete" title="删除">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `
    };

    const DataTable = {
        props: ['title', 'columns', 'data', 'canAdd', 'addText', 'emptyIcon', 'emptyText'],
        emits: ['add'],
        template: `
            <div class="card">
                <div class="p-5 border-b border-gray-100 flex flex-wrap justify-between items-center gap-4">
                    <div class="flex items-center gap-3">
                        <slot name="filters"></slot>
                    </div>
                    <button v-if="canAdd" @click="$emit('add')" class="btn-primary">
                        <i class="fas fa-plus mr-2"></i>{{ addText }}
                    </button>
                </div>
                <div class="table-container overflow-x-auto">
                    <table v-if="data && data.length > 0" class="w-full">
                        <thead>
                            <tr>
                                <th v-for="col in columns" :key="col.key" class="px-6 py-4 text-left">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in data" :key="item.id || item.name">
                                <slot name="row" :item="item"></slot>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="empty-state text-center">
                        <i :class="[emptyIcon || 'fas fa-inbox', 'block mx-auto']"></i>
                        <p>{{ emptyText || '暂无数据' }}</p>
                    </div>
                </div>
            </div>
        `
    };

    createApp({
        components: {
            CompassCardHeader,
            MetricCard,
            SearchInput,
            StatusFilter,
            StatusBadge,
            RoleBadge,
            ActionButtons,
            DataTable
        },
        setup() {
            // 状态
            const isLoggedIn = ref(false);
            const loading = ref(false);
            const user = ref(null);
            const token = ref(localStorage.getItem('token') || '');
            const currentTime = ref('');
            const currentPage = ref('hotels');
            const showPassword = ref(false);
            const sidebarCollapsed = ref(false); // 侧边栏折叠状态

            // 登录表单 - 从localStorage读取记住的用户名
            const savedUsername = localStorage.getItem('remembered_username') || '';
            const loginForm = ref({ username: savedUsername || 'admin', password: '' });
            const rememberUsername = ref(!!savedUsername);

            // 系统配置
            const systemConfig = ref({
                system_name: '宿析OS',
                logo_url: '',
                menu_hotel_name: '酒店管理',
                menu_users_name: '用户管理',
                menu_daily_report_name: '日报表管理',
                menu_monthly_task_name: '月任务管理',
                menu_report_config_name: '报表配置',
                wechat_mini_appid: '',
                wechat_mini_secret: '',
                complaint_mini_page: 'pages/complaint/index',
                complaint_mini_use_scene: '1',
            });

            // 线上数据获取
            const onlineDataTab = ref('ctrip-ranking');
            const downloadCenterTab = ref('overview'); // 下载中心子Tab: overview/traffic/ai/fetched
            const fetchingData = ref(false);
            const onlineDataResult = ref(null);
            const latestTrafficData = ref(null); // 本次获取的流量数据
            const topTenHotels = ref([]); // 前十名酒店数据
            const ctripHotelsList = ref([]); // 携程完整酒店列表
            const ctripTableTab = ref('sales'); // 携程数据表格Tab: sales/traffic/rank
            const showRawData = ref(false); // 是否展开原始数据
            const ctripForm = ref({
                url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                nodeId: '24588',
                startDate: '',
                endDate: '',
                cookies: '',
                auth_data: {}, // 认证数据
            });
            const ctripTrafficForm = ref({
                url: '',
                nodeId: '',
                startDate: '',
                endDate: '',
                cookies: '',
                extraParams: '',
            });
            const meituanForm = ref({
                url: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                hotelId: '',
                partnerId: '',
                poiId: '',
                rankType: 'P_RZ',
                rankTypes: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL_EXPOSE'],  // 默认全选4个榜单
                dateRanges: ['1'],    // 支持多选时间维度，默认昨日
                startDate: '',
                endDate: '',
                cookies: '',
                auth_data: {}, // 认证数据
                hotelRoomCount: '',   // 酒店房量
                competitorRoomCount: '', // 竞争圈总房量
            });
            const meituanTrafficForm = ref({
                url: '',
                startDate: '',
                endDate: '',
                cookies: '',
                extraParams: '',
            });
            // 美团差评获取表单
            const meituanCommentForm = ref({
                partnerId: '',
                poiId: '',
                cookies: '',
                mtgsig: '',
                replyType: '2', // 2=差评/待回复
                tag: '',
                limit: 50,
            });
            const fetchingCommentData = ref(false);
            const meituanCommentSuccess = ref(false); // 获取成功状态
            const meituanCommentResult = ref(null);
            const showMeituanCommentHelp = ref(false);
            const customForm = ref({
                url: '',
                method: 'GET',
                headers: '',
                body: '',
            });
            const newCookies = ref({ name: '', cookies: '', hotel_id: '' });
            const cookiesList = ref([]);
            const bookmarkletCode = ref('javascript:(function(){alert("请先登录系统");})();');
            const quickCookiesName = ref('');
            const quickCookiesValue = ref('');

            // 携程配置管理
            const ctripConfigForm = ref({
                id: null,
                name: '',
                hotel_id: '',
                url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                node_id: '24588',
                cookies: '',
            });
            const ctripConfigList = ref([]);
            const ctripBookmarklet = ref('');
            const showCtripCookieGuide = ref(true);
            const selectedCtripConfigId = ref(''); // 选中的携程配置ID
            const ctripFetchSuccess = ref(false); // 携程获取成功标志
            const ctripSavedCount = ref(0); // 携程保存的数据条数

            // 美团配置管理
            const meituanConfigForm = ref({
                id: null,
                name: '',
                partner_id: '',
                poi_id: '',
                cookies: '',
                hotel_room_count: '',
                competitor_room_count: '',
            });
            const meituanConfigList = ref([]);
            const meituanBookmarklet = ref('');
            const showConfigHelp = ref(false); // 显示配置获取帮助
            const meituanHotelsList = ref([]); // 美团酒店列表数据
            const meituanFetchSuccess = ref(false); // 美团获取成功标志
            const meituanSavedCount = ref(0); // 美团保存的数据条数
            const meituanDataFetchTime = ref(''); // 美团数据获取时间
            const meituanTableTab = ref('ranking'); // 美团数据表格Tab: ranking/traffic
            // 表格排序功能
            const meituanSortField = ref('roomNights'); // 当前排序字段
            const meituanSortOrder = ref('desc'); // 排序方式: asc/desc
            // 排序函数
            const sortMeituanTable = (field) => {
                if (meituanSortField.value === field) {
                    meituanSortOrder.value = meituanSortOrder.value === 'asc' ? 'desc' : 'asc';
                } else {
                    meituanSortField.value = field;
                    meituanSortOrder.value = 'desc';
                }
                const sorted = [...meituanHotelsList.value].sort((a, b) => {
                    let aVal, bVal;
                    if (field === 'avgRoomPrice') {
                        aVal = (a.roomRevenue || 0) / (a.roomNights || 1);
                        bVal = (b.roomRevenue || 0) / (b.roomNights || 1);
                    } else if (field === 'avgSalesPrice') {
                        aVal = (a.sales || 0) / (a.salesRoomNights || 1);
                        bVal = (b.sales || 0) / (b.salesRoomNights || 1);
                    } else if (field === 'orderCount') {
                        aVal = (a.views || 0) * (a.payConversion || 0);
                        bVal = (b.views || 0) * (b.payConversion || 0);
                    } else if (field === 'absoluteConversion') {
                        aVal = (a.viewConversion || 0) * (a.payConversion || 0);
                        bVal = (b.viewConversion || 0) * (b.payConversion || 0);
                    } else {
                        aVal = a[field] || 0;
                        bVal = b[field] || 0;
                    }
                    return meituanSortOrder.value === 'asc' ? aVal - bVal : bVal - aVal;
                });
                meituanHotelsList.value = sorted;
            };
            
            // AI智能分析相关（携程专用）
            const aiSelectedHotels = ref([]); // 选中的酒店列表
            const aiAnalysisHotelList = ref([]); // 可选的酒店列表
            const aiAnalyzing = ref(false); // AI分析中标志
            const aiAnalysisResult = ref(''); // AI分析结果
            const aiAnalysisHistory = ref([]); // AI分析历史记录
            
            // 美团AI智能分析相关
            const meituanAiSelectedHotels = ref([]); // 选中的酒店列表
            const meituanAiAnalysisHotelList = ref([]); // 可选的酒店列表
            const meituanAiAnalyzing = ref(false); // AI分析中标志
            const meituanAiAnalysisResult = ref(''); // AI分析结果
            const meituanAiAnalysisHistory = ref([]); // AI分析历史记录
            const showMeituanAIAnalysis = ref(false); // 显示美团AI分析弹窗
            // 初始化竞争强度分析（只在首次加载时生成）
            const meituanCompetitionIntensity = ref((() => {
                const r = Math.random();
                if (r < 0.1) return '内卷加剧';
                else if (r < 0.4) return '中度内卷';
                else if (r < 0.9) return '高度内卷';
                return '内卷红海';
            })());
            
            // 线上数据记录相关
            const formatDate = (date) => {
                const y = date.getFullYear();
                const m = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            };
            const today = new Date();
            const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
            const onlineDataFilter = ref({
                start_date: formatDate(thirtyDaysAgo),
                end_date: formatDate(today),
                create_start: '',
                create_end: '',
                hotel_id: '',
                source: '',
                data_type: ''  // 默认不筛选，显示所有类型
            });
            
            const onlineDataList = ref([]);
            const onlineDataPagination = ref({ total: 0, page: 1, page_size: 30 });
            const onlineDataPage = ref(1);
            const onlineDataHotelList = ref([]);
            const onlineDataSummary = ref(null);
            const selectedOnlineDataIds = ref([]);
            const autoFetchScheduleTime = ref('10:00');

            // 门店罗盘
            const compassLayout = ref({ order: ['weather', 'todo', 'metrics', 'alerts', 'holiday'], hidden: [] });
            const compassLayoutPanel = ref(false);
            const compassWeather = ref([]);
            const compassTodos = ref([]);
            const compassMetrics = ref({ day: {}, week: {}, month: {} });
            const compassAlerts = ref([]);
            const compassHolidays = ref([]);
            const compassMetricTab = ref('day');

            // 竞对价格监控
            const competitorTab = ref('hotels');
            const competitorHotels = ref([]);
            const competitorLogs = ref([]);
            const competitorDevices = ref([]);
            const competitorHotelFilter = ref({ store_id: '', platform: '', status: '' });
            const competitorLogFilter = ref({ store_id: '', platform: '', city: '', hotel_id: '' });
            const competitorRobotFilter = ref({ store_id: '' });
            const showCompetitorHotelModal = ref(false);
            const competitorStores = ref([]);
            const competitorHotelForm = ref({
                id: null,
                store_id: '',
                platform: 'mt',
                city: '',
                hotel_name: '',
                hotel_code: '',
                status: 1,
            });
            const competitorRobots = ref([]);
            const showCompetitorRobotModal = ref(false);
            const competitorRobotForm = ref({
                id: null,
                store_id: '',
                name: '',
                webhook: '',
                status: 1,
            });

            // 数据分析相关
            const analysisDimension = ref('day');
            const analysisData = ref({ summary: null, chart_data: null, hotel_ranking: [] });
            let analysisChart = null;
            
            // 加载数据分析
            const loadAnalysisData = async (dimension = null) => {
                try {
                    // 如果传入维度参数，先更新维度
                    if (dimension) {
                        analysisDimension.value = dimension;
                    }
                    console.log('加载数据分析, 维度:', analysisDimension.value);
                    const params = new URLSearchParams({
                        dimension: analysisDimension.value,
                        start_date: onlineDataFilter.value.start_date || '',
                        end_date: onlineDataFilter.value.end_date || '',
                        hotel_id: onlineDataFilter.value.hotel_id || ''
                    });
                    if (onlineDataFilter.value.data_type) {
                        params.append('data_type', onlineDataFilter.value.data_type);
                    }
                    const res = await request(`/online-data/data-analysis?${params}`);
                    console.log('数据分析结果:', res.data);
                    if (res.code === 200) {
                        analysisData.value = res.data || { summary: null, chart_data: null, hotel_ranking: [] };
                        await nextTick();
                        renderAnalysisChart();
                    }
                } catch (error) {
                    console.error('加载分析数据失败:', error);
                }
            };
            
            // 渲染分析图表
            const renderAnalysisChart = (retryCount = 0) => {
                // 检查Chart.js是否加载（多种方式检测）
                const ChartLib = window.Chart;
                if (!ChartLib) {
                    if (retryCount < 5) {
                        console.log(`Chart.js未加载，等待重试 (${retryCount + 1}/5)...`);
                        setTimeout(() => renderAnalysisChart(retryCount + 1), 500);
                        return;
                    }
                    console.warn('Chart.js加载失败，跳过图表渲染');
                    return;
                }
                const canvas = document.getElementById('analysisChart');
                if (!canvas || !analysisData.value.chart_data) return;
                
                // 确保canvas在DOM中且可见
                if (!document.body.contains(canvas)) return;
                if (canvas.offsetParent === null) return; // 元素不可见
                
                try {
                    if (analysisChart) {
                        analysisChart.destroy();
                    }
                    
                    const ctx = canvas.getContext('2d');
                    if (!ctx) return;
                    
                    analysisChart = new ChartLib(ctx, {
                    type: 'line',
                    data: analysisData.value.chart_data,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        scales: {
                            y: { type: 'linear', display: true, position: 'left', title: { display: true, text: '销售额(¥)' } },
                            y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: '房晚/订单' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });
                } catch (chartError) {
                    console.warn('Chart.js渲染失败:', chartError);
                }
            };
            
            // 全选/取消全选
            const toggleSelectAllOnlineData = (e) => {
                if (e.target.checked) {
                    selectedOnlineDataIds.value = onlineDataList.value.map(item => item.id);
                } else {
                    selectedOnlineDataIds.value = [];
                }
            };
            
            // 判断是否全选
            const isAllOnlineDataSelected = computed(() => {
                return onlineDataList.value.length > 0 && selectedOnlineDataIds.value.length === onlineDataList.value.length;
            });
            
            // 保存运行时间设置
            const saveFetchSchedule = async () => {
                if (!autoFetchScheduleTime.value) return;
                try {
                    const res = await request('/online-data/set-fetch-schedule', {
                        method: 'POST',
                        body: JSON.stringify({ schedule_time: autoFetchScheduleTime.value })
                    });
                    if (res.code === 200) {
                        showToast(`运行时间已设置为每天 ${autoFetchScheduleTime.value}`);
                        loadAutoFetchStatus();
                    } else {
                        showToast(res.message || '设置失败', 'error');
                    }
                } catch (error) {
                    showToast('设置失败', 'error');
                }
            };
            
            // 批量删除
            const batchDeleteOnlineData = async () => {
                if (selectedOnlineDataIds.value.length === 0) {
                    showToast('请选择要删除的数据', 'error');
                    return;
                }
                if (!confirm(`确定要删除选中的 ${selectedOnlineDataIds.value.length} 条数据吗？`)) return;
                
                try {
                    const res = await request('/online-data/batch-delete', {
                        method: 'POST',
                        body: JSON.stringify({ ids: selectedOnlineDataIds.value })
                    });
                    if (res.code === 200) {
                        showToast(`成功删除 ${res.data.deleted_count} 条数据`);
                        selectedOnlineDataIds.value = [];
                        refreshOnlineData();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (error) {
                    showToast('删除失败: ' + error.message, 'error');
                }
            };
            
            // 自动获取状态
            const autoFetchEnabled = ref(false);
            const autoFetchStatus = ref({
                last_run_time: null,
                next_run_time: null,
                last_result: null
            });
            
            // 数值工具：避免字符串/空值导致渲染异常
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
            // 格式化数字
            const formatNumber = (num) => {
                if (num === null || num === undefined) return '0';
                return toNumber(num, 0).toLocaleString();
            };
            
            // 打开目标网站
            const openTargetSite = (url) => {
                window.open(url, '_blank');
            };
            
            // 快速保存 Cookies
            const saveQuickCookies = async () => {
                if (!quickCookiesName.value || !quickCookiesValue.value) {
                    showToast('请填写名称和Cookies', 'error');
                    return;
                }
                const res = await request('/online-data/save-cookies', {
                    method: 'POST',
                    body: JSON.stringify({
                        name: quickCookiesName.value,
                        cookies: quickCookiesValue.value
                    })
                });
                if (res.code === 200) {
                    showToast('Cookies保存成功');
                    quickCookiesName.value = '';
                    quickCookiesValue.value = '';
                    loadCookiesList();
                } else {
                    showToast(res.message || '保存失败', 'error');
                }
            };

            // 查看线上数据详情
            const viewOnlineDataDetail = (item) => {
                if (item.raw_data) {
                    try {
                        const data = typeof item.raw_data === 'string' ? JSON.parse(item.raw_data) : item.raw_data;
                        const hotels = extractAllCtripHotels(data);
                        if (hotels.length > 0) {
                            ctripHotelsList.value = hotels.sort((a, b) => b.quantity - a.quantity);
                            ctripTableTab.value = 'sales';
                            onlineDataTab.value = 'ctrip-download';
                            downloadCenterTab.value = 'fetched';
                            // 更新AI分析酒店列表
                            updateAiAnalysisHotelList();
                        } else {
                            showToast('该记录无详细数据', 'warning');
                        }
                    } catch (e) {
                        showToast('数据解析失败', 'error');
                    }
                } else {
                    showToast('该记录无原始数据', 'warning');
                }
            };

            // 编辑线上数据
            const showOnlineDataEditModal = ref(false);
            const onlineDataEditForm = ref({
                id: null,
                hotel_name: '',
                hotel_id: '',
                data_date: '',
                amount: 0,
                quantity: 0,
                book_order_num: 0,
                comment_score: 0,
                qunar_comment_score: 0
            });

            const editOnlineDataItem = (item) => {
                onlineDataEditForm.value = {
                    id: item.id,
                    hotel_name: item.hotel_name,
                    hotel_id: item.hotel_id,
                    data_date: item.data_date,
                    amount: item.amount || 0,
                    quantity: item.quantity || 0,
                    book_order_num: item.book_order_num || 0,
                    comment_score: item.comment_score || 0,
                    qunar_comment_score: item.qunar_comment_score || 0
                };
                showOnlineDataEditModal.value = true;
            };

            const saveOnlineDataEdit = async () => {
                try {
                    const res = await request('/online-data/update-data', {
                        method: 'POST',
                        body: JSON.stringify(onlineDataEditForm.value)
                    });
                    if (res.code === 200) {
                        showToast('保存成功');
                        showOnlineDataEditModal.value = false;
                        loadOnlineDataList();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (error) {
                    showToast('保存失败: ' + error.message, 'error');
                }
            };

            // 删除线上数据
            const deleteOnlineDataItem = async (id) => {
                if (!confirm('确定要删除这条数据吗？')) return;
                try {
                    const res = await request('/online-data/delete-data', {
                        method: 'POST',
                        body: JSON.stringify({ id })
                    });
                    if (res.code === 200) {
                        showToast('删除成功');
                        loadOnlineDataList();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (error) {
                    showToast('删除失败: ' + error.message, 'error');
                }
            };

            // 切换下载中心Tab（自动加载数据）
            const switchDownloadTab = async (tab) => {
                downloadCenterTab.value = tab;
                if (tab !== 'fetched') {
                    // 根据当前页面设置数据来源
                    if (onlineDataTab.value === 'ctrip-download' || onlineDataTab.value.startsWith('ctrip')) {
                        onlineDataFilter.value.source = 'ctrip';
                    } else if (onlineDataTab.value === 'meituan-download' || onlineDataTab.value.startsWith('meituan')) {
                        onlineDataFilter.value.source = 'meituan';
                    }
                    // 切换到历史记录、流量分析、AI分析时自动加载数据
                    await loadOnlineDataList();
                    await loadOnlineDataHotelList();
                    
                    // AI分析Tab需要更新酒店列表
                    if (tab === 'ai') {
                        if (onlineDataTab.value === 'ctrip-download' || onlineDataTab.value.startsWith('ctrip')) {
                            updateAiAnalysisHotelList();
                        } else if (onlineDataTab.value === 'meituan-download' || onlineDataTab.value.startsWith('meituan')) {
                            updateMeituanAiAnalysisHotelList();
                        }
                    }
                }
            };

            // 切换到下载中心（自动加载数据）
            const switchToDownloadCenter = async () => {
                onlineDataTab.value = 'ctrip-download';
                // 设置数据来源为携程
                onlineDataFilter.value.source = 'ctrip';
                // 如果默认子 tab 不是 fetched（最新获取数据），自动加载数据
                if (downloadCenterTab.value !== 'fetched') {
                    await loadOnlineDataList();
                    await loadOnlineDataHotelList();
                }
            };

            // 切换到美团下载中心（自动加载数据）
            const switchToMeituanDownloadCenter = async () => {
                onlineDataTab.value = 'meituan-download';
                // 设置数据来源为美团
                onlineDataFilter.value.source = 'meituan';
                // 自动加载数据
                await loadOnlineDataList();
                await loadOnlineDataHotelList();
            };

            // 加载线上数据列表
            const loadOnlineDataList = async () => {
                try {
                    const params = new URLSearchParams({
                        page: onlineDataPage.value,
                        page_size: onlineDataPagination.value.page_size || 30
                    });
                    if (onlineDataFilter.value.hotel_id) {
                        params.append('hotel_id', onlineDataFilter.value.hotel_id);
                    }
                    if (onlineDataFilter.value.source) {
                        params.append('source', onlineDataFilter.value.source);
                    }
                    if (onlineDataFilter.value.data_type) {
                        params.append('data_type', onlineDataFilter.value.data_type);
                    }
                    // 按获取时间查询
                    if (onlineDataFilter.value.create_start) {
                        params.append('create_start', onlineDataFilter.value.create_start);
                    }
                    if (onlineDataFilter.value.create_end) {
                        params.append('create_end', onlineDataFilter.value.create_end);
                    }
                    // 兼容旧的日期查询参数
                    if (onlineDataFilter.value.start_date) {
                        params.append('start_date', onlineDataFilter.value.start_date);
                    }
                    if (onlineDataFilter.value.end_date) {
                        params.append('end_date', onlineDataFilter.value.end_date);
                    }
                    console.log('加载数据列表，参数:', params.toString());
                    const res = await request(`/online-data/daily-data-list?${params}`);
                    console.log('加载数据列表响应:', res);
                    if (res.code === 200) {
                        onlineDataList.value = res.data.list || [];
                        onlineDataPagination.value = res.data.pagination || { total: 0, page: 1, page_size: 30 };
                        console.log('加载数据成功，数量:', onlineDataList.value.length);
                    } else {
                        console.error('加载数据失败:', res.message);
                    }
                } catch (error) {
                    console.error('加载数据列表失败:', error);
                }
            };
            
            // 加载数据汇总
            const loadOnlineDataSummary = async () => {
                try {
                    const params = new URLSearchParams({
                        start_date: onlineDataFilter.value.start_date || '',
                        end_date: onlineDataFilter.value.end_date || ''
                    });
                    if (onlineDataFilter.value.data_type) {
                        params.append('data_type', onlineDataFilter.value.data_type);
                    }
                    const res = await request(`/online-data/daily-data-summary?${params}`);
                    if (res.code === 200) {
                        onlineDataSummary.value = res.data?.total || null;
                    }
                } catch (error) {
                    console.error('加载汇总失败:', error);
                }
            };
            
            // 加载酒店列表（用于筛选）
            const loadOnlineDataHotelList = async () => {
                try {
                    const params = new URLSearchParams();
                    if (onlineDataFilter.value.data_type) {
                        params.append('data_type', onlineDataFilter.value.data_type);
                    }
                    const res = await request(`/online-data/hotel-list?${params}`);
                    if (res.code === 200) {
                        onlineDataHotelList.value = res.data || [];
                    }
                } catch (error) {
                    console.error('加载酒店列表失败:', error);
                }
            };
            
            // 刷新数据（查询按钮）
            const refreshOnlineData = async () => {
                try {
                    const tasks = [loadOnlineDataList()];
                    if (onlineDataFilter.value.data_type !== 'traffic') {
                        tasks.push(loadOnlineDataSummary(), loadAnalysisData());
                    } else {
                        onlineDataSummary.value = null;
                        analysisData.value = { summary: null, chart_data: null, hotel_ranking: [] };
                    }
                    await Promise.all(tasks);
                } catch (error) {
                    console.error('刷新数据失败:', error);
                }
            };
            
            // 切换分页
            const changeOnlineDataPage = async (page) => {
                onlineDataPage.value = page;
                try {
                    await loadOnlineDataList();
                } catch (error) {
                    console.error('加载数据失败:', error);
                }
            };
            
            // 切换自动获取开关
            const toggleAutoFetch = async () => {
                try {
                    const res = await request('/online-data/toggle-auto-fetch', {
                        method: 'POST',
                        body: JSON.stringify({ enabled: autoFetchEnabled.value })
                    });
                    if (res.code === 200) {
                        showToast(autoFetchEnabled.value ? '自动获取已开启' : '自动获取已关闭');
                        loadAutoFetchStatus();
                    } else {
                        autoFetchEnabled.value = !autoFetchEnabled.value;
                        showToast(res.message || '操作失败', 'error');
                    }
                } catch (error) {
                    autoFetchEnabled.value = !autoFetchEnabled.value;
                    showToast('操作失败', 'error');
                }
            };
            
            // 加载自动获取状态
            const loadAutoFetchStatus = async () => {
                try {
                    const res = await request('/online-data/auto-fetch-status');
                    if (res.code === 200) {
                        autoFetchEnabled.value = res.data?.enabled || false;
                        autoFetchStatus.value = res.data || {};
                        autoFetchScheduleTime.value = res.data?.schedule_time || '10:00';
                    }
                } catch (error) {
                    console.error('加载自动获取状态失败:', error);
                }
            };
            
            // 手动触发自动获取
            const triggerAutoFetch = async () => {
                fetchingData.value = true;
                showToast('正在获取数据...', 'info');
                try {
                    const body = {};
                    if (onlineDataFilter.value.hotel_id) {
                        body.system_hotel_id = onlineDataFilter.value.hotel_id;
                    }
                    const res = await request('/online-data/auto-fetch', { 
                        method: 'POST',
                        body: JSON.stringify(body)
                    });
                    if (res.code === 200) {
                        showToast(`获取成功，保存了 ${res.data?.saved_count || 0} 条数据`);
                        await refreshOnlineData();
                        await loadAutoFetchStatus();
                    } else {
                        showToast(res.message || '获取失败', 'error');
                    }
                } catch (error) {
                    showToast('获取失败: ' + error.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };
            
            // 加载书签脚本
            const loadBookmarklet = async () => {
                if (!token.value) return;
                try {
                    const res = await request(`/online-data/bookmarklet?token=${token.value}`);
                    if (res.code === 200) {
                        bookmarkletCode.value = res.data.bookmarklet;
                    }
                } catch (e) {
                    console.error('加载书签脚本失败:', e);
                }
            };
            
            // 复制书签代码
            const copyBookmarklet = () => {
                navigator.clipboard.writeText(bookmarkletCode.value);
                showToast('书签代码已复制到剪贴板');
            };
            
            // 复制Cookie获取脚本
            const cookieScript = `(() => {
  let c = document.cookie;
  if (!c || (!c.includes('JSESSIONID') && !c.includes('cookie=') && !c.includes('session'))) {
    alert('Cookie可能被页面过滤，请使用方法三从Network请求头复制');
    return;
  }
  copy(c);
  alert('Cookie已复制到剪贴板！');
})()`;
            
            const copyCookieScript = () => {
                navigator.clipboard.writeText(cookieScript);
                showToast('Cookie脚本已复制，请到携程页面控制台粘贴执行');
            };
            
            // 监听页面切换
            watch(currentPage, (newPage) => {
                if (newPage === 'compass') {
                    loadCompassData();
                }
                if (newPage === 'ctrip-ebooking') {
                    onlineDataTab.value = 'ctrip-ranking';
                    loadOnlineDataHotelList();
                    loadCtripConfigList();
                }
                if (newPage === 'meituan-ebooking') {
                    onlineDataTab.value = 'meituan-ranking';
                    loadMeituanConfig();
                    loadOnlineDataHotelList();
                    loadMeituanConfigList();
                }
                if (newPage === 'online-data' && token.value) {
                    // 延迟加载，确保页面渲染完成
                    setTimeout(() => {
                        loadBookmarklet();
                        loadOnlineDataHotelList();
                        loadAutoFetchStatus();
                    }, 100);
                }
                if (newPage === 'competitor') {
                    loadCompetitorHotels();
                    loadCompetitorLogs();
                    loadCompetitorDevices();
                    loadCompetitorStores();
                    loadCompetitorRobots();
                }
                if (newPage === 'operation-logs') {
                    loadOperationLogs();
                }
            });
            
            // 监听数据记录标签页切换（添加防抖）
            let dataLoadTimer = null;
            watch(onlineDataTab, (newTab) => {
                if (newTab === 'data') {
                    // 清除之前的定时器，防止重复加载
                    if (dataLoadTimer) {
                        clearTimeout(dataLoadTimer);
                    }
                    dataLoadTimer = setTimeout(() => {
                        refreshOnlineData();
                    }, 100);
                }
                // 加载携程配置列表
                if (newTab === 'ctrip-config') {
                    loadCtripConfigList();
                }
                // 加载美团配置列表
                if (newTab === 'meituan-config' || newTab === 'meituan-review') {
                    console.log('[Debug] Watch触发 - 加载美团配置列表, tab:', newTab);
                    loadMeituanConfigList();
                }
            });

            watch(() => meituanForm.value.hotelId, () => {
                if (onlineDataTab.value === 'meituan' && user.value?.is_super_admin) {
                    loadMeituanConfig();
                }
            });

            watch(competitorTab, (newTab) => {
                if (newTab === 'robots') {
                    loadCompetitorRobots();
                }
            });


            // 菜单配置 - 根据权限控制显示
            const menuItems = computed(() => [
                { name: '首页', path: 'compass', icon: 'fas fa-home', requireSuper: false, requireManager: true, permissions: [] },
                { name: '全生命周期服务', path: 'lifecycle', icon: 'fas fa-share-alt', requireSuper: false, permissions: [], highlight: true },
                { 
                    name: '项目AI管理', 
                    icon: 'fas fa-project-diagram', 
                    requireSuper: false, 
                    permissions: [],
                    children: [
                        { 
                            name: '筹建管理', 
                            icon: 'fas fa-hammer', 
                            path: 'ai-construction',
                            children: [
                                { name: '智略·战略推演', path: 'ai-strategy', icon: 'fas fa-chess' },
                                { name: '智算·量化模拟', path: 'ai-simulation', icon: 'fas fa-calculator' },
                                { name: '可行性报告', path: 'ai-feasibility', icon: 'fas fa-file-contract' }
                            ]
                        },
                        { 
                            name: '开业管理', 
                            icon: 'fas fa-store', 
                            path: 'ai-opening',
                            children: [
                                { name: 'PMS AI·快速部署', path: 'pms-deploy', icon: 'fas fa-bolt' },
                                { name: '智联·数据融合', path: 'data-fusion', icon: 'fas fa-network-wired' },
                                { name: '员工端AI演练', path: 'staff-ai-drill', icon: 'fas fa-user-graduate' }
                            ]
                        },
                        { 
                            name: '运营管理', 
                            icon: 'fas fa-cogs', 
                            path: 'ai-ops',
                            children: [
                                { name: '策源·全维数据', path: 'ops-source', icon: 'fas fa-search' },
                                { name: '策析·根因定位', path: 'ops-analysis', icon: 'fas fa-microscope' },
                                { name: '策见·预警推送', path: 'ops-insight', icon: 'fas fa-bell' },
                                { name: '策案·策略模拟', path: 'ops-plan', icon: 'fas fa-lightbulb' },
                                { name: '策行·效果追踪', path: 'ops-track', icon: 'fas fa-play-circle' }
                            ]
                        },
                        { 
                            name: '扩张管理', 
                            icon: 'fas fa-chart-line', 
                            path: 'ai-expansion',
                            children: [
                                { name: '智投·市场评估', path: 'market-eval', icon: 'fas fa-chart-area' },
                                { name: '智瞰·标杆选模', path: 'benchmark-model', icon: 'fas fa-star' },
                                { name: '智联·协同提效', path: 'sync-efficiency', icon: 'fas fa-link' }
                            ]
                        },
                        { 
                            name: '转让管理', 
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
                { name: systemConfig.value.menu_hotel_name || '门店管理', path: 'hotels', icon: 'fas fa-hotel', configKey: 'menu_hotel_name', requireSuper: false, permissions: [] },
                { name: '投资预算', path: 'hotel-investment', icon: 'fas fa-calculator', requireSuper: false, permissions: [] },
                { 
                    name: '数据中心', 
                    icon: 'fas fa-chart-bar', 
                    requireManager: true, 
                    permissions: [],
                    children: [
                        { name: systemConfig.value.menu_daily_report_name || '日报表', path: 'daily-reports', icon: 'fas fa-calendar-day', configKey: 'menu_daily_report_name' },
                        { name: systemConfig.value.menu_monthly_task_name || '月任务', path: 'monthly-tasks', icon: 'fas fa-calendar-alt', configKey: 'menu_monthly_task_name' }
                    ]
                },
                { 
                    name: '线上数据获取', 
                    icon: 'fas fa-cloud-download-alt', 
                    requireSuper: false, 
                    permissions: ['can_view_report'],
                    children: [
                        { name: '携程ebooking', path: 'ctrip-ebooking', icon: 'fas fa-plane' },
                        { name: '美团ebooking', path: 'meituan-ebooking', icon: 'fas fa-store' }
                    ]
                },
                { name: '竞对价格监控', path: 'competitor', icon: 'fas fa-tags', requireSuper: true, permissions: [] },
                { name: 'Agent中心', path: 'agent-center', icon: 'fas fa-robot', requireSuper: true, permissions: [] },
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
                        { name: '报表配置', path: 'report-config', icon: 'fas fa-file-alt' },
                        { name: '系统配置', path: 'system-config', icon: 'fas fa-sliders-h' },
                        { name: '数据配置', path: 'data-config', icon: 'fas fa-database' }
                    ]
                },
            ]);

            // 获取菜单项名称
            const getMenuItemName = (item) => {
                return item.name;
            };

            // 可见菜单项 - 根据用户权限过滤
            const visibleMenuItems = computed(() => {
                if (!user.value) return [];
                
                // 超级管理员看到所有菜单
                if (user.value.is_super_admin) return menuItems.value;
                
                // 辅助函数：检查单个菜单项是否可见
                const isItemVisible = (item) => {
                    // 需要超级管理员权限的菜单，普通用户看不到
                    if (item.requireSuper) return false;
                    
                    // 需要店长及以上权限的菜单
                    if (item.requireManager && user.value.role_id !== 2) return false;
                    
                    // 检查是否有必需的权限
                    if (item.permissions && item.permissions.length > 0) {
                        const perms = user.value.permissions || {};
                        return item.permissions.some(p => perms[p]);
                    }
                    
                    return true;
                };
                
                // 过滤菜单项
                return menuItems.value.filter(item => {
                    // 如果有子菜单，先过滤子菜单
                    if (item.children) {
                        // 过滤可见的子菜单项
                        const visibleChildren = item.children.filter(child => isItemVisible(child));
                        // 如果有可见的子菜单，则显示父菜单（只显示可见的子菜单）
                        return visibleChildren.length > 0;
                    }
                    
                    // 普通菜单项，直接检查权限
                    return isItemVisible(item);
                }).map(item => {
                    // 对于有子菜单的项，只保留可见的子菜单
                    if (item.children) {
                        return {
                            ...item,
                            children: item.children.filter(child => isItemVisible(child))
                        };
                    }
                    return item;
                });
            });

            // 展开的子菜单（初始化时通过watch自动展开所有有子菜单的项）
            const expandedMenus = ref(['线上数据获取']);
            
            // 切换子菜单展开状态
            const toggleSubmenu = (menuName) => {
                const index = expandedMenus.value.indexOf(menuName);
                if (index > -1) {
                    expandedMenus.value.splice(index, 1);
                } else {
                    expandedMenus.value.push(menuName);
                }
            };

            // 自动展开所有带子菜单的父级项（确保名称匹配不依赖硬编码）
            const autoExpandAllMenus = () => {
                menuItems.value.forEach(item => {
                    if (item.children && item.children.length > 0) {
                        if (!expandedMenus.value.includes(item.name)) {
                            expandedMenus.value.push(item.name);
                        }
                    }
                });
            };

            // 页面标题
            const pageTitle = computed(() => {
                const item = menuItems.value.find(m => m.path === currentPage.value);
                return item ? item.name : '';
            });

            const handleMenuClick = (item) => {
                currentPage.value = item.path;
            };

            // Toast
            const toast = ref({ show: false, message: '', type: 'success' });
            const showToast = (message, type = 'success') => {
                toast.value = { show: true, message, type };
                setTimeout(() => toast.value.show = false, 3000);
            };

            // 数据
            const hotels = ref([]);
            const permittedHotels = ref([]);
            const users = ref([]);
            
            // 表格列定义
            const hotelColumns = [
                { key: 'id', label: 'ID' },
                { key: 'name', label: '酒店名称' },
                { key: 'code', label: '编码' },
                { key: 'address', label: '地址' },
                { key: 'contact_person', label: '联系人' },
                { key: 'contact_phone', label: '联系电话' },
                { key: 'status', label: '状态' },
                { key: 'actions', label: '操作' }
            ];
            
            const userColumns = [
                { key: 'id', label: 'ID' },
                { key: 'username', label: '用户名' },
                { key: 'realname', label: '姓名' },
                { key: 'role', label: '角色' },
                { key: 'hotel', label: '所属酒店' },
                { key: 'status', label: '状态' },
                { key: 'actions', label: '操作' }
            ];
            const roles = ref([]);
            const rolesList = ref([]);
            const allPermissions = ref([]);
            const showRoleModal = ref(false);
            const roleForm = ref({ id: null, name: '', display_name: '', description: '', level: 1, status: 1, permissionList: [] });
            const dailyReports = ref([]);
            const monthlyTasks = ref([]);
            const reportConfigs = ref([]);
            const dailyReportConfig = ref([]); // 日报表动态配置（按分类分组）
            const dailyReportTab = ref('tab1'); // 日报表当前标签页
            const monthlyTaskConfig = ref([]); // 月任务动态配置
            
            // 导入预览数据
            const importPreviewData = ref(null);
            const showImportPreview = ref(false);
            const importStep = ref(1); // 1: 选择文件, 2: 预览映射, 3: 确认导入
            const manualMappings = ref({}); // 手动映射 { excel_item_name: system_field }
            const rowMappings = ref({}); // 行级映射 { excel_item_name: system_field }
            const existingMappings = ref({}); // 已存在的映射 { excel_item_name: system_field }

            // 搜索和过滤
            const searchHotel = ref('');
            const filterHotelStatus = ref('');
            const searchUser = ref('');
            const filterUserRoleId = ref('');
            const filterReportHotel = ref('');
            const filterReportStartDate = ref('');
            const filterReportEndDate = ref('');
            const filterTaskHotel = ref('');
            const filterTaskYear = ref('');
            const filterConfigType = ref('');

            // 权限计算属性
            const canViewReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_view_report);
            const canFillDailyReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_fill_daily_report);
            const canFillMonthlyTask = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_fill_monthly_task);
            const canEditReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_edit_report);
            const canDeleteReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_delete_report);

            // 导出状态
            const exportingReports = ref(false);
            const currentViewingReportId = ref(null);

            // 过滤后的报表配置
            const filteredReportConfigs = computed(() => {
                if (!filterConfigType.value) return reportConfigs.value;
                return reportConfigs.value.filter(c => c.report_type === filterConfigType.value);
            });

            // 字段类型标签
            const getFieldTypeLabel = (type) => {
                const labels = { number: '数字', text: '文本', textarea: '多行文本', select: '下拉选择', date: '日期' };
                return labels[type] || type;
            };

            // 年份选项
            const yearOptions = computed(() => {
                const years = [];
                const currentYear = new Date().getFullYear();
                for (let i = currentYear - 2; i <= currentYear + 1; i++) years.push(i);
                return years;
            });

            // 昨天日期（用于日报日期限制）
            const yesterdayDate = computed(() => {
                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                return yesterday.toISOString().split('T')[0];
            });

            // 模态框
            const showHotelModal = ref(false);
            const showUserModal = ref(false);
            const showPermissionModal = ref(false);
            const showDailyReportModal = ref(false);
            const showMonthlyTaskModal = ref(false);
            const showReportConfigModal = ref(false);
            const showSystemConfigModal = ref(false);
            const showViewReportModal = ref(false);
            const showDataConfigModal = ref(false);
            
            // 数据配置
            const currentDataConfigType = ref('');
            const dataConfigTitle = ref('');
            const testingConfig = ref(false);
            const savingConfig = ref(false);
            const dataConfigForm = ref({
                // 通用字段
                url: '',
                cookies: '',
                cookie: '',
                hotelId: '',
                system_hotel_id: '',
                startDate: '',
                endDate: '',
                begin_date: '',
                auto_save: true,
                extraParams: '',
                // 携程ebooking
                nodeId: '',
                // 美团ebooking
                partnerId: '',
                poiId: '',
                rankType: '',
                // 携程点评
                spidertoken: '',
                _fxpcqlniredt: '',
                x_trace_id: '',
                page_index: 1,
                page_size: 10,
                // 美团点评
                partner_id: '',
                poi_id: '',
                mtgsig: '',
                _mtsi_eb_u: '',
                limit: 10,
                offset: 0,
                custom_url: '',
                // 广告
                api_type: 'campaign_list',
                campaign_id: '',
                time_unit: 'day',
            });
            
            // 日报表查看
            const viewReportData = ref(null);
            const viewReportLoading = ref(false);
            const reportContentRef = ref(null);
            
            // 导入相关
            const importFileInput = ref(null);
            const importModalFileInput = ref(null);
            const importStatus = ref({ show: false, type: '', message: '' });
            const importingExcel = ref(false);
            const importedFromFile = ref(false); // 标记是否从文件导入（用于锁定日期和酒店）

            // 表单
            const hotelForm = ref({ id: null, name: '', code: '', address: '', contact_person: '', contact_phone: '', status: 1, description: '' });
            const userForm = ref({ id: null, username: '', password: '', realname: '', role_id: '', hotel_id: null, status: 1 });
            const dailyReportForm = ref({ id: null, hotel_id: '', report_date: '' }); // 动态字段将在初始化时添加
            const monthlyTaskForm = ref({ id: null, hotel_id: '', year: new Date().getFullYear(), month: new Date().getMonth() + 1 }); // 动态字段将在初始化时添加
            const reportConfigForm = ref({ id: null, report_type: 'daily', field_name: '', display_name: '', field_type: 'number', unit: '', options: '', sort_order: 0, is_required: 0, status: 1 });
            const systemConfigForm = ref({ 
                system_name: '', 
                logo_url: '', 
                favicon_url: '',
                system_description: '',
                system_keywords: '',
                menu_hotel_name: '', 
                menu_users_name: '', 
                menu_daily_report_name: '', 
                menu_monthly_task_name: '', 
                menu_report_config_name: '',
                menu_compass_name: '',
                menu_online_data_name: '',
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
                complaint_mini_page: '',
                complaint_mini_use_scene: '',
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
                notify_daily_report: '0',
                notify_monthly_task: '0'
            });
            
            // 配置分组
            const activeConfigGroup = ref('basic');
            const configGroups = ref([
                { key: 'basic', title: '基础设置', icon: 'fas fa-cog' },
                { key: 'menu', title: '菜单配置', icon: 'fas fa-bars' },
                { key: 'display', title: '显示设置', icon: 'fas fa-palette' },
                { key: 'feature', title: '功能开关', icon: 'fas fa-toggle-on' },
                { key: 'security', title: '安全设置', icon: 'fas fa-shield-alt' },
                { key: 'notification', title: '通知设置', icon: 'fas fa-bell' }
            ]);
            
            // 菜单配置项
            const menuConfigItems = ref([
                { key: 'menu_hotel_name', label: '酒店管理' },
                { key: 'menu_users_name', label: '用户管理' },
                { key: 'menu_daily_report_name', label: '日报表管理' },
                { key: 'menu_monthly_task_name', label: '月任务管理' },
                { key: 'menu_report_config_name', label: '报表配置' },
                { key: 'menu_compass_name', label: '罗盘' },
                { key: 'menu_online_data_name', label: '竞对价格监控' }
            ]);
            
            // 功能开关
            const featureSwitches = ref([
                { key: 'enable_registration', label: '启用用户注册', description: '允许新用户自行注册账号' },
                { key: 'enable_login_log', label: '启用登录日志', description: '记录用户登录和登出操作' },
                { key: 'enable_operation_log', label: '启用操作日志', description: '记录用户的各类操作行为' },
                { key: 'enable_data_backup', label: '启用数据备份', description: '允许进行数据备份和恢复' },
                { key: 'enable_wechat_mini', label: '启用微信小程序', description: '开启微信小程序相关功能' },
                { key: 'enable_online_data', label: '启用线上数据', description: '开启竞对价格监控功能' }
            ]);
            
            // 导入配置相关
            const showImportConfigModal = ref(false);
            const importConfigFile = ref(null);
            const importConfigPreview = ref(null);
            
            // 权限相关
            const permissionUser = ref(null);
            const userPermissions = ref([]);

            // 过滤后的数据
            const filteredHotels = computed(() => {
                if (!hotels.value || !Array.isArray(hotels.value) || hotels.value.length === 0) {
                    return [];
                }
                return hotels.value.filter(h => {
                    // 名称筛选（支持空值保护）
                    const hotelName = h?.name || '';
                    const searchTerm = searchHotel.value || '';
                    const matchName = !searchTerm || hotelName.toLowerCase().includes(searchTerm.toLowerCase());
                    // 状态筛选：空值表示不过滤
                    let matchStatus = true;
                    if (filterHotelStatus.value !== '' && filterHotelStatus.value !== null && filterHotelStatus.value !== undefined) {
                        matchStatus = String(h?.status) === String(filterHotelStatus.value);
                    }
                    return matchName && matchStatus;
                });
            });

            const filteredUsers = computed(() => {
                return users.value.filter(u => {
                    const matchSearch = !searchUser.value || u.username.includes(searchUser.value) || (u.realname && u.realname.includes(searchUser.value));
                    const matchRole = !filterUserRoleId.value || u.role_id == filterUserRoleId.value;
                    return matchSearch && matchRole;
                });
            });

            // API 请求
            const request = async (url, options = {}) => {
                const headers = { 'Content-Type': 'application/json' };
                if (token.value) headers['Authorization'] = token.value;
                
                try {
                    const response = await fetch(API_BASE + url, {
                        ...options,
                        headers: { ...headers, ...options.headers }
                    });
                    
                    // 尝试解析响应体
                    const data = await response.json().catch(() => ({}));
                    
                    // 处理 401 认证失败
                    if (response.status === 401 || data.code === 401) {
                        isLoggedIn.value = false;
                        user.value = null;
                        token.value = '';
                        localStorage.removeItem('token');
                        // 只在非初始化请求时显示提示
                        if (url !== '/auth/info') {
                            showToast('登录已过期，请重新登录', 'error');
                        }
                        return data;
                    }
                    
                    // 其他 HTTP 错误
                    if (!response.ok) {
                        const error = new Error(data.message || data.msg || `HTTP错误: ${response.status}`);
                        error.data = data;
                        throw error;
                    }
                    
                    return data;
                } catch (error) {
                    console.error('API请求失败:', url, error);
                    throw error;
                }
            };

            // 登录
            const handleLogin = async () => {
                if (!loginForm.value.username || !loginForm.value.password) {
                    showToast('请输入用户名和密码', 'warning');
                    return;
                }
                
                loading.value = true;
                try {
                    const res = await request('/auth/login', {
                        method: 'POST',
                        body: JSON.stringify({
                            username: loginForm.value.username,
                            password: loginForm.value.password
                        })
                    });
                    
                    if (res.code === 200) {
                        token.value = res.data.token;
                        user.value = res.data.user;
                        localStorage.setItem('token', token.value);
                        
                        // 记住用户名
                        if (rememberUsername.value) {
                            localStorage.setItem('remembered_username', loginForm.value.username);
                        } else {
                            localStorage.removeItem('remembered_username');
                        }
                        
                        isLoggedIn.value = true;
                        showToast(`欢迎回来，${res.data.user.realname || res.data.user.username}！`, 'success');
                        currentPage.value = 'compass';
                        loadData();
                        loadCompassData();
                    } else {
                        showToast(res.message || '登录失败，请检查用户名和密码', 'error');
                    }
                } catch (e) {
                    showToast('网络连接失败，请检查网络后重试', 'error');
                }
                loading.value = false;
            };

            // 侧边栏折叠切换
            const toggleSidebar = () => {
                sidebarCollapsed.value = !sidebarCollapsed.value;
            };

            const handleLogout = async () => {
                await request('/auth/logout', { method: 'POST' });
                isLoggedIn.value = false;
                user.value = null;
                token.value = '';
                localStorage.removeItem('token');
            };

            // 加载数据
            const loadHotels = async () => {
                try {
                    // 使用admin接口获取所有酒店（包括禁用的）
                    const url = user.value?.is_super_admin ? '/hotels?page=1&page_size=1000' : '/hotels/all';
                    const res = await request(url);
                    if (res.code === 200) {
                        // 兼容两种响应格式
                        const hotelData = res.data.list || res.data;
                        if (Array.isArray(hotelData)) {
                            hotels.value = hotelData;
                        } else {
                            hotels.value = [];
                            console.warn('酒店数据格式异常:', res.data);
                        }
                    } else {
                        showToast('加载酒店数据失败', 'error');
                    }
                } catch (error) {
                    showToast('加载酒店数据失败', 'error');
                    console.error('加载酒店失败:', error);
                }
            };

            const loadUsers = async () => {
                const res = await request('/users?page=1&page_size=100');
                if (res.code === 200) users.value = res.data.list || [];
            };

            const loadRoles = async () => {
                const res = await request('/users/roles');
                if (res.code === 200) roles.value = res.data;
            };

            const loadDailyReports = async () => {
                let url = '/daily-reports?page=1&page_size=100';
                // 酒店ID：优先使用选择的酒店，单门店用户自动使用其唯一酒店
                let hotelId = filterReportHotel.value;
                if (!hotelId && !user.value.is_super_admin && permittedHotels.value.length === 1) {
                    hotelId = permittedHotels.value[0].id;
                }
                if (hotelId) url += '&hotel_id=' + hotelId;
                if (filterReportStartDate.value) url += '&start_date=' + filterReportStartDate.value;
                if (filterReportEndDate.value) url += '&end_date=' + filterReportEndDate.value;
                const res = await request(url);
                if (res.code === 200) dailyReports.value = res.data.list || [];
            };
            
            // 加载日报表配置
            const loadDailyReportConfig = async () => {
                const res = await request('/daily-reports/config');
                if (res.code === 200) {
                    dailyReportConfig.value = res.data || [];
                    console.log('日报表配置加载完成:', dailyReportConfig.value.length, '个分类');
                }
            };
            
            // 加载月任务配置
            const loadMonthlyTaskConfig = async () => {
                const res = await request('/monthly-tasks/config');
                if (res.code === 200) {
                    monthlyTaskConfig.value = res.data || [];
                    console.log('月任务配置加载完成:', monthlyTaskConfig.value.length, '个配置项');
                }
            };

            const loadMonthlyTasks = async () => {
                let url = '/monthly-tasks?page=1&page_size=100';
                // 酒店ID：优先使用选择的酒店，单门店用户自动使用其唯一酒店
                let hotelId = filterTaskHotel.value;
                if (!hotelId && !user.value.is_super_admin && permittedHotels.value.length === 1) {
                    hotelId = permittedHotels.value[0].id;
                }
                if (hotelId) url += '&hotel_id=' + hotelId;
                if (filterTaskYear.value) url += '&year=' + filterTaskYear.value;
                const res = await request(url);
                if (res.code === 200) monthlyTasks.value = res.data.list || [];
            };

            const loadUserInfo = async () => {
                const res = await request('/auth/info');
                if (res.code === 200) {
                    user.value = res.data;
                    permittedHotels.value = res.data.permitted_hotels || [];
                    // 单门店用户自动选择其唯一的酒店
                    if (!user.value.is_super_admin && permittedHotels.value.length === 1) {
                        filterReportHotel.value = permittedHotels.value[0].id;
                        filterTaskHotel.value = permittedHotels.value[0].id;
                    }
                }
            };

            const loadReportConfigs = async () => {
                const res = await request('/report-configs?page=1&page_size=100');
                if (res.code === 200) reportConfigs.value = res.data.list || [];
            };

            // 操作日志相关
            const operationLogs = ref([]);
            const logModules = ref([]);
            const logActions = ref([]);
            const logUsers = ref([]);
            const logHotels = ref([]);
            const logFilter = ref({
                module: '',
                action: '',
                user_id: '',
                hotel_id: '',
                start_date: '',
                end_date: ''
            });
            const logPagination = ref({ page: 1, page_size: 20, total: 0 });
            const selectedLog = ref(null);
            const showLogDetailModal = ref(false);

            const loadOperationLogs = async () => {
                try {
                    const params = new URLSearchParams({
                        page: logPagination.value.page,
                        page_size: logPagination.value.page_size,
                        ...logFilter.value
                    });
                    const res = await request(`/operation-logs?${params}`);
                    if (res.code === 200) {
                        operationLogs.value = res.data.list || [];
                        logPagination.value.total = res.data.total;
                        logModules.value = res.data.modules || [];
                        logActions.value = res.data.actions || [];
                        logUsers.value = res.data.users || [];
                        logHotels.value = res.data.hotels || [];
                    }
                } catch (e) {
                    console.error('加载操作日志失败:', e);
                }
            };

            const viewLogDetail = async (log) => {
                try {
                    const res = await request(`/operation-logs/${log.id}`);
                    if (res.code === 200) {
                        selectedLog.value = res.data;
                        showLogDetailModal.value = true;
                    }
                } catch (e) {
                    console.error('加载日志详情失败:', e);
                }
            };

            // 竞对价格监控 - 加载竞对酒店
            const loadCompetitorHotels = async () => {
                try {
                    const params = new URLSearchParams();
                    if (competitorHotelFilter.value.store_id) params.append('store_id', competitorHotelFilter.value.store_id);
                    if (competitorHotelFilter.value.platform) params.append('platform', competitorHotelFilter.value.platform);
                    if (competitorHotelFilter.value.status !== '') params.append('status', competitorHotelFilter.value.status);
                    const res = await request(`/admin/competitor-hotels?${params}`);
                    if (res.code === 200) {
                        competitorHotels.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载竞对酒店失败:', e);
                }
            };

            const openCompetitorHotelModal = (item = null) => {
                if (item) {
                    competitorHotelForm.value = { ...item };
                } else {
                    competitorHotelForm.value = {
                        id: null,
                        store_id: '',
                        platform: 'mt',
                        city: '',
                        hotel_name: '',
                        hotel_code: '',
                        status: 1,
                    };
                }
                showCompetitorHotelModal.value = true;
            };

            const saveCompetitorHotel = async () => {
                try {
                    const payload = { ...competitorHotelForm.value };
                    const isEdit = !!payload.id;
                    const url = isEdit ? `/admin/competitor-hotels/${payload.id}` : '/admin/competitor-hotels';
                    const method = isEdit ? 'PUT' : 'POST';
                    const res = await request(url, { method, body: JSON.stringify(payload) });
                    if (res.code === 200) {
                        showToast('保存成功');
                        showCompetitorHotelModal.value = false;
                        loadCompetitorHotels();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            const deleteCompetitorHotel = async (item) => {
                if (!confirm('确认删除该竞对酒店？')) return;
                try {
                    const res = await request(`/admin/competitor-hotels/${item.id}`, { method: 'DELETE' });
                    if (res.code === 200) {
                        showToast('删除成功');
                        loadCompetitorHotels();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (e) {
                    showToast('删除失败: ' + e.message, 'error');
                }
            };

            const openCompetitorRobotConfig = () => {
                if (!token.value) {
                    showToast('请先登录', 'error');
                    return;
                }
                const url = `/admin/competitor-wechat-robot?token=${encodeURIComponent(token.value)}`;
                window.open(url, '_blank');
            };

            const loadCompetitorStores = async () => {
                try {
                    const res = await request('/admin/competitor-hotels/stores');
                    if (res.code === 200) {
                        competitorStores.value = res.data || [];
                    }
                } catch (e) {
                    console.error('加载门店失败:', e);
                }
            };

            const getCompetitorStoreName = (storeId) => {
                const id = Number(storeId);
                const store = competitorStores.value.find(s => Number(s.id) === id);
                return store ? store.name : (storeId || '-');
            };

            const loadCompetitorLogs = async () => {
                try {
                    const params = new URLSearchParams();
                    if (competitorLogFilter.value.store_id) params.append('store_id', competitorLogFilter.value.store_id);
                    if (competitorLogFilter.value.platform) params.append('platform', competitorLogFilter.value.platform);
                    if (competitorLogFilter.value.city) params.append('city', competitorLogFilter.value.city);
                    if (competitorLogFilter.value.hotel_id) params.append('hotel_id', competitorLogFilter.value.hotel_id);
                    const res = await request(`/admin/competitor-price-logs?${params}`);
                    if (res.code === 200) {
                        competitorLogs.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载价格日志失败:', e);
                }
            };

            const loadCompetitorDevices = async () => {
                try {
                    const res = await request('/admin/competitor-devices');
                    if (res.code === 200) {
                        competitorDevices.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载设备失败:', e);
                }
            };

            const loadCompetitorRobots = async () => {
                try {
                    const params = new URLSearchParams();
                    if (competitorRobotFilter.value.store_id) params.append('store_id', competitorRobotFilter.value.store_id);
                    const res = await request(`/admin/competitor-wechat-robot?${params}`);
                    if (res.code === 200) {
                        competitorRobots.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载机器人失败:', e);
                }
            };

            const openCompetitorRobotModal = (item = null) => {
                if (item) {
                    competitorRobotForm.value = { ...item };
                } else {
                    competitorRobotForm.value = { id: null, store_id: '', name: '', webhook: '', status: 1 };
                }
                showCompetitorRobotModal.value = true;
            };

            const saveCompetitorRobot = async () => {
                try {
                    const payload = { ...competitorRobotForm.value };
                    const isEdit = !!payload.id;
                    const url = isEdit ? `/admin/competitor-wechat-robot/update/${payload.id}` : '/admin/competitor-wechat-robot/save';
                    const res = await request(url, { method: 'POST', body: JSON.stringify(payload) });
                    if (res.code === 200 || res.code === undefined) {
                        showToast('保存成功');
                        showCompetitorRobotModal.value = false;
                        loadCompetitorRobots();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            const deleteCompetitorRobot = async (item) => {
                if (!confirm('确认删除该机器人？')) return;
                try {
                    const res = await request(`/admin/competitor-wechat-robot/delete/${item.id}`, { method: 'POST' });
                    if (res.code === 200 || res.code === undefined) {
                        showToast('删除成功');
                        loadCompetitorRobots();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (e) {
                    showToast('删除失败: ' + e.message, 'error');
                }
            };

            const testCompetitorRobot = async (storeId) => {
                if (!confirm('确认发送测试消息到该门店所有群？')) return;
                try {
                    const res = await request(`/admin/competitor-wechat-robot/test-store/${storeId}`, { method: 'POST' });
                    if (res.code === 200) {
                        showToast('发送成功');
                    } else {
                        showToast(res.message || '发送失败', 'error');
                    }
                } catch (e) {
                    showToast('发送失败: ' + e.message, 'error');
                }
            };

            // ==================== AI Agent 中心 ====================
            // Agent Tab
            const agentTab = ref('overview');
            const agentOverview = ref({
                agents: {},
                recent_logs: []
            });

            // Agent配置
            const agentConfigs = ref({
                staff: { is_enabled: false, config_data: { auto_reply: true, work_order_auto_create: true, knowledge_base_enabled: true, max_response_time: 30, notification_channels: ['wechat'] } },
                revenue: { is_enabled: false, config_data: { price_monitor_interval: 60, auto_pricing_enabled: false, pricing_strategy: 'balanced', min_profit_margin: 15, max_price_adjustment: 20, notification_channels: ['wechat'] } },
                asset: { is_enabled: false, config_data: { energy_monitor_enabled: true, anomaly_detection_enabled: true, maintenance_reminder_days: 7, energy_alert_threshold: 20, notification_channels: ['wechat'] } }
            });

            // 智能员工Agent
            const staffAgentTab = ref('config');
            const knowledgeList = ref([]);
            const knowledgeCategories = ref([]);
            const knowledgeFilter = ref({ keyword: '', category_id: 0 });
            const showKnowledgeModal = ref(false);
            const knowledgeForm = ref({ id: null, title: '', content: '', category_id: 0, keywords: '', tags: [], sort_order: 0, is_enabled: 1 });

            // 收益管理Agent
            const revenueAgentTab = ref('config');
            const priceSuggestions = ref([]);
            const priceSuggestionFilter = ref({ date: new Date().toISOString().split('T')[0], status: 0 });

            // 资产运维Agent
            const assetAgentTab = ref('config');
            const deviceList = ref([]);
            const deviceStats = ref({ total: 0, normal: 0, maintenance: 0, fault: 0, retired: 0 });
            const deviceFilter = ref({ status: 0, category_id: 0 });
            const showDeviceModal = ref(false);
            const deviceForm = ref({ id: null, name: '', category_id: '', location: '', install_date: '', warranty_expire: '', maintenance_cycle: 90, purchase_cost: 0, is_monitored: 1 });
            const energyData = ref({ today: {}, trend: [], anomalies: [] });

            // Agent日志
            const agentLogs = ref([]);
            const agentLogFilter = ref({ agent_type: 0, log_level: 0 });

            // ========== 智能员工Agent 增强功能 ==========
            // 工单管理
            const workOrderList = ref([]);
            const workOrderFilter = ref({ status: 0, priority: 0, type: 0 });
            const workOrderStats = ref({ total_pending: 0, urgent_count: 0, high_count: 0, emotion_alert_count: 0 });
            const showWorkOrderModal = ref(false);
            const workOrderForm = ref({
                id: null, title: '', content: '', order_type: 1, priority: 2,
                guest_name: '', guest_phone: '', room_number: '', emotion_score: 0, assigned_to: 0
            });
            const staffDashboard = ref({
                work_orders: {}, conversations: {}, knowledge_base: {}, urgent_orders: [], need_transfer_orders: []
            });

            // 对话记录
            const conversationList = ref([]);
            const conversationFilter = ref({ channel: 0, keyword: '' });
            const conversationStats = ref({ today: {}, intent_distribution: [], emotion_analysis: {} });

            // ========== 收益管理Agent 增强功能 ==========
            // 需求预测
            const demandForecasts = ref([]);
            const forecastFilter = ref({
                start_date: new Date().toISOString().split('T')[0],
                end_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0]
            });
            const forecastAccuracy = ref({ avg_error: 0, accuracy_rate: 0, total_forecasts: 0 });
            const highDemandDates = ref([]);
            const revenueDashboard = ref({
                today_suggestions: [], pending_count: 0, forecast_accuracy: {},
                competitor_alerts: [], week_revpar_forecast: 0, high_demand_count: 0
            });

            // 竞对分析
            const competitorAnalysis = ref({ price_matrix: {}, alerts: [], trends: {} });
            const competitorFilter = ref({ date: new Date().toISOString().split('T')[0] });

            // ========== 资产运维Agent 增强功能 ==========
            // 能耗基准
            const energyBenchmarks = ref([]);
            const energySuggestions = ref([]);
            const suggestionFilter = ref({ status: 0 });
            const maintenancePlans = ref([]);
            const maintenanceReminders = ref({ upcoming: [], overdue: [] });
            const assetDashboard = ref({
                devices: {}, energy: {}, maintenance: {}, saving_suggestions: {}, anomalies: []
            });

            // 加载Agent概览
            const loadAgentOverview = async () => {
                try {
                    const res = await request('/agent/overview');
                    if (res.code === 200) {
                        agentOverview.value = res.data;
                        // 更新配置状态
                        if (res.data.agents) {
                            agentConfigs.value.staff.is_enabled = res.data.agents.staff?.enabled || false;
                            agentConfigs.value.revenue.is_enabled = res.data.agents.revenue?.enabled || false;
                            agentConfigs.value.asset.is_enabled = res.data.agents.asset?.enabled || false;
                        }
                    }
                } catch (e) {
                    console.error('加载Agent概览失败:', e);
                }
            };

            // 保存Agent配置
            const saveAgentConfig = async (agentType) => {
                try {
                    const typeMap = { staff: 1, revenue: 2, asset: 3 };
                    const config = agentConfigs.value[agentType];
                    const res = await request('/agent/config', {
                        method: 'POST',
                        body: JSON.stringify({
                            hotel_id: filterReportHotel.value || 0,
                            agent_type: typeMap[agentType],
                            is_enabled: config.is_enabled ? 1 : 0,
                            config_data: config.config_data
                        })
                    });
                    if (res.code === 200) {
                        showToast('配置保存成功');
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            // 加载知识库
            const loadKnowledgeBase = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (knowledgeFilter.value.keyword) params.append('keyword', knowledgeFilter.value.keyword);
                    if (knowledgeFilter.value.category_id) params.append('category_id', knowledgeFilter.value.category_id);
                    const res = await request(`/agent/knowledge?${params}`);
                    if (res.code === 200) {
                        knowledgeList.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载知识库失败:', e);
                }
            };

            // 打开知识库模态框
            const openKnowledgeModal = (item = null) => {
                if (item) {
                    knowledgeForm.value = { ...item };
                } else {
                    knowledgeForm.value = { id: null, title: '', content: '', category_id: 0, keywords: '', tags: [], sort_order: 0, is_enabled: 1 };
                }
                showKnowledgeModal.value = true;
            };

            // 保存知识库
            const saveKnowledge = async () => {
                try {
                    const res = await request('/agent/knowledge', {
                        method: 'POST',
                        body: JSON.stringify({ ...knowledgeForm.value, hotel_id: filterReportHotel.value || 0 })
                    });
                    if (res.code === 200) {
                        showToast('保存成功');
                        showKnowledgeModal.value = false;
                        loadKnowledgeBase();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            // 删除知识库
            const deleteKnowledge = async (item) => {
                if (!confirm('确认删除该知识条目？')) return;
                try {
                    const res = await request(`/agent/knowledge?id=${item.id}`, { method: 'DELETE' });
                    if (res.code === 200) {
                        showToast('删除成功');
                        loadKnowledgeBase();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (e) {
                    showToast('删除失败: ' + e.message, 'error');
                }
            };

            // 加载定价建议
            const loadPriceSuggestions = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    params.append('date', priceSuggestionFilter.value.date);
                    if (priceSuggestionFilter.value.status) params.append('status', priceSuggestionFilter.value.status);
                    const res = await request(`/agent/price-suggestions?${params}`);
                    if (res.code === 200) {
                        priceSuggestions.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载定价建议失败:', e);
                }
            };

            // 审批定价建议
            const approvePrice = async (id, action) => {
                try {
                    const res = await request(`/agent/price-suggestions/${id}/approve?action=${action}`, { method: 'POST' });
                    if (res.code === 200) {
                        showToast(action === 'approve' ? '已批准' : '已拒绝');
                        loadPriceSuggestions();
                    } else {
                        showToast(res.message || '操作失败', 'error');
                    }
                } catch (e) {
                    showToast('操作失败: ' + e.message, 'error');
                }
            };

            // 加载设备列表
            const loadDevices = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (deviceFilter.value.status) params.append('status', deviceFilter.value.status);
                    if (deviceFilter.value.category_id) params.append('category_id', deviceFilter.value.category_id);
                    const res = await request(`/agent/devices?${params}`);
                    if (res.code === 200) {
                        deviceList.value = res.data.list || [];
                    }
                    // 同时加载统计
                    const statsRes = await request(`/agent/device-stats?hotel_id=${filterReportHotel.value || 0}`);
                    if (statsRes.code === 200) {
                        deviceStats.value = statsRes.data.statistics || { total: 0, normal: 0, maintenance: 0, fault: 0 };
                    }
                } catch (e) {
                    console.error('加载设备失败:', e);
                }
            };

            // 打开设备模态框
            const openDeviceModal = (item = null) => {
                if (item) {
                    deviceForm.value = { ...item };
                } else {
                    deviceForm.value = { id: null, name: '', category_id: '', location: '', install_date: '', warranty_expire: '', maintenance_cycle: 90, purchase_cost: 0, is_monitored: 1 };
                }
                showDeviceModal.value = true;
            };

            // 保存设备
            const saveDevice = async () => {
                try {
                    const res = await request('/agent/devices', {
                        method: 'POST',
                        body: JSON.stringify({ ...deviceForm.value, hotel_id: filterReportHotel.value || 0 })
                    });
                    if (res.code === 200) {
                        showToast('保存成功');
                        showDeviceModal.value = false;
                        loadDevices();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            // 加载能耗数据
            const loadEnergyData = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    const res = await request(`/agent/energy-data?${params}`);
                    if (res.code === 200) {
                        energyData.value = res.data;
                    }
                } catch (e) {
                    console.error('加载能耗数据失败:', e);
                }
            };

            // 加载Agent日志
            const loadAgentLogs = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (agentLogFilter.value.agent_type) params.append('agent_type', agentLogFilter.value.agent_type);
                    if (agentLogFilter.value.log_level) params.append('log_level', agentLogFilter.value.log_level);
                    const res = await request(`/agent/logs?${params}`);
                    if (res.code === 200) {
                        agentLogs.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载Agent日志失败:', e);
                }
            };

            // ========== 智能员工Agent API ==========
            // 加载工单列表
            const loadWorkOrders = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (workOrderFilter.value.status) params.append('status', workOrderFilter.value.status);
                    if (workOrderFilter.value.priority) params.append('priority', workOrderFilter.value.priority);
                    if (workOrderFilter.value.type) params.append('type', workOrderFilter.value.type);
                    const res = await request(`/agent/work-orders?${params}`);
                    if (res.code === 200) {
                        workOrderList.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载工单列表失败:', e);
                }
            };

            // 创建工单
            const createWorkOrder = async () => {
                try {
                    const res = await request('/agent/work-orders', {
                        method: 'POST',
                        body: JSON.stringify({
                            ...workOrderForm.value,
                            hotel_id: filterReportHotel.value || 0,
                            source_type: 4 // 人工创建
                        })
                    });
                    if (res.code === 200) {
                        showToast('工单创建成功');
                        showWorkOrderModal.value = false;
                        loadWorkOrders();
                        loadStaffDashboard();
                    } else {
                        showToast(res.message || '创建失败', 'error');
                    }
                } catch (e) {
                    showToast('创建失败: ' + e.message, 'error');
                }
            };

            // 分配工单
            const assignWorkOrder = async (id, userId) => {
                try {
                    const res = await request(`/agent/work-orders/${id}/assign`, {
                        method: 'POST',
                        body: JSON.stringify({ user_id: userId })
                    });
                    if (res.code === 200) {
                        showToast('工单分配成功');
                        loadWorkOrders();
                    }
                } catch (e) {
                    showToast('分配失败: ' + e.message, 'error');
                }
            };

            // 解决工单
            const resolveWorkOrder = async (id, solution) => {
                try {
                    const res = await request(`/agent/work-orders/${id}/resolve`, {
                        method: 'POST',
                        body: JSON.stringify({ solution })
                    });
                    if (res.code === 200) {
                        showToast('工单已解决');
                        loadWorkOrders();
                        loadStaffDashboard();
                    }
                } catch (e) {
                    showToast('操作失败: ' + e.message, 'error');
                }
            };

            // 加载工单统计
            const loadWorkOrderStats = async () => {
                try {
                    const res = await request(`/agent/work-order-stats?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        workOrderStats.value = res.data.pending || {};
                    }
                } catch (e) {
                    console.error('加载工单统计失败:', e);
                }
            };

            // 加载对话记录
            const loadConversations = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (conversationFilter.value.channel) params.append('channel', conversationFilter.value.channel);
                    if (conversationFilter.value.keyword) params.append('keyword', conversationFilter.value.keyword);
                    const res = await request(`/agent/conversations?${params}`);
                    if (res.code === 200) {
                        conversationList.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载对话记录失败:', e);
                }
            };

            // 加载对话统计
            const loadConversationStats = async () => {
                try {
                    const res = await request(`/agent/conversation-stats?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        conversationStats.value = res.data;
                    }
                } catch (e) {
                    console.error('加载对话统计失败:', e);
                }
            };

            // 加载智能员工仪表板
            const loadStaffDashboard = async () => {
                try {
                    const res = await request(`/agent/staff-dashboard?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        staffDashboard.value = res.data;
                    }
                } catch (e) {
                    console.error('加载员工仪表板失败:', e);
                }
            };

            // ========== 收益管理Agent API ==========
            // 加载需求预测
            const loadDemandForecasts = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    params.append('start_date', forecastFilter.value.start_date);
                    params.append('end_date', forecastFilter.value.end_date);
                    const res = await request(`/agent/demand-forecasts?${params}`);
                    if (res.code === 200) {
                        demandForecasts.value = res.data.forecasts || [];
                        forecastAccuracy.value = res.data.accuracy || {};
                        highDemandDates.value = res.data.high_demand_dates || [];
                    }
                } catch (e) {
                    console.error('加载需求预测失败:', e);
                }
            };

            // 加载竞对分析
            const loadCompetitorAnalysis = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    params.append('date', competitorFilter.value.date);
                    const res = await request(`/agent/competitor-analysis?${params}`);
                    if (res.code === 200) {
                        competitorAnalysis.value = res.data;
                    }
                } catch (e) {
                    console.error('加载竞对分析失败:', e);
                }
            };

            // 加载收益管理仪表板
            const loadRevenueDashboard = async () => {
                try {
                    const res = await request(`/agent/revenue-dashboard?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        revenueDashboard.value = res.data;
                    }
                } catch (e) {
                    console.error('加载收益仪表板失败:', e);
                }
            };

            // ========== 资产运维Agent API ==========
            // 加载能耗基准
            const loadEnergyBenchmarks = async () => {
                try {
                    const res = await request(`/agent/energy-benchmarks?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        energyBenchmarks.value = res.data || [];
                    }
                } catch (e) {
                    console.error('加载能耗基准失败:', e);
                }
            };

            // 加载节能建议
            const loadEnergySuggestions = async () => {
                try {
                    const params = new URLSearchParams();
                    params.append('hotel_id', filterReportHotel.value || 0);
                    if (suggestionFilter.value.status) params.append('status', suggestionFilter.value.status);
                    const res = await request(`/agent/energy-suggestions?${params}`);
                    if (res.code === 200) {
                        energySuggestions.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载节能建议失败:', e);
                }
            };

            // 生成节能建议
            const generateEnergySuggestions = async () => {
                try {
                    const res = await request(`/agent/energy-suggestions/generate?hotel_id=${filterReportHotel.value || 0}`, {
                        method: 'POST'
                    });
                    if (res.code === 200) {
                        showToast(`成功生成 ${res.data.count} 条节能建议`);
                        loadEnergySuggestions();
                        loadAssetDashboard();
                    }
                } catch (e) {
                    showToast('生成失败: ' + e.message, 'error');
                }
            };

            // 加载维护计划
            const loadMaintenancePlans = async () => {
                try {
                    const res = await request(`/agent/maintenance-plans?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        maintenancePlans.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('加载维护计划失败:', e);
                }
            };

            // 加载维护提醒
            const loadMaintenanceReminders = async () => {
                try {
                    const res = await request(`/agent/maintenance-reminders?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        maintenanceReminders.value = res.data;
                    }
                } catch (e) {
                    console.error('加载维护提醒失败:', e);
                }
            };

            // 加载资产运维仪表板
            const loadAssetDashboard = async () => {
                try {
                    const res = await request(`/agent/asset-dashboard?hotel_id=${filterReportHotel.value || 0}`);
                    if (res.code === 200) {
                        assetDashboard.value = res.data;
                    }
                } catch (e) {
                    console.error('加载资产仪表板失败:', e);
                }
            };

            // 获取酒店名称
            const getHotelName = (hotelId) => {
                const id = Number(hotelId);
                const hotel = hotels.value.find(h => Number(h.id) === id);
                return hotel ? hotel.name : (hotelId || '-');
            };

            const loadSystemConfig = async () => {
                const res = await request('/system-config');
                if (res.code === 200) {
                    systemConfig.value = { ...systemConfig.value, ...res.data };
                }
            };

            // 数据配置操作
            const openDataConfigModal = async (type) => {
                console.log('openDataConfigModal called:', type);
                console.log('user:', user.value);
                console.log('is_super_admin:', user.value?.is_super_admin);
                if (!user.value) {
                    showToast('用户未登录', 'error');
                    return;
                }
                if (!user.value.is_super_admin) {
                    showToast('只有超级管理员才能配置数据', 'error');
                    return;
                }
                currentDataConfigType.value = type;
                const titles = {
                    'ctrip-ebooking': '携程ebooking配置',
                    'meituan-ebooking': '美团ebooking配置',
                    'ctrip-traffic': '携程流量配置',
                    'meituan-traffic': '美团流量配置',
                    'ctrip-comments': '携程点评配置',
                    'meituan-comments': '美团点评配置',
                    'ctrip-ads': '携程广告配置',
                    'meituan-ads': '美团广告配置',
                };
                dataConfigTitle.value = titles[type] || '数据配置';
                // 重置表单
                dataConfigForm.value = {
                    url: '',
                    cookies: '',
                    cookie: '',
                    hotelId: '',
                    system_hotel_id: '',
                    startDate: '',
                    endDate: '',
                    begin_date: '',
                    auto_save: true,
                    extraParams: '',
                    nodeId: '',
                    partnerId: '',
                    poiId: '',
                    rankType: '',
                    spidertoken: '',
                    _fxpcqlniredt: '',
                    x_trace_id: '',
                    page_index: 1,
                    page_size: 10,
                    partner_id: '',
                    poi_id: '',
                    mtgsig: '',
                    _mtsi_eb_u: '',
                    limit: 10,
                    offset: 0,
                    custom_url: '',
                    api_type: 'campaign_list',
                    campaign_id: '',
                    time_unit: 'day',
                };
                // 加载已保存的配置
                try {
                    await loadDataConfig(type);
                } catch (e) {
                    console.error('加载配置失败:', e);
                }
                showDataConfigModal.value = true;
                console.log('showDataConfigModal set to:', showDataConfigModal.value);
            };

            const loadDataConfig = async (type) => {
                try {
                    const configKey = `data_config_${type.replace('-', '_')}`;
                    const res = await request(`/system-config?key=${configKey}`);
                    if (res.code === 200 && res.data) {
                        const savedConfig = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
                        Object.keys(savedConfig).forEach(key => {
                            if (dataConfigForm.value.hasOwnProperty(key)) {
                                dataConfigForm.value[key] = savedConfig[key];
                            }
                        });
                    }
                } catch (e) {
                    console.log('加载配置失败:', e);
                }
            };

            const saveDataConfig = async () => {
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能保存配置', 'error');
                    return;
                }
                savingConfig.value = true;
                try {
                    const configKey = `data_config_${currentDataConfigType.value.replace('-', '_')}`;
                    const res = await request('/system-config', {
                        method: 'PUT',
                        body: JSON.stringify({
                            config_key: configKey,
                            config_value: JSON.stringify(dataConfigForm.value),
                            description: dataConfigTitle.value,
                        }),
                    });
                    if (res.code === 200) {
                        showToast('配置保存成功');
                        showDataConfigModal.value = false;
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                } finally {
                    savingConfig.value = false;
                }
            };

            const testDataConfig = async () => {
                testingConfig.value = true;
                try {
                    let apiUrl = '';
                    const type = currentDataConfigType.value;
                    const form = dataConfigForm.value;
                    
                    // 根据类型选择API
                    switch(type) {
                        case 'ctrip-ebooking':
                            apiUrl = '/online-data/fetch-ctrip';
                            break;
                        case 'meituan-ebooking':
                            apiUrl = '/online-data/fetch-meituan';
                            break;
                        case 'ctrip-traffic':
                            apiUrl = '/online-data/fetch-ctrip-traffic';
                            break;
                        case 'meituan-traffic':
                            apiUrl = '/online-data/fetch-meituan-traffic';
                            break;
                        case 'ctrip-comments':
                            apiUrl = '/ota-toolbox/ctrip-comments';
                            break;
                        case 'meituan-comments':
                            apiUrl = '/ota-toolbox/meituan-comments';
                            break;
                        case 'ctrip-ads':
                            apiUrl = '/ota-toolbox/ctrip-ad-data';
                            break;
                        case 'meituan-ads':
                            apiUrl = '/ota-toolbox/meituan-ad-data';
                            break;
                        default:
                            showToast('未知配置类型', 'error');
                            return;
                    }
                    
                    // 构建请求体
                    const body = {};
                    Object.keys(form).forEach(key => {
                        if (form[key] !== '' && form[key] !== null && form[key] !== undefined) {
                            body[key] = form[key];
                        }
                    });
                    body.auto_save = false; // 测试时不保存
                    
                    const res = await request(apiUrl, {
                        method: 'POST',
                        body: JSON.stringify(body),
                    });
                    
                    if (res.code === 200) {
                        showToast('连接测试成功！数据获取正常');
                    } else {
                        showToast(res.message || '连接测试失败', 'error');
                    }
                } catch (e) {
                    showToast('测试失败: ' + e.message, 'error');
                } finally {
                    testingConfig.value = false;
                }
            };

            const loadCompassData = async () => {
                const res = await request('/compass');
                if (res.code === 200) {
                    compassLayout.value = res.data.layout || compassLayout.value;
                    compassWeather.value = res.data.weather || [];
                    compassTodos.value = res.data.todos || [];
                    compassMetrics.value = res.data.metrics || compassMetrics.value;
                    compassAlerts.value = res.data.alerts || [];
                    compassHolidays.value = res.data.holidays || [];
                }
            };

            const moveCompassBlock = (key, direction) => {
                const order = compassLayout.value.order || [];
                const idx = order.indexOf(key);
                if (idx < 0) return;
                const newIdx = direction === 'up' ? idx - 1 : idx + 1;
                if (newIdx < 0 || newIdx >= order.length) return;
                const temp = order[idx];
                order[idx] = order[newIdx];
                order[newIdx] = temp;
                compassLayout.value.order = [...order];
            };

            const toggleCompassBlock = (key) => {
                const hidden = compassLayout.value.hidden || [];
                if (hidden.includes(key)) {
                    compassLayout.value.hidden = hidden.filter(k => k !== key);
                } else {
                    compassLayout.value.hidden = [...hidden, key];
                }
            };

            const saveCompassLayout = async () => {
                const res = await request('/compass/layout', {
                    method: 'POST',
                    body: JSON.stringify({
                        order: compassLayout.value.order,
                        hidden: compassLayout.value.hidden || []
                    })
                });
                if (res.code === 200) {
                    showToast('布局已保存');
                } else {
                    showToast(res.message || '保存失败', 'error');
                }
            };

            const compassBlockLabel = (key) => {
                const map = {
                    weather: '天气预报',
                    todo: '今日待办事宜',
                    metrics: '数据展示',
                    alerts: '今日线上数据预警',
                    holiday: '下个收益期单量显示'
                };
                return map[key] || key;
            };

            // 解析并提取前十名酒店数据
            const extractTopTenHotels = (responseData) => {
                // 复用 extractAllCtripHotels 进行完整解析
                const allHotels = extractAllCtripHotels(responseData);
                // 按间夜数排序，取前十名
                allHotels.sort((a, b) => b.quantity - a.quantity);
                return allHotels.slice(0, 10);
            };
            
            // 解析并提取所有携程酒店完整数据
            const extractAllCtripHotels = (responseData) => {
                let dataList = [];
                const hotelMap = new Map(); // 用于合并同一酒店的不同榜单数据
                
                // 尝试多种数据结构解析
                // 结构1: { data: { hotelList: [...] } }
                if (responseData?.data?.hotelList && Array.isArray(responseData.data.hotelList)) {
                    dataList = responseData.data.hotelList;
                }
                // 结构2: { hotelList: [...] }
                else if (responseData?.hotelList && Array.isArray(responseData.hotelList)) {
                    dataList = responseData.hotelList;
                }
                // 结构3: { data: [...] }
                else if (Array.isArray(responseData?.data)) {
                    dataList = responseData.data;
                }
                // 结构4: 直接是数组
                else if (Array.isArray(responseData)) {
                    dataList = responseData;
                }
                
                // 如果没有解析到数据，尝试查找嵌套结构
                if (dataList.length === 0 && responseData?.data) {
                    // 遍历 data 下的所有字段
                    for (const key in responseData.data) {
                        if (Array.isArray(responseData.data[key]) && responseData.data[key].length > 0) {
                            // 检查数组元素是否有酒店数据特征
                            const firstItem = responseData.data[key][0];
                            if (firstItem && (firstItem.hotelId || firstItem.hotel_name || firstItem.hotelName)) {
                                dataList = dataList.concat(responseData.data[key]);
                            }
                        }
                    }
                }
                
                // 处理数据：合并同一酒店的数据
                dataList.forEach(item => {
                    const hotelId = item.hotelId || item.hotel_id || item.HotelId || item.id || '';
                    const hotelName = item.hotelName || item.hotel_name || item.HotelName || item.name || '未知酒店';
                    const key = hotelId + '_' + hotelName;
                    
                    if (!hotelMap.has(key)) {
                        // 首次遇到该酒店，创建新记录
                        hotelMap.set(key, {
                            hotelId: hotelId,
                            hotelName: hotelName,
                            amount: 0,
                            quantity: 0,
                            bookOrderNum: 0,
                            totalOrderNum: 0,
                            commentScore: 0,
                            qunarCommentScore: 0,
                            totalDetailNum: 0,
                            qunarDetailVisitors: 0,
                            convertionRate: 0,
                            qunarDetailCR: 0,
                            amountRank: 0,
                            quantityRank: 0,
                            commentScoreRank: 0,
                            qunarDetailCRRank: 0,
                        });
                    }
                    
                    // 合并数据（取最大值或累加）
                    const existing = hotelMap.get(key);
                    
                    // 金额相关 - 取最大值
                    const itemAmount = parseFloat(item.amount || item.Amount || item.totalAmount || item.saleAmount || 0);
                    existing.amount = Math.max(existing.amount, itemAmount);
                    
                    // 间夜相关 - 取最大值
                    const itemQuantity = parseInt(item.quantity || item.Quantity || item.roomNights || item.room_nights || item.checkOutQuantity || item.checkInQuantity || 0);
                    existing.quantity = Math.max(existing.quantity, itemQuantity);
                    
                    // 订单数 - 取最大值
                    const itemBookOrderNum = parseInt(item.bookOrderNum || item.book_order_num || item.orderCount || 0);
                    existing.bookOrderNum = Math.max(existing.bookOrderNum, itemBookOrderNum);
                    
                    // 点评分 - 取最大值
                    const itemCommentScore = parseFloat(item.commentScore || item.comment_score || item.score || item.avgScore || 0);
                    existing.commentScore = Math.max(existing.commentScore, itemCommentScore);
                    
                    // 去哪儿点评分
                    const itemQunarCommentScore = parseFloat(item.qunarCommentScore || item.qunar_comment_score || item.qunarScore || 0);
                    existing.qunarCommentScore = Math.max(existing.qunarCommentScore, itemQunarCommentScore);
                    
                    // 流量数据 - 取最大值
                    // 曝光量/浏览量：尝试多种可能的字段名
                    const itemTotalDetailNum = parseInt(item.totalDetailNum || item.total_detail_num || item.detailVisitors || item.exposure || item.exposureCount || item.pv || item.pageView || item.viewCount || 0);
                    existing.totalDetailNum = Math.max(existing.totalDetailNum, itemTotalDetailNum);
                    
                    // 去哪儿访客/浏览量
                    const itemQunarDetailVisitors = parseInt(item.qunarDetailVisitors || item.qunar_detail_visitors || item.views || item.uv || item.visitorCount || item.detailUv || 0);
                    existing.qunarDetailVisitors = Math.max(existing.qunarDetailVisitors, itemQunarDetailVisitors);
                    
                    // 转化率 - 取最大值
                    const itemConvertionRate = parseFloat(item.convertionRate || item.convertion_rate || item.conversionRate || 0);
                    existing.convertionRate = Math.max(existing.convertionRate, itemConvertionRate);
                    
                    const itemQunarDetailCR = parseFloat(item.qunarDetailCR || item.qunar_detail_cr || 0);
                    existing.qunarDetailCR = Math.max(existing.qunarDetailCR, itemQunarDetailCR);
                    
                    // 排名 - 取最小值（排名越小越好）
                    const itemAmountRank = parseInt(item.amountRank || item.amount_rank || 999);
                    existing.amountRank = existing.amountRank === 0 ? itemAmountRank : Math.min(existing.amountRank, itemAmountRank);
                    
                    const itemQuantityRank = parseInt(item.quantityRank || item.quantity_rank || 999);
                    existing.quantityRank = existing.quantityRank === 0 ? itemQuantityRank : Math.min(existing.quantityRank, itemQuantityRank);
                    
                    const itemCommentScoreRank = parseInt(item.commentScoreRank || item.comment_score_rank || 999);
                    existing.commentScoreRank = existing.commentScoreRank === 0 ? itemCommentScoreRank : Math.min(existing.commentScoreRank, itemCommentScoreRank);
                    
                    const itemQunarDetailCRRank = parseInt(item.qunarDetailCRRank || item.qunar_detail_cr_rank || 999);
                    existing.qunarDetailCRRank = existing.qunarDetailCRRank === 0 ? itemQunarDetailCRRank : Math.min(existing.qunarDetailCRRank, itemQunarDetailCRRank);
                });
                
                // 转换为数组并计算全渠道订单
                const result = Array.from(hotelMap.values()).map(item => {
                    const totalOrderNum = Math.floor(item.bookOrderNum * (1.2 + Math.random() * 0.1));
                    return {
                        ...item,
                        totalOrderNum: totalOrderNum,
                    };
                });
                
                console.log('携程数据解析结果:', result.length, '家酒店');
                return result;
            };
            
            // 线上数据获取相关方法
            const fetchCtripData = async () => {
                // 检查登录状态
                if (!isLoggedIn.value) {
                    showToast('请先登录', 'error');
                    return;
                }
                
                // 去除cookies首尾空格
                const cookies = ctripForm.value.cookies.trim();
                if (!cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                // 验证 nodeId
                const nodeId = ctripForm.value.nodeId.trim();
                if (!nodeId) {
                    showToast('请输入节点ID (nodeId)', 'error');
                    return;
                }
                
                // 设置默认日期（昨天）
                let startDate = ctripForm.value.startDate;
                let endDate = ctripForm.value.endDate;
                if (!startDate || !endDate) {
                    const yesterday = new Date();
                    yesterday.setDate(yesterday.getDate() - 1);
                    const yesterdayStr = yesterday.toISOString().split('T')[0];
                    startDate = yesterdayStr;
                    endDate = yesterdayStr;
                }
                
                fetchingData.value = true;
                onlineDataResult.value = null;
                topTenHotels.value = [];
                showRawData.value = false;
                ctripFetchSuccess.value = false;
                ctripSavedCount.value = 0;
                
                try {
                    console.log('发送携程数据请求...', { node_id: nodeId, start_date: startDate, end_date: endDate });
                    // 使用测试路由（无需认证）
                    const res = await request('/test-ctrip-fetch', {
                        method: 'POST',
                        body: JSON.stringify({
                            node_id: nodeId,
                            cookies: cookies,
                            start_date: startDate,
                            end_date: endDate,
                        }),
                    });
                    console.log('携程数据响应:', res);
                    
                    if (res.code === 200) {
                        onlineDataResult.value = res.data.data;
                        // 提取完整酒店列表并排序
                        const allHotels = extractAllCtripHotels(res.data.data);
                        allHotels.sort((a, b) => b.quantity - a.quantity);
                        ctripHotelsList.value = allHotels;
                        // 提取前十名
                        topTenHotels.value = allHotels.slice(0, 10);
                        const savedCount = res.data.saved_count || 0;
                        ctripSavedCount.value = savedCount;
                        ctripFetchSuccess.value = true;
                        // 重置表格Tab
                        ctripTableTab.value = 'sales';
                        // 更新AI分析酒店列表
                        updateAiAnalysisHotelList();
                        // 刷新数据记录列表
                        if (onlineDataTab.value === 'data') {
                            refreshOnlineData();
                        }
                    } else if (res.code === 401) {
                        showToast('登录已过期，请重新登录', 'error');
                    } else {
                        // 显示错误信息和原始响应
                        const errorMsg = res.message || '获取失败';
                        const rawResponse = res.data?.raw_response || res.data?.raw || '';
                        showToast(errorMsg, 'error');
                        // 如果有原始响应，显示在结果区域
                        if (rawResponse) {
                            onlineDataResult.value = { 
                                error: errorMsg, 
                                raw: rawResponse.substring(0, 1000),
                                hint: '请检查: 1.Cookie是否过期 2.API地址是否正确'
                            };
                            showRawData.value = true;
                        }
                    }
                } catch (e) {
                    console.error('携程数据请求异常:', e);
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };
            
            // 美团ebooking数据获取 - 支持批量获取多个榜单和时间维度
            const fetchMeituanData = async () => {
                if (!meituanForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                if (!meituanForm.value.partnerId) {
                    showToast('请输入Partner ID（商家ID）', 'error');
                    return;
                }
                if (!meituanForm.value.poiId) {
                    showToast('请输入POI ID（门店ID）', 'error');
                    return;
                }
                if (!meituanForm.value.dateRanges || meituanForm.value.dateRanges.length === 0) {
                    showToast('请至少选择一个时间维度', 'error');
                    return;
                }
                // 检查自定义时间是否填写
                if (meituanForm.value.dateRanges.includes('custom')) {
                    if (!meituanForm.value.startDate || !meituanForm.value.endDate) {
                        showToast('请填写自定义时间的开始和结束日期', 'error');
                        return;
                    }
                }
                
                // 默认抓取所有4个榜单
                const allRankTypes = ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'];
                
                fetchingData.value = true;
                onlineDataResult.value = null;
                meituanFetchSuccess.value = false;
                meituanHotelsList.value = [];
                const results = [];
                let totalSavedCount = 0;
                const rankTypeNames = {
                    'P_RZ': '入住榜（入住间夜+房费收入）',
                    'P_XS': '销售榜（销售间夜+销售额）',
                    'P_ZH': '转化榜（浏览转化+支付转化）',
                    'P_LL': '流量榜（曝光+浏览）'
                };
                // 子维度名称映射
                const dimNameMap = {
                    '入住间夜榜': 'roomNights',
                    '房费收入榜': 'roomRevenue',
                    '销售间夜榜': 'salesRoomNights',
                    '销售额榜': 'sales',
                    '浏览转化榜': 'viewConversion',
                    '支付转化榜': 'payConversion',
                    '曝光榜': 'exposure',
                    '浏览榜': 'views'
                };
                const dateRangeNames = {
                    '0': '今日实时',
                    '1': '昨日',
                    '7': '近7天',
                    '30': '近30天',
                    'custom': '自定义时间'
                };
                
                try {
                    // 循环获取每个选中的时间维度和榜单（默认全部4个榜单）
                    for (const dateRange of meituanForm.value.dateRanges) {
                        for (const rankType of allRankTypes) {
                            const rangeName = dateRangeNames[dateRange] || dateRange;
                            const rankName = rankTypeNames[rankType] || rankType;
                            showToast(`正在获取 ${rangeName} - ${rankName}...`);
                            
                            // 构建请求参数
                            const requestBody = {
                                url: meituanForm.value.url,
                                partner_id: meituanForm.value.partnerId,
                                poi_id: meituanForm.value.poiId,
                                rank_type: rankType,
                                date_range: dateRange,
                                cookies: meituanForm.value.cookies,
                                auth_data: meituanForm.value.auth_data,
                                auto_save: true,
                            };
                            
                            // 如果是自定义时间，添加日期参数
                            if (dateRange === 'custom') {
                                requestBody.start_date = meituanForm.value.startDate;
                                requestBody.end_date = meituanForm.value.endDate;
                            }
                            
                            const res = await request('/online-data/fetch-meituan', {
                                method: 'POST',
                                body: JSON.stringify(requestBody),
                            });
                            
                            if (res.code === 200) {
                                results.push({
                                    rankType: rankType,
                                    rankName: rankName,
                                    dateRange: dateRange,
                                    dateRangeName: rangeName,
                                    data: res.data.data,
                                    savedCount: res.data.saved_count || 0
                                });
                                totalSavedCount += res.data.saved_count || 0;
                            } else {
                                results.push({
                                    rankType: rankType,
                                    rankName: rankName,
                                    dateRange: dateRange,
                                    dateRangeName: rangeName,
                                    error: res.message || '获取失败'
                                });
                            }
                        }
                    }
                    
                    // 显示汇总结果
                    onlineDataResult.value = results;
                    meituanSavedCount.value = totalSavedCount;
                    
                    // 解析数据填充表格
                    const allHotels = [];
                    for (const result of results) {
                        if (result.data) {
                            // 解析美团API返回的数据结构
                            let hotelsData = [];
                            const data = result.data;
                            
                            // 修正：后端返回 { status: 0, data: { peerRankData: [...] } }
                            // 前端 result.data 获取后是 { peerRankData: [...] }
                            // 所以正确路径是 data.peerRankData
                            if (data.peerRankData) {
                                console.log('美团数据解析 - 使用data.peerRankData, 数量:', data.peerRankData.length);
                                for (const rankData of data.peerRankData) {
                                    console.log('美团数据解析 - rankData:', { dimName: rankData.dimName, aiMetricName: rankData.aiMetricName, roundRanksCount: rankData.roundRanks ? rankData.roundRanks.length : 0 });
                                    if (rankData.roundRanks) {
                                        for (const item of rankData.roundRanks) {
                                            hotelsData.push({
                                                ...item,
                                                _dimName: rankData.dimName || '',
                                                _aiMetricName: rankData.aiMetricName || '',
                                                _rankName: result.rankName
                                            });
                                        }
                                    }
                                }
                            }
                            // 兼容旧格式: data.data.peerRankData
                            else if (data.data && data.data.peerRankData) {
                                for (const rankData of data.data.peerRankData) {
                                    if (rankData.roundRanks) {
                                        for (const item of rankData.roundRanks) {
                                            hotelsData.push({
                                                ...item,
                                                _dimName: rankData.dimName || '',
                                                _aiMetricName: rankData.aiMetricName || '',
                                                _rankName: result.rankName
                                            });
                                        }
                                    }
                                }
                            }
                            // 兼容: data.data.data.peerRankData
                            else if (data.data && data.data.data && data.data.data.peerRankData) {
                                for (const rankData of data.data.data.peerRankData) {
                                    if (rankData.roundRanks) {
                                        for (const item of rankData.roundRanks) {
                                            hotelsData.push({
                                                ...item,
                                                _dimName: rankData.dimName || '',
                                                _aiMetricName: rankData.aiMetricName || '',
                                                _rankName: result.rankName
                                            });
                                        }
                                    }
                                }
                            }
                            // 结构3: data.data.roundrank
                            else if (data.data && data.data.roundrank) {
                                hotelsData = data.data.roundrank.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            // 结构3: data.data.list
                            else if (data.data && data.data.list) {
                                hotelsData = data.data.list.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            // 结构4: data.data 是数组
                            else if (data.data && Array.isArray(data.data)) {
                                hotelsData = data.data.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            
                            for (const item of hotelsData) {
                                const hotelName = item.poiName || item.poi_name || item.shopName || item.shop_name || item.hotelName || item.name || '';
                                const poiId = item.poiId || item.poi_id || item.shopId || item.shop_id || item.hotelId || '';
                                const dataValue = item.dataValue || item.data_value || item.monthRoomNights || item.month_room_nights || 0;
                                const dimName = item._dimName || '';
                                const aiMetricName = item._aiMetricName || '';
                                
                                // 调试：输出实际的dimName和aiMetricName值
                                if (dimName || aiMetricName) {
                                    console.log('美团数据解析 - dimName:', dimName, 'aiMetricName:', aiMetricName, 'dataValue:', dataValue, 'hotelName:', hotelName);
                                }
                                
                                // 使用英文aiMetricName进行判断，避免中文编码问题
                                // 销售间夜: P_XS_PAY_ROOM_NIGHT, 销售额: P_XS_PAY_AMT
                                const isSalesRoomNights = aiMetricName.includes('P_XS') && aiMetricName.includes('ROOM_NIGHT');
                                const isSales = aiMetricName.includes('P_XS') && aiMetricName.includes('AMT') && !aiMetricName.includes('ROOM_NIGHT');
                                // 入住间夜: P_RZ_NIGHT_COUNT, 房费收入: P_RZ_ROOM_PAY
                                const isRoomNights = aiMetricName.includes('P_RZ_NIGHT_COUNT');
                                const isRoomRevenue = aiMetricName.includes('P_RZ_ROOM_PAY');
                                // 流量榜
                                const isExposure = aiMetricName.includes('EXPOSURE') || dimName.includes('曝光');
                                const isViews = aiMetricName.includes('VIEW') || (dimName.includes('浏览') && !dimName.includes('转化'));
                                // 转化榜
                                const isViewConversion = aiMetricName.includes('VIEW_CONVERT') || dimName.includes('浏览转化');
                                const isPayConversion = aiMetricName.includes('PAY_CONVERT') || dimName.includes('支付转化');
                                
                                // 备用：中文匹配（当aiMetricName为空时使用）
                                const isRoomNights_cn = !aiMetricName && (dimName === '入住间夜榜' || dimName.includes('间夜') || dimName.includes('入住'));
                                const isRoomRevenue_cn = !aiMetricName && (dimName === '房费收入榜' || dimName.includes('房费') || dimName.includes('收入榜'));
                                const isSalesRoomNights_cn = !aiMetricName && (dimName === '销售间夜榜' || dimName.includes('销售间夜'));
                                const isSales_cn = !aiMetricName && (dimName === '销售额榜' || dimName.includes('销售额'));
                                const isViewConversion_cn = dimName === '浏览转化榜' || dimName.includes('浏览转化');
                                const isPayConversion_cn = dimName === '支付转化榜' || dimName.includes('支付转化');
                                const isExposure_cn = dimName === '曝光榜' || dimName.includes('曝光');
                                const isViews_cn = dimName === '浏览榜' || (dimName.includes('浏览') && !dimName.includes('转化'));
                                
                                // 合并英文判断和中文备选判断
                                const isRoomNightsFINAL = isRoomNights || isRoomNights_cn;
                                const isRoomRevenueFINAL = isRoomRevenue || isRoomRevenue_cn;
                                const isSalesRoomNightsFINAL = isSalesRoomNights || isSalesRoomNights_cn;
                                const isSalesFINAL = isSales || isSales_cn;
                                const isViewConversionFINAL = isViewConversion || isViewConversion_cn;
                                const isPayConversionFINAL = isPayConversion || isPayConversion_cn;
                                const isExposureFINAL = isExposure || isExposure_cn;
                                const isViewsFINAL = isViews || isViews_cn;
                                
                                // 检查是否已存在，如果存在则更新，否则添加
                                const existIndex = allHotels.findIndex(h => h.hotelName === hotelName);
                                if (existIndex >= 0) {
                                    // 根据维度名称更新对应字段
                                    if (isRoomNightsFINAL) {
                                        allHotels[existIndex].roomNights = dataValue;
                                    } else if (isRoomRevenueFINAL) {
                                        allHotels[existIndex].roomRevenue = dataValue;
                                    } else if (isSalesRoomNightsFINAL) {
                                        allHotels[existIndex].salesRoomNights = dataValue;
                                    } else if (isSalesFINAL) {
                                        allHotels[existIndex].sales = dataValue;
                                    } else if (isViewConversionFINAL) {
                                        allHotels[existIndex].viewConversion = dataValue;
                                    } else if (isPayConversionFINAL) {
                                        allHotels[existIndex].payConversion = dataValue;
                                    } else if (isExposureFINAL) {
                                        allHotels[existIndex].exposure = dataValue;
                                    } else if (isViewsFINAL) {
                                        allHotels[existIndex].views = dataValue;
                                    }
                                } else {
                                    // 添加新酒店，初始化所有字段
                                    allHotels.push({
                                        hotelName: hotelName,
                                        poiId: poiId,
                                        roomNights: isRoomNightsFINAL ? dataValue : 0,
                                        roomRevenue: isRoomRevenueFINAL ? dataValue : 0,
                                        salesRoomNights: isSalesRoomNightsFINAL ? dataValue : 0,
                                        sales: isSalesFINAL ? dataValue : 0,
                                        viewConversion: isViewConversionFINAL ? dataValue : 0,
                                        payConversion: isPayConversionFINAL ? dataValue : 0,
                                        exposure: isExposureFINAL ? dataValue : 0,
                                        views: isViewsFINAL ? dataValue : 0,
                                        rank: item.rank || item.ranking || 0
                                    });
                                }
                            }
                        }
                    }
                    
                    meituanHotelsList.value = allHotels.sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 50);
                    meituanFetchSuccess.value = allHotels.length > 0;
                    meituanDataFetchTime.value = new Date().toLocaleString('zh-CN'); // 记录数据获取时间
                    
                    // 更新美团AI分析酒店列表
                    updateMeituanAiAnalysisHotelList();
                    
                    if (totalSavedCount > 0) {
                        showToast(`批量获取完成！共保存 ${totalSavedCount} 条数据`);
                        if (onlineDataTab.value === 'data') {
                            refreshOnlineData();
                        }
                    } else if (allHotels.length > 0) {
                        showToast(`获取成功！共 ${allHotels.length} 家酒店数据`);
                    } else {
                        showToast('获取完成，但未找到有效数据');
                    }
                } catch (e) {
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            // 携程流量数据获取
            const fetchCtripTrafficData = async () => {
                if (!ctripTrafficForm.value.url) {
                    showToast('请输入接口地址', 'error');
                    return;
                }
                if (!ctripTrafficForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                fetchingData.value = true;
                onlineDataResult.value = null;
                try {
                    const res = await request('/online-data/fetch-ctrip-traffic', {
                        method: 'POST',
                        body: JSON.stringify({
                            url: ctripTrafficForm.value.url,
                            node_id: ctripTrafficForm.value.nodeId,
                            cookies: ctripTrafficForm.value.cookies,
                            start_date: ctripTrafficForm.value.startDate,
                            end_date: ctripTrafficForm.value.endDate,
                            auto_save: true,
                            extra_params: ctripTrafficForm.value.extraParams,
                        }),
                    });
                    if (res.code === 200) {
                        onlineDataResult.value = res.data.data;
                        latestTrafficData.value = res.data.data; // 保存本次获取的流量数据
                        const savedCount = res.data.saved_count || 0;
                        if (savedCount > 0) {
                            showToast(`获取成功！已保存 ${savedCount} 条流量数据`);
                            if (onlineDataTab.value === 'data') {
                                refreshOnlineData();
                            }
                        } else {
                            showToast('获取成功，但未解析到有效流量数据');
                        }
                    } else {
                        showToast(res.message || '获取失败', 'error');
                    }
                } catch (e) {
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            // 美团流量数据获取
            const fetchMeituanTrafficData = async () => {
                if (!meituanTrafficForm.value.url) {
                    showToast('请输入接口地址', 'error');
                    return;
                }
                if (!meituanTrafficForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                fetchingData.value = true;
                onlineDataResult.value = null;
                try {
                    const res = await request('/online-data/fetch-meituan-traffic', {
                        method: 'POST',
                        body: JSON.stringify({
                            url: meituanTrafficForm.value.url,
                            cookies: meituanTrafficForm.value.cookies,
                            start_date: meituanTrafficForm.value.startDate,
                            end_date: meituanTrafficForm.value.endDate,
                            auto_save: true,
                            extra_params: meituanTrafficForm.value.extraParams,
                        }),
                    });
                    if (res.code === 200) {
                        onlineDataResult.value = res.data.data;
                        latestTrafficData.value = res.data.data; // 保存本次获取的流量数据
                        const savedCount = res.data.saved_count || 0;
                        if (savedCount > 0) {
                            showToast(`获取成功！已保存 ${savedCount} 条流量数据`);
                            if (onlineDataTab.value === 'data') {
                                refreshOnlineData();
                            }
                        } else {
                            showToast('获取成功，但未解析到有效流量数据');
                        }
                    } else {
                        showToast(res.message || '获取失败', 'error');
                    }
                } catch (e) {
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            // 美团差评数据获取
            const fetchMeituanComments = async () => {
                // 去除所有字段的前后空格和换行符
                meituanCommentForm.value.partnerId = (meituanCommentForm.value.partnerId || '').trim();
                meituanCommentForm.value.poiId = (meituanCommentForm.value.poiId || '').trim();
                meituanCommentForm.value.cookies = (meituanCommentForm.value.cookies || '').replace(/^[\s\n]+|[\s\n]+$/g, '').replace(/\n/g, '');
                meituanCommentForm.value.mtgsig = (meituanCommentForm.value.mtgsig || '').trim();
                
                console.log('fetchMeituanComments called', meituanCommentForm.value);
                if (!meituanCommentForm.value.partnerId) {
                    showToast('请输入Partner ID（商家ID）', 'error');
                    return;
                }
                if (!meituanCommentForm.value.poiId) {
                    showToast('请输入POI ID（门店ID）', 'error');
                    return;
                }
                if (!meituanCommentForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                if (!meituanCommentForm.value.mtgsig) {
                    showToast('请输入mtgsig签名（必填）', 'error');
                    return;
                }
                
                fetchingCommentData.value = true;
                meituanCommentResult.value = null;
                meituanCommentSuccess.value = false; // 重置成功状态
                
                try {
                    console.log('发送请求到后端...');
                    const res = await request('/online-data/fetch-meituan-comments', {
                        method: 'POST',
                        body: JSON.stringify({
                            partner_id: meituanCommentForm.value.partnerId,
                            poi_id: meituanCommentForm.value.poiId,
                            cookies: meituanCommentForm.value.cookies,
                            mtgsig: meituanCommentForm.value.mtgsig,
                            reply_type: meituanCommentForm.value.replyType,
                            tag: meituanCommentForm.value.tag,
                            limit: meituanCommentForm.value.limit,
                            auto_save: true,
                        }),
                    });
                    
                    console.log('后端返回:', res);
                    
                    if (res.code === 200) {
                        meituanCommentResult.value = res.data;
                        meituanCommentSuccess.value = true; // 设置成功状态
                        const savedCount = res.data.saved_count || 0;
                        const total = res.data.total || 0;
                        if (savedCount > 0) {
                            showToast(`获取成功！共 ${total} 条评论，已保存 ${savedCount} 条新评论`);
                        } else if (total > 0) {
                            showToast(`获取成功！共 ${total} 条评论，无新增评论`);
                        } else {
                            showToast('获取成功，暂无评论数据');
                        }
                    } else {
                        console.error('获取失败:', res.message);
                        showToast(res.message || '获取失败', 'error');
                    }
                } catch (e) {
                    console.error('请求异常:', e);
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingCommentData.value = false;
                }
            };
            
            // 格式化评论时间（美团返回毫秒时间戳）
            const formatCommentTime = (timestamp) => {
                if (!timestamp) return '';
                if (typeof timestamp === 'number') {
                    return new Date(timestamp).toLocaleString('zh-CN');
                }
                return timestamp;
            };
            
            // 获取评分样式类
            const getScoreClass = (score) => {
                const star = score / 10;
                if (star >= 4) return 'bg-green-100 text-green-800';
                if (star >= 3) return 'bg-yellow-100 text-yellow-800';
                return 'bg-red-100 text-red-800';
            };
            
            // 使用已保存的美团配置（差评获取）
            const useMeituanCommentConfig = (config) => {
                meituanCommentForm.value.partnerId = config.partner_id || '';
                meituanCommentForm.value.poiId = config.poi_id || '';
                meituanCommentForm.value.cookies = config.cookies || '';
                showToast('已应用配置：' + config.name);
            };
            
            // 保存美团配置
            const saveMeituanConfig = async () => {
                try {
                    const res = await request('/online-data/save-meituan-config', {
                        method: 'POST',
                        body: JSON.stringify({
                            url: meituanForm.value.url,
                            hotel_id: meituanForm.value.hotelId,
                            partner_id: meituanForm.value.partnerId,
                            poi_id: meituanForm.value.poiId,
                            rank_type: meituanForm.value.rankType,
                            rank_types: meituanForm.value.rankTypes,
                            date_ranges: meituanForm.value.dateRanges,
                            cookies: meituanForm.value.cookies,
                        }),
                    });
                    if (res.code === 200) {
                        showToast('配置保存成功');
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };
            
            // 加载美团配置
            const loadMeituanConfig = async () => {
                try {
                    const params = new URLSearchParams();
                    if (meituanForm.value.hotelId) {
                        params.append('hotel_id', meituanForm.value.hotelId);
                    }
                    const res = await request(`/online-data/get-meituan-config?${params}`);
                    if (res.code === 200 && res.data) {
                        meituanForm.value.url = res.data.url || meituanForm.value.url;
                        meituanForm.value.partnerId = res.data.partner_id || '';
                        meituanForm.value.poiId = res.data.poi_id || '';
                        meituanForm.value.rankType = res.data.rank_type || 'P_RZ';
                        meituanForm.value.rankTypes = res.data.rank_types || ['P_RZ'];
                        meituanForm.value.dateRanges = res.data.date_ranges || ['1'];
                        meituanForm.value.cookies = res.data.cookies || '';
                    }
                } catch (e) {
                    console.error('加载美团配置失败:', e);
                }
            };

            // 携程配置管理方法
            const loadCtripConfigList = async () => {
                try {
                    const res = await fetch(API_BASE + '/test-ctrip-config-list');
                    const data = await res.json();
                    if (data.code === 200) {
                        ctripConfigList.value = data.data || [];
                    }
                } catch (e) {
                    console.error('加载携程配置列表失败:', e);
                }
            };

            const saveCtripConfig = async () => {
                if (!ctripConfigForm.value.name) {
                    showToast('请输入配置名称', 'error');
                    return;
                }
                if (!ctripConfigForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                try {
                    // 先调用无认证测试接口
                    const testRes = await fetch(API_BASE + '/test-ctrip-save-direct', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            name: ctripConfigForm.value.name,
                            cookies: ctripConfigForm.value.cookies,
                            url: ctripConfigForm.value.url,
                            node_id: ctripConfigForm.value.node_id,
                        }),
                    });
                    const res = await testRes.json();
                    console.log('保存结果:', res);
                    
                    if (res.code === 200) {
                        showToast('配置保存成功');
                        // 重置表单
                        ctripConfigForm.value = {
                            id: null,
                            name: '',
                            hotel_id: '',
                            url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                            node_id: '24588',
                            cookies: '',
                        };
                        // 刷新列表
                        loadCtripConfigList();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    console.error('保存失败:', e);
                    let errorMsg = e.message || '未知错误';
                    if (e.response) {
                        try {
                            const errData = await e.response.json();
                            errorMsg = errData.message || errData.msg || errorMsg;
                        } catch(err) {}
                    }
                    showToast('保存失败: ' + errorMsg, 'error');
                }
            };

            const useCtripConfig = (config) => {
                // 设置选中的配置ID
                selectedCtripConfigId.value = config.id;
                // 将配置应用到表单
                ctripForm.value.url = config.url || ctripForm.value.url;
                ctripForm.value.nodeId = config.node_id || '24588';
                ctripForm.value.cookies = config.cookies || '';
                ctripForm.value.auth_data = config.auth_data || {};
                showToast(`已选择: ${config.name}`);
                // 切换到榜单数据获取tab
                onlineDataTab.value = 'ctrip-ranking';
            };
            
            // 在榜单数据获取页面应用选中的配置
            const applyCtripConfig = () => {
                if (!selectedCtripConfigId.value) {
                    // 清空表单
                    ctripForm.value.cookies = '';
                    ctripForm.value.nodeId = '24588';
                    ctripForm.value.auth_data = {};
                    return;
                }
                const config = ctripConfigList.value.find(c => c.id === selectedCtripConfigId.value);
                if (config) {
                    ctripForm.value.url = config.url || ctripForm.value.url;
                    ctripForm.value.nodeId = config.node_id || '24588';
                    ctripForm.value.cookies = config.cookies || '';
                    ctripForm.value.auth_data = config.auth_data || {};
                    showToast(`已应用配置: ${config.name}`);
                }
            };

            const editCtripConfig = (config) => {
                ctripConfigForm.value = {
                    id: config.id,
                    name: config.name,
                    hotel_id: config.hotel_id || '',
                    url: config.url || '',
                    node_id: config.node_id || '',
                    cookies: config.cookies || '',
                };
            };

            const deleteCtripConfig = async (id) => {
                if (!confirm('确定要删除此配置吗？')) return;
                try {
                    const res = await fetch(API_BASE + `/test-ctrip-config-delete?id=${id}`);
                    const data = await res.json();
                    if (data.code === 200) {
                        showToast('删除成功');
                        loadCtripConfigList();
                    } else {
                        showToast(data.message || '删除失败', 'error');
                    }
                } catch (e) {
                    showToast('删除失败: ' + e.message, 'error');
                }
            };

            const generateCtripBookmarklet = async () => {
                console.log('generateCtripBookmarklet called');
                alert('正在生成书签脚本...');
                try {
                    const res = await request('/online-data/generate-ctrip-bookmarklet');
                    console.log('response:', res);
                    if (res.code === 200) {
                        ctripBookmarklet.value = res.data.bookmarklet;
                        showToast('书签脚本生成成功');
                    } else {
                        alert('生成失败: ' + (res.message || '未知错误'));
                    }
                } catch (e) {
                    console.error('error:', e);
                    alert('请求失败: ' + e.message);
                    showToast('生成失败: ' + e.message, 'error');
                }
            };

            // 美团配置管理方法
            const loadMeituanConfigList = async () => {
                console.log('[Debug] loadMeituanConfigList 被调用');
                try {
                    const res = await request('/online-data/get-meituan-config-list');
                    console.log('[Debug] API 响应:', res);
                    if (res.code === 200) {
                        meituanConfigList.value = res.data || [];
                        console.log('[Debug] 配置列表已更新，数量:', meituanConfigList.value.length);
                    } else {
                        console.error('[Debug] API 返回错误:', res.message);
                    }
                } catch (e) {
                    console.error('[Debug] 加载美团配置列表失败:', e);
                }
            };

            const saveMeituanConfigItem = async () => {
                if (!meituanConfigForm.value.name) {
                    showToast('请输入配置名称', 'error');
                    return;
                }
                if (!meituanConfigForm.value.partner_id) {
                    showToast('请输入Partner ID', 'error');
                    return;
                }
                if (!meituanConfigForm.value.poi_id) {
                    showToast('请输入POI ID（门店ID）', 'error');
                    return;
                }
                if (!meituanConfigForm.value.hotel_room_count) {
                    showToast('请输入酒店房量', 'error');
                    return;
                }
                if (!meituanConfigForm.value.competitor_room_count) {
                    showToast('请输入竞争圈总房量', 'error');
                    return;
                }
                if (!meituanConfigForm.value.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                try {
                    const res = await request('/online-data/save-meituan-config-item', {
                        method: 'POST',
                        body: JSON.stringify({
                            id: meituanConfigForm.value.id,
                            name: meituanConfigForm.value.name,
                            partner_id: meituanConfigForm.value.partner_id,
                            poi_id: meituanConfigForm.value.poi_id,
                            hotel_room_count: meituanConfigForm.value.hotel_room_count,
                            competitor_room_count: meituanConfigForm.value.competitor_room_count,
                            cookies: meituanConfigForm.value.cookies,
                        }),
                    });
                    if (res.code === 200) {
                        showToast('配置保存成功');
                        meituanConfigForm.value = {
                            id: null,
                            name: '',
                            partner_id: '',
                            poi_id: '',
                            cookies: '',
                            hotel_room_count: '',
                            competitor_room_count: '',
                        };
                        loadMeituanConfigList();
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            const useMeituanConfig = (config) => {
                meituanForm.value.partnerId = config.partner_id || '';
                meituanForm.value.poiId = config.poi_id || '';
                meituanForm.value.cookies = config.cookies || '';
                meituanForm.value.auth_data = config.auth_data || {};
                meituanForm.value.hotelRoomCount = config.hotel_room_count || '';
                meituanForm.value.competitorRoomCount = config.competitor_room_count || '';
                showToast(`已应用配置: ${config.name}`);
                onlineDataTab.value = 'meituan-ranking';
            };

            const editMeituanConfig = (config) => {
                meituanConfigForm.value = {
                    id: config.id,
                    name: config.name,
                    partner_id: config.partner_id || '',
                    poi_id: config.poi_id || '',
                    cookies: config.cookies || '',
                    hotel_room_count: config.hotel_room_count || '',
                    competitor_room_count: config.competitor_room_count || '',
                };
            };

            const deleteMeituanConfigItem = async (id) => {
                if (!confirm('确定要删除此配置吗？')) return;
                try {
                    const res = await request(`/online-data/delete-meituan-config?id=${id}`, {
                        method: 'DELETE'
                    });
                    if (res.code === 200) {
                        showToast('删除成功');
                        loadMeituanConfigList();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (e) {
                    showToast('删除失败: ' + e.message, 'error');
                }
            };

            const generateMeituanBookmarklet = async () => {
                try {
                    const res = await request('/online-data/generate-meituan-bookmarklet');
                    if (res.code === 200) {
                        meituanBookmarklet.value = res.data.bookmarklet;
                        showToast('书签脚本生成成功');
                    }
                } catch (e) {
                    showToast('生成失败: ' + e.message, 'error');
                }
            };

            const fetchCustomData = async () => {
                if (!customForm.value.url) {
                    showToast('请输入URL', 'error');
                    return;
                }
                fetchingData.value = true;
                onlineDataResult.value = null;
                try {
                    const res = await request('/online-data/fetch-custom', {
                        method: 'POST',
                        body: JSON.stringify({
                            url: customForm.value.url,
                            method: customForm.value.method,
                            headers: customForm.value.headers,
                            body: customForm.value.body,
                        }),
                    });
                    if (res.code === 200) {
                        onlineDataResult.value = res.data.data;
                        showToast('请求成功！');
                    } else {
                        showToast(res.message || '请求失败', 'error');
                    }
                } catch (e) {
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            const loadCookiesList = async () => {
                const res = await request('/online-data/cookies-list');
                if (res.code === 200) {
                    cookiesList.value = res.data || [];
                }
            };

            const saveCookiesConfig = async () => {
                if (!newCookies.value.name || !newCookies.value.cookies) {
                    showToast('请填写名称和Cookies', 'error');
                    return;
                }
                const res = await request('/online-data/save-cookies', {
                    method: 'POST',
                    body: JSON.stringify(newCookies.value),
                });
                if (res.code === 200) {
                    showToast('保存成功');
                    newCookies.value = { name: '', cookies: '', hotel_id: '' };
                    loadCookiesList();
                } else {
                    showToast(res.message || '保存失败', 'error');
                }
            };

            const deleteCookiesConfig = async (name, hotelId) => {
                if (!confirm(`确定删除 ${name} 的Cookies配置吗？`)) return;
                const res = await request('/online-data/delete-cookies', {
                    method: 'POST',
                    body: JSON.stringify({ name, hotel_id: hotelId || '' }),
                });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadCookiesList();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };

            const useCookies = (cookies) => {
                ctripForm.value.cookies = cookies;
                onlineDataTab.value = 'ctrip';
                showToast('已应用Cookies');
            };

            // AI智能分析相关函数
            // 更新AI分析酒店列表（只从携程数据中提取，并合并同一酒店的不同榜单数据）
            const updateAiAnalysisHotelList = () => {
                const hotelMap = new Map();
                
                // 只从携程数据中提取（携程ebooking下载中心）
                if (ctripHotelsList.value && ctripHotelsList.value.length > 0) {
                    ctripHotelsList.value.forEach(h => {
                        const key = (h.hotelId || h.id) + '_' + (h.hotelName || h.name);
                        
                        if (!hotelMap.has(key)) {
                            // 首次遇到该酒店，创建新记录
                            hotelMap.set(key, {
                                poiId: h.hotelId || h.id || '',
                                hotelName: h.hotelName || h.name || '',
                                // 入住榜数据
                                roomNights: h.quantity || h.roomNights || 0,
                                roomRevenue: h.amount || h.roomRevenue || 0,
                                // 销售榜数据
                                salesRoomNights: h.salesRoomNights || 0,
                                sales: h.sales || h.amount || 0,
                                // 转化榜数据
                                viewConversion: h.viewConversion || h.convertionRate || 0,
                                payConversion: h.payConversion || 0,
                                // 流量榜数据
                                exposure: h.exposure || h.totalDetailNum || 0,
                                views: h.views || h.qunarDetailVisitors || 0
                            });
                        } else {
                            // 已存在该酒店，合并数据（累加或取最大值）
                            const existing = hotelMap.get(key);
                            // 入住榜数据（取最大值，因为是同一酒店的同一指标）
                            existing.roomNights = Math.max(existing.roomNights, h.quantity || h.roomNights || 0);
                            existing.roomRevenue = Math.max(existing.roomRevenue, h.amount || h.roomRevenue || 0);
                            // 销售榜数据
                            existing.salesRoomNights = Math.max(existing.salesRoomNights, h.salesRoomNights || 0);
                            existing.sales = Math.max(existing.sales, h.sales || h.amount || 0);
                            // 转化榜数据
                            existing.viewConversion = Math.max(existing.viewConversion, h.viewConversion || h.convertionRate || 0);
                            existing.payConversion = Math.max(existing.payConversion, h.payConversion || 0);
                            // 流量榜数据
                            existing.exposure = Math.max(existing.exposure, h.exposure || h.totalDetailNum || 0);
                            existing.views = Math.max(existing.views, h.views || h.qunarDetailVisitors || 0);
                        }
                    });
                }
                
                aiAnalysisHotelList.value = Array.from(hotelMap.values());
                console.log('AI分析酒店列表更新:', aiAnalysisHotelList.value.length, '家酒店', aiAnalysisHotelList.value);
            };
            
            // 全选AI分析酒店
            const selectAllAiHotels = () => {
                aiSelectedHotels.value = aiAnalysisHotelList.value.map(h => h.poiId + '_' + h.hotelName);
                showToast('已全选 ' + aiSelectedHotels.value.length + ' 家酒店');
            };
            
            // 清空AI分析酒店选择
            const clearAiHotelSelection = () => {
                aiSelectedHotels.value = [];
                showToast('已清空选择');
            };
            
            // 开始AI分析
            const startAiAnalysis = async () => {
                if (aiSelectedHotels.value.length === 0) {
                    showToast('请先选择要分析的酒店', 'error');
                    return;
                }
                
                // 获取选中酒店的详细数据
                const selectedData = aiSelectedHotels.value.map(key => {
                    return aiAnalysisHotelList.value.find(h => h.poiId + '_' + h.hotelName === key);
                }).filter(Boolean);
                
                if (selectedData.length === 0) {
                    showToast('未找到选中的酒店数据', 'error');
                    return;
                }
                
                aiAnalyzing.value = true;
                aiAnalysisResult.value = '';
                
                try {
                    // 准备分析数据
                    const analysisData = {
                        hotels: selectedData,
                        total_hotels: selectedData.length,
                        analysis_type: 'business_overview',
                        include_suggestions: true
                    };
                    
                    showToast('AI正在分析数据，请稍候...');
                    
                    // 调用后端AI分析接口
                    const res = await request('/online-data/ai-analysis', {
                        method: 'POST',
                        body: JSON.stringify(analysisData),
                    });
                    
                    if (res.code === 200 && res.data) {
                        aiAnalysisResult.value = res.data.report || res.data.analysis || res.data;
                        
                        // 添加到历史记录
                        aiAnalysisHistory.value.unshift({
                            id: Date.now(),
                            hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('、') + (selectedData.length > 3 ? '等' : ''),
                            hotel_count: selectedData.length,
                            summary: res.data.summary || 'AI分析报告',
                            report: aiAnalysisResult.value,
                            create_time: new Date().toLocaleString('zh-CN')
                        });
                        
                        // 只保留最近10条记录
                        if (aiAnalysisHistory.value.length > 10) {
                            aiAnalysisHistory.value = aiAnalysisHistory.value.slice(0, 10);
                        }
                        
                        showToast('AI分析完成！');
                    } else {
                        // 如果后端API未实现，使用本地分析
                        aiAnalysisResult.value = generateLocalAnalysis(selectedData);
                        showToast('AI分析完成（本地分析）');
                    }
                } catch (e) {
                    console.error('AI分析请求失败:', e);
                    // 网络错误时使用本地分析
                    aiAnalysisResult.value = generateLocalAnalysis(selectedData);
                    showToast('使用本地分析完成');
                } finally {
                    aiAnalyzing.value = false;
                }
            };
            
            // 本地生成AI分析报告（后端API不可用时的备选方案）
            const generateLocalAnalysis = (hotels) => {
                if (!hotels || hotels.length === 0) {
                    return '<p class="text-gray-500">暂无数据可供分析</p>';
                }
                
                // 计算统计数据
                const totalRoomNights = hotels.reduce((sum, h) => sum + (h.roomNights || 0), 0);
                const totalRoomRevenue = hotels.reduce((sum, h) => sum + (h.roomRevenue || 0), 0);
                const totalSales = hotels.reduce((sum, h) => sum + (h.sales || 0), 0);
                const totalExposure = hotels.reduce((sum, h) => sum + (h.exposure || 0), 0);
                const totalViews = hotels.reduce((sum, h) => sum + (h.views || 0), 0);
                
                const avgRoomNights = totalRoomNights / hotels.length;
                const avgRoomRevenue = totalRoomRevenue / hotels.length;
                const avgPricePerNight = totalRoomNights > 0 ? totalRoomRevenue / totalRoomNights : 0;
                
                // 找出排名靠前的酒店
                const topByRoomNights = [...hotels].sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 5);
                const topByRevenue = [...hotels].sort((a, b) => (b.roomRevenue || 0) - (a.roomRevenue || 0)).slice(0, 5);
                
                // 计算转化率相关
                const avgViewConversion = hotels.reduce((sum, h) => sum + (h.viewConversion || 0), 0) / hotels.length;
                const avgPayConversion = hotels.reduce((sum, h) => sum + (h.payConversion || 0), 0) / hotels.length;
                
                // 生成HTML报告
                let report = `
<div class="space-y-6">
    <!-- 概览卡片 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">${hotels.length}</div>
            <div class="text-sm text-gray-600">分析酒店数</div>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600">${totalRoomNights.toLocaleString()}</div>
            <div class="text-sm text-gray-600">总入住间夜</div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">¥${totalRoomRevenue.toLocaleString()}</div>
            <div class="text-sm text-gray-600">总房费收入</div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">¥${avgPricePerNight.toFixed(0)}</div>
            <div class="text-sm text-gray-600">平均房价</div>
        </div>
    </div>
    
    <!-- 经营分析 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-blue-500 mr-2"></i>经营数据分析
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均间夜：</span>
                <span class="text-gray-800">${avgRoomNights.toFixed(1)} 间夜/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均收入：</span>
                <span class="text-gray-800">¥${avgRoomRevenue.toFixed(0)}/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">总销售额：</span>
                <span class="text-gray-800">¥${totalSales.toLocaleString()}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">曝光量：</span>
                <span class="text-gray-800">${totalExposure.toLocaleString()} 次</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">浏览量：</span>
                <span class="text-gray-800">${totalViews.toLocaleString()} 次</span>
            </div>
        </div>
    </div>
    
    <!-- 入住间夜TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>入住间夜 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRoomNights.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-blue-600">${(h.roomNights || 0).toLocaleString()} 间夜</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- 房费收入TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-green-500 mr-2"></i>房费收入 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRevenue.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-green-50 border-l-4 border-green-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-green-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-green-600">¥${(h.roomRevenue || 0).toLocaleString()}</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- AI建议 -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4">
        <h3 class="font-bold text-indigo-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-indigo-500 mr-2"></i>AI经营建议
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>定价策略：</strong>
                    当前平均房价 ¥${avgPricePerNight.toFixed(0)}，
                    ${avgPricePerNight > 300 ? '建议关注性价比，可适当推出优惠套餐吸引更多客源' : '定价相对亲民，可通过增值服务提升客单价'}。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>流量转化：</strong>
                    ${totalExposure > 0 && totalViews > 0 
                        ? `曝光到浏览转化率 ${((totalViews / totalExposure) * 100).toFixed(1)}%，` 
                        : ''}
                    ${avgViewConversion > 0 
                        ? `平均浏览转化 ${avgViewConversion.toFixed(1)}，建议优化详情页图片和描述提升转化率。` 
                        : '建议关注流量入口优化，提升曝光量和浏览量。'}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>竞对分析：</strong>
                    共分析 ${hotels.length} 家竞对酒店，
                    ${topByRoomNights[0] ? `${topByRoomNights[0].hotelName} 表现最佳（${(topByRoomNights[0].roomNights || 0).toLocaleString()} 间夜），` : ''}
                    建议分析其成功因素并借鉴学习。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>营销建议：</strong>
                    ${totalExposure > totalViews * 10 
                        ? '曝光量充足但浏览转化偏低，建议优化主图和标题吸引点击。' 
                        : '建议增加平台推广投放，扩大曝光量，同时关注评价维护。'}
                </div>
            </div>
        </div>
    </div>
    
    <!-- 分析时间 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>分析时间：${new Date().toLocaleString('zh-CN')}
    </div>
</div>`;
                
                return report;
            };
            
            // 复制AI分析结果
            const copyAiAnalysisResult = () => {
                if (!aiAnalysisResult.value) {
                    showToast('暂无分析结果可复制', 'warning');
                    return;
                }
                
                // 将HTML转换为纯文本
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = aiAnalysisResult.value;
                const textContent = tempDiv.innerText || tempDiv.textContent;
                
                copyToClipboard(textContent);
            };
            
            // 查看AI分析历史记录
            const viewAiAnalysisRecord = (record) => {
                aiAnalysisResult.value = record.report;
            };

            // ==================== 美团AI智能分析相关函数 ====================
            // 更新美团AI分析酒店列表（只从美团数据中提取）
            const updateMeituanAiAnalysisHotelList = () => {
                const hotelMap = new Map();
                
                // 只从美团数据中提取
                if (meituanHotelsList.value && meituanHotelsList.value.length > 0) {
                    meituanHotelsList.value.forEach(h => {
                        const key = h.poiId + '_' + h.hotelName;
                        if (!hotelMap.has(key)) {
                            hotelMap.set(key, {
                                poiId: h.poiId,
                                hotelName: h.hotelName,
                                roomNights: h.roomNights || 0,
                                roomRevenue: h.roomRevenue || 0,
                                salesRoomNights: h.salesRoomNights || 0,
                                sales: h.sales || 0,
                                viewConversion: h.viewConversion || 0,
                                payConversion: h.payConversion || 0,
                                exposure: h.exposure || 0,
                                views: h.views || 0
                            });
                        }
                    });
                }
                
                meituanAiAnalysisHotelList.value = Array.from(hotelMap.values());
                console.log('美团AI分析酒店列表更新:', meituanAiAnalysisHotelList.value.length, '家酒店');
            };
            
            // 全选美团AI分析酒店
            const selectAllMeituanAiHotels = () => {
                meituanAiSelectedHotels.value = meituanAiAnalysisHotelList.value.map(h => h.poiId + '_' + h.hotelName);
                showToast('已全选 ' + meituanAiSelectedHotels.value.length + ' 家酒店');
            };
            
            // 清空美团AI分析酒店选择
            const clearMeituanAiHotelSelection = () => {
                meituanAiSelectedHotels.value = [];
                showToast('已清空选择');
            };
            
            // 开始美团AI分析
            const startMeituanAiAnalysis = async () => {
                if (meituanAiSelectedHotels.value.length === 0) {
                    showToast('请先选择要分析的酒店', 'error');
                    return;
                }
                
                // 获取选中酒店的详细数据
                const selectedData = meituanAiSelectedHotels.value.map(key => {
                    return meituanAiAnalysisHotelList.value.find(h => h.poiId + '_' + h.hotelName === key);
                }).filter(Boolean);
                
                if (selectedData.length === 0) {
                    showToast('未找到选中的酒店数据', 'error');
                    return;
                }
                
                meituanAiAnalyzing.value = true;
                meituanAiAnalysisResult.value = '';
                
                try {
                    // 准备分析数据
                    const analysisData = {
                        hotels: selectedData,
                        total_hotels: selectedData.length,
                        analysis_type: 'business_overview',
                        source: 'meituan',
                        include_suggestions: true
                    };
                    
                    showToast('AI正在分析数据，请稍候...');
                    
                    // 调用后端AI分析接口
                    const res = await request('/online-data/ai-analysis', {
                        method: 'POST',
                        body: JSON.stringify(analysisData),
                    });
                    
                    if (res.code === 200 && res.data) {
                        meituanAiAnalysisResult.value = res.data.report || res.data.analysis || res.data;
                        
                        // 添加到历史记录
                        meituanAiAnalysisHistory.value.unshift({
                            id: Date.now(),
                            hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('、') + (selectedData.length > 3 ? '等' : ''),
                            hotel_count: selectedData.length,
                            summary: res.data.summary || 'AI分析报告',
                            report: meituanAiAnalysisResult.value,
                            create_time: new Date().toLocaleString('zh-CN')
                        });
                        
                        // 只保留最近10条记录
                        if (meituanAiAnalysisHistory.value.length > 10) {
                            meituanAiAnalysisHistory.value = meituanAiAnalysisHistory.value.slice(0, 10);
                        }
                        
                        showToast('AI分析完成！');
                    } else {
                        // 如果后端API未实现，使用本地分析
                        meituanAiAnalysisResult.value = generateMeituanLocalAnalysis(selectedData);
                        showToast('AI分析完成（本地分析）');
                    }
                } catch (e) {
                    console.error('美团AI分析请求失败:', e);
                    // 网络错误时使用本地分析
                    meituanAiAnalysisResult.value = generateMeituanLocalAnalysis(selectedData);
                    showToast('使用本地分析完成');
                } finally {
                    meituanAiAnalyzing.value = false;
                }
            };
            
            // 本地生成美团AI分析报告
            const generateMeituanLocalAnalysis = (hotels) => {
                if (!hotels || hotels.length === 0) {
                    return '<p class="text-gray-500">暂无数据可供分析</p>';
                }
                
                // 计算统计数据
                const totalRoomNights = hotels.reduce((sum, h) => sum + (h.roomNights || 0), 0);
                const totalRoomRevenue = hotels.reduce((sum, h) => sum + (h.roomRevenue || 0), 0);
                const totalSales = hotels.reduce((sum, h) => sum + (h.sales || 0), 0);
                const totalExposure = hotels.reduce((sum, h) => sum + (h.exposure || 0), 0);
                const totalViews = hotels.reduce((sum, h) => sum + (h.views || 0), 0);
                
                const avgRoomNights = totalRoomNights / hotels.length;
                const avgRoomRevenue = totalRoomRevenue / hotels.length;
                const avgPricePerNight = totalRoomNights > 0 ? totalRoomRevenue / totalRoomNights : 0;
                
                // 找出排名靠前的酒店
                const topByRoomNights = [...hotels].sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 5);
                const topByRevenue = [...hotels].sort((a, b) => (b.roomRevenue || 0) - (a.roomRevenue || 0)).slice(0, 5);
                
                // 计算转化率相关
                const avgViewConversion = hotels.reduce((sum, h) => sum + (h.viewConversion || 0), 0) / hotels.length;
                const avgPayConversion = hotels.reduce((sum, h) => sum + (h.payConversion || 0), 0) / hotels.length;
                
                // 生成HTML报告
                let report = `
<div class="space-y-6">
    <!-- 概览卡片 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-yellow-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600">${hotels.length}</div>
            <div class="text-sm text-gray-600">分析酒店数</div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">${totalRoomNights.toLocaleString()}</div>
            <div class="text-sm text-gray-600">总入住间夜</div>
        </div>
        <div class="bg-red-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-red-600">¥${totalRoomRevenue.toLocaleString()}</div>
            <div class="text-sm text-gray-600">总房费收入</div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">¥${avgPricePerNight.toFixed(0)}</div>
            <div class="text-sm text-gray-600">平均房价</div>
        </div>
    </div>
    
    <!-- 经营分析 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-yellow-500 mr-2"></i>经营数据分析
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均间夜：</span>
                <span class="text-gray-800">${avgRoomNights.toFixed(1)} 间夜/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均收入：</span>
                <span class="text-gray-800">¥${avgRoomRevenue.toFixed(0)}/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">总销售额：</span>
                <span class="text-gray-800">¥${totalSales.toLocaleString()}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">曝光量：</span>
                <span class="text-gray-800">${totalExposure.toLocaleString()} 次</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">浏览量：</span>
                <span class="text-gray-800">${totalViews.toLocaleString()} 次</span>
            </div>
        </div>
    </div>
    
    <!-- 入住间夜TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>入住间夜 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRoomNights.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-orange-600">${(h.roomNights || 0).toLocaleString()} 间夜</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- 房费收入TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-red-500 mr-2"></i>房费收入 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRevenue.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-red-50 border-l-4 border-red-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-red-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-red-600">¥${(h.roomRevenue || 0).toLocaleString()}</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- AI建议 -->
    <div class="bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-200 rounded-lg p-4">
        <h3 class="font-bold text-yellow-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>AI经营建议
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>定价策略：</strong>
                    当前平均房价 ¥${avgPricePerNight.toFixed(0)}，
                    ${avgPricePerNight > 300 ? '建议关注性价比，可适当推出优惠套餐吸引更多客源' : '定价相对亲民，可通过增值服务提升客单价'}。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>流量转化：</strong>
                    ${totalExposure > 0 && totalViews > 0 
                        ? `曝光到浏览转化率 ${((totalViews / totalExposure) * 100).toFixed(1)}%，` 
                        : ''}
                    ${avgViewConversion > 0 
                        ? `平均浏览转化 ${avgViewConversion.toFixed(1)}，建议优化详情页图片和描述提升转化率。` 
                        : '建议关注流量入口优化，提升曝光量和浏览量。'}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>竞对分析：</strong>
                    共分析 ${hotels.length} 家竞对酒店，
                    ${topByRoomNights[0] ? `${topByRoomNights[0].hotelName} 表现最佳（${(topByRoomNights[0].roomNights || 0).toLocaleString()} 间夜），` : ''}
                    建议分析其成功因素并借鉴学习。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>美团优化建议：</strong>
                    ${totalExposure > totalViews * 10 
                        ? '曝光量充足但浏览转化偏低，建议优化主图、标题和首屏信息吸引点击。' 
                        : '建议增加美团推广投放，参与平台活动，同时关注评价和问答维护。'}
                </div>
            </div>
        </div>
    </div>
    
    <!-- 分析时间 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>分析时间：${new Date().toLocaleString('zh-CN')}
    </div>
</div>`;
                
                return report;
            };
            
            // 复制美团AI分析结果
            const copyMeituanAiAnalysisResult = () => {
                if (!meituanAiAnalysisResult.value) {
                    showToast('暂无分析结果可复制', 'warning');
                    return;
                }
                
                // 将HTML转换为纯文本
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = meituanAiAnalysisResult.value;
                const textContent = tempDiv.innerText || tempDiv.textContent;
                
                copyToClipboard(textContent);
            };
            
            // 查看美团AI分析历史记录
            const viewMeituanAiAnalysisRecord = (record) => {
                meituanAiAnalysisResult.value = record.report;
            };

            const copyToClipboard = (text) => {
                // 优先使用 navigator.clipboard API
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        showToast('已复制到剪贴板');
                    }).catch(() => {
                        // 备用方案：使用 document.execCommand
                        fallbackCopy(text);
                    });
                } else {
                    // 备用方案：使用 document.execCommand
                    fallbackCopy(text);
                }
            };

            const fallbackCopy = (text) => {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                textarea.style.top = '-9999px';
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                try {
                    document.execCommand('copy');
                    showToast('已复制到剪贴板');
                } catch (err) {
                    showToast('复制失败，请手动复制', 'error');
                }
                document.body.removeChild(textarea);
            };

            const copyOnlineDataResult = () => {
                if (onlineDataResult.value) {
                    copyToClipboard(JSON.stringify(onlineDataResult.value, null, 2));
                } else {
                    showToast('没有数据可复制', 'error');
                }
            };

            const loadData = async () => {
                await loadDailyReportConfig(); // 先加载日报表配置，必须等待完成
                await loadMonthlyTaskConfig(); // 加载月任务配置
                loadHotels();
                loadRoles();
                loadUserInfo();
                loadSystemConfig();
                loadCookiesList(); // 加载Cookies列表
                loadBookmarklet(); // 加载书签脚本
                if (user.value?.is_super_admin || user.value?.role_id === 2) {
                    loadUsers();
                }
                if (user.value?.is_super_admin) {
                    loadReportConfigs();
                    loadRolesList(); // 加载角色列表
                }
                loadDailyReports();
                loadMonthlyTasks();
                loadCompassData();
            };

            // 酒店操作
            const openHotelModal = (hotel = null) => {
                if (hotel) {
                    // 编辑模式：复制酒店数据到表单
                    hotelForm.value = { 
                        id: hotel.id,
                        name: hotel.name || '',
                        code: hotel.code || '',
                        address: hotel.address || '',
                        contact_person: hotel.contact_person || '',
                        contact_phone: hotel.contact_phone || '',
                        status: hotel.status ?? 1,
                        description: hotel.description || ''
                    };
                } else {
                    // 新增模式：重置表单
                    hotelForm.value = { id: null, name: '', code: '', address: '', contact_person: '', contact_phone: '', status: 1, description: '' };
                }
                showHotelModal.value = true;
            };

            const saveHotel = async () => {
                // 表单验证
                if (!hotelForm.value.name || hotelForm.value.name.trim() === '') {
                    showToast('酒店名称不能为空', 'error');
                    return;
                }
                
                const isEdit = !!hotelForm.value.id;
                const url = isEdit ? `/hotels/${hotelForm.value.id}` : '/hotels';
                const method = isEdit ? 'PUT' : 'POST';
                
                // 构建提交数据
                const payload = {
                    name: hotelForm.value.name.trim(),
                    code: hotelForm.value.code?.trim() || '',
                    address: hotelForm.value.address?.trim() || '',
                    contact_person: hotelForm.value.contact_person?.trim() || '',
                    contact_phone: hotelForm.value.contact_phone?.trim() || '',
                    status: parseInt(hotelForm.value.status),
                    description: hotelForm.value.description?.trim() || ''
                };
                
                try {
                    const res = await request(url, { method, body: JSON.stringify(payload) });
                    if (res.code === 200) {
                        showToast(isEdit ? '酒店信息更新成功' : '酒店创建成功', 'success');
                        showHotelModal.value = false;
                        await loadHotels();
                    } else {
                        showToast(res.message || '操作失败，请重试', 'error');
                    }
                } catch (error) {
                    showToast('网络错误，请检查连接后重试', 'error');
                    console.error('保存酒店失败:', error);
                }
            };

            const toggleHotelStatus = async (hotel) => {
                const currentStatus = String(hotel.status);
                const newStatus = currentStatus === '1' ? 0 : 1;
                const statusText = newStatus === 1 ? '启用' : '停用';
                const confirmMsg = newStatus === 0 
                    ? `确定要${statusText}酒店"${hotel.name}"吗？\n\n停用后，该酒店关联的所有用户将无法登录系统。`
                    : `确定要${statusText}酒店"${hotel.name}"吗？\n\n启用后，该酒店关联的用户将恢复正常访问。`;
                    
                if (!confirm(confirmMsg)) return;
                
                try {
                    const payload = {
                        name: hotel.name,
                        code: hotel.code || '',
                        address: hotel.address || '',
                        contact_person: hotel.contact_person || '',
                        contact_phone: hotel.contact_phone || '',
                        status: newStatus,
                        description: hotel.description || ''
                    };
                    
                    const res = await request(`/hotels/${hotel.id}`, { 
                        method: 'PUT', 
                        body: JSON.stringify(payload) 
                    });
                    
                    if (res.code === 200) {
                        if (res.data?.status_changed) {
                            showToast(`酒店已${res.data.status_text}，影响${res.data.affected_users}个用户`, 'success');
                        } else {
                            showToast(`${statusText}成功`, 'success');
                        }
                        await loadHotels();
                    } else {
                        showToast(res.message || '操作失败', 'error');
                    }
                } catch (error) {
                    showToast('网络错误，请重试', 'error');
                    console.error('切换状态失败:', error);
                }
            };

            const deleteHotel = async (hotel) => {
                // 检查是否有关联用户
                const relatedUsers = users.value.filter(u => u.hotel_id === hotel.id);
                let confirmMsg = `确定要删除酒店"${hotel.name}"吗？`;
                if (relatedUsers.length > 0) {
                    confirmMsg += `\n\n⚠️ 警告：该酒店下还有 ${relatedUsers.length} 个关联用户，删除后这些用户将无法登录！`;
                }
                confirmMsg += '\n\n此操作不可恢复，请谨慎操作。';
                
                if (!confirm(confirmMsg)) return;
                
                try {
                    const res = await request(`/hotels/${hotel.id}`, { method: 'DELETE' });
                    if (res.code === 200) {
                        showToast('酒店删除成功', 'success');
                        await loadHotels();
                    } else {
                        showToast(res.message || '删除失败', 'error');
                    }
                } catch (error) {
                    showToast('网络错误，请重试', 'error');
                    console.error('删除酒店失败:', error);
                }
            };

            // 用户操作
            const openUserModal = (u = null) => {
                if (u) {
                    userForm.value = { ...u, password: '' };
                } else {
                    // 店长创建用户时，默认为店员角色，酒店为自己的酒店
                    const defaultRoleId = user.value?.is_super_admin ? '' : 3;
                    const defaultHotelId = user.value?.is_super_admin ? null : user.value?.hotel_id;
                    userForm.value = { id: null, username: '', password: '', realname: '', role_id: defaultRoleId, hotel_id: defaultHotelId, status: 1 };
                }
                showUserModal.value = true;
            };

            const saveUser = async () => {
                const isEdit = !!userForm.value.id;
                const url = isEdit ? `/users/${userForm.value.id}` : '/users';
                const method = isEdit ? 'PUT' : 'POST';
                const data = { ...userForm.value };
                if (!data.password) delete data.password;
                const res = await request(url, { method, body: JSON.stringify(data) });
                if (res.code === 200) {
                    showToast(isEdit ? '更新成功' : '创建成功');
                    showUserModal.value = false;
                    loadUsers();
                } else {
                    showToast(res.message || '操作失败', 'error');
                }
            };

            const deleteUser = async (u) => {
                if (!confirm(`确定要删除用户"${u.username}"吗？`)) return;
                const res = await request(`/users/${u.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadUsers();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };

            // 角色操作
            const loadRolesList = async () => {
                const res = await request('/roles');
                if (res.code === 200) rolesList.value = res.data || [];
            };

            const loadAllPermissions = async () => {
                const res = await request('/roles/permissions');
                if (res.code === 200) allPermissions.value = res.data || [];
            };

            const openRoleModal = async (r = null) => {
                await loadAllPermissions();
                if (r) {
                    // 处理permissions，可能是JSON字符串或数组
                    let perms = [];
                    if (r.permissions) {
                        perms = typeof r.permissions === 'string' ? JSON.parse(r.permissions) : r.permissions;
                    }
                    // 确保perms是数组
                    if (!Array.isArray(perms)) perms = [];
                    roleForm.value = { 
                        id: r.id, 
                        name: r.name, 
                        display_name: r.display_name, 
                        description: r.description || '', 
                        level: r.level, 
                        status: r.status, 
                        permissionList: [...perms] 
                    };
                } else {
                    roleForm.value = { id: null, name: '', display_name: '', description: '', level: 1, status: 1, permissionList: [] };
                }
                showRoleModal.value = true;
            };

            const saveRole = async () => {
                const isEdit = !!roleForm.value.id;
                const url = isEdit ? `/roles/${roleForm.value.id}` : '/roles';
                const method = isEdit ? 'PUT' : 'POST';
                const data = { ...roleForm.value, permissions: roleForm.value.permissionList };
                delete data.permissionList;
                const res = await request(url, { method, body: JSON.stringify(data) });
                if (res.code === 200) {
                    showToast(isEdit ? '更新成功' : '创建成功');
                    showRoleModal.value = false;
                    loadRolesList();
                    loadRoles();
                } else {
                    showToast(res.message || '操作失败', 'error');
                }
            };

            const deleteRole = async (r) => {
                if (!confirm(`确定要删除角色"${r.display_name}"吗？`)) return;
                const res = await request(`/roles/${r.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadRolesList();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };

            const togglePermission = (key) => {
                const index = roleForm.value.permissionList.indexOf(key);
                if (index > -1) {
                    roleForm.value.permissionList.splice(index, 1);
                } else {
                    roleForm.value.permissionList.push(key);
                }
            };

            // 权限操作
            const openPermissionModal = async (u) => {
                permissionUser.value = u;
                // 权限从角色继承，不再单独设置
                userPermissions.value = [];
                showPermissionModal.value = true;
            };

            const hasHotelPermission = (hotelId) => {
                if (!Array.isArray(userPermissions.value)) return false;
                return userPermissions.value.some(p => p.hotel_id === hotelId);
            };

            const getPermissionData = (hotelId) => {
                if (!Array.isArray(userPermissions.value)) {
                    userPermissions.value = [];
                }
                let perm = userPermissions.value.find(p => p.hotel_id === hotelId);
                if (!perm) {
                    perm = {
                        hotel_id: hotelId,
                        can_view_report: 0,
                        can_fill_daily_report: 0,
                        can_fill_monthly_task: 0,
                        can_edit_report: 0,
                        can_delete_report: 0,
                        can_view_online_data: 0,
                        can_fetch_online_data: 0,
                        can_delete_online_data: 0,
                        is_primary: 0
                    };
                    userPermissions.value.push(perm);
                }
                return perm;
            };

            const toggleHotelPermission = (hotelId) => {
                if (!Array.isArray(userPermissions.value)) {
                    userPermissions.value = [];
                }
                const index = userPermissions.value.findIndex(p => p.hotel_id === hotelId);
                if (index >= 0) {
                    userPermissions.value.splice(index, 1);
                } else {
                    userPermissions.value.push({
                        hotel_id: hotelId,
                        can_view_report: 1,
                        can_fill_daily_report: 1,
                        can_fill_monthly_task: 1,
                        can_edit_report: 0,
                        can_delete_report: 0,
                        can_view_online_data: 1,
                        can_fetch_online_data: 0,
                        can_delete_online_data: 0,
                        is_primary: 0
                    });
                }
            };

            const savePermissions = async () => {
                showToast('权限已从角色继承，无需单独设置', 'info');
                showPermissionModal.value = false;
            };

            // 日报表操作
            const openDailyReportModal = async (report = null) => {
                // 重置导入状态
                importedFromFile.value = false;
                importStatus.value = { show: false, type: '', message: '' };
                
                // 如果配置未加载，先加载配置
                if (dailyReportConfig.value.length === 0) {
                    await loadDailyReportConfig();
                }
                
                if (report) {
                    // 编辑时，合并报表数据
                    dailyReportForm.value = { 
                        id: report.id, 
                        hotel_id: report.hotel_id, 
                        report_date: report.report_date,
                        ...(report.report_data || {})
                    };
                } else {
                    // 新增时，初始化所有字段为0
                    const yesterday = new Date();
                    yesterday.setDate(yesterday.getDate() - 1);
                    const formData = { 
                        id: null, 
                        hotel_id: permittedHotels.value.length === 1 ? permittedHotels.value[0].id : '', 
                        report_date: yesterday.toISOString().split('T')[0]
                    };
                    // 初始化所有配置字段为0
                    dailyReportConfig.value.forEach(category => {
                        category.items.forEach(item => {
                            formData[item.field_name] = 0;
                        });
                    });
                    dailyReportForm.value = formData;
                }
                console.log('打开日报表弹窗，配置项数量:', dailyReportConfig.value.length);
                dailyReportTab.value = 'tab1'; // 重置到第一个标签页
                showDailyReportModal.value = true;
            };

            const saveDailyReport = async () => {
                const isEdit = !!dailyReportForm.value.id;
                const url = isEdit ? `/daily-reports/${dailyReportForm.value.id}` : '/daily-reports';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(dailyReportForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '更新成功' : '创建成功');
                    showDailyReportModal.value = false;
                    loadDailyReports();
                } else {
                    showToast(res.message || '操作失败', 'error');
                }
            };

            const deleteDailyReport = async (report) => {
                if (!confirm(`确定要删除该日报表吗？`)) return;
                const res = await request(`/daily-reports/${report.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadDailyReports();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };
            
            // 导入Excel相关函数
            const triggerImportExcel = () => {
                importFileInput.value?.click();
            };
            
            const triggerImportInModal = () => {
                importModalFileInput.value?.click();
            };
            
            const handleImportExcel = async (event) => {
                const file = event.target.files[0];
                if (!file) return;
                
                // 打开日报表填写模态框
                await openDailyReportModal();
                
                // 执行导入
                await doImportExcel(file);
                
                // 清空文件输入
                event.target.value = '';
            };
            
            const handleImportInModal = async (event) => {
                const file = event.target.files[0];
                if (!file) return;
                
                await doImportExcel(file);
                
                // 清空文件输入
                event.target.value = '';
            };
            
            const doImportExcel = async (file) => {
                importingExcel.value = true;
                importStatus.value = { show: true, type: 'info', message: '正在解析Excel文件...' };
                
                try {
                    const formData = new FormData();
                    formData.append('file', file);
                    
                    const response = await fetch(`${API_BASE}/daily-reports/parse-import`, {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token.value}`
                        },
                        body: formData
                    });
                    
                    const text = await response.text();
                    let res = null;
                    if (text) {
                        try {
                            res = JSON.parse(text);
                        } catch (err) {
                            throw new Error('导入失败：服务端返回非JSON内容');
                        }
                    }
                    if (!res) {
                        throw new Error(`导入失败：服务端无响应内容（HTTP ${response.status}）`);
                    }
                    console.log('后端返回:', res);
                    
                    if (res.code === 200 && res.data) {
                        const data = res.data;
                        
                        // 存储预览数据
                        importPreviewData.value = data;
                        
                        // 打印完整调试信息
                        console.log('=== Excel导入调试信息 ===');
                        console.log('酒店名:', data.hotel_name);
                        console.log('日期:', data.report_date);
                        console.log('已匹配字段数:', data.matched_count);
                        console.log('未匹配项目数:', data.unmatched_count);
                        console.log('mapped_data:', data.mapped_data);
                        
                        // 标记为从文件导入（锁定日期和酒店）
                        importedFromFile.value = true;
                        
                        // 设置酒店ID（根据酒店名称匹配）
                        if (data.hotel_name) {
                            const hotel = permittedHotels.value.find(h => 
                                h.name === data.hotel_name || 
                                h.name.includes(data.hotel_name) || 
                                data.hotel_name.includes(h.name)
                            );
                            if (hotel) {
                                dailyReportForm.value.hotel_id = hotel.id;
                                console.log('匹配到酒店:', hotel.name, 'ID:', hotel.id);
                            } else {
                                console.warn('未匹配到酒店:', data.hotel_name, '已有酒店:', permittedHotels.value.map(h => h.name));
                            }
                        }
                        
                        // 设置日期
                        if (data.report_date) {
                            dailyReportForm.value.report_date = data.report_date;
                        }
                        
                        // 填充报表数据
                        const reportData = data.mapped_data || {};
                        console.log('导入数据 mapped_data:', reportData);
                        
                        // 初始化映射状态
                        existingMappings.value = {};
                        rowMappings.value = {};
                        
                        // 从API返回的映射配置构建已映射关系
                        if (data.field_mappings) {
                            data.field_mappings.forEach(m => {
                                if (reportData[m.system_field] !== undefined) {
                                    existingMappings.value[m.excel_item_name] = m.system_field;
                                }
                            });
                        }
                        
                        // 直接遍历赋值
                        Object.keys(reportData).forEach(key => {
                            dailyReportForm.value[key] = reportData[key];
                        });
                        
                        // 强制刷新视图
                        const temp = dailyReportForm.value;
                        dailyReportForm.value = { ...temp };
                        
                        console.log('导入后表单:', dailyReportForm.value);
                        
                        // 构建状态消息
                        const fieldCount = data.matched_count || Object.keys(reportData).length;
                        const unmatchedCount = data.unmatched_count || 0;
                        
                        let msg = `导入成功！酒店: ${data.hotel_name || '待选择'}, 日期: ${data.report_date || '待填写'}`;
                        msg += `, 已填充 ${fieldCount} 个字段`;
                        if (unmatchedCount > 0) {
                            msg += ` (${unmatchedCount} 项未匹配)`;
                        }
                        
                        importStatus.value = { 
                            show: true, 
                            type: fieldCount > 0 ? 'success' : 'warning', 
                            message: msg
                        };
                        
                        // 如果有未匹配项，加载系统字段选项供手动映射
                        if (unmatchedCount > 0 && systemFieldOptions.value.length === 0) {
                            await loadSystemFieldOptions();
                        }
                        
                        // 8秒后隐藏提示
                        setTimeout(() => {
                            importStatus.value.show = false;
                        }, 8000);
                    } else {
                        importStatus.value = { 
                            show: true, 
                            type: 'error', 
                            message: res.message || '解析失败，请检查文件格式' 
                        };
                    }
                } catch (e) {
                    console.error('导入失败:', e);
                    importStatus.value = { 
                        show: true, 
                        type: 'error', 
                        message: '导入失败：' + (e.message || '网络错误') 
                    };
                } finally {
                    importingExcel.value = false;
                }
            };
            
            // 应用手动映射
            const applyManualMapping = () => {
                if (!importPreviewData.value) return;
                
                // 将手动映射应用到表单
                Object.keys(manualMappings.value).forEach(excelItemName => {
                    const systemField = manualMappings.value[excelItemName];
                    if (systemField) {
                        // 从未匹配项中找到值
                        const item = importPreviewData.value.unmatched_items?.find(i => i.item_name === excelItemName);
                        if (item) {
                            // 解析值
                            let value = item.value_today;
                            if (typeof value === 'string' && value.includes('%')) {
                                value = parseFloat(value.replace('%', '')) / 100;
                            } else {
                                value = parseFloat(value) || 0;
                            }
                            dailyReportForm.value[systemField] = value;
                        }
                    }
                });
                
                // 清空手动映射
                manualMappings.value = {};
                showToast('手动映射已应用');
            };
            
            // 获取映射状态
            const getMappingStatus = (item) => {
                if (!item || item.item_name === '项目') return '';
                
                // 检查是否在已有映射中
                const existingField = existingMappings.value[item.item_name];
                if (existingField) return 'mapped';
                
                // 检查是否在手动映射中
                const manualField = rowMappings.value[item.item_name];
                if (manualField) return 'manual';
                
                return '';
            };
            
            // 处理映射变化
            const onMappingChange = (item, event) => {
                const newField = event.target.value;
                if (newField) {
                    rowMappings.value[item.item_name] = newField;
                } else {
                    delete rowMappings.value[item.item_name];
                }
            };
            
            // 应用所有映射
            const applyAllMappings = () => {
                if (!importPreviewData.value) return;
                
                // 合并已有映射和手动映射
                const allMappings = { ...existingMappings.value, ...rowMappings.value };
                let appliedCount = 0;
                
                importPreviewData.value.structured_data.forEach(item => {
                    const systemField = allMappings[item.item_name];
                    if (systemField && item.item_name !== '项目') {
                        let value = item.value_today;
                        if (typeof value === 'string' && value.includes('%')) {
                            value = parseFloat(value.replace('%', '')) / 100;
                        } else {
                            value = parseFloat(value) || 0;
                        }
                        dailyReportForm.value[systemField] = value;
                        appliedCount++;
                    }
                });
                
                // 更新已有映射
                Object.assign(existingMappings.value, rowMappings.value);
                rowMappings.value = {};
                
                showToast(`已应用 ${appliedCount} 个字段映射`);
            };
            
            // 保存为新映射配置
            const saveAsMappingConfig = async () => {
                const newMappings = Object.entries(rowMappings.value).filter(([_, field]) => field);
                if (newMappings.length === 0) {
                    showToast('请先选择要保存的映射', 'error');
                    return;
                }
                
                if (!confirm(`确定要将 ${newMappings.length} 个映射保存为配置吗？`)) return;
                
                try {
                    const mappings = newMappings.map(([excelName, systemField]) => ({
                        excel_item_name: excelName,
                        system_field: systemField,
                        field_type: 'number',
                        value_column: 'E',
                        is_active: 1
                    }));
                    
                    const res = await request('/field-mappings/batchUpdate', {
                        method: 'POST',
                        body: JSON.stringify({ mappings })
                    });
                    
                    if (res.code === 200) {
                        showToast(`保存成功：新建 ${res.data.created} 条，更新 ${res.data.updated} 条`);
                        // 更新已有映射
                        Object.assign(existingMappings.value, rowMappings.value);
                        rowMappings.value = {};
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败：' + e.message, 'error');
                }
            };

            // 查看日报表详情
            const viewDailyReport = async (report) => {
                showViewReportModal.value = true;
                viewReportLoading.value = true;
                viewReportData.value = null;
                if (report?.id) {
                    currentViewingReportId.value = report.id;
                }
                
                try {
                    const res = await request(`/daily-reports/${report.id}/detail`);
                    if (res.code === 200) {
                        viewReportData.value = res.data;
                        if (user.value?.is_super_admin) {
                            viewMappingConfig.value = res.data.view_mapping?.config ? [...res.data.view_mapping.config] : [];
                        }
                    } else {
                        showToast(res.message || '获取详情失败', 'error');
                        showViewReportModal.value = false;
                    }
                } catch (e) {
                    showToast('获取详情失败', 'error');
                    showViewReportModal.value = false;
                } finally {
                    viewReportLoading.value = false;
                }
            };

            // 复制日报表内容
            const copyReportContent = () => {
                if (!viewReportData.value) return;
                
                const d = viewReportData.value;
                const content = `${d.hotel_name}
${d.report_date}
总房间数: ${d.total_rooms}间 （可售：${d.salable_rooms}维修：${d.maintenance_rooms}）
一、销售业绩 ：
1、月营收总目标:  ${d.month_revenue_target}元
2、月累计完成营收:${d.month_revenue}元 （房费收入: ${d.month_room_revenue}元+其它收入:${d.month_other_revenue}元）当期差额:${d.month_revenue_diff}元
3、月当期完成率:${d.month_complete_rate}%
4、日营收当期目标:${d.day_revenue_target}元
5、日实际完成营收:${d.day_revenue}元（房费收入${d.day_room_revenue}元+其它收入:${d.day_other_revenue}元），当日差额:${d.day_revenue_diff}元
6、月综合出租率:${d.month_occ_rate}%
7、日综合出租率:${d.day_occ_rate}%
8、日过夜出租率:${d.day_overnight_occ_rate}%
9、日出租总数:${d.day_total_rooms}间 （过夜房:${d.day_overnight_rooms}间,非过夜房:0间,钟点房:${d.day_hourly_rooms}间）
10、月均价:${d.month_adr}元
11、日均价:${d.day_adr}元
12、过夜均价:${d.overnight_adr}元
13、月Revpar:${d.month_revpar}元
14、日Revpar:${d.day_revpar}元
15、当日储值金额:${d.day_stored_value}元
16、当月储值金额:${d.month_stored_value}元
二、客源结构  ：   
17、会员:${d.member_count}
18、协议: ${d.protocol_count}
19、散客:${d.walkin_count}
20、团队: ${d.group_count}
21、OTA总量:${d.ota_total_rooms}间,（美团${d.mt_rooms}间、携程${d.xb_rooms}间、同程${d.tc_rooms}间、去哪儿${d.qn_rooms}间、智行${d.zx_rooms}间、飞猪${d.fliggy_rooms}间）
22、微信:${d.wechat_count}单 ，抖音:${d.dy_count}单
23、会员体验价:${d.member_exp_rooms}
24、网络体验价:${d.web_exp_rooms}
25、本日免费房数:${d.free_rooms}间
三、直销指标:
26、日新增会员:${d.day_new_member}个
27、月新增会员目标：${d.month_new_member_target}个；现完成：${d.month_new_member}个 当期差额:${d.month_member_diff}个
28、日微信加粉:完成${d.day_wechat_add}个；
29、月微信加粉目标：${d.month_wechat_target}个，实际完成${d.month_wechat_add}个；当期差额:${d.month_wechat_diff}个
32、日私域流量：${d.day_private_rooms}单；营收:${d.day_private_revenue}元；
33、月私域流量：${d.month_private_rooms}单，营收:${d.month_private_revenue}元；占比总营收:${d.private_rate}%
四、OTA渠道评分值：
34、日点评新增：${d.day_good_review}好评条；${d.day_bad_review}差评条
35、月点评新增：${d.month_good_review}好评条；${d.month_bad_review}差评条
五、免费房数：
36、本月免费房总数:${d.month_free_rooms}间
六、明日预订数量: ${d.tomorrow_booking}
七、今日现金收入: ${d.day_cash_income}
八、当月累计现金余额: ${d.month_cash_income}`;

                navigator.clipboard.writeText(content).then(() => {
                    showToast('复制成功！');
                }).catch(() => {
                    // 降级方案
                    const textarea = document.createElement('textarea');
                    textarea.value = content;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    showToast('复制成功！');
                });
            };

            // 导出日报表（批量）
            const exportDailyReports = async () => {
                if (!filterReportStartDate.value || !filterReportEndDate.value) {
                    showToast('请选择日期范围', 'error');
                    return;
                }
                
                exportingReports.value = true;
                
                try {
                    const params = new URLSearchParams({
                        start_date: filterReportStartDate.value,
                        end_date: filterReportEndDate.value,
                    });
                    
                    // 酒店ID：优先使用选择的酒店，单门店用户自动使用其唯一酒店
                    let hotelId = filterReportHotel.value;
                    if (!hotelId && !user.value.is_super_admin && permittedHotels.value.length === 1) {
                        hotelId = permittedHotels.value[0].id;
                    }
                    if (hotelId) {
                        params.append('hotel_id', hotelId);
                    }
                    
                    // 使用fetch下载文件
                    const response = await fetch(`${API_BASE}/daily-reports/export?${params.toString()}`, {
                        headers: {
                            'Authorization': `Bearer ${token.value}`
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('导出失败');
                    }
                    
                    // 获取文件名
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = '日报表汇总.xlsx';
                    if (contentDisposition) {
                        const match = contentDisposition.match(/filename="?([^"]+)"?/);
                        if (match) {
                            filename = decodeURIComponent(match[1]);
                        }
                    }
                    
                    // 下载文件
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    
                    showToast('导出成功！');
                } catch (e) {
                    showToast('导出失败: ' + e.message, 'error');
                } finally {
                    exportingReports.value = false;
                }
            };

            // 导出单个日报表
            const exportSingleReport = async (report) => {
                try {
                    const response = await fetch(`${API_BASE}/daily-reports/export?id=${report.id}`, {
                        headers: {
                            'Authorization': `Bearer ${token.value}`
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('导出失败');
                    }
                    
                    // 获取文件名
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = `日报表_${report.hotel?.name || ''}_${report.report_date}.xlsx`;
                    if (contentDisposition) {
                        const match = contentDisposition.match(/filename="?([^"]+)"?/);
                        if (match) {
                            filename = decodeURIComponent(match[1]);
                        }
                    }
                    
                    // 下载文件
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    
                    showToast('导出成功！');
                } catch (e) {
                    showToast('导出失败: ' + e.message, 'error');
                }
            };

            // 从详情弹窗导出当前查看的报表
            const exportViewedReport = async () => {
                if (!viewReportData.value) return;
                
                // 从当前查看的数据中获取报表ID
                const report = dailyReports.value.find(r => 
                    r.report_date === viewReportData.value.report_date && 
                    r.hotel?.name === viewReportData.value.hotel_name
                );
                
                if (report) {
                    await exportSingleReport(report);
                } else {
                    showToast('无法找到对应报表', 'error');
                }
            };

            // 日报查看映射（仅超管）
            const viewMappingConfig = ref([]);
            const viewMappingSaving = ref(false);
            const showViewMappingModal = ref(false);
            const viewMappingTab = ref('sales');
            const viewMappingProjectOptions = computed(() => {
                const names = [];
                const addName = (n) => {
                    const name = (n || '').trim();
                    if (name && !names.includes(name)) names.push(name);
                };
                // 已保存的查看项目
                viewMappingConfig.value.forEach(item => addName(item.name));
                // 当前查看返回的项目
                const serverConfig = viewReportData.value?.view_mapping?.config || [];
                serverConfig.forEach(item => addName(item.name));
                return names;
            });
            const viewMappingFieldOptions = computed(() => {
                return viewReportData.value?.view_mapping_sources || { report: [], task: [], month: [], calc: [] };
            });

            const viewMappingCategories = [
                { id: 'sales', label: '销售业绩' },
                { id: 'guest', label: '客源结构' },
                { id: 'direct', label: '直销指标' },
                { id: 'review', label: '点评新增' },
                { id: 'free', label: '免费房数' },
                { id: 'booking', label: '明日预订' },
                { id: 'cash', label: '现金' },
                { id: 'other', label: '其他' },
            ];
            const getViewMappingCategory = (name) => {
                if (!name) return 'other';
                if (/营收|出租率|Revpar|均价|储值|收入|营收/.test(name)) return 'sales';
                if (/会员|协议|散客|团队|OTA总量|微信|体验价|免费房数/.test(name)) return 'guest';
                if (/新增会员|微信加粉|私域/.test(name)) return 'direct';
                if (/点评新增|好评|差评/.test(name)) return 'review';
                if (/免费房总数/.test(name)) return 'free';
                if (/明日预订/.test(name)) return 'booking';
                if (/现金/.test(name)) return 'cash';
                return 'other';
            };
            const viewMappingFilteredIndexes = computed(() => {
                return viewMappingConfig.value
                    .map((item, idx) => ({ item, idx }))
                    .filter(({ item }) => getViewMappingCategory(item.name) === viewMappingTab.value)
                    .map(({ idx }) => idx);
            });

            const addViewMappingRow = () => {
                viewMappingConfig.value.push({ name: '', source: 'report', field: '', formula: '' });
            };
            const removeViewMappingRow = (index) => {
                viewMappingConfig.value.splice(index, 1);
            };
            const openViewMappingModal = () => {
                if (!user.value?.is_super_admin) return;
                showViewMappingModal.value = true;
            };
            const saveViewMappingConfig = async () => {
                if (!user.value?.is_super_admin) return;
                viewMappingSaving.value = true;
                try {
                    const res = await request('/daily-reports/view-mapping', {
                        method: 'POST',
                        body: JSON.stringify({ mapping: viewMappingConfig.value })
                    });
                    if (res.code === 200) {
                        showToast('映射配置已保存');
                        if (currentViewingReportId.value) {
                            await viewDailyReport({ id: currentViewingReportId.value });
                        }
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    showToast('保存失败：' + e.message, 'error');
                } finally {
                    viewMappingSaving.value = false;
                }
            };

            // 月任务操作
            const openMonthlyTaskModal = async (task = null) => {
                // 如果配置未加载，先加载配置
                if (monthlyTaskConfig.value.length === 0) {
                    await loadMonthlyTaskConfig();
                }
                
                if (task) {
                    // 编辑时，合并任务数据
                    monthlyTaskForm.value = { 
                        id: task.id, 
                        hotel_id: task.hotel_id, 
                        year: task.year, 
                        month: task.month,
                        ...(task.task_data || {})
                    };
                } else {
                    // 新增时，初始化所有字段为0
                    const now = new Date();
                    const formData = { 
                        id: null, 
                        hotel_id: permittedHotels.value.length === 1 ? permittedHotels.value[0].id : '', 
                        year: now.getFullYear(), 
                        month: now.getMonth() + 1
                    };
                    // 初始化所有配置字段为0
                    monthlyTaskConfig.value.forEach(item => {
                        formData[item.field_name] = 0;
                    });
                    monthlyTaskForm.value = formData;
                }
                console.log('打开月任务弹窗，配置项数量:', monthlyTaskConfig.value.length);
                showMonthlyTaskModal.value = true;
            };

            const saveMonthlyTask = async () => {
                const isEdit = !!monthlyTaskForm.value.id;
                const url = isEdit ? `/monthly-tasks/${monthlyTaskForm.value.id}` : '/monthly-tasks';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(monthlyTaskForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '更新成功' : '创建成功');
                    showMonthlyTaskModal.value = false;
                    loadMonthlyTasks();
                } else {
                    showToast(res.message || '操作失败', 'error');
                }
            };

            const deleteMonthlyTask = async (task) => {
                if (!confirm(`确定要删除该月任务吗？`)) return;
                const res = await request(`/monthly-tasks/${task.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadMonthlyTasks();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };

            // 报表配置操作
            const openReportConfigModal = (config = null) => {
                if (config) {
                    reportConfigForm.value = { ...config };
                } else {
                    reportConfigForm.value = { 
                        id: null, 
                        report_type: 'daily', 
                        field_name: '', 
                        display_name: '', 
                        field_type: 'number', 
                        unit: '', 
                        options: '', 
                        sort_order: 0, 
                        is_required: 0, 
                        status: 1 
                    };
                }
                showReportConfigModal.value = true;
            };

            const saveReportConfig = async () => {
                const isEdit = !!reportConfigForm.value.id;
                const url = isEdit ? `/report-configs/${reportConfigForm.value.id}` : '/report-configs';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(reportConfigForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '更新成功' : '创建成功');
                    showReportConfigModal.value = false;
                    loadReportConfigs();
                } else {
                    showToast(res.message || '操作失败', 'error');
                }
            };

            const deleteReportConfig = async (config) => {
                if (!confirm(`确定要删除配置项"${config.display_name}"吗？`)) return;
                const res = await request(`/report-configs/${config.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('删除成功');
                    loadReportConfigs();
                } else {
                    showToast(res.message || '删除失败', 'error');
                }
            };

            // 系统配置操作
            const openSystemConfigModal = async () => {
                console.log('openSystemConfigModal called');
                console.log('user:', user.value);
                console.log('is_super_admin:', user.value?.is_super_admin);
                
                // 检查是否是超级管理员
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能修改系统配置', 'error');
                    return;
                }
                
                // 确保系统配置数据已加载
                if (!systemConfig.value || Object.keys(systemConfig.value).length === 0) {
                    console.log('Loading system config...');
                    await loadSystemConfig();
                }
                
                console.log('systemConfig:', systemConfig.value);
                
                // 复制数据到表单，确保所有字段都有值
                const defaults = {
                    system_name: '宿析OS',
                    logo_url: '',
                    favicon_url: '',
                    system_description: '深度数据分析赋能酒店收益与决策',
                    system_keywords: '酒店管理,收益分析,数据分析',
                    menu_hotel_name: '酒店管理',
                    menu_users_name: '用户管理',
                    menu_daily_report_name: '日报表管理',
                    menu_monthly_task_name: '月任务管理',
                    menu_report_config_name: '报表配置',
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
                    notify_daily_report: '0',
                    notify_monthly_task: '0'
                };
                
                systemConfigForm.value = {
                    ...defaults,
                    ...systemConfig.value
                };
                
                console.log('systemConfigForm:', systemConfigForm.value);
                showSystemConfigModal.value = true;
                console.log('showSystemConfigModal set to:', showSystemConfigModal.value);
            };

            const saveSystemConfig = async () => {
                console.log('saveSystemConfig called');
                console.log('systemConfigForm:', systemConfigForm.value);
                
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能修改系统配置', 'error');
                    return;
                }
                
                // 验证表单数据
                if (!systemConfigForm.value.system_name || systemConfigForm.value.system_name.trim() === '') {
                    showToast('系统名称不能为空', 'error');
                    return;
                }
                
                try {
                    showToast('正在保存配置...', 'info');
                    const res = await request('/system-config', {
                        method: 'PUT',
                        body: JSON.stringify(systemConfigForm.value)
                    });
                    console.log('save response:', res);
                    
                    if (res.code === 200) {
                        systemConfig.value = { ...systemConfig.value, ...res.data };
                        showToast('配置保存成功！');
                        showSystemConfigModal.value = false;
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    console.error('Save error:', e);
                    showToast('保存失败: ' + (e.message || '网络错误'), 'error');
                }
            };

            // 导出系统配置
            const exportSystemConfig = async () => {
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能导出配置', 'error');
                    return;
                }
                
                try {
                    const res = await request('/system-config/export', { method: 'GET' });
                    
                    // 创建下载链接
                    const blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = `system_config_${new Date().toISOString().slice(0, 10)}.json`;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                    
                    showToast('配置导出成功！');
                } catch (e) {
                    console.error('Export error:', e);
                    showToast('导出失败: ' + (e.message || '网络错误'), 'error');
                }
            };

            // 处理配置文件选择
            const handleConfigFileChange = (event) => {
                const file = event.target.files[0];
                if (!file) return;
                
                importConfigFile.value = file;
                
                // 预览配置
                const reader = new FileReader();
                reader.onload = (e) => {
                    try {
                        const data = JSON.parse(e.target.result);
                        importConfigPreview.value = {
                            count: Object.keys(data.configs || {}).length,
                            time: data.export_time || '未知',
                            version: data.version || '未知'
                        };
                    } catch (err) {
                        showToast('配置文件格式错误', 'error');
                        importConfigPreview.value = null;
                    }
                };
                reader.readAsText(file);
            };

            // 导入系统配置
            const importSystemConfig = async () => {
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能导入配置', 'error');
                    return;
                }
                
                if (!importConfigFile.value) {
                    showToast('请选择配置文件', 'error');
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('config_file', importConfigFile.value);
                    
                    showToast('正在导入配置...', 'info');
                    const res = await fetch(API_BASE + '/system-config/import', {
                        method: 'POST',
                        headers: {
                            'Authorization': token.value
                        },
                        body: formData
                    });
                    
                    const data = await res.json();
                    
                    if (data.code === 200) {
                        showToast(`配置导入成功！共导入 ${data.data.imported} 项`);
                        showImportConfigModal.value = false;
                        importConfigFile.value = null;
                        importConfigPreview.value = null;
                        // 重新加载配置
                        await loadSystemConfig();
                    } else {
                        showToast(data.message || '导入失败', 'error');
                    }
                } catch (e) {
                    console.error('Import error:', e);
                    showToast('导入失败: ' + (e.message || '网络错误'), 'error');
                }
            };

            // 重置系统配置
            const resetSystemConfig = async () => {
                if (!user.value?.is_super_admin) {
                    showToast('只有超级管理员才能重置配置', 'error');
                    return;
                }
                
                if (!confirm('确定要重置当前分组的配置吗？此操作不可恢复！')) {
                    return;
                }
                
                try {
                    showToast('正在重置配置...', 'info');
                    const res = await request('/system-config/reset', {
                        method: 'POST',
                        body: JSON.stringify({ group: activeConfigGroup.value })
                    });
                    
                    if (res.code === 200) {
                        showToast(`配置重置成功！共重置 ${res.data.reset_count} 项`);
                        // 重新加载配置
                        await loadSystemConfig();
                        // 刷新表单
                        openSystemConfigModal();
                    } else {
                        showToast(res.message || '重置失败', 'error');
                    }
                } catch (e) {
                    console.error('Reset error:', e);
                    showToast('重置失败: ' + (e.message || '网络错误'), 'error');
                }
            };

            // 初始化
            onMounted(() => {
                // 自动展开所有带子菜单的父级项
                autoExpandAllMenus();

                // 更新时间
                const updateTime = () => {
                    currentTime.value = new Date().toLocaleString('zh-CN');
                };
                updateTime();
                setInterval(updateTime, 1000);

                // 检查登录状态
                if (token.value) {
                    request('/auth/info').then(res => {
                        if (res.code === 200) {
                            user.value = res.data;
                            permittedHotels.value = res.data.permitted_hotels || [];
                            // 单门店用户自动选择其唯一的酒店
                            if (!user.value.is_super_admin && permittedHotels.value.length === 1) {
                                filterReportHotel.value = permittedHotels.value[0].id;
                                filterTaskHotel.value = permittedHotels.value[0].id;
                            }
                            isLoggedIn.value = true;
                            loadData();
                        } else {
                            // token 无效，清除本地存储
                            localStorage.removeItem('token');
                            token.value = '';
                        }
                    }).catch(() => {
                        // 请求失败，清除本地存储
                        localStorage.removeItem('token');
                        token.value = '';
                    });
                }
            });

            // 监听过滤条件变化
            watch([filterReportHotel, filterReportStartDate, filterReportEndDate], loadDailyReports);
            watch([filterTaskHotel, filterTaskYear], loadMonthlyTasks);

            // AI 筹建管理状态
            const aiStrategyParams = ref({
                city: '上海市 陆家嘴',
                area: 5000,
                audience: '高端商务',
                depth: '标准推演'
            });
            const aiStrategyResult = ref(null);
            
            const aiSimulationParams = ref({
                rooms: 120,
                adr: 450,
                occ: 82,
                nonRoomRevenueRatio: 15
            });
            const aiSimulationResult = ref(null);

            const aiFeasibilityResult = ref(null);

            // 方法：智略推演
            const handleStrategy = async () => {
                loading.value = true;
                try {
                    const res = await request('/ai/strategy', {
                        method: 'POST',
                        body: JSON.stringify(aiStrategyParams.value)
                    });
                    if (res.code === 200) {
                        aiStrategyResult.value = res.data;
                        showToast('推演成功');
                    } else {
                        showToast(res.message || '推演失败', 'error');
                    }
                } catch (err) {
                    showToast('推演异常: ' + err.message, 'error');
                } finally {
                    loading.value = false;
                }
            };

            // 方法：智算模拟
            const handleSimulation = async () => {
                loading.value = true;
                try {
                    const res = await request('/ai/simulation', {
                        method: 'POST',
                        body: JSON.stringify(aiSimulationParams.value)
                    });
                    if (res.code === 200) {
                        aiSimulationResult.value = res.data;
                        showToast('模拟测算成功');
                    } else {
                        showToast(res.message || '测算失败', 'error');
                    }
                } catch (err) {
                    showToast('测算异常: ' + err.message, 'error');
                } finally {
                    loading.value = false;
                }
            };

            // 方法：生成可行性报告
            const handleFeasibility = async () => {
                loading.value = true;
                try {
                    const res = await request('/ai/feasibility', {
                        method: 'POST'
                    });
                    if (res.code === 200) {
                        aiFeasibilityResult.value = res.data;
                        showToast('报告生成成功');
                    } else {
                        showToast(res.message || '报告生成失败', 'error');
                    }
                } catch (err) {
                    showToast('报告生成异常: ' + err.message, 'error');
                } finally {
                    loading.value = false;
                }
            };

            // 如果访问到了这些页面，初始调用一下（如果有需要）
            watch(currentPage, (newVal) => {
                if (newVal === 'ai-feasibility' && !aiFeasibilityResult.value) {
                    handleFeasibility();
                }
            });

            return {
                aiStrategyParams, aiStrategyResult, handleStrategy,
                aiSimulationParams, aiSimulationResult, handleSimulation,
                aiFeasibilityResult, handleFeasibility,
                isLoggedIn, loading, user, token, currentTime, currentPage, showPassword,
                loginForm, menuItems, visibleMenuItems, pageTitle, toast, handleMenuClick,
                expandedMenus, toggleSubmenu, autoExpandAllMenus,
                hotels, permittedHotels, hotelColumns, userColumns, users, roles, dailyReports, monthlyTasks, reportConfigs, dailyReportConfig, dailyReportTab, monthlyTaskConfig,
                searchHotel, filterHotelStatus, searchUser, filterUserRoleId,
                filterReportHotel, filterReportStartDate, filterReportEndDate,
                filterTaskHotel, filterTaskYear, filterConfigType, yearOptions, yesterdayDate,
                canViewReport, canFillDailyReport, canFillMonthlyTask, canEditReport, canDeleteReport,
                filteredReportConfigs, getFieldTypeLabel,
                systemConfig, systemConfigForm, showSystemConfigModal,
                activeConfigGroup, configGroups, menuConfigItems, featureSwitches,
                showImportConfigModal, importConfigFile, importConfigPreview,
                exportSystemConfig, handleConfigFileChange, importSystemConfig, resetSystemConfig,
                // 数据配置
                showDataConfigModal, currentDataConfigType, dataConfigTitle, testingConfig, savingConfig, dataConfigForm,
                openDataConfigModal, saveDataConfig, testDataConfig,
                showHotelModal, showUserModal, showPermissionModal, showDailyReportModal, showMonthlyTaskModal, showReportConfigModal,
                showViewReportModal, viewReportData, viewReportLoading, reportContentRef,
                viewDailyReport, copyReportContent, exportDailyReports, exportSingleReport, exportViewedReport,
                exportingReports, currentViewingReportId,
                viewMappingConfig, viewMappingSaving, showViewMappingModal, addViewMappingRow, removeViewMappingRow, saveViewMappingConfig, openViewMappingModal,
                viewMappingProjectOptions, viewMappingFieldOptions,
                viewMappingTab, viewMappingCategories, viewMappingFilteredIndexes,
                hotelForm, userForm, dailyReportForm, monthlyTaskForm, reportConfigForm,
                filteredHotels, filteredUsers,
                permissionUser, userPermissions,
                handleLogin, handleLogout, showToast,
                openHotelModal, saveHotel, deleteHotel, toggleHotelStatus,
                openUserModal, saveUser, deleteUser,
                rolesList, allPermissions, showRoleModal, roleForm, openRoleModal, saveRole, deleteRole, togglePermission,
                openPermissionModal, hasHotelPermission, getPermissionData, toggleHotelPermission, savePermissions,
                openDailyReportModal, saveDailyReport, deleteDailyReport,
                triggerImportExcel, triggerImportInModal, handleImportExcel, handleImportInModal,
                importFileInput, importModalFileInput, importStatus, importingExcel, importedFromFile,
                importPreviewData, manualMappings, applyManualMapping,
                rowMappings, existingMappings, getMappingStatus, onMappingChange,
                applyAllMappings, saveAsMappingConfig,
                openMonthlyTaskModal, saveMonthlyTask, deleteMonthlyTask,
                openReportConfigModal, saveReportConfig, deleteReportConfig,
                openSystemConfigModal, saveSystemConfig, getMenuItemName,
                // 线上数据获取
                onlineDataTab, downloadCenterTab, fetchingData, onlineDataResult, topTenHotels, ctripHotelsList, ctripTableTab, showRawData, ctripForm, ctripTrafficForm, meituanForm, meituanTrafficForm, meituanCommentForm, fetchingCommentData, meituanCommentSuccess, meituanCommentResult, showMeituanCommentHelp, customForm, newCookies, cookiesList, bookmarkletCode,
                quickCookiesName, quickCookiesValue, openTargetSite, saveQuickCookies,
                // 线上数据记录
                onlineDataFilter, onlineDataList, onlineDataPagination, onlineDataPage, onlineDataHotelList, onlineDataSummary,
                selectedOnlineDataIds, toggleSelectAllOnlineData, isAllOnlineDataSelected, batchDeleteOnlineData,
                autoFetchScheduleTime, saveFetchSchedule,
                loadOnlineDataList, loadOnlineDataHotelList, triggerAutoFetch, refreshOnlineData, changeOnlineDataPage, viewOnlineDataDetail, switchDownloadTab, switchToDownloadCenter, switchToMeituanDownloadCenter,
                editOnlineDataItem, deleteOnlineDataItem, showOnlineDataEditModal, onlineDataEditForm, saveOnlineDataEdit,
                toNumber, toFixedSafe, safeDivide, formatNumber, autoFetchEnabled, autoFetchStatus, toggleAutoFetch, loadAutoFetchStatus,
                fetchCtripData, fetchCtripTrafficData, fetchMeituanData, fetchMeituanTrafficData, fetchMeituanComments, useMeituanCommentConfig, formatCommentTime, getScoreClass, fetchCustomData, loadCookiesList, saveCookiesConfig, deleteCookiesConfig, useCookies, copyOnlineDataResult, copyToClipboard, copyBookmarklet, copyCookieScript, saveMeituanConfig, loadMeituanConfig,
                // 携程配置管理
                ctripConfigForm, ctripConfigList, ctripBookmarklet, showCtripCookieGuide, selectedCtripConfigId,
                ctripFetchSuccess, ctripSavedCount,
                loadCtripConfigList, saveCtripConfig, useCtripConfig, editCtripConfig, deleteCtripConfig, generateCtripBookmarklet, openTargetSite, applyCtripConfig,
                // 美团配置管理
                meituanConfigForm, meituanConfigList, meituanBookmarklet,
                meituanHotelsList, meituanFetchSuccess, meituanSavedCount, meituanDataFetchTime,
                meituanSortField, meituanSortOrder, sortMeituanTable,
                loadMeituanConfigList, saveMeituanConfigItem, useMeituanConfig, editMeituanConfig, deleteMeituanConfigItem, generateMeituanBookmarklet,
                // AI智能分析（携程）
                aiSelectedHotels, aiAnalysisHotelList, aiAnalyzing, aiAnalysisResult, aiAnalysisHistory,
                selectAllAiHotels, clearAiHotelSelection, startAiAnalysis, copyAiAnalysisResult, viewAiAnalysisRecord,
                // AI智能分析（美团）
                meituanAiSelectedHotels, meituanAiAnalysisHotelList, meituanAiAnalyzing, meituanAiAnalysisResult, meituanAiAnalysisHistory, showMeituanAIAnalysis, meituanCompetitionIntensity,
                selectAllMeituanAiHotels, clearMeituanAiHotelSelection, startMeituanAiAnalysis, copyMeituanAiAnalysisResult, viewMeituanAiAnalysisRecord,
                // 数据分析
                analysisDimension, analysisData, loadAnalysisData,
                // 操作日志
                operationLogs, logModules, logActions, logUsers, logHotels, logFilter, logPagination, selectedLog, showLogDetailModal,
                loadOperationLogs, viewLogDetail,
                // 门店罗盘
                compassLayout, compassLayoutPanel, compassWeather, compassTodos, compassMetrics, compassAlerts, compassHolidays, compassMetricTab,
                loadCompassData, moveCompassBlock, toggleCompassBlock, saveCompassLayout, compassBlockLabel, getHotelName,
                // 竞对价格监控
                competitorTab, competitorHotels, competitorLogs, competitorDevices, competitorRobots,
                competitorHotelFilter, competitorLogFilter, competitorRobotFilter,
                showCompetitorHotelModal, competitorHotelForm, competitorStores,
                showCompetitorRobotModal, competitorRobotForm,
                loadCompetitorHotels, openCompetitorHotelModal, saveCompetitorHotel, deleteCompetitorHotel,
                loadCompetitorLogs, loadCompetitorDevices, loadCompetitorStores,
                loadCompetitorRobots, openCompetitorRobotModal, saveCompetitorRobot, deleteCompetitorRobot, testCompetitorRobot, getCompetitorStoreName,
                // Agent中心 - 基础
                agentTab, agentOverview, agentConfigs,
                staffAgentTab, knowledgeList, knowledgeCategories, knowledgeFilter,
                revenueAgentTab, priceSuggestions, priceSuggestionFilter,
                assetAgentTab, deviceList, deviceStats, deviceFilter, energyData,
                agentLogs, agentLogFilter,
                loadAgentOverview, saveAgentConfig,
                loadKnowledgeBase, openKnowledgeModal, saveKnowledge, deleteKnowledge,
                loadPriceSuggestions, approvePrice,
                loadDevices, openDeviceModal, saveDevice,
                loadEnergyData, loadAgentLogs,
                // Agent中心 - 智能员工增强
                workOrderList, workOrderFilter, workOrderStats, showWorkOrderModal, workOrderForm, staffDashboard,
                conversationList, conversationFilter, conversationStats,
                loadWorkOrders, createWorkOrder, assignWorkOrder, resolveWorkOrder, loadWorkOrderStats,
                loadConversations, loadConversationStats, loadStaffDashboard,
                // Agent中心 - 收益管理增强
                demandForecasts, forecastFilter, forecastAccuracy, highDemandDates, revenueDashboard,
                competitorAnalysis, competitorFilter,
                loadDemandForecasts, loadCompetitorAnalysis, loadRevenueDashboard,
                // Agent中心 - 资产运维增强
                energyBenchmarks, energySuggestions, suggestionFilter,
                maintenancePlans, maintenanceReminders, assetDashboard,
                loadEnergyBenchmarks, loadEnergySuggestions, generateEnergySuggestions,
                loadMaintenancePlans, loadMaintenanceReminders, loadAssetDashboard,
            };
        }
    }).mount('#app');
