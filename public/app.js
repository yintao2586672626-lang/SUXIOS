    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;

    const API_BASE = '/api';

    createApp({
        setup() {
            // 鐘舵€?
            const isLoggedIn = ref(false);
            const loading = ref(false);
            const user = ref(null);
            const token = ref(localStorage.getItem('token') || '');
            const currentTime = ref('');
            const currentPage = ref('hotels');

            // 鐧诲綍琛ㄥ崟
            const loginForm = ref({ username: 'admin', password: 'admin123' });
            const loginError = ref('');
            const showPassword = ref(false);
            const rememberUsername = ref(false); // 鐧诲綍閿欒鎰忔�?

            // 绯荤粺閰嶇疆
            const systemConfig = ref({
                system_name: '鏁版嵁娴侀噺VVVIP',
                logo_url: 'images/logo.svg',
                menu_hotel_name: '閰掑簵绠＄悊',
                menu_users_name: '鐢ㄦ埛绠＄悊',
                menu_daily_report_name: '鏃ユ姤琛ㄧ鐞?',
                menu_monthly_task_name: '鏈堜换鍔＄鐞?',
                menu_report_config_name: '鎶ヨ〃閰嶇疆',
                wechat_mini_appid: '',
                wechat_mini_secret: '',
                complaint_mini_page: 'pages/complaint/index',
                complaint_mini_use_scene: '1',
            });

            // 绾夸笂鏁版嵁鑾峰彇
            const onlineDataTab = ref('ctrip-ranking');
            const downloadCenterTab = ref('overview'); // 涓嬭浇涓績瀛怲ab: overview/traffic/ai/fetched
            const fetchingData = ref(false);
            const onlineDataResult = ref(null);
            const topTenHotels = ref([]); // 鍓嶅崄鍚嶉厭搴楁暟鎹?
            const ctripHotelsList = ref([]); // 鎼虹▼瀹屾暣閰掑簵鍒楄〃
            const ctripTableTab = ref('sales'); // 鎼虹▼鏁版嵁琛ㄦ牸Tab: sales/traffic/rank
            const showRawData = ref(false); // 鏄惁灞曞紑鍘熷鏁版嵁
            const ctripForm = ref({
                url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                nodeId: '24588',
                startDate: '',
                endDate: '',
                cookies: '',
                auth_data: {}, // 璁よ瘉鏁版嵁
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
                rankTypes: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL_EXPOSE'],  // 榛樿鍏ㄩ€?涓鍗?
                dateRanges: ['1'],    // 鏀寔澶氶€夋椂闂寸淮搴︼紝榛樿鏄ㄦ棩
                startDate: '',
                endDate: '',
                cookies: '',
                auth_data: {}, // 璁よ瘉鏁版嵁
            });
            const meituanTrafficForm = ref({
                url: '',
                startDate: '',
                endDate: '',
                cookies: '',
                extraParams: '',
            });
            // 缇庡洟宸瘎鑾峰彇琛ㄥ崟
            const meituanCommentForm = ref({
                requestUrl: '',      // 璇锋眰鍦板潃锛堢敤鎴峰～鍐欙紝浠庝腑鎻愬彇鍙傛暟锛?
                partnerId: '',       // 鑷姩鎻愬彇
                poiId: '',           // 鑷姩鎻愬彇
                mtsiEbU: '',         // 鑷姩鎻愬彇锛堝彲閫夛級
                cookies: '',         // 蹇呭～
                mtgsig: '',          // 蹇呭～
                replyType: '2',      // 2=宸瘎/寰呭洖澶?
                tag: '',
                limit: 50,
                startDate: '',
                endDate: '',
            });
            const fetchingCommentData = ref(false);
            const meituanCommentSuccess = ref(false); // 鑾峰彇鎴愬姛鐘舵€?
            const meituanCommentResult = ref(null);
            const showMeituanCommentHelp = ref(false);
            const showAdvancedConfig = ref(false);    // 进一步配置折叠状态

            // 计算属性：是否可以获取数据
            const canFetchComments = computed(() => {
                return meituanCommentForm.value.requestUrl && 
                       meituanCommentForm.value.mtgsig && 
                       meituanCommentForm.value.cookies;
            });

            // 计算属性：是否可以保存配置
            const canSaveConfig = computed(() => {
                return meituanCommentForm.value.partnerId && 
                       meituanCommentForm.value.poiId && 
                       meituanCommentForm.value.cookies;
            });

            // 解析请求URL，提取参数
            const parseRequestUrl = () => {
                try {
                    const url = meituanCommentForm.value.requestUrl;
                    if (!url || typeof url !== 'string') return;
                    
                    const trimmedUrl = url.trim();
                    if (!trimmedUrl) return;
                    
                    console.log('解析URL:', trimmedUrl.substring(0, 50) + '...');
                    
                    // 使用正则提取参数
                    const partnerMatch = trimmedUrl.match(/[?&]partnerId=([^&]+)/i);
                    const poiMatch = trimmedUrl.match(/[?&]poiId=([^&]+)/i);
                    const mtsiMatch = trimmedUrl.match(/[?&]_mtsi_eb_u=([^&]+)/i);
                    
                    // 鍏堟媶瀵瑰埌涓存椂瀵硅薄锛岀劧鍚庝竴娆℃€т慨鏀瑰搷搴斿紡瀵硅薄
                    const updates = {};
                    if (partnerMatch) {
                        updates.partnerId = partnerMatch[1];
                        console.log('提取 partnerId:', partnerMatch[1]);
                    }
                    if (poiMatch) {
                        updates.poiId = poiMatch[1];
                        console.log('提取 poiId:', poiMatch[1]);
                    }
                    if (mtsiMatch) {
                        updates.mtsiEbU = mtsiMatch[1];
                        console.log('提取 _mtsi_eb_u:', mtsiMatch[1]);
                    }
                    
                    // 鍚堝苟鏇存柊鍒板搷搴斿紡瀵硅薄
                    if (Object.keys(updates).length > 0) {
                        meituanCommentForm.value = { ...meituanCommentForm.value, ...updates };
                        showToast('参数提取成功', 'success');
                    }
                } catch (e) {
                    console.error('解析URL出错:', e);
                }
            };

            // 重置美团点评表单
            const resetMeituanCommentForm = () => {
                meituanCommentForm.value = {
                    requestUrl: '',
                    partnerId: '',
                    poiId: '',
                    mtsiEbU: '',
                    cookies: '',
                    mtgsig: '',
                    replyType: '2',
                    tag: '',
                    limit: 50,
                    startDate: '',
                    endDate: '',
                };
                meituanCommentResult.value = null;
                meituanCommentSuccess.value = false;
                showToast('表单已清空', 'success');
            };

            // 携程点评获取表单
            const ctripCommentForm = ref({
                requestUrl: '',      // 请求地址（用户填写，从中提取参数）
                hotelId: '',         // 自动提取或手动填写
                masterHotelId: '',   // 主酒店ID
                cookies: '',         // 必填
                token: '',           // 鉴权token，必填
                pageIndex: 1,
                pageSize: 50,
                startDate: '',
                endDate: '',
                tagType: '',         // 标签类型
            });
            const fetchingCtripCommentData = ref(false);
            const ctripCommentSuccess = ref(false);
            const ctripCommentResult = ref(null);
            const showCtripCommentHelp = ref(false);
            const showCtripAdvancedConfig = ref(false);
            const ctripCommentConfigList = ref([]);

            // 计算属性：是否可以获取携程点评数据
            const canFetchCtripComments = computed(() => {
                return ctripCommentForm.value.requestUrl && 
                       ctripCommentForm.value.token && 
                       ctripCommentForm.value.cookies;
            });

            // 计算属性：是否可以保存携程配置
            const canSaveCtripCommentConfig = computed(() => {
                return ctripCommentForm.value.hotelId && 
                       ctripCommentForm.value.cookies;
            });

            // 解析携程请求URL，提取参数
            const parseCtripRequestUrl = () => {
                try {
                    const url = ctripCommentForm.value.requestUrl;
                    if (!url || typeof url !== 'string') return;
                    
                    const trimmedUrl = url.trim();
                    if (!trimmedUrl) return;
                    
                    console.log('解析携程URL:', trimmedUrl.substring(0, 50) + '...');
                    
                    // 使用正则提取参数
                    const hotelMatch = trimmedUrl.match(/[?&]hotelId=([^&]+)/i);
                    const masterMatch = trimmedUrl.match(/[?&]masterHotelId=([^&]+)/i);
                    const tokenMatch = trimmedUrl.match(/[?&]token=([^&]+)/i);
                    
                    // 鍏堟媶瀵瑰埌涓存椂瀵硅薄锛岀劧鍚庝竴娆℃€т慨鏀瑰搷搴斿紡瀵硅薄
                    const updates = {};
                    if (hotelMatch) {
                        updates.hotelId = hotelMatch[1];
                        console.log('提取 hotelId:', hotelMatch[1]);
                    }
                    if (masterMatch) {
                        updates.masterHotelId = masterMatch[1];
                        console.log('提取 masterHotelId:', masterMatch[1]);
                    }
                    if (tokenMatch) {
                        updates.token = decodeURIComponent(tokenMatch[1]);
                        console.log('提取 token:', tokenMatch[1].substring(0, 20) + '...');
                    }
                    
                    // 鍚堝苟鏇存柊鍒板搷搴斿紡瀵硅薄
                    if (Object.keys(updates).length > 0) {
                        ctripCommentForm.value = { ...ctripCommentForm.value, ...updates };
                        showToast('参数提取成功', 'success');
                    }
                } catch (e) {
                    console.error('解析携程URL出错:', e);
                }
            };

            // 重置携程点评表单
            const resetCtripCommentForm = () => {
                ctripCommentForm.value = {
                    requestUrl: '',
                    hotelId: '',
                    masterHotelId: '',
                    cookies: '',
                    token: '',
                    pageIndex: 1,
                    pageSize: 50,
                    startDate: '',
                    endDate: '',
                    tagType: '',
                };
                ctripCommentResult.value = null;
                ctripCommentSuccess.value = false;
                showToast('表单已清空', 'success');
            };

            // 获取携程点评数据
            const fetchCtripComments = async () => {
                // 使用 toRaw 避免触发响应式更新
                const form = { ...ctripCommentForm.value };
                form.requestUrl = (form.requestUrl || '').trim();
                form.cookies = (form.cookies || '').replace(/^[\s\n]+|[\s\n]+$/g, '').replace(/\n/g, '');
                form.token = (form.token || '').trim();
                
                console.log('fetchCtripComments called', form);
                
                if (!form.requestUrl) {
                    showToast('请输入请求地址', 'error');
                    return;
                }
                if (!form.token) {
                    showToast('请输入Token', 'error');
                    return;
                }
                if (!form.cookies) {
                    showToast('请输入Cookies', 'error');
                    return;
                }
                if (!form.hotelId) {
                    showToast('请输入Hotel ID', 'error');
                    return;
                }
                
                fetchingCtripCommentData.value = true;
                ctripCommentResult.value = null;
                ctripCommentSuccess.value = false;
                
                try {
                    const result = await request('/fetch-ctrip-comments', {
                        method: 'POST',
                        body: JSON.stringify({
                            request_url: form.requestUrl,
                            hotel_id: form.hotelId,
                            master_hotel_id: form.masterHotelId,
                            cookies: form.cookies,
                            token: form.token,
                            page_index: form.pageIndex,
                            page_size: form.pageSize,
                            start_date: form.startDate,
                            end_date: form.endDate,
                            tag_type: form.tagType,
                        })
                    });
                    console.log('携程点评获取结果:', result);
                    
                    ctripCommentResult.value = result;
                    
                    if (result.code === 200) {
                        ctripCommentSuccess.value = true;
                        showToast(`获取成功，共 ${result.total || 0} 条评论`, 'success');
                    } else {
                        showToast(result.message || '获取失败', 'error');
                    }
                } catch (e) {
                    console.error('请求异常:', e);
                    showToast('请求失败: ' + e.message, 'error');
                } finally {
                    fetchingCtripCommentData.value = false;
                }
            };

            // 保存携程点评配置
            const saveCtripCommentConfig = async () => {
                // 浣跨敤鍝嶅紑杩愮鍒涘缓瀵硅薄鍓湰锛岄伩鍏嶇洿鎺ヤ慨鏀瑰搷搴斿紡瀵硅薄
                const form = { ...ctripCommentForm.value };
                console.log('=== 开始保存携程配置 ===');
                
                if (!form.hotelId) {
                    showToast('请先填写 Hotel ID', 'error');
                    return;
                }
                if (!form.cookies) {
                    showToast('请先填写 Cookies', 'error');
                    return;
                }
                
                try {
                    const result = await request('/save-ctrip-comment-config', {
                        method: 'POST',
                        body: JSON.stringify({
                            name: `携程点评-${form.hotelId}`,
                            request_url: form.requestUrl,
                            hotel_id: form.hotelId,
                            master_hotel_id: form.masterHotelId,
                            cookies: form.cookies,
                            token: form.token,
                        })
                    });
                    console.log('保存配置结果:', result);
                    
                    if (result.code === 200) {
                        showToast('配置保存成功', 'success');
                        await loadCtripCommentConfigList();
                    } else {
                        showToast(result.message || '保存失败', 'error');
                    }
                } catch (e) {
                    console.error('保存异常:', e);
                    showToast('保存失败: ' + e.message, 'error');
                }
            };

            // 加载携程点评配置列表
            const loadCtripCommentConfigList = async () => {
                try {
                    const result = await request('/get-ctrip-comment-config-list');
                    if (result.code === 200) {
                        ctripCommentConfigList.value = result.data || [];
                    }
                } catch (e) {
                    console.error('加载配置列表失败:', e);
                }
            };

            // 使用已保存的携程配置
            const useCtripCommentConfig = (config) => {
                // 浣跨敤鍝嶅紑杩愮鍒涘缓鏂扮殑瀵硅薄锛岄伩鍏嶇洿鎺ヤ慨鏀瑰搷搴斿紡瀵硅薄
                ctripCommentForm.value = {
                    ...ctripCommentForm.value,
                    requestUrl: config.request_url || '',
                    hotelId: config.hotel_id || '',
                    masterHotelId: config.master_hotel_id || '',
                    cookies: config.cookies || '',
                    token: config.token || '',
                };
                showToast('已应用配置: ' + config.name);
            };

            // 格式化携程点评时间
            const formatCtripCommentTime = (timestamp) => {
                if (!timestamp) return '';
                if (typeof timestamp === 'number') {
                    // 假设是毫秒时间戳
                    const date = new Date(timestamp);
                    return date.toLocaleString('zh-CN');
                }
                return timestamp;
            };

            // 获取携程评分样式类
            const getCtripScoreClass = (score) => {
                if (!score) return 'bg-gray-100 text-gray-600';
                const numScore = parseFloat(score);
                if (numScore >= 4.5) return 'bg-green-100 text-green-800';
                if (numScore >= 4.0) return 'bg-blue-100 text-blue-800';
                if (numScore >= 3.0) return 'bg-yellow-100 text-yellow-800';
                return 'bg-red-100 text-red-800';
            };

            const customForm = ref({
                url: '',
                method: 'GET',
                headers: '',
                body: '',
            });
            const newCookies = ref({ name: '', cookies: '', hotel_id: '' });
            const cookiesList = ref([]);
            const bookmarkletCode = ref('javascript:(function(){alert("璇峰厛鐧诲綍绯荤粺");})();');
            const quickCookiesName = ref('');
            const quickCookiesValue = ref('');

            // 鎼虹▼閰嶇疆绠＄悊
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
            const selectedCtripConfigId = ref(''); // 閫変腑鐨勬惡绋嬮厤缃甀D
            const ctripFetchSuccess = ref(false); // 鎼虹▼鑾峰彇鎴愬姛鏍囧織
            const ctripSavedCount = ref(0); // 鎼虹▼淇濆瓨鐨勬暟鎹潯鏁?

            // 缇庡洟閰嶇疆绠＄悊
            const meituanConfigForm = ref({
                id: null,
                name: '',
                partner_id: '',
                poi_id: '',
                cookies: '',
            });
            const meituanConfigList = ref([]);
            const meituanBookmarklet = ref('');
            const showConfigHelp = ref(false); // 鏄剧ず閰嶇疆鑾峰彇甯姪
            const meituanHotelsList = ref([]); // 缇庡洟閰掑簵鍒楄〃鏁版嵁
            const meituanFetchSuccess = ref(false); // 缇庡洟鑾峰彇鎴愬姛鏍囧織
            const meituanSavedCount = ref(0); // 缇庡洟淇濆瓨鐨勬暟鎹潯鏁?
            const meituanTableTab = ref('ranking'); // 缇庡洟鏁版嵁琛ㄦ牸Tab: ranking/traffic
            
            // AI鏅鸿兘鍒嗘瀽鐩稿叧锛堟惡绋嬩笓鐢級
            const aiSelectedHotels = ref([]); // 閫変腑鐨勯厭搴楀垪琛?
            const aiAnalysisHotelList = ref([]); // 鍙€夌殑閰掑簵鍒楄〃
            const aiAnalyzing = ref(false); // AI鍒嗘瀽涓爣蹇?
            const aiAnalysisResult = ref(''); // AI鍒嗘瀽缁撴灉
            const aiAnalysisHistory = ref([]); // AI鍒嗘瀽鍘嗗彶璁板綍
            
            // 缇庡洟AI鏅鸿兘鍒嗘瀽鐩稿叧
            const meituanAiSelectedHotels = ref([]); // 閫変腑鐨勯厭搴楀垪琛?
            const meituanAiAnalysisHotelList = ref([]); // 鍙€夌殑閰掑簵鍒楄〃
            const meituanAiAnalyzing = ref(false); // AI鍒嗘瀽涓爣蹇?
            const meituanAiAnalysisResult = ref(''); // AI鍒嗘瀽缁撴灉
            const meituanAiAnalysisHistory = ref([]); // AI鍒嗘瀽鍘嗗彶璁板綍
            
            // 绾夸笂鏁版嵁璁板綍鐩稿叧
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
                data_type: ''  // 榛樿涓嶇瓫閫夛紝鏄剧ず鎵€鏈夌被鍨?
            });
            
            const onlineDataList = ref([]);
            const onlineDataPagination = ref({ total: 0, page: 1, page_size: 30 });
            const onlineDataPage = ref(1);
            const onlineDataHotelList = ref([]);
            const onlineDataSummary = ref(null);
            const selectedOnlineDataIds = ref([]);
            const autoFetchScheduleTime = ref('10:00');

            // 闂ㄥ簵缃楃洏
            const compassLayout = ref({ order: ['weather', 'todo', 'metrics', 'alerts', 'holiday'], hidden: [] });
            const compassLayoutPanel = ref(false);
            const compassWeather = ref([]);
            const compassTodos = ref([]);
            const compassMetrics = ref({ day: {}, week: {}, month: {} });
            const compassAlerts = ref([]);
            const compassHolidays = ref([]);
            const compassMetricTab = ref('day');

            // 绔炲浠锋牸鐩戞帶
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

            // 鏁版嵁鍒嗘瀽鐩稿叧
            const analysisDimension = ref('day');
            const analysisData = ref({ summary: null, chart_data: null, hotel_ranking: [] });
            let analysisChart = null;
            
            // 鍔犺浇鏁版嵁鍒嗘瀽
            const loadAnalysisData = async (dimension = null) => {
                try {
                    // 濡傛灉浼犲叆缁村害鍙傛暟锛屽厛鏇存柊缁村害
                    if (dimension) {
                        analysisDimension.value = dimension;
                    }
                    console.log('鍔犺浇鏁版嵁鍒嗘瀽, 缁村害:', analysisDimension.value);
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
                    console.log('鏁版嵁鍒嗘瀽缁撴灉:', res.data);
                    if (res.code === 200) {
                        analysisData.value = res.data || { summary: null, chart_data: null, hotel_ranking: [] };
                        await nextTick();
                        renderAnalysisChart();
                    }
                } catch (error) {
                    console.error('鍔犺浇鍒嗘瀽鏁版嵁澶辫触:', error);
                }
            };
            
            // 娓叉煋鍒嗘瀽鍥捐〃
            const renderAnalysisChart = (retryCount = 0) => {
                // 妫€鏌hart.js鏄惁鍔犺浇锛堝绉嶆柟寮忔娴嬶級
                const ChartLib = window.Chart;
                if (!ChartLib) {
                    if (retryCount < 5) {
                        console.log(`Chart.js鏈姞杞斤紝绛夊緟閲嶈瘯 (${retryCount + 1}/5)...`);
                        setTimeout(() => renderAnalysisChart(retryCount + 1), 500);
                        return;
                    }
                    console.warn('Chart.js鍔犺浇澶辫触锛岃烦杩囧浘琛ㄦ覆鏌?');
                    return;
                }
                const canvas = document.getElementById('analysisChart');
                if (!canvas || !analysisData.value.chart_data) return;
                
                if (analysisChart) {
                    analysisChart.destroy();
                }
                
                const ctx = canvas.getContext('2d');
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
                            y: { type: 'linear', display: true, position: 'left', title: { display: true, text: '閿€鍞(楼)' } },
                            y1: { type: 'linear', display: true, position: 'right', title: { display: true, text: '鎴挎櫄/璁㈠崟' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });
            };
            
            // 鍏ㄩ€?鍙栨秷鍏ㄩ€?
            const toggleSelectAllOnlineData = (e) => {
                if (e.target.checked) {
                    selectedOnlineDataIds.value = onlineDataList.value.map(item => item.id);
                } else {
                    selectedOnlineDataIds.value = [];
                }
            };
            
            // 鍒ゆ柇鏄惁鍏ㄩ€?
            const isAllOnlineDataSelected = computed(() => {
                return onlineDataList.value.length > 0 && selectedOnlineDataIds.value.length === onlineDataList.value.length;
            });
            
            // 淇濆瓨杩愯鏃堕棿璁剧疆
            const saveFetchSchedule = async () => {
                if (!autoFetchScheduleTime.value) return;
                try {
                    const res = await request('/online-data/set-fetch-schedule', {
                        method: 'POST',
                        body: JSON.stringify({ schedule_time: autoFetchScheduleTime.value })
                    });
                    if (res.code === 200) {
                        showToast(`杩愯鏃堕棿宸茶缃负姣忓ぉ ${autoFetchScheduleTime.value}`);
                        loadAutoFetchStatus();
                    } else {
                        showToast(res.message || '璁剧疆澶辫触', 'error');
                    }
                } catch (error) {
                    showToast('璁剧疆澶辫触', 'error');
                }
            };
            
            // 鎵归噺鍒犻櫎
            const batchDeleteOnlineData = async () => {
                if (selectedOnlineDataIds.value.length === 0) {
                    showToast('璇烽€夋嫨瑕佸垹闄ょ殑鏁版嵁', 'error');
                    return;
                }
                if (!confirm(`纭畾瑕佸垹闄ら€変腑鐨?${selectedOnlineDataIds.value.length} 鏉℃暟鎹悧锛焅)) return;
                
                try {
                    const res = await request('/online-data/batch-delete', {
                        method: 'POST',
                        body: JSON.stringify({ ids: selectedOnlineDataIds.value })
                    });
                    if (res.code === 200) {
                        showToast(`鎴愬姛鍒犻櫎 ${res.data.deleted_count} 鏉℃暟鎹甡);
                        selectedOnlineDataIds.value = [];
                        refreshOnlineData();
                    } else {
                        showToast(res.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (error) {
                    showToast('鍒犻櫎澶辫触: ' + error.message, 'error');
                }
            };
            
            // 鑷姩鑾峰彇鐘舵€?
            const autoFetchEnabled = ref(false);
            const autoFetchStatus = ref({
                last_run_time: null,
                next_run_time: null,
                last_result: null
            });
            
            // 鏁板€煎伐鍏凤細閬垮厤瀛楃涓?绌哄€煎鑷存覆鏌撳紓甯?
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
            // 鏍煎紡鍖栨暟瀛?
            const formatNumber = (num) => {
                if (num === null || num === undefined) return '0';
                return toNumber(num, 0).toLocaleString();
            };
            
            // 鎵撳紑鐩爣缃戠珯
            const openTargetSite = (url) => {
                window.open(url, '_blank');
            };
            
            // 蹇€熶繚瀛?Cookies
            const saveQuickCookies = async () => {
                if (!quickCookiesName.value || !quickCookiesValue.value) {
                    showToast('璇峰～鍐欏悕绉板拰Cookies', 'error');
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
                    showToast('Cookies淇濆瓨鎴愬姛');
                    quickCookiesName.value = '';
                    quickCookiesValue.value = '';
                    loadCookiesList();
                } else {
                    showToast(res.message || '淇濆瓨澶辫触', 'error');
                }
            };

            // 鏌ョ湅绾夸笂鏁版嵁璇︽儏
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
                            // 鏇存柊AI鍒嗘瀽閰掑簵鍒楄〃
                            updateAiAnalysisHotelList();
                        } else {
                            showToast('璇ヨ褰曟棤璇︾粏鏁版嵁', 'warning');
                        }
                    } catch (e) {
                        showToast('鏁版嵁瑙ｆ瀽澶辫触', 'error');
                    }
                } else {
                    showToast('璇ヨ褰曟棤鍘熷鏁版嵁', 'warning');
                }
            };

            // 缂栬緫绾夸笂鏁版嵁
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
                        showToast('淇濆瓨鎴愬姛');
                        showOnlineDataEditModal.value = false;
                        loadOnlineDataList();
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (error) {
                    showToast('淇濆瓨澶辫触: ' + error.message, 'error');
                }
            };

            // 鍒犻櫎绾夸笂鏁版嵁
            const deleteOnlineDataItem = async (id) => {
                if (!confirm('纭畾瑕佸垹闄よ繖鏉℃暟鎹悧锛?')) return;
                try {
                    const res = await request('/online-data/delete-data', {
                        method: 'POST',
                        body: JSON.stringify({ id })
                    });
                    if (res.code === 200) {
                        showToast('鍒犻櫎鎴愬姛');
                        loadOnlineDataList();
                    } else {
                        showToast(res.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (error) {
                    showToast('鍒犻櫎澶辫触: ' + error.message, 'error');
                }
            };

            // 鍒囨崲涓嬭浇涓績Tab锛堣嚜鍔ㄥ姞杞芥暟鎹級
            const switchDownloadTab = async (tab) => {
                downloadCenterTab.value = tab;
                if (tab !== 'fetched') {
                    // 鏍规嵁褰撳墠椤甸潰璁剧疆鏁版嵁鏉ユ簮
                    if (onlineDataTab.value === 'ctrip-download' || onlineDataTab.value.startsWith('ctrip')) {
                        onlineDataFilter.value.source = 'ctrip';
                    } else if (onlineDataTab.value === 'meituan-download' || onlineDataTab.value.startsWith('meituan')) {
                        onlineDataFilter.value.source = 'meituan';
                    }
                    // 鍒囨崲鍒板巻鍙茶褰曘€佹祦閲忓垎鏋愩€丄I鍒嗘瀽鏃惰嚜鍔ㄥ姞杞芥暟鎹?
                    await loadOnlineDataList();
                    await loadOnlineDataHotelList();
                    
                    // AI鍒嗘瀽Tab闇€瑕佹洿鏂伴厭搴楀垪琛?
                    if (tab === 'ai') {
                        if (onlineDataTab.value === 'ctrip-download' || onlineDataTab.value.startsWith('ctrip')) {
                            updateAiAnalysisHotelList();
                        } else if (onlineDataTab.value === 'meituan-download' || onlineDataTab.value.startsWith('meituan')) {
                            updateMeituanAiAnalysisHotelList();
                        }
                    }
                }
            };

            // 鍒囨崲鍒颁笅杞戒腑蹇冿紙鑷姩鍔犺浇鏁版嵁锛?
            const switchToDownloadCenter = async () => {
                onlineDataTab.value = 'ctrip-download';
                // 璁剧疆鏁版嵁鏉ユ簮涓烘惡绋?
                onlineDataFilter.value.source = 'ctrip';
                // 濡傛灉榛樿瀛?tab 涓嶆槸 fetched锛堟渶鏂拌幏鍙栨暟鎹級锛岃嚜鍔ㄥ姞杞芥暟鎹?
                if (downloadCenterTab.value !== 'fetched') {
                    await loadOnlineDataList();
                    await loadOnlineDataHotelList();
                }
            };

            // 鍒囨崲鍒扮編鍥笅杞戒腑蹇冿紙鑷姩鍔犺浇鏁版嵁锛?
            const switchToMeituanDownloadCenter = async () => {
                onlineDataTab.value = 'meituan-download';
                // 璁剧疆鏁版嵁鏉ユ簮涓虹編鍥?
                onlineDataFilter.value.source = 'meituan';
                // 鑷姩鍔犺浇鏁版嵁
                await loadOnlineDataList();
                await loadOnlineDataHotelList();
            };

            // 鍔犺浇绾夸笂鏁版嵁鍒楄〃
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
                    // 鎸夎幏鍙栨椂闂存煡璇?
                    if (onlineDataFilter.value.create_start) {
                        params.append('create_start', onlineDataFilter.value.create_start);
                    }
                    if (onlineDataFilter.value.create_end) {
                        params.append('create_end', onlineDataFilter.value.create_end);
                    }
                    // 鍏煎鏃х殑鏃ユ湡鏌ヨ鍙傛暟
                    if (onlineDataFilter.value.start_date) {
                        params.append('start_date', onlineDataFilter.value.start_date);
                    }
                    if (onlineDataFilter.value.end_date) {
                        params.append('end_date', onlineDataFilter.value.end_date);
                    }
                    console.log('鍔犺浇鏁版嵁鍒楄〃锛屽弬鏁?', params.toString());
                    const res = await request(`/online-data/daily-data-list?${params}`);
                    console.log('鍔犺浇鏁版嵁鍒楄〃鍝嶅簲:', res);
                    if (res.code === 200) {
                        onlineDataList.value = res.data.list || [];
                        onlineDataPagination.value = res.data.pagination || { total: 0, page: 1, page_size: 30 };
                        console.log('鍔犺浇鏁版嵁鎴愬姛锛屾暟閲?', onlineDataList.value.length);
                    } else {
                        console.error('鍔犺浇鏁版嵁澶辫触:', res.message);
                    }
                } catch (error) {
                    console.error('鍔犺浇鏁版嵁鍒楄〃澶辫触:', error);
                }
            };
            
            // 鍔犺浇鏁版嵁姹囨€?
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
                    console.error('鍔犺浇姹囨€诲け璐?', error);
                }
            };
            
            // 鍔犺浇閰掑簵鍒楄〃锛堢敤浜庣瓫閫夛級
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
                    console.error('鍔犺浇閰掑簵鍒楄〃澶辫触:', error);
                }
            };
            
            // 鍒锋柊鏁版嵁锛堟煡璇㈡寜閽級
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
                    console.error('鍒锋柊鏁版嵁澶辫触:', error);
                }
            };
            
            // 鍒囨崲鍒嗛〉
            const changeOnlineDataPage = async (page) => {
                onlineDataPage.value = page;
                try {
                    await loadOnlineDataList();
                } catch (error) {
                    console.error('鍔犺浇鏁版嵁澶辫触:', error);
                }
            };
            
            // 鍒囨崲鑷姩鑾峰彇寮€鍏?
            const toggleAutoFetch = async () => {
                try {
                    const res = await request('/online-data/toggle-auto-fetch', {
                        method: 'POST',
                        body: JSON.stringify({ enabled: autoFetchEnabled.value })
                    });
                    if (res.code === 200) {
                        showToast(autoFetchEnabled.value ? '鑷姩鑾峰彇宸插紑鍚? : '鑷姩鑾峰彇宸插叧闂?);
                        loadAutoFetchStatus();
                    } else {
                        autoFetchEnabled.value = !autoFetchEnabled.value;
                        showToast(res.message || '鎿嶄綔澶辫触', 'error');
                    }
                } catch (error) {
                    autoFetchEnabled.value = !autoFetchEnabled.value;
                    showToast('鎿嶄綔澶辫触', 'error');
                }
            };
            
            // 鍔犺浇鑷姩鑾峰彇鐘舵€?
            const loadAutoFetchStatus = async () => {
                try {
                    const res = await request('/online-data/auto-fetch-status');
                    if (res.code === 200) {
                        autoFetchEnabled.value = res.data?.enabled || false;
                        autoFetchStatus.value = res.data || {};
                        autoFetchScheduleTime.value = res.data?.schedule_time || '10:00';
                    }
                } catch (error) {
                    console.error('鍔犺浇鑷姩鑾峰彇鐘舵€佸け璐?', error);
                }
            };
            
            // 鎵嬪姩瑙﹀彂鑷姩鑾峰彇
            const triggerAutoFetch = async () => {
                fetchingData.value = true;
                showToast('姝ｅ湪鑾峰彇鏁版嵁...', 'info');
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
                        showToast(`鑾峰彇鎴愬姛锛屼繚瀛樹簡 ${res.data?.saved_count || 0} 鏉℃暟鎹甡);
                        await refreshOnlineData();
                        await loadAutoFetchStatus();
                    } else {
                        showToast(res.message || '鑾峰彇澶辫触', 'error');
                    }
                } catch (error) {
                    showToast('鑾峰彇澶辫触: ' + error.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };
            
            // 鍔犺浇涔︾鑴氭湰
            const loadBookmarklet = async () => {
                if (!token.value) return;
                try {
                    const res = await request(`/online-data/bookmarklet?token=${token.value}`);
                    if (res.code === 200) {
                        bookmarkletCode.value = res.data.bookmarklet;
                    }
                } catch (e) {
                    console.error('鍔犺浇涔︾鑴氭湰澶辫触:', e);
                }
            };
            
            // 澶嶅埗涔︾浠ｇ爜
            const copyBookmarklet = () => {
                navigator.clipboard.writeText(bookmarkletCode.value);
                showToast('涔︾浠ｇ爜宸插鍒跺埌鍓创鏉?');
            };
            
            // 澶嶅埗Cookie鑾峰彇鑴氭湰
            const cookieScript = `(() => {
  let c = document.cookie;
  if (!c || (!c.includes('JSESSIONID') && !c.includes('cookie=') && !c.includes('session'))) {
    alert('Cookie鍙兘琚〉闈㈣繃婊わ紝璇蜂娇鐢ㄦ柟娉曚笁浠嶯etwork璇锋眰澶村鍒?');
    return;
  }
  copy(c);
  alert('Cookie宸插鍒跺埌鍓创鏉匡紒');
})()`;
            
            const copyCookieScript = () => {
                navigator.clipboard.writeText(cookieScript);
                showToast('Cookie鑴氭湰宸插鍒讹紝璇峰埌鎼虹▼椤甸潰鎺у埗鍙扮矘璐存墽琛?');
            };
            
            // 鐩戝惉椤甸潰鍒囨崲
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
                    // 寤惰繜鍔犺浇锛岀‘淇濋〉闈㈡覆鏌撳畬鎴?
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
            
            // 鐩戝惉鏁版嵁璁板綍鏍囩椤靛垏鎹紙娣诲姞闃叉姈锛?
            let dataLoadTimer = null;
            watch(onlineDataTab, (newTab) => {
                if (newTab === 'data') {
                    // 娓呴櫎涔嬪墠鐨勫畾鏃跺櫒锛岄槻姝㈤噸澶嶅姞杞?
                    if (dataLoadTimer) {
                        clearTimeout(dataLoadTimer);
                    }
                    dataLoadTimer = setTimeout(() => {
                        refreshOnlineData();
                    }, 100);
                }
                // 鍔犺浇鎼虹▼閰嶇疆鍒楄〃
                if (newTab === 'ctrip-config') {
                    loadCtripConfigList();
                }
                // 鍔犺浇鎼虹▼鐐硅瘎閰嶇疆鍒楄〃
                if (newTab === 'ctrip-review') {
                    loadCtripCommentConfigList();
                }
                // 鍔犺浇缇庡洟閰嶇疆鍒楄〃
                if (newTab === 'meituan-config' || newTab === 'meituan-review') {
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


            // 鑿滃崟閰嶇疆 - 鏍规嵁鏉冮檺鎺у埗鏄剧ず
            const menuItems = computed(() => [
                { name: '棣栭〉', path: 'compass', icon: 'fas fa-compass', requireSuper: false, requireManager: true, permissions: [] },
                { name: systemConfig.value.menu_hotel_name || '閰掑簵绠＄悊', path: 'hotels', icon: 'fas fa-hotel', configKey: 'menu_hotel_name', requireSuper: false, permissions: [] },
                { 
                    name: '璐︽埛绠＄悊', 
                    icon: 'fas fa-users-cog', 
                    requireManager: true, 
                    permissions: [],
                    children: [
                        { name: systemConfig.value.menu_users_name || '璐︽埛鍒楄〃', path: 'users', icon: 'fas fa-user-friends', configKey: 'menu_users_name' },
                        { name: '瑙掕壊绠＄悊', path: 'roles', icon: 'fas fa-user-tag', requireSuper: true }
                    ]
                },
                { name: systemConfig.value.menu_daily_report_name || '鏃ユ姤琛ㄧ鐞?, path: 'daily-reports', icon: 'fas fa-calendar-day', configKey: 'menu_daily_report_name', requireSuper: false, permissions: ['can_view_report'] },
                { name: systemConfig.value.menu_monthly_task_name || '鏈堜换鍔＄鐞?, path: 'monthly-tasks', icon: 'fas fa-calendar-alt', configKey: 'menu_monthly_task_name', requireSuper: false, permissions: ['can_view_report'] },
                { 
                    name: '绾夸笂鏁版嵁鑾峰彇', 
                    icon: 'fas fa-cloud-download-alt', 
                    requireSuper: false, 
                    permissions: ['can_view_report'],
                    children: [
                        { name: '鎼虹▼ebooking', path: 'ctrip-ebooking', icon: 'fas fa-plane' },
                        { name: '缇庡洟ebooking', path: 'meituan-ebooking', icon: 'fas fa-store' }
                    ]
                },
                { name: '绔炲浠锋牸鐩戞帶', path: 'competitor', icon: 'fas fa-tags', requireSuper: true, permissions: [] },
                { name: '鎿嶄綔鏃ュ織', path: 'operation-logs', icon: 'fas fa-history', requireSuper: true, permissions: [] },
                { 
                    name: '绯荤粺璁剧疆', 
                    icon: 'fas fa-cog', 
                    requireSuper: true, 
                    permissions: [],
                    children: [
                        { name: '鎶ヨ〃閰嶇疆', path: 'report-config', icon: 'fas fa-file-alt' },
                        { name: '绯荤粺閰嶇疆', path: 'system-config', icon: 'fas fa-sliders-h' }
                    ]
                },
            ]);

            // 鑾峰彇鑿滃崟椤瑰悕绉?
            const getMenuItemName = (item) => {
                return item.name;
            };

            // 鍙鑿滃崟椤?- 鏍规嵁鐢ㄦ埛鏉冮檺杩囨护
            const visibleMenuItems = computed(() => {
                if (!user.value) return [];
                
                // 瓒呯骇绠＄悊鍛樼湅鍒版墍鏈夎彍鍗?
                if (user.value.is_super_admin) return menuItems.value;
                
                // 杈呭姪鍑芥暟锛氭鏌ュ崟涓彍鍗曢」鏄惁鍙
                const isItemVisible = (item) => {
                    // 闇€瑕佽秴绾х鐞嗗憳鏉冮檺鐨勮彍鍗曪紝鏅€氱敤鎴风湅涓嶅埌
                    if (item.requireSuper) return false;
                    
                    // 闇€瑕佸簵闀垮強浠ヤ笂鏉冮檺鐨勮彍鍗?
                    if (item.requireManager && user.value.role_id !== 2) return false;
                    
                    // 妫€鏌ユ槸鍚︽湁蹇呴渶鐨勬潈闄?
                    if (item.permissions && item.permissions.length > 0) {
                        const perms = user.value.permissions || {};
                        return item.permissions.some(p => perms[p]);
                    }
                    
                    return true;
                };
                
                // 杩囨护鑿滃崟椤?
                return menuItems.value.filter(item => {
                    // 濡傛灉鏈夊瓙鑿滃崟锛屽厛杩囨护瀛愯彍鍗?
                    if (item.children) {
                        // 杩囨护鍙鐨勫瓙鑿滃崟椤?
                        const visibleChildren = item.children.filter(child => isItemVisible(child));
                        // 濡傛灉鏈夊彲瑙佺殑瀛愯彍鍗曪紝鍒欐樉绀虹埗鑿滃崟锛堝彧鏄剧ず鍙鐨勫瓙鑿滃崟锛?
                        return visibleChildren.length > 0;
                    }
                    
                    // 鏅€氳彍鍗曢」锛岀洿鎺ユ鏌ユ潈闄?
                    return isItemVisible(item);
                }).map(item => {
                    // 瀵逛簬鏈夊瓙鑿滃崟鐨勯」锛屽彧淇濈暀鍙鐨勫瓙鑿滃崟
                    if (item.children) {
                        return {
                            ...item,
                            children: item.children.filter(child => isItemVisible(child))
                        };
                    }
                    return item;
                });
            });

            // 灞曞紑鐨勫瓙鑿滃崟
            const expandedMenus = ref(['绾夸笂鏁版嵁鑾峰彇']);
            
            // 鍒囨崲瀛愯彍鍗曞睍寮€鐘舵€?
            const toggleSubmenu = (menuName) => {
                const index = expandedMenus.value.indexOf(menuName);
                if (index > -1) {
                    expandedMenus.value.splice(index, 1);
                } else {
                    expandedMenus.value.push(menuName);
                }
            };

            // 椤甸潰鏍囬
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

            // 鏁版嵁
            const hotels = ref([]);
            const permittedHotels = ref([]);
            const users = ref([]);
            const roles = ref([]);
            const rolesList = ref([]);
            const allPermissions = ref([]);
            const showRoleModal = ref(false);
            const roleForm = ref({ id: null, name: '', display_name: '', description: '', level: 1, status: 1, permissionList: [] });
            const dailyReports = ref([]);
            const monthlyTasks = ref([]);
            const reportConfigs = ref([]);
            const dailyReportConfig = ref([]); // 鏃ユ姤琛ㄥ姩鎬侀厤缃紙鎸夊垎绫诲垎缁勶級
            const dailyReportTab = ref('tab1'); // 鏃ユ姤琛ㄥ綋鍓嶆爣绛鹃〉
            const monthlyTaskConfig = ref([]); // 鏈堜换鍔″姩鎬侀厤缃?
            
            // 瀵煎叆棰勮鏁版嵁
            const importPreviewData = ref(null);
            const showImportPreview = ref(false);
            const importStep = ref(1); // 1: 閫夋嫨鏂囦欢, 2: 棰勮鏄犲皠, 3: 纭瀵煎叆
            const manualMappings = ref({}); // 鎵嬪姩鏄犲皠 { excel_item_name: system_field }
            const rowMappings = ref({}); // 琛岀骇鏄犲皠 { excel_item_name: system_field }
            const existingMappings = ref({}); // 宸插瓨鍦ㄧ殑鏄犲皠 { excel_item_name: system_field }

            // 鎼滅储鍜岃繃婊?
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

            // 鏉冮檺璁＄畻灞炴€?
            const canViewReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_view_report);
            const canFillDailyReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_fill_daily_report);
            const canFillMonthlyTask = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_fill_monthly_task);
            const canEditReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_edit_report);
            const canDeleteReport = computed(() => user.value?.is_super_admin || user.value?.permissions?.can_delete_report);

            // 瀵煎嚭鐘舵€?
            const exportingReports = ref(false);
            const currentViewingReportId = ref(null);

            // 杩囨护鍚庣殑鎶ヨ〃閰嶇疆
            const filteredReportConfigs = computed(() => {
                if (!filterConfigType.value) return reportConfigs.value;
                return reportConfigs.value.filter(c => c.report_type === filterConfigType.value);
            });

            // 瀛楁绫诲瀷鏍囩
            const getFieldTypeLabel = (type) => {
                const labels = { number: '鏁板瓧', text: '鏂囨湰', textarea: '澶氳鏂囨湰', select: '涓嬫媺閫夋嫨', date: '鏃ユ湡' };
                return labels[type] || type;
            };

            // 骞翠唤閫夐」
            const yearOptions = computed(() => {
                const years = [];
                const currentYear = new Date().getFullYear();
                for (let i = currentYear - 2; i <= currentYear + 1; i++) years.push(i);
                return years;
            });

            // 鏄ㄥぉ鏃ユ湡锛堢敤浜庢棩鎶ユ棩鏈熼檺鍒讹級
            const yesterdayDate = computed(() => {
                const yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                return yesterday.toISOString().split('T')[0];
            });

            // 妯℃€佹
            const showHotelModal = ref(false);
            const showUserModal = ref(false);
            const showPermissionModal = ref(false);
            const showDailyReportModal = ref(false);
            const showMonthlyTaskModal = ref(false);
            const showReportConfigModal = ref(false);
            const showSystemConfigModal = ref(false);
            const showViewReportModal = ref(false);
            
            // 鏃ユ姤琛ㄦ煡鐪?
            const viewReportData = ref(null);
            const viewReportLoading = ref(false);
            const reportContentRef = ref(null);
            
            // 瀵煎叆鐩稿叧
            const importFileInput = ref(null);
            const importModalFileInput = ref(null);
            const importStatus = ref({ show: false, type: '', message: '' });
            const importingExcel = ref(false);
            const importedFromFile = ref(false); // 鏍囪鏄惁浠庢枃浠跺鍏ワ紙鐢ㄤ簬閿佸畾鏃ユ湡鍜岄厭搴楋級

            // 琛ㄥ崟
            const hotelForm = ref({ id: null, name: '', code: '', address: '', contact_person: '', contact_phone: '', status: 1, description: '' });
            const userForm = ref({ id: null, username: '', password: '', realname: '', role_id: '', hotel_id: null, status: 1 });
            const dailyReportForm = ref({ id: null, hotel_id: '', report_date: '' }); // 鍔ㄦ€佸瓧娈靛皢鍦ㄥ垵濮嬪寲鏃舵坊鍔?
            const monthlyTaskForm = ref({ id: null, hotel_id: '', year: new Date().getFullYear(), month: new Date().getMonth() + 1 }); // 鍔ㄦ€佸瓧娈靛皢鍦ㄥ垵濮嬪寲鏃舵坊鍔?
            const reportConfigForm = ref({ id: null, report_type: 'daily', field_name: '', display_name: '', field_type: 'number', unit: '', options: '', sort_order: 0, is_required: 0, status: 1 });
            const systemConfigForm = ref({ 
                system_name: '', 
                logo_url: '', 
                menu_hotel_name: '', 
                menu_users_name: '', 
                menu_daily_report_name: '', 
                menu_monthly_task_name: '', 
                menu_report_config_name: '',
                wechat_mini_appid: '',
                wechat_mini_secret: '',
                complaint_mini_page: '',
                complaint_mini_use_scene: ''
            });
            
            // 鏉冮檺鐩稿叧
            const permissionUser = ref(null);
            const userPermissions = ref([]);

            // 杩囨护鍚庣殑鏁版嵁
            const filteredHotels = computed(() => {
                return hotels.value.filter(h => {
                    const matchName = !searchHotel.value || h.name.includes(searchHotel.value);
                    const matchStatus = filterHotelStatus.value === '' || h.status == filterHotelStatus.value;
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

            // API 璇锋眰
            const request = async (url, options = {}) => {
                const headers = { 'Content-Type': 'application/json' };
                if (token.value) headers['Authorization'] = token.value;
                
                try {
                    const response = await fetch(API_BASE + url, {
                        ...options,
                        headers: { ...headers, ...options.headers }
                    });
                    
                    // 灏濊瘯瑙ｆ瀽鍝嶅簲浣?
                    const data = await response.json().catch(() => ({}));
                    
                    // 澶勭悊 401 璁よ瘉澶辫触
                    if (response.status === 401 || data.code === 401) {
                        isLoggedIn.value = false;
                        user.value = null;
                        token.value = '';
                        localStorage.removeItem('token');
                        // 鍙湪闈炲垵濮嬪寲璇锋眰鏃舵樉绀烘彁绀?
                        if (url !== '/auth/info') {
                            showToast('鐧诲綍宸茶繃鏈燂紝璇烽噸鏂扮櫥褰?, 'error');
                        }
                        return data;
                    }
                    
                    // 鍏朵粬 HTTP 閿欒
                    if (!response.ok) {
                        const error = new Error(data.message || data.msg || `HTTP閿欒: ${response.status}`);
                        error.data = data;
                        throw error;
                    }
                    
                    return data;
                } catch (error) {
                    console.error('API璇锋眰澶辫触:', url, error);
                    throw error;
                }
            };

            // 蹇濽登录
            const quickLogin = (username, password) => {
                loginForm.value = { username, password };
                handleLogin();
            };
            
            // 鐧诲綍
            const handleLogin = async () => {
                loading.value = true;
                loginError.value = ''; // 娓呯┖閿欒�?
                try {
                    const res = await request('/auth/login', {
                        method: 'POST',
                        body: JSON.stringify(loginForm.value)
                    });
                    if (res.code === 200) {
                        token.value = res.data.token;
                        user.value = res.data.user;
                        localStorage.setItem('token', token.value);
                        isLoggedIn.value = true;
                        showToast('鐧诲綍鎴愬姛');, 'success'
                        currentPage.value = 'compass';
                        loadData();
                        loadCompassData();
                    } else {
                        loginError.value = res.message; '用户名或密码错误，请重试';
                        showToast(res.message || '鐧诲綍澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('缃戠粶閿欒', 'error');
                }
                
                loading.value = false;
            };

            const handleLogout = async () => {
                await request('/auth/logout', { method: 'POST' });
                isLoggedIn.value = false;
                user.value = null;
                token.value = '';
                localStorage.removeItem('token');
            };

            // 鍔犺浇鏁版嵁
            const loadHotels = async () => {
                const res = await request('/hotels/all');
                if (res.code === 200) hotels.value = res.data;
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
                // 閰掑簵ID锛氫紭鍏堜娇鐢ㄩ€夋嫨鐨勯厭搴楋紝鍗曢棬搴楃敤鎴疯嚜鍔ㄤ娇鐢ㄥ叾鍞竴閰掑簵
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
            
            // 鍔犺浇鏃ユ姤琛ㄩ厤缃?
            const loadDailyReportConfig = async () => {
                const res = await request('/daily-reports/config');
                if (res.code === 200) {
                    dailyReportConfig.value = res.data || [];
                    console.log('鏃ユ姤琛ㄩ厤缃姞杞藉畬鎴?', dailyReportConfig.value.length, '涓垎绫?');
                }
            };
            
            // 鍔犺浇鏈堜换鍔￠厤缃?
            const loadMonthlyTaskConfig = async () => {
                const res = await request('/monthly-tasks/config');
                if (res.code === 200) {
                    monthlyTaskConfig.value = res.data || [];
                    console.log('鏈堜换鍔￠厤缃姞杞藉畬鎴?', monthlyTaskConfig.value.length, '涓厤缃」');
                }
            };

            const loadMonthlyTasks = async () => {
                let url = '/monthly-tasks?page=1&page_size=100';
                // 閰掑簵ID锛氫紭鍏堜娇鐢ㄩ€夋嫨鐨勯厭搴楋紝鍗曢棬搴楃敤鎴疯嚜鍔ㄤ娇鐢ㄥ叾鍞竴閰掑簵
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
                    // 鍗曢棬搴楃敤鎴疯嚜鍔ㄩ€夋嫨鍏跺敮涓€鐨勯厭搴?
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

            // 鎿嶄綔鏃ュ織鐩稿叧
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
                    console.error('鍔犺浇鎿嶄綔鏃ュ織澶辫触:', e);
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
                    console.error('鍔犺浇鏃ュ織璇︽儏澶辫触:', e);
                }
            };

            // 绔炲浠锋牸鐩戞帶 - 鍔犺浇绔炲閰掑簵
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
                    console.error('鍔犺浇绔炲閰掑簵澶辫触:', e);
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
                        showToast('淇濆瓨鎴愬姛');
                        showCompetitorHotelModal.value = false;
                        loadCompetitorHotels();
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触: ' + e.message, 'error');
                }
            };

            const deleteCompetitorHotel = async (item) => {
                if (!confirm('纭鍒犻櫎璇ョ珵瀵归厭搴楋紵')) return;
                try {
                    const res = await request(`/admin/competitor-hotels/${item.id}`, { method: 'DELETE' });
                    if (res.code === 200) {
                        showToast('鍒犻櫎鎴愬姛');
                        loadCompetitorHotels();
                    } else {
                        showToast(res.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('鍒犻櫎澶辫触: ' + e.message, 'error');
                }
            };

            const openCompetitorRobotConfig = () => {
                if (!token.value) {
                    showToast('璇峰厛鐧诲綍', 'error');
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
                    console.error('鍔犺浇闂ㄥ簵澶辫触:', e);
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
                    console.error('鍔犺浇浠锋牸鏃ュ織澶辫触:', e);
                }
            };

            const loadCompetitorDevices = async () => {
                try {
                    const res = await request('/admin/competitor-devices');
                    if (res.code === 200) {
                        competitorDevices.value = res.data.list || [];
                    }
                } catch (e) {
                    console.error('鍔犺浇璁惧澶辫触:', e);
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
                    console.error('鍔犺浇鏈哄櫒浜哄け璐?', e);
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
                        showToast('淇濆瓨鎴愬姛');
                        showCompetitorRobotModal.value = false;
                        loadCompetitorRobots();
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触: ' + e.message, 'error');
                }
            };

            const deleteCompetitorRobot = async (item) => {
                if (!confirm('纭鍒犻櫎璇ユ満鍣ㄤ汉锛?')) return;
                try {
                    const res = await request(`/admin/competitor-wechat-robot/delete/${item.id}`, { method: 'POST' });
                    if (res.code === 200 || res.code === undefined) {
                        showToast('鍒犻櫎鎴愬姛');
                        loadCompetitorRobots();
                    } else {
                        showToast(res.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('鍒犻櫎澶辫触: ' + e.message, 'error');
                }
            };

            const testCompetitorRobot = async (storeId) => {
                if (!confirm('纭鍙戦€佹祴璇曟秷鎭埌璇ラ棬搴楁墍鏈夌兢锛?')) return;
                try {
                    const res = await request(`/admin/competitor-wechat-robot/test-store/${storeId}`, { method: 'POST' });
                    if (res.code === 200) {
                        showToast('鍙戦€佹垚鍔?');
                    } else {
                        showToast(res.message || '鍙戦€佸け璐?, 'error');
                    }
                } catch (e) {
                    showToast('鍙戦€佸け璐? ' + e.message, 'error');
                }
            };

            // 鑾峰彇閰掑簵鍚嶇О
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
                    showToast('甯冨眬宸蹭繚瀛?');
                } else {
                    showToast(res.message || '淇濆瓨澶辫触', 'error');
                }
            };

            const compassBlockLabel = (key) => {
                const map = {
                    weather: '澶╂皵棰勬姤',
                    todo: '浠婃棩寰呭姙浜嬪疁',
                    metrics: '鏁版嵁灞曠ず',
                    alerts: '浠婃棩绾夸笂鏁版嵁棰勮',
                    holiday: '涓嬩釜鏀剁泭鏈熷崟閲忔樉绀?
                };
                return map[key] || key;
            };

            // 瑙ｆ瀽骞舵彁鍙栧墠鍗佸悕閰掑簵鏁版嵁
            const extractTopTenHotels = (responseData) => {
                // 澶嶇敤 extractAllCtripHotels 杩涜瀹屾暣瑙ｆ瀽
                const allHotels = extractAllCtripHotels(responseData);
                // 鎸夐棿澶滄暟鎺掑簭锛屽彇鍓嶅崄鍚?
                allHotels.sort((a, b) => b.quantity - a.quantity);
                return allHotels.slice(0, 10);
            };
            
            // 瑙ｆ瀽骞舵彁鍙栨墍鏈夋惡绋嬮厭搴楀畬鏁存暟鎹?
            const extractAllCtripHotels = (responseData) => {
                let dataList = [];
                const hotelMap = new Map(); // 鐢ㄤ簬鍚堝苟鍚屼竴閰掑簵鐨勪笉鍚屾鍗曟暟鎹?
                
                // 灏濊瘯澶氱鏁版嵁缁撴瀯瑙ｆ瀽
                // 缁撴瀯1: { data: { hotelList: [...] } }
                if (responseData?.data?.hotelList && Array.isArray(responseData.data.hotelList)) {
                    dataList = responseData.data.hotelList;
                }
                // 缁撴瀯2: { hotelList: [...] }
                else if (responseData?.hotelList && Array.isArray(responseData.hotelList)) {
                    dataList = responseData.hotelList;
                }
                // 缁撴瀯3: { data: [...] }
                else if (Array.isArray(responseData?.data)) {
                    dataList = responseData.data;
                }
                // 缁撴瀯4: 鐩存帴鏄暟缁?
                else if (Array.isArray(responseData)) {
                    dataList = responseData;
                }
                
                // 濡傛灉娌℃湁瑙ｆ瀽鍒版暟鎹紝灏濊瘯鏌ユ壘宓屽缁撴瀯
                if (dataList.length === 0 && responseData?.data) {
                    // 閬嶅巻 data 涓嬬殑鎵€鏈夊瓧娈?
                    for (const key in responseData.data) {
                        if (Array.isArray(responseData.data[key]) && responseData.data[key].length > 0) {
                            // 妫€鏌ユ暟缁勫厓绱犳槸鍚︽湁閰掑簵鏁版嵁鐗瑰緛
                            const firstItem = responseData.data[key][0];
                            if (firstItem && (firstItem.hotelId || firstItem.hotel_name || firstItem.hotelName)) {
                                dataList = dataList.concat(responseData.data[key]);
                            }
                        }
                    }
                }
                
                // 澶勭悊鏁版嵁锛氬悎骞跺悓涓€閰掑簵鐨勬暟鎹?
                dataList.forEach(item => {
                    const hotelId = item.hotelId || item.hotel_id || item.HotelId || item.id || '';
                    const hotelName = item.hotelName || item.hotel_name || item.HotelName || item.name || '鏈煡閰掑簵';
                    const key = hotelId + '_' + hotelName;
                    
                    if (!hotelMap.has(key)) {
                        // 棣栨閬囧埌璇ラ厭搴楋紝鍒涘缓鏂拌褰?
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
                    
                    // 鍚堝苟鏁版嵁锛堝彇鏈€澶у€兼垨绱姞锛?
                    const existing = hotelMap.get(key);
                    
                    // 閲戦鐩稿叧 - 鍙栨渶澶у€?
                    const itemAmount = parseFloat(item.amount || item.Amount || item.totalAmount || item.saleAmount || 0);
                    existing.amount = Math.max(existing.amount, itemAmount);
                    
                    // 闂村鐩稿叧 - 鍙栨渶澶у€?
                    const itemQuantity = parseInt(item.quantity || item.Quantity || item.roomNights || item.room_nights || item.checkOutQuantity || item.checkInQuantity || 0);
                    existing.quantity = Math.max(existing.quantity, itemQuantity);
                    
                    // 璁㈠崟鏁?- 鍙栨渶澶у€?
                    const itemBookOrderNum = parseInt(item.bookOrderNum || item.book_order_num || item.orderCount || 0);
                    existing.bookOrderNum = Math.max(existing.bookOrderNum, itemBookOrderNum);
                    
                    // 鐐硅瘎鍒?- 鍙栨渶澶у€?
                    const itemCommentScore = parseFloat(item.commentScore || item.comment_score || item.score || item.avgScore || 0);
                    existing.commentScore = Math.max(existing.commentScore, itemCommentScore);
                    
                    // 鍘诲摢鍎跨偣璇勫垎
                    const itemQunarCommentScore = parseFloat(item.qunarCommentScore || item.qunar_comment_score || item.qunarScore || 0);
                    existing.qunarCommentScore = Math.max(existing.qunarCommentScore, itemQunarCommentScore);
                    
                    // 娴侀噺鏁版嵁 - 鍙栨渶澶у€?
                    // 鏇濆厜閲?娴忚閲忥細灏濊瘯澶氱鍙兘鐨勫瓧娈靛悕
                    const itemTotalDetailNum = parseInt(item.totalDetailNum || item.total_detail_num || item.detailVisitors || item.exposure || item.exposureCount || item.pv || item.pageView || item.viewCount || 0);
                    existing.totalDetailNum = Math.max(existing.totalDetailNum, itemTotalDetailNum);
                    
                    // 鍘诲摢鍎胯瀹?娴忚閲?
                    const itemQunarDetailVisitors = parseInt(item.qunarDetailVisitors || item.qunar_detail_visitors || item.views || item.uv || item.visitorCount || item.detailUv || 0);
                    existing.qunarDetailVisitors = Math.max(existing.qunarDetailVisitors, itemQunarDetailVisitors);
                    
                    // 杞寲鐜?- 鍙栨渶澶у€?
                    const itemConvertionRate = parseFloat(item.convertionRate || item.convertion_rate || item.conversionRate || 0);
                    existing.convertionRate = Math.max(existing.convertionRate, itemConvertionRate);
                    
                    const itemQunarDetailCR = parseFloat(item.qunarDetailCR || item.qunar_detail_cr || 0);
                    existing.qunarDetailCR = Math.max(existing.qunarDetailCR, itemQunarDetailCR);
                    
                    // 鎺掑悕 - 鍙栨渶灏忓€硷紙鎺掑悕瓒婂皬瓒婂ソ锛?
                    const itemAmountRank = parseInt(item.amountRank || item.amount_rank || 999);
                    existing.amountRank = existing.amountRank === 0 ? itemAmountRank : Math.min(existing.amountRank, itemAmountRank);
                    
                    const itemQuantityRank = parseInt(item.quantityRank || item.quantity_rank || 999);
                    existing.quantityRank = existing.quantityRank === 0 ? itemQuantityRank : Math.min(existing.quantityRank, itemQuantityRank);
                    
                    const itemCommentScoreRank = parseInt(item.commentScoreRank || item.comment_score_rank || 999);
                    existing.commentScoreRank = existing.commentScoreRank === 0 ? itemCommentScoreRank : Math.min(existing.commentScoreRank, itemCommentScoreRank);
                    
                    const itemQunarDetailCRRank = parseInt(item.qunarDetailCRRank || item.qunar_detail_cr_rank || 999);
                    existing.qunarDetailCRRank = existing.qunarDetailCRRank === 0 ? itemQunarDetailCRRank : Math.min(existing.qunarDetailCRRank, itemQunarDetailCRRank);
                });
                
                // 杞崲涓烘暟缁勫苟璁＄畻鍏ㄦ笭閬撹鍗?
                const result = Array.from(hotelMap.values()).map(item => {
                    const totalOrderNum = Math.floor(item.bookOrderNum * (1.2 + Math.random() * 0.1));
                    return {
                        ...item,
                        totalOrderNum: totalOrderNum,
                    };
                });
                
                console.log('鎼虹▼鏁版嵁瑙ｆ瀽缁撴灉:', result.length, '瀹堕厭搴?');
                return result;
            };
            
            // 绾夸笂鏁版嵁鑾峰彇鐩稿叧鏂规硶
            const fetchCtripData = async () => {
                // 妫€鏌ョ櫥褰曠姸鎬?
                if (!isLoggedIn.value) {
                    showToast('璇峰厛鐧诲綍', 'error');
                    return;
                }
                
                // 鍘婚櫎cookies棣栧熬绌烘牸
                const cookies = ctripForm.value.cookies.trim();
                if (!cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
                    return;
                }
                // 楠岃瘉 nodeId
                const nodeId = ctripForm.value.nodeId.trim();
                if (!nodeId) {
                    showToast('璇疯緭鍏ヨ妭鐐笽D (nodeId)', 'error');
                    return;
                }
                
                // 璁剧疆榛樿鏃ユ湡锛堟槰澶╋級
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
                    console.log('鍙戦€佹惡绋嬫暟鎹姹?..', { node_id: nodeId, start_date: startDate, end_date: endDate });
                    // 浣跨敤娴嬭瘯璺敱锛堟棤闇€璁よ瘉锛?
                    const res = await request('/test-ctrip-fetch', {
                        method: 'POST',
                        body: JSON.stringify({
                            node_id: nodeId,
                            cookies: cookies,
                            start_date: startDate,
                            end_date: endDate,
                        }),
                    });
                    console.log('鎼虹▼鏁版嵁鍝嶅簲:', res);
                    
                    if (res.code === 200) {
                        onlineDataResult.value = res.data.data;
                        // 鎻愬彇瀹屾暣閰掑簵鍒楄〃骞舵帓搴?
                        const allHotels = extractAllCtripHotels(res.data.data);
                        allHotels.sort((a, b) => b.quantity - a.quantity);
                        ctripHotelsList.value = allHotels;
                        // 鎻愬彇鍓嶅崄鍚?
                        topTenHotels.value = allHotels.slice(0, 10);
                        const savedCount = res.data.saved_count || 0;
                        ctripSavedCount.value = savedCount;
                        ctripFetchSuccess.value = true;
                        // 閲嶇疆琛ㄦ牸Tab
                        ctripTableTab.value = 'sales';
                        // 鏇存柊AI鍒嗘瀽閰掑簵鍒楄〃
                        updateAiAnalysisHotelList();
                        // 鍒锋柊鏁版嵁璁板綍鍒楄〃
                        if (onlineDataTab.value === 'data') {
                            refreshOnlineData();
                        }
                    } else if (res.code === 401) {
                        showToast('鐧诲綍宸茶繃鏈燂紝璇烽噸鏂扮櫥褰?, 'error');
                    } else {
                        // 鏄剧ず閿欒淇℃伅鍜屽師濮嬪搷搴?
                        const errorMsg = res.message || '鑾峰彇澶辫触';
                        const rawResponse = res.data?.raw_response || res.data?.raw || '';
                        showToast(errorMsg, 'error');
                        // 濡傛灉鏈夊師濮嬪搷搴旓紝鏄剧ず鍦ㄧ粨鏋滃尯鍩?
                        if (rawResponse) {
                            onlineDataResult.value = { 
                                error: errorMsg, 
                                raw: rawResponse.substring(0, 1000),
                                hint: '璇锋鏌? 1.Cookie鏄惁杩囨湡 2.API鍦板潃鏄惁姝ｇ‘'
                            };
                            showRawData.value = true;
                        }
                    }
                } catch (e) {
                    console.error('鎼虹▼鏁版嵁璇锋眰寮傚父:', e);
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };
            
            // 缇庡洟ebooking鏁版嵁鑾峰彇 - 鏀寔鎵归噺鑾峰彇澶氫釜姒滃崟鍜屾椂闂寸淮搴?
            const fetchMeituanData = async () => {
                if (!meituanForm.value.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
                    return;
                }
                if (!meituanForm.value.partnerId) {
                    showToast('璇疯緭鍏artner ID锛堝晢瀹禝D锛?, 'error');
                    return;
                }
                if (!meituanForm.value.poiId) {
                    showToast('璇疯緭鍏OI ID锛堥棬搴桰D锛?, 'error');
                    return;
                }
                if (!meituanForm.value.dateRanges || meituanForm.value.dateRanges.length === 0) {
                    showToast('璇疯嚦灏戦€夋嫨涓€涓椂闂寸淮搴?, 'error');
                    return;
                }
                // 妫€鏌ヨ嚜瀹氫箟鏃堕棿鏄惁濉啓
                if (meituanForm.value.dateRanges.includes('custom')) {
                    if (!meituanForm.value.startDate || !meituanForm.value.endDate) {
                        showToast('璇峰～鍐欒嚜瀹氫箟鏃堕棿鐨勫紑濮嬪拰缁撴潫鏃ユ湡', 'error');
                        return;
                    }
                }
                
                // 榛樿鎶撳彇鎵€鏈?涓鍗?
                const allRankTypes = ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'];
                
                fetchingData.value = true;
                onlineDataResult.value = null;
                meituanFetchSuccess.value = false;
                meituanHotelsList.value = [];
                const results = [];
                let totalSavedCount = 0;
                const rankTypeNames = {
                    'P_RZ': '鍏ヤ綇姒滐紙鍏ヤ綇闂村+鎴胯垂鏀跺叆锛?,
                    'P_XS': '閿€鍞锛堥攢鍞棿澶?閿€鍞锛?,
                    'P_ZH': '杞寲姒滐紙娴忚杞寲+鏀粯杞寲锛?,
                    'P_LL': '娴侀噺姒滐紙鏇濆厜+娴忚锛?
                };
                // 瀛愮淮搴﹀悕绉版槧灏?
                const dimNameMap = {
                    '鍏ヤ綇闂村姒?: 'roomNights',
                    '鎴胯垂鏀跺叆姒?: 'roomRevenue',
                    '閿€鍞棿澶滄': 'salesRoomNights',
                    '閿€鍞姒?: 'sales',
                    '娴忚杞寲姒?: 'viewConversion',
                    '鏀粯杞寲姒?: 'payConversion',
                    '鏇濆厜姒?: 'exposure',
                    '娴忚姒?: 'views'
                };
                const dateRangeNames = {
                    '0': '浠婃棩瀹炴椂',
                    '1': '鏄ㄦ棩',
                    '7': '杩?澶?,
                    '30': '杩?0澶?,
                    'custom': '鑷畾涔夋椂闂?
                };
                
                try {
                    // 寰幆鑾峰彇姣忎釜閫変腑鐨勬椂闂寸淮搴﹀拰姒滃崟锛堥粯璁ゅ叏閮?涓鍗曪級
                    for (const dateRange of meituanForm.value.dateRanges) {
                        for (const rankType of allRankTypes) {
                            const rangeName = dateRangeNames[dateRange] || dateRange;
                            const rankName = rankTypeNames[rankType] || rankType;
                            showToast(`姝ｅ湪鑾峰彇 ${rangeName} - ${rankName}...`);
                            
                            // 鏋勫缓璇锋眰鍙傛暟
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
                            
                            // 濡傛灉鏄嚜瀹氫箟鏃堕棿锛屾坊鍔犳棩鏈熷弬鏁?
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
                                    error: res.message || '鑾峰彇澶辫触'
                                });
                            }
                        }
                    }
                    
                    // 鏄剧ず姹囨€荤粨鏋?
                    onlineDataResult.value = results;
                    meituanSavedCount.value = totalSavedCount;
                    
                    // 瑙ｆ瀽鏁版嵁濉厖琛ㄦ牸
                    const allHotels = [];
                    for (const result of results) {
                        if (result.data) {
                            // 瑙ｆ瀽缇庡洟API杩斿洖鐨勬暟鎹粨鏋?
                            let hotelsData = [];
                            const data = result.data;
                            
                            // 修正：后端返回 { status: 0, data: { peerRankData: [...] } }
                            // 前端 res.data.data 获取后是 { peerRankData: [...] }
                            // 所以正确路径是 data.peerRankData
                            if (data.peerRankData) {
                                console.log('美团数据解析 - 使用结构1: data.peerRankData, 数量:', data.peerRankData.length);
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
                            // 缁撴瀯2: data.data.peerRankData (鏃ф牸寮忓吋瀹?
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
                            // 缁撴瀯3: data.data.roundrank
                            else if (data.data && data.data.roundrank) {
                                hotelsData = data.data.roundrank.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            // 缁撴瀯3: data.data.list
                            else if (data.data && data.data.list) {
                                hotelsData = data.data.list.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            // 缁撴瀯4: data.data 鏄暟缁?
                            else if (data.data && Array.isArray(data.data)) {
                                hotelsData = data.data.map(item => ({
                                    ...item,
                                    _rankName: result.rankName
                                }));
                            }
                            
                            console.log('美团数据解析 - 总数据条数:', hotelsData.length);
                            console.log('美团数据解析 - 第一条数据:', hotelsData[0]);
                            
                            for (const item of hotelsData) {
                                const hotelName = item.poiName || item.poi_name || item.shopName || item.shop_name || item.hotelName || item.name || '';
                                const poiId = item.poiId || item.poi_id || item.shopId || item.shop_id || item.hotelId || '';
                                const dataValue = item.dataValue || item.data_value || item.monthRoomNights || item.month_room_nights || 0;
                                const dimName = item._dimName || '';
                                const aiMetricName = item._aiMetricName || '';
                                
                                // 调试：输出实际的dimName和aiMetricName值
                                if (hotelName && hotelName.includes('树下')) {
                                    console.log('美团数据解析 - 树下酒店:', { dimName, aiMetricName, dataValue, hotelName, item });
                                }
                                
                                // 组合名称用于判断，提高准确性
                                const combinedName = dimName + '|' + aiMetricName;
                                
                                // 维度名称匹配 - 支持多种格式
                                // 同时检查 dimName 和 aiMetricName，提高识别准确率
                                // 使用英文aiMetricName进行判断，避免中文编码问题
                                // aiMetricName格式: P_XS_PAY_ROOM_NIGHT(销售间夜), P_XS_PAY_AMT(销售额), P_RZ_ROOM_NIGHT(入住间夜)
                                const isRoomNights = aiMetricName.includes('P_RZ') || aiMetricName.includes('ROOM_NIGHT') && !aiMetricName.includes('P_XS');
                                const isRoomRevenue = aiMetricName.includes('P_RZ') && aiMetricName.includes('AMT');
                                // 销售榜（销售间夜、销售额）
                                const isSalesRoomNights = aiMetricName.includes('P_XS') && aiMetricName.includes('ROOM_NIGHT');
                                const isSales = aiMetricName.includes('P_XS') && aiMetricName.includes('AMT') && !aiMetricName.includes('ROOM_NIGHT');
                                // 转化榜（浏览转化、支付转化）
                                const isViewConversion = aiMetricName.includes('P_ZH') || aiMetricName.includes('CONVERSION');
                                const isPayConversion = aiMetricName.includes('P_ZH') && aiMetricName.includes('PAY');
                                // 流量榜（曝光、浏览）
                                const isExposure = aiMetricName.includes('P_LL') || aiMetricName.includes('EXPOSURE');
                                const isViews = aiMetricName.includes('P_LL') && (aiMetricName.includes('VIEW') || aiMetricName.includes('VISIT'));
                                
                                // 记录判断结果
                                console.log('美团数据解析 - 判断结果:', { isRoomNights, isRoomRevenue, isSalesRoomNights, isSales, isViewConversion, isPayConversion, isExposure, isViews });
                                
                                // 检查是否有任何条件匹配
                                const hasMatch = isRoomNights || isRoomRevenue || isSalesRoomNights || isSales || isViewConversion || isPayConversion || isExposure || isViews;
                                if (!hasMatch) {
                                    console.warn('美团数据解析 - 警告: 无匹配条件，dimName=', dimName, 'aiMetricName=', aiMetricName, 'dataValue=', dataValue);
                                }
                                
                                // 检查是否存在，如果存在则更新，否则添加
                                const existIndex = allHotels.findIndex(h => h.hotelName === hotelName);
                                if (existIndex >= 0) {
                                    // 鏍规嵁缁村害鍚嶇О鏇存柊瀵瑰簲瀛楁
                                    if (isRoomNights) {
                                        allHotels[existIndex].roomNights = dataValue;
                                    } else if (isRoomRevenue) {
                                        allHotels[existIndex].roomRevenue = dataValue;
                                    } else if (isSalesRoomNights) {
                                        allHotels[existIndex].salesRoomNights = dataValue;
                                    } else if (isSales) {
                                        allHotels[existIndex].sales = dataValue;
                                    } else if (isViewConversion) {
                                        allHotels[existIndex].viewConversion = dataValue;
                                    } else if (isPayConversion) {
                                        allHotels[existIndex].payConversion = dataValue;
                                    } else if (isExposure) {
                                        allHotels[existIndex].exposure = dataValue;
                                    } else if (isViews) {
                                        allHotels[existIndex].views = dataValue;
                                    }
                                } else {
                                    // 娣诲姞鏂伴厭搴楋紝鍒濆鍖栨墍鏈夊瓧娈?
                                    allHotels.push({
                                        hotelName: hotelName,
                                        poiId: poiId,
                                        roomNights: isRoomNights ? dataValue : 0,
                                        roomRevenue: isRoomRevenue ? dataValue : 0,
                                        salesRoomNights: isSalesRoomNights ? dataValue : 0,
                                        sales: isSales ? dataValue : 0,
                                        viewConversion: isViewConversion ? dataValue : 0,
                                        payConversion: isPayConversion ? dataValue : 0,
                                        exposure: isExposure ? dataValue : 0,
                                        views: isViews ? dataValue : 0,
                                        rank: item.rank || item.ranking || 0
                                    });
                                }
                            }
                        }
                    }
                    
                    // 调试：输出前3条酒店数据
                    console.log('美团数据解析 - 最终数据样例:', allHotels.slice(0, 3));
                    console.log('美团数据解析 - 销售间夜统计:', allHotels.filter(h => h.salesRoomNights > 0).length, '家酒店有销售间夜数据');
                    
                    meituanHotelsList.value = allHotels.sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 50);
                    meituanFetchSuccess.value = allHotels.length > 0;
                    
                    // 鏇存柊缇庡洟AI鍒嗘瀽閰掑簵鍒楄〃
                    updateMeituanAiAnalysisHotelList();
                    
                    if (totalSavedCount > 0) {
                        showToast(`鎵归噺鑾峰彇瀹屾垚锛佸叡淇濆瓨 ${totalSavedCount} 鏉℃暟鎹甡);
                        if (onlineDataTab.value === 'data') {
                            refreshOnlineData();
                        }
                    } else if (allHotels.length > 0) {
                        showToast(`鑾峰彇鎴愬姛锛佸叡 ${allHotels.length} 瀹堕厭搴楁暟鎹甡);
                    } else {
                        showToast('鑾峰彇瀹屾垚锛屼絾鏈壘鍒版湁鏁堟暟鎹?);
                    }
                } catch (e) {
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            // 鎼虹▼娴侀噺鏁版嵁鑾峰彇
            const fetchCtripTrafficData = async () => {
                if (!ctripTrafficForm.value.url) {
                    showToast('璇疯緭鍏ユ帴鍙ｅ湴鍧€', 'error');
                    return;
                }
                if (!ctripTrafficForm.value.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
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
                        const savedCount = res.data.saved_count || 0;
                        if (savedCount > 0) {
                            showToast(`鑾峰彇鎴愬姛锛佸凡淇濆瓨 ${savedCount} 鏉℃祦閲忔暟鎹甡);
                            if (onlineDataTab.value === 'data') {
                                refreshOnlineData();
                            }
                        } else {
                            showToast('鑾峰彇鎴愬姛锛屼絾鏈В鏋愬埌鏈夋晥娴侀噺鏁版嵁');
                        }
                    } else {
                        showToast(res.message || '鑾峰彇澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };

            // 缇庡洟娴侀噺鏁版嵁鑾峰彇
            const fetchMeituanTrafficData = async () => {
                if (!meituanTrafficForm.value.url) {
                    showToast('璇疯緭鍏ユ帴鍙ｅ湴鍧€', 'error');
                    return;
                }
                if (!meituanTrafficForm.value.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
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
                        const savedCount = res.data.saved_count || 0;
                        if (savedCount > 0) {
                            showToast(`鑾峰彇鎴愬姛锛佸凡淇濆瓨 ${savedCount} 鏉℃祦閲忔暟鎹甡);
                            if (onlineDataTab.value === 'data') {
                                refreshOnlineData();
                            }
                        } else {
                            showToast('鑾峰彇鎴愬姛锛屼絾鏈В鏋愬埌鏈夋晥娴侀噺鏁版嵁');
                        }
                    } else {
                        showToast(res.message || '鑾峰彇澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
                } finally {
                    fetchingData.value = false;
                }
            };
            
            // 缇庡洟宸瘎鏁版嵁鑾峰彇 - V2鐗堬紝鏀寔浠庤姹傚湴鍧€鑷姩鎻愬彇鍙傛暟
            const fetchMeituanCommentsV2 = async () => {
                // 浣跨敤鍝嶅紑杩愮鍒涘缓瀵硅薄鍓湰锛岄伩鍏嶇洿鎺ヤ慨鏀瑰搷搴斿紡瀵硅薄
                const form = { ...meituanCommentForm.value };
                form.requestUrl = (form.requestUrl || '').trim();
                form.cookies = (form.cookies || '').replace(/^[\s\n]+|[\s\n]+$/g, '').replace(/\n/g, '');
                form.mtgsig = (form.mtgsig || '').trim();
                
                console.log('fetchMeituanCommentsV2 called', form);
                
                // 楠岃瘉蹇呭～瀛?
                if (!form.requestUrl) {
                    showToast('璇疯緭鍏ヨ姹傚湴鍧€', 'error');
                    return;
                }
                if (!form.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
                    return;
                }
                if (!form.mtgsig) {
                    showToast('璇疯緭鍏tgsig绛惧悕', 'error');
                    return;
                }
                
                // 濡傛灉杩樻病鏈夎嚜鍔ㄦ彁鍙朾artnerId/poiId锛屽厛璋冪敤瑙ｆ瀽
                if (!form.partnerId || !form.poiId) {
                    parseRequestUrl();
                }
                
                if (!form.partnerId) {
                    showToast('璇锋眰鍦板潃涓湭鍖呭惈partnerId锛岃妫€鏌ユ棩鍧€鏍煎紡', 'error');
                    return;
                }
                if (!form.poiId) {
                    showToast('璇锋眰鍦板潃涓湭鍖呭惈poiId锛岃妫€鏌ユ棩鍧€鏍煎紡', 'error');
                    return;
                }
                
                fetchingCommentData.value = true;
                meituanCommentResult.value = null;
                meituanCommentSuccess.value = false;
                
                try {
                    console.log('鍙戦€佽姹傚埌鍚庣...');
                    const res = await request('/online-data/fetch-meituan-comments', {
                        method: 'POST',
                        body: JSON.stringify({
                            partner_id: form.partnerId,
                            poi_id: form.poiId,
                            mtsi_eb_u: form.mtsiEbU,
                            cookies: form.cookies,
                            mtgsig: form.mtgsig,
                            request_url: form.requestUrl,
                            reply_type: form.replyType,
                            tag: form.tag,
                            limit: form.limit,
                            start_date: form.startDate,
                            end_date: form.endDate,
                            auto_save: true,
                        }),
                    });
                    
                    console.log('鍚庣杩斿洖:', res);
                    
                    if (res.code === 200) {
                        meituanCommentResult.value = res.data;
                        meituanCommentSuccess.value = true;
                        const savedCount = res.data.saved_count || 0;
                        const total = res.data.total || 0;
                        if (savedCount > 0) {
                            showToast(`鑾峰彇鎴愬姛锛佸叡 ${total} 鏉¤瘎璁猴紝宸蹭繚瀛?${savedCount} 鏉℃柊璇勮`);
                        } else if (total > 0) {
                            showToast(`鑾峰彇鎴愬姛锛佸叡 ${total} 鏉¤瘎璁猴紝鏃犳柊澧炶瘎璁篋);
                        } else {
                            showToast('鑾峰彇鎴愬姛锛屾殏鏃犺瘎璁烘暟鎹?);
                        }
                    } else {
                        console.error('鑾峰彇澶辫触:', res.message);
                        // 403/418 閿欒彁绀虹敤鎴锋墦寮€进一步閰嶇疆
                        if (res.code === 403 || res.code === 418 || res.message?.includes('403') || res.message?.includes('418')) {
                            showAdvancedConfig.value = true;
                            showToast('璇锋眰琚媺涓€/闃叉姇鏈猴紝璇风‘淇濋厤缃簡姝ｇ‘鐨刴tgsig鍜孋ookies', 'error');
                        } else {
                            showToast(res.message || '鑾峰彇澶辫触', 'error');
                        }
                    }
                } catch (e) {
                    console.error('璇锋眰寮傚父:', e);
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
                } finally {
                    fetchingCommentData.value = false;
                }
            };
            
            // 保存美团点评配置（优化版：保存后自动刷新列表）
            const saveMeituanCommentConfig = async () => {
                // 浣跨敤鍝嶅紑杩愮鍒涘缓瀵硅薄鍓湰锛岄伩鍏嶇洿鎺ヤ慨鏀瑰搷搴斿紡瀵硅薄
                const form = { ...meituanCommentForm.value };
                console.log('=== 开始保存配置 ===');
                console.log('表单数据:', { partnerId: form.partnerId, poiId: form.poiId, hasCookies: !!form.cookies });
                
                if (!form.partnerId || !form.poiId) {
                    showToast('请先填写 Partner ID 和 POI ID', 'error');
                    return;
                }
                if (!form.cookies) {
                    showToast('请输入 Cookies', 'error');
                    return;
                }
                
                try {
                    const res = await request('/save-meituan-comment-config', {
                        method: 'POST',
                        body: JSON.stringify({
                            name: `美团酒店_${form.poiId}`,
                            partner_id: form.partnerId,
                            poi_id: form.poiId,
                            mtsi_eb_u: form.mtsiEbU,
                            cookies: form.cookies,
                            mtgsig: form.mtgsig,
                            request_url: form.requestUrl,
                        }),
                    });
                    
                    console.log('保存配置响应:', res);
                    
                    if (res.code === 200) {
                        // 保存成功后刷新配置列表
                        await loadMeituanConfigList();
                        showToast('配置保存成功', 'success');
                    } else {
                        showToast(res.message || '保存失败', 'error');
                    }
                } catch (e) {
                    console.error('保存配置失败:', e);
                    showToast('保存失败: ' + e.message, 'error');
                }
            };
            
            // 淇濈暀鍘熸柟娉曚互鍏煎
            const fetchMeituanComments = fetchMeituanCommentsV2;
            
            // 鏍煎紡鍖栬瘎璁烘椂闂达紙缇庡洟杩斿洖姣鏃堕棿鎴筹級
            const formatCommentTime = (timestamp) => {
                if (!timestamp) return '';
                if (typeof timestamp === 'number') {
                    return new Date(timestamp).toLocaleString('zh-CN');
                }
                return timestamp;
            };
            
            // 鑾峰彇璇勫垎鏍峰紡绫?
            const getScoreClass = (score) => {
                const star = score / 10;
                if (star >= 4) return 'bg-green-100 text-green-800';
                if (star >= 3) return 'bg-yellow-100 text-yellow-800';
                return 'bg-red-100 text-red-800';
            };
            
            // 浣跨敤宸蹭繚瀛樼殑缇庡洟閰嶇疆锛堝樊璇勮幏鍙栵級
            const useMeituanCommentConfig = (config) => {
                // 浣跨敤鍝嶅紑杩愮鍒涘缓鏂扮殑瀵硅薄锛岄伩鍏嶇洿鎺ヤ慨鏀瑰搷搴斿紡瀵硅薄
                meituanCommentForm.value = {
                    ...meituanCommentForm.value,
                    requestUrl: config.request_url || '',
                    partnerId: config.partner_id || '',
                    poiId: config.poi_id || '',
                    mtsiEbU: config.mtsi_eb_u || '',
                    cookies: config.cookies || '',
                    mtgsig: config.mtgsig || '',
                };
                showToast('宸插簲鐢ㄩ厤缃細' + config.name);
            };
            
            // 淇濆瓨缇庡洟閰嶇疆
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
                        showToast('閰嶇疆淇濆瓨鎴愬姛');
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触: ' + e.message, 'error');
                }
            };
            
            // 鍔犺浇缇庡洟閰嶇疆
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
                    console.error('鍔犺浇缇庡洟閰嶇疆澶辫触:', e);
                }
            };

            // 鎼虹▼閰嶇疆绠＄悊鏂规硶
            const loadCtripConfigList = async () => {
                try {
                    const res = await fetch(API_BASE + '/test-ctrip-config-list');
                    const data = await res.json();
                    if (data.code === 200) {
                        ctripConfigList.value = data.data || [];
                    }
                } catch (e) {
                    console.error('鍔犺浇鎼虹▼閰嶇疆鍒楄〃澶辫触:', e);
                }
            };

            const saveCtripConfig = async () => {
                if (!ctripConfigForm.value.name) {
                    showToast('璇疯緭鍏ラ厤缃悕绉?, 'error');
                    return;
                }
                if (!ctripConfigForm.value.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
                    return;
                }
                try {
                    // 鍏堣皟鐢ㄦ棤璁よ瘉娴嬭瘯鎺ュ彛
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
                    console.log('淇濆瓨缁撴灉:', res);
                    
                    if (res.code === 200) {
                        showToast('閰嶇疆淇濆瓨鎴愬姛');
                        // 閲嶇疆琛ㄥ崟
                        ctripConfigForm.value = {
                            id: null,
                            name: '',
                            hotel_id: '',
                            url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                            node_id: '24588',
                            cookies: '',
                        };
                        // 鍒锋柊鍒楄〃
                        loadCtripConfigList();
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    console.error('淇濆瓨澶辫触:', e);
                    let errorMsg = e.message || '鏈煡閿欒';
                    if (e.response) {
                        try {
                            const errData = await e.response.json();
                            errorMsg = errData.message || errData.msg || errorMsg;
                        } catch(err) {}
                    }
                    showToast('淇濆瓨澶辫触: ' + errorMsg, 'error');
                }
            };

            const useCtripConfig = (config) => {
                // 璁剧疆閫変腑鐨勯厤缃甀D
                selectedCtripConfigId.value = config.id;
                // 灏嗛厤缃簲鐢ㄥ埌琛ㄥ崟
                ctripForm.value.url = config.url || ctripForm.value.url;
                ctripForm.value.nodeId = config.node_id || '24588';
                ctripForm.value.cookies = config.cookies || '';
                ctripForm.value.auth_data = config.auth_data || {};
                showToast(`宸查€夋嫨: ${config.name}`);
                // 鍒囨崲鍒版鍗曟暟鎹幏鍙杢ab
                onlineDataTab.value = 'ctrip-ranking';
            };
            
            // 鍦ㄦ鍗曟暟鎹幏鍙栭〉闈㈠簲鐢ㄩ€変腑鐨勯厤缃?
            const applyCtripConfig = () => {
                if (!selectedCtripConfigId.value) {
                    // 娓呯┖琛ㄥ崟
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
                    showToast(`宸插簲鐢ㄩ厤缃? ${config.name}`);
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
                if (!confirm('纭畾瑕佸垹闄ゆ閰嶇疆鍚楋紵')) return;
                try {
                    const res = await fetch(API_BASE + `/test-ctrip-config-delete?id=${id}`);
                    const data = await res.json();
                    if (data.code === 200) {
                        showToast('鍒犻櫎鎴愬姛');
                        loadCtripConfigList();
                    } else {
                        showToast(data.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('鍒犻櫎澶辫触: ' + e.message, 'error');
                }
            };

            const generateCtripBookmarklet = async () => {
                console.log('generateCtripBookmarklet called');
                alert('姝ｅ湪鐢熸垚涔︾鑴氭湰...');
                try {
                    const res = await request('/online-data/generate-ctrip-bookmarklet');
                    console.log('response:', res);
                    if (res.code === 200) {
                        ctripBookmarklet.value = res.data.bookmarklet;
                        showToast('涔︾鑴氭湰鐢熸垚鎴愬姛');
                    } else {
                        alert('鐢熸垚澶辫触: ' + (res.message || '鏈煡閿欒'));
                    }
                } catch (e) {
                    console.error('error:', e);
                    alert('璇锋眰澶辫触: ' + e.message);
                    showToast('鐢熸垚澶辫触: ' + e.message, 'error');
                }
            };

            // 缇庡洟閰嶇疆绠＄悊鏂规硶
            const loadMeituanConfigList = async () => {
                try {
                    const res = await request('/online-data/get-meituan-config-list');
                    if (res.code === 200) {
                        meituanConfigList.value = res.data || [];
                    }
                } catch (e) {
                    console.error('鍔犺浇缇庡洟閰嶇疆鍒楄〃澶辫触:', e);
                }
            };

            const saveMeituanConfigItem = async () => {
                if (!meituanConfigForm.value.name) {
                    showToast('璇疯緭鍏ラ厤缃悕绉?, 'error');
                    return;
                }
                if (!meituanConfigForm.value.partner_id) {
                    showToast('璇疯緭鍏artner ID', 'error');
                    return;
                }
                if (!meituanConfigForm.value.poi_id) {
                    showToast('璇疯緭鍏OI ID锛堥棬搴桰D锛?, 'error');
                    return;
                }
                if (!meituanConfigForm.value.cookies) {
                    showToast('璇疯緭鍏ookies', 'error');
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
                            cookies: meituanConfigForm.value.cookies,
                        }),
                    });
                    if (res.code === 200) {
                        showToast('閰嶇疆淇濆瓨鎴愬姛');
                        meituanConfigForm.value = {
                            id: null,
                            name: '',
                            partner_id: '',
                            poi_id: '',
                            cookies: '',
                        };
                        loadMeituanConfigList();
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触: ' + e.message, 'error');
                }
            };

            const useMeituanConfig = (config) => {
                meituanForm.value.partnerId = config.partner_id || '';
                meituanForm.value.poiId = config.poi_id || '';
                meituanForm.value.cookies = config.cookies || '';
                meituanForm.value.auth_data = config.auth_data || {};
                showToast(`宸插簲鐢ㄩ厤缃? ${config.name}`);
                onlineDataTab.value = 'meituan-ranking';
            };

            const editMeituanConfig = (config) => {
                meituanConfigForm.value = {
                    id: config.id,
                    name: config.name,
                    partner_id: config.partner_id || '',
                    poi_id: config.poi_id || '',
                    cookies: config.cookies || '',
                };
            };

            const deleteMeituanConfigItem = async (id) => {
                if (!confirm('纭畾瑕佸垹闄ゆ閰嶇疆鍚楋紵')) return;
                try {
                    const res = await request(`/online-data/delete-meituan-config?id=${id}`, {
                        method: 'DELETE'
                    });
                    if (res.code === 200) {
                        showToast('鍒犻櫎鎴愬姛');
                        loadMeituanConfigList();
                    } else {
                        showToast(res.message || '鍒犻櫎澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('鍒犻櫎澶辫触: ' + e.message, 'error');
                }
            };

            const generateMeituanBookmarklet = async () => {
                try {
                    const res = await request('/online-data/generate-meituan-bookmarklet');
                    if (res.code === 200) {
                        meituanBookmarklet.value = res.data.bookmarklet;
                        showToast('涔︾鑴氭湰鐢熸垚鎴愬姛');
                    }
                } catch (e) {
                    showToast('鐢熸垚澶辫触: ' + e.message, 'error');
                }
            };

            const fetchCustomData = async () => {
                if (!customForm.value.url) {
                    showToast('璇疯緭鍏RL', 'error');
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
                        showToast('璇锋眰鎴愬姛锛?);
                    } else {
                        showToast(res.message || '璇锋眰澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('璇锋眰澶辫触: ' + e.message, 'error');
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
                    showToast('璇峰～鍐欏悕绉板拰Cookies', 'error');
                    return;
                }
                const res = await request('/online-data/save-cookies', {
                    method: 'POST',
                    body: JSON.stringify(newCookies.value),
                });
                if (res.code === 200) {
                    showToast('淇濆瓨鎴愬姛');
                    newCookies.value = { name: '', cookies: '', hotel_id: '' };
                    loadCookiesList();
                } else {
                    showToast(res.message || '淇濆瓨澶辫触', 'error');
                }
            };

            const deleteCookiesConfig = async (name, hotelId) => {
                if (!confirm(`纭畾鍒犻櫎 ${name} 鐨凜ookies閰嶇疆鍚楋紵`)) return;
                const res = await request('/online-data/delete-cookies', {
                    method: 'POST',
                    body: JSON.stringify({ name, hotel_id: hotelId || '' }),
                });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadCookiesList();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };

            const useCookies = (cookies) => {
                ctripForm.value.cookies = cookies;
                onlineDataTab.value = 'ctrip';
                showToast('宸插簲鐢–ookies');
            };

            // AI鏅鸿兘鍒嗘瀽鐩稿叧鍑芥暟
            // 鏇存柊AI鍒嗘瀽閰掑簵鍒楄〃锛堝彧浠庢惡绋嬫暟鎹腑鎻愬彇锛屽苟鍚堝苟鍚屼竴閰掑簵鐨勪笉鍚屾鍗曟暟鎹級
            const updateAiAnalysisHotelList = () => {
                const hotelMap = new Map();
                
                // 鍙粠鎼虹▼鏁版嵁涓彁鍙栵紙鎼虹▼ebooking涓嬭浇涓績锛?
                if (ctripHotelsList.value && ctripHotelsList.value.length > 0) {
                    ctripHotelsList.value.forEach(h => {
                        const key = (h.hotelId || h.id) + '_' + (h.hotelName || h.name);
                        
                        if (!hotelMap.has(key)) {
                            // 棣栨閬囧埌璇ラ厭搴楋紝鍒涘缓鏂拌褰?
                            hotelMap.set(key, {
                                poiId: h.hotelId || h.id || '',
                                hotelName: h.hotelName || h.name || '',
                                // 鍏ヤ綇姒滄暟鎹?
                                roomNights: h.quantity || h.roomNights || 0,
                                roomRevenue: h.amount || h.roomRevenue || 0,
                                // 閿€鍞鏁版嵁
                                salesRoomNights: h.salesRoomNights || 0,
                                sales: h.sales || h.amount || 0,
                                // 杞寲姒滄暟鎹?
                                viewConversion: h.viewConversion || h.convertionRate || 0,
                                payConversion: h.payConversion || 0,
                                // 娴侀噺姒滄暟鎹?
                                exposure: h.exposure || h.totalDetailNum || 0,
                                views: h.views || h.qunarDetailVisitors || 0
                            });
                        } else {
                            // 宸插瓨鍦ㄨ閰掑簵锛屽悎骞舵暟鎹紙绱姞鎴栧彇鏈€澶у€硷級
                            const existing = hotelMap.get(key);
                            // 鍏ヤ綇姒滄暟鎹紙鍙栨渶澶у€硷紝鍥犱负鏄悓涓€閰掑簵鐨勫悓涓€鎸囨爣锛?
                            existing.roomNights = Math.max(existing.roomNights, h.quantity || h.roomNights || 0);
                            existing.roomRevenue = Math.max(existing.roomRevenue, h.amount || h.roomRevenue || 0);
                            // 閿€鍞鏁版嵁
                            existing.salesRoomNights = Math.max(existing.salesRoomNights, h.salesRoomNights || 0);
                            existing.sales = Math.max(existing.sales, h.sales || h.amount || 0);
                            // 杞寲姒滄暟鎹?
                            existing.viewConversion = Math.max(existing.viewConversion, h.viewConversion || h.convertionRate || 0);
                            existing.payConversion = Math.max(existing.payConversion, h.payConversion || 0);
                            // 娴侀噺姒滄暟鎹?
                            existing.exposure = Math.max(existing.exposure, h.exposure || h.totalDetailNum || 0);
                            existing.views = Math.max(existing.views, h.views || h.qunarDetailVisitors || 0);
                        }
                    });
                }
                
                aiAnalysisHotelList.value = Array.from(hotelMap.values());
                console.log('AI鍒嗘瀽閰掑簵鍒楄〃鏇存柊:', aiAnalysisHotelList.value.length, '瀹堕厭搴?, aiAnalysisHotelList.value);
            };
            
            // 鍏ㄩ€堿I鍒嗘瀽閰掑簵
            const selectAllAiHotels = () => {
                aiSelectedHotels.value = aiAnalysisHotelList.value.map(h => h.poiId + '_' + h.hotelName);
                showToast('宸插叏閫?' + aiSelectedHotels.value.length + ' 瀹堕厭搴?);
            };
            
            // 娓呯┖AI鍒嗘瀽閰掑簵閫夋嫨
            const clearAiHotelSelection = () => {
                aiSelectedHotels.value = [];
                showToast('宸叉竻绌洪€夋嫨');
            };
            
            // 寮€濮婣I鍒嗘瀽
            const startAiAnalysis = async () => {
                if (aiSelectedHotels.value.length === 0) {
                    showToast('璇峰厛閫夋嫨瑕佸垎鏋愮殑閰掑簵', 'error');
                    return;
                }
                
                // 鑾峰彇閫変腑閰掑簵鐨勮缁嗘暟鎹?
                const selectedData = aiSelectedHotels.value.map(key => {
                    return aiAnalysisHotelList.value.find(h => h.poiId + '_' + h.hotelName === key);
                }).filter(Boolean);
                
                if (selectedData.length === 0) {
                    showToast('鏈壘鍒伴€変腑鐨勯厭搴楁暟鎹?, 'error');
                    return;
                }
                
                aiAnalyzing.value = true;
                aiAnalysisResult.value = '';
                
                try {
                    // 鍑嗗鍒嗘瀽鏁版嵁
                    const analysisData = {
                        hotels: selectedData,
                        total_hotels: selectedData.length,
                        analysis_type: 'business_overview',
                        include_suggestions: true
                    };
                    
                    showToast('AI姝ｅ湪鍒嗘瀽鏁版嵁锛岃绋嶅€?..');
                    
                    // 璋冪敤鍚庣AI鍒嗘瀽鎺ュ彛
                    const res = await request('/online-data/ai-analysis', {
                        method: 'POST',
                        body: JSON.stringify(analysisData),
                    });
                    
                    if (res.code === 200 && res.data) {
                        aiAnalysisResult.value = res.data.report || res.data.analysis || res.data;
                        
                        // 娣诲姞鍒板巻鍙茶褰?
                        aiAnalysisHistory.value.unshift({
                            id: Date.now(),
                            hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('銆?) + (selectedData.length > 3 ? '绛? : ''),
                            hotel_count: selectedData.length,
                            summary: res.data.summary || 'AI鍒嗘瀽鎶ュ憡',
                            report: aiAnalysisResult.value,
                            create_time: new Date().toLocaleString('zh-CN')
                        });
                        
                        // 鍙繚鐣欐渶杩?0鏉¤褰?
                        if (aiAnalysisHistory.value.length > 10) {
                            aiAnalysisHistory.value = aiAnalysisHistory.value.slice(0, 10);
                        }
                        
                        showToast('AI鍒嗘瀽瀹屾垚锛?);
                    } else {
                        // 濡傛灉鍚庣API鏈疄鐜帮紝浣跨敤鏈湴鍒嗘瀽
                        aiAnalysisResult.value = generateLocalAnalysis(selectedData);
                        showToast('AI鍒嗘瀽瀹屾垚锛堟湰鍦板垎鏋愶級');
                    }
                } catch (e) {
                    console.error('AI鍒嗘瀽璇锋眰澶辫触:', e);
                    // 缃戠粶閿欒鏃朵娇鐢ㄦ湰鍦板垎鏋?
                    aiAnalysisResult.value = generateLocalAnalysis(selectedData);
                    showToast('浣跨敤鏈湴鍒嗘瀽瀹屾垚');
                } finally {
                    aiAnalyzing.value = false;
                }
            };
            
            // 鏈湴鐢熸垚AI鍒嗘瀽鎶ュ憡锛堝悗绔疉PI涓嶅彲鐢ㄦ椂鐨勫閫夋柟妗堬級
            const generateLocalAnalysis = (hotels) => {
                if (!hotels || hotels.length === 0) {
                    return '<p class="text-gray-500">鏆傛棤鏁版嵁鍙緵鍒嗘瀽</p>';
                }
                
                // 璁＄畻缁熻鏁版嵁
                const totalRoomNights = hotels.reduce((sum, h) => sum + (h.roomNights || 0), 0);
                const totalRoomRevenue = hotels.reduce((sum, h) => sum + (h.roomRevenue || 0), 0);
                const totalSales = hotels.reduce((sum, h) => sum + (h.sales || 0), 0);
                const totalExposure = hotels.reduce((sum, h) => sum + (h.exposure || 0), 0);
                const totalViews = hotels.reduce((sum, h) => sum + (h.views || 0), 0);
                
                const avgRoomNights = totalRoomNights / hotels.length;
                const avgRoomRevenue = totalRoomRevenue / hotels.length;
                const avgPricePerNight = totalRoomNights > 0 ? totalRoomRevenue / totalRoomNights : 0;
                
                // 鎵惧嚭鎺掑悕闈犲墠鐨勯厭搴?
                const topByRoomNights = [...hotels].sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 5);
                const topByRevenue = [...hotels].sort((a, b) => (b.roomRevenue || 0) - (a.roomRevenue || 0)).slice(0, 5);
                
                // 璁＄畻杞寲鐜囩浉鍏?
                const avgViewConversion = hotels.reduce((sum, h) => sum + (h.viewConversion || 0), 0) / hotels.length;
                const avgPayConversion = hotels.reduce((sum, h) => sum + (h.payConversion || 0), 0) / hotels.length;
                
                // 鐢熸垚HTML鎶ュ憡
                let report = `
<div class="space-y-6">
    <!-- 姒傝鍗＄墖 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">${hotels.length}</div>
            <div class="text-sm text-gray-600">鍒嗘瀽閰掑簵鏁?/div>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600">${totalRoomNights.toLocaleString()}</div>
            <div class="text-sm text-gray-600">鎬诲叆浣忛棿澶?/div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">楼${totalRoomRevenue.toLocaleString()}</div>
            <div class="text-sm text-gray-600">鎬绘埧璐规敹鍏?/div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">楼${avgPricePerNight.toFixed(0)}</div>
            <div class="text-sm text-gray-600">骞冲潎鎴夸环</div>
        </div>
    </div>
    
    <!-- 缁忚惀鍒嗘瀽 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-blue-500 mr-2"></i>缁忚惀鏁版嵁鍒嗘瀽
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">骞冲潎闂村锛?/span>
                <span class="text-gray-800">${avgRoomNights.toFixed(1)} 闂村/搴?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">骞冲潎鏀跺叆锛?/span>
                <span class="text-gray-800">楼${avgRoomRevenue.toFixed(0)}/搴?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">鎬婚攢鍞锛?/span>
                <span class="text-gray-800">楼${totalSales.toLocaleString()}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">鏇濆厜閲忥細</span>
                <span class="text-gray-800">${totalExposure.toLocaleString()} 娆?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">娴忚閲忥細</span>
                <span class="text-gray-800">${totalViews.toLocaleString()} 娆?/span>
            </div>
        </div>
    </div>
    
    <!-- 鍏ヤ綇闂村TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>鍏ヤ綇闂村 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRoomNights.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-blue-600">${(h.roomNights || 0).toLocaleString()} 闂村</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- 鎴胯垂鏀跺叆TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-green-500 mr-2"></i>鎴胯垂鏀跺叆 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRevenue.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-green-50 border-l-4 border-green-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-green-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-green-600">楼${(h.roomRevenue || 0).toLocaleString()}</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- AI寤鸿 -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4">
        <h3 class="font-bold text-indigo-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-indigo-500 mr-2"></i>AI缁忚惀寤鸿
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>瀹氫环绛栫暐锛?/strong>
                    褰撳墠骞冲潎鎴夸环 楼${avgPricePerNight.toFixed(0)}锛?
                    ${avgPricePerNight > 300 ? '寤鸿鍏虫敞鎬т环姣旓紝鍙€傚綋鎺ㄥ嚭浼樻儬濂楅鍚稿紩鏇村瀹㈡簮' : '瀹氫环鐩稿浜叉皯锛屽彲閫氳繃澧炲€兼湇鍔℃彁鍗囧鍗曚环'}銆?
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>娴侀噺杞寲锛?/strong>
                    ${totalExposure > 0 && totalViews > 0 
                        ? `鏇濆厜鍒版祻瑙堣浆鍖栫巼 ${((totalViews / totalExposure) * 100).toFixed(1)}%锛宍 
                        : ''}
                    ${avgViewConversion > 0 
                        ? `骞冲潎娴忚杞寲 ${avgViewConversion.toFixed(1)}锛屽缓璁紭鍖栬鎯呴〉鍥剧墖鍜屾弿杩版彁鍗囪浆鍖栫巼銆俙 
                        : '寤鸿鍏虫敞娴侀噺鍏ュ彛浼樺寲锛屾彁鍗囨洕鍏夐噺鍜屾祻瑙堥噺銆?}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>绔炲鍒嗘瀽锛?/strong>
                    鍏卞垎鏋?${hotels.length} 瀹剁珵瀵归厭搴楋紝
                    ${topByRoomNights[0] ? `${topByRoomNights[0].hotelName} 琛ㄧ幇鏈€浣筹紙${(topByRoomNights[0].roomNights || 0).toLocaleString()} 闂村锛夛紝` : ''}
                    寤鸿鍒嗘瀽鍏舵垚鍔熷洜绱犲苟鍊熼壌瀛︿範銆?
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>钀ラ攢寤鸿锛?/strong>
                    ${totalExposure > totalViews * 10 
                        ? '鏇濆厜閲忓厖瓒充絾娴忚杞寲鍋忎綆锛屽缓璁紭鍖栦富鍥惧拰鏍囬鍚稿紩鐐瑰嚮銆? 
                        : '寤鸿澧炲姞骞冲彴鎺ㄥ箍鎶曟斁锛屾墿澶ф洕鍏夐噺锛屽悓鏃跺叧娉ㄨ瘎浠风淮鎶ゃ€?}
                </div>
            </div>
        </div>
    </div>
    
    <!-- 鍒嗘瀽鏃堕棿 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>鍒嗘瀽鏃堕棿锛?{new Date().toLocaleString('zh-CN')}
    </div>
</div>`;
                
                return report;
            };
            
            // 澶嶅埗AI鍒嗘瀽缁撴灉
            const copyAiAnalysisResult = () => {
                if (!aiAnalysisResult.value) {
                    showToast('鏆傛棤鍒嗘瀽缁撴灉鍙鍒?, 'warning');
                    return;
                }
                
                // 灏咹TML杞崲涓虹函鏂囨湰
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = aiAnalysisResult.value;
                const textContent = tempDiv.innerText || tempDiv.textContent;
                
                copyToClipboard(textContent);
            };
            
            // 鏌ョ湅AI鍒嗘瀽鍘嗗彶璁板綍
            const viewAiAnalysisRecord = (record) => {
                aiAnalysisResult.value = record.report;
            };

            // ==================== 缇庡洟AI鏅鸿兘鍒嗘瀽鐩稿叧鍑芥暟 ====================
            // 鏇存柊缇庡洟AI鍒嗘瀽閰掑簵鍒楄〃锛堝彧浠庣編鍥㈡暟鎹腑鎻愬彇锛?
            const updateMeituanAiAnalysisHotelList = () => {
                const hotelMap = new Map();
                
                // 鍙粠缇庡洟鏁版嵁涓彁鍙?
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
                console.log('缇庡洟AI鍒嗘瀽閰掑簵鍒楄〃鏇存柊:', meituanAiAnalysisHotelList.value.length, '瀹堕厭搴?);
            };
            
            // 鍏ㄩ€夌編鍥I鍒嗘瀽閰掑簵
            const selectAllMeituanAiHotels = () => {
                meituanAiSelectedHotels.value = meituanAiAnalysisHotelList.value.map(h => h.poiId + '_' + h.hotelName);
                showToast('宸插叏閫?' + meituanAiSelectedHotels.value.length + ' 瀹堕厭搴?);
            };
            
            // 娓呯┖缇庡洟AI鍒嗘瀽閰掑簵閫夋嫨
            const clearMeituanAiHotelSelection = () => {
                meituanAiSelectedHotels.value = [];
                showToast('宸叉竻绌洪€夋嫨');
            };
            
            // 寮€濮嬬編鍥I鍒嗘瀽
            const startMeituanAiAnalysis = async () => {
                if (meituanAiSelectedHotels.value.length === 0) {
                    showToast('璇峰厛閫夋嫨瑕佸垎鏋愮殑閰掑簵', 'error');
                    return;
                }
                
                // 鑾峰彇閫変腑閰掑簵鐨勮缁嗘暟鎹?
                const selectedData = meituanAiSelectedHotels.value.map(key => {
                    return meituanAiAnalysisHotelList.value.find(h => h.poiId + '_' + h.hotelName === key);
                }).filter(Boolean);
                
                if (selectedData.length === 0) {
                    showToast('鏈壘鍒伴€変腑鐨勯厭搴楁暟鎹?, 'error');
                    return;
                }
                
                meituanAiAnalyzing.value = true;
                meituanAiAnalysisResult.value = '';
                
                try {
                    // 鍑嗗鍒嗘瀽鏁版嵁
                    const analysisData = {
                        hotels: selectedData,
                        total_hotels: selectedData.length,
                        analysis_type: 'business_overview',
                        source: 'meituan',
                        include_suggestions: true
                    };
                    
                    showToast('AI姝ｅ湪鍒嗘瀽鏁版嵁锛岃绋嶅€?..');
                    
                    // 璋冪敤鍚庣AI鍒嗘瀽鎺ュ彛
                    const res = await request('/online-data/ai-analysis', {
                        method: 'POST',
                        body: JSON.stringify(analysisData),
                    });
                    
                    if (res.code === 200 && res.data) {
                        meituanAiAnalysisResult.value = res.data.report || res.data.analysis || res.data;
                        
                        // 娣诲姞鍒板巻鍙茶褰?
                        meituanAiAnalysisHistory.value.unshift({
                            id: Date.now(),
                            hotel_names: selectedData.slice(0, 3).map(h => h.hotelName).join('銆?) + (selectedData.length > 3 ? '绛? : ''),
                            hotel_count: selectedData.length,
                            summary: res.data.summary || 'AI鍒嗘瀽鎶ュ憡',
                            report: meituanAiAnalysisResult.value,
                            create_time: new Date().toLocaleString('zh-CN')
                        });
                        
                        // 鍙繚鐣欐渶杩?0鏉¤褰?
                        if (meituanAiAnalysisHistory.value.length > 10) {
                            meituanAiAnalysisHistory.value = meituanAiAnalysisHistory.value.slice(0, 10);
                        }
                        
                        showToast('AI鍒嗘瀽瀹屾垚锛?);
                    } else {
                        // 濡傛灉鍚庣API鏈疄鐜帮紝浣跨敤鏈湴鍒嗘瀽
                        meituanAiAnalysisResult.value = generateMeituanLocalAnalysis(selectedData);
                        showToast('AI鍒嗘瀽瀹屾垚锛堟湰鍦板垎鏋愶級');
                    }
                } catch (e) {
                    console.error('缇庡洟AI鍒嗘瀽璇锋眰澶辫触:', e);
                    // 缃戠粶閿欒鏃朵娇鐢ㄦ湰鍦板垎鏋?
                    meituanAiAnalysisResult.value = generateMeituanLocalAnalysis(selectedData);
                    showToast('浣跨敤鏈湴鍒嗘瀽瀹屾垚');
                } finally {
                    meituanAiAnalyzing.value = false;
                }
            };
            
            // 鏈湴鐢熸垚缇庡洟AI鍒嗘瀽鎶ュ憡
            const generateMeituanLocalAnalysis = (hotels) => {
                if (!hotels || hotels.length === 0) {
                    return '<p class="text-gray-500">鏆傛棤鏁版嵁鍙緵鍒嗘瀽</p>';
                }
                
                // 璁＄畻缁熻鏁版嵁
                const totalRoomNights = hotels.reduce((sum, h) => sum + (h.roomNights || 0), 0);
                const totalRoomRevenue = hotels.reduce((sum, h) => sum + (h.roomRevenue || 0), 0);
                const totalSales = hotels.reduce((sum, h) => sum + (h.sales || 0), 0);
                const totalExposure = hotels.reduce((sum, h) => sum + (h.exposure || 0), 0);
                const totalViews = hotels.reduce((sum, h) => sum + (h.views || 0), 0);
                
                const avgRoomNights = totalRoomNights / hotels.length;
                const avgRoomRevenue = totalRoomRevenue / hotels.length;
                const avgPricePerNight = totalRoomNights > 0 ? totalRoomRevenue / totalRoomNights : 0;
                
                // 鎵惧嚭鎺掑悕闈犲墠鐨勯厭搴?
                const topByRoomNights = [...hotels].sort((a, b) => (b.roomNights || 0) - (a.roomNights || 0)).slice(0, 5);
                const topByRevenue = [...hotels].sort((a, b) => (b.roomRevenue || 0) - (a.roomRevenue || 0)).slice(0, 5);
                
                // 璁＄畻杞寲鐜囩浉鍏?
                const avgViewConversion = hotels.reduce((sum, h) => sum + (h.viewConversion || 0), 0) / hotels.length;
                const avgPayConversion = hotels.reduce((sum, h) => sum + (h.payConversion || 0), 0) / hotels.length;
                
                // 鐢熸垚HTML鎶ュ憡
                let report = `
<div class="space-y-6">
    <!-- 姒傝鍗＄墖 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-yellow-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600">${hotels.length}</div>
            <div class="text-sm text-gray-600">鍒嗘瀽閰掑簵鏁?/div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">${totalRoomNights.toLocaleString()}</div>
            <div class="text-sm text-gray-600">鎬诲叆浣忛棿澶?/div>
        </div>
        <div class="bg-red-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-red-600">楼${totalRoomRevenue.toLocaleString()}</div>
            <div class="text-sm text-gray-600">鎬绘埧璐规敹鍏?/div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">楼${avgPricePerNight.toFixed(0)}</div>
            <div class="text-sm text-gray-600">骞冲潎鎴夸环</div>
        </div>
    </div>
    
    <!-- 缁忚惀鍒嗘瀽 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-yellow-500 mr-2"></i>缁忚惀鏁版嵁鍒嗘瀽
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">骞冲潎闂村锛?/span>
                <span class="text-gray-800">${avgRoomNights.toFixed(1)} 闂村/搴?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">骞冲潎鏀跺叆锛?/span>
                <span class="text-gray-800">楼${avgRoomRevenue.toFixed(0)}/搴?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">鎬婚攢鍞锛?/span>
                <span class="text-gray-800">楼${totalSales.toLocaleString()}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">鏇濆厜閲忥細</span>
                <span class="text-gray-800">${totalExposure.toLocaleString()} 娆?/span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">娴忚閲忥細</span>
                <span class="text-gray-800">${totalViews.toLocaleString()} 娆?/span>
            </div>
        </div>
    </div>
    
    <!-- 鍏ヤ綇闂村TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>鍏ヤ綇闂村 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRoomNights.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-orange-600">${(h.roomNights || 0).toLocaleString()} 闂村</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- 鎴胯垂鏀跺叆TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-red-500 mr-2"></i>鎴胯垂鏀跺叆 TOP5
        </h3>
        <div class="space-y-2">
            ${topByRevenue.map((h, i) => `
            <div class="flex items-center justify-between p-2 ${i === 0 ? 'bg-red-50 border-l-4 border-red-400' : 'bg-gray-50'} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full ${i < 3 ? 'bg-red-400 text-white' : 'bg-gray-300 text-white'} flex items-center justify-center text-xs font-bold mr-2">${i + 1}</span>
                    <span class="text-sm font-medium">${h.hotelName}</span>
                </div>
                <span class="text-sm font-bold text-red-600">楼${(h.roomRevenue || 0).toLocaleString()}</span>
            </div>
            `).join('')}
        </div>
    </div>
    
    <!-- AI寤鸿 -->
    <div class="bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-200 rounded-lg p-4">
        <h3 class="font-bold text-yellow-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>AI缁忚惀寤鸿
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>瀹氫环绛栫暐锛?/strong>
                    褰撳墠骞冲潎鎴夸环 楼${avgPricePerNight.toFixed(0)}锛?
                    ${avgPricePerNight > 300 ? '寤鸿鍏虫敞鎬т环姣旓紝鍙€傚綋鎺ㄥ嚭浼樻儬濂楅鍚稿紩鏇村瀹㈡簮' : '瀹氫环鐩稿浜叉皯锛屽彲閫氳繃澧炲€兼湇鍔℃彁鍗囧鍗曚环'}銆?
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>娴侀噺杞寲锛?/strong>
                    ${totalExposure > 0 && totalViews > 0 
                        ? `鏇濆厜鍒版祻瑙堣浆鍖栫巼 ${((totalViews / totalExposure) * 100).toFixed(1)}%锛宍 
                        : ''}
                    ${avgViewConversion > 0 
                        ? `骞冲潎娴忚杞寲 ${avgViewConversion.toFixed(1)}锛屽缓璁紭鍖栬鎯呴〉鍥剧墖鍜屾弿杩版彁鍗囪浆鍖栫巼銆俙 
                        : '寤鸿鍏虫敞娴侀噺鍏ュ彛浼樺寲锛屾彁鍗囨洕鍏夐噺鍜屾祻瑙堥噺銆?}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>绔炲鍒嗘瀽锛?/strong>
                    鍏卞垎鏋?${hotels.length} 瀹剁珵瀵归厭搴楋紝
                    ${topByRoomNights[0] ? `${topByRoomNights[0].hotelName} 琛ㄧ幇鏈€浣筹紙${(topByRoomNights[0].roomNights || 0).toLocaleString()} 闂村锛夛紝` : ''}
                    寤鸿鍒嗘瀽鍏舵垚鍔熷洜绱犲苟鍊熼壌瀛︿範銆?
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>缇庡洟浼樺寲寤鸿锛?/strong>
                    ${totalExposure > totalViews * 10 
                        ? '鏇濆厜閲忓厖瓒充絾娴忚杞寲鍋忎綆锛屽缓璁紭鍖栦富鍥俱€佹爣棰樺拰棣栧睆淇℃伅鍚稿紩鐐瑰嚮銆? 
                        : '寤鸿澧炲姞缇庡洟鎺ㄥ箍鎶曟斁锛屽弬涓庡钩鍙版椿鍔紝鍚屾椂鍏虫敞璇勪环鍜岄棶绛旂淮鎶ゃ€?}
                </div>
            </div>
        </div>
    </div>
    
    <!-- 鍒嗘瀽鏃堕棿 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>鍒嗘瀽鏃堕棿锛?{new Date().toLocaleString('zh-CN')}
    </div>
</div>`;
                
                return report;
            };
            
            // 澶嶅埗缇庡洟AI鍒嗘瀽缁撴灉
            const copyMeituanAiAnalysisResult = () => {
                if (!meituanAiAnalysisResult.value) {
                    showToast('鏆傛棤鍒嗘瀽缁撴灉鍙鍒?, 'warning');
                    return;
                }
                
                // 灏咹TML杞崲涓虹函鏂囨湰
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = meituanAiAnalysisResult.value;
                const textContent = tempDiv.innerText || tempDiv.textContent;
                
                copyToClipboard(textContent);
            };
            
            // 鏌ョ湅缇庡洟AI鍒嗘瀽鍘嗗彶璁板綍
            const viewMeituanAiAnalysisRecord = (record) => {
                meituanAiAnalysisResult.value = record.report;
            };

            const copyToClipboard = (text) => {
                // 浼樺厛浣跨敤 navigator.clipboard API
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(() => {
                        showToast('宸插鍒跺埌鍓创鏉?);
                    }).catch(() => {
                        // 澶囩敤鏂规锛氫娇鐢?document.execCommand
                        fallbackCopy(text);
                    });
                } else {
                    // 澶囩敤鏂规锛氫娇鐢?document.execCommand
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
                    showToast('宸插鍒跺埌鍓创鏉?);
                } catch (err) {
                    showToast('澶嶅埗澶辫触锛岃鎵嬪姩澶嶅埗', 'error');
                }
                document.body.removeChild(textarea);
            };

            const copyOnlineDataResult = () => {
                if (onlineDataResult.value) {
                    copyToClipboard(JSON.stringify(onlineDataResult.value, null, 2));
                } else {
                    showToast('娌℃湁鏁版嵁鍙鍒?, 'error');
                }
            };

            const loadData = async () => {
                await loadDailyReportConfig(); // 鍏堝姞杞芥棩鎶ヨ〃閰嶇疆锛屽繀椤荤瓑寰呭畬鎴?
                await loadMonthlyTaskConfig(); // 鍔犺浇鏈堜换鍔￠厤缃?
                loadHotels();
                loadRoles();
                loadUserInfo();
                loadSystemConfig();
                loadCookiesList(); // 鍔犺浇Cookies鍒楄〃
                loadBookmarklet(); // 鍔犺浇涔︾鑴氭湰
                if (user.value?.is_super_admin || user.value?.role_id === 2) {
                    loadUsers();
                }
                if (user.value?.is_super_admin) {
                    loadReportConfigs();
                    loadRolesList(); // 鍔犺浇瑙掕壊鍒楄〃
                }
                loadDailyReports();
                loadMonthlyTasks();
                loadCompassData();
            };

            // 閰掑簵鎿嶄綔
            const openHotelModal = (hotel = null) => {
                if (hotel) {
                    hotelForm.value = { ...hotel };
                } else {
                    hotelForm.value = { id: null, name: '', code: '', address: '', contact_person: '', contact_phone: '', status: 1, description: '' };
                }
                showHotelModal.value = true;
            };

            const saveHotel = async () => {
                const isEdit = !!hotelForm.value.id;
                const url = isEdit ? `/hotels/${hotelForm.value.id}` : '/hotels';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(hotelForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showHotelModal.value = false;
                    loadHotels();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const toggleHotelStatus = async (hotel) => {
                const newStatus = hotel.status === 1 ? 0 : 1;
                const statusText = newStatus === 1 ? '鍚敤' : '绂佺敤';
                if (!confirm(`纭畾瑕?{statusText}閰掑簵"${hotel.name}"鍚楋紵\n${newStatus === 0 ? '绂佺敤鍚庯紝璇ラ厭搴楀叧鑱旂殑鎵€鏈夌敤鎴锋潈闄愬皢鏆傛椂澶辨晥銆? : '鍚敤鍚庯紝璇ラ厭搴楀叧鑱旂殑鐢ㄦ埛鏉冮檺灏嗘仮澶嶃€?}`)) return;
                
                const res = await request(`/hotels/${hotel.id}`, { 
                    method: 'PUT', 
                    body: JSON.stringify({ ...hotel, status: newStatus }) 
                });
                if (res.code === 200) {
                    if (res.data?.status_changed) {
                        showToast(res.data.status_text + `锛屽奖鍝?{res.data.affected_users}涓敤鎴风殑鏉冮檺`);
                    } else {
                        showToast(`${statusText}鎴愬姛`);
                    }
                    loadHotels();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteHotel = async (hotel) => {
                if (!confirm(`纭畾瑕佸垹闄ら厭搴?${hotel.name}"鍚楋紵`)) return;
                const res = await request(`/hotels/${hotel.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadHotels();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };

            // 鐢ㄦ埛鎿嶄綔
            const openUserModal = (u = null) => {
                if (u) {
                    userForm.value = { ...u, password: '' };
                } else {
                    // 搴楅暱鍒涘缓鐢ㄦ埛鏃讹紝榛樿涓哄簵鍛樿鑹诧紝閰掑簵涓鸿嚜宸辩殑閰掑簵
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
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showUserModal.value = false;
                    loadUsers();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteUser = async (u) => {
                if (!confirm(`纭畾瑕佸垹闄ょ敤鎴?${u.username}"鍚楋紵`)) return;
                const res = await request(`/users/${u.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadUsers();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };

            // 瑙掕壊鎿嶄綔
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
                    // 澶勭悊permissions锛屽彲鑳芥槸JSON瀛楃涓叉垨鏁扮粍
                    let perms = [];
                    if (r.permissions) {
                        perms = typeof r.permissions === 'string' ? JSON.parse(r.permissions) : r.permissions;
                    }
                    // 纭繚perms鏄暟缁?
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
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showRoleModal.value = false;
                    loadRolesList();
                    loadRoles();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteRole = async (r) => {
                if (!confirm(`纭畾瑕佸垹闄よ鑹?${r.display_name}"鍚楋紵`)) return;
                const res = await request(`/roles/${r.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadRolesList();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
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

            // 鏉冮檺鎿嶄綔
            const openPermissionModal = async (u) => {
                permissionUser.value = u;
                // 鏉冮檺浠庤鑹茬户鎵匡紝涓嶅啀鍗曠嫭璁剧疆
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
                showToast('鏉冮檺宸蹭粠瑙掕壊缁ф壙锛屾棤闇€鍗曠嫭璁剧疆', 'info');
                showPermissionModal.value = false;
            };

            // 鏃ユ姤琛ㄦ搷浣?
            const openDailyReportModal = async (report = null) => {
                // 閲嶇疆瀵煎叆鐘舵€?
                importedFromFile.value = false;
                importStatus.value = { show: false, type: '', message: '' };
                
                // 濡傛灉閰嶇疆鏈姞杞斤紝鍏堝姞杞介厤缃?
                if (dailyReportConfig.value.length === 0) {
                    await loadDailyReportConfig();
                }
                
                if (report) {
                    // 缂栬緫鏃讹紝鍚堝苟鎶ヨ〃鏁版嵁
                    dailyReportForm.value = { 
                        id: report.id, 
                        hotel_id: report.hotel_id, 
                        report_date: report.report_date,
                        ...(report.report_data || {})
                    };
                } else {
                    // 鏂板鏃讹紝鍒濆鍖栨墍鏈夊瓧娈典负0
                    const yesterday = new Date();
                    yesterday.setDate(yesterday.getDate() - 1);
                    const formData = { 
                        id: null, 
                        hotel_id: permittedHotels.value.length === 1 ? permittedHotels.value[0].id : '', 
                        report_date: yesterday.toISOString().split('T')[0]
                    };
                    // 鍒濆鍖栨墍鏈夐厤缃瓧娈典负0
                    dailyReportConfig.value.forEach(category => {
                        category.items.forEach(item => {
                            formData[item.field_name] = 0;
                        });
                    });
                    dailyReportForm.value = formData;
                }
                console.log('鎵撳紑鏃ユ姤琛ㄥ脊绐楋紝閰嶇疆椤规暟閲?', dailyReportConfig.value.length);
                dailyReportTab.value = 'tab1'; // 閲嶇疆鍒扮涓€涓爣绛鹃〉
                showDailyReportModal.value = true;
            };

            const saveDailyReport = async () => {
                const isEdit = !!dailyReportForm.value.id;
                const url = isEdit ? `/daily-reports/${dailyReportForm.value.id}` : '/daily-reports';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(dailyReportForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showDailyReportModal.value = false;
                    loadDailyReports();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteDailyReport = async (report) => {
                if (!confirm(`纭畾瑕佸垹闄よ鏃ユ姤琛ㄥ悧锛焋)) return;
                const res = await request(`/daily-reports/${report.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadDailyReports();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };
            
            // 瀵煎叆Excel鐩稿叧鍑芥暟
            const triggerImportExcel = () => {
                importFileInput.value?.click();
            };
            
            const triggerImportInModal = () => {
                importModalFileInput.value?.click();
            };
            
            const handleImportExcel = async (event) => {
                const file = event.target.files[0];
                if (!file) return;
                
                // 鎵撳紑鏃ユ姤琛ㄥ～鍐欐ā鎬佹
                await openDailyReportModal();
                
                // 鎵ц瀵煎叆
                await doImportExcel(file);
                
                // 娓呯┖鏂囦欢杈撳叆
                event.target.value = '';
            };
            
            const handleImportInModal = async (event) => {
                const file = event.target.files[0];
                if (!file) return;
                
                await doImportExcel(file);
                
                // 娓呯┖鏂囦欢杈撳叆
                event.target.value = '';
            };
            
            const doImportExcel = async (file) => {
                importingExcel.value = true;
                importStatus.value = { show: true, type: 'info', message: '姝ｅ湪瑙ｆ瀽Excel鏂囦欢...' };
                
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
                            throw new Error('瀵煎叆澶辫触锛氭湇鍔＄杩斿洖闈濲SON鍐呭');
                        }
                    }
                    if (!res) {
                        throw new Error(`瀵煎叆澶辫触锛氭湇鍔＄鏃犲搷搴斿唴瀹癸紙HTTP ${response.status}锛塦);
                    }
                    console.log('鍚庣杩斿洖:', res);
                    
                    if (res.code === 200 && res.data) {
                        const data = res.data;
                        
                        // 瀛樺偍棰勮鏁版嵁
                        importPreviewData.value = data;
                        
                        // 鎵撳嵃瀹屾暣璋冭瘯淇℃伅
                        console.log('=== Excel瀵煎叆璋冭瘯淇℃伅 ===');
                        console.log('閰掑簵鍚?', data.hotel_name);
                        console.log('鏃ユ湡:', data.report_date);
                        console.log('宸插尮閰嶅瓧娈垫暟:', data.matched_count);
                        console.log('鏈尮閰嶉」鐩暟:', data.unmatched_count);
                        console.log('mapped_data:', data.mapped_data);
                        
                        // 鏍囪涓轰粠鏂囦欢瀵煎叆锛堥攣瀹氭棩鏈熷拰閰掑簵锛?
                        importedFromFile.value = true;
                        
                        // 璁剧疆閰掑簵ID锛堟牴鎹厭搴楀悕绉板尮閰嶏級
                        if (data.hotel_name) {
                            const hotel = permittedHotels.value.find(h => 
                                h.name === data.hotel_name || 
                                h.name.includes(data.hotel_name) || 
                                data.hotel_name.includes(h.name)
                            );
                            if (hotel) {
                                dailyReportForm.value.hotel_id = hotel.id;
                                console.log('鍖归厤鍒伴厭搴?', hotel.name, 'ID:', hotel.id);
                            } else {
                                console.warn('鏈尮閰嶅埌閰掑簵:', data.hotel_name, '宸叉湁閰掑簵:', permittedHotels.value.map(h => h.name));
                            }
                        }
                        
                        // 璁剧疆鏃ユ湡
                        if (data.report_date) {
                            dailyReportForm.value.report_date = data.report_date;
                        }
                        
                        // 濉厖鎶ヨ〃鏁版嵁
                        const reportData = data.mapped_data || {};
                        console.log('瀵煎叆鏁版嵁 mapped_data:', reportData);
                        
                        // 鍒濆鍖栨槧灏勭姸鎬?
                        existingMappings.value = {};
                        rowMappings.value = {};
                        
                        // 浠嶢PI杩斿洖鐨勬槧灏勯厤缃瀯寤哄凡鏄犲皠鍏崇郴
                        if (data.field_mappings) {
                            data.field_mappings.forEach(m => {
                                if (reportData[m.system_field] !== undefined) {
                                    existingMappings.value[m.excel_item_name] = m.system_field;
                                }
                            });
                        }
                        
                        // 鐩存帴閬嶅巻璧嬪€?
                        Object.keys(reportData).forEach(key => {
                            dailyReportForm.value[key] = reportData[key];
                        });
                        
                        // 寮哄埗鍒锋柊瑙嗗浘
                        const temp = dailyReportForm.value;
                        dailyReportForm.value = { ...temp };
                        
                        console.log('瀵煎叆鍚庤〃鍗?', dailyReportForm.value);
                        
                        // 鏋勫缓鐘舵€佹秷鎭?
                        const fieldCount = data.matched_count || Object.keys(reportData).length;
                        const unmatchedCount = data.unmatched_count || 0;
                        
                        let msg = `瀵煎叆鎴愬姛锛侀厭搴? ${data.hotel_name || '寰呴€夋嫨'}, 鏃ユ湡: ${data.report_date || '寰呭～鍐?}`;
                        msg += `, 宸插～鍏?${fieldCount} 涓瓧娈礰;
                        if (unmatchedCount > 0) {
                            msg += ` (${unmatchedCount} 椤规湭鍖归厤)`;
                        }
                        
                        importStatus.value = { 
                            show: true, 
                            type: fieldCount > 0 ? 'success' : 'warning', 
                            message: msg
                        };
                        
                        // 濡傛灉鏈夋湭鍖归厤椤癸紝鍔犺浇绯荤粺瀛楁閫夐」渚涙墜鍔ㄦ槧灏?
                        if (unmatchedCount > 0 && systemFieldOptions.value.length === 0) {
                            await loadSystemFieldOptions();
                        }
                        
                        // 8绉掑悗闅愯棌鎻愮ず
                        setTimeout(() => {
                            importStatus.value.show = false;
                        }, 8000);
                    } else {
                        importStatus.value = { 
                            show: true, 
                            type: 'error', 
                            message: res.message || '瑙ｆ瀽澶辫触锛岃妫€鏌ユ枃浠舵牸寮? 
                        };
                    }
                } catch (e) {
                    console.error('瀵煎叆澶辫触:', e);
                    importStatus.value = { 
                        show: true, 
                        type: 'error', 
                        message: '瀵煎叆澶辫触锛? + (e.message || '缃戠粶閿欒') 
                    };
                } finally {
                    importingExcel.value = false;
                }
            };
            
            // 搴旂敤鎵嬪姩鏄犲皠
            const applyManualMapping = () => {
                if (!importPreviewData.value) return;
                
                // 灏嗘墜鍔ㄦ槧灏勫簲鐢ㄥ埌琛ㄥ崟
                Object.keys(manualMappings.value).forEach(excelItemName => {
                    const systemField = manualMappings.value[excelItemName];
                    if (systemField) {
                        // 浠庢湭鍖归厤椤逛腑鎵惧埌鍊?
                        const item = importPreviewData.value.unmatched_items?.find(i => i.item_name === excelItemName);
                        if (item) {
                            // 瑙ｆ瀽鍊?
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
                
                // 娓呯┖鎵嬪姩鏄犲皠
                manualMappings.value = {};
                showToast('鎵嬪姩鏄犲皠宸插簲鐢?);
            };
            
            // 鑾峰彇鏄犲皠鐘舵€?
            const getMappingStatus = (item) => {
                if (!item || item.item_name === '椤圭洰') return '';
                
                // 妫€鏌ユ槸鍚﹀湪宸叉湁鏄犲皠涓?
                const existingField = existingMappings.value[item.item_name];
                if (existingField) return 'mapped';
                
                // 妫€鏌ユ槸鍚﹀湪鎵嬪姩鏄犲皠涓?
                const manualField = rowMappings.value[item.item_name];
                if (manualField) return 'manual';
                
                return '';
            };
            
            // 澶勭悊鏄犲皠鍙樺寲
            const onMappingChange = (item, event) => {
                const newField = event.target.value;
                if (newField) {
                    rowMappings.value[item.item_name] = newField;
                } else {
                    delete rowMappings.value[item.item_name];
                }
            };
            
            // 搴旂敤鎵€鏈夋槧灏?
            const applyAllMappings = () => {
                if (!importPreviewData.value) return;
                
                // 鍚堝苟宸叉湁鏄犲皠鍜屾墜鍔ㄦ槧灏?
                const allMappings = { ...existingMappings.value, ...rowMappings.value };
                let appliedCount = 0;
                
                importPreviewData.value.structured_data.forEach(item => {
                    const systemField = allMappings[item.item_name];
                    if (systemField && item.item_name !== '椤圭洰') {
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
                
                // 鏇存柊宸叉湁鏄犲皠
                Object.assign(existingMappings.value, rowMappings.value);
                rowMappings.value = {};
                
                showToast(`宸插簲鐢?${appliedCount} 涓瓧娈垫槧灏刞);
            };
            
            // 淇濆瓨涓烘柊鏄犲皠閰嶇疆
            const saveAsMappingConfig = async () => {
                const newMappings = Object.entries(rowMappings.value).filter(([_, field]) => field);
                if (newMappings.length === 0) {
                    showToast('璇峰厛閫夋嫨瑕佷繚瀛樼殑鏄犲皠', 'error');
                    return;
                }
                
                if (!confirm(`纭畾瑕佸皢 ${newMappings.length} 涓槧灏勪繚瀛樹负閰嶇疆鍚楋紵`)) return;
                
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
                        showToast(`淇濆瓨鎴愬姛锛氭柊寤?${res.data.created} 鏉★紝鏇存柊 ${res.data.updated} 鏉);
                        // 鏇存柊宸叉湁鏄犲皠
                        Object.assign(existingMappings.value, rowMappings.value);
                        rowMappings.value = {};
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触锛? + e.message, 'error');
                }
            };

            // 鏌ョ湅鏃ユ姤琛ㄨ鎯?
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
                        showToast(res.message || '鑾峰彇璇︽儏澶辫触', 'error');
                        showViewReportModal.value = false;
                    }
                } catch (e) {
                    showToast('鑾峰彇璇︽儏澶辫触', 'error');
                    showViewReportModal.value = false;
                } finally {
                    viewReportLoading.value = false;
                }
            };

            // 澶嶅埗鏃ユ姤琛ㄥ唴瀹?
            const copyReportContent = () => {
                if (!viewReportData.value) return;
                
                const d = viewReportData.value;
                const content = `${d.hotel_name}
${d.report_date}
鎬绘埧闂存暟: ${d.total_rooms}闂?锛堝彲鍞細${d.salable_rooms}缁翠慨锛?{d.maintenance_rooms}锛?
涓€銆侀攢鍞笟缁?锛?
1銆佹湀钀ユ敹鎬荤洰鏍?  ${d.month_revenue_target}鍏?
2銆佹湀绱瀹屾垚钀ユ敹:${d.month_revenue}鍏?锛堟埧璐规敹鍏? ${d.month_room_revenue}鍏?鍏跺畠鏀跺叆:${d.month_other_revenue}鍏冿級褰撴湡宸:${d.month_revenue_diff}鍏?
3銆佹湀褰撴湡瀹屾垚鐜?${d.month_complete_rate}%
4銆佹棩钀ユ敹褰撴湡鐩爣:${d.day_revenue_target}鍏?
5銆佹棩瀹為檯瀹屾垚钀ユ敹:${d.day_revenue}鍏冿紙鎴胯垂鏀跺叆${d.day_room_revenue}鍏?鍏跺畠鏀跺叆:${d.day_other_revenue}鍏冿級锛屽綋鏃ュ樊棰?${d.day_revenue_diff}鍏?
6銆佹湀缁煎悎鍑虹鐜?${d.month_occ_rate}%
7銆佹棩缁煎悎鍑虹鐜?${d.day_occ_rate}%
8銆佹棩杩囧鍑虹鐜?${d.day_overnight_occ_rate}%
9銆佹棩鍑虹鎬绘暟:${d.day_total_rooms}闂?锛堣繃澶滄埧:${d.day_overnight_rooms}闂?闈炶繃澶滄埧:0闂?閽熺偣鎴?${d.day_hourly_rooms}闂达級
10銆佹湀鍧囦环:${d.month_adr}鍏?
11銆佹棩鍧囦环:${d.day_adr}鍏?
12銆佽繃澶滃潎浠?${d.overnight_adr}鍏?
13銆佹湀Revpar:${d.month_revpar}鍏?
14銆佹棩Revpar:${d.day_revpar}鍏?
15銆佸綋鏃ュ偍鍊奸噾棰?${d.day_stored_value}鍏?
16銆佸綋鏈堝偍鍊奸噾棰?${d.month_stored_value}鍏?
浜屻€佸婧愮粨鏋? 锛?  
17銆佷細鍛?${d.member_count}
18銆佸崗璁? ${d.protocol_count}
19銆佹暎瀹?${d.walkin_count}
20銆佸洟闃? ${d.group_count}
21銆丱TA鎬婚噺:${d.ota_total_rooms}闂?锛堢編鍥?{d.mt_rooms}闂淬€佹惡绋?{d.xb_rooms}闂淬€佸悓绋?{d.tc_rooms}闂淬€佸幓鍝効${d.qn_rooms}闂淬€佹櫤琛?{d.zx_rooms}闂淬€侀鐚?{d.fliggy_rooms}闂达級
22銆佸井淇?${d.wechat_count}鍗?锛屾姈闊?${d.dy_count}鍗?
23銆佷細鍛樹綋楠屼环:${d.member_exp_rooms}
24銆佺綉缁滀綋楠屼环:${d.web_exp_rooms}
25銆佹湰鏃ュ厤璐规埧鏁?${d.free_rooms}闂?
涓夈€佺洿閿€鎸囨爣:
26銆佹棩鏂板浼氬憳:${d.day_new_member}涓?
27銆佹湀鏂板浼氬憳鐩爣锛?{d.month_new_member_target}涓紱鐜板畬鎴愶細${d.month_new_member}涓?褰撴湡宸:${d.month_member_diff}涓?
28銆佹棩寰俊鍔犵矇:瀹屾垚${d.day_wechat_add}涓紱
29銆佹湀寰俊鍔犵矇鐩爣锛?{d.month_wechat_target}涓紝瀹為檯瀹屾垚${d.month_wechat_add}涓紱褰撴湡宸:${d.month_wechat_diff}涓?
32銆佹棩绉佸煙娴侀噺锛?{d.day_private_rooms}鍗曪紱钀ユ敹:${d.day_private_revenue}鍏冿紱
33銆佹湀绉佸煙娴侀噺锛?{d.month_private_rooms}鍗曪紝钀ユ敹:${d.month_private_revenue}鍏冿紱鍗犳瘮鎬昏惀鏀?${d.private_rate}%
鍥涖€丱TA娓犻亾璇勫垎鍊硷細
34銆佹棩鐐硅瘎鏂板锛?{d.day_good_review}濂借瘎鏉★紱${d.day_bad_review}宸瘎鏉?
35銆佹湀鐐硅瘎鏂板锛?{d.month_good_review}濂借瘎鏉★紱${d.month_bad_review}宸瘎鏉?
浜斻€佸厤璐规埧鏁帮細
36銆佹湰鏈堝厤璐规埧鎬绘暟:${d.month_free_rooms}闂?
鍏€佹槑鏃ラ璁㈡暟閲? ${d.tomorrow_booking}
涓冦€佷粖鏃ョ幇閲戞敹鍏? ${d.day_cash_income}
鍏€佸綋鏈堢疮璁＄幇閲戜綑棰? ${d.month_cash_income}`;

                navigator.clipboard.writeText(content).then(() => {
                    showToast('澶嶅埗鎴愬姛锛?);
                }).catch(() => {
                    // 闄嶇骇鏂规
                    const textarea = document.createElement('textarea');
                    textarea.value = content;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    showToast('澶嶅埗鎴愬姛锛?);
                });
            };

            // 瀵煎嚭鏃ユ姤琛紙鎵归噺锛?
            const exportDailyReports = async () => {
                if (!filterReportStartDate.value || !filterReportEndDate.value) {
                    showToast('璇烽€夋嫨鏃ユ湡鑼冨洿', 'error');
                    return;
                }
                
                exportingReports.value = true;
                
                try {
                    const params = new URLSearchParams({
                        start_date: filterReportStartDate.value,
                        end_date: filterReportEndDate.value,
                    });
                    
                    // 閰掑簵ID锛氫紭鍏堜娇鐢ㄩ€夋嫨鐨勯厭搴楋紝鍗曢棬搴楃敤鎴疯嚜鍔ㄤ娇鐢ㄥ叾鍞竴閰掑簵
                    let hotelId = filterReportHotel.value;
                    if (!hotelId && !user.value.is_super_admin && permittedHotels.value.length === 1) {
                        hotelId = permittedHotels.value[0].id;
                    }
                    if (hotelId) {
                        params.append('hotel_id', hotelId);
                    }
                    
                    // 浣跨敤fetch涓嬭浇鏂囦欢
                    const response = await fetch(`${API_BASE}/daily-reports/export?${params.toString()}`, {
                        headers: {
                            'Authorization': `Bearer ${token.value}`
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('瀵煎嚭澶辫触');
                    }
                    
                    // 鑾峰彇鏂囦欢鍚?
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = '鏃ユ姤琛ㄦ眹鎬?xlsx';
                    if (contentDisposition) {
                        const match = contentDisposition.match(/filename="?([^"]+)"?/);
                        if (match) {
                            filename = decodeURIComponent(match[1]);
                        }
                    }
                    
                    // 涓嬭浇鏂囦欢
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    
                    showToast('瀵煎嚭鎴愬姛锛?);
                } catch (e) {
                    showToast('瀵煎嚭澶辫触: ' + e.message, 'error');
                } finally {
                    exportingReports.value = false;
                }
            };

            // 瀵煎嚭鍗曚釜鏃ユ姤琛?
            const exportSingleReport = async (report) => {
                try {
                    const response = await fetch(`${API_BASE}/daily-reports/export?id=${report.id}`, {
                        headers: {
                            'Authorization': `Bearer ${token.value}`
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error('瀵煎嚭澶辫触');
                    }
                    
                    // 鑾峰彇鏂囦欢鍚?
                    const contentDisposition = response.headers.get('Content-Disposition');
                    let filename = `鏃ユ姤琛╛${report.hotel?.name || ''}_${report.report_date}.xlsx`;
                    if (contentDisposition) {
                        const match = contentDisposition.match(/filename="?([^"]+)"?/);
                        if (match) {
                            filename = decodeURIComponent(match[1]);
                        }
                    }
                    
                    // 涓嬭浇鏂囦欢
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                    
                    showToast('瀵煎嚭鎴愬姛锛?);
                } catch (e) {
                    showToast('瀵煎嚭澶辫触: ' + e.message, 'error');
                }
            };

            // 浠庤鎯呭脊绐楀鍑哄綋鍓嶆煡鐪嬬殑鎶ヨ〃
            const exportViewedReport = async () => {
                if (!viewReportData.value) return;
                
                // 浠庡綋鍓嶆煡鐪嬬殑鏁版嵁涓幏鍙栨姤琛↖D
                const report = dailyReports.value.find(r => 
                    r.report_date === viewReportData.value.report_date && 
                    r.hotel?.name === viewReportData.value.hotel_name
                );
                
                if (report) {
                    await exportSingleReport(report);
                } else {
                    showToast('鏃犳硶鎵惧埌瀵瑰簲鎶ヨ〃', 'error');
                }
            };

            // 鏃ユ姤鏌ョ湅鏄犲皠锛堜粎瓒呯锛?
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
                // 宸蹭繚瀛樼殑鏌ョ湅椤圭洰
                viewMappingConfig.value.forEach(item => addName(item.name));
                // 褰撳墠鏌ョ湅杩斿洖鐨勯」鐩?
                const serverConfig = viewReportData.value?.view_mapping?.config || [];
                serverConfig.forEach(item => addName(item.name));
                return names;
            });
            const viewMappingFieldOptions = computed(() => {
                return viewReportData.value?.view_mapping_sources || { report: [], task: [], month: [], calc: [] };
            });

            const viewMappingCategories = [
                { id: 'sales', label: '閿€鍞笟缁? },
                { id: 'guest', label: '瀹㈡簮缁撴瀯' },
                { id: 'direct', label: '鐩撮攢鎸囨爣' },
                { id: 'review', label: '鐐硅瘎鏂板' },
                { id: 'free', label: '鍏嶈垂鎴挎暟' },
                { id: 'booking', label: '鏄庢棩棰勮' },
                { id: 'cash', label: '鐜伴噾' },
                { id: 'other', label: '鍏朵粬' },
            ];
            const getViewMappingCategory = (name) => {
                if (!name) return 'other';
                if (/钀ユ敹|鍑虹鐜噟Revpar|鍧囦环|鍌ㄥ€紎鏀跺叆|钀ユ敹/.test(name)) return 'sales';
                if (/浼氬憳|鍗忚|鏁ｅ|鍥㈤槦|OTA鎬婚噺|寰俊|浣撻獙浠穦鍏嶈垂鎴挎暟/.test(name)) return 'guest';
                if (/鏂板浼氬憳|寰俊鍔犵矇|绉佸煙/.test(name)) return 'direct';
                if (/鐐硅瘎鏂板|濂借瘎|宸瘎/.test(name)) return 'review';
                if (/鍏嶈垂鎴挎€绘暟/.test(name)) return 'free';
                if (/鏄庢棩棰勮/.test(name)) return 'booking';
                if (/鐜伴噾/.test(name)) return 'cash';
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
                        showToast('鏄犲皠閰嶇疆宸蹭繚瀛?);
                        if (currentViewingReportId.value) {
                            await viewDailyReport({ id: currentViewingReportId.value });
                        }
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触锛? + e.message, 'error');
                } finally {
                    viewMappingSaving.value = false;
                }
            };

            // 鏈堜换鍔℃搷浣?
            const openMonthlyTaskModal = async (task = null) => {
                // 濡傛灉閰嶇疆鏈姞杞斤紝鍏堝姞杞介厤缃?
                if (monthlyTaskConfig.value.length === 0) {
                    await loadMonthlyTaskConfig();
                }
                
                if (task) {
                    // 缂栬緫鏃讹紝鍚堝苟浠诲姟鏁版嵁
                    monthlyTaskForm.value = { 
                        id: task.id, 
                        hotel_id: task.hotel_id, 
                        year: task.year, 
                        month: task.month,
                        ...(task.task_data || {})
                    };
                } else {
                    // 鏂板鏃讹紝鍒濆鍖栨墍鏈夊瓧娈典负0
                    const now = new Date();
                    const formData = { 
                        id: null, 
                        hotel_id: permittedHotels.value.length === 1 ? permittedHotels.value[0].id : '', 
                        year: now.getFullYear(), 
                        month: now.getMonth() + 1
                    };
                    // 鍒濆鍖栨墍鏈夐厤缃瓧娈典负0
                    monthlyTaskConfig.value.forEach(item => {
                        formData[item.field_name] = 0;
                    });
                    monthlyTaskForm.value = formData;
                }
                console.log('鎵撳紑鏈堜换鍔″脊绐楋紝閰嶇疆椤规暟閲?', monthlyTaskConfig.value.length);
                showMonthlyTaskModal.value = true;
            };

            const saveMonthlyTask = async () => {
                const isEdit = !!monthlyTaskForm.value.id;
                const url = isEdit ? `/monthly-tasks/${monthlyTaskForm.value.id}` : '/monthly-tasks';
                const method = isEdit ? 'PUT' : 'POST';
                const res = await request(url, { method, body: JSON.stringify(monthlyTaskForm.value) });
                if (res.code === 200) {
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showMonthlyTaskModal.value = false;
                    loadMonthlyTasks();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteMonthlyTask = async (task) => {
                if (!confirm(`纭畾瑕佸垹闄よ鏈堜换鍔″悧锛焋)) return;
                const res = await request(`/monthly-tasks/${task.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadMonthlyTasks();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };

            // 鎶ヨ〃閰嶇疆鎿嶄綔
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
                    showToast(isEdit ? '鏇存柊鎴愬姛' : '鍒涘缓鎴愬姛');
                    showReportConfigModal.value = false;
                    loadReportConfigs();
                } else {
                    showToast(res.message || '鎿嶄綔澶辫触', 'error');
                }
            };

            const deleteReportConfig = async (config) => {
                if (!confirm(`纭畾瑕佸垹闄ら厤缃」"${config.display_name}"鍚楋紵`)) return;
                const res = await request(`/report-configs/${config.id}`, { method: 'DELETE' });
                if (res.code === 200) {
                    showToast('鍒犻櫎鎴愬姛');
                    loadReportConfigs();
                } else {
                    showToast(res.message || '鍒犻櫎澶辫触', 'error');
                }
            };

            // 绯荤粺閰嶇疆鎿嶄綔
            const openSystemConfigModal = async () => {
                // 妫€鏌ユ槸鍚︽槸瓒呯骇绠＄悊鍛?
                if (!user.value?.is_super_admin) {
                    showToast('鍙湁瓒呯骇绠＄悊鍛樻墠鑳戒慨鏀圭郴缁熼厤缃?, 'error');
                    return;
                }
                systemConfigForm.value = { ...systemConfig.value };
                showSystemConfigModal.value = true;
            };

            const saveSystemConfig = async () => {
                if (!user.value?.is_super_admin) {
                    showToast('鍙湁瓒呯骇绠＄悊鍛樻墠鑳戒慨鏀圭郴缁熼厤缃?, 'error');
                    return;
                }
                try {
                    const res = await request('/system-config', {
                        method: 'PUT',
                        body: JSON.stringify(systemConfigForm.value)
                    });
                    if (res.code === 200) {
                        systemConfig.value = { ...systemConfig.value, ...res.data };
                        showToast('閰嶇疆淇濆瓨鎴愬姛');
                        showSystemConfigModal.value = false;
                    } else {
                        showToast(res.message || '淇濆瓨澶辫触', 'error');
                    }
                } catch (e) {
                    showToast('淇濆瓨澶辫触: ' + (e.message || '缃戠粶閿欒'), 'error');
                }
            };

            // 鍒濆鍖?
            onMounted(() => {
                // 鏇存柊鏃堕棿
                const updateTime = () => {
                    currentTime.value = new Date().toLocaleString('zh-CN');
                };
                updateTime();
                setInterval(updateTime, 1000);

                // 妫€鏌ョ櫥褰曠姸鎬?
                if (token.value) {
                    request('/auth/info').then(res => {
                        if (res.code === 200) {
                            user.value = res.data;
                            permittedHotels.value = res.data.permitted_hotels || [];
                            // 鍗曢棬搴楃敤鎴疯嚜鍔ㄩ€夋嫨鍏跺敮涓€鐨勯厭搴?
                            if (!user.value.is_super_admin && permittedHotels.value.length === 1) {
                                filterReportHotel.value = permittedHotels.value[0].id;
                                filterTaskHotel.value = permittedHotels.value[0].id;
                            }
                            isLoggedIn.value = true;
                            loadData();
                        } else {
                            // token 鏃犳晥锛屾竻闄ゆ湰鍦板瓨鍌?
                            localStorage.removeItem('token');
                            token.value = '';
                        }
                    }).catch(() => {
                        // 璇锋眰澶辫触锛屾竻闄ゆ湰鍦板瓨鍌?
                        localStorage.removeItem('token');
                        token.value = '';
                    });
                }
            });

            // 鐩戝惉杩囨护鏉′欢鍙樺寲
            watch([filterReportHotel, filterReportStartDate, filterReportEndDate], loadDailyReports);
            watch([filterTaskHotel, filterTaskYear], loadMonthlyTasks);

            return {
                isLoggedIn, loading, user, token, currentTime, currentPage, loginError, showPassword, rememberUsername,
                loginForm, menuItems, visibleMenuItems, pageTitle, toast, handleMenuClick,
                expandedMenus, toggleSubmenu,
                hotels, permittedHotels, users, roles, dailyReports, monthlyTasks, reportConfigs, dailyReportConfig, dailyReportTab, monthlyTaskConfig,
                searchHotel, filterHotelStatus, searchUser, filterUserRoleId,
                filterReportHotel, filterReportStartDate, filterReportEndDate,
                filterTaskHotel, filterTaskYear, filterConfigType, yearOptions, yesterdayDate,
                canViewReport, canFillDailyReport, canFillMonthlyTask, canEditReport, canDeleteReport,
                filteredReportConfigs, getFieldTypeLabel,
                systemConfig, systemConfigForm, showSystemConfigModal,
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
                handleLogin, handleLogout, showToast, quickLogin,
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
                // 绾夸笂鏁版嵁鑾峰彇
                onlineDataTab, downloadCenterTab, fetchingData, onlineDataResult, topTenHotels, ctripHotelsList, ctripTableTab, showRawData, ctripForm, ctripTrafficForm, meituanForm, meituanTrafficForm, meituanCommentForm, fetchingCommentData, meituanCommentSuccess, meituanCommentResult, showMeituanCommentHelp, customForm, newCookies, cookiesList, bookmarkletCode,
                quickCookiesName, quickCookiesValue, openTargetSite, saveQuickCookies,
                // 绾夸笂鏁版嵁璁板綍
                onlineDataFilter, onlineDataList, onlineDataPagination, onlineDataPage, onlineDataHotelList, onlineDataSummary,
                selectedOnlineDataIds, toggleSelectAllOnlineData, isAllOnlineDataSelected, batchDeleteOnlineData,
                autoFetchScheduleTime, saveFetchSchedule,
                loadOnlineDataList, loadOnlineDataHotelList, triggerAutoFetch, refreshOnlineData, changeOnlineDataPage, viewOnlineDataDetail, switchDownloadTab, switchToDownloadCenter, switchToMeituanDownloadCenter,
                editOnlineDataItem, deleteOnlineDataItem, showOnlineDataEditModal, onlineDataEditForm, saveOnlineDataEdit,
                toNumber, toFixedSafe, safeDivide, formatNumber, autoFetchEnabled, autoFetchStatus, toggleAutoFetch, loadAutoFetchStatus,
                fetchCtripData, fetchCtripTrafficData, fetchMeituanData, fetchMeituanTrafficData, fetchMeituanComments, fetchMeituanCommentsV2, useMeituanCommentConfig, saveMeituanCommentConfig, parseRequestUrl, resetMeituanCommentForm, formatCommentTime, getScoreClass, fetchCustomData, loadCookiesList, saveCookiesConfig, deleteCookiesConfig, useCookies, copyOnlineDataResult, copyToClipboard, copyBookmarklet, copyCookieScript, saveMeituanConfig, loadMeituanConfig,
                // 美团点评相关
                canFetchComments, canSaveConfig, showAdvancedConfig,
                // 携程点评相关
                ctripCommentForm, fetchingCtripCommentData, ctripCommentSuccess, ctripCommentResult, 
                showCtripCommentHelp, showCtripAdvancedConfig, ctripCommentConfigList,
                canFetchCtripComments, canSaveCtripCommentConfig, parseCtripRequestUrl, resetCtripCommentForm,
                fetchCtripComments, saveCtripCommentConfig, loadCtripCommentConfigList, useCtripCommentConfig,
                formatCtripCommentTime, getCtripScoreClass,
                // 鎼虹▼閰嶇疆绠＄悊
                ctripConfigForm, ctripConfigList, ctripBookmarklet, showCtripCookieGuide, selectedCtripConfigId,
                ctripFetchSuccess, ctripSavedCount,
                loadCtripConfigList, saveCtripConfig, useCtripConfig, editCtripConfig, deleteCtripConfig, generateCtripBookmarklet, openTargetSite, applyCtripConfig,
                // 缇庡洟閰嶇疆绠＄悊
                meituanConfigForm, meituanConfigList, meituanBookmarklet,
                meituanHotelsList, meituanFetchSuccess, meituanSavedCount,
                loadMeituanConfigList, saveMeituanConfigItem, useMeituanConfig, editMeituanConfig, deleteMeituanConfigItem, generateMeituanBookmarklet,
                // AI鏅鸿兘鍒嗘瀽锛堟惡绋嬶級
                aiSelectedHotels, aiAnalysisHotelList, aiAnalyzing, aiAnalysisResult, aiAnalysisHistory,
                selectAllAiHotels, clearAiHotelSelection, startAiAnalysis, copyAiAnalysisResult, viewAiAnalysisRecord,
                // AI鏅鸿兘鍒嗘瀽锛堢編鍥級
                meituanAiSelectedHotels, meituanAiAnalysisHotelList, meituanAiAnalyzing, meituanAiAnalysisResult, meituanAiAnalysisHistory,
                selectAllMeituanAiHotels, clearMeituanAiHotelSelection, startMeituanAiAnalysis, copyMeituanAiAnalysisResult, viewMeituanAiAnalysisRecord,
                // 鏁版嵁鍒嗘瀽
                analysisDimension, analysisData, loadAnalysisData,
                // 鎿嶄綔鏃ュ織
                operationLogs, logModules, logActions, logUsers, logHotels, logFilter, logPagination, selectedLog, showLogDetailModal,
                loadOperationLogs, viewLogDetail,
                // 闂ㄥ簵缃楃洏
                compassLayout, compassLayoutPanel, compassWeather, compassTodos, compassMetrics, compassAlerts, compassHolidays, compassMetricTab,
                loadCompassData, moveCompassBlock, toggleCompassBlock, saveCompassLayout, compassBlockLabel, getHotelName,
                // 绔炲浠锋牸鐩戞帶
                competitorTab, competitorHotels, competitorLogs, competitorDevices, competitorRobots,
                competitorHotelFilter, competitorLogFilter, competitorRobotFilter,
                showCompetitorHotelModal, competitorHotelForm, competitorStores,
                showCompetitorRobotModal, competitorRobotForm,
                loadCompetitorHotels, openCompetitorHotelModal, saveCompetitorHotel, deleteCompetitorHotel,
                loadCompetitorLogs, loadCompetitorDevices, loadCompetitorStores,
                loadCompetitorRobots, openCompetitorRobotModal, saveCompetitorRobot, deleteCompetitorRobot, testCompetitorRobot, getCompetitorStoreName,
            };
        }
    }).mount('#app');
    </script>
