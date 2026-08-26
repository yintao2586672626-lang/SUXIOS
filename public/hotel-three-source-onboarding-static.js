(function () {
    'use strict';

    const create = ({
        computed,
        runtimeWindow,
        request,
        showToast,
        hotelForm,
        hotelFormChannelSelected,
        normalizeHotelIdentityName,
        wechatNotificationHotelId,
        manualNotificationForm,
        showHotelModal,
        currentPage,
        hotelOnboardingActive,
        hotelOnboardingStep,
        hotelOnboardingHotelId,
        hotelOnboardingSnapshot,
        hotelOnboardingLoading,
        hotelOnboardingError,
        hotelOnboardingBusyPlatform,
        hotelOnboardingLoginSessions,
        hotelOnboardingBindingForms,
        hotelOnboardingCollectionPlanStatus,
        hotelOnboardingCollectionPlanError,
    }) => {
        const hotelOnboardingPlatformMeta = Object.freeze({
            ctrip: { label: '携程', icon: 'fas fa-plane-departure', accent: 'border-blue-200 bg-blue-50/60' },
            meituan: { label: '美团', icon: 'fas fa-store', accent: 'border-orange-200 bg-orange-50/60' },
            dingdandao: { label: '订单来了 PMS', icon: 'fas fa-hotel', accent: 'border-violet-200 bg-violet-50/60' },
            meituan_cloud_pms: { label: '美团云 PMS', icon: 'fas fa-cloud', accent: 'border-cyan-200 bg-cyan-50/60' },
        });
        const normalizeHotelOnboardingPlatform = (platform) => ({
            dingdandao_pms: 'dingdandao',
            dingdandao: 'dingdandao',
            meituan_pms: 'meituan_cloud_pms',
            meituan_cloud: 'meituan_cloud_pms',
        })[String(platform || '').trim().toLowerCase()] || String(platform || '').trim().toLowerCase();
        const resetHotelOnboarding = ({ active = false, hotelId = '', step = 'hotel' } = {}) => {
            hotelOnboardingActive.value = active;
            hotelOnboardingStep.value = step;
            hotelOnboardingHotelId.value = String(hotelId || '').trim();
            hotelOnboardingSnapshot.value = null;
            hotelOnboardingLoading.value = false;
            hotelOnboardingError.value = '';
            hotelOnboardingBusyPlatform.value = '';
            hotelOnboardingLoginSessions.value = {};
            hotelOnboardingBindingForms.value = {};
            hotelOnboardingCollectionPlanStatus.value = 'idle';
            hotelOnboardingCollectionPlanError.value = '';
        };
        const hotelOnboardingExpectedPlatforms = computed(() => {
            const platforms = [];
            if (hotelFormChannelSelected('ctrip')) platforms.push('ctrip');
            if (hotelFormChannelSelected('meituan')) platforms.push('meituan');
            const pmsPlatform = normalizeHotelOnboardingPlatform(hotelForm.value.pms_provider);
            if (['dingdandao', 'meituan_cloud_pms'].includes(pmsPlatform)) platforms.push(pmsPlatform);
            return [...new Set(platforms)];
        });
        const hotelOnboardingSnapshotSources = computed(() => {
            const snapshot = hotelOnboardingSnapshot.value || {};
            const raw = snapshot.sources || snapshot.platforms || snapshot.source_statuses || [];
            const entries = Array.isArray(raw)
                ? raw
                : Object.entries(raw || {}).map(([platform, value]) => ({ platform, ...(value || {}) }));
            return entries.reduce((map, source) => {
                const platform = normalizeHotelOnboardingPlatform(source?.platform || source?.source || source?.key);
                if (platform) map.set(platform, source || {});
                return map;
            }, new Map());
        });
        const hotelOnboardingSourceRows = computed(() => hotelOnboardingExpectedPlatforms.value.map((platform) => {
            const source = hotelOnboardingSnapshotSources.value.get(platform) || {};
            const binding = source.binding || source.platform_binding || {};
            const sourceStatus = String(
                source.status
                || source.state
                || (source.ready === true ? 'ready' : 'unverified')
            ).trim().toLowerCase();
            const authorizationStatus = String(source.authorization_status || source.profile?.authorization_status || '').trim().toLowerCase();
            const profileReady = source.profile_ready === true
                || source.profile?.ready === true
                || ['ready', 'ready_to_collect', 'login_verified'].includes(authorizationStatus);
            const sourceReady = source.source_ready === true
                || source.ready === true
                || ['ready', 'completed', 'complete', 'ready_to_collect'].includes(sourceStatus);
            const bindingStatus = String(binding.binding_status || binding.status || source.binding_status || '').trim().toLowerCase();
            const bindingIdentityReady = String(
                source.platform_hotel_id
                || source.provider_hotel_id
                || binding.platform_hotel_id
                || binding.provider_hotel_id
                || ''
            ).trim() !== '' && String(
                source.platform_hotel_name
                || source.provider_hotel_name
                || binding.platform_hotel_name
                || binding.provider_hotel_name
                || ''
            ).trim() !== '';
            const bindingReady = bindingIdentityReady && (source.binding_ready === true
                || binding.ready === true
                || binding.readback_verified === true
                || ['ready', 'verified', 'readback_verified'].includes(bindingStatus));
            const status = !profileReady && sourceReady
                ? 'awaiting_login'
                : (!bindingReady && sourceReady ? 'missing_binding' : sourceStatus);
            const form = hotelOnboardingBindingForms.value[platform] || {
                platform_hotel_id: String(source.platform_hotel_id || source.provider_hotel_id || binding.platform_hotel_id || binding.provider_hotel_id || binding.hotel_id || '').trim(),
                platform_hotel_name: String(source.platform_hotel_name || source.provider_hotel_name || binding.platform_hotel_name || binding.provider_hotel_name || binding.hotel_name || '').trim(),
            };
            const meta = hotelOnboardingPlatformMeta[platform] || {
                label: platform,
                icon: 'fas fa-database',
                accent: 'border-slate-200 bg-slate-50',
            };
            return {
                ...meta,
                ...source,
                platform,
                status,
                profileReady,
                sourceReady,
                bindingReady,
                dataSourceId: Number(source.data_source_id || source.source_id || source.data_source?.id || binding.data_source_id || binding.source_id || 0),
                form,
                detail: String(source.detail || source.message || source.reason || '').trim(),
            };
        }));
        const setHotelOnboardingBindingField = (platform, field, value) => {
            const key = normalizeHotelOnboardingPlatform(platform);
            if (!key || !['platform_hotel_id', 'platform_hotel_name'].includes(field)) return;
            hotelOnboardingBindingForms.value = {
                ...hotelOnboardingBindingForms.value,
                [key]: {
                    ...(hotelOnboardingBindingForms.value[key] || {}),
                    [field]: value,
                },
            };
        };
        const hotelOnboardingStatusText = (row = {}) => ({
            ready: '已就绪',
            completed: '已就绪',
            complete: '已就绪',
            ready_to_collect: '已授权，待采集',
            login_verified: '登录已验证',
            awaiting_login: '等待平台登录',
            awaiting_relogin: '等待重新登录',
            unauthorized: '尚未授权',
            missing_binding: '待补门店身份',
            binding_missing: '待补门店身份',
            session_expired: '登录已失效',
            blocked: '当前受阻',
            failed: '验证失败',
            unverified: '状态待回读',
        })[String(row?.status || '').toLowerCase()] || '状态待回读';
        const hotelOnboardingStatusClass = (row = {}) => {
            const status = String(row?.status || '').toLowerCase();
            if (['ready', 'completed', 'complete', 'ready_to_collect'].includes(status)) return 'border-emerald-200 bg-emerald-50 text-emerald-700';
            if (['failed', 'blocked', 'session_expired'].includes(status)) return 'border-red-200 bg-red-50 text-red-700';
            if (['awaiting_login', 'awaiting_relogin', 'missing_binding', 'binding_missing'].includes(status)) return 'border-amber-200 bg-amber-50 text-amber-800';
            return 'border-slate-200 bg-slate-50 text-slate-700';
        };
        const hotelOnboardingReady = computed(() => {
            const snapshot = hotelOnboardingSnapshot.value || {};
            const overall = String(snapshot.overall_status || snapshot.status || '').trim().toLowerCase();
            const sourceRowsReady = hotelOnboardingSourceRows.value.length > 0
                && hotelOnboardingSourceRows.value.every(row => row.sourceReady === true
                    && row.profileReady === true
                    && row.bindingReady === true
                    && ['ready', 'completed', 'complete', 'ready_to_collect'].includes(row.status));
            if (!sourceRowsReady) return false;
            return snapshot.ready === true
                || snapshot.readiness?.ready === true
                || ['ready', 'completed', 'complete', 'continuous_running'].includes(overall);
        });
        const hotelOnboardingCollectionPlanEligible = computed(() => {
            const rows = hotelOnboardingSourceRows.value;
            const byPlatform = new Map(rows.map(row => [row.platform, row]));
            const pmsRows = rows.filter(row => ['dingdandao', 'meituan_cloud_pms'].includes(row.platform));
            if (rows.length !== 3 || pmsRows.length !== 1 || !byPlatform.has('ctrip') || !byPlatform.has('meituan')) return false;
            if (Number(byPlatform.get('ctrip')?.dataSourceId || 0) <= 0 || Number(byPlatform.get('meituan')?.dataSourceId || 0) <= 0) return false;
            return rows.every(row => row.sourceReady === true && row.profileReady === true && row.bindingReady === true);
        });
        const loadHotelThreeSourceOnboarding = async ({ hotelId = hotelOnboardingHotelId.value, silent = false } = {}) => {
            const exactHotelId = String(hotelId || '').trim();
            if (!exactHotelId) {
                hotelOnboardingError.value = '缺少刚创建门店的精确 ID，已停止回读。';
                return false;
            }
            hotelOnboardingLoading.value = true;
            if (!silent) hotelOnboardingError.value = '';
            try {
                const response = await request(`/hotels/${encodeURIComponent(exactHotelId)}/three-source-onboarding`, {
                    requestPolicy: { scope: 'hotel', priority: 'action', force: true },
                });
                if (response.code !== 200 || !response.data) {
                    throw new Error(response.message || '三源接入状态回读失败');
                }
                const returnedHotelId = String(response.data.hotel_id || response.data.hotel?.id || exactHotelId).trim();
                if (returnedHotelId !== exactHotelId) {
                    throw new Error(`门店回读串店：期望 ${exactHotelId}，实际 ${returnedHotelId || '未返回'}`);
                }
                hotelOnboardingSnapshot.value = response.data;
                const collectionPlan = response.data.collection_plan || {};
                hotelOnboardingCollectionPlanStatus.value = response.data.collection_plan_ready === true
                    && collectionPlan.readback_verified === true
                    && collectionPlan.execution_authorized === true
                    ? 'active'
                    : 'idle';
                const nextForms = { ...hotelOnboardingBindingForms.value };
                const rawSources = response.data.sources || response.data.platforms || response.data.source_statuses || [];
                const entries = Array.isArray(rawSources)
                    ? rawSources
                    : Object.entries(rawSources || {}).map(([platform, value]) => ({ platform, ...(value || {}) }));
                entries.forEach((source) => {
                    const platform = normalizeHotelOnboardingPlatform(source?.platform || source?.source || source?.key);
                    if (!platform || nextForms[platform]) return;
                    const binding = source.binding || source.platform_binding || {};
                    nextForms[platform] = {
                        platform_hotel_id: String(source.platform_hotel_id || source.provider_hotel_id || binding.platform_hotel_id || binding.provider_hotel_id || binding.hotel_id || '').trim(),
                        platform_hotel_name: String(source.platform_hotel_name || source.provider_hotel_name || binding.platform_hotel_name || binding.provider_hotel_name || binding.hotel_name || '').trim(),
                    };
                });
                hotelOnboardingBindingForms.value = nextForms;
                hotelOnboardingError.value = '';
                return true;
            } catch (error) {
                hotelOnboardingError.value = error?.message || '三源接入状态回读失败';
                if (!silent) showToast(hotelOnboardingError.value, 'error');
                return false;
            } finally {
                hotelOnboardingLoading.value = false;
            }
        };
        const hotelOnboardingViewerUrl = (value) => {
            const raw = String(value || '').trim();
            if (!raw) throw new Error('云端可视浏览器未返回 viewer_url');
            const url = new URL(raw, runtimeWindow.location.origin);
            if (!['http:', 'https:'].includes(url.protocol)) throw new Error('云端可视浏览器地址协议不安全');
            if (url.origin !== runtimeWindow.location.origin || !url.pathname.startsWith('/cloud-browser-viewer/')) {
                throw new Error('云端可视浏览器地址不属于宿析受控查看器');
            }
            return url.href;
        };
        const openHotelOnboardingCloudLogin = async (row = {}) => {
            const platform = normalizeHotelOnboardingPlatform(row.platform);
            const exactHotelId = String(hotelOnboardingHotelId.value || '').trim();
            if (!platform || !exactHotelId || hotelOnboardingBusyPlatform.value) return false;
            const viewerWindow = runtimeWindow.open('about:blank', '_blank');
            if (!viewerWindow) {
                showToast('浏览器阻止了云端登录窗口，请允许弹窗后重试', 'warning');
                return false;
            }
            try { viewerWindow.opener = null; } catch (error) { /* Browser-owned protection. */ }
            hotelOnboardingBusyPlatform.value = platform;
            hotelOnboardingError.value = '';
            try {
                const response = await request('/cloud-browser-profiles/open-login', {
                    method: 'POST',
                    body: JSON.stringify({ hotel_id: Number(exactHotelId), platform }),
                });
                const data = response.data || {};
                if (response.code !== 200 || data.browser_started !== true) {
                    throw new Error(response.message || '云端可视浏览器未启动');
                }
                const viewerUrl = hotelOnboardingViewerUrl(data.viewer_url);
                const profileId = String(data.profile_id || data.profile?.id || data.profile || '').trim();
                const sessionId = String(data.session_id || data.session?.id || data.session || '').trim();
                if (!profileId || !sessionId) throw new Error('云端登录会话标识不完整');
                hotelOnboardingLoginSessions.value = {
                    ...hotelOnboardingLoginSessions.value,
                    [platform]: { profile_id: profileId, session_id: sessionId },
                };
                viewerWindow.location.replace(viewerUrl);
                showToast(`已打开${hotelOnboardingPlatformMeta[platform]?.label || platform}云端可视登录页，请在该页面完成登录`, 'info');
                return true;
            } catch (error) {
                try { viewerWindow.close(); } catch (closeError) { /* Window may already be gone. */ }
                hotelOnboardingBusyPlatform.value = '';
                hotelOnboardingError.value = error?.message || '云端登录入口创建失败';
                showToast(hotelOnboardingError.value, 'error');
                return false;
            }
        };
        const completeHotelOnboardingCloudLogin = async (row = {}) => {
            const platform = normalizeHotelOnboardingPlatform(row.platform);
            const exactHotelId = String(hotelOnboardingHotelId.value || '').trim();
            const session = hotelOnboardingLoginSessions.value[platform];
            if (!platform || !exactHotelId || !session) return false;
            hotelOnboardingLoading.value = true;
            hotelOnboardingError.value = '';
            try {
                const response = await request('/cloud-browser-profiles/complete-login', {
                    method: 'POST',
                    body: JSON.stringify({
                        hotel_id: Number(exactHotelId),
                        platform,
                        profile_id: session.profile_id,
                        session_id: session.session_id,
                    }),
                });
                if (response.code !== 200) throw new Error(response.message || '平台登录完成确认失败');
                const nextSessions = { ...hotelOnboardingLoginSessions.value };
                delete nextSessions[platform];
                hotelOnboardingLoginSessions.value = nextSessions;
                hotelOnboardingBusyPlatform.value = '';
                const refreshed = await loadHotelThreeSourceOnboarding({ hotelId: exactHotelId, silent: true });
                if (!refreshed) throw new Error(hotelOnboardingError.value || '登录完成，但状态回读失败');
                showToast(`${hotelOnboardingPlatformMeta[platform]?.label || platform}登录状态已回读`, 'success');
                return true;
            } catch (error) {
                const nextSessions = { ...hotelOnboardingLoginSessions.value };
                delete nextSessions[platform];
                hotelOnboardingLoginSessions.value = nextSessions;
                hotelOnboardingBusyPlatform.value = '';
                hotelOnboardingError.value = error?.message || '平台登录完成确认失败';
                showToast(hotelOnboardingError.value, 'error');
                return false;
            } finally {
                hotelOnboardingLoading.value = false;
            }
        };
        const saveHotelOnboardingBinding = async (row = {}) => {
            const platform = normalizeHotelOnboardingPlatform(row.platform);
            const exactHotelId = String(hotelOnboardingHotelId.value || '').trim();
            const form = hotelOnboardingBindingForms.value[platform] || {};
            const platformHotelId = String(form.platform_hotel_id || '').trim();
            const platformHotelName = String(form.platform_hotel_name || '').trim();
            if (!exactHotelId || !platform || !platformHotelId || !platformHotelName) {
                showToast('请同时填写平台公开门店 ID 和名称，再保存并回读', 'warning');
                return false;
            }
            hotelOnboardingLoading.value = true;
            hotelOnboardingError.value = '';
            try {
                const isPmsPlatform = ['dingdandao', 'meituan_cloud_pms'].includes(platform);
                const response = await request(isPmsPlatform
                    ? `/hotels/${encodeURIComponent(exactHotelId)}/pms-binding`
                    : `/hotels/${encodeURIComponent(exactHotelId)}/platform-bindings/${encodeURIComponent(platform)}`, {
                    method: 'PUT',
                    body: JSON.stringify(isPmsPlatform ? {
                        provider: platform === 'dingdandao' ? 'dingdandao_pms' : 'meituan_cloud_pms',
                        provider_hotel_id: platformHotelId,
                        provider_hotel_name: platformHotelName,
                    } : {
                        platform_hotel_id: platformHotelId,
                        platform_hotel_name: platformHotelName,
                    }),
                });
                if (response.code !== 200) throw new Error(response.message || '平台门店身份保存失败');
                const refreshed = await loadHotelThreeSourceOnboarding({ hotelId: exactHotelId, silent: true });
                if (!refreshed) throw new Error(hotelOnboardingError.value || '平台门店身份保存后回读失败');
                const readback = hotelOnboardingSourceRows.value.find(item => item.platform === platform);
                const readbackBinding = readback?.binding || readback?.platform_binding || readback || {};
                const readbackId = String(readbackBinding.platform_hotel_id || readbackBinding.provider_hotel_id || readbackBinding.hotel_id || '').trim();
                const readbackName = String(readbackBinding.platform_hotel_name || readbackBinding.provider_hotel_name || readbackBinding.hotel_name || '').trim();
                if ((platformHotelId && readbackId !== platformHotelId)
                    || normalizeHotelIdentityName(readbackName) !== normalizeHotelIdentityName(platformHotelName)
                ) {
                    throw new Error('平台门店身份回读与本次保存不一致');
                }
                showToast(`${hotelOnboardingPlatformMeta[platform]?.label || platform}门店身份已保存并回读`, 'success');
                return true;
            } catch (error) {
                hotelOnboardingError.value = error?.message || '平台门店身份保存失败';
                showToast(hotelOnboardingError.value, 'error');
                return false;
            } finally {
                hotelOnboardingLoading.value = false;
            }
        };
        const goToHotelOnboardingVerification = async () => {
            hotelOnboardingStep.value = 'verification';
            await loadHotelThreeSourceOnboarding({ silent: true });
        };
        const finishHotelOnboarding = () => {
            if (!hotelOnboardingReady.value) {
                hotelOnboardingError.value = '仍有来源未通过正式回读，不能标记为接入完成。';
                showToast(hotelOnboardingError.value, 'warning');
                return false;
            }
            hotelOnboardingStep.value = 'complete';
            return true;
        };
        const enableHotelOnboardingHourlyCollection = async () => {
            const exactHotelId = String(hotelOnboardingHotelId.value || '').trim();
            if (!exactHotelId || !hotelOnboardingCollectionPlanEligible.value) {
                hotelOnboardingCollectionPlanError.value = '当前统一三源队列要求携程+美团+PMS，且三个来源、Profile 与门店绑定均已就绪。';
                showToast(hotelOnboardingCollectionPlanError.value, 'warning');
                return false;
            }
            if (hotelOnboardingCollectionPlanStatus.value === 'saving') return false;
            const rows = new Map(hotelOnboardingSourceRows.value.map(row => [row.platform, row]));
            const pmsRow = hotelOnboardingSourceRows.value.find(row => ['dingdandao', 'meituan_cloud_pms'].includes(row.platform));
            hotelOnboardingCollectionPlanStatus.value = 'saving';
            hotelOnboardingCollectionPlanError.value = '';
            try {
                const response = await request(`/hotels/${encodeURIComponent(exactHotelId)}/collection-plan`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        business_date_policy: 'same_day_realtime',
                        timezone: 'Asia/Shanghai',
                        schedule_time: '00:30',
                        retry_interval_minutes: 30,
                        max_attempts: 1,
                        activate: true,
                        sources: {
                            ctrip: { data_source_id: Number(rows.get('ctrip')?.dataSourceId || 0) },
                            meituan: { data_source_id: Number(rows.get('meituan')?.dataSourceId || 0) },
                            pms: { provider: pmsRow?.platform === 'dingdandao' ? 'dingdandao_pms' : 'meituan_cloud_pms' },
                        },
                    }),
                });
                const data = response.data || {};
                if (response.code !== 200
                    || data.readback_verified !== true
                    || data.execution_authorized !== true
                    || String(data.plan_status || '').trim().toLowerCase() !== 'active'
                ) {
                    throw new Error(response.message || '采集计划写入后未通过启用回读');
                }
                hotelOnboardingCollectionPlanStatus.value = 'active';
                const refreshed = await loadHotelThreeSourceOnboarding({ hotelId: exactHotelId, silent: true });
                if (!refreshed || !hotelOnboardingReady.value) {
                    throw new Error(hotelOnboardingError.value || '采集计划已写入，但三源接入整体状态尚未通过精确回读');
                }
                showToast('三源采集计划已启用并完成精确回读；云端定时器由系统运行状态单独确认', 'success');
                return true;
            } catch (error) {
                hotelOnboardingCollectionPlanStatus.value = 'failed';
                hotelOnboardingCollectionPlanError.value = error?.message || '每小时三源采集启用失败';
                showToast(hotelOnboardingCollectionPlanError.value, 'error');
                return false;
            }
        };
        const openHotelOnboardingWechatConfig = () => {
            const exactHotelId = String(hotelOnboardingHotelId.value || '').trim();
            if (!exactHotelId) return;
            wechatNotificationHotelId.value = exactHotelId;
            manualNotificationForm.value = {
                ...manualNotificationForm.value,
                hotel_id: exactHotelId,
            };
            showHotelModal.value = false;
            currentPage.value = 'wechat-notification';
        };
        return Object.freeze({
            resetHotelOnboarding,
            hotelOnboardingSourceRows,
            hotelOnboardingReady,
            hotelOnboardingStatusText,
            hotelOnboardingStatusClass,
            hotelOnboardingCollectionPlanEligible,
            setHotelOnboardingBindingField,
            loadHotelThreeSourceOnboarding,
            openHotelOnboardingCloudLogin,
            completeHotelOnboardingCloudLogin,
            saveHotelOnboardingBinding,
            goToHotelOnboardingVerification,
            finishHotelOnboarding,
            enableHotelOnboardingHourlyCollection,
            openHotelOnboardingWechatConfig,
        });
    };

    window.SUXI_HOTEL_THREE_SOURCE_ONBOARDING_STATIC = Object.freeze({ create });
})();
